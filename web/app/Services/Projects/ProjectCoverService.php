<?php

namespace App\Services\Projects;

use App\Models\Association;
use App\Models\MediaPhoto;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Associations\AssociationLifecycleService;
use App\Services\Media\PhotoRules;
use App\Services\Programs\ProgramActorService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProjectCoverService
{
    public function __construct(private ProgramActorService $actors, private ProjectLifecycleService $projects,
        private AssociationLifecycleService $associations) {}

    public function authorize(Request $request, Project $expected, bool $write): Closure
    {
        return function (Model $parent) use ($request, $expected, $write): Project {
            abort_unless($parent instanceof Project && $parent->getKey() === $expected->getKey(), 404);
            $actor = $request->attributes->get('current_user');
            abort_unless($actor instanceof UserProfile, 403);
            $fresh = $this->actors->lock($actor);
            if ($write) {
                $this->projects->lockNotFinalized([$expected->id], 'project');
            }
            $owner = Project::whereKey($expected->id)->firstOrFail();
            $association = Association::whereKey($owner->association_id)->lockForUpdate()->firstOrFail();
            $project = Project::whereKey($expected->id)->lockForUpdate()->firstOrFail();
            abort_unless($project->association_id === $association->id, 409);
            $this->projects->authorize($fresh, $association);
            if ($write) {
                $this->associations->assertOperational($association);
                $this->projects->assertNotFinalized($project, 'project');
                if ($project->archived_at !== null) {
                    throw ValidationException::withMessages(['project' => 'Archived projects are read-only.']);
                }
            } else {
                // Match existing Project history scope, including archived Projects.
                abort_if($fresh->role->code === 'field_officer' && $association->archived_at !== null, 403);
            }
            $request->attributes->set('current_user', $fresh);

            return $project->setRelation('association', $association);
        };
    }

    public function snapshot(Project $project, Closure $authorize): array
    {
        return DB::transaction(function () use ($project, $authorize) {
            $project = $authorize($project);
            $rows = MediaPhoto::where('project_id', $project->id)->where('state', '!=', 'deleted')
                ->orderBy('created_at')->orderBy('id')->get();

            return ['can_manage' => $this->associations->isOperational($project->association)
                    && $project->archived_at === null && ! $project->isFinalized(),
                'cover' => $rows->firstWhere('state', 'ready')?->toArray(),
                'upload_pending' => $rows->whereIn('state', array_diff(PhotoRules::LIVE_STATES, ['ready']))->isNotEmpty(),
                'pending' => $rows->where('state', '!=', 'ready')->values()->map(fn ($row) => [
                    'id' => $row->id, 'status' => $row->state,
                    'retry_after' => max(0, (int) ceil(now()->diffInSeconds($row->lease_until ?? now(), false))),
                ])->all()];
        });
    }

    public function photo(Project $project, string $id): MediaPhoto
    {
        return MediaPhoto::where('project_id', $project->id)->whereKey($id)->firstOrFail();
    }
}
