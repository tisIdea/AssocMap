<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffLoginRequest;
use App\Models\UserProfile;
use App\Services\SupabaseAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffAuthController extends Controller
{
    public function store(
        StaffLoginRequest $request,
        SupabaseAuthService $supabaseAuth
    ): RedirectResponse {
        $credentials = $request->validated();

        $result = $supabaseAuth->signIn(
            $credentials['email'],
            $credentials['password']
        );

        if (! ($result['success'] ?? false)) {
            return $this->rejectLogin($request, $result['message'] ?? 'Unable to verify this account. Please try again.');
        }

        $authData = $supabaseAuth->validatedBrowserSession($result['data'] ?? null);
        if (! $authData) {
            return $this->rejectLogin($request, 'Unable to verify this account. Please try again.');
        }
        $supabaseUser = $authData['user'];

        $profile = UserProfile::with('role')->useWritePdo()
            ->find($supabaseUser['id']);

        if (! $profile) {
            return $this->rejectLogin($request, 'This account is not registered in AssocMap.');
        }

        if (! $profile->canAccessApplication($supabaseUser)) {
            return $this->rejectLogin($request, 'This AssocMap account or role is unavailable.');
        }

        if ($profile->auth_sessions_valid_after !== null) {
            $verified = $supabaseAuth->getUser($authData['access_token']);
            if (! $verified || ! app(\App\Services\SupabaseSessionBoundary::class)->allows($profile, $verified, $authData['access_token'])) {
                return $this->rejectLogin($request, 'Fresh password sign-in is required. Wait a moment and sign in again.');
            }
        }

        if (! $profile->role) {
            return $this->rejectLogin($request, 'No system role is assigned to this account.');
        }

        if (! in_array(
            $profile->role->code,
            [
                'system_admin',
                'field_officer',
                'association_account',
            ],
            true
        )) {
            return $this->rejectLogin($request, 'This account is not authorized for web access.');
        }

        $request->session()->regenerate();

        $request->session()->put([
            'supabase_access_token' => $authData['access_token'],
            'supabase_refresh_token' => $authData['refresh_token'] ?? null,
            'user_profile_id' => $profile->id,
            'user_role' => $profile->role->code,
        ]);

        if ($profile->role->code === 'system_admin') {
            return redirect()->route('admin.dashboard');
        }

        if ($profile->role->code === 'field_officer') {
            return redirect()
                ->route('field-officer.dashboard');
        }

        if ($profile->role->code === 'association_account') {
            return redirect()
                ->route('association.dashboard');
        }

        return redirect()->route('home');
    }

    private function rejectLogin(StaffLoginRequest $request, string $message): RedirectResponse
    {
        $request->session()->forget(['supabase_access_token', 'supabase_refresh_token', 'user_profile_id', 'user_role']);

        return back()->withErrors(['email' => $message])->onlyInput('email');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
