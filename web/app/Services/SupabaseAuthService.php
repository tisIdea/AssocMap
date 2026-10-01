<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SupabaseAuthService
{
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
}