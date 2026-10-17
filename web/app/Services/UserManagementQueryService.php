<?php

namespace App\Services;

use App\Models\UserInvitation;
use App\Models\UserProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UserManagementQueryService
{
    public function __construct(private ProfileAuthIdentityService $identities)
    {
    }

    public function users(array $filters = []): LengthAwarePaginator
    {
        $query = $this->profiles();
        if (filled($filters['role'] ?? null)) {
            $query->whereHas('role', fn ($role) => $role->where('code', $filters['role']));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('user_profiles.is_active', $filters['status'] === 'active');
        }
        if (filled($filters['q'] ?? null)) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn ($search) => $search
                ->whereRaw("LOWER(user_profiles.display_name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereIn('user_profiles.id', $this->identities->matchingEmails($pattern)));
        }
        $sort = $filters['sort'] ?? 'name';
        if (in_array($sort, ['newest', 'oldest'], true)) {
            $query->orderBy('user_profiles.created_at', $sort === 'newest' ? 'desc' : 'asc');
        } else {
            $query->orderByRaw('LOWER(user_profiles.display_name) '.($sort === 'name_desc' ? 'DESC' : 'ASC'));
        }
        $users = $query->orderBy('user_profiles.id')->paginate(25)->appends($filters);
        $users->setCollection($this->present($users->getCollection()));

        return $users;
    }

    public function summary(): array
    {
        $counts = UserProfile::query()->leftJoin('roles as register_role', 'register_role.id', '=', 'user_profiles.role_id')
            ->selectRaw("COUNT(*) AS total,
                COUNT(CASE WHEN register_role.code = 'system_admin' THEN 1 END) AS system_admin,
                COUNT(CASE WHEN register_role.code = 'field_officer' THEN 1 END) AS field_officer,
                COUNT(CASE WHEN register_role.code = 'association_account' THEN 1 END) AS association_account,
                COUNT(CASE WHEN user_profiles.is_active = FALSE THEN 1 END) AS inactive")
            ->toBase()->first();

        return array_map('intval', (array) $counts);
    }

    public function account(UserProfile $profile): array
    {
        $profile->load(['role:id,code,name,is_active', 'associationAccount.association:id,name,status,archived_at']);
        $profile->loadCount(['assignedAssociations', 'assignedAssociations as archived_assignments_count' =>
            fn ($query) => $query->whereNotNull('archived_at')]);

        return $this->present(collect([$profile]))->first();
    }

    private function profiles(): Builder
    {
        return UserProfile::query()->select(['id', 'display_name', 'role_id', 'is_active', 'created_at'])
            ->with(['role:id,code,name,is_active', 'associationAccount.association:id,name,status,archived_at'])
            ->withCount(['assignedAssociations', 'assignedAssociations as archived_assignments_count' =>
                fn ($query) => $query->whereNotNull('archived_at')]);
    }

    private function present(Collection $profiles): Collection
    {
        $ids = $profiles->pluck('id')->all();
        $identities = $this->identities->read($ids);
        $invitations = UserInvitation::whereIn('auth_user_id', $ids)->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'auth_user_id', 'email', 'status'])->unique('auth_user_id')->keyBy('auth_user_id');

        return $profiles->map(function (UserProfile $profile) use ($identities, $invitations) {
            $identity = $identities?->get($profile->id);
            $invitation = $invitations->get($profile->id);
            $email = is_string($identity?->email) && filter_var($identity->email, FILTER_VALIDATE_EMAIL)
                ? $identity->email : null;

            return [
                'id' => $profile->id, 'display_name' => $profile->display_name,
                'role' => $profile->role?->name ?? 'Role unavailable', 'role_code' => $profile->role?->code,
                'role_active' => $profile->role?->is_active === true, 'is_active' => $profile->is_active,
                'created_at' => $profile->created_at, 'activated_at' => $profile->activated_at,
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
        });
    }
}
