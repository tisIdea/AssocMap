<?php

namespace App\Services;

use App\Models\Association;
use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldOfficerAssignmentService
{
    public function __construct(private AuditLogService $audit)
    {
    }

    public function eligibleOfficer(UserProfile $officer): bool
    {
        $officer->loadMissing('role');

        return $officer->role?->code === 'field_officer' && $officer->canAccessApplication();
    }

    public function assign(Request $request, UserProfile $officer, array $data): int
    {
        return DB::transaction(function () use ($request, $officer, $data) {
            $actor = UserProfile::whereKey($request->attributes->get('current_user')?->id)->sharedLock()->first();
            $actorRole = $actor ? Role::whereKey($actor->role_id)->sharedLock()->first() : null;
            if ($actor) $actor->setRelation('role', $actorRole);
            abort_unless($actor?->canAccessApplication() && $actorRole?->code === 'system_admin', 403);

            $target = UserProfile::whereKey($officer->id)->sharedLock()->first();
            $targetRole = $target ? Role::whereKey($target->role_id)->sharedLock()->first() : null;
            if ($target) $target->setRelation('role', $targetRole);
            if (! $target || ! $this->eligibleOfficer($target)) {
                throw ValidationException::withMessages(['officer' => 'Select an active, eligible Field Officer with an active role.']);
            }
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
                if ($association->archived_at !== null) {
                    throw ValidationException::withMessages(['association_ids' => 'Archived associations cannot be reassigned.']);
                }
                $expected = $data['expected_officers'][$association->id] ?? null;
                if (! is_string($expected)) {
                    throw ValidationException::withMessages(['association_ids' => 'Assignment snapshots are missing. Reload and select the associations again.']);
                }
                // A repeated request for the same target is an unchanged assignment.
                if ($association->primary_field_officer_id === $target->id) continue;
                if ($association->primary_field_officer_id !== strtolower($expected)) {
                    throw ValidationException::withMessages(['association_ids' => 'An assignment changed after this form was loaded. Reload and review before saving.'])->status(409);
                }
                $previous = $association->primary_field_officer_id;
                $association->primary_field_officer_id = $target->id;
                $association->updated_by = $actor->id;
                $association->save();
                $this->audit->record($request, 'REASSIGN FIELD OFFICER', 'User Management', 'association', $association->id,
                    ['primary_field_officer_id' => $previous], ['primary_field_officer_id' => $target->id],
                    ['primary_field_officer_id']);
                $changed++;
            }

            return $changed;
        });
    }
}
