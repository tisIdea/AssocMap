<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UserAccessRevocationService
{
    public const ACTION = 'REVOKE USER ACCESS';

    public function __construct(private AuditLogService $audit)
    {
    }

    public function revoke(Request $request, UserProfile $user): bool
    {
        return DB::transaction(function () use ($request, $user) {
            // Serialize revocations before checking the remaining administrators.
            $role = Role::where('code', 'system_admin')->lockForUpdate()->first();
            abort_unless($role?->is_active, 403);
            $administrators = UserProfile::where('role_id', $role->id)->where('is_active', true)
                ->orderBy('id')->lockForUpdate()->get();
            $actor = $administrators->firstWhere('id', $request->attributes->get('current_user')?->id);
            if ($actor) $actor->setRelation('role', $role);
            abort_unless($actor?->canAccessApplication(throwOnDatabaseError: true), 403);
            if ($actor->id === $user->id) {
                throw ValidationException::withMessages(['confirm_revocation' => 'You cannot revoke your own access.']);
            }

            // Match activation: associations, invitations, profile, then current links.
            $associationIds = AssociationAccount::where('user_id', $user->id)->where('is_active', true)->pluck('association_id')
                ->merge(UserInvitation::whereIn('status', UserInvitation::UNRESOLVED_STATUSES)
                    ->where(fn ($query) => $query->where('auth_user_id', $user->id)
                        ->orWhere('last_error_code', UserInvitation::IDENTITY_CONFLICT_PREFIX.$user->id))
                    ->whereNotNull('association_id')->pluck('association_id'))->unique()->all();
            Association::whereIn('id', $associationIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $invitations = UserInvitation::where(fn ($query) => $query->where('auth_user_id', $user->id)
                ->orWhere('last_error_code', UserInvitation::IDENTITY_CONFLICT_PREFIX.$user->id))
                ->whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->orderBy('id')->lockForUpdate()->get();
            $target = UserProfile::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $links = AssociationAccount::where('user_id', $target->id)->where('is_active', true)
                ->orderBy('id')->lockForUpdate()->get();
            if ($links->pluck('association_id')->diff($associationIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['confirm_revocation' => 'The account appointment changed. Reload before revoking access.']);
            }
            if ($links->isNotEmpty() && ! Schema::hasColumns('association_accounts', ['ended_at', 'ended_by'])) {
                throw ValidationException::withMessages(['confirm_revocation' => 'Association unlinking requires its reviewed database migration. No account or relationship changes were saved.']);
            }
            if ((string) $target->role_id === (string) $role->id && $target->is_active) {
                $available = $administrators->filter(function (UserProfile $administrator) use ($role) {
                    $administrator->setRelation('role', $role);

                    return $administrator->canAccessApplication(throwOnDatabaseError: true);
                });
                if ($available->count() <= 1) {
                    throw ValidationException::withMessages(['confirm_revocation' => 'The last active Administrator cannot be revoked.']);
                }
            }
            // The security migration adds both the profile boundary and approval table atomically.
            $reactivation = array_key_exists('auth_sessions_valid_after', $target->getAttributes())
                ? DB::table('account_reactivations')->where('user_id', $target->id)->where('state', '!=', 'completed')
                    ->whereNull('cancelled_at')->lockForUpdate()->first() : null;
            if (! $reactivation && $links->isEmpty() && ! $target->is_active && $invitations->isEmpty() && DB::table('audit_logs')
                ->where('action_type', self::ACTION)->where('entity_type', 'user_profile')->where('entity_id', $target->id)->exists()) {
                return false;
            }

            $wasActive = $target->is_active;
            $previousStatuses = $invitations->pluck('status', 'id')->all();
            $target->is_active = false;
            $target->save();
            foreach ($links as $link) {
                $link->is_active = false;
                $link->ended_at = now();
                $link->ended_by = $actor->id;
                $link->save();
                $this->audit->record($request, 'END ASSOCIATION ACCOUNT LINK', 'User Management', 'association_account', $link->id,
                    ['association_id' => $link->association_id, 'user_id' => $target->id, 'is_active' => true],
                    ['association_id' => $link->association_id, 'user_id' => $target->id, 'is_active' => false,
                        'ended_at' => $link->ended_at, 'ended_by' => $actor->id],
                    ['association_id', 'user_id', 'is_active', 'ended_at', 'ended_by']);
            }
            foreach ($invitations as $invitation) {
                $invitation->status = 'cancelled';
                $invitation->save();
            }
            if ($reactivation) {
                DB::table('account_reactivations')->where('id', $reactivation->id)->update(['cancelled_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record($request, self::ACTION, 'User Management', 'user_profile', $target->id,
                ['is_active' => $wasActive, 'invitation_statuses' => $previousStatuses],
                ['is_active' => false, 'cancelled_invitation_ids' => $invitations->pluck('id')->all()]
                    + ($reactivation ? ['cancelled_reactivation_id' => $reactivation->id] : [])
                    + ($links->isNotEmpty() ? ['ended_association_link_ids' => $links->pluck('id')->all()] : []),
                ['is_active', 'invitation_statuses', 'cancelled_invitation_ids', 'cancelled_reactivation_id', 'ended_association_link_ids']);

            return true;
        }, 3);
    }
}
