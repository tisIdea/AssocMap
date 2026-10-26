<?php

namespace Tests\Unit;

use App\Exceptions\MemberApplicationPhotoUnavailableException;
use App\Services\Members\MemberApplicationPhotoService;
use App\Services\SupabaseStorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Fixtures\PhotoEncodingFailure;
use Tests\TestCase;

class MemberApplicationPhotoServiceCharacterizationTest extends TestCase
{
    private const APPLICATION = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PATH = 'photos/'.self::APPLICATION.'/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.png';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase_storage.photo_transport' => 'local']);
        Storage::fake('member_application_photos');
        Http::fake([]);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); } finally {
            try { parent::tearDown(); } finally { HandleExceptions::flushState($this); }
        }
    }

    private function service(): MemberApplicationPhotoService
    {
        $remote = Mockery::mock(SupabaseStorageService::class);
        $remote->shouldNotReceive('upload');
        $remote->shouldNotReceive('download');
        $remote->shouldNotReceive('delete');

        return new MemberApplicationPhotoService($remote);
    }

    private function requireGd(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagejpeg') || ! function_exists('imagepng')) {
            $this->markTestSkipped('PHP GD with JPEG and PNG support is required.');
        }
    }

    #[DataProvider('supportedImages')]
    public function test_supported_images_are_normalized_to_private_application_bound_files(string $extension, string $output, string $mime): void
    {
        $this->requireGd();
        $source = UploadedFile::fake()->image('portrait.'.$extension, 32, 24)->getContent();
        $file = UploadedFile::fake()->createWithContent('portrait.'.$extension, $source.'SYNTHETIC_TRAILING_PAYLOAD');
        $disk = Storage::disk('member_application_photos');
        $privateDisk = Mockery::mock($disk);
        $privateDisk->shouldReceive('put')->once()->with(Mockery::type('string'), Mockery::type('string'), 'private')
            ->andReturnUsing(fn ($path, $contents, $visibility) => $disk->put($path, $contents, $visibility));
        Storage::shouldReceive('disk')->with('member_application_photos')->andReturn($privateDisk);
        $service = $this->service();
        $path = $service->store($file, strtoupper(self::APPLICATION));
        $this->assertMatchesRegularExpression('~^photos/'.self::APPLICATION.'/[0-9a-f-]{36}\.'.$output.'$~', $path);
        $encoded = $disk->get($path);
        $this->assertStringNotContainsString('SYNTHETIC_TRAILING_PAYLOAD', $encoded);
        $this->assertSame([32, 24], array_slice(getimagesizefromstring($encoded), 0, 2));
        $photo = $service->open(self::APPLICATION, $path);
        try {
            $this->assertSame($mime, $photo['mime_type']);
            $this->assertSame(0, ftell($photo['stream']));
            $this->assertSame($encoded, stream_get_contents($photo['stream']));
        } finally {
            fclose($photo['stream']);
        }
        $service->discard($path, self::APPLICATION);
        $disk->assertMissing($path);
    }

    public static function supportedImages(): array
    {
        return [['jpg', 'jpg', 'image/jpeg'], ['jpeg', 'jpg', 'image/jpeg'], ['png', 'png', 'image/png']];
    }

    public function test_png_normalization_preserves_transparency(): void
    {
        $this->requireGd();
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 12, 34, 56, 96));
        ob_start();
        try { imagepng($image); $contents = ob_get_contents(); } finally { ob_end_clean(); unset($image); }
        $path = $this->service()->store(UploadedFile::fake()->createWithContent('portrait.png', $contents), self::APPLICATION);
        $decoded = imagecreatefromstring(Storage::disk('member_application_photos')->get($path));
        try { $this->assertSame(96, (imagecolorat($decoded, 0, 0) >> 24) & 0x7f); } finally { unset($decoded); }
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_images_are_rejected_before_storage_and_restore_the_error_handler_and_buffers(string $kind): void
    {
        $this->requireGd();
        $png = UploadedFile::fake()->image('portrait.png', 8, 8)->getContent();
        $file = match ($kind) {
            'corrupt' => UploadedFile::fake()->createWithContent('portrait.png', "\x89PNG\r\n\x1a\ninvalid"),
            'non-image' => UploadedFile::fake()->createWithContent('portrait.jpg', 'not image data'),
            'extension mismatch' => UploadedFile::fake()->createWithContent('portrait.jpg', $png),
            'unsupported extension' => UploadedFile::fake()->createWithContent('portrait.gif', $png),
            'unsupported format' => UploadedFile::fake()->createWithContent('portrait.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'oversized bytes' => UploadedFile::fake()->createWithContent('portrait.png', str_pad($png, 2 * 1024 * 1024 + 1, 'x')),
            'excessive width' => UploadedFile::fake()->image('portrait.png', 2049, 1),
            'excessive height' => UploadedFile::fake()->image('portrait.png', 1, 2049),
            'invalid upload' => new UploadedFile('missing-synthetic-photo', 'portrait.png', 'image/png', UPLOAD_ERR_PARTIAL, true),
        };
        $handler = static fn () => false;
        set_error_handler($handler);
        $level = ob_get_level();
        ob_start(); echo 'outer buffer';
        try {
            try {
                $this->service()->store($file, self::APPLICATION);
                $this->fail('Invalid image was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('applicant_photo', $exception->errors());
            }
            $this->assertSame($level + 1, ob_get_level());
            $this->assertSame('outer buffer', ob_get_contents());
            $restored = set_error_handler(static fn () => false);
            restore_error_handler();
            $this->assertSame($handler, $restored);
            $this->assertSame([], Storage::disk('member_application_photos')->allFiles());
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
            restore_error_handler();
        }
    }

    public static function invalidImages(): array
    {
        return array_map(fn ($kind) => [$kind], ['corrupt', 'non-image', 'extension mismatch', 'unsupported extension',
            'unsupported format', 'oversized bytes', 'excessive width', 'excessive height', 'invalid upload']);
    }

    public function test_exact_byte_and_dimension_boundaries_remain_supported_after_reencoding(): void
    {
        $this->requireGd();
        $source = UploadedFile::fake()->image('portrait.png', 2048, 1)->getContent();
        $file = UploadedFile::fake()->createWithContent('portrait.png', str_pad($source, 2 * 1024 * 1024, 'x'));
        $path = $this->service()->store($file, self::APPLICATION);
        $contents = Storage::disk('member_application_photos')->get($path);
        $this->assertSame([2048, 1], array_slice(getimagesizefromstring($contents), 0, 2));
        $this->assertLessThan(2 * 1024 * 1024, strlen($contents));
    }

    #[DataProvider('localWriteFailures')]
    public function test_failed_local_upload_attempts_one_private_write_and_one_cleanup_without_masking_validation(bool $throws, bool $cleanup): void
    {
        $this->requireGd();
        Log::spy();
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->with('member_application_photos')->twice()->andReturn($disk);
        $path = null;
        $write = $disk->shouldReceive('put')->once()->ordered()->withArgs(function ($name, $bytes, $visibility) use (&$path) {
            $path = $name;
            $this->assertSame('private', $visibility);
            $this->assertSame('image/png', getimagesizefromstring($bytes)['mime']);

            return true;
        });
        $throws ? $write->andThrow(UnableToWriteFile::atLocation('synthetic')) : $write->andReturn(false);
        $disk->shouldReceive('delete')->once()->ordered()->with(Mockery::on(function ($name) use (&$path) { return $name === $path; }))->andReturn($cleanup);
        try {
            $this->service()->store(UploadedFile::fake()->image('portrait.png', 8, 8), self::APPLICATION);
            $this->fail('Failed upload was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('applicant_photo', $exception->errors());
        }
        if (! $cleanup) Log::shouldHaveReceived('warning')->once()->with('Member application photo cleanup requires reconciliation.',
            ['application_id' => self::APPLICATION, 'code' => 'photo_cleanup_failed']);
        else Log::shouldNotHaveReceived('warning');
    }

    public static function localWriteFailures(): array
    {
        return [[false, true], [true, true], [false, false]];
    }

    #[DataProvider('localReadFailures')]
    public function test_local_read_contract_distinguishes_missing_from_unavailable_storage(string $scenario): void
    {
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->once()->with('member_application_photos')->andReturn($disk);
        $disk->shouldReceive('exists')->once()->with(self::PATH)->andReturn($scenario !== 'missing');
        if ($scenario === 'missing') {
            $disk->shouldNotReceive('readStream');
            $this->assertNull($this->service()->open(self::APPLICATION, self::PATH));

            return;
        }
        $read = $disk->shouldReceive('readStream')->once()->with(self::PATH);
        $scenario === 'exception' ? $read->andThrow(UnableToReadFile::fromLocation(self::PATH)) : $read->andReturn(false);
        $this->expectException(MemberApplicationPhotoUnavailableException::class);
        $this->service()->open(self::APPLICATION, self::PATH);
    }

    public static function localReadFailures(): array
    {
        return [['missing'], ['exception'], ['false stream']];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_partial_encoder_failure_releases_image_restores_buffers_and_handler_and_never_writes_storage(): void
    {
        $this->requireGd();
        require __DIR__.'/../Fixtures/photo-encoding-failure.php';
        $handler = static fn () => false;
        set_error_handler($handler);
        $level = ob_get_level();
        ob_start(); echo 'outer buffer';
        try {
            try {
                $this->service()->store(UploadedFile::fake()->image('portrait.png', 8, 8), self::APPLICATION);
                $this->fail('Partial encoding was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('applicant_photo', $exception->errors());
            }
            $this->assertSame(1, PhotoEncodingFailure::$calls);
            $this->assertNotNull(PhotoEncodingFailure::$image);
            $this->assertNull(PhotoEncodingFailure::$image->get());
            $this->assertSame($level + 1, ob_get_level());
            $this->assertSame('outer buffer', ob_get_contents());
            $restored = set_error_handler(static fn () => false);
            restore_error_handler();
            $this->assertSame($handler, $restored);
            $this->assertSame([], Storage::disk('member_application_photos')->allFiles());
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
            restore_error_handler();
        }
    }
}
