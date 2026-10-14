<?php

namespace App\Services;

use App\Models\Association;
use App\Models\GisLocation;
use App\Models\Project;
use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GisLocationService
{
    public function __construct(private GisGeometry $geometry, private GisQueryService $queries) {}

    public function store(array $data, UserProfile $user, Request $request): array
    {
        $data = $this->payload($data);

        return DB::transaction(function () use ($data, $user, $request) {
            $this->lockIdentity($data['idempotency_key']);
            $actor = $this->actor($user);
            $this->lockParent($data, $actor);
            abort_if(DB::table('audit_logs')->where('entity_type', 'gis_location')
                ->where('entity_id', $data['idempotency_key'])->where('action_type', 'DELETE GIS')->exists(), 409);
            $this->geometry->validateSpatial($data['geometry']);
            $created = DB::affectingStatement('INSERT INTO gis_locations
                (id, association_id, project_id, location_type, location_name, address, geom,
                 public_title, public_description, public_address, is_published, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, FALSE, ?, ?)
                ON CONFLICT (id) DO NOTHING', [
                $data['idempotency_key'], $data['association_id'], $data['project_id'], $data['location_type'],
                $data['location_name'], $data['address'], json_encode($data['geometry'], JSON_THROW_ON_ERROR),
                $data['public_title'], $data['public_description'], $data['public_address'], $actor->id, $actor->id,
            ]) === 1;
            $location = $this->locked($data['idempotency_key']);
            $this->lockParent($location->getAttributes(), $actor);
            if (! $created) {
                abort_if($location->archived_at !== null, 409, 'This request has already been used. Refresh the register.');
                foreach (['association_id', 'project_id', 'location_type', 'location_name', 'address', 'public_title', 'public_description', 'public_address'] as $field) {
                    abort_if($location->$field !== $data[$field], 409, 'This request has already been used with different values.');
                }
                $same = DB::selectOne('SELECT extensions.ST_Equals(geom,
                    extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326)) AS same FROM gis_locations WHERE id = ?',
                    [json_encode($data['geometry'], JSON_THROW_ON_ERROR), $location->id]);
                abort_unless(in_array($same->same, [true, 1, '1', 't', 'true'], true), 409, 'This request has already been used with different geometry.');
            } else {
                $this->audit('CREATE', $location, null, $this->snapshot($location), $actor, $request);
            }

            return ['feature' => $this->queries->detail($actor, $location->id), 'created' => $created];
        }, 3);
    }

    public function update(string $id, array $data, UserProfile $user, Request $request): array
    {
        $data = $this->payload($data);

        return DB::transaction(function () use ($id, $data, $user, $request) {
            $actor = $this->actor($user);
            $location = $this->locked($id);
            $this->lockParent($location->getAttributes(), $actor);
            $this->assertVersion($location, $data['version']);
            abort_if($location->archived_at !== null, 409, 'Archived locations cannot be edited.');
            $this->lockParent($data, $actor);
            $this->geometry->validateSpatial($data['geometry']);
            if ($location->is_published) $this->requirePublicTitle($data['public_title']);
            $old = $this->snapshot($location);
            DB::update('UPDATE gis_locations SET association_id = ?, project_id = ?, location_type = ?,
                location_name = ?, address = ?, geom = extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326),
                public_title = ?, public_description = ?, public_address = ?, updated_by = ? WHERE id = ?', [
                $data['association_id'], $data['project_id'], $data['location_type'], $data['location_name'], $data['address'],
                json_encode($data['geometry'], JSON_THROW_ON_ERROR), $data['public_title'], $data['public_description'],
                $data['public_address'], $actor->id, $id,
            ]);
            $location = $this->locked($id);
            $this->audit('UPDATE', $location, $old, $this->snapshot($location), $actor, $request);

            return $this->queries->detail($actor, $id);
        }, 3);
    }

    public function lifecycle(string $id, string $action, string $version, UserProfile $user, Request $request): array
    {
        abort_unless(in_array($action, ['publish', 'unpublish', 'archive'], true), 404);

        return DB::transaction(function () use ($id, $action, $version, $user, $request) {
            $actor = $this->actor($user);
            $location = $this->locked($id);
            $this->lockParent($location->getAttributes(), $actor);
            $this->assertVersion($location, $version);
            if ($location->archived_at !== null) {
                abort_unless($action === 'archive', 409, 'Archived locations cannot be published or edited.');
                return $this->queries->detail($actor, $id);
            }
            $old = $this->snapshot($location);
            if ($action === 'publish') {
                $this->requirePublicTitle($location->public_title);
                $geometry = $this->geometry->normalize(json_decode($location->geometry_json, true), $location->location_type);
                $this->geometry->validateSpatial($geometry);
                if ($location->is_published) return $this->queries->detail($actor, $id);
                $location->is_published = true;
                $location->published_by = $actor->id;
                $location->published_at = now();
            } elseif ($action === 'unpublish') {
                if (! $location->is_published) return $this->queries->detail($actor, $id);
                $location->is_published = false;
            } else {
                $location->archived_at = now();
                $location->archived_by = $actor->id;
                $location->is_published = false;
            }
            $location->updated_by = $actor->id;
            $location->save();
            $location = $this->locked($id);
            $labels = ['publish' => 'PUBLISH GIS', 'unpublish' => 'UNPUBLISH GIS', 'archive' => 'ARCHIVE'];
            $this->audit($labels[$action], $location, $old, $this->snapshot($location), $actor, $request);

            return $this->queries->detail($actor, $id);
        }, 3);
    }

    public function destroy(string $id, string $version, UserProfile $user): void
    {
        try {
            DB::transaction(function () use ($id, $version, $user) {
                $this->lockIdentity($id);
                $actor = $this->actor($user);
                $location = GisLocation::whereKey($id)->select(['id', 'association_id', 'project_id', 'location_type'])
                    ->selectRaw('updated_at::text AS version')->lockForUpdate()->first();
                abort_unless($location, 404);
                $this->lockParent($location->getAttributes(), $actor);
                $this->assertVersion($location, $version);
                $this->assertDeletionSchema();
                $deleted = DB::table('gis_locations')->where('id', $location->id)->delete();
                if ($deleted !== 1) {
                    throw ValidationException::withMessages(['deletion' => 'The database did not permit deletion. Refresh the record and verify server permissions.']);
                }
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(), 'actor_user_id' => $actor->id, 'action_type' => 'DELETE GIS',
                    'module' => 'GIS Mapping', 'entity_type' => 'gis_location', 'entity_id' => $location->id,
                    'performed_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $code = $exception->errorInfo[0] ?? null;
            if (in_array($code, ['23503', '23514', '23502', '42501'], true)) {
                $blocker = 'A database constraint';
                $detail = $exception->errorInfo[2] ?? '';
                if (preg_match('/constraint "([A-Za-z0-9_]{1,63})"/', $detail, $matches)) {
                    $blocker = 'Database constraint '.$matches[1];
                } elseif (preg_match('/column "([A-Za-z0-9_]{1,63})" of relation "(audit_logs|gis_locations)"/', $detail, $matches)) {
                    $blocker = 'Database column '.$matches[2].'.'.$matches[1];
                }
                throw ValidationException::withMessages(['deletion' => $code === '42501'
                    ? 'Database permissions prevent deletion or its audit entry. No change was committed.'
                    : $blocker.' prevents deletion or its audit entry. No change was committed.']);
            }
            throw $exception;
        }
    }

    private function lockIdentity(string $id): void
    {
        // Serialize creation and deletion so a delayed create retry cannot restore a deleted ID.
        DB::select('SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(?, 0))', [strtolower($id)]);
    }

    private function assertDeletionSchema(): void
    {
        $privileges = DB::selectOne("SELECT
            pg_catalog.has_table_privilege(current_user, 'public.gis_locations', 'DELETE') AS can_delete,
            pg_catalog.has_table_privilege(current_user, 'public.audit_logs', 'INSERT') AS can_audit");
        foreach (['can_delete' => 'DELETE on gis_locations', 'can_audit' => 'INSERT on audit_logs'] as $field => $permission) {
            if (! in_array($privileges->$field, [true, 1, '1', 't', 'true'], true)) {
                throw ValidationException::withMessages(['deletion' => 'The server database role requires '.$permission.'. No location was deleted.']);
            }
        }
        DB::statement('LOCK TABLE public.gis_locations IN ROW EXCLUSIVE MODE');
        $foreignKey = DB::selectOne("SELECT c.conname, n.nspname, t.relname
            FROM pg_catalog.pg_constraint AS c
            JOIN pg_catalog.pg_class AS t ON t.oid = c.conrelid
            JOIN pg_catalog.pg_namespace AS n ON n.oid = t.relnamespace
            WHERE c.contype = 'f' AND c.confrelid = pg_catalog.to_regclass('public.gis_locations')
            ORDER BY n.nspname, t.relname, c.conname LIMIT 1");
        if ($foreignKey) {
            throw ValidationException::withMessages(['deletion' => 'Deletion requires schema review: incoming foreign key '
                .$foreignKey->conname.' on '.$foreignKey->nspname.'.'.$foreignKey->relname.'. No dependent records were changed.']);
        }
        $trigger = DB::selectOne("SELECT tgname FROM pg_catalog.pg_trigger
            WHERE tgrelid = pg_catalog.to_regclass('public.gis_locations') AND NOT tgisinternal
                AND tgenabled <> 'D' AND (tgtype::integer & 8) <> 0 ORDER BY tgname LIMIT 1");
        if ($trigger) {
            throw ValidationException::withMessages(['deletion' => 'Deletion requires schema review: DELETE trigger '
                .$trigger->tgname.' is enabled on gis_locations. No location was deleted.']);
        }
    }

    private function actor(UserProfile $user): UserProfile
    {
        $actor = UserProfile::whereKey($user->id)->lockForUpdate()->first();
        $role = $actor ? Role::whereKey($actor->role_id)->sharedLock()->first() : null;
        abort_unless($actor && $role && $role->code === $user->role?->code, 403);
        $actor->setRelation('role', $role);
        abort_unless($this->queries->manager($actor), 403);

        return $actor;
    }

    private function lockParent(array $data, UserProfile $user): Association
    {
        abort_unless(in_array($data['location_type'], GisLocation::TYPES, true), 404);
        $projectId = $data['project_id'] ?? null;
        abort_unless($projectId !== null && ($data['association_id'] ?? null) === null, 422, 'Select a valid project parent.');
        $project = Project::whereKey($projectId)->lockForUpdate()->first();
        abort_unless($project && $project->archived_at === null, 404);
        $association = Association::whereKey($project->association_id)->lockForUpdate()->first();
        abort_unless($association && $association->status === 'active' && $association->archived_at === null, 404);
        abort_unless($user->role->code === 'system_admin' || $association->primary_field_officer_id === $user->id, 404);
        if (isset($data['parent_association_id'])) {
            abort_unless($project->association_id === $data['parent_association_id'], 422, 'The project must belong to the selected association.');
        }

        return $association;
    }

    private function locked(string $id): GisLocation
    {
        $location = GisLocation::whereKey($id)->select('gis_locations.*')
            ->selectRaw('updated_at::text AS version, extensions.ST_AsGeoJSON(geom, 15, 0) AS geometry_json')
            ->lockForUpdate()->first();
        abort_unless($location, 404);

        return $location;
    }

    private function assertVersion(GisLocation $location, string $version): void
    {
        abort_unless(hash_equals($location->version, $version), 409, 'This location changed. Reload it before saving.');
    }

    private function payload(array $data): array
    {
        $data['geometry'] = $this->geometry->normalize($data['geometry'], $data['location_type']);
        foreach (['association_id', 'project_id', 'address', 'public_title', 'public_description', 'public_address'] as $field) {
            $data[$field] = $data[$field] ?? null;
        }

        return $data;
    }

    private function requirePublicTitle(?string $title): void
    {
        if ($title === null || trim($title) === '') {
            throw ValidationException::withMessages(['public_title' => 'Provide an approved public title before publishing. Unpublish before removing that title.']);
        }
    }

    private function snapshot(GisLocation $location): array
    {
        $fields = ['association_id', 'project_id', 'location_type', 'location_name', 'address', 'public_title',
            'public_description', 'public_address', 'is_published', 'published_by', 'published_at', 'archived_at', 'archived_by'];
        $values = [];
        foreach ($fields as $field) $values[$field] = $location->$field;
        $values['geometry'] = json_decode($location->geometry_json, true);

        return $values;
    }

    private function audit(string $action, GisLocation $location, ?array $old, array $new, UserProfile $user, Request $request): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'actor_user_id' => $user->id, 'action_type' => $action,
            'module' => 'GIS Mapping', 'entity_type' => 'gis_location', 'entity_id' => $location->id,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'ip_address' => filter_var($request->ip(), FILTER_VALIDATE_IP) ?: null,
            'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500), 'performed_at' => now(),
        ]);
    }
}
