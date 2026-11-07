<?php

namespace App\Services\GIS;

use App\Models\GisLocation;
use App\Models\UserProfile;
use App\Services\Projects\ProjectLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GisLocationService
{
    public function __construct(private GisGeometry $geometry, private GisQueryService $queries,
        private ProjectLifecycleService $projects, private GisAccessService $access) {}

    public function store(array $data, UserProfile $user, Request $request): array
    {
        $data = $this->payload($data);
        $data += ['site_type_id' => null];

        return DB::transaction(function () use ($data, $user, $request) {
            $actor = $this->access->actor($user);
            $this->access->identity($data['idempotency_key']);
            $existing = GisLocation::find($data['idempotency_key']);
            $this->access->parents($existing ? [$data, $existing->getAttributes()] : [$data], $actor);
            $data['site_type_id'] = $this->siteType($data['site_type_id']);
            abort_if(DB::table('audit_logs')->where('entity_type', 'gis_location')
                ->where('entity_id', $data['idempotency_key'])->where('action_type', 'DELETE GIS')->exists(), 409);
            $this->geometry->validateSpatial($data['geometry']);
            $created = DB::affectingStatement('INSERT INTO gis_locations
                (id, association_id, project_id, location_type, site_type_id, location_name, address, geom,
                 public_title, public_description, public_address, is_published, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, FALSE, ?, ?)
                ON CONFLICT (id) DO NOTHING', [
                $data['idempotency_key'], $data['association_id'], $data['project_id'], $data['location_type'],
                $data['site_type_id'],
                $data['location_name'], $data['address'], json_encode($data['geometry'], JSON_THROW_ON_ERROR),
                $data['public_title'], $data['public_description'], $data['public_address'], $actor->id, $actor->id,
            ]) === 1;
            $location = $this->locked($data['idempotency_key']);
            if (! $created) {
                abort_if($location->archived_at !== null, 409, 'This request has already been used. Refresh the register.');
                foreach (['association_id', 'project_id', 'location_type', 'site_type_id', 'location_name', 'address', 'public_title', 'public_description', 'public_address'] as $field) {
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
        $data = $this->payload($data, false);

        return DB::transaction(function () use ($id, $data, $user, $request) {
            $actor = $this->access->actor($user);
            $this->access->identity($id);
            $snapshot = GisLocation::findOrFail($id);
            $parents = $this->access->parents([$snapshot->getAttributes(), $data], $actor);
            $location = $this->locked($id);
            $this->access->unchangedOwner($snapshot, $location);
            $source = $parents[$location->project_id];
            $this->assertVersion($location, $data['version']);
            abort_if($location->archived_at !== null, 409, 'Archived locations cannot be edited.');
            $data['site_type_id'] = $this->siteType(array_key_exists('site_type_id', $data) ? $data['site_type_id'] : $location->site_type_id);
            $destination = $parents[$data['project_id']];
            if (($source->isFinalized() || $destination->isFinalized())
                && ($location->project_id !== $data['project_id'] || $location->association_id !== $data['association_id'])) {
                throw ValidationException::withMessages(['project_id' => 'A finalized project location must retain its existing project and association ownership.']);
            }
            if (! array_key_exists('geometry', $data) && $data['location_type'] !== $location->location_type) {
                throw ValidationException::withMessages(['geometry' => 'Supply replacement geometry when changing the geometry type.']);
            }
            $geometryChanged = array_key_exists('geometry', $data)
                && $data['geometry'] != json_decode($location->geometry_json, true, flags: JSON_THROW_ON_ERROR);
            if ($geometryChanged) $this->geometry->validateSpatial($data['geometry']);
            if ($location->is_published) $this->requirePublicTitle($data['public_title']);
            $old = $this->snapshot($location);
            $bindings = [$data['association_id'], $data['project_id'], $data['location_type'], $data['site_type_id'], $data['location_name'], $data['address']];
            $geometrySql = '';
            if ($geometryChanged) {
                $geometrySql = ', geom = extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326)';
                $bindings[] = json_encode($data['geometry'], JSON_THROW_ON_ERROR);
            }
            array_push($bindings, $data['public_title'], $data['public_description'], $data['public_address'], $actor->id, $id);
            DB::update('UPDATE gis_locations SET association_id = ?, project_id = ?, location_type = ?, site_type_id = ?,
                location_name = ?, address = ?'.$geometrySql.',
                public_title = ?, public_description = ?, public_address = ?, updated_by = ? WHERE id = ?', $bindings);
            $location = $this->locked($id);
            $this->audit('UPDATE', $location, $old, $this->snapshot($location), $actor, $request);

            return $this->queries->detail($actor, $id);
        }, 3);
    }

    public function correctGeometry(string $id, array $data, UserProfile $user, Request $request): array
    {
        if (array_diff(array_keys($data), ['geometry', 'version']) !== []) {
            throw ValidationException::withMessages(['geometry' => 'Only geometry and the expected version may be corrected.']);
        }

        return DB::transaction(function () use ($id, $data, $user, $request) {
            $actor = $this->access->actor($user);
            abort_unless($actor->role->code === 'system_admin', 403);
            $parent = $this->access->location($id, $actor, true);
            $location = $this->locked($id);
            abort_unless($location->association_id === null && $location->project_id !== null
                && in_array($location->location_type, GisLocation::TYPES, true), 404);
            $lockedProject = $parent->project;
            $association = $lockedProject->association;
            abort_unless($association->archived_at !== null || $lockedProject->archived_at !== null
                || $location->archived_at !== null, 409, 'Use the regular editor for an operational GIS location.');
            $this->assertVersion($location, $data['version']);
            $geometry = $this->geometry->normalize($data['geometry'], $location->location_type);
            $this->geometry->validateSpatial($geometry);
            $old = $this->geometrySnapshot($location);
            DB::update('UPDATE gis_locations SET geom = extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326),
                updated_by = ?, updated_at = ? WHERE id = ?', [json_encode($geometry, JSON_THROW_ON_ERROR),
                $actor->id, now()->format('Y-m-d H:i:s.u'), $id]);
            $location = $this->locked($id);
            $this->audit('CORRECT GIS GEOMETRY', $location, $old, $this->geometrySnapshot($location), $actor, $request);

            return $this->queries->detail($actor, $id);
        }, 3);
    }

    private function geometrySnapshot(GisLocation $location): array
    {
        return ['association_id' => $location->association_id, 'project_id' => $location->project_id,
            'location_type' => $location->location_type, 'geometry' => json_decode($location->geometry_json, true)];
    }

    public function lifecycle(string $id, string $action, string $version, UserProfile $user, Request $request): array
    {
        abort_unless(in_array($action, ['publish', 'unpublish', 'archive'], true), 404);

        return DB::transaction(function () use ($id, $action, $version, $user, $request) {
            $actor = $this->access->actor($user);
            $this->access->location($id, $actor);
            $location = $this->locked($id);
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
                $actor = $this->access->actor($user);
                $parent = $this->access->location($id, $actor);
                $location = GisLocation::whereKey($id)->select(['id', 'association_id', 'project_id', 'location_type'])
                    ->selectRaw('updated_at::text AS version')->lockForUpdate()->first();
                abort_unless($location, 404);
                $project = $parent->project;
                $this->projects->assertNotFinalized($project, 'deletion');
                $this->assertVersion($location, $version);
                $this->assertDeletionSchema($location->id);
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

    private function assertDeletionSchema(string $locationId): void
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
        $foreignKeys = DB::select("SELECT c.conname, n.nspname, t.relname,
                (c.conrelid = pg_catalog.to_regclass('public.media_photos')
                 AND c.conname = 'media_photos_gis_location_id_fkey'
                 AND c.confdeltype = 'r' AND c.confupdtype = 'a' AND c.confmatchtype = 's'
                 AND c.convalidated AND NOT c.condeferrable AND NOT c.condeferred
                 AND c.conkey = ARRAY[(SELECT a.attnum FROM pg_catalog.pg_attribute a
                     WHERE a.attrelid = c.conrelid AND a.attname = 'gis_location_id')]::smallint[]
                 AND c.confkey = ARRAY[(SELECT a.attnum FROM pg_catalog.pg_attribute a
                     WHERE a.attrelid = c.confrelid AND a.attname = 'id')]::smallint[]
                ) AS approved_media_fk
            FROM pg_catalog.pg_constraint AS c
            JOIN pg_catalog.pg_class AS t ON t.oid = c.conrelid
            JOIN pg_catalog.pg_namespace AS n ON n.oid = t.relnamespace
            WHERE c.contype = 'f' AND c.confrelid = pg_catalog.to_regclass('public.gis_locations')
            ORDER BY n.nspname, t.relname, c.conname");
        $hasMediaForeignKey = false;
        foreach ($foreignKeys as $foreignKey) {
            if (in_array($foreignKey->approved_media_fk, [true, 1, '1', 't', 'true'], true)) {
                $hasMediaForeignKey = true;

                continue;
            }
            throw ValidationException::withMessages(['deletion' => 'Deletion requires schema review: incoming foreign key '
                .$foreignKey->conname.' on '.$foreignKey->nspname.'.'.$foreignKey->relname.'. No dependent records were changed.']);
        }
        if ($hasMediaForeignKey && DB::table('media_photos')->where('gis_location_id', $locationId)->exists()) {
            throw ValidationException::withMessages(['deletion' => 'This location has photo history and cannot be permanently deleted. '
                .'Archive it instead. No photo records were changed.']);
        }
        $triggers = DB::select("SELECT t.tgname, t.tgtype, t.tgenabled, t.tgnargs, t.tgconstraint,
                t.tgattr::text AS columns, t.tgqual::text AS condition, t.tgoldtable, t.tgnewtable,
                p.oid::text AS function_oid,
                pg_catalog.to_regprocedure('public.protect_finalized_project_history()')::oid::text AS expected_oid,
                l.lanname, p.prosecdef, p.pronargs, p.prorettype::regtype::text AS return_type,
                p.proconfig::text[] AS function_config,
                (p.proowner = g.relowner AND p.proowner = j.relowner) AS trusted_owner
            FROM pg_catalog.pg_trigger t
            JOIN pg_catalog.pg_proc p ON p.oid = t.tgfoid
            JOIN pg_catalog.pg_language l ON l.oid = p.prolang
            JOIN pg_catalog.pg_class g ON g.oid = t.tgrelid
            JOIN pg_catalog.pg_class j ON j.oid = pg_catalog.to_regclass('public.projects')
            WHERE t.tgrelid = pg_catalog.to_regclass('public.gis_locations') AND NOT t.tgisinternal
                AND t.tgenabled <> 'D' AND (t.tgtype::integer & 8) <> 0 ORDER BY t.tgname");
        foreach ($triggers as $trigger) {
            if (! $this->approvedHistoryTrigger($trigger)) {
                throw ValidationException::withMessages(['deletion' => 'Deletion requires schema review: DELETE trigger '
                    .$trigger->tgname.' has an unapproved definition on gis_locations. No location was deleted.']);
            }
        }
    }

    private function approvedHistoryTrigger(object $trigger): bool
    {
        // Migration 014: AFTER ROW INSERT/UPDATE/DELETE, no arguments or filters.
        return $trigger->tgname === 'trg_protect_finalized_project_history'
            && (int) $trigger->tgtype === 29 && $trigger->tgenabled === 'O'
            && (int) $trigger->tgnargs === 0 && (int) $trigger->tgconstraint === 0
            && $trigger->columns === '' && $trigger->condition === null
            && $trigger->tgoldtable === null && $trigger->tgnewtable === null
            && $trigger->expected_oid !== null && $trigger->function_oid === $trigger->expected_oid
            && $trigger->lanname === 'plpgsql' && (int) $trigger->pronargs === 0
            && $trigger->return_type === 'trigger'
            && in_array($trigger->prosecdef, [true, 1, '1', 't', 'true'], true)
            && in_array($trigger->trusted_owner, [true, 1, '1', 't', 'true'], true)
            && $trigger->function_config === '{"search_path=pg_catalog, public, pg_temp",row_security=off}';
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

    private function payload(array $data, bool $requireGeometry = true): array
    {
        if ($requireGeometry || array_key_exists('geometry', $data)) {
            $data['geometry'] = $this->geometry->normalize($data['geometry'] ?? null, $data['location_type']);
        }
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

    private function siteType(mixed $id): ?int
    {
        if ($id === null) return null;
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || ! DB::table('gis_site_types')->where('id', $id)->sharedLock()->first(['id'])) {
            throw ValidationException::withMessages(['site_type_id' => 'Select an available site classification.']);
        }

        return (int) $id;
    }

    private function snapshot(GisLocation $location): array
    {
        $fields = ['association_id', 'project_id', 'location_type', 'site_type_id', 'location_name', 'address', 'public_title',
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
