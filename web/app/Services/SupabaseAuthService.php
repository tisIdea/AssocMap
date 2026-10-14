<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

class SupabaseAuthService
{
    public function invitationConfigurationAvailable(): bool
    {
        foreach (['url', 'invitation_redirect_url'] as $setting) {
            $value = config('services.supabase.'.$setting);
            if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) return false;
            $parts = parse_url($value);
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])
                || isset($parts['pass']) || isset($parts['fragment'])) return false;
            if ($setting === 'url' && (isset($parts['query']) || ! in_array($parts['path'] ?? '', ['', '/'], true))) return false;
        }

        $key = config('services.supabase.service_role_key');

        // This server-side integration uses the legacy service-role JWT.
        return is_string($key) && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $key) === 1;
    }

    private function adminRequest(): PendingRequest
    {
        $key = config('services.supabase.service_role_key');

        return Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(15)
            ->withOptions(['allow_redirects' => false]);
    }

    public function findInvitationIdentity(string $email): array
    {
        // Filter is a provider search; always compare the returned email exactly.
        $previousTotal = null;
        for ($page = 1; $page <= 5; $page++) {
            try {
                $response = $this->adminRequest()->get(rtrim(config('services.supabase.url'), '/').'/auth/v1/admin/users', [
                    'filter' => $email, 'page' => $page, 'per_page' => 100,
                ]);
            } catch (ConnectionException $exception) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
            }
            $users = $response->json('users');
            if (! $response->successful() || ! is_array($users) || ! array_is_list($users)) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
            }
            foreach ($users as $user) {
                if (! is_array($user) || ! array_key_exists('email', $user)) {
                    return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
                }
                if ($user['email'] === null) continue;
                if (! is_string($user['email'])) return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
                if (mb_strtolower(trim($user['email'])) === $email) {
                    return ['outcome' => 'existing', 'code' => 'existing_identity'];
                }
            }
            $total = $response->header('X-Total-Count');
            if (! is_string($total) || ! ctype_digit($total)
                || ($previousTotal !== null && $previousTotal !== $total)) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'];
            }
            $previousTotal = $total;
            $lastPage = (int) ceil((int) $total / 100);
            $links = [];
            preg_match_all('/<([^>]+)>;\s*rel="(next|last)"/', $response->header('Link') ?? '', $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $parts = parse_url($match[1]);
                if (! is_array($parts)) return ['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'];
                parse_str($parts['query'] ?? '', $query);
                if (isset($links[$match[2]]) || ! is_string($query['page'] ?? null) || ! ctype_digit($query['page'])
                    || ($query['filter'] ?? null) !== $email || ($query['per_page'] ?? null) !== '100') {
                    return ['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'];
                }
                // Read pagination metadata; never follow a provider-supplied URL.
                $links[$match[2]] = (int) $query['page'];
            }
            $expectedCount = max(0, min(100, (int) $total - (($page - 1) * 100)));
            if (($links['last'] ?? null) !== $lastPage || count($users) !== $expectedCount
                || ($page < $lastPage ? ($links['next'] ?? null) !== $page + 1 : isset($links['next']))) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'];
            }
            if ($page >= $lastPage) return ['outcome' => 'absent'];
        }

        return ['outcome' => 'failed', 'code' => 'identity_lookup_limit'];
    }

    public function inviteUser(string $email, string $displayName): array
    {
        $url = rtrim(config('services.supabase.url'), '/').'/auth/v1/invite?'.http_build_query([
            'redirect_to' => config('services.supabase.invitation_redirect_url'),
        ]);
        try {
            // Never retry this write: a timeout may follow successful provisioning.
            $response = $this->adminRequest()->post($url, ['email' => $email, 'data' => ['display_name' => $displayName]]);
        } catch (ConnectionException $exception) {
            return ['outcome' => 'uncertain', 'code' => 'provider_outcome_unknown'];
        }
        if ($response->successful()) {
            $body = $response->json();
            if (! is_array($body) || array_is_list($body) || array_key_exists('code', $body) || array_key_exists('error_code', $body)) {
                return ['outcome' => 'uncertain', 'code' => 'provider_response_invalid'];
            }
            $id = $response->json('id');
            $returnedEmail = $response->json('email');
            $createdAt = $response->json('created_at');
            if (! is_string($id) || ! Str::isUuid($id) || ! is_string($returnedEmail)
                || mb_strtolower(trim($returnedEmail)) !== $email) {
                return ['outcome' => 'uncertain', 'code' => 'provider_response_invalid'];
            }
            $invalidIdentityResponse = ['outcome' => 'uncertain', 'code' => 'provider_response_invalid', 'reconciliation_auth_user_id' => strtolower($id)];
            if (! is_string($createdAt)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $createdAt)
                || ! checkdate((int) substr($createdAt, 5, 2), (int) substr($createdAt, 8, 2), (int) substr($createdAt, 0, 4))
                || (int) substr($createdAt, 11, 2) > 23 || (int) substr($createdAt, 14, 2) > 59
                || (int) substr($createdAt, 17, 2) > 59) return $invalidIdentityResponse;

            try {
                $createdAt = CarbonImmutable::parse($createdAt);
            } catch (InvalidFormatException $exception) {
                return $invalidIdentityResponse;
            }

            return ['outcome' => 'sent', 'auth_user_id' => strtolower($id), 'auth_created_at' => $createdAt];
        }
        $errorCode = $this->invitationErrorCode($response);
        if (in_array($response->status(), [400, 401, 403], true)
            && in_array($errorCode, ['bad_jwt', 'not_admin'], true)) {
            return ['outcome' => 'failed', 'code' => 'provider_auth_rejected'];
        }
        if ($response->status() === 429
            && in_array($errorCode, ['over_request_rate_limit', 'over_email_send_rate_limit'], true)) {
            return ['outcome' => 'failed', 'code' => 'provider_rate_limited'];
        }
        if ($response->clientError()) {
            if (in_array($errorCode, ['email_exists', 'user_already_exists'], true)) {
                return ['outcome' => 'failed', 'code' => 'existing_identity'];
            }
            // Custom Auth hooks can fail after provisioning; do not assume rollback.
            return ['outcome' => 'uncertain', 'code' => 'provider_request_rejected'];
        }

        return ['outcome' => 'uncertain', 'code' => 'provider_outcome_unknown'];
    }

    private function invitationErrorCode(Response $response): ?string
    {
        $body = $response->json();
        if (! is_array($body) || array_is_list($body)) return null;
        $codes = [];
        foreach (['code', 'error_code'] as $field) {
            if (! array_key_exists($field, $body)) continue;
            if ($field === 'code' && is_int($body[$field]) && $body[$field] === $response->status()) continue;
            if (! is_string($body[$field]) || $body[$field] === '') return null;
            $codes[] = $body[$field];
        }
        $headerCode = $response->header('x-sb-error-code');
        if (is_string($headerCode) && $headerCode !== '') $codes[] = $headerCode;
        $codes = array_values(array_unique($codes));
        $known = [
            'bad_jwt' => [400, 401, 403], 'not_admin' => [403],
            'over_request_rate_limit' => [429], 'over_email_send_rate_limit' => [429],
            'email_exists' => [422], 'user_already_exists' => [422],
        ];

        return count($codes) === 1 && isset($known[$codes[0]])
            && in_array($response->status(), $known[$codes[0]], true) ? $codes[0] : null;
    }

    public function signIn(string $email, string $password): array
    {
        $response = Http::withHeaders([
            'apikey' => config('services.supabase.publishable_key'),
            'Content-Type' => 'application/json',
        ])->post(
            config('services.supabase.url') . '/auth/v1/token?grant_type=password',
            [
                'email' => $email,
                'password' => $password,
            ]
        );

        if ($response->failed()) {
            return [
                'success' => false,
                'message' => 'Invalid email or password.',
            ];
        }

        return [
            'success' => true,
            'data' => $response->json(),
        ];
    }

    public function getUser(string $accessToken): ?array
    {
        $response = Http::withHeaders([
            'apikey' => config('services.supabase.publishable_key'),
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get(
            config('services.supabase.url') . '/auth/v1/user'
        );

        return $response->successful()
            ? $response->json()
            : null;
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
        // Supabase email OTP token hashes are hexadecimal SHA-256 hashes.
        if (! preg_match('/\A[a-fA-F0-9]{64}\z/D', $tokenHash)) {
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
        if ($accessToken === '' || ! $this->activationConfigurationAvailable()) return null;
        try {
            $response = $this->activationRequest($accessToken)
                ->get(rtrim(config('services.supabase.url'), '/').'/auth/v1/user');
        } catch (ConnectionException $exception) {
            return null;
        }
        $user = $response->json();
        if (! $response->successful() || ! is_array($user) || array_is_list($user)
            || array_key_exists('error', $user) || array_key_exists('error_code', $user)
            || ! is_string($user['id'] ?? null) || ! Str::isUuid($user['id'])
            || ! is_string($user['email'] ?? null) || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($user['email_confirmed_at'] ?? null) || $user['email_confirmed_at'] === '') return null;

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
