<?php

namespace App\Services\Projects;

use App\Models\Association;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Associations\AssociationLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProjectLifecycleService
{
    public const FINALIZED_MESSAGE = 'Completed or terminated projects are permanently read-only and cannot be reopened.';

    public function __construct(private AssociationLifecycleService $associations) {}

    public function authorize(UserProfile $actor, Association $association): void
    {
        abort_unless($actor->canAccessApplication()
            && in_array($actor->role?->code, ['system_admin', 'field_officer'], true), 403);
        if ($actor->role->code === 'field_officer') {
            abort_unless($association->primary_field_officer_id === $actor->id, 403);
        }
    }

    public function assertNotFinalized(Project $project, string $field = 'project_id'): void
    {
        if ($project->isFinalized()) {
            throw ValidationException::withMessages([$field => self::FINALIZED_MESSAGE]);
        }
    }

    // Lock actual project owners too, including retained links after an earlier reassignment.
    public function lockNotFinalized(array $projectIds, string $field = 'related_project_id'): void
    {
        if (DB::transactionLevel() === 0) throw new LogicException('Project eligibility locks require a transaction.');
        $ids = array_values(array_unique(array_map('strtolower', array_filter($projectIds))));
        if ($ids === []) return;
        $owners = Project::whereIn('id', $ids)->get(['id', 'association_id'])->keyBy('id');
        if ($owners->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => 'A linked project is unavailable. Reload before saving.']);
        }
        $this->associations->lockOperational($owners->pluck('association_id')->all(), $field);
        $projects = Project::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($projects->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => 'A linked project is unavailable. Reload before saving.']);
        }
        foreach ($projects as $project) {
            if ($project->association_id !== $owners->get($project->id)?->association_id) {
                throw ValidationException::withMessages([$field => 'A linked project changed association. Reload before saving.'])->status(409);
            }
            $this->assertNotFinalized($project, $field);
        }
    }
}
