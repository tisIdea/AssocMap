<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountReactivationRequest;
use App\Models\UserProfile;
use App\Services\AccountReactivationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccountReactivationController extends Controller
{
    public function edit(Request $request, UserProfile $userProfile, AccountReactivationService $service)
    {
        try {
            $review = $service->review($request->attributes->get('current_user'), $userProfile);

            return response()->view('admin.users.reactivation', compact('review'))->header('Cache-Control', 'no-store, private');
        } catch (ValidationException $exception) {
            return redirect()->route('admin.users.show', $userProfile->id)->withErrors($exception->errors());
        }
    }

    public function store(AccountReactivationRequest $request, UserProfile $userProfile, AccountReactivationService $service)
    {
        try {
            $service->requestLink($request, $userProfile, $request->validated('review_hash'));
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            Log::warning('Account reactivation request unconfirmed.', ['code' => 'reactivation_request_unconfirmed']);

            return redirect()->route('admin.users.show', $userProfile->id)->with('error', 'The request could not be confirmed. The account remains inactive. An email may have been sent; review the request before trying again.');
        }

        return redirect()->route('admin.users.show', $userProfile->id)->with('success', 'Reactivation requested. The account remains inactive until the holder verifies their email and replaces their password. Email delivery is not confirmed.');
    }
}
