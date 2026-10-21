<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevokeUserAccessRequest;
use App\Models\UserProfile;
use App\Services\UserAccessRevocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class UserAccessController extends Controller
{
    public function revoke(RevokeUserAccessRequest $request, UserProfile $userProfile,
        UserAccessRevocationService $service): JsonResponse|RedirectResponse
    {
        try {
            $changed = $service->revoke($request, $userProfile);
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) throw $exception;
            Log::warning('Account revocation requires review.', ['code' => 'user_revocation_unconfirmed']);
            $message = 'Access revocation could not be confirmed. Reload the account and review its audit history before retrying.';
            if ($request->expectsJson()) return response()->json(['message' => $message], 503)->header('Cache-Control', 'no-store');

            return redirect()->route('admin.users.show', $userProfile->id)->with('error', $message)->header('Cache-Control', 'no-store');
        }
        $message = $changed ? 'Access revoked. Organizational records were preserved and outstanding invitations were cancelled.'
            : 'Access is already revoked. No further changes were made.';
        if ($request->expectsJson()) return response()->json(['message' => $message, 'changed' => $changed])->header('Cache-Control', 'no-store');

        return redirect()->route('admin.users.show', $userProfile->id)->with('success', $message)->header('Cache-Control', 'no-store');
    }
}
