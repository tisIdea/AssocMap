<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagementIndexRequest;
use App\Models\UserProfile;
use App\Services\UserManagementQueryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class UserManagementController extends Controller
{
    public function index(UserManagementIndexRequest $request, UserManagementQueryService $query): Response
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor?->canAccessApplication() && $actor->role?->code === 'system_admin', 403);
        $users = null;
        $summary = null;
        $filters = $request->filters();
        try {
            $users = $query->users($filters);
            $summary = $query->summary();
        } catch (QueryException $exception) {
            $users = null;
            Log::notice('User register could not be read.', ['code' => 'user_register_unavailable']);
        }

        return response()->view('admin.users.register', compact('users', 'summary', 'filters'), $users === null ? 503 : 200)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function show(UserManagementIndexRequest $request, UserProfile $userProfile, UserManagementQueryService $query): Response
    {
        $account = null;
        $assigned = null;
        $filters = $request->filters();
        try {
            $account = $query->account($userProfile);
            if ($account['role_code'] === 'field_officer') {
                $assigned = $userProfile->assignedAssociations()->orderBy('name')->orderBy('id')
                    ->paginate(25, ['id', 'name', 'status', 'archived_at'], 'assigned_page')
                    ->appends($filters + ['page' => $request->validated('page')]);
            }
        } catch (QueryException $exception) {
            $account = null;
            Log::notice('User details could not be read.', ['code' => 'user_details_unavailable']);
        }

        $backUrl = route('admin.users.index', $filters + ['page' => $request->validated('page')]);

        return response()->view('admin.users.details', compact('account', 'assigned', 'backUrl'), $account === null ? 503 : 200)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
