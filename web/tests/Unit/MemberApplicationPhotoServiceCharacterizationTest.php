<?php

namespace Tests\Unit;

use App\Exceptions\MemberApplicationPhotoUnavailableException;
use App\Exceptions\PrivateMediaStorageException;
use App\Services\Members\MemberApplicationPhotoService;
use App\Services\SupabaseStorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
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
use Tests\Fixtures\PhotoReadFailureStream;
use Tests\Fixtures\MemberApplicationReadClock;
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

    private function useTransport(string $transport): string
    {
        config(['services.supabase_storage.photo_transport' => $transport]);
        $disk = $transport === 'r2' ? 'media_private' : 'member_application_photos';
        if ($transport === 'r2') Storage::fake($disk);

        return $disk;
    }

    public static function transports(): array
    {
        return ['local' => ['local'], 'R2' => ['r2']];
    }

    #[DataProvider('supportedImages')]
    public function test_supported_images_are_normalized_to_private_application_bound_files(string $extension, string $output, string $mime, string $transport): void
    {
        $this->requireGd();
        $diskName = $this->useTransport($transport);
        $source = UploadedFile::fake()->image('portrait.'.$extension, 32, 24)->getContent();
        $file = UploadedFile::fake()->createWithContent('portrait.'.$extension, $source.'SYNTHETIC_TRAILING_PAYLOAD');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);
        $privateDisk = Mockery::mock($disk);
        $options = $transport === 'r2' ? ['visibility' => 'private', 'ContentType' => $mime, 'CacheControl' => 'private, no-store, max-age=0'] : 'private';
        $privateDisk->shouldReceive('put')->once()->with(Mockery::type('string'), Mockery::type('string'), $options)
            ->andReturnUsing(fn ($path, $contents, $visibility) => $disk->put($path, $contents, $visibility));
        Storage::shouldReceive('disk')->with($diskName)->andReturn($privateDisk);
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
        $cases = [];
        foreach (['local', 'r2'] as $transport) {
            foreach ([['jpg', 'jpg', 'image/jpeg'], ['jpeg', 'jpg', 'image/jpeg'], ['png', 'png', 'image/png']] as $image) {
                $cases[$transport.' '.$image[0]] = [...$image, $transport];
            }
        }

        return $cases;
    }

    #[DataProvider('transports')]
    public function test_png_normalization_preserves_transparency(string $transport): void
    {
        $this->requireGd();
        $diskName = $this->useTransport($transport);
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 12, 34, 56, 96));
        ob_start();
        try { imagepng($image); $contents = ob_get_contents(); } finally { ob_end_clean(); unset($image); }
        $path = $this->service()->store(UploadedFile::fake()->createWithContent('portrait.png', $contents), self::APPLICATION);
        $decoded = imagecreatefromstring(Storage::disk($diskName)->get($path));
        try { $this->assertSame(96, (imagecolorat($decoded, 0, 0) >> 24) & 0x7f); } finally { unset($decoded); }
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_images_are_rejected_before_storage_and_restore_the_error_handler_and_buffers(string $kind, string $transport): void
    {
        $this->requireGd();
        $diskName = $this->useTransport($transport);
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
            $this->assertSame([], Storage::disk($diskName)->allFiles());
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
            restore_error_handler();
        }
    }

    public static function invalidImages(): array
    {
        $cases = [];
        foreach (['local', 'r2'] as $transport) {
            foreach (['corrupt', 'non-image', 'extension mismatch', 'unsupported extension', 'unsupported format',
                'oversized bytes', 'excessive width', 'excessive height', 'invalid upload'] as $kind) {
                $cases[$transport.' '.$kind] = [$kind, $transport];
            }
        }

        return $cases;
    }

    #[DataProvider('transports')]
    public function test_exact_byte_and_dimension_boundaries_remain_supported_after_reencoding(string $transport): void
    {
        $this->requireGd();
        $diskName = $this->useTransport($transport);
        $source = UploadedFile::fake()->image('portrait.png', 2048, 1)->getContent();
        $file = UploadedFile::fake()->createWithContent('portrait.png', str_pad($source, 2 * 1024 * 1024, 'x'));
        $path = $this->service()->store($file, self::APPLICATION);
        $contents = Storage::disk($diskName)->get($path);
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

    #[DataProvider('r2WriteFailures')]
    public function test_r2_unconfirmed_upload_never_retries_deletes_or_uses_another_transport(bool $throws): void
    {
        $this->requireGd();
        config(['services.supabase_storage.photo_transport' => 'r2']);
        Log::spy();
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->once()->with('media_private')->andReturn($disk);
        $write = $disk->shouldReceive('put')->once()->with(Mockery::type('string'), Mockery::type('string'), Mockery::type('array'));
        $throws ? $write->andThrow(new PrivateMediaStorageException('write_unconfirmed')) : $write->andReturn(false);
        $disk->shouldNotReceive('delete');
        try {
            $this->service()->store(UploadedFile::fake()->image('portrait.png', 8, 8), self::APPLICATION);
            $this->fail('Unconfirmed upload was accepted.');
        } catch (MemberApplicationPhotoUnavailableException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringContainsString('could not be confirmed', $exception->getMessage());
        }
        Log::shouldHaveReceived('warning')->once()->with('Member application photo upload requires attention.',
            ['application_id' => self::APPLICATION, 'code' => 'photo_upload_outcome_uncertain']);
    }

    public static function r2WriteFailures(): array
    {
        return ['unconfirmed exception' => [true], 'false result' => [false]];
    }

    public function test_r2_cleanup_stays_with_selected_transport_and_sanitizes_failure(): void
    {
        config(['services.supabase_storage.photo_transport' => 'r2']);
        $service = $this->service();
        config(['services.supabase_storage.photo_transport' => 'supabase']);
        Log::spy();
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->once()->with('media_private')->andReturn($disk);
        $disk->shouldReceive('delete')->once()->with(self::PATH)->andThrow(new PrivateMediaStorageException('delete_unconfirmed'));
        $service->discard('../invalid', self::APPLICATION);
        $service->discard(self::PATH, self::APPLICATION);
        Log::shouldHaveReceived('warning')->once()->with('Member application photo cleanup requires reconciliation.',
            ['application_id' => self::APPLICATION, 'code' => 'photo_cleanup_failed']);
    }

    #[DataProvider('r2ReadFailures')]
    public function test_r2_reads_distinguish_missing_from_unavailable_and_reject_invalid_bodies(string $scenario): void
    {
        $this->requireGd();
        config(['services.supabase_storage.photo_transport' => 'r2']);
        $png = UploadedFile::fake()->image('portrait.png', 8, 8)->getContent();
        $mimeType = $scenario === 'truncated JPEG image' ? 'image/jpeg' : 'image/png';
        $path = $mimeType === 'image/jpeg' ? str_replace('.png', '.jpg', self::PATH) : self::PATH;
        $body = match ($scenario) {
            'empty body' => '',
            'malformed image' => 'synthetic private provider response',
            'truncated image' => substr($png, 0, 33),
            'missing PNG end' => substr($png, 0, -12),
            'truncated JPEG image' => substr(UploadedFile::fake()->image('portrait.jpg', 8, 8)->getContent(), 0, -2),
            'wrong image format' => UploadedFile::fake()->image('portrait.jpg', 8, 8)->getContent(),
            'excessive dimensions' => UploadedFile::fake()->image('portrait.png', 2049, 1)->getContent(),
            'oversized body' => str_pad($png, 2 * 1024 * 1024 + 1, 'x'),
            default => $png,
        };
        $expected = match ($scenario) {
            'empty metadata' => 0,
            'oversized metadata' => 2 * 1024 * 1024 + 1,
            'empty body' => strlen($png),
            'truncated body' => strlen($body) + 1,
            'long body' => strlen($body) - 1,
            'oversized body' => 2 * 1024 * 1024,
            default => strlen($body),
        };
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->once()->with('media_private')->andReturn($disk);
        $exists = $disk->shouldReceive('exists')->once()->with($path);
        if ($scenario === 'missing') {
            $exists->andReturn(false);
            $disk->shouldNotReceive('readStream');
            $this->assertNull($this->service()->open(self::APPLICATION, $path));

            return;
        }
        $scenario === 'exists failure' ? $exists->andThrow(new PrivateMediaStorageException('exists_failed')) : $exists->andReturn(true);
        $stream = null;
        if ($scenario !== 'exists failure') {
            $size = $disk->shouldReceive('size')->once()->with($path);
            $scenario === 'metadata failure' ? $size->andThrow(new PrivateMediaStorageException('metadata_failed')) : $size->andReturn($expected);
            if (! in_array($scenario, ['empty metadata', 'oversized metadata', 'metadata failure'], true)) {
                $disk->shouldReceive('mimeType')->once()->with($path)->andReturn($scenario === 'wrong metadata MIME' ? 'image/jpeg' : $mimeType);
                if ($scenario !== 'wrong metadata MIME') {
                    $stream = fopen('php://temp', 'w+b');
                    fwrite($stream, $body);
                    rewind($stream);
                    $read = $disk->shouldReceive('readStream')->once()->with($path);
                    $scenario === 'read failure' ? $read->andThrow(new PrivateMediaStorageException('read_failed')) : $read->andReturn($scenario === 'false stream' ? false : $stream);
                }
            }
        }
        $handler = static fn () => false;
        set_error_handler($handler);
        try {
            try {
                $this->service()->open(self::APPLICATION, $path);
                $this->fail('Invalid R2 read was accepted.');
            } catch (MemberApplicationPhotoUnavailableException $exception) {
                $this->assertSame('Photo storage is temporarily unavailable. Please try again later.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
            $previous = set_error_handler(static fn () => false);
            restore_error_handler();
            $this->assertSame($handler, $previous);
            if ($stream !== null && ! in_array($scenario, ['read failure', 'false stream'], true)) $this->assertFalse(is_resource($stream));
        } finally {
            if (is_resource($stream)) fclose($stream);
            restore_error_handler();
        }
    }

    public static function r2ReadFailures(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['missing', 'exists failure', 'metadata failure', 'empty metadata',
            'oversized metadata', 'wrong metadata MIME', 'read failure', 'false stream', 'truncated body', 'long body',
            'empty body', 'malformed image', 'truncated image', 'missing PNG end', 'truncated JPEG image',
            'wrong image format', 'excessive dimensions', 'oversized body']);
    }

    public function test_r2_source_is_closed_before_successful_delivery(): void
    {
        $this->requireGd();
        config(['services.supabase_storage.photo_transport' => 'r2']);
        $body = UploadedFile::fake()->image('portrait.png', 8, 8)->getContent();
        $source = fopen('php://temp', 'w+b');
        fwrite($source, $body);
        rewind($source);
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->once()->with('media_private')->andReturn($disk);
        $disk->shouldReceive('exists')->once()->with(self::PATH)->andReturn(true);
        $disk->shouldReceive('size')->once()->with(self::PATH)->andReturn(strlen($body));
        $disk->shouldReceive('mimeType')->once()->with(self::PATH)->andReturn('image/png');
        $disk->shouldReceive('readStream')->once()->with(self::PATH)->andReturn($source);
        $photo = $this->service()->open(self::APPLICATION, self::PATH);
        try {
            $this->assertFalse(is_resource($source));
            $this->assertSame(0, ftell($photo['stream']));
            $this->assertSame($body, stream_get_contents($photo['stream']));
        } finally {
            fclose($photo['stream']);
        }
    }

    public static function r2ExpiredReads(): array
    {
        return ['metadata deadline' => ['metadata'], 'body deadline' => ['body']];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('r2ExpiredReads')]
    public function test_r2_elapsed_deadline_rejects_slow_metadata_or_body_and_closes_source(string $stage): void
    {
        require __DIR__.'/../Fixtures/member-application-read-clock.php';
        config(['services.supabase_storage.photo_transport' => 'r2']);
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->once()->with('media_private')->andReturn($disk);
        $disk->shouldReceive('exists')->once()->andReturnUsing(function () use ($stage) {
            if ($stage === 'metadata') MemberApplicationReadClock::$now = 31000000000;

            return true;
        });
        $source = null;
        if ($stage === 'body') {
            $source = fopen('php://temp', 'w+b');
            fwrite($source, 'synthetic');
            rewind($source);
            $disk->shouldReceive('size')->once()->andReturn(9);
            $disk->shouldReceive('mimeType')->once()->andReturn('image/png');
            $disk->shouldReceive('readStream')->once()->andReturnUsing(function () use ($source) {
                MemberApplicationReadClock::$now = 31000000000;

                return $source;
            });
        } else {
            $disk->shouldNotReceive('readStream');
        }
        try {
            try {
                $this->service()->open(self::APPLICATION, self::PATH);
                $this->fail('Expired R2 read was accepted.');
            } catch (MemberApplicationPhotoUnavailableException $exception) {
                $this->assertNull($exception->getPrevious());
            }
            if ($stage === 'body') $this->assertFalse(is_resource($source));
        } finally {
            if (is_resource($source)) fclose($source);
        }
    }

    #[DataProvider('r2InterruptedReads')]
    public function test_r2_interrupted_source_is_closed_and_provider_details_are_suppressed(bool $stall): void
    {
        config(['services.supabase_storage.photo_transport' => 'r2']);
        $this->assertTrue(stream_wrapper_register('member-r2-failure', PhotoReadFailureStream::class));
        PhotoReadFailureStream::$closes = 0;
        PhotoReadFailureStream::$stall = $stall;
        $source = fopen('member-r2-failure://image', 'rb');
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('disk')->once()->with('media_private')->andReturn($disk);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(100);
        $disk->shouldReceive('mimeType')->once()->andReturn('image/png');
        $disk->shouldReceive('readStream')->once()->andReturn($source);
        try {
            try {
                $this->service()->open(self::APPLICATION, self::PATH);
                $this->fail('Interrupted read was accepted.');
            } catch (MemberApplicationPhotoUnavailableException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertStringNotContainsString('Synthetic', $exception->getMessage());
            }
            $this->assertFalse(is_resource($source));
            $this->assertSame(1, PhotoReadFailureStream::$closes);
        } finally {
            if (is_resource($source)) fclose($source);
            PhotoReadFailureStream::$stall = false;
            stream_wrapper_unregister('member-r2-failure');
        }
    }

    public static function r2InterruptedReads(): array
    {
        return ['exception' => [false], 'empty non-EOF read' => [true]];
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
