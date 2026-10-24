<?php

namespace App\Services;

use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class SupabaseSessionBoundary
{
    // Call only after the provider has verified this exact bearer token.
    public function allows(UserProfile $profile, array $verifiedIdentity, string $token): bool
    {
        if ($profile->auth_sessions_valid_after === null) {
            return true;
        }
        try {
            if (strlen($token) > 8192 || ! preg_match('/\A[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+\z/D', $token, $parts)) {
                return false;
            }
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($claims) || ($claims['sub'] ?? null) !== $profile->id
                || ($verifiedIdentity['id'] ?? null) !== $profile->id
                || ($claims['iss'] ?? null) !== rtrim(config('services.supabase.url'), '/').'/auth/v1'
                || ($claims['role'] ?? null) !== 'authenticated'
                || ! in_array('authenticated', (array) ($claims['aud'] ?? []), true)
                || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= now()->timestamp
                || ! is_string($claims['session_id'] ?? null) || ! Str::isUuid($claims['session_id'])) {
                return false;
            }
            $passwordProof = collect(is_array($claims['amr'] ?? null) ? $claims['amr'] : [])
                ->contains(fn ($proof) => is_array($proof) && ($proof['method'] ?? null) === 'password'
                    && is_int($proof['timestamp'] ?? null)
                    && $proof['timestamp'] > $profile->auth_sessions_valid_after->timestamp
                    && $proof['timestamp'] <= now()->timestamp);
            if (! $passwordProof) {
                return false;
            }
        } catch (Throwable $exception) {
            return false;
        }

        try {
            // Refresh changes token issuance, not the originating provider session.
            return DB::table('auth.sessions')->select('id')->useWritePdo()->where('id', $claims['session_id'])
                ->where('user_id', $profile->id)->where('created_at', '>', $profile->auth_sessions_valid_after)->exists();
        } catch (Throwable $exception) {
            throw new HttpException(503, 'Session verification is temporarily unavailable.');
        }
    }
}
