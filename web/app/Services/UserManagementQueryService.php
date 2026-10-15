<?php

namespace App\Services;

use App\Models\UserInvitation;
use App\Models\UserProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserManagementQueryService
{
    public function __construct(private ProfileAuthIdentityService $identities)
    {
    }

    public function users(): LengthAwarePaginator
    {
        $users = UserProfile::query()->select(['id', 'display_name', 'role_id', 'is_active', 'created_at'])
            ->with(['role:id,code,name,is_active', 'associationAccount.association:id,name,status,archived_at'])
            ->withCount(['assignedAssociations', 'assignedAssociations as archived_assignments_count' =>
                fn ($query) => $query->whereNotNull('archived_at')])
            ->orderBy('display_name')->orderBy('id')->paginate(25);
        $ids = $users->getCollection()->pluck('id')->all();
        $identities = $this->identities->read($ids);
        $invitations = UserInvitation::whereIn('auth_user_id', $ids)->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'auth_user_id', 'email', 'status'])->unique('auth_user_id')->keyBy('auth_user_id');

        $users->setCollection($users->getCollection()->map(function (UserProfile $profile) use ($identities, $invitations) {
            $identity = $identities?->get($profile->id);
            $invitation = $invitations->get($profile->id);
            $email = is_string($identity?->email) && filter_var($identity->email, FILTER_VALIDATE_EMAIL)
                ? $identity->email : null;

            return [
                'id' => $profile->id, 'display_name' => $profile->display_name,
                'role' => $profile->role?->name ?? 'Role unavailable', 'role_code' => $profile->role?->code,
                'role_active' => $profile->role?->is_active === true, 'is_active' => $profile->is_active,
                'email' => $email, 'auth_link' => $identities === null ? 'unavailable' : ($identity === null ? 'missing' : 'linked'),
                'invitation_status' => $invitation?->status,
                'invitation_email' => $invitation?->email,
                'email_mismatch' => $email !== null && $invitation !== null
                    && mb_strtolower(trim($email)) !== mb_strtolower(trim($invitation->email)),
                'assigned_count' => $profile->assigned_associations_count,
                'archived_assigned_count' => $profile->archived_assignments_count,
                'association' => $profile->associationAccount?->association?->name,
                'link_active' => $profile->associationAccount?->is_active,
                'association_available' => $profile->associationAccount?->association?->status === 'active'
                    && $profile->associationAccount->association->archived_at === null,
            ];
        }));

        return $users;
    }
}
