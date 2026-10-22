<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountReactivationRequest;
use App\Services\AccountReactivationService;
use App\Services\PasswordRecoveryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AccountReactivationController extends Controller
{
    public function callback(Request $request, string $reference)
    {
        return view('auth.password-recovery', ['reactivation' => true, 'step' => 'verify',
            'signedIn' => $request->session()->has('supabase_access_token'),
            'verificationUrl' => route('account-reactivation.verify', $reference)]);
    }

    public function verify(AccountReactivationRequest $request, string $reference, AccountReactivationService $service)
    {
        try {
            $service->verify($request, $reference, $request->validated('token_hash'));

            return redirect()->route('account-reactivation.password');
        } catch (Throwable $exception) {
            return $this->failure($request);
        }
    }

    public function password(Request $request, AccountReactivationService $service)
    {
        try {
            $service->passwordContext($request);

            return view('auth.password-recovery', ['reactivation' => true, 'step' => 'reset',
                'passwordUpdateUrl' => route('account-reactivation.complete')]);
        } catch (Throwable $exception) {
            return $this->failure($request);
        }
    }

    public function complete(AccountReactivationRequest $request, AccountReactivationService $service)
    {
        try {
            $service->complete($request, $request->validated('password'));

            return redirect()->route('account-reactivation.result')->with('reactivation_completed', true);
        } catch (Throwable $exception) {
            return $this->failure($request);
        }
    }

    public function result(Request $request)
    {
        return view('auth.password-recovery', ['reactivation' => true,
            'step' => $request->session()->pull('reactivation_completed', false) ? 'success' : 'invalid']);
    }

    private function failure(Request $request)
    {
        app(PasswordRecoveryContext::class)->forget($request);
        Log::notice('Account reactivation stopped.', ['code' => 'reactivation_unconfirmed']);

        return redirect()->route('account-reactivation.result')->withErrors(['reactivation' => 'Reactivation could not be completed. The account has not been restored by this request. Contact the Administrator to review expired links, changed relationships, or an uncertain password update before retrying.']);
    }
}
