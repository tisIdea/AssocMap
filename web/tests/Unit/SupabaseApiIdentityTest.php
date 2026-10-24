<?php

namespace Tests\Unit;

use App\Services\SupabaseAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupabaseApiIdentityTest extends TestCase
{
    private const URL = 'https://auth.example.test/auth/v1/user';
    private const KEY = 'sb_publishable_synthetic_test_only';
    private const TOKEN = 'synthetic.header.signature';
    private const ID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => self::KEY, 'services.supabase.service_role_key' => null]);
        Http::fake([]);
        Http::preventStrayRequests();
    }

    public function test_verified_identity_is_filtered_and_request_is_bounded_without_retries(): void
    {
        $optionsSeen = [];
        Http::fake(function ($request, array $options) use (&$optionsSeen) {
            $optionsSeen = $options;
            return Http::response(['id' => self::ID, 'email' => 'Synthetic@Example.test',
                'user_metadata' => ['role' => 'system_admin', 'private_note' => 'withheld']], 200);
        });
        $result = app(SupabaseAuthService::class)->verifyApiIdentity(self::TOKEN);
        $this->assertSame(['outcome' => 'verified', 'identity' => [
            'id' => self::ID, 'email' => 'synthetic@example.test',
        ]], $result);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === self::URL
            && $request->header('apikey') === [self::KEY]
            && $request->header('Authorization') === ['Bearer '.self::TOKEN]
            && ! $request->hasHeader('Cookie'));
        $this->assertSame(5, $optionsSeen['connect_timeout']);
        $this->assertSame(15, $optionsSeen['timeout']);
        $this->assertFalse($optionsSeen['allow_redirects']);
        $this->assertTrue($optionsSeen['verify']);
        $this->assertFalse($optionsSeen['cookies']);
        Http::assertSentCount(1);
        $this->assertSame([], DB::getConnections());
    }

    #[DataProvider('errorResponses')]
    public function test_only_explicit_provider_errors_establish_invalid_or_denied_identity(
        int $status, array $body, array $headers, string $outcome): void
    {
        Http::fake([self::URL => Http::response($body, $status, $headers)]);
        $result = app(SupabaseAuthService::class)->verifyApiIdentity(self::TOKEN);
        $this->assertSame(['outcome' => $outcome], $result);
        Http::assertSentCount(1);
        $this->assertSame([], DB::getConnections());
    }

    public static function errorResponses(): array
    {
        return [
            'invalid JWT' => [403, ['error_code' => 'bad_jwt'], [], 'invalid'],
            'expired session' => [401, ['error_code' => 'session_expired'], [], 'invalid'],
            'revoked session' => [403, ['error_code' => 'session_not_found'], [], 'invalid'],
            'deleted identity' => [403, ['error_code' => 'user_not_found'], [], 'invalid'],
            'missing authorization' => [401, ['error_code' => 'no_authorization'], [], 'invalid'],
            'wrong audience' => [400, ['error_code' => 'unexpected_audience'], [], 'invalid'],
            'numeric status and header' => [403, ['code' => 403], ['x-sb-error-code' => 'bad_jwt'], 'invalid'],
            'matching identifiers' => [403, ['error_code' => 'bad_jwt', 'code' => 'bad_jwt'], [], 'invalid'],
            'banned user' => [403, ['error_code' => 'user_banned'], [], 'denied'],
            'gateway credential failure' => [401, ['message' => 'Invalid API key'], [], 'unavailable'],
            'unknown rejection' => [403, ['error_code' => 'unknown_future_error'], [], 'unavailable'],
            'contradictory identifiers' => [403, ['error_code' => 'bad_jwt', 'code' => 'user_banned'], [], 'unavailable'],
            'contradictory header' => [403, ['error_code' => 'bad_jwt'], ['x-sb-error-code' => 'unexpected_failure'], 'unavailable'],
            'contradictory numeric status' => [403, ['error_code' => 'bad_jwt', 'code' => 401], [], 'unavailable'],
            'provider outage with misleading body' => [503, ['error_code' => 'bad_jwt'], [], 'unavailable'],
            'provider rate limit' => [429, ['error_code' => 'over_request_rate_limit'], [], 'unavailable'],
            'redirect' => [302, ['error_code' => 'bad_jwt'], ['Location' => 'https://other.example.test'], 'unavailable'],
        ];
    }

    #[DataProvider('malformedResponses')]
    public function test_unexpected_identity_payloads_are_unavailable(string $body): void
    {
        Http::fake([self::URL => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame(['outcome' => 'unavailable'], app(SupabaseAuthService::class)->verifyApiIdentity(self::TOKEN));
        Http::assertSentCount(1);
    }

    public static function malformedResponses(): array
    {
        return [
            'empty object' => ['{}'], 'array' => ['[]'], 'null' => ['null'], 'malformed JSON' => ['{'],
            'HTML' => ['<html>Unavailable</html>'],
            'invalid UUID' => ['{"id":"invalid","email":"synthetic@example.test"}'],
            'missing email' => ['{"id":"'.self::ID.'"}'],
            'invalid email type' => ['{"id":"'.self::ID.'","email":[]}'],
            'invalid email' => ['{"id":"'.self::ID.'","email":"invalid"}'],
            'error disguised as success' => ['{"id":"'.self::ID.'","email":"synthetic@example.test","error_code":"bad_jwt"}'],
            'invalid anonymous flag' => ['{"id":"'.self::ID.'","email":"synthetic@example.test","is_anonymous":"false"}'],
            'oversized body' => [str_repeat('x', 65537)],
        ];
    }

    public function test_anonymous_identity_is_denied(): void
    {
        Http::fake([self::URL => Http::response(['id' => self::ID,
            'email' => null, 'is_anonymous' => true])]);
        $this->assertSame(['outcome' => 'denied'], app(SupabaseAuthService::class)->verifyApiIdentity(self::TOKEN));
    }

    public function test_json_identity_with_unexpected_content_type_is_unavailable(): void
    {
        Http::fake([self::URL => Http::response(json_encode(['id' => self::ID,
            'email' => 'synthetic@example.test']), 200, ['Content-Type' => 'text/html'])]);
        $this->assertSame(['outcome' => 'unavailable'], app(SupabaseAuthService::class)->verifyApiIdentity(self::TOKEN));
        Http::assertSentCount(1);
    }

    public function test_legacy_anon_key_is_supported_without_using_a_privileged_key(): void
    {
        $encode = static fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $key = $encode('{"alg":"HS256","typ":"JWT"}').'.'.$encode('{"role":"anon"}').'.synthetic_signature';
        config(['services.supabase.publishable_key' => $key]);
        Http::fake([self::URL => Http::response(['id' => self::ID, 'email' => 'synthetic@example.test'])]);
        $service = app(SupabaseAuthService::class);
        $this->assertSame('verified', $service->verifyApiIdentity(self::TOKEN)['outcome']);
        Http::assertSent(fn ($request) => $request->header('apikey') === [$key]
            && $request->header('Authorization') === ['Bearer '.self::TOKEN]);
        $privilegedKey = $encode('{"alg":"HS256","typ":"JWT"}').'.'.$encode('{"role":"service_role"}').'.synthetic_signature';
        config(['services.supabase.publishable_key' => $privilegedKey]);
        $this->assertSame(['outcome' => 'unavailable'], $service->verifyApiIdentity(self::TOKEN));
        Http::assertSentCount(1);
    }

    public function test_timeout_is_unavailable_without_retry_or_sensitive_error_details(): void
    {
        Http::fake([self::URL => Http::failedConnection('Synthetic private context '.self::TOKEN)]);
        $this->assertSame(['outcome' => 'unavailable'], app(SupabaseAuthService::class)->verifyApiIdentity(self::TOKEN));
        Http::assertSentCount(1);
        $this->assertSame([], DB::getConnections());
    }

    public function test_invalid_tokens_and_configuration_never_contact_provider(): void
    {
        $service = app(SupabaseAuthService::class);
        foreach (['', 'not-a-jwt', self::TOKEN."\r\n", str_repeat('x', 8193)] as $token) {
            $this->assertSame(['outcome' => 'invalid'], $service->verifyApiIdentity($token));
        }
        foreach ([['url' => 'http://auth.example.test'], ['url' => 'https://auth.example.test/path'],
            ['url' => 'https://user:pass@auth.example.test'], ['publishable_key' => null],
            ['publishable_key' => 'sb_secret_synthetic_test_only'], ['publishable_key' => str_repeat('x', 4097)]] as $change) {
            config(['services.supabase.url' => 'https://auth.example.test', 'services.supabase.publishable_key' => self::KEY]);
            foreach ($change as $name => $value) config(['services.supabase.'.$name => $value]);
            $this->assertSame(['outcome' => 'unavailable'], $service->verifyApiIdentity(self::TOKEN));
        }
        Http::assertNothingSent();
        $this->assertSame([], DB::getConnections());
    }
}
