<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupabaseInvitationProvider
{
    private const LOOKUP_PAGE_SIZE = 100;
    private const LOOKUP_PAGE_LIMIT = 5;

    public function invitationConfigurationAvailable(): bool
    {
        return $this->invitationConfigurationProblems() === [];
    }

    public function invitationConfigurationProblems(): array
    {
        $problems = [];
        $names = ['url' => 'SUPABASE_URL', 'invitation_redirect_url' => 'SUPABASE_INVITATION_REDIRECT_URL'];
        foreach ($names as $setting => $name) {
            $value = config('services.supabase.'.$setting);
            if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
                $problems[] = $name.' is missing or is not a valid URL.';
                continue;
            }
            $parts = parse_url($value);
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])
                || isset($parts['pass']) || isset($parts['fragment'])) {
                $problems[] = $name.' must use HTTPS without embedded credentials or a fragment.';
            }
            if ($setting === 'url' && (isset($parts['query']) || ! in_array($parts['path'] ?? '', ['', '/'], true))) {
                $problems[] = 'SUPABASE_URL must be the project origin, without an API path or query.';
            }
        }

        $key = config('services.supabase.service_role_key');

        // This server-side integration uses the legacy service-role JWT.
        if (! is_string($key) || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $key) !== 1) {
            $problems[] = 'SUPABASE_SERVICE_ROLE_KEY is missing or is not in the supported legacy three-part JWT format. This invitation client does not support sb_secret_ keys.';
        }

        return $problems;
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
        for ($page = 1; $page <= self::LOOKUP_PAGE_LIMIT; $page++) {
            try {
                $response = $this->adminRequest()->get(rtrim(config('services.supabase.url'), '/').'/auth/v1/admin/users', [
                    'filter' => $email, 'page' => $page, 'per_page' => self::LOOKUP_PAGE_SIZE,
                ]);
            } catch (ConnectionException $exception) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
            }
            $body = SupabaseAuthResponse::jsonObject($response);
            $users = $body->users ?? null;
            if (! $response->successful() || ! is_array($users)
                || property_exists($body, 'error') || property_exists($body, 'error_code') || property_exists($body, 'code')) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
            }
            $match = $this->lookupMatch($users, $email);
            if ($match !== null) return $match;

            $pagination = $this->lookupPagination($response, $email, $page, count($users));
            if ($pagination === null || ($previousTotal !== null && $previousTotal !== $pagination['total'])) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'];
            }
            $previousTotal = $pagination['total'];
            if ($page >= $pagination['last_page']) return ['outcome' => 'absent'];
        }

        return ['outcome' => 'failed', 'code' => 'identity_lookup_limit'];
    }

    /** Null means no exact match among valid rows. */
    private function lookupMatch(array $users, string $email): ?array
    {
        foreach ($users as $user) {
            if (! $user instanceof \stdClass || ! property_exists($user, 'email')) {
                return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
            }
            if ($user->email === null) continue;
            if (! is_string($user->email)) return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
            if (mb_strtolower(trim($user->email)) === $email) {
                return ['outcome' => 'existing', 'code' => 'existing_identity'];
            }
        }

        return null;
    }

    /** @return array{total: string, last_page: int}|null */
    private function lookupPagination(Response $response, string $email, int $page, int $userCount): ?array
    {
        $total = $response->header('X-Total-Count');
        if (! is_string($total) || ! ctype_digit($total)) return null;
        $lastPage = (int) ceil((int) $total / self::LOOKUP_PAGE_SIZE);
        $links = [];
        preg_match_all('/<([^>]+)>;\s*rel="(next|last)"/', $response->header('Link') ?? '', $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $parts = parse_url($match[1]);
            if (! is_array($parts)) return null;
            parse_str($parts['query'] ?? '', $query);
            if (isset($links[$match[2]]) || ! is_string($query['page'] ?? null) || ! ctype_digit($query['page'])
                || ($query['filter'] ?? null) !== $email || ($query['per_page'] ?? null) !== (string) self::LOOKUP_PAGE_SIZE) {
                return null;
            }
            // Read pagination metadata; never follow a provider-supplied URL.
            $links[$match[2]] = (int) $query['page'];
        }
        $expectedCount = max(0, min(self::LOOKUP_PAGE_SIZE, (int) $total - (($page - 1) * self::LOOKUP_PAGE_SIZE)));
        $expectedNextPage = $page < $lastPage ? $page + 1 : null;
        if (($links['last'] ?? null) !== $lastPage || $userCount !== $expectedCount
            || ($links['next'] ?? null) !== $expectedNextPage) {
            return null;
        }

        return ['total' => $total, 'last_page' => $lastPage];
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
            return $this->invitationSuccessResult($response->json(), $email);
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
        if (! $response->clientError()) return ['outcome' => 'uncertain', 'code' => 'provider_outcome_unknown'];
        if (in_array($errorCode, ['email_exists', 'user_already_exists'], true)) {
            return ['outcome' => 'failed', 'code' => 'existing_identity'];
        }
        // Custom Auth hooks can fail after provisioning; do not assume rollback.
        return ['outcome' => 'uncertain', 'code' => 'provider_request_rejected'];
    }

    public function existingInvitationIdentity(string $id, string $email): array
    {
        if (! Str::isUuid($id)) return ['outcome' => 'failed', 'code' => 'identity_mismatch'];
        try {
            $response = $this->adminRequest()->get(rtrim(config('services.supabase.url'), '/').'/auth/v1/admin/users/'.$id);
        } catch (ConnectionException $exception) {
            return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
        }
        $body = SupabaseAuthResponse::jsonObject($response);
        if (! $response->successful() || ! $body || property_exists($body, 'error')
            || property_exists($body, 'error_code') || property_exists($body, 'code')) {
            return ['outcome' => 'failed', 'code' => 'identity_lookup_failed'];
        }
        if (! is_string($body->id ?? null) || strtolower($body->id) !== strtolower($id)
            || ! is_string($body->email ?? null) || mb_strtolower(trim($body->email)) !== $email
            || $this->invitationCreatedAt($body->invited_at ?? null) === null) {
            return ['outcome' => 'failed', 'code' => 'identity_mismatch'];
        }
        if (($body->email_confirmed_at ?? null) !== null) {
            return ['outcome' => 'failed', 'code' => 'identity_already_confirmed'];
        }

        return ['outcome' => 'eligible'];
    }

    public function resendInvitation(string $id, string $email, string $displayName): array
    {
        if (! Str::isUuid($id)) return ['outcome' => 'failed', 'code' => 'identity_mismatch'];
        // The caller must preflight the bound, unconfirmed identity immediately before this write.
        $result = $this->inviteUser($email, $displayName);
        if ($result['outcome'] === 'sent' && $result['auth_user_id'] !== strtolower($id)) {
            return ['outcome' => 'uncertain', 'code' => 'identity_mismatch',
                'reconciliation_auth_user_id' => $result['auth_user_id']];
        }

        return $result;
    }

    private function invitationSuccessResult(mixed $body, string $email): array
    {
        $invalidResponse = ['outcome' => 'uncertain', 'code' => 'provider_response_invalid'];
        if (! is_array($body) || array_is_list($body) || array_key_exists('error', $body)
            || array_key_exists('code', $body) || array_key_exists('error_code', $body)) {
            return $invalidResponse;
        }
        $id = $body['id'] ?? null;
        $returnedEmail = $body['email'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id) || ! is_string($returnedEmail)
            || mb_strtolower(trim($returnedEmail)) !== $email) {
            return $invalidResponse;
        }
        $authUserId = strtolower($id);
        $createdAt = $this->invitationCreatedAt($body['created_at'] ?? null);
        if ($createdAt === null) return $invalidResponse + ['reconciliation_auth_user_id' => $authUserId];

        return ['outcome' => 'sent', 'auth_user_id' => $authUserId, 'auth_created_at' => $createdAt];
    }

    private function invitationCreatedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value) !== 1) {
            return null;
        }
        if (! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) return null;
        if ((int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59 || (int) substr($value, 17, 2) > 59) return null;

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException $exception) {
            return null;
        }
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
        if (count($codes) !== 1) return null;
        $errorCode = $codes[0];
        $known = [
            'bad_jwt' => [400, 401, 403], 'not_admin' => [403],
            'over_request_rate_limit' => [429], 'over_email_send_rate_limit' => [429],
            'email_exists' => [422], 'user_already_exists' => [422],
        ];

        return in_array($response->status(), $known[$errorCode] ?? [], true) ? $errorCode : null;
    }

}
