<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SupabaseEmailChangeProvider
{
    public function __construct(private SupabaseAuthService $auth) {}

    public function begin(string $token, string $email, string $reference): array
    {
        $origin = config('app.url');
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! filter_var($origin, FILTER_VALIDATE_URL)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || ! preg_match('/\A[A-Za-z0-9]{64}\z/D', $reference)) {
            throw new RuntimeException('email_change_configuration_unavailable');
        }
        $response = $this->client($token)->put($this->url('/user').'?'.http_build_query([
            'redirect_to' => rtrim($origin, '/').'/account-email-change/'.$reference.'/confirm',
        ]), ['email' => $email]);
        $identity = $response->successful() && SupabaseAuthResponse::jsonObject($response)
            ? SupabaseAuthResponse::browserIdentity($response->json()) : null;
        if (! $identity) {
            throw new RuntimeException('email_change_start_unconfirmed');
        }

        return ['auth_user_id' => $identity['id'], 'email' => mb_strtolower(trim($identity['email']))];
    }

    public function verify(string $hash): ?array
    {
        if (! preg_match('/\A[a-fA-F0-9]{56}\z/D', $hash)) {
            throw new RuntimeException('email_change_proof_invalid');
        }
        $response = $this->client()->post($this->url('/verify'), ['token_hash' => $hash, 'type' => 'email_change']);
        $body = $response->successful() ? SupabaseAuthResponse::jsonObject($response) : null;
        if (! $body || property_exists($body, 'error') || property_exists($body, 'error_code') || property_exists($body, 'code')) {
            throw new RuntimeException('email_change_verification_unconfirmed');
        }
        // Secure Email Change's first confirmation issues no session and grants no capability.
        if ((array) $body === ['msg' => 'Confirmation link accepted. Please proceed to confirm link sent to the other email']) {
            return null;
        }
        $session = SupabaseAuthResponse::browserSession($response->json());
        if (! $session || ($session['token_type'] ?? null) !== 'bearer' || ! is_int($session['expires_in'] ?? null)
            || $session['expires_in'] < 60 || strlen($session['access_token']) > 8192) {
            throw new RuntimeException('email_change_verification_unconfirmed');
        }

        return ['auth_user_id' => $session['user']['id'], 'email' => mb_strtolower(trim($session['user']['email'])),
            'access_token' => $session['access_token'], 'expires_in' => $session['expires_in']];
    }

    private function client(?string $token = null): PendingRequest
    {
        if (config('services.supabase.account_email_change_enabled') !== true || ! $this->auth->activationConfigurationAvailable()
            || ($token !== null && ! SupabaseAuthResponse::isBearerToken($token))) {
            throw new RuntimeException('email_change_unavailable');
        }
        $headers = ['apikey' => config('services.supabase.publishable_key')];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return Http::withHeaders($headers)->acceptJson()->asJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false]);
    }

    private function url(string $path): string
    {
        return rtrim(config('services.supabase.url'), '/').'/auth/v1'.$path;
    }
}
