<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupabasePasswordRecoveryProvider
{
    public function __construct(private SupabaseAuthService $auth)
    {
    }

    public function request(string $email): void
    {
        $this->sendRecovery($email, '/password-recovery');
    }

    public function requestInvitationRestart(string $email, string $reference): void
    {
        if (config('services.supabase.invitation_restart_enabled') !== true
            || ! preg_match('/\A[A-Za-z0-9]{64}\z/D', $reference)) {
            throw new RuntimeException('invitation_restart_unavailable');
        }
        $this->sendRecovery($email, '/invitation/restart/'.$reference);
    }

    private function sendRecovery(string $email, string $callbackPath): void
    {
        $origin = config('app.url');
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! $this->auth->activationConfigurationAvailable() || ! is_array($parts)
            || ! filter_var($origin, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('recovery_configuration_unavailable');
        }
        // The callback is server configured, never supplied by the visitor.
        $url = rtrim(config('services.supabase.url'), '/').'/auth/v1/recover?'.http_build_query([
            'redirect_to' => rtrim($origin, '/').$callbackPath,
        ]);
        $response = $this->requestClient()->post($url, ['email' => $email]);
        $body = SupabaseAuthResponse::jsonObject($response);
        if (! $response->successful() || ! $body || property_exists($body, 'error')
            || property_exists($body, 'error_code') || property_exists($body, 'code')) {
            throw new RuntimeException('recovery_request_unconfirmed');
        }
    }

    public function verify(string $hash): array
    {
        if (! $this->auth->activationConfigurationAvailable()) {
            throw new RuntimeException('recovery_configuration_unavailable');
        }
        if (! preg_match('/\A[a-fA-F0-9]{56}\z/D', $hash)) {
            throw new RuntimeException('recovery_verification_failed');
        }
        // Single-use verification is never retried automatically.
        $response = $this->requestClient()->post(rtrim(config('services.supabase.url'), '/').'/auth/v1/verify', [
            'token_hash' => $hash, 'type' => 'recovery',
        ]);
        $body = SupabaseAuthResponse::jsonObject($response);
        if (! $response->successful()) {
            $code = $body->error_code ?? $body->code ?? null;
            $reason = match (true) {
                in_array($response->status(), [400, 403], true) && $code === 'otp_expired' => 'recovery_link_expired',
                $response->status() === 429 || $response->serverError() => 'recovery_verification_unavailable',
                default => 'recovery_verification_failed',
            };
            throw new RuntimeException($reason, $response->status());
        }
        $session = $body && $response->successful() ? SupabaseAuthResponse::browserSession($response->json()) : null;
        if (! $session || ($session['token_type'] ?? null) !== 'bearer'
            || ! is_int($session['expires_in'] ?? null) || $session['expires_in'] < 60
            || strlen($session['access_token']) > 8192) {
            throw new RuntimeException('recovery_verification_response_invalid', $response->status());
        }

        // Keep no refresh token and create no regular application login session.
        return ['auth_user_id' => $session['user']['id'],
            'email' => mb_strtolower(trim($session['user']['email'])),
            'access_token' => $session['access_token'], 'expires_in' => $session['expires_in']];
    }

    public function identity(string $token): array
    {
        $identity = $this->auth->getUser($token);
        if (! $identity) throw new RuntimeException('recovery_identity_unavailable');

        return ['auth_user_id' => $identity['id'], 'email' => mb_strtolower(trim($identity['email']))];
    }

    public function update(string $token, string $password): array
    {
        if (! $this->auth->activationConfigurationAvailable() || ! SupabaseAuthResponse::isBearerToken($token)) {
            throw new RuntimeException('recovery_configuration_unavailable');
        }
        // The controller persists a consumed context before this non-retryable write.
        $response = $this->requestClient($token)->put(rtrim(config('services.supabase.url'), '/').'/auth/v1/user', [
            'password' => $password,
        ]);
        $body = SupabaseAuthResponse::jsonObject($response);
        $identity = $body && $response->successful() ? SupabaseAuthResponse::browserIdentity($response->json()) : null;
        if (! $identity) throw new RuntimeException('recovery_password_unconfirmed');

        return ['auth_user_id' => $identity['id'], 'email' => mb_strtolower(trim($identity['email']))];
    }

    private function requestClient(?string $token = null): PendingRequest
    {
        $headers = ['apikey' => config('services.supabase.publishable_key')];
        if ($token !== null) $headers['Authorization'] = 'Bearer '.$token;

        return Http::withHeaders($headers)->acceptJson()->asJson()->connectTimeout(5)->timeout(15)
            ->withOptions(['allow_redirects' => false]);
    }
}
