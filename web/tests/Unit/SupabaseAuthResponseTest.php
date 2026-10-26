<?php

namespace Tests\Unit;

use App\Services\Auth\SupabaseAuthResponse;
use GuzzleHttp\Psr7\Response as ProviderResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupabaseAuthResponseTest extends TestCase
{
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private function sessionPayload(): array
    {
        return ['access_token' => 'opaque+/=token', 'refresh_token' => null,
            'user' => ['id' => strtoupper(self::ID), 'email' => 'Recipient@Example.test',
                'is_anonymous' => false, 'user_metadata' => ['display_name' => 'Synthetic Recipient']],
            'expires_in' => 3600];
    }

    public function test_valid_payload_preserves_optional_fields_and_normalizes_only_the_identity_id(): void
    {
        $payload = $this->sessionPayload();
        $expected = $payload;
        $expected['user']['id'] = self::ID;
        $this->assertSame($expected, SupabaseAuthResponse::browserSession($payload));
        $this->assertSame(strtoupper(self::ID), $payload['user']['id']);
    }

    #[DataProvider('errorFields')]
    public function test_even_null_error_fields_reject_both_session_and_identity_payloads(string $field): void
    {
        $payload = $this->sessionPayload();
        $this->assertNull(SupabaseAuthResponse::browserSession($payload + [$field => null]));
        $payload['user'][$field] = null;
        $this->assertNull(SupabaseAuthResponse::browserSession($payload));
        $this->assertNull(SupabaseAuthResponse::browserIdentity($payload['user']));
    }

    public static function errorFields(): array
    {
        return [['error'], ['error_code'], ['code']];
    }

    public function test_json_decoder_preserves_the_difference_between_users_lists_and_objects(): void
    {
        foreach (['[]', '{}'] as $users) {
            $response = new Response(new ProviderResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"users":'.$users.'}'));
            $body = SupabaseAuthResponse::jsonObject($response);
            $this->assertInstanceOf(\stdClass::class, $body);
            if ($users === '[]') $this->assertSame([], $body->users);
            else $this->assertInstanceOf(\stdClass::class, $body->users);
        }
    }
}
