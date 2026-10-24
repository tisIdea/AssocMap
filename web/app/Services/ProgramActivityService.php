<?php

namespace App\Services;

use App\Models\Association;
use App\Models\ProgramActivityRecord;
use App\Models\Project;
use App\Models\SaadActivity;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramActivityService
{
    public function __construct(private AssociationLifecycleService $lifecycle, private ProjectLifecycleService $projects,
        private ProgramActorService $actors) {}

    private const EXCLUDED_ACTIVITY_CODES = [
        // Dedicated Project module
        'FPL_LPD',

        // Dedicated Training module
        'SP_LT',
        'FPL_CST',
        'MAED_ERT',
        'PM_CB',
        'PM_CD',
    ];

    public function create(
        array $data,
        UserProfile $currentUser
    ): ProgramActivityRecord {
        $association = $this->resolveAssociationForUser(
            $data['association_id'] ?? null,
            $currentUser
        );

        $activity = $this->resolveActivity(
            (int) $data['saad_activity_id']
        );

        $relatedProject = $this->resolveRelatedProject(
            $data['related_project_id'] ?? null,
            $association
        );

        return DB::transaction(function () use (
            $data,
            $currentUser,
            $association,
            $activity,
            $relatedProject
        ) {
            $currentUser = $this->actors->lock($currentUser);
            $this->lifecycle->lockOperational([$association?->id]);
            $this->projects->lockNotFinalized([$relatedProject?->id]);
            $association = $this->resolveAssociationForUser($association?->id, $currentUser);
            $relatedProject = $this->resolveRelatedProject($relatedProject?->id, $association);
            $record = ProgramActivityRecord::create([
                'saad_activity_id' =>
                    $activity->id,

                'association_id' =>
                    $association?->id,

                'related_project_id' =>
                    $relatedProject?->id,

                'title' =>
                    $data['title'],

                'description' =>
                    $data['description'] ?? null,

                'program_year' =>
                    $data['program_year'],

                'activity_date' =>
                    $data['activity_date'],

                'venue' =>
                    $data['venue'] ?? null,

                'status' =>
                    $data['status'],

                'conducted_by' =>
                    $data['conducted_by'] ?? null,

                'conducted_by_user_id' =>
                    $currentUser->id,

                'remarks' =>
                    $data['remarks'] ?? null,

                'created_by' =>
                    $currentUser->id,

                'updated_by' =>
                    $currentUser->id,
            ]);

            return $record->load([
                'saadActivity',
                'association',
                'relatedProject',
                'conductedByUser',
            ]);
        });
    }

    public function update(
        ProgramActivityRecord $record,
        array $data,
        UserProfile $currentUser
    ): ProgramActivityRecord {
        $this->ensureUserCanManageRecord(
            $record,
            $currentUser
        );

        if ($record->archived_at !== null) {
            throw ValidationException::withMessages([
                'program_activity' =>
                    'Archived program activities cannot be edited.',
            ]);
        }

        $association = $this->resolveAssociationForUser(
            $data['association_id'] ?? null,
            $currentUser
        );

        $activity = $this->resolveActivity(
            (int) $data['saad_activity_id']
        );

        $relatedProject = $this->resolveRelatedProject(
            $data['related_project_id'] ?? null,
            $association
        );

        return DB::transaction(function () use (
            $record,
            $data,
            $currentUser,
            $association,
            $activity,
            $relatedProject
        ) {
            $currentUser = $this->actors->lock($currentUser);
            $record = $this->lockForMutation($record, $currentUser, [$association?->id], [$relatedProject?->id]);
            if ($record->archived_at !== null) {
                throw ValidationException::withMessages(['program_activity' => 'Archived program activities cannot be edited.']);
            }
            $association = $this->resolveAssociationForUser($association?->id, $currentUser);
            $relatedProject = $this->resolveRelatedProject($relatedProject?->id, $association);
            $record->update([
                'saad_activity_id' =>
                    $activity->id,

                'association_id' =>
                    $association?->id,

                'related_project_id' =>
                    $relatedProject?->id,

                'title' =>
                    $data['title'],

                'description' =>
                    $data['description'] ?? null,

                'program_year' =>
                    $data['program_year'],

                'activity_date' =>
                    $data['activity_date'],

                'venue' =>
                    $data['venue'] ?? null,

                'status' =>
                    $data['status'],

                'conducted_by' =>
                    $data['conducted_by'] ?? null,

                'conducted_by_user_id' =>
                    $currentUser->id,

                'remarks' =>
                    $data['remarks'] ?? null,

                'updated_by' =>
                    $currentUser->id,
            ]);

            return $record->load([
                'saadActivity',
                'association',
                'relatedProject',
                'conductedByUser',
            ]);
        });
    }

    public function archive(
        ProgramActivityRecord $record,
        UserProfile $currentUser
    ): void {
        DB::transaction(function () use ($record, $currentUser) {
            $currentUser = $this->actors->lock($currentUser);
            $record = $this->lockForMutation($record, $currentUser);
            if ($record->archived_at !== null) return;
            $record->update([
                'archived_at' => now(),
                'archived_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
        }, 3);
    }

    public function unarchive(
        ProgramActivityRecord $record,
        UserProfile $currentUser
    ): void {
        DB::transaction(function () use ($record, $currentUser) {
            $currentUser = $this->actors->lock($currentUser);
            $record = $this->lockForMutation($record, $currentUser);
            if ($record->archived_at === null) return;
            $record->update([
                'archived_at' => null,
                'archived_by' => null,
                'updated_by' => $currentUser->id,
            ]);
        }, 3);
    }

    private function lockForMutation(ProgramActivityRecord $record, UserProfile $actor,
        array $destinationIds = [], array $destinationProjects = []): ProgramActivityRecord
    {
        $this->lifecycle->lockOperational([$record->association_id, ...$destinationIds]);
        $locked = ProgramActivityRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
        if ($locked->association_id !== $record->association_id) {
            throw ValidationException::withMessages(['program_activity' => 'The activity association changed. Refresh before saving.'])->status(409);
        }
        $this->ensureUserCanManageRecord($locked, $actor);
        $this->projects->lockNotFinalized([$locked->related_project_id, ...$destinationProjects]);

        return $locked;
    }

    private function resolveAssociationForUser(
        ?string $associationId,
        UserProfile $currentUser
    ): ?Association {
        $roleCode =
            $currentUser->role?->code;

        /*
        |--------------------------------------------------------------------------
        | System Administrator
        |--------------------------------------------------------------------------
        |
        | Admin may create:
        | - Association-specific activities
        | - Program-wide activities without an Association
        |
        */

        if ($roleCode === 'system_admin') {
            if (! $associationId) {
                return null;
            }

            $association = Association::query()
                ->where('id', $associationId)
                ->whereNull('archived_at')
                ->where('status', 'active')
                ->first();

            if (! $association) {
                throw ValidationException::withMessages([
                    'association_id' =>
                        'The selected association is not active or available.',
                ]);
            }

            return $association;
        }

        /*
        |--------------------------------------------------------------------------
        | Field Officer
        |--------------------------------------------------------------------------
        |
        | Field Officers must always associate the activity
        | with one of their assigned active Associations.
        |
        */

        if ($roleCode === 'field_officer') {
            if (! $associationId) {
                throw ValidationException::withMessages([
                    'association_id' =>
                        'An association is required for Field Officer program activities.',
                ]);
            }

            $association = Association::query()
                ->where('id', $associationId)
                ->where(
                    'primary_field_officer_id',
                    $currentUser->id
                )
                ->whereNull('archived_at')
                ->where('status', 'active')
                ->first();

            if (! $association) {
                throw ValidationException::withMessages([
                    'association_id' =>
                        'You can only manage program activities for associations assigned to you.',
                ]);
            }

            return $association;
        }

        throw ValidationException::withMessages([
            'authorization' =>
                'You are not authorized to manage program activities.',
        ]);
    }

    private function ensureUserCanManageRecord(
        ProgramActivityRecord $record,
        UserProfile $currentUser
    ): void {
        $roleCode =
            $currentUser->role?->code;

        if ($roleCode === 'system_admin') {
            return;
        }

        if ($roleCode === 'field_officer') {
            if (! $record->association_id) {
                throw ValidationException::withMessages([
                    'authorization' =>
                        'You are not authorized to manage this program-wide activity.',
                ]);
            }

            $isAssigned = Association::query()
                ->where(
                    'id',
                    $record->association_id
                )
                ->where(
                    'primary_field_officer_id',
                    $currentUser->id
                )
                ->exists();

            if ($isAssigned) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'authorization' =>
                'You are not authorized to manage this program activity.',
        ]);
    }

    private function resolveActivity(
        int $activityId
    ): SaadActivity {
        $activity = SaadActivity::query()
            ->where('id', $activityId)
            ->where('is_active', true)
            ->whereNotIn(
                'code',
                self::EXCLUDED_ACTIVITY_CODES
            )
            ->first();

        if (! $activity) {
            throw ValidationException::withMessages([
                'saad_activity_id' =>
                    'The selected SAAD activity is unavailable or belongs to another dedicated module.',
            ]);
        }

        return $activity;
    }

    private function resolveRelatedProject(
        ?string $projectId,
        ?Association $association
    ): ?Project {
        if (! $projectId) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | A related Project requires an Association
        |--------------------------------------------------------------------------
        */

        if (! $association) {
            throw ValidationException::withMessages([
                'related_project_id' =>
                    'A related project can only be selected when an association is also selected.',
            ]);
        }

        $project = Project::query()
            ->where('id', $projectId)
            ->where(
                'association_id',
                $association->id
            )
            ->whereNull('archived_at')
            ->first();

        if (! $project) {
            throw ValidationException::withMessages([
                'related_project_id' =>
                    'The selected project does not belong to the selected association or is archived.',
            ]);
        }

        $this->projects->assertNotFinalized($project, 'related_project_id');

        return $project;
    }
}
