<?php

namespace Tests\Unit;

use App\Exceptions\MemberApplicationPhotoUnavailableException;
use App\Http\Requests\StoreMemberApplicationRequest;
use App\Models\MemberApplication;
use App\Services\Members\MemberApplicationPhotoService;
use App\Services\SupabaseStorageService;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as ProviderResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MemberApplicationEvidenceTest extends TestCase
{
    private const APPLICATION_ID = '11111111-1111-4111-8111-111111111111';
    private const PHOTO_ID = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase_storage' => [
            'photo_transport' => 'supabase', 'url' => 'https://storage.example.test',
            'secret_key' => 'sb_secret_synthetic_test_only', 'key_type' => 'secret',
            'bucket' => 'member-application-photos', 'connect_timeout' => 5, 'timeout' => 20,
        ]]);
        Http::fake([]);
        Http::preventStrayRequests();
    }

    private function photos(): MemberApplicationPhotoService
    {
        return new MemberApplicationPhotoService(new SupabaseStorageService);
    }

    public function test_endorsement_is_trimmed_and_validated_without_coercing_invalid_types(): void
    {
        foreach ([["  Officer letter\nSecond line  ", true], ['', false], [" \r\n\t ", false],
            [null, false], [['letter'], false], [123, false], [str_repeat('a', 10000), true],
            [str_repeat('a', 10001), false]] as [$value, $expected]) {
            $request = EvidenceValidationRequest::create('/', 'POST', ['endorsement_letter' => $value]);
            $request->normalize();
            $validator = Validator::make($request->only('endorsement_letter'), [
                'endorsement_letter' => $request->rules()['endorsement_letter'],
            ]);
            $this->assertSame($expected, $validator->passes());
            $this->assertSame(is_string($value) ? trim($value) : $value, $request->input('endorsement_letter'));
        }
    }

    public function test_private_evidence_is_excluded_from_generic_model_serialization(): void
    {
        $application = new MemberApplication([
            'first_name' => 'Synthetic', 'endorsement_letter' => 'Private letter',
            'applicant_photo_path' => 'private-reference',
        ]);

        $this->assertArrayNotHasKey('endorsement_letter', $application->toArray());
        $this->assertArrayNotHasKey('applicant_photo_path', $application->toArray());
        $this->assertSame('Private letter', $application->endorsement_letter);
    }

    public function test_storage_references_are_bound_to_the_application_and_reject_traversal(): void
    {
        $service = $this->photos();
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID;
        $this->assertSame('image/jpeg', $service->mimeTypeFor($path.'.jpg', self::APPLICATION_ID));
        $this->assertSame('image/png', $service->mimeTypeFor($path.'.png', self::APPLICATION_ID));

        foreach ([null, '../'.$path.'.jpg', $path.'.jpg/extra', $path.'.jpg?download=1',
            str_replace('/', '\\', $path).'.jpg', $path.'.svg', 'photos/%2e%2e/photo.jpg',
            'photos/'.self::PHOTO_ID.'/'.self::PHOTO_ID.'.jpg'] as $invalid) {
            $this->assertNull($service->mimeTypeFor($invalid, self::APPLICATION_ID));
        }
    }

    public function test_a_valid_photo_is_reencoded_privately_and_can_be_cleaned_up(): void
    {
        $this->requireGd();
        $uploaded = '';
        Http::fake(function (ClientRequest $request) use (&$uploaded) {
            if ($request->method() === 'POST') {
                $uploaded = $request->body();
                $path = substr($request->url(), strlen('https://storage.example.test/storage/v1/object/member-application-photos/'));
                return Http::response(['Key' => 'member-application-photos/'.$path], 200);
            }
            if ($request->method() === 'GET') return Http::response($uploaded, 200, ['Content-Type' => 'image/png']);
            $this->assertSame('DELETE', $request->method());
            return Http::response([['name' => $request['prefixes'][0]]], 200);
        });
        $service = $this->photos();
        $path = $service->store(UploadedFile::fake()->image('portrait.png', 64, 80), self::APPLICATION_ID);
        $photo = $service->open(self::APPLICATION_ID, $path);
        $this->assertSame('image/png', $photo['mime_type']);
        $info = getimagesizefromstring(stream_get_contents($photo['stream']));
        fclose($photo['stream']);
        $this->assertSame([64, 80, IMAGETYPE_PNG], array_slice($info, 0, 3));
        $service->discard($path, self::PHOTO_ID);
        Http::assertSentCount(2);
        $service->discard($path, self::APPLICATION_ID);
        Http::assertSentCount(3);
    }

    public function test_corrupt_images_are_rejected_before_storage(): void
    {
        $this->requireGd();
        $this->expectException(ValidationException::class);
        $this->photos()->store(
            UploadedFile::fake()->create('portrait.png', 1, 'image/png'), self::APPLICATION_ID
        );
    }

    public function test_image_content_cannot_use_a_misleading_allowed_extension(): void
    {
        $this->requireGd();
        $jpeg = UploadedFile::fake()->image('portrait.jpg', 64, 64);
        $mislabeled = new UploadedFile($jpeg->getPathname(), 'portrait.png', null, null, true);
        $this->expectException(ValidationException::class);
        $this->photos()->store($mislabeled, self::APPLICATION_ID);
    }

    public function test_oversized_uploads_and_excessive_dimensions_are_rejected(): void
    {
        $this->requireGd();
        foreach ([UploadedFile::fake()->create('portrait.png', 2049, 'image/png'),
            UploadedFile::fake()->image('portrait.png', 2049, 1)] as $photo) {
            try {
                $this->photos()->store($photo, self::APPLICATION_ID);
                $this->fail('The invalid photo was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('applicant_photo', $exception->errors());
            }
        }
        Http::assertNothingSent();
    }

    public function test_unknown_upload_is_not_retried_or_deleted_and_reports_only_safe_context(): void
    {
        $this->requireGd();
        $attempts = 0;
        Http::fake(function (ClientRequest $request) use (&$attempts) {
            $attempts++;
            $this->assertSame('POST', $request->method());
            return Http::failedConnection('Synthetic network failure');
        });
        Log::spy();

        try {
            $this->photos()->store(UploadedFile::fake()->image('portrait.png', 64, 64), self::APPLICATION_ID);
            $this->fail('The uncertain upload was accepted.');
        } catch (MemberApplicationPhotoUnavailableException $exception) {
            $this->assertStringContainsString('No application was submitted.', $exception->getMessage());
        }
        $this->assertSame(1, $attempts);
        Log::shouldHaveReceived('warning')->once()->with('Member application photo upload requires attention.', [
            'application_id' => self::APPLICATION_ID, 'code' => 'photo_upload_outcome_uncertain',
        ]);
    }

    public function test_cleanup_failure_is_nonthrowing_and_logs_only_safe_context(): void
    {
        Http::fake(['https://storage.example.test/storage/v1/object/member-application-photos' => Http::response([], 503)]);
        Log::spy();
        $this->photos()->discard('photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png', self::APPLICATION_ID);

        Log::shouldHaveReceived('warning')->once()->with('Member application photo cleanup requires reconciliation.', [
            'application_id' => self::APPLICATION_ID,
            'code' => 'photo_cleanup_failed',
        ]);
    }

    public function test_unconfirmed_empty_object_deletion_logs_a_reconciliation_warning(): void
    {
        Http::fake(['https://storage.example.test/storage/v1/object/member-application-photos'
            => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);
        Log::spy();
        $this->photos()->discard('photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png', self::APPLICATION_ID);

        Log::shouldHaveReceived('warning')->once()->with('Member application photo cleanup requires reconciliation.', [
            'application_id' => self::APPLICATION_ID, 'code' => 'photo_cleanup_failed',
        ]);
        Http::assertSentCount(1);
    }

    public function test_confirmed_empty_array_cleanup_does_not_require_reconciliation(): void
    {
        Http::fake(['https://storage.example.test/storage/v1/object/member-application-photos'
            => Http::response('[]', 200, ['Content-Type' => 'application/json'])]);
        Log::spy();
        $this->photos()->discard('photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png', self::APPLICATION_ID);

        Log::shouldNotHaveReceived('warning');
        Http::assertSentCount(1);
    }

    public function test_cleanup_timeout_is_not_retried_and_logs_reconciliation(): void
    {
        $attempts = 0;
        Http::fake(function (ClientRequest $request) use (&$attempts) {
            $attempts++;
            $this->assertSame('DELETE', $request->method());

            return Http::failedConnection('Synthetic cleanup timeout');
        });
        Log::spy();
        $this->photos()->discard('photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png', self::APPLICATION_ID);

        $this->assertSame(1, $attempts);
        Log::shouldHaveReceived('warning')->once()->with('Member application photo cleanup requires reconciliation.', [
            'application_id' => self::APPLICATION_ID, 'code' => 'photo_cleanup_failed',
        ]);
    }

    public function test_cloud_missing_objects_and_outages_remain_distinct(): void
    {
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        $url = 'https://storage.example.test/storage/v1/object/authenticated/member-application-photos/'.$path;
        Http::fakeSequence($url)->push(['code' => 'NoSuchKey'], 404)->push([], 503);
        $this->assertNull($this->photos()->open(self::APPLICATION_ID, $path));
        $this->expectException(MemberApplicationPhotoUnavailableException::class);
        $this->photos()->open(self::APPLICATION_ID, $path);
    }

    public function test_local_references_remain_supported_without_cloud_fallback(): void
    {
        config(['services.supabase_storage.photo_transport' => 'local']);
        $disk = Storage::fake('member_application_photos');
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        $disk->put($path, 'synthetic local object', 'private');
        $service = $this->photos();
        $photo = $service->open(self::APPLICATION_ID, $path);
        $this->assertSame('synthetic local object', stream_get_contents($photo['stream']));
        fclose($photo['stream']);
        $service->discard($path, self::APPLICATION_ID);
        $disk->assertMissing($path);
        Http::assertNothingSent();
    }

    public function test_supabase_missing_photo_and_failed_cleanup_never_use_matching_local_file(): void
    {
        $disk = Storage::fake('member_application_photos');
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        $disk->put($path, 'synthetic local object', 'private');
        Http::fake([
            'https://storage.example.test/storage/v1/object/authenticated/member-application-photos/'.$path
                => Http::response(['code' => 'NoSuchKey'], 404),
            'https://storage.example.test/storage/v1/object/member-application-photos' => Http::response([], 503),
        ]);
        Log::spy();
        $service = $this->photos();
        $this->assertNull($service->open(self::APPLICATION_ID, $path));
        $service->discard($path, self::APPLICATION_ID);
        $disk->assertExists($path);
        Http::assertSentCount(2);
    }

    public function test_supabase_outage_does_not_fall_back_to_existing_local_photo(): void
    {
        Storage::fake('member_application_photos');
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        Storage::disk('member_application_photos')->put($path, 'synthetic local object', 'private');
        Http::fake(['https://storage.example.test/storage/v1/object/authenticated/member-application-photos/'.$path
            => Http::response([], 503)]);
        $this->expectException(MemberApplicationPhotoUnavailableException::class);
        $this->photos()->open(self::APPLICATION_ID, $path);
    }

    public function test_invalid_provider_is_rejected_without_reading_local_file_or_contacting_storage(): void
    {
        config(['services.supabase_storage.photo_transport' => 'invalid-provider']);
        $disk = Storage::fake('member_application_photos');
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        $disk->put($path, 'synthetic local object', 'private');
        try {
            $this->photos()->open(self::APPLICATION_ID, $path);
            $this->fail('The invalid provider was accepted.');
        } catch (MemberApplicationPhotoUnavailableException $exception) {
            $disk->assertExists($path);
            Http::assertNothingSent();
        }
    }

    public function test_cleanup_keeps_the_provider_selected_when_the_service_was_created(): void
    {
        $disk = Storage::fake('member_application_photos');
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        $disk->put($path, 'synthetic local object', 'private');
        Http::fake(['https://storage.example.test/storage/v1/object/member-application-photos'
            => Http::response([['name' => $path]], 200)]);
        $service = $this->photos();
        config(['services.supabase_storage.photo_transport' => 'local']);
        $service->discard($path, self::APPLICATION_ID);
        $disk->assertExists($path);
        Http::assertSentCount(1);
    }

    #[DataProvider('remoteReadResponses')]
    public function test_provider_body_stream_is_closed_after_success_missing_outage_and_invalid_responses(int $status, string $contents, string $mime, string $outcome): void
    {
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        $stream = Utils::streamFor($contents);
        Http::fake(['https://storage.example.test/storage/v1/object/authenticated/member-application-photos/'.$path =>
            Create::promiseFor(new ProviderResponse($status, ['Content-Type' => $mime], $stream))]);
        $photo = null;
        try {
            try {
                $photo = $this->photos()->open(self::APPLICATION_ID, $path);
                if ($outcome === 'unavailable') $this->fail('Unavailable provider response was accepted.');
                if ($outcome === 'missing') $this->assertNull($photo);
                else {
                    $this->assertSame('image/png', $photo['mime_type']);
                    $this->assertSame($contents, stream_get_contents($photo['stream']));
                }
            } catch (MemberApplicationPhotoUnavailableException $exception) {
                $this->assertSame('unavailable', $outcome);
            }
            $this->assertFalse($stream->isReadable());
            Http::assertSentCount(1);
        } finally {
            if (isset($photo['stream']) && is_resource($photo['stream'])) fclose($photo['stream']);
        }
    }

    public static function remoteReadResponses(): array
    {
        return ['success' => [200, 'synthetic image bytes', 'image/png', 'downloaded'],
            'missing object' => [404, '{"code":"NoSuchKey"}', 'application/json', 'missing'],
            'provider outage' => [503, '{}', 'application/json', 'unavailable'],
            'wrong MIME' => [200, 'synthetic image bytes', 'image/jpeg', 'unavailable'],
            'oversized body' => [200, str_repeat('x', 2 * 1024 * 1024 + 1), 'image/png', 'unavailable']];
    }

    public function test_interrupted_provider_body_read_is_unavailable_and_closes_the_stream_without_retry(): void
    {
        $reads = 0;
        $stream = new PumpStream(function () use (&$reads): never {
            $reads++;
            throw new \RuntimeException('Synthetic interrupted storage body');
        });
        $path = 'photos/'.self::APPLICATION_ID.'/'.self::PHOTO_ID.'.png';
        Http::fake(['https://storage.example.test/storage/v1/object/authenticated/member-application-photos/'.$path =>
            Create::promiseFor(new ProviderResponse(200, ['Content-Type' => 'image/png'], $stream))]);
        try {
            $this->photos()->open(self::APPLICATION_ID, $path);
            $this->fail('Interrupted provider read was accepted.');
        } catch (MemberApplicationPhotoUnavailableException $exception) {
            $this->assertSame(1, $reads);
            $this->assertTrue($stream->eof());
        }
        Http::assertSentCount(1);
    }

    private function requireGd(): void
    {
        foreach (['imagecreatetruecolor', 'imagecreatefromstring', 'imagejpeg', 'imagepng',
            'imagesx', 'imagesy', 'imagealphablending', 'imagesavealpha'] as $function) {
            if (! function_exists($function)) $this->markTestSkipped('PHP GD with JPEG/PNG support is required.');
        }
    }
}

class EvidenceValidationRequest extends StoreMemberApplicationRequest
{
    public function normalize(): void
    {
        $this->prepareForValidation();
    }
}
