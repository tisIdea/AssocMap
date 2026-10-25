<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResendUserInvitationRequest;
use App\Models\UserProfile;
use App\Services\Auth\UserInvitationResendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class UserInvitationResendController extends Controller
{
    public function store(ResendUserInvitationRequest $request, UserProfile $userProfile,
        UserInvitationResendService $service): JsonResponse|RedirectResponse
    {
        try {
            $result = $service->resend($request, $userProfile);
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) throw $exception;
            Log::warning('Invitation resend requires review.', ['code' => 'resend_unconfirmed']);
            $result = ['code' => 'resend_unconfirmed', 'http_status' => 503,
                'message' => 'The resend outcome could not be confirmed. Review the invitation and audit history before retrying.'];
        }
        $status = $result['http_status'];
        unset($result['http_status']);
        if ($request->expectsJson()) return response()->json($result, $status)->header('Cache-Control', 'no-store');

        return redirect()->route('admin.users.show', $userProfile->id)
            ->with($result['code'] === 'provider_accepted' ? 'success' : 'error', $result['message'])->header('Cache-Control', 'no-store');
    }
}
