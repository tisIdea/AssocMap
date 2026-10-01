<?php

namespace App\Http\Middleware;

use App\Models\UserProfile;
use App\Services\SupabaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupabaseApiAuthenticated
{
    public function __construct(
        private SupabaseAuthService $supabaseAuth
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $accessToken = $request->bearerToken();

        if (! $accessToken) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $supabaseUser = $this->supabaseAuth->getUser($accessToken);

        if (! $supabaseUser || empty($supabaseUser['id'])) {
            return response()->json([
                'message' => 'Invalid or expired access token.',
            ], 401);
        }

        $profile = UserProfile::with('role')
            ->find($supabaseUser['id']);

        if (! $profile) {
            return response()->json([
                'message' => 'AssocMap user profile not found.',
            ], 403);
        }

        if (! $profile->is_active) {
            return response()->json([
                'message' => 'This AssocMap account is inactive.',
            ], 403);
        }

        $request->attributes->set('current_user', $profile);

        return $next($request);
    }
}