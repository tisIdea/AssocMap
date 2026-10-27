<?php

namespace App\Services\GIS;

use App\Models\Association;
use App\Models\GisLocation;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Programs\ProgramActorService;
use Illuminate\Support\Facades\DB;
use LogicException;

final class GisAccessService
{
    public function __construct(private ProgramActorService $actors) {}

    public function actor(UserProfile $previous): UserProfile
    {
        return $this->actors->lock($previous);
    }

    public function identity(string $id): void
    {
        if (DB::transactionLevel() === 0) throw new LogicException('GIS locks require a transaction.');
        // Actor first, then this identity fence, then associations/projects/location/media.
        // Creation and deletion retain the same fence against delayed create retries.
        DB::select('SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(?, 0))', [strtolower($id)]);
    }

    /** Lock both sides of an ownership edit in deterministic order before the location. */
    public function parents(array $payloads, ?UserProfile $actor, bool $history = false): array
    {
        if (DB::transactionLevel() === 0) throw new LogicException('GIS locks require a transaction.');
        foreach ($payloads as $data) {
            abort_unless(in_array($data['location_type'], GisLocation::TYPES, true), 404);
            abort_unless(($data['project_id'] ?? null) !== null && ($data['association_id'] ?? null) === null, 422, 'Select a valid project parent.');
        }
        $ids = array_unique(array_column($payloads, 'project_id'));
        $owners = Project::whereIn('id', $ids)->get(['id', 'association_id'])->keyBy('id');
        abort_unless($owners->count() === count($ids), 404);
        $associations = Association::whereIn('id', $owners->pluck('association_id')->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $projects = Project::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($projects->count() === count($ids), 404);
        foreach ($payloads as $data) {
            $project = $projects->get($data['project_id']);
            $association = $associations->get($owners->get($project->id)->association_id);
            abort_unless($association && $project->association_id === $association->id, 409, 'The project association changed. Reload before saving.');
            $historian = $history && $actor?->role->code === 'system_admin';
            abort_unless(($association->status === 'active' && $association->archived_at === null
                && $project->archived_at === null) || ($historian && ($association->status === 'active' || $association->archived_at !== null)), 404);
            abort_unless($actor === null || $actor->role->code === 'system_admin' || $association->primary_field_officer_id === $actor->id, 404);
            if (isset($data['parent_association_id'])) {
                abort_unless($project->association_id === $data['parent_association_id'], 422, 'The project must belong to the selected association.');
            }
            $project->setRelation('association', $association);
        }

        return $projects->all();
    }

    public function location(string $id, ?UserProfile $actor, bool $history = false): GisLocation
    {
        $this->identity($id);
        $snapshot = GisLocation::findOrFail($id);
        abort_unless($snapshot->association_id === null && $snapshot->project_id !== null
            && in_array($snapshot->location_type, GisLocation::TYPES, true), 404);
        $projects = $this->parents([$snapshot->getAttributes()], $actor, $history);
        $location = GisLocation::whereKey($id)->lockForUpdate()->firstOrFail();
        $this->unchangedOwner($snapshot, $location);

        return $location->setRelation('project', $projects[$location->project_id]);
    }

    public function unchangedOwner(GisLocation $snapshot, GisLocation $locked): void
    {
        foreach (['association_id', 'project_id', 'location_type'] as $field) {
            abort_unless($snapshot->$field === $locked->$field, 409, 'The location ownership changed. Reload before saving.');
        }
    }
}
