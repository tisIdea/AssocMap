<?php

namespace Tests\Support;

use Illuminate\Database\SQLiteConnection;
use RuntimeException;

// Application decisions only. No PostGIS, catalogue, trigger or lock semantics are simulated as proof.
final class GisSqliteConnection extends SQLiteConnection
{
    public array $deleteTriggers = [];
    public array $incomingForeignKeys = [];
    public bool $rejectAudit = false;

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (str_contains($query, 'pg_advisory_xact_lock')) return [];
        if (str_contains($query, 'has_table_privilege')) return [(object) ['can_delete' => true, 'can_audit' => true]];
        if (str_contains($query, 'pg_catalog.pg_constraint')) return $this->incomingForeignKeys;
        if (str_contains($query, 'pg_catalog.pg_trigger')) return $this->deleteTriggers;
        if (str_contains($query, 'extensions.ST_Equals')) {
            $row = $this->table('gis_locations')->where('id', $bindings[1])->first();
            return [(object) ['same' => json_decode($row->geom, true) === json_decode($bindings[0], true)]];
        }

        return parent::select($this->portableSql($query), $bindings, $useReadPdo);
    }

    public function statement($query, $bindings = [])
    {
        if ($query === 'LOCK TABLE public.gis_locations IN ROW EXCLUSIVE MODE') return true;
        if ($this->rejectAudit && str_contains($query, 'insert into "audit_logs"')) {
            throw new RuntimeException('Synthetic audit failure.');
        }

        return parent::statement($query, $bindings);
    }

    public function affectingStatement($query, $bindings = [])
    {
        return parent::affectingStatement($this->portableSql($query), $bindings);
    }

    private function portableSql(string $query): string
    {
        return str_replace([
            'updated_at::text', 'extensions.ST_AsGeoJSON(geom, 15, 0)',
            'extensions.ST_SetSRID(extensions.ST_GeomFromGeoJSON(?), 4326)',
        ], ['CAST(updated_at AS TEXT)', 'geom', '?'], $query);
    }

    public static function approvedTrigger(): object
    {
        return (object) [
            'tgname' => 'trg_protect_finalized_project_history', 'tgtype' => 29, 'tgenabled' => 'O',
            'tgnargs' => 0, 'tgconstraint' => 0, 'columns' => '', 'condition' => null,
            'tgoldtable' => null, 'tgnewtable' => null, 'function_oid' => '12345', 'expected_oid' => '12345',
            'lanname' => 'plpgsql', 'prosecdef' => true, 'pronargs' => 0, 'return_type' => 'trigger',
            'trusted_owner' => true, 'function_config' => '{"search_path=pg_catalog, public, pg_temp",row_security=off}',
        ];
    }
}
