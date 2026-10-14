<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\InvitationActivationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetInvitationPasswordRequest;
use App\Http\Requests\VerifyInvitationRequest;
use App\Services\InvitationActivationContext;
use App\Services\InvitationActivationService;
use App\Services\SupabaseAuthService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class InvitationActivationController extends Controller
{
    public function __construct(
        private SupabaseAuthService $auth,
        private InvitationActivationContext $contexts,
        private InvitationActivationService $activation,
    ) {
    }

    public function showVerification(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('supabase_access_token')) return $this->signedInFailure($request);
        $context = $this->contexts->read($request);
        if ($context !== null) {
            return redirect()->route($context['phase'] === 'password_confirmed' ? 'invitation.recovery' : 'invitation.password');
        }

        return view('auth.invitation-verify');
    }

    public function verify(VerifyInvitationRequest $request): RedirectResponse
    {
        if ($request->session()->has('supabase_access_token')) return $this->signedInFailure($request);
        $this->contexts->forget($request);
        try {
            // Check storage before consuming the provider's single-use token.
            $this->contexts->checkStorage();
            $verified = $this->auth->verifyInvitationToken($request->validated('token_hash'));
            if (! $verified['success']) return $this->failure($request);
            $identity = $this->auth->getInvitationIdentity($verified['access_token']);
            if (! $this->sameIdentity($identity, $verified)) return $this->failure($request);
            $context = $this->activation->identify($identity);
            $context['access_token'] = $verified['access_token'];
            $this->contexts->create($request, $context, $verified['expires_in']);
        } catch (Throwable $exception) {
            Log::notice('Invitation verification stopped.', ['code' => 'activation_verification_failed']);
            return $this->failure($request);
        }

        return redirect()->route('invitation.password');
    }

    public function showPassword(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('supabase_access_token')) return $this->signedInFailure($request);
        $context = $this->contexts->read($request);
        if ($context === null) return $this->failure($request);
        if ($context['phase'] === 'password_confirmed') return redirect()->route('invitation.recovery');
        if ($context['phase'] !== 'verified') return $this->failure($request);
        try {
            $this->verifyContextIdentity($context);
            $this->activation->eligible($context);
        } catch (Throwable $exception) {
            return $this->failure($request);
        }

        return view('auth.invitation-password');
    }

    public function setPassword(SetInvitationPasswordRequest $request): RedirectResponse
    {
        if ($request->session()->has('supabase_access_token')) return $this->signedInFailure($request);
        $context = $this->contexts->read($request);
        if ($context === null) return $this->failure($request);

        try {
            return $this->contexts->storage()->lock('invitation-activation-write:'.$context['invitation_id'], 120)
                ->block(3, function () use ($request) {
                    $context = $this->contexts->read($request);
                    $id = $this->contexts->id($request);
                    if ($context === null || $id === null) return $this->failure($request);
                    if ($context['phase'] === 'password_confirmed') return redirect()->route('invitation.recovery');
                    if ($context['phase'] !== 'verified') return $this->failure($request);
                    $this->verifyContextIdentity($context);
                    $this->activation->eligible($context);

                    // Persist before the provider call; a crash must not permit replay.
                    $context['phase'] = 'password_update_started';
                    $this->contexts->save($id, $context);
                    $claim = $this->contexts->passwordClaim($id);
                    $this->activation->claimPassword($context, $claim);
                    $updated = $this->auth->updateInvitationPassword($context['access_token'], $request->validated('password'));
                    if (! $updated['success'] || ! $this->sameIdentity($updated, $context)) return $this->failure($request);

                    $context['phase'] = 'password_confirmed';
                    $this->contexts->save($id, $context);
                    return $this->finish($request, $context, $claim);
                });
        } catch (LockTimeoutException $exception) {
            return redirect()->route('invitation.password')->withErrors(['activation' => 'An activation request is already running. Wait before continuing.']);
        } catch (Throwable $exception) {
            Log::warning('Invitation password operation stopped.', ['code' => 'activation_reconciliation_required']);
            return $this->failure($request);
        }
    }

    public function showRecovery(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('supabase_access_token')) return $this->signedInFailure($request);
        $context = $this->contexts->read($request);
        $id = $this->contexts->id($request);
        if ($context === null || $id === null || $context['phase'] !== 'password_confirmed') return $this->failure($request);
        try {
            $this->verifyContextIdentity($context);
            $this->activation->eligible($context, $this->contexts->passwordClaim($id));
        } catch (InvitationActivationException $exception) {
            return $this->failure($request);
        } catch (Throwable $exception) {
            // Temporary storage failure can be retried without another password write.
        }

        return view('auth.invitation-recovery');
    }

    public function recover(Request $request): RedirectResponse
    {
        if ($request->session()->has('supabase_access_token')) return $this->signedInFailure($request);
        $context = $this->contexts->read($request);
        if ($context === null || $context['phase'] !== 'password_confirmed') return $this->failure($request);
        try {
            return $this->contexts->storage()->lock('invitation-activation-write:'.$context['invitation_id'], 120)
                ->block(3, function () use ($request) {
                    $context = $this->contexts->read($request);
                    $id = $this->contexts->id($request);
                    if ($context === null || $id === null || $context['phase'] !== 'password_confirmed') return $this->failure($request);
                    $this->verifyContextIdentity($context);

                    return $this->finish($request, $context, $this->contexts->passwordClaim($id));
                });
        } catch (LockTimeoutException $exception) {
            return redirect()->route('invitation.recovery')->withErrors(['activation' => 'An activation request is already running. Wait before continuing.']);
        } catch (Throwable $exception) {
            return $this->failure($request);
        }
    }

    public function result(Request $request): View
    {
        return view('auth.invitation-result', ['succeeded' => $request->session()->get('invitation_activation_succeeded', false)]);
    }

    private function finish(Request $request, array $context, string $claim): RedirectResponse
    {
        if ($context['expires_at'] <= time()) return $this->failure($request);
        try {
            $this->activation->finish($context, $claim);
        } catch (InvitationActivationException $exception) {
            return $this->failure($request);
        } catch (Throwable $exception) {
            Log::warning('Invitation database activation needs recovery.', [
                'invitation_id' => $context['invitation_id'], 'code' => 'activation_database_pending',
            ]);
            return redirect()->route('invitation.recovery');
        }

        $this->contexts->forget($request);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        Log::notice('Invitation activated.', ['invitation_id' => $context['invitation_id'], 'code' => 'activation_completed']);

        return redirect()->route('invitation.result')->with('invitation_activation_succeeded', true);
    }

    private function verifyContextIdentity(array $context): void
    {
        $identity = $this->auth->getInvitationIdentity($context['access_token']);
        if (! $this->sameIdentity($identity, $context)) {
            throw new InvitationActivationException('activation_identity_unavailable');
        }
    }

    private function sameIdentity(?array $identity, array $expected): bool
    {
        return $identity !== null && ($identity['auth_user_id'] ?? null) === $expected['auth_user_id']
            && ($identity['email'] ?? null) === $expected['email'];
    }

    private function failure(Request $request): RedirectResponse
    {
        $this->contexts->forget($request);

        return redirect()->route('invitation.result');
    }

    private function signedInFailure(Request $request): RedirectResponse
    {
        $this->contexts->forget($request);

        return redirect()->route('invitation.result')->with('activation_message', 'Sign out of your current account before opening an invitation.');
    }
}
