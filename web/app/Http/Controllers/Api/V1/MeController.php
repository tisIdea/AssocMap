<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $profile = $request->attributes->get('current_user');

        return response()->json([
            'data' => [
                'id' => $profile->id,
                'display_name' => $profile->display_name,
                'role' => [
                    'code' => $profile->role->code,
                    'name' => $profile->role->name,
                ],
                'is_active' => $profile->is_active,
                'mobile' => $request->attributes->get('mobile_context'),
            ],
        ]);
    }
}
