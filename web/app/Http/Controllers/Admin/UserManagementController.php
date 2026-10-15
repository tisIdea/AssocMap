<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UserManagementQueryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class UserManagementController extends Controller
{
    public function index(Request $request, UserManagementQueryService $query): Response
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor?->canAccessApplication() && $actor->role?->code === 'system_admin', 403);
        $users = null;
        try {
            $users = $query->users();
        } catch (QueryException $exception) {
            Log::notice('User register could not be read.', ['code' => 'user_register_unavailable']);
        }

        return response()->view('admin.users.register', ['users' => $users], $users === null ? 503 : 200)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
