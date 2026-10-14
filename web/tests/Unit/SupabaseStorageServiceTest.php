<?php

namespace Tests\Unit;

use App\Services\SupabaseStorageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseStorageServiceTest extends TestCase
{
    private const URL = 'https://storage.example.test/storage/v1';
    private const KEY = 'sb_secret_synthetic_test_only';
    private const BUCKET = 'member-application-photos';
    private const PATH = 'photos/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure();
        Http::fake([]);
        Http::preventStrayRequests();
    }

    private function configure(array $changes = []): void
    {
        config(['services.supabase_storage' => array_replace([
            'photo_transport' => 'supabase', 'url' => 'https://storage.example.test',
            'secret_key' => self::KEY, 'key_type' => 'secret', 'bucket' => self::BUCKET,
            'connect_timeout' => 5, 'timeout' => 20,
        ], $changes)]);
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jv1kAAAAASUVORK5CYII=');
    }

    private function legacyKey(string $role): string
    {
        $encode = static fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $encode('{"alg":"HS256","typ":"JWT"}').'.'.$encode(json_encode(['role' => $role])).'.synthetic_signature';
    }

    public function test_modern_key_upload_uses_raw_image_body_without_bearer_token_or_upsert(): void
    {
        $optionsSeen = [];
        Http::fake(function (Request $request, array $options) use (&$optionsSeen) {
            $optionsSeen = $options;
            return Http::response(['Key' => self::BUCKET.'/'.self::PATH], 200);
        });
        $result = (new SupabaseStorageService)->upload(self::PATH, $this->png(), 'image/png');
        $this->assertSame('uploaded', $result['outcome']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::URL.'/object/'.self::BUCKET.'/'.self::PATH
            && $request->header('apikey') === [self::KEY]
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('Cookie')
            && $request->header('x-upsert') === ['false']
            && $request->header('Content-Type') === ['image/png'] && $request->body() === $this->png());
        $this->assertSame(5, $optionsSeen['connect_timeout']);
        $this->assertSame(5, $optionsSeen['timeout']);
        $this->assertSame(20, $optionsSeen['read_timeout']);
        $this->assertFalse($optionsSeen['allow_redirects']);
        $this->assertTrue($optionsSeen['verify']);
        $this->assertTrue($optionsSeen['stream']);
        $this->assertFalse($optionsSeen['cookies']);
        Http::assertSentCount(1);
    }

    public function test_legacy_service_role_key_requires_explicit_mode_and_uses_jwt_bearer_header(): void
    {
        $key = $this->legacyKey('service_role');
        $this->configure(['key_type' => 'service_role', 'secret_key' => $key]);
        Http::fake([self::URL.'/object/*' => Http::response(['Key' => self::BUCKET.'/'.self::PATH], 200)]);
        $this->assertSame('uploaded', (new SupabaseStorageService)->upload(self::PATH, $this->png(), 'image/png')['outcome']);
        Http::assertSent(fn (Request $request) => $request->header('apikey') === [$key]
            && $request->header('Authorization') === ['Bearer '.$key]);
    }

    public function test_invalid_configuration_fails_closed_without_using_auth_settings(): void
    {
        config(['services.supabase.url' => 'https://auth.example.test',
            'services.supabase.service_role_key' => $this->legacyKey('service_role')]);
        foreach ([['url' => null], ['secret_key' => null], ['secret_key' => 'sb_publishable_synthetic'],
            ['secret_key' => "sb_secret_invalid\r\nheader"], ['key_type' => 'anon'],
            ['key_type' => 'service_role', 'secret_key' => $this->legacyKey('anon')],
            ['secret_key' => $this->legacyKey('service_role')], ['bucket' => 'public-photos'],
            ['url' => 'http://storage.example.test'], ['url' => 'https://user:pass@storage.example.test'],
            ['url' => 'https://storage.example.test/path'], ['url' => 'https://storage.example.test?target=other'],
            ['url' => 'https://storage.example.test#fragment'], ['connect_timeout' => 0],
            ['connect_timeout' => 11], ['timeout' => 61], ['connect_timeout' => 5, 'timeout' => 4]] as $changes) {
            $this->configure($changes);
            $this->assertSame('storage_configuration_invalid',
                (new SupabaseStorageService)->upload(self::PATH, $this->png(), 'image/png')['code']);
        }
        Http::assertNothingSent();
    }

    public function test_invalid_paths_mime_types_and_sizes_never_reach_storage(): void
    {
        $service = new SupabaseStorageService;
        foreach (['../'.self::PATH, self::PATH.'?download=1', self::PATH.'/extra',
            str_replace('/', '\\', self::PATH), 'photos/%2e%2e/photo.png', self::PATH.'.svg'] as $path) {
            $this->assertSame('failed', $service->upload($path, $this->png(), 'image/png')['outcome']);
            $this->assertSame('unavailable', $service->download($path)['outcome']);
            $this->assertSame('unavailable', $service->delete($path)['outcome']);
        }
        $this->assertSame('failed', $service->upload(self::PATH, $this->png(), 'image/jpeg')['outcome']);
        $this->assertSame('failed', $service->upload(self::PATH, '', 'image/png')['outcome']);
        $this->assertSame('failed', $service->upload(self::PATH, str_repeat('x', 2097153), 'image/png')['outcome']);
        Http::assertNothingSent();
    }

    public function test_upload_failures_do_not_retry_delete_or_return_private_response_details(): void
    {
        $sequence = Http::fakeSequence(self::URL.'/object/'.self::BUCKET.'/'.self::PATH);
        foreach ([401, 403, 409, 413, 503, 302, 200] as $status) {
            $sequence->push(['message' => self::KEY.' synthetic private response', 'Key' => 'wrong/object'], $status);
        }
        $service = new SupabaseStorageService;
        foreach (['failed', 'failed', 'failed', 'failed', 'unknown', 'unknown', 'unknown'] as $expected) {
            $result = $service->upload(self::PATH, $this->png(), 'image/png');
            $this->assertSame($expected, $result['outcome']);
            $this->assertStringNotContainsString(self::KEY, json_encode($result));
            $this->assertStringNotContainsString('synthetic private response', json_encode($result));
        }
        Http::assertSentCount(7);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'POST');
    }

    public function test_upload_connection_failure_is_unknown_with_one_attempt(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;
            $this->assertSame('POST', $request->method());
            return Http::failedConnection('Synthetic timeout');
        });
        $this->assertSame('unknown', (new SupabaseStorageService)->upload(self::PATH, $this->png(), 'image/png')['outcome']);
        $this->assertSame(1, $attempts);
    }

    public function test_download_uses_authenticated_endpoint_and_returns_only_bounded_image_content(): void
    {
        Http::fake([self::URL.'/object/authenticated/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $result = (new SupabaseStorageService)->download(self::PATH);
        $this->assertSame('downloaded', $result['outcome']);
        $this->assertSame($this->png(), $result['contents']);
        $this->assertSame('image/png', $result['mime_type']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::URL.'/object/authenticated/'.self::BUCKET.'/'.self::PATH
            && $request->header('apikey') === [self::KEY] && ! $request->hasHeader('Authorization'));
    }

    public function test_missing_objects_are_distinct_from_missing_buckets_and_provider_failures(): void
    {
        $sequence = Http::fakeSequence(self::URL.'/object/authenticated/'.self::BUCKET.'/'.self::PATH);
        $sequence->push(['code' => 'NoSuchKey'], 404)
            ->push(['error' => 'not_found', 'message' => 'Object not found'], 404)
            ->push(['code' => 'NoSuchBucket'], 404)->push([], 401)->push([], 403)->push([], 503)->push([], 404);
        $service = new SupabaseStorageService;
        foreach (['missing', 'missing', 'unavailable', 'unavailable', 'unavailable', 'unavailable', 'unavailable'] as $expected) {
            $this->assertSame($expected, $service->download(self::PATH)['outcome']);
        }
    }

    public function test_oversized_wrong_mime_partial_and_empty_downloads_are_unavailable(): void
    {
        Http::fakeSequence(self::URL.'/object/authenticated/'.self::BUCKET.'/'.self::PATH)
            ->push(str_repeat('x', 2097153), 200, ['Content-Type' => 'image/png'])
            ->push($this->png(), 200, ['Content-Type' => 'text/html'])
            ->push($this->png(), 206, ['Content-Type' => 'image/png'])
            ->push('', 200, ['Content-Type' => 'image/png']);
        $service = new SupabaseStorageService;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->assertSame('unavailable', $service->download(self::PATH)['outcome']);
        }
    }

    public function test_deletion_targets_one_exact_key_and_distinguishes_missing_from_failure(): void
    {
        Http::fakeSequence(self::URL.'/object/'.self::BUCKET)
            ->push([['name' => self::PATH]], 200)->push([], 200)->push(['code' => 'NoSuchKey'], 404)
            ->push(['code' => 'NoSuchBucket'], 404)->push([], 401)->push([], 503)
            ->push([['name' => 'different/object']], 200);
        $service = new SupabaseStorageService;
        foreach (['deleted', 'missing', 'missing', 'unavailable', 'unavailable', 'unavailable', 'unavailable'] as $expected) {
            $this->assertSame($expected, $service->delete(self::PATH)['outcome']);
        }
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === self::URL.'/object/'.self::BUCKET && $request['prefixes'] === [self::PATH]);
        Http::assertSentCount(7);
    }

    public function test_deletion_rejects_unexpected_json_root_types_and_malformed_responses(): void
    {
        $bodies = ['{}', '{"0":{"name":"'.self::PATH.'"}}', 'null', 'true', '1', '"deleted"',
            '{', '', '[null]', '["deleted"]', '[{}]', '[{"name":null}]',
            '[{"name":"'.self::PATH.'"},{"name":"'.self::PATH.'"}]'];
        $sequence = Http::fakeSequence(self::URL.'/object/'.self::BUCKET);
        foreach ($bodies as $body) $sequence->push($body, 200, ['Content-Type' => 'application/json']);
        $service = new SupabaseStorageService;
        foreach ($bodies as $body) {
            $this->assertSame('unavailable', $service->delete(self::PATH)['outcome'], $body);
        }
        Http::assertSentCount(count($bodies));
    }

    public function test_deletion_requires_the_documented_200_status_before_confirming_an_outcome(): void
    {
        Http::fakeSequence(self::URL.'/object/'.self::BUCKET)
            ->push([], 202)->push([['name' => self::PATH]], 201)->push('', 204);
        $service = new SupabaseStorageService;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertSame('unavailable', $service->delete(self::PATH)['outcome']);
        }
        Http::assertSentCount(3);
    }

    public function test_deletion_timeout_is_unavailable_and_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;
            $this->assertSame('DELETE', $request->method());
            $this->assertSame([self::PATH], $request['prefixes']);

            return Http::failedConnection('Synthetic deletion timeout');
        });
        $this->assertSame('unavailable', (new SupabaseStorageService)->delete(self::PATH)['outcome']);
        $this->assertSame(1, $attempts);
    }
}
