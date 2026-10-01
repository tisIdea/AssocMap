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

        if (! $result['success']) {
            return back()
                ->withErrors([
                    'email' => 'Invalid email or password.',
                ])
                ->onlyInput('email');
        }

        $authData = $result['data'];

        $supabaseUser = $authData['user'] ?? null;

        if (! $supabaseUser || empty($supabaseUser['id'])) {
            return back()
                ->withErrors([
                    'email' => 'Unable to verify this account.',
                ])
                ->onlyInput('email');
        }

        $profile = UserProfile::with('role')
            ->find($supabaseUser['id']);

        if (! $profile) {
            return back()
                ->withErrors([
                    'email' => 'This account is not registered in AssocMap.',
                ])
                ->onlyInput('email');
        }

        if (! $profile->is_active) {
            return back()
                ->withErrors([
                    'email' => 'This AssocMap account is inactive.',
                ])
                ->onlyInput('email');
        }

        if (! $profile->role) {
            return back()
                ->withErrors([
                    'email' => 'No system role is assigned to this account.',
                ])
                ->onlyInput('email');
        }

        if (! in_array(
            $profile->role->code,
            ['system_admin', 'field_officer'],
            true
        )) {
            return back()
                ->withErrors([
                    'email' => 'This account is not authorized for staff web access.',
                ])
                ->onlyInput('email');
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

        /*
         * Field Officer dashboard will be implemented in the next user-side phase.
         */
        return redirect()->route('home')
            ->with(
                'status',
                'Field Officer access will be configured next.'
            );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}