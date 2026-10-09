<?php

namespace App\Services;

use App\Models\Association;
use App\Models\Member;
use App\Models\Project;
use App\Models\SaadActivity;
use App\Models\Training;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrainingService
{
    private const ALLOWED_TRAINING_ACTIVITY_CODES = [
        'SP_LT',
        'FPL_CST',
        'MAED_ERT',
        'PM_CB',
        'PM_CD',
    ];

    public function create(
        array $data,
        UserProfile $currentUser
    ): Training {
        $association = $this->resolveAssociationForUser(
            $data['association_id'],
            $currentUser
        );

        $saadActivity = $this->resolveTrainingActivity(
            (int) $data['saad_activity_id']
        );

        $relatedProject = $this->resolveRelatedProject(
            $data['related_project_id'] ?? null,
            $association
        );

        $participants = $data['participants'] ?? [];

        $this->validateParticipants(
            $participants,
            $association
        );

        return DB::transaction(function () use (
            $data,
            $currentUser,
            $association,
            $saadActivity,
            $relatedProject,
            $participants
        ) {
            $training = Training::create([
                'association_id' =>
                    $association->id,

                'related_project_id' =>
                    $relatedProject?->id,

                'saad_activity_id' =>
                    $saadActivity->id,

                'title' =>
                    $data['title'],

                'description' =>
                    $data['description'] ?? null,

                'program_year' =>
                    $data['program_year'],

                'start_date' =>
                    $data['start_date'],

                'end_date' =>
                    $data['end_date'] ?? null,

                'venue' =>
                    $data['venue'] ?? null,

                'conducted_by' =>
                    $data['conducted_by'] ?? null,

                'training_cost' =>
                    $data['training_cost'] ?? null,

                'status' =>
                    $data['status'],

                'remarks' =>
                    $data['remarks'] ?? null,

                'created_by' =>
                    $currentUser->id,

                'updated_by' =>
                    $currentUser->id,
            ]);

            $this->createParticipants(
                $training,
                $participants
            );

            return $training->load([
                'association',
                'relatedProject',
                'saadActivity',
                'participants.member',
            ]);
        });
    }

    public function update(
        Training $training,
        array $data,
        UserProfile $currentUser
    ): Training {
        $this->ensureUserCanManageTraining(
            $training,
            $currentUser
        );

        if ($training->archived_at !== null) {
            throw ValidationException::withMessages([
                'training' =>
                    'Archived trainings cannot be edited.',
            ]);
        }

        $association = $this->resolveAssociationForUser(
            $data['association_id'],
            $currentUser
        );

        $saadActivity = $this->resolveTrainingActivity(
            (int) $data['saad_activity_id']
        );

        $relatedProject = $this->resolveRelatedProject(
            $data['related_project_id'] ?? null,
            $association
        );

        $participants = $data['participants'] ?? [];

        $this->validateParticipants(
            $participants,
            $association
        );

        return DB::transaction(function () use (
            $training,
            $data,
            $currentUser,
            $association,
            $saadActivity,
            $relatedProject,
            $participants
        ) {
            $training->update([
                'association_id' =>
                    $association->id,

                'related_project_id' =>
                    $relatedProject?->id,

                'saad_activity_id' =>
                    $saadActivity->id,

                'title' =>
                    $data['title'],

                'description' =>
                    $data['description'] ?? null,

                'program_year' =>
                    $data['program_year'],

                'start_date' =>
                    $data['start_date'],

                'end_date' =>
                    $data['end_date'] ?? null,

                'venue' =>
                    $data['venue'] ?? null,

                'conducted_by' =>
                    $data['conducted_by'] ?? null,

                'training_cost' =>
                    $data['training_cost'] ?? null,

                'status' =>
                    $data['status'],

                'remarks' =>
                    $data['remarks'] ?? null,

                'updated_by' =>
                    $currentUser->id,
            ]);

            if (array_key_exists('participants', $data)) {
                $training->participants()->delete();

                $this->createParticipants(
                    $training,
                    $participants
                );
            }

            return $training->load([
                'association',
                'relatedProject',
                'saadActivity',
                'participants.member',
            ]);
        });
    }

    public function archive(
        Training $training,
        UserProfile $currentUser
    ): void {
        $this->ensureUserCanManageTraining(
            $training,
            $currentUser
        );

        if ($training->archived_at !== null) {
            return;
        }

        $training->update([
            'archived_at' =>
                now(),

            'archived_by' =>
                $currentUser->id,

            'updated_by' =>
                $currentUser->id,
        ]);
    }

    public function unarchive(
        Training $training,
        UserProfile $currentUser
    ): void {
        $this->ensureUserCanManageTraining(
            $training,
            $currentUser
        );

        if ($training->archived_at === null) {
            return;
        }

        $training->update([
            'archived_at' =>
                null,

            'archived_by' =>
                null,

            'updated_by' =>
                $currentUser->id,
        ]);
    }

    private function resolveAssociationForUser(
        string $associationId,
        UserProfile $currentUser
    ): Association {
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

        $roleCode = $currentUser->role?->code;

        if ($roleCode === 'system_admin') {
            return $association;
        }

        if ($roleCode === 'field_officer') {
            if (
                $association->primary_field_officer_id
                !== $currentUser->id
            ) {
                throw ValidationException::withMessages([
                    'association_id' =>
                        'You can only manage trainings for associations assigned to you.',
                ]);
            }

            return $association;
        }

        throw ValidationException::withMessages([
            'authorization' =>
                'You are not authorized to manage trainings.',
        ]);
    }

    private function ensureUserCanManageTraining(
        Training $training,
        UserProfile $currentUser
    ): void {
        $roleCode = $currentUser->role?->code;

        if ($roleCode === 'system_admin') {
            return;
        }

        if ($roleCode === 'field_officer') {
            $isAssigned = Association::query()
                ->where(
                    'id',
                    $training->association_id
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
                'You are not authorized to manage this training.',
        ]);
    }

    private function resolveTrainingActivity(
        int $activityId
    ): SaadActivity {
        $activity = SaadActivity::query()
            ->where('id', $activityId)
            ->where('is_active', true)
            ->whereIn(
                'code',
                self::ALLOWED_TRAINING_ACTIVITY_CODES
            )
            ->first();

        if (! $activity) {
            throw ValidationException::withMessages([
                'saad_activity_id' =>
                    'The selected SAAD activity is not valid for a training record.',
            ]);
        }

        return $activity;
    }

    private function resolveRelatedProject(
        ?string $projectId,
        Association $association
    ): ?Project {
        if (! $projectId) {
            return null;
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

        return $project;
    }

    private function validateParticipants(
        array $participants,
        Association $association
    ): void {
        if (empty($participants)) {
            return;
        }

        $memberIds = collect($participants)
            ->pluck('member_id')
            ->filter()
            ->values();

        if ($memberIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'participants' =>
                    'A member can only be added once to a training.',
            ]);
        }

        $validMemberIds = Member::query()
            ->whereIn('id', $memberIds)
            ->where(
                'association_id',
                $association->id
            )
            ->whereNull('archived_at')
            ->pluck('id');

        if (
            $validMemberIds->count()
            !== $memberIds->count()
        ) {
            throw ValidationException::withMessages([
                'participants' =>
                    'All participants must be active members of the selected association.',
            ]);
        }
    }

    private function createParticipants(
        Training $training,
        array $participants
    ): void {
        foreach ($participants as $participant) {
            $training->participants()->create([
                'member_id' =>
                    $participant['member_id'],

                'attendance_status' =>
                    $participant['attendance_status'],

                'remarks' =>
                    isset($participant['remarks'])
                        ? trim($participant['remarks'])
                        : null,
            ]);
        }
    }
}