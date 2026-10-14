<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserInvitationRequest;
use App\Services\UserInvitationService;
use Illuminate\Http\JsonResponse;

class UserInvitationController extends Controller
{
    public function store(StoreUserInvitationRequest $request, UserInvitationService $service): JsonResponse
    {
        $result = $service->initiate($request->validated(), $request->attributes->get('current_user'));
        $httpStatus = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $httpStatus)->header('Cache-Control', 'no-store');
    }
}
