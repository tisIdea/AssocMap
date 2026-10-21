<?php

namespace App\Services;

use App\Models\Role;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            // Activation locks invitations before their profiles; keep that order.
            $invitations = UserInvitation::where(fn ($query) => $query->where('auth_user_id', $user->id)
                ->orWhere('last_error_code', UserInvitation::IDENTITY_CONFLICT_PREFIX.$user->id))
                ->whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->orderBy('id')->lockForUpdate()->get();
            $target = UserProfile::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ((string) $target->role_id === (string) $role->id && $target->is_active) {
                $available = $administrators->filter(function (UserProfile $administrator) use ($role) {
                    $administrator->setRelation('role', $role);

                    return $administrator->canAccessApplication(throwOnDatabaseError: true);
                });
                if ($available->count() <= 1) {
                    throw ValidationException::withMessages(['confirm_revocation' => 'The last active Administrator cannot be revoked.']);
                }
            }
            if (! $target->is_active && $invitations->isEmpty() && DB::table('audit_logs')
                ->where('action_type', self::ACTION)->where('entity_type', 'user_profile')->where('entity_id', $target->id)->exists()) {
                return false;
            }

            $wasActive = $target->is_active;
            $previousStatuses = $invitations->pluck('status', 'id')->all();
            $target->is_active = false;
            $target->save();
            foreach ($invitations as $invitation) {
                $invitation->status = 'cancelled';
                $invitation->save();
            }
            $this->audit->record($request, self::ACTION, 'User Management', 'user_profile', $target->id,
                ['is_active' => $wasActive, 'invitation_statuses' => $previousStatuses],
                ['is_active' => false, 'cancelled_invitation_ids' => $invitations->pluck('id')->all()],
                ['is_active', 'invitation_statuses', 'cancelled_invitation_ids']);

            return true;
        }, 3);
    }
}
