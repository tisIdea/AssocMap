<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestInvitationRestartRequest;
use App\Http\Requests\VerifyInvitationRestartRequest;
use App\Services\Auth\InvitationRestartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class InvitationRestartController extends Controller
{
    public function show(): View
    {
        return view('auth.invitation-restart');
    }

    public function requestLink(RequestInvitationRestartRequest $request, InvitationRestartService $restart): RedirectResponse
    {
        $restart->requestLink($request->validated('email'));

        return redirect()->route('invitation.restart')->with('invitation_restart_requested', true);
    }

    public function callback(Request $request, string $reference): View
    {
        return view('auth.invitation-restart-verify', ['reference' => $reference,
            'signedIn' => $request->session()->has('supabase_access_token')]);
    }

    public function verify(VerifyInvitationRestartRequest $request, string $reference, InvitationRestartService $restart): RedirectResponse
    {
        try {
            $restart->verify($request, $reference, $request->validated('token_hash'));
        } catch (Throwable) {
            return redirect()->route('invitation.restart')->withErrors(['restart' =>
                'Recovery could not be completed. Request a fresh link if no password was submitted. If a password was submitted or its outcome is uncertain, contact an Administrator before trying again.']);
        }

        return redirect()->route('invitation.password');
    }
}
