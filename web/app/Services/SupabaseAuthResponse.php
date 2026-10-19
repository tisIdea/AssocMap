<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

final class SupabaseAuthResponse
{
    public static function jsonObject(Response $response): ?\stdClass
    {
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($contentType !== 'application/json') return null;
        $body = json_decode($response->body());

        return $body instanceof \stdClass ? $body : null;
    }

    public static function isBearerToken(mixed $token): bool
    {
        return is_string($token) && $token !== '' && preg_match('/[\s\p{C}]/u', $token) === 0;
    }

    public static function browserIdentity(mixed $identity): ?array
    {
        if (! is_array($identity) || array_is_list($identity) || self::hasError($identity)) return null;
        if (! is_string($identity['id'] ?? null) || ! Str::isUuid($identity['id'])) return null;
        if (! is_string($identity['email'] ?? null) || ! filter_var($identity['email'], FILTER_VALIDATE_EMAIL)) return null;
        if (array_key_exists('is_anonymous', $identity) && $identity['is_anonymous'] !== false) return null;
        $identity['id'] = strtolower($identity['id']);

        return $identity;
    }

    public static function browserSession(mixed $data): ?array
    {
        if (! is_array($data) || array_is_list($data) || self::hasError($data)) return null;
        if (! self::isBearerToken($data['access_token'] ?? null)) return null;
        if (isset($data['refresh_token']) && ! self::isBearerToken($data['refresh_token'])) return null;
        $identity = self::browserIdentity($data['user'] ?? null);
        if (! $identity) return null;
        $data['user'] = $identity;

        return $data;
    }

    private static function hasError(array $data): bool
    {
        return array_key_exists('error', $data) || array_key_exists('error_code', $data) || array_key_exists('code', $data);
    }
}
