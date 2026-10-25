<?php

namespace App\Services\Programs;

use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProgramActorService
{
    public function lock(UserProfile $previous): UserProfile
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Actor eligibility locks require a transaction.');
        }

        $expectedRole = $previous->role?->code;
        abort_unless(in_array($expectedRole, ['system_admin', 'field_officer'], true), 403);
        // Match revocation and reassignment: role, profile, then associations.
        $role = Role::where('code', $expectedRole)->sharedLock()->first();
        $actor = UserProfile::whereKey($previous->id)->sharedLock()->first();
        abort_unless($role && $actor && (string) $actor->role_id === (string) $role->id, 403);
        $actor->setRelation('role', $role);
        abort_unless($actor->canAccessApplication(throwOnDatabaseError: true), 403);
        abort_unless($actor->getRawOriginal('auth_sessions_valid_after') === $previous->getRawOriginal('auth_sessions_valid_after'), 403);

        return $actor;
    }
}
