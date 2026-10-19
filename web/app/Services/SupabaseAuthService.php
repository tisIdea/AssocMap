<?php

namespace App\Services;

use App\Exceptions\InvitationActivationUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupabaseAuthService
{
    public function __construct(private SupabaseInvitationProvider $invitations)
    {
    }

    public function invitationConfigurationAvailable(): bool
    {
        return $this->invitations->invitationConfigurationAvailable();
    }

    public function invitationConfigurationProblems(): array
    {
        return $this->invitations->invitationConfigurationProblems();
    }

    public function findInvitationIdentity(string $email): array
    {
        return $this->invitations->findInvitationIdentity($email);
    }

    public function inviteUser(string $email, string $displayName): array
    {
        return $this->invitations->inviteUser($email, $displayName);
    }

    public function signIn(string $email, string $password): array
    {
        try {
            $response = Http::withHeaders([
                'apikey' => config('services.supabase.publishable_key'),
                'Content-Type' => 'application/json',
            ])->connectTimeout(5)->timeout(15)->withoutRedirecting()->post(
                rtrim(config('services.supabase.url'), '/').'/auth/v1/token?grant_type=password',
                ['email' => $email, 'password' => $password]
            );
        } catch (ConnectionException | \GuzzleHttp\Exception\GuzzleException $exception) {
            return $this->signInFailure('provider_unavailable');
        }

        if ($response->status() === 429 || $response->serverError()) {
            return $this->signInFailure('provider_unavailable');
        }
        $body = SupabaseAuthResponse::jsonObject($response);
        if (! $body) return $this->signInFailure('invalid_provider_response');

        if (in_array($response->status(), [400, 401, 403], true)) {
            foreach (['error_code', 'code', 'error', 'msg'] as $field) {
                if (is_string($body->$field ?? null) && trim($body->$field) !== '') {
                    return $this->signInFailure('authentication_rejected');
                }
            }
        }
        if ($response->status() !== 200) return $this->signInFailure('invalid_provider_response');

        $session = $this->validatedBrowserSession($response->json());
        if (! $session) return $this->signInFailure('invalid_provider_response');

        return ['success' => true, 'data' => $session];
    }

    private function signInFailure(string $code): array
    {
        $message = match ($code) {
            'authentication_rejected' => 'Invalid email or password.',
            'provider_unavailable' => 'Sign-in is temporarily unavailable. Please try again.',
            default => 'Unable to verify this account. Please try again.',
        };

        return ['success' => false, 'code' => $code, 'message' => $message];
    }

    /** Validate locally before successful browser session mutation. */
    public function validatedBrowserSession(mixed $data): ?array
    {
        return SupabaseAuthResponse::browserSession($data);
    }

    /** Null means rejected credentials; provider failures stop the request with 502/503. */
    public function getUser(mixed $accessToken): ?array
    {
        if (! SupabaseAuthResponse::isBearerToken($accessToken)) return null;
        try {
            $response = Http::withHeaders([
                'apikey' => config('services.supabase.publishable_key'),
                'Authorization' => 'Bearer '.$accessToken,
            ])->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->get(rtrim(config('services.supabase.url'), '/').'/auth/v1/user');
        } catch (ConnectionException | \GuzzleHttp\Exception\GuzzleException $exception) {
            throw new HttpException(503, 'Account verification is temporarily unavailable. Please try again.');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new HttpException(503, 'Account verification is temporarily unavailable. Please try again.');
        }
        $body = SupabaseAuthResponse::jsonObject($response);
        if ($body && $response->status() !== 200
            && in_array($this->apiIdentityErrorOutcome($response, $body), ['invalid', 'denied'], true)) {
            return null;
        }
        $identity = $body && $response->status() === 200 ? SupabaseAuthResponse::browserIdentity($response->json()) : null;
        if (! $identity) throw new HttpException(502, 'Unable to verify this account. Please try again.');

        return $identity;
    }

    public function verifyApiIdentity(string $accessToken): array
    {
        if (strlen($accessToken) > 8192
            || ! preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $accessToken)) {
            return ['outcome' => 'invalid'];
        }
        if (! $this->activationConfigurationAvailable()) return ['outcome' => 'unavailable'];
        $key = config('services.supabase.publishable_key');
        if (strlen($key) > 4096 || (! str_starts_with($key, 'sb_publishable_')
            && ! preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $key))) {
            return ['outcome' => 'unavailable'];
        }

        try {
            $response = Http::withHeaders([
                'apikey' => $key,
                'Authorization' => 'Bearer '.$accessToken,
            ])->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false, 'verify' => true, 'cookies' => false])
                ->get(rtrim(config('services.supabase.url'), '/').'/auth/v1/user');
        } catch (ConnectionException | \GuzzleHttp\Exception\GuzzleException $exception) {
            return ['outcome' => 'unavailable'];
        }

        $contents = $response->body();
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($contentType !== 'application/json' || strlen($contents) > 65536) return ['outcome' => 'unavailable'];
        $body = json_decode($contents, false, 64);
        if (! $body instanceof \stdClass) return ['outcome' => 'unavailable'];

        if ($response->status() !== 200) {
            return ['outcome' => $this->apiIdentityErrorOutcome($response, $body)];
        }
        if (property_exists($body, 'error') || property_exists($body, 'error_code') || property_exists($body, 'code')
            || ! is_string($body->id ?? null) || ! Str::isUuid($body->id)
            || (property_exists($body, 'is_anonymous') && ! is_bool($body->is_anonymous))) {
            return ['outcome' => 'unavailable'];
        }
        if (($body->is_anonymous ?? false) === true) return ['outcome' => 'denied'];
        if (! is_string($body->email ?? null) || ! filter_var($body->email, FILTER_VALIDATE_EMAIL)) {
            return ['outcome' => 'unavailable'];
        }

        return ['outcome' => 'verified', 'identity' => [
            'id' => strtolower($body->id), 'email' => mb_strtolower(trim($body->email)),
        ]];
    }

    private function apiIdentityErrorOutcome(Response $response, \stdClass $body): string
    {
        if (! in_array($response->status(), [400, 401, 403, 404], true)) return 'unavailable';
        $codes = [];
        foreach (['error_code', 'code'] as $field) {
            if (! property_exists($body, $field)) continue;
            if ($field === 'code' && is_int($body->$field) && $body->$field === $response->status()) continue;
            if (! is_string($body->$field) || $body->$field === '') return 'unavailable';
            $codes[] = $body->$field;
        }
        $headerCode = $response->header('x-sb-error-code');
        if (is_string($headerCode) && $headerCode !== '') $codes[] = $headerCode;
        $codes = array_values(array_unique($codes));
        if (count($codes) !== 1) return 'unavailable';

        // Only explicit Auth errors establish invalid identity; gateway failures do not.
        if (in_array($codes[0], ['bad_jwt', 'no_authorization', 'session_expired', 'session_not_found',
            'user_not_found', 'unexpected_audience'], true)) return 'invalid';
        if ($codes[0] === 'user_banned' && in_array($response->status(), [401, 403], true)) return 'denied';

        return 'unavailable';
    }

    public function verifyInvitationToken(string $tokenHash): array
    {
        if (! $this->activationConfigurationAvailable()) {
            return ['success' => false, 'code' => 'activation_configuration_missing'];
        }
        // Supabase invitation OTP hashes use hexadecimal SHA-224.
        if (! preg_match('/\A[a-fA-F0-9]{56}\z/D', $tokenHash)) {
            return [
                'success' => false,
                'code' => 'invalid_invitation_token',
            ];
        }

        $url = rtrim(
            config('services.supabase.url'),
            '/'
        ).'/auth/v1/verify';

        try {
            // A single-use token must never be retried automatically.
            $response = $this->activationRequest()->post($url, [
                    'token_hash' => $tokenHash,
                    'type' => 'invite',
                ]);
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'code' => 'verification_unavailable',
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'code' => 'invitation_verification_failed',
            ];
        }

        $data = $response->json();

        if (! is_array($data) || array_is_list($data) || array_key_exists('error', $data) || array_key_exists('error_code', $data)
            || array_key_exists('code', $data)) {
            return [
                'success' => false,
                'code' => 'invalid_provider_response',
            ];
        }

        $user = $data['user'] ?? null;
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $tokenType = $data['token_type'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (
            ! is_array($user)
            || ! is_string($user['id'] ?? null)
            || ! Str::isUuid($user['id'])
            || ! is_string($user['email'] ?? null)
            || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($accessToken)
            || $accessToken === ''
            || ! is_string($refreshToken)
            || $refreshToken === ''
            || $tokenType !== 'bearer'
            || ! is_int($expiresIn)
            || $expiresIn < 60
        ) {
            return [
                'success' => false,
                'code' => 'invalid_provider_response',
            ];
        }

        return [
            'success' => true,
            'code' => 'invitation_verified',
            'auth_user_id' => strtolower($user['id']),
            'email' => mb_strtolower(trim($user['email'])),
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
        ];
    }

    public function updateInvitationPassword(
        string $accessToken,
        string $password
    ): array {
        if (! $this->activationConfigurationAvailable()) {
            return ['success' => false, 'code' => 'activation_configuration_missing'];
        }
        if ($accessToken === '' || $password === '') {
            return [
                'success' => false,
                'code' => 'invalid_request',
            ];
        }

        $url = rtrim(
            config('services.supabase.url'),
            '/'
        ).'/auth/v1/user';

        try {
            // Do not automatically retry password-changing requests.
            $response = $this->activationRequest($accessToken)->put($url, [
                    'password' => $password,
                ]);
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'code' => 'password_update_uncertain',
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'code' => $response->clientError() ? 'password_update_rejected' : 'password_update_uncertain',
            ];
        }

        $user = $response->json();

        if (
            ! is_array($user)
            || array_is_list($user)
            || array_key_exists('error', $user)
            || array_key_exists('error_code', $user)
            || ! is_string($user['id'] ?? null)
            || ! Str::isUuid($user['id'])
            || ! is_string($user['email'] ?? null)
            || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)
        ) {
            return [
                'success' => false,
                'code' => 'password_update_uncertain',
            ];
        }

        return [
            'success' => true,
            'code' => 'password_updated',
            'auth_user_id' => strtolower($user['id']),
            'email' => mb_strtolower(trim($user['email'])),
        ];
    }

    public function getInvitationIdentity(string $accessToken): ?array
    {
        if ($accessToken === '') return null;
        if (! $this->activationConfigurationAvailable()) {
            throw new InvitationActivationUnavailableException('activation_configuration_unavailable');
        }
        try {
            $response = $this->activationRequest($accessToken)
                ->get(rtrim(config('services.supabase.url'), '/').'/auth/v1/user');
        } catch (ConnectionException $exception) {
            throw new InvitationActivationUnavailableException('activation_identity_unavailable');
        }
        if (in_array($response->status(), [401, 403], true)) return null;
        if (! $response->successful()) {
            throw new InvitationActivationUnavailableException('activation_identity_unavailable');
        }
        $user = $response->json();
        if (! is_array($user) || array_is_list($user)
            || array_key_exists('error', $user) || array_key_exists('error_code', $user)
            || ! is_string($user['id'] ?? null) || ! Str::isUuid($user['id'])
            || ! is_string($user['email'] ?? null) || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvitationActivationUnavailableException('activation_identity_response_invalid');
        }
        if (! is_string($user['email_confirmed_at'] ?? null) || $user['email_confirmed_at'] === '') return null;

        return ['auth_user_id' => strtolower($user['id']), 'email' => mb_strtolower(trim($user['email']))];
    }

    public function activationConfigurationAvailable(): bool
    {
        $url = config('services.supabase.url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) return false;
        $key = config('services.supabase.publishable_key');
        if (! is_string($key) || $key === config('services.supabase.service_role_key')) return false;
        if (preg_match('/\Asb_publishable_[A-Za-z0-9_-]+\z/D', $key)) return true;

        // Legacy public keys are permitted only when their role is anon.
        $segments = explode('.', $key);
        if (count($segments) !== 3) return false;
        $payload = json_decode(base64_decode(strtr($segments[1], '-_', '+/'), true) ?: '', true);

        return is_array($payload) && ($payload['role'] ?? null) === 'anon';
    }

    private function activationRequest(?string $accessToken = null): PendingRequest
    {
        $headers = ['apikey' => config('services.supabase.publishable_key')];
        if ($accessToken !== null) $headers['Authorization'] = 'Bearer '.$accessToken;

        return Http::withHeaders($headers)->acceptJson()->asJson()->connectTimeout(5)->timeout(15)
            ->withOptions(['allow_redirects' => false]);
    }
}
