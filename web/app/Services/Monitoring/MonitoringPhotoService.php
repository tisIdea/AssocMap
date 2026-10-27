<?php

namespace App\Services\Monitoring;

use App\Models\Association;
use App\Models\MediaPhoto;
use App\Models\MonitoringMaterial;
use App\Models\MonitoringRecord;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Media\PhotoRules;
use App\Services\Programs\ProgramActorService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MonitoringPhotoService
{
    public function __construct(private MonitoringService $monitoring, private ProgramActorService $actors) {}

    public function parent(MonitoringRecord $record, string $kind, string $id): Model
    {
        return match ($kind) {
            'visits' => $record->visitReports()->whereKey($id)->firstOrFail(),
            'materials' => $record->materials()->whereKey($id)->firstOrFail(),
            default => abort(404),
        };
    }

    public function authorize(Request $request, MonitoringRecord $record, Model $expected, bool $write): Closure
    {
        return function (Model $parent) use ($request, $record, $expected, $write): Model {
            abort_unless($parent::class === $expected::class && $parent->getKey() === $expected->getKey(), 404);
            $actor = $request->attributes->get('current_user');
            abort_unless($actor instanceof UserProfile, 403);
            if ($write) {
                $locked = $this->monitoring->lockRecordForMutation($record, $actor);
            } else {
                $fresh = $this->actors->lock($actor);
                $project = Project::whereKey($record->project_id)->firstOrFail();
                $association = Association::whereKey($project->association_id)->lockForUpdate()->firstOrFail();
                $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                abort_unless($project->association_id === $association->id, 404);
                if ($fresh->role->code === 'field_officer') {
                    abort_unless($association->primary_field_officer_id === $fresh->id
                        && $association->status === 'active' && $association->archived_at === null
                        && $project->archived_at === null, 404);
                }
                $locked = MonitoringRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->project_id === $project->id, 404);
                $request->attributes->set('current_user', $fresh);
            }
            abort_unless($parent instanceof MonitoringVisitReport || $parent instanceof MonitoringMaterial, 404);

            return $parent->newQuery()->whereKey($parent->getKey())->where('monitoring_record_id', $locked->id)
                ->lockForUpdate()->firstOrFail();
        };
    }

    /** One parent-locked snapshot includes reserved slots, without storage requests. */
    public function gallery(Model $parent, Closure $authorize): array
    {
        return DB::transaction(function () use ($parent, $authorize) {
            $parent = $authorize($parent);
            [$column] = PhotoRules::parent($parent);
            $rows = MediaPhoto::where($column, $parent->getKey())->where('state', '!=', 'deleted')
                ->orderBy('display_order')->orderBy('id')->get();

            return [
                'photos' => $rows->where('state', 'ready')->values()->map->toArray()->all(),
                'pending' => $rows->where('state', '!=', 'ready')->values()->map(fn ($row) => [
                    'id' => $row->id, 'status' => $row->state,
                    'retry_after' => max(0, (int) ceil(now()->diffInSeconds($row->lease_until ?? now(), false))),
                ])->all(),
                'used_slots' => $rows->whereIn('state', PhotoRules::LIVE_STATES)->count(),
                'limit' => 5,
            ];
        });
    }

    public function photo(Model $parent, string $id): MediaPhoto
    {
        [$column] = PhotoRules::parent($parent);

        return MediaPhoto::where($column, $parent->getKey())->whereKey($id)->firstOrFail();
    }
}
