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

    public function __construct(
        private AssociationLifecycleService $lifecycle,
        private ProjectLifecycleService $projects,
        private ProgramActorService $actors,
    ) {}

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
            $currentUser = $this->actors->lock($currentUser);
            $this->lifecycle->lockOperational([$association->id]);
            $this->projects->lockNotFinalized([$relatedProject?->id]);
            $association = $this->resolveAssociationForUser($association->id, $currentUser);
            $relatedProject = $this->resolveRelatedProject($relatedProject?->id, $association);
            $this->validateParticipants($participants, $association);
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

    public function update(Training $training, array $data, UserProfile $currentUser): Training
    {
        return DB::transaction(function () use ($training, $data, $currentUser) {
            abort_unless(in_array($currentUser->role?->code, ['system_admin', 'field_officer'], true), 403);
            $actor = $this->actors->lock($currentUser);
            $associationIds = array_unique([$training->association_id, strtolower($data['association_id'])]);
            $this->lifecycle->lockOperational($associationIds);
            $locked = Training::whereKey($training->id)->lockForUpdate()->firstOrFail();
            $this->ensureUserCanManageTraining($locked, $actor);
            if ($locked->association_id !== $training->association_id
                || ($data['expected_updated_at'] ?? null) !== $locked->updated_at?->format('Y-m-d\TH:i:s.uP')) {
                throw ValidationException::withMessages([
                    'training' => 'This training changed after the form was opened. Refresh and review it before saving.',
                ])->status(409);
            }
            $this->projects->lockNotFinalized([$locked->related_project_id, $data['related_project_id'] ?? null]);
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['training' => 'Archived trainings cannot be edited.']);
            }

            $association = $this->resolveAssociationForUser($data['association_id'], $actor);
            $activity = $this->resolveTrainingActivity((int) $data['saad_activity_id']);
            $project = $this->resolveRelatedProject($data['related_project_id'] ?? null, $association);
            $originalAssociationId = $locked->association_id;
            $locked->fill([
                'association_id' => $association->id,
                'related_project_id' => $project?->id,
                'saad_activity_id' => $activity->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'program_year' => $data['program_year'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'venue' => $data['venue'] ?? null,
                'conducted_by' => $data['conducted_by'] ?? null,
                'training_cost' => $data['training_cost'] ?? null,
                'status' => $data['status'],
                'remarks' => $data['remarks'] ?? null,
                'updated_by' => $actor->id,
            ]);
            $locked->save();
            $this->syncParticipants($locked, $data['participants'] ?? [], $data['removed_participant_ids'] ?? [],
                $association, $originalAssociationId);

            return $locked->load(['association', 'relatedProject', 'saadActivity', 'participants.member']);
        });
    }

    private function syncParticipants(Training $training, array $participants, array $removedIds,
        Association $association, string $originalAssociationId): void
    {
        $existing = $training->participants()->lockForUpdate()->get()->keyBy('member_id');
        $removedIds = array_map('strtolower', $removedIds);
        $existingIds = $existing->pluck('id')->all();
        if (count(array_unique($removedIds)) !== count($removedIds) || array_diff($removedIds, $existingIds) !== []) {
            throw ValidationException::withMessages(['participants' => 'A participant removal is invalid or no longer belongs to this training. Refresh before saving.']);
        }
        if ($association->id !== $originalAssociationId
            && $existing->contains(fn ($participant) => ! in_array($participant->id, $removedIds, true))) {
            throw ValidationException::withMessages(['association_id' => 'A training with retained participation cannot change association. Its participation history must be preserved.']);
        }

        $seen = [];
        $new = [];
        foreach ($participants as $participant) {
            $memberId = strtolower($participant['member_id']);
            if (in_array($memberId, $seen, true)) {
                throw ValidationException::withMessages(['participants' => 'A member can only be added once to a training.']);
            }
            $seen[] = $memberId;
            $record = $existing->get($memberId);
            if ((isset($participant['id']) && (! $record || strtolower($participant['id']) !== $record->id))
                || ($record && in_array($record->id, $removedIds, true))) {
                throw ValidationException::withMessages(['participants' => 'A participant changed or was also marked for removal. Refresh and review the participant rows.']);
            }
            if (! $record) {
                $participant['member_id'] = $memberId;
                $new[] = $participant;
                continue;
            }

            // Keep participant identity and historical membership links unchanged.
            $changes = array_intersect_key($participant, array_flip(['attendance_status', 'remarks']));
            if (array_key_exists('remarks', $changes) && is_string($changes['remarks'])) $changes['remarks'] = trim($changes['remarks']);
            $record->fill($changes);
            if ($record->isDirty()) $record->save();
        }

        $this->validateParticipants($new, $association);
        if ($removedIds !== []) $training->participants()->whereIn('id', $removedIds)->delete();
        $this->createParticipants($training, $new);
    }

    public function archive(
        Training $training,
        UserProfile $currentUser
    ): void {
        DB::transaction(function () use ($training, $currentUser) {
            $currentUser = $this->actors->lock($currentUser);
            $training = $this->lockForMutation($training, $currentUser);
            if ($training->archived_at !== null) return;
            $training->update([
                'archived_at' => now(),
                'archived_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
            ]);
        }, 3);
    }

    public function unarchive(
        Training $training,
        UserProfile $currentUser
    ): void {
        DB::transaction(function () use ($training, $currentUser) {
            $currentUser = $this->actors->lock($currentUser);
            $training = $this->lockForMutation($training, $currentUser);
            if ($training->archived_at === null) return;
            $training->update([
                'archived_at' => null,
                'archived_by' => null,
                'updated_by' => $currentUser->id,
            ]);
        }, 3);
    }

    private function lockForMutation(Training $training, UserProfile $actor): Training
    {
        $this->lifecycle->lockOperational([$training->association_id]);
        $locked = Training::whereKey($training->id)->lockForUpdate()->firstOrFail();
        if ($locked->association_id !== $training->association_id) {
            throw ValidationException::withMessages(['training' => 'The training association changed. Refresh before saving.'])->status(409);
        }
        $this->ensureUserCanManageTraining($locked, $actor);
        $this->projects->lockNotFinalized([$locked->related_project_id]);

        return $locked;
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

        $this->projects->assertNotFinalized($project, 'related_project_id');

        return $project;
    }

    private function validateParticipants(
        array $participants,
        Association $association
    ): void {
        if (empty($participants)) {
            return;
        }

        foreach ($participants as $participant) {
            if (! in_array($participant['attendance_status'] ?? null, ['present', 'absent'], true)) {
                throw ValidationException::withMessages(['participants' => 'Select Present or Absent for every new participant.']);
            }
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
            ->where('member_status', 'active')
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
