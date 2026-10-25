<?php

namespace App\Services\Associations;

use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class FieldOfficerAssignmentService
{
    public function __construct(private AuditLogService $audit, private AssociationLifecycleService $lifecycle) {}

    public function eligibleOfficer(UserProfile $officer): bool
    {
        $officer->loadMissing('role');

        return $officer->role?->code === 'field_officer' && $officer->activated_at !== null
            && $officer->canAccessApplication(throwOnDatabaseError: true)
            && ! AssociationAccount::where('user_id', $officer->id)->exists()
            && ! UserInvitation::whereIn('status', UserInvitation::UNRESOLVED_STATUSES)
                ->where('last_error_code', UserInvitation::IDENTITY_CONFLICT_PREFIX.$officer->id)->exists();
    }

    /** @return array{UserProfile, ?UserProfile} */
    public function lockParticipants(?UserProfile $administrator, ?string $officerId = null, string $field = 'officer'): array
    {
        $this->requireTransaction();
        abort_unless($administrator && Str::isUuid($administrator->id), 403);
        // Revocation locks roles before profiles. Keep that order for every writer.
        $roles = Role::whereIn('code', $officerId === null ? ['system_admin'] : ['system_admin', 'field_officer'])
            ->orderBy('id')->sharedLock()->get()->keyBy('id');
        $ids = array_values(array_unique(array_filter([$administrator->id, $officerId])));
        $profiles = UserProfile::whereIn('id', $ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
        foreach ($profiles as $profile) {
            $profile->setRelation('role', $roles->get($profile->role_id));
        }
        $actor = $profiles->get($administrator->id);
        abort_unless($actor?->role?->code === 'system_admin' && $actor->canAccessApplication(throwOnDatabaseError: true), 403);
        $target = $officerId === null ? null : $profiles->get($officerId);
        if ($officerId !== null && (! $target || ! $this->eligibleOfficer($target))) {
            throw ValidationException::withMessages([$field => 'Select an active, fully activated Field Officer with an active role and eligible application access.']);
        }

        return [$actor, $target];
    }

    public function assertCurrent(Association $association, mixed $expected, string $field): void
    {
        $this->requireTransaction();
        if (! is_string($expected) || ! Str::isUuid($expected)) {
            throw ValidationException::withMessages([$field => 'The assignment snapshot is missing or invalid. Reload the form before saving.']);
        }
        if ($association->primary_field_officer_id !== strtolower($expected)) {
            throw ValidationException::withMessages([$field => 'An assignment changed after this form was loaded. Reload and review before saving.'])->status(409);
        }
    }

    public function assertReassignable(Association $association, string $status, string $field): void
    {
        $this->requireTransaction();
        $this->lifecycle->assertOperational($association, $field);
        if ($status !== 'active') {
            throw ValidationException::withMessages([$field => 'Change the officer while the association is active, separately from changing its status.']);
        }
    }

    public function recordAssignment(UserProfile $actor, Association $association, ?string $previous, string $module): void
    {
        $this->requireTransaction();
        if ($previous === $association->primary_field_officer_id) {
            return;
        }
        $request = new Request;
        $request->attributes->set('current_user', $actor);
        $this->audit->record($request, $previous === null ? 'ASSIGN FIELD OFFICER' : 'REASSIGN FIELD OFFICER',
            $module, 'association', $association->id, ['primary_field_officer_id' => $previous],
            ['primary_field_officer_id' => $association->primary_field_officer_id], ['primary_field_officer_id']);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Assignment safeguards require a transaction.');
        }
    }

    public function assign(Request $request, UserProfile $officer, array $data): int
    {
        return DB::transaction(function () use ($request, $officer, $data) {
            [$actor, $target] = $this->lockParticipants($request->attributes->get('current_user'), $officer->id);
            if (! in_array($data['confirm_reassignment'] ?? null, ['1', 1, true, 'true', 'yes', 'on'], true)) {
                throw ValidationException::withMessages(['confirm_reassignment' => 'Confirm the selected association reassignment.']);
            }

            $ids = $data['association_ids'];
            $associations = Association::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($associations->count() !== count($ids)) {
                throw ValidationException::withMessages(['association_ids' => 'An association is missing or repeated. Reload the assignments.']);
            }
            $changed = 0;
            foreach ($associations as $association) {
                $this->assertReassignable($association, $association->status, 'association_ids');
                $expected = $data['expected_officers'][$association->id] ?? null;
                if (! is_string($expected)) {
                    throw ValidationException::withMessages(['association_ids' => 'Assignment snapshots are missing. Reload and select the associations again.']);
                }
                // A repeated request for the same target is an unchanged assignment.
                if ($association->primary_field_officer_id === $target->id) {
                    continue;
                }
                $this->assertCurrent($association, $expected, 'association_ids');
                $previous = $association->primary_field_officer_id;
                $association->primary_field_officer_id = $target->id;
                $association->updated_by = $actor->id;
                $association->save();
                $this->recordAssignment($actor, $association, $previous, 'User Management');
                $changed++;
            }

            return $changed;
        });
    }
}
