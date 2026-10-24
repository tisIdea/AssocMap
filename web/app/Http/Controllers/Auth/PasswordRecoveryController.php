<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordRecoveryRequest;
use App\Models\UserProfile;
use App\Services\PasswordRecoveryContext;
use App\Services\SupabasePasswordRecoveryProvider;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PasswordRecoveryController extends Controller
{
    public function __construct(private SupabasePasswordRecoveryProvider $provider, private PasswordRecoveryContext $contexts)
    {
    }

    public function requestLink(PasswordRecoveryRequest $request): RedirectResponse
    {
        $request->session()->forget(['recovery_completed', 'recovery_requested']);
        $confirmation = $this->confirmation($request);
        if ($confirmation !== null && $confirmation['email'] === $request->validated('email')
            && $confirmation['available_at'] > now()->timestamp) {
            return redirect()->route('password.forgot');
        }
        try {
            $this->provider->request($request->validated('email'));
        } catch (Throwable $exception) {
            Log::notice('Password recovery request unconfirmed.', ['code' => 'recovery_request_unconfirmed']);
        }
        $request->session()->put('recovery_request', ['email' => $request->validated('email'),
            'available_at' => now()->timestamp + PasswordRecoveryRequest::COOLDOWN_SECONDS,
            'expires_at' => now()->timestamp + 3600]);

        return redirect()->route('password.forgot')->with('recovery_requested', true);
    }

    public function showRequest(Request $request): View
    {
        if ($request->session()->pull('recovery_completed', false)) {
            $request->session()->forget(['recovery_request', 'recovery_requested']);

            return view('auth.password-recovery', ['step' => 'success']);
        }
        $confirmation = $this->confirmation($request);

        return view('auth.password-recovery', ['step' => $confirmation !== null ? 'confirmation'
            : ($request->session()->get('errors')?->has('recovery') ? 'invalid' : 'forgot'),
            'waitSeconds' => max(0, ($confirmation['available_at'] ?? 0) - now()->timestamp)]);
    }

    public function editRequest(Request $request): RedirectResponse
    {
        $request->session()->forget(['recovery_request', 'recovery_requested', 'recovery_completed']);

        return redirect()->route('password.forgot');
    }

    public function throttled(Request $request, array $headers): Response
    {
        $request->session()->forget('recovery_completed');
        $confirmation = $this->confirmation($request);
        $wait = max(1, (int) ($headers['Retry-After'] ?? PasswordRecoveryRequest::COOLDOWN_SECONDS));
        if ($confirmation !== null) {
            $confirmation['available_at'] = max($confirmation['available_at'], now()->timestamp + $wait);
            $request->session()->put('recovery_request', $confirmation);
            $wait = $confirmation['available_at'] - now()->timestamp;
        }

        return response()->view('auth.password-recovery', ['step' => $confirmation !== null ? 'confirmation' : 'forgot',
            'waitSeconds' => $wait, 'recoveryFailureMessage' => 'Too many recovery requests. Wait before trying again.'], 429, $headers);
    }

    private function confirmation(Request $request): ?array
    {
        $confirmation = $request->session()->get('recovery_request');
        if (! is_array($confirmation) || ! is_string($confirmation['email'] ?? null)
            || ! is_int($confirmation['available_at'] ?? null) || ! is_int($confirmation['expires_at'] ?? null)
            || $confirmation['expires_at'] <= now()->timestamp) {
            $request->session()->forget('recovery_request');
            return null;
        }

        return $confirmation;
    }

    public function callback(Request $request): View
    {
        // Opening an email link must not consume its single-use token.
        return view('auth.password-recovery', ['step' => 'verify',
            'signedIn' => $request->session()->has('supabase_access_token')]);
    }

    public function verify(PasswordRecoveryRequest $request): RedirectResponse
    {
        $signedIn = $request->session()->has('supabase_access_token');
        if ($signedIn && ! $request->boolean('sign_out_current_account')) {
            return $this->verificationFailure($request, 'verify.session_conflict');
        }
        $this->contexts->forget($request);
        $stage = 'verify.storage_check';
        try {
            $this->contexts->checkStorage();
            if ($signedIn) {
                $stage = 'verify.local_sign_out';
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            $stage = 'verify.token_verification';
            $verified = $this->provider->verify($request->validated('token_hash'));
            $stage = 'verify.identity_validation';
            $this->assertIdentity($verified);
            $stage = 'verify.context_create';
            $this->contexts->create($request, $verified);
        } catch (Throwable $exception) {
            return $this->verificationFailure($request, $stage, $exception);
        }

        return redirect()->route('password.reset');
    }

    public function showPassword(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('supabase_access_token')) {
            return $this->verificationFailure($request, 'password_form.session_conflict');
        }
        $stage = 'password_form.context_read';
        try {
            $context = $this->contexts->read($request);
            if ($context === null) {
                return $this->verificationFailure($request, $stage);
            }
            $stage = 'password_form.identity_validation';
            $this->assertIdentity($context);
        } catch (Throwable $exception) {
            return $this->verificationFailure($request, $stage, $exception);
        }

        return view('auth.password-recovery', ['step' => 'reset']);
    }

    public function updatePassword(PasswordRecoveryRequest $request): RedirectResponse
    {
        try {
            $context = $this->contexts->read($request);
            if ($request->session()->has('supabase_access_token') || $context === null) return $this->failure($request);
            return $this->contexts->locked($context['auth_user_id'], function () use ($request) {
                $context = $this->contexts->read($request);
                if ($context === null) return $this->failure($request);
                $this->assertIdentity($context);
                $this->contexts->consume($request, $context);
                $updated = $this->provider->update($context['access_token'], $request->validated('password'));
                if (! $this->sameIdentity($updated, $context)) throw new RuntimeException('recovery_identity_mismatch');
                $this->assertEligible($context);
                $this->contexts->forget($request);
                $request->session()->forget(['recovery_request', 'recovery_requested', 'recovery_completed']);
                $request->session()->regenerate(true);
                $request->session()->regenerateToken();

                return redirect()->route('password.forgot')->with('recovery_completed', true);
            });
        } catch (Throwable $exception) {
            Log::notice('Password recovery update unconfirmed.', ['code' => 'recovery_update_unconfirmed']);
            return $this->failure($request);
        }
    }

    private function assertIdentity(array $context): void
    {
        if (! $this->sameIdentity($this->provider->identity($context['access_token']), $context)) {
            throw new RuntimeException('recovery_identity_mismatch');
        }
        $this->assertEligible($context);
    }

    private function assertEligible(array $context): void
    {
        $profile = UserProfile::query()->useWritePdo()->find($context['auth_user_id']);
        if ($profile) $profile->setRelation('role', $profile->role()->useWritePdo()->first());
        if (! $profile?->canAccessApplication(['id' => $context['auth_user_id'], 'email' => $context['email']], true)) {
            throw new RuntimeException('recovery_account_unavailable');
        }
    }

    private function sameIdentity(array $identity, array $context): bool
    {
        return $identity['auth_user_id'] === $context['auth_user_id'] && $identity['email'] === $context['email'];
    }

    private function verificationFailure(Request $request, string $stage, ?Throwable $exception = null): RedirectResponse
    {
        $reason = $exception?->getMessage();
        $allowed = ['recovery_configuration_unavailable', 'recovery_link_expired',
            'recovery_verification_failed', 'recovery_verification_unavailable', 'recovery_verification_response_invalid',
            'recovery_identity_unavailable', 'recovery_identity_mismatch', 'recovery_account_unavailable',
            'activation_storage_unavailable', 'recovery_storage_unavailable'];
        if (! in_array($reason, $allowed, true)) {
            $reason = match (true) {
                $exception instanceof QueryException => 'profile_query_failed',
                $exception instanceof ConnectionException => 'provider_connection_failed',
                $exception instanceof HttpExceptionInterface => 'identity_provider_unavailable',
                $exception !== null => 'unexpected_failure',
                str_ends_with($stage, 'session_conflict') => 'existing_application_session',
                default => $request->attributes->get('recovery_context_failure', 'context_unavailable'),
            };
        }
        $reference = 'R-'.strtoupper(bin2hex(random_bytes(6)));
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : null;
        if ($stage === 'verify.token_verification' && in_array($reason, ['recovery_link_expired',
            'recovery_verification_failed', 'recovery_verification_unavailable', 'recovery_verification_response_invalid'], true)) {
            $status = $exception?->getCode();
            if (! is_int($status) || $status < 100 || $status > 599) $status = null;
        }
        // Only fixed classifications and an opaque reference may enter recovery logs.
        Log::notice('Password recovery verification stopped.', ['reference' => $reference, 'stage' => $stage,
            'reason' => $reason, 'status' => $status]);
        $message = match (true) {
            $reason === 'recovery_link_expired' => 'This recovery link has expired or has already been used. Request a new recovery link.',
            str_ends_with($stage, 'session_conflict') => 'Reopen the recovery email and confirm whether to sign out of the current account to continue.',
            $stage === 'password_form.context_read' && $exception === null => 'This recovery session is unavailable or has expired. Open a fresh recovery link.',
            default => 'Recovery verification could not be completed. Contact your administrator with the reference below before requesting more links.',
        };

        return $this->failure($request, $message.' No password change was attempted by this request. Reference: '.$reference.'.');
    }

    private function failure(Request $request, ?string $message = null): RedirectResponse
    {
        $this->contexts->forget($request);
        $request->session()->forget(['recovery_request', 'recovery_requested', 'recovery_completed']);

        return redirect()->route('password.forgot')->withErrors(['recovery' => $message ?? 'Recovery could not be completed. If you submitted a password, try signing in once; otherwise request a new recovery link. Contact your administrator if access remains unavailable.']);
    }
}
