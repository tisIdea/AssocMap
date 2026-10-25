<?php

namespace App\Services\Auth;

use App\Models\UserInvitation;
use App\Models\AuditLog;
use App\Models\AssociationAccount;
use App\Models\UserProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $profile->load(['role:id,code,name,is_active']);
        $profile->loadCount(['assignedAssociations', 'assignedAssociations as archived_assignments_count' =>
            fn ($query) => $query->whereNotNull('archived_at')]);

        return $this->present(collect([$profile]))->first();
    }

    private function profiles(): Builder
    {
        return UserProfile::query()->select(['id', 'display_name', 'role_id', 'is_active', 'created_at', 'activated_at'])
            ->with(['role:id,code,name,is_active'])
            ->withCount(['assignedAssociations', 'assignedAssociations as archived_assignments_count' =>
                fn ($query) => $query->whereNotNull('archived_at')]);
    }

    private function present(Collection $profiles): Collection
    {
        $ids = $profiles->pluck('id')->all();
        $identities = $this->identities->read($ids);
        $links = AssociationAccount::whereIn('user_id', $ids)->with('association:id,name,status,archived_at')->get();
        $associationCounts = AssociationAccount::whereIn('association_id', $links->pluck('association_id')->unique())
            ->where('is_active', true)->groupBy('association_id')->selectRaw('association_id, COUNT(*) AS total')->pluck('total', 'association_id');
        $links = $links->groupBy('user_id');
        $invitations = UserInvitation::whereIn('auth_user_id', $ids)->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'auth_user_id', 'email', 'status'])->unique('auth_user_id')->keyBy('auth_user_id');
        $revocations = AuditLog::query()->useWritePdo()->where('action_type', UserAccessRevocationService::ACTION)
            ->where('entity_type', 'user_profile')->whereIn('entity_id', $ids)
            ->groupBy('entity_id')->selectRaw('entity_id, MAX(performed_at) AS performed_at')->get()->keyBy('entity_id');

        return $profiles->map(function (UserProfile $profile) use ($identities, $invitations, $revocations, $links, $associationCounts) {
            $identity = $identities?->get($profile->id);
            $invitation = $invitations->get($profile->id);
            $email = is_string($identity?->email) && filter_var($identity->email, FILTER_VALIDATE_EMAIL)
                ? $identity->email : null;
            $allLinks = $links->get($profile->id, collect());
            $accountLinks = $allLinks->where('is_active', true);
            $link = $accountLinks->count() === 1 ? $accountLinks->first() : null;
            $association = $link?->association;
            $integrityIssue = ($allLinks->isNotEmpty() && $profile->role?->code !== 'association_account')
                || $accountLinks->count() > 1 || ($link !== null && ($association === null
                || (int) $associationCounts->get($link->association_id, 0) !== 1 || $profile->role?->code !== 'association_account'));
            $relationshipStatus = match (true) {
                $integrityIssue => 'Relationship needs review',
                $link === null => 'No active association linked',
                $association->archived_at !== null => 'Association archived',
                $association->status !== 'active' => 'Association inactive',
                default => 'Active relationship',
            };
            // Relationship hints do not replace the transactional reactivation checks.
            $reactivationBlockedReason = match (true) {
                $integrityIssue => 'Account relationships need review before reactivation.',
                $profile->role?->code === 'association_account' && ($link === null || ! $link->is_active)
                    => AccountReactivationService::ASSOCIATION_APPOINTMENT_REQUIRED,
                $profile->role?->code === 'association_account'
                    && ($association->status !== 'active' || $association->archived_at !== null)
                    => 'The linked association must be active and unarchived before reactivation.',
                $profile->role?->code === 'association_account' && $profile->assigned_associations_count > 0
                    => 'Conflicting Field Officer assignments need review before reactivation.',
                default => null,
            };

            return [
                'id' => $profile->id, 'display_name' => $profile->display_name,
                'role' => $profile->role?->name ?? 'Role unavailable', 'role_code' => $profile->role?->code,
                'role_active' => $profile->role?->is_active === true, 'is_active' => $profile->is_active,
                'revoked_at' => $profile->is_active ? null : $revocations->get($profile->id)?->performed_at,
                'created_at' => $profile->created_at, 'activated_at' => $profile->activated_at,
                'email' => $email, 'auth_link' => $identities === null ? 'unavailable' : ($identity === null ? 'missing' : 'linked'),
                'invitation_status' => $invitation?->status,
                'invitation_email' => $invitation?->email,
                'email_mismatch' => $email !== null && $invitation !== null
                    && ($profile->getAttribute('approved_email') === null || mb_strtolower(trim($email)) !== $profile->approved_email)
                    && mb_strtolower(trim($email)) !== mb_strtolower(trim($invitation->email)),
                'assigned_count' => $profile->assigned_associations_count,
                'archived_assigned_count' => $profile->archived_assignments_count,
                'association' => $integrityIssue ? null : $association?->name,
                'association_relationship_status' => $relationshipStatus,
                'association_integrity_issue' => $integrityIssue,
                'association_history' => $allLinks->where('is_active', false)->map(fn ($historical) => [
                    'id' => $historical->id, 'association' => $historical->association?->name ?? 'Association unavailable',
                    'ended_at' => $historical->ended_at,
                ])->values()->all(),
                'reactivation_blocked_reason' => $reactivationBlockedReason,
                'association_status' => $integrityIssue || $association === null ? null
                    : ($association->archived_at !== null ? 'Archived' : ucfirst($association->status)),
                'link_active' => $integrityIssue ? null : $link?->is_active,
                'association_available' => ! $integrityIssue && $association?->status === 'active' && $association->archived_at === null,
            ];
        });
    }

    public function invitationReferences(mixed $email, mixed $associationId): Collection
    {
        $references = collect();
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($email)));
                $profiles = UserProfile::whereIn('id', $this->identities->matchingEmails($pattern))
                    ->orderBy('id')->get(['id', 'display_name']);
                foreach ($profiles as $profile) $references->push(['id' => $profile->id,
                    'name' => $profile->display_name, 'reason' => 'Current email matches an existing account']);
            } catch (QueryException $exception) {
                Log::notice('Invitation account references unavailable.', ['code' => 'invitation_reference_lookup_unavailable']);
            }
        }
        if (is_string($associationId) && Str::isUuid($associationId)) {
            $links = AssociationAccount::where('association_id', $associationId)->with('userProfile:id,display_name')->get();
            foreach ($links as $link) {
                if ($link->userProfile) $references->push(['id' => $link->userProfile->id,
                    'name' => $link->userProfile->display_name, 'reason' => $link->is_active
                        ? 'Current relationship with the selected association' : 'Ended appointment with the selected association']);
            }
        }

        return $references;
    }
}
