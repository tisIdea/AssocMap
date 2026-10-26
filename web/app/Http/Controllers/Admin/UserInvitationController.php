<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserInvitationRequest;
use App\Models\Association;
use App\Models\Role;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Auth\UserInvitationService;
use App\Services\Auth\UserManagementQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserInvitationController extends Controller
{
    public function create(Request $request, SupabaseAuthService $auth, UserManagementQueryService $accounts): Response
    {
        $user = $request->attributes->get('current_user');
        abort_unless($user?->canAccessApplication() && $user->role->code === 'system_admin', 403);

        return response()->view('admin.user-invitations.create', [
            'configurationProblems' => $auth->invitationConfigurationProblems(),
            'configurationCached' => app()->configurationIsCached(),
            'accountReferences' => $accounts->invitationReferences($request->old('email'), $request->old('association_id')),
            'roles' => Role::where('is_active', true)->whereIn('code', ['field_officer', 'association_account'])
                ->orderBy('name')->get(['id', 'code', 'name']),
            'associations' => Association::where('status', 'active')->whereNull('archived_at')
                ->whereDoesntHave('associationAccount')->orderBy('name')->get(['id', 'name']),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreUserInvitationRequest $request, UserInvitationService $service): JsonResponse|RedirectResponse
    {
        $result = $service->initiate($request->validated(), $request->attributes->get('current_user'));
        $httpStatus = $result['http_status'];
        unset($result['http_status']);

        if (! $request->expectsJson()) {
            return redirect()->route('admin.user-invitations.create')
                ->with('invitation_result', $result)
                ->withInput($httpStatus === 201 ? [] : $request->only(['email', 'display_name', 'role_id', 'association_id']))
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($result, $httpStatus)->header('Cache-Control', 'no-store');
    }
}
