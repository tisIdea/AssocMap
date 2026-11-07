<?php

use App\Services\Projects\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Uses only the existing ProgramActorVerification disposable-cluster bootstrap.
function verifyProjectTransfers(PDO $observer): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        throw new LogicException('Native PostgreSQL verification required.');
    }

    $destination = '30000000-0000-4000-8000-000000000002';
    foreach (['admin', 'officer'] as $role) {
        foreach (['none', 'training', 'archived training', 'activity', 'archived activity', 'monitoring'] as $link) {
            fixtures('project', 'update');
            DB::table('associations')->insert(['id' => $destination, 'name' => 'Synthetic destination',
                'area_id' => DB::table('areas')->value('id'), 'saad_phase2_join_date' => '2025-01-01',
                'status' => 'active', 'primary_field_officer_id' => OFFICER]);
            $service = app(ProjectService::class);
            $project = $service->create(payload('project'), actor($role));
            $data = array_replace(payload('project'), ['association_id' => $destination]);
            if (str_contains($link, 'training') || str_contains($link, 'activity')) {
                $table = str_contains($link, 'training') ? 'trainings' : 'program_activity_records';
                $id = $table === 'trainings' ? TRAINING : ACTIVITY;
                DB::table($table)->where('id', $id)->update(['association_id' => ASSOCIATION,
                    'related_project_id' => $project->id,
                    'archived_at' => str_starts_with($link, 'archived') ? now() : null]);
            } elseif ($link === 'monitoring') {
                DB::table('monitoring_records')->insert(['project_id' => $project->id, 'year' => 2026,
                    'quarter_id' => 1, 'monitored_by' => OFFICER, 'monitoring_date' => '2026-01-01', 'project_status' => 'ongoing']);
            }
            // A GIS location alone does not forbid a transfer; its geometry and provenance must survive.
            DB::table('gis_locations')->where('id', '70000000-0000-4000-8000-000000000002')
                ->update(['project_id' => $project->id]);
            $before = snapshot($observer);
            $auditBefore = DB::table('audit_logs')->orderBy('id')->get()->toJson();
            try {
                $updated = $service->update($project, $data, actor($role));
                check($link === 'none', $role.' '.$link.' transfer must be blocked');
                check($updated->association_id === $destination, $role.' unlinked project transfers');
            } catch (ValidationException $exception) {
                check($link !== 'none' && isset($exception->errors()['association_id']), $role.' '.$link.' has association validation error');
            }
            $after = snapshot($observer);
            if ($link === 'none') {
                unset($before['projects'], $after['projects']);
            }
            check($before === $after, $role.' '.$link.' retains all historical rows and GIS EWKB/provenance');
            check($auditBefore === DB::table('audit_logs')->orderBy('id')->get()->toJson(), $role.' '.$link.' retains audit history');
            if ($link !== 'none') {
                $edited = $service->update($project, array_replace(payload('project'), ['title' => 'Same association edit']), actor($role));
                check($edited->title === 'Same association edit' && $edited->association_id === ASSOCIATION,
                    $role.' '.$link.' permits same-association editing');
            }
        }
    }
    echo "Project transfers: 12 native PostgreSQL scenarios passed\n";
}
