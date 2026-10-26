<?php

namespace Tests\Unit;

use App\Services\Auth\SupabasePasswordRecoveryProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SupabasePasswordRecoveryProviderTest extends TestCase
{
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.example.test', 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic',
            'services.supabase.service_role_key' => 'synthetic.server.signature']);
        Http::fake([]);
    }

    public function test_request_uses_the_configured_callback_public_key_and_no_identity_creation(): void
    {
        Http::fake(['*/auth/v1/recover*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);
        app(SupabasePasswordRecoveryProvider::class)->request('recipient@example.test');
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            return $request->method() === 'POST' && $query['redirect_to'] === 'https://app.example.test/password-recovery'
                && $request['email'] === 'recipient@example.test' && $request->hasHeader('apikey', 'sb_publishable_synthetic')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_verification_uses_recovery_type_and_discards_refresh_token(): void
    {
        Http::fake(['*/auth/v1/verify' => Http::response($this->verificationResponse())]);
        $result = app(SupabasePasswordRecoveryProvider::class)->verify(str_repeat('a', 56));
        $this->assertSame(self::ID, $result['auth_user_id']);
        $this->assertArrayNotHasKey('refresh_token', $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['type'] === 'recovery' && $request['token_hash'] === str_repeat('a', 56));
    }

    #[DataProvider('invalidSessions')]
    public function test_invalid_provider_sessions_do_not_authorize_password_changes(array $changes): void
    {
        Http::fake(['*/auth/v1/verify' => Http::response(array_replace($this->verificationResponse(), $changes))]);
        $this->expectException(RuntimeException::class);
        app(SupabasePasswordRecoveryProvider::class)->verify(str_repeat('a', 56));
    }

    public static function invalidSessions(): array
    {
        return [[['error' => null]], [['code' => 'synthetic_error']], [['expires_in' => 0]],
            [['token_type' => 'unknown']], [['access_token' => "invalid\nvalue"]], [['user' => ['id' => 'invalid']]]];
    }

    #[DataProvider('verificationFailures')]
    public function test_verification_failures_have_fixed_reasons_without_provider_details(int $status, array $body, string $reason): void
    {
        Http::fake(['*/auth/v1/verify' => Http::response($body, $status)]);
        try {
            app(SupabasePasswordRecoveryProvider::class)->verify(str_repeat('a', 56));
            $this->fail('Expected verification failure');
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception->getMessage());
            $this->assertSame($status, $exception->getCode());
        }
        Http::assertSentCount(1);
    }

    public static function verificationFailures(): array
    {
        return [
            [403, ['code' => 'otp_expired', 'msg' => 'Synthetic private detail'], 'recovery_link_expired'],
            [400, ['error_code' => 'otp_expired'], 'recovery_link_expired'],
            [403, ['code' => 'unexpected_private_code'], 'recovery_verification_failed'],
            [429, ['msg' => 'Synthetic private detail'], 'recovery_verification_unavailable'],
            [503, ['msg' => 'Synthetic private detail'], 'recovery_verification_unavailable'],
            [200, ['access_token' => 'malformed'], 'recovery_verification_response_invalid'],
        ];
    }

    public function test_update_uses_recipient_bearer_and_never_retries_uncertain_writes(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request, array $options) use (&$attempts) {
            $attempts++;
            $this->assertSame('PUT', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic_access'));
            $this->assertTrue($request->hasHeader('apikey', 'sb_publishable_synthetic'));
            $this->assertFalse($options['allow_redirects']);
            throw new ConnectionException('Synthetic private failure');
        });
        try {
            app(SupabasePasswordRecoveryProvider::class)->update('synthetic_access', 'Synthetic-Password42!');
            $this->fail('Expected interrupted write');
        } catch (ConnectionException $exception) {
            $this->assertSame(1, $attempts);
        }
    }

    private function verificationResponse(): array
    {
        return ['access_token' => 'synthetic_access', 'refresh_token' => 'synthetic_refresh',
            'token_type' => 'bearer', 'expires_in' => 3600,
            'user' => ['id' => self::ID, 'email' => 'recipient@example.test']];
    }
}
