<?php

namespace App\Http\Middleware;

use App\Models\UserProfile;
use App\Services\Auth\SupabaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupabaseAuthenticated
{
    public function __construct(
        private SupabaseAuthService $supabaseAuth
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $accessToken = $request->session()->get('supabase_access_token');

        if (! $accessToken) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Please sign in to continue.',
                ]);
        }

        $supabaseUser = LocalRequestTiming::measure($request, 'supabase_auth',
            fn () => $this->supabaseAuth->getUser($accessToken));

        if (! $supabaseUser || empty($supabaseUser['id'])) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Your session has expired. Please sign in again.',
                ]);
        }

        $profile = LocalRequestTiming::measure($request, 'profile_role',
            fn () => UserProfile::findWithRole($supabaseUser['id'], useWritePdo: true));

        if (! $profile || ! $profile->canAccessApplication($supabaseUser)
            || ! app(\App\Services\Auth\SupabaseSessionBoundary::class)->allows($profile, $supabaseUser, $accessToken)) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'This AssocMap account is unavailable.',
                ]);
        }

        $request->attributes->set('current_user', $profile);
        view()->share('currentUser', $profile);
        return $next($request);
    }
}
