<?php

namespace App\Services;

use App\Models\Association;
use App\Models\Project;
use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectClosureService
{
    private const AUDIT_FIELDS = ['project_id', 'association_id', 'status', 'closed_at', 'closed_by', 'actor_role'];

    public function __construct(private ProjectLifecycleService $projects,
        private AssociationLifecycleService $associations, private AuditLogService $audit) {}

    public function close(Request $request, Project $project, array $data): Project
    {
        return $this->finalize($request, $project, $data, 'completed');
    }

    public function terminate(Request $request, Project $project, array $data): Project
    {
        return $this->finalize($request, $project, $data, 'terminated');
    }

    private function finalize(Request $request, Project $project, array $data, string $outcome): Project
    {
        $remarks = $data['closure_remarks'] ?? null;
        $remarks = is_string($remarks) ? Str::trim($remarks) : null;
        if ($remarks === null || ! preg_match('/[^\p{Z}\p{C}\s\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}]/u', $remarks) || mb_strlen($remarks) > 2000) {
            throw ValidationException::withMessages(['closure_remarks' => 'Provide visible Final Remarks of at most 2,000 characters.']);
        }
        $confirmation = $outcome === 'completed' ? 'completion_confirmation' : 'termination_confirmation';
        $word = $outcome === 'completed' ? 'COMPLETED' : 'TERMINATE';
        if (($data[$confirmation] ?? null) !== $word) {
            throw ValidationException::withMessages([$confirmation => 'Type '.$word.' exactly, without surrounding spaces.']);
        }
        return DB::transaction(function () use ($request, $project, $data, $remarks, $outcome) {
            $expectedRole = $request->attributes->get('current_user')?->role?->code;
            $actor = UserProfile::whereKey($request->attributes->get('current_user')?->id)->sharedLock()->first();
            abort_unless($actor, 403);
            $actor->setRelation('role', Role::whereKey($actor->role_id)->sharedLock()->first());
            abort_unless($actor->canAccessApplication() && $actor->role?->code === $expectedRole, 403);
            $association = Association::whereKey($project->association_id)->lockForUpdate()->firstOrFail();
            $this->projects->authorize($actor, $association);
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->association_id !== $association->id) {
                throw ValidationException::withMessages(['project' => 'The project association changed. Reload before closing.'])->status(409);
            }
            $this->associations->assertOperational($association, 'project');
            if ($locked->isFinalized()) {
                throw ValidationException::withMessages(['project' => 'This project is already finalized. No further action was taken.'])->status(409);
            }
            if ($locked->status !== 'ongoing' || $locked->archived_at !== null) {
                throw ValidationException::withMessages(['project' => 'Only an ongoing, unarchived project can be completed or terminated.']);
            }
            if (! is_string($data['expected_updated_at'] ?? null) || $data['expected_updated_at'] === ''
                || $data['expected_updated_at'] !== $locked->updated_at?->format('Y-m-d\TH:i:s.uP')) {
                throw ValidationException::withMessages(['project' => 'This project changed. Reload and review it before closing.'])->status(409);
            }
            $before = $this->snapshot($locked);
            $locked->status = $outcome;
            $locked->closed_at = now();
            $locked->closure_remarks = $remarks;
            $locked->closed_by = $actor->id;
            $locked->updated_by = $actor->id;
            $locked->save();
            $request->attributes->set('current_user', $actor);
            $this->audit->record($request, $outcome === 'completed' ? 'CLOSE PROJECT' : 'TERMINATE PROJECT',
                'Project Management', $outcome === 'completed' ? 'project_closure' : 'project_termination', $locked->id,
                $before, $this->snapshot($locked) + ['actor_role' => $actor->role->code], self::AUDIT_FIELDS);

            return $locked;
        }, 3);
    }

    private function snapshot(Project $project): array
    {
        return ['project_id' => $project->id, 'association_id' => $project->association_id,
            'status' => $project->status, 'closed_at' => $project->closed_at?->toIso8601String(), 'closed_by' => $project->closed_by];
    }
}
