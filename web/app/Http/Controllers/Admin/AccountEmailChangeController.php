<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountEmailChangeRequest;
use App\Models\UserProfile;
use App\Services\AccountEmailChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccountEmailChangeController extends Controller
{
    public function edit(Request $request, UserProfile $userProfile)
    {
        abort_unless(config('services.supabase.account_email_change_enabled') === true, 404);
        $pending = DB::table('email_change_requests')->where('user_id', $userProfile->id)->latest('created_at')->first();

        return response()->view('admin.users.email-change', ['target' => $userProfile, 'pending' => $pending, 'review' => null])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function review(AccountEmailChangeRequest $request, UserProfile $userProfile, AccountEmailChangeService $service)
    {
        $review = $service->review($request->attributes->get('current_user'), $userProfile, $request->validated('new_email'));

        return response()->view('admin.users.email-change', ['target' => $review['target'], 'pending' => null, 'review' => $review])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(AccountEmailChangeRequest $request, UserProfile $userProfile, AccountEmailChangeService $service)
    {
        try {
            $service->requestLink($request, $userProfile, $request->validated('new_email'), $request->validated('review_hash'));
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return redirect()->route('admin.users.email-change.edit', $userProfile->id)->with('error',
                'The operation is unconfirmed. An email may have been sent. Review the durable request state; do not repeat it.');
        }

        return redirect()->route('admin.users.email-change.edit', $userProfile->id)->with('success',
            'Request recorded. Application access is held pending both mailbox confirmations and private password setup. Delivery is not confirmed.');
    }

    public function cancel(AccountEmailChangeRequest $request, UserProfile $userProfile, AccountEmailChangeService $service)
    {
        $service->cancel($request, $userProfile);

        return redirect()->route('admin.users.email-change.edit', $userProfile->id)->with('success', 'Unstarted request cancelled. Its links cannot continue the change.');
    }
}
