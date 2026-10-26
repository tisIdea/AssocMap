<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountEmailChangeRequest;
use App\Services\Auth\AccountEmailChangeService;
use App\Services\Auth\EmailChangeContext;
use Illuminate\Http\Request;
use Throwable;

class AccountEmailChangeController extends Controller
{
    public function callback(Request $request, string $reference, string $mailbox)
    {
        return view('auth.email-change', ['step' => 'verify', 'mailbox' => $mailbox, 'reference' => $reference]);
    }

    public function verifyOld(AccountEmailChangeRequest $request, string $reference, AccountEmailChangeService $service)
    {
        return $this->perform($request, $reference, fn () => $service->verifyOld($request, $reference, $request->validated('token_hash')));
    }

    public function progress(Request $request, string $reference, AccountEmailChangeService $service)
    {
        try {
            [, $row] = $service->current($request, $reference);

            return view('auth.email-change', ['step' => $row->phase, 'reference' => $reference, 'row' => $row]);
        } catch (Throwable $exception) {
            return $this->failure($request);
        }
    }

    public function begin(AccountEmailChangeRequest $request, string $reference, AccountEmailChangeService $service)
    {
        return $this->perform($request, $reference, fn () => $service->begin($request, $reference));
    }

    public function confirm(AccountEmailChangeRequest $request, string $reference, AccountEmailChangeService $service)
    {
        return $this->perform($request, $reference, fn () => $service->confirm($request, $reference, $request->validated('token_hash')));
    }

    public function complete(AccountEmailChangeRequest $request, string $reference, AccountEmailChangeService $service)
    {
        try {
            $service->current($request, $reference);
            $service->complete($request, $request->validated('password'));

            return redirect()->route('account-email-change.result')->with('email_change_completed', true);
        } catch (Throwable $exception) {
            return $this->failure($request);
        }
    }

    public function result(Request $request)
    {
        return view('auth.email-change', ['step' => $request->session()->pull('email_change_completed', false) ? 'completed' : 'unavailable']);
    }

    private function perform(Request $request, string $reference, callable $operation)
    {
        try {
            $operation();

            return redirect()->route('account-email-change.progress', $reference);
        } catch (Throwable $exception) {
            return $this->failure($request);
        }
    }

    private function failure(Request $request)
    {
        app(EmailChangeContext::class)->forget($request);

        return redirect()->route('account-email-change.result')->withErrors(['email_change' => 'The change is unconfirmed or unavailable. Access remains subject to the account hold. Contact the Administrator; do not repeat password or email operations.']);
    }
}
