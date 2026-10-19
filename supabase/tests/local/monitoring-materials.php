<?php

declare(strict_types=1);

require __DIR__.'/bootstrap-application.php';

use App\Exceptions\MonitoringMaterialsConflictException;
use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\MaterialsMonitoringService;
use App\Services\MonitoringService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$scratch = new ScratchDatabase($argv[1] ?? '');
$app = scratchApplication($scratch);

final class MaterialsScratchFixture
{
    use \Tests\Support\MonitoringMaterialsFixture;
    public function seed(): void { $this->schema(false); $this->fixtures(); }
}

function assertMaterials(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}
function materialsRecord(): MonitoringRecord { return MonitoringRecord::orderBy('id')->firstOrFail(); }
function materialsActor(string $role): UserProfile
{
    return UserProfile::with('role')->whereHas('role', fn ($query) => $query->where('code', $role))->firstOrFail();
}
function materialsPayload(array $entries = [], array $removed = []): array
{
    return ['materials_revision' => app(MaterialsMonitoringService::class)->revision(materialsRecord()),
        'materials' => $entries, 'deleted_material_ids' => $removed];
}
function materialsFacts(): array
{
    return DB::table('monitoring_materials')->orderBy('id')->get(['id', 'monitoring_record_id', 'project_material_id',
        'condition', 'scheduled_maintenance', 'actual_maintenance', 'remarks'])->map(fn ($row) => (array) $row)->all();
}

if (($argv[2] ?? '') === 'worker') {
    $name = $argv[3] ?? '';
    if (! in_array($name, ['addition', 'edit', 'removal'], true)) throw new RuntimeException('Unknown scratch worker.');
    $payload = json_decode(file_get_contents($scratch->directory.'/materials-'.$name.'-payload.json'), true, 512, JSON_THROW_ON_ERROR);
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    file_put_contents($scratch->directory.'/materials-'.$name.'-ready.json', json_encode(['pid' => (int) $pid], JSON_THROW_ON_ERROR));
    try {
        app(MonitoringService::class)->updateSection(materialsRecord(), 'materials', $payload, materialsActor('field_officer'));
        $result = ['status' => 200];
    } catch (MonitoringMaterialsConflictException $exception) {
        $result = ['status' => $exception->getStatusCode()];
    }
    file_put_contents($scratch->directory.'/materials-'.$name.'-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

$tables = $scratch->pdo->query("SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")->fetchColumn();
assertMaterials((int) $tables === 0, 'A fresh empty disposable database is required; no tables were changed.');
(new MaterialsScratchFixture)->seed();
$root = dirname(__DIR__, 3);
$baseline = file_get_contents($root.'/supabase/migrations/001_assocmap_schema.sql');
assertMaterials(preg_match('/CREATE TABLE public\.monitoring_materials \([\s\S]*?\n\);/', $baseline, $tableSql) === 1, 'Actual Materials table SQL was not found.');
// Replace only the empty synthetic fixture table with its exact declared PostgreSQL definition.
assertMaterials(DB::table('monitoring_materials')->count() === 0, 'Fixture Materials must be empty.');
DB::statement('DROP TABLE public.monitoring_materials');
DB::unprepared($tableSql[0]);
$start = strpos($baseline, 'CREATE OR REPLACE FUNCTION public.validate_monitoring_material_project()');
$end = strpos($baseline, '-- GIS parent and geometry', $start);
assertMaterials($start !== false && $end !== false, 'Actual Materials project validation SQL was not found.');
DB::unprepared(substr($baseline, $start, $end - $start));
$history = file_get_contents($root.'/supabase/migrations/014_protect_finalized_project_history.sql');
$start = strpos($history, 'CREATE FUNCTION public.project_history_project_ids(');
$end = strpos($history, 'REVOKE ALL ON FUNCTION', $start);
assertMaterials($start !== false && $end !== false, 'Actual history guard functions were not found.');
DB::unprepared(substr($history, $start, $end - $start));
foreach (['monitoring_records', 'monitoring_materials', 'project_materials'] as $table) {
    DB::unprepared('CREATE TRIGGER trg_protect_finalized_project_history AFTER INSERT OR UPDATE OR DELETE ON public.'.$table
        .' FOR EACH ROW EXECUTE FUNCTION public.protect_finalized_project_history()');
}

$materialIds = DB::table('project_materials')->orderBy('id')->pluck('id')->all();
$entry = static fn (int $index, string $condition = 'Good'): array => ['project_material_id' => $materialIds[$index],
    'condition' => $condition, 'scheduled_maintenance' => '2026-02-01', 'actual_maintenance' => '2026-02-02', 'remarks' => 'Synthetic PostgreSQL observation'];
$initialId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
DB::table('monitoring_materials')->insert($entry(0) + ['id' => $initialId, 'monitoring_record_id' => materialsRecord()->id]);
$beforeMigration = materialsFacts();
$oldIncomeVersion = materialsRecord()->income_version;
// 015's already-existing shape is supplied by the fixture; migration 015 is never executed here.
DB::unprepared(file_get_contents($root.'/supabase/migrations/016_add_monitoring_materials_version.sql'));
$column = $scratch->pdo->query("SELECT data_type, is_nullable, column_default FROM information_schema.columns
    WHERE table_schema='public' AND table_name='monitoring_records' AND column_name='materials_version'")->fetch(PDO::FETCH_ASSOC);
assertMaterials($column['data_type'] === 'bigint' && $column['is_nullable'] === 'NO' && $column['column_default'] === '0', 'Migration column shape mismatch.');
assertMaterials(materialsRecord()->materials_version === 0 && materialsFacts() === $beforeMigration
    && materialsRecord()->income_version === $oldIncomeVersion, 'Migration changed existing facts or Income version.');
$evidence = [['case' => 'actual migration 016', 'column' => $column, 'existing_facts_preserved' => true, 'income_version_unchanged' => true]];

function rejectedMaterialsSql(string $sql, string $state): void
{
    DB::beginTransaction();
    try {
        try { DB::statement($sql); throw new RuntimeException('Expected SQL rejection.'); }
        catch (\Illuminate\Database\QueryException $exception) {
            assertMaterials(($exception->errorInfo[0] ?? null) === $state, 'Unexpected integrity rejection: '.($exception->errorInfo[0] ?? 'unknown'));
        }
    } finally { DB::rollBack(); }
}
rejectedMaterialsSql('UPDATE monitoring_records SET materials_version=-1', '23514');
rejectedMaterialsSql('UPDATE monitoring_records SET materials_version=NULL', '23502');
rejectedMaterialsSql("INSERT INTO monitoring_materials(monitoring_record_id,project_material_id,condition) VALUES ('".materialsRecord()->id."','".$materialIds[0]."','Duplicate')", '23505');
rejectedMaterialsSql("DELETE FROM project_materials WHERE id='".$materialIds[0]."'", '23001');
$evidence[] = ['case' => 'native constraints', 'negative_version' => '23514', 'null_version' => '23502', 'duplicate_reference' => '23505', 'referenced_inventory_delete' => '23001'];

foreach (['addition', 'edit', 'removal'] as $case) {
    $before = materialsFacts();
    $stale = $case === 'removal' ? materialsPayload([], [$before[0]['id']]) : materialsPayload([$entry(2, 'Stale')]);
    $winner = match ($case) {
        'addition' => materialsPayload([$entry(1)]),
        'edit' => materialsPayload([array_replace($entry(0), ['id' => $initialId, 'condition' => 'Repaired'])]),
        default => materialsPayload([], [$initialId]),
    };
    file_put_contents($scratch->directory.'/materials-'.$case.'-payload.json', json_encode($stale, JSON_THROW_ON_ERROR));
    DB::beginTransaction(); $worker = null;
    try {
        app(MonitoringService::class)->updateSection(materialsRecord(), 'materials', $winner, materialsActor('system_admin'));
        $expected = materialsFacts(); $version = materialsRecord()->materials_version;
        $worker = new Process([PHP_BINARY, __FILE__, realpath($argv[1]), 'worker', $case], $root);
        $worker->setTimeout(20)->start(); $deadline = microtime(true) + 10; $wait = null;
        do {
            $readyPath = $scratch->directory.'/materials-'.$case.'-ready.json';
            if (is_file($readyPath)) {
                $ready = json_decode(file_get_contents($readyPath), true, 512, JSON_THROW_ON_ERROR);
                $query = $scratch->pdo->prepare('SELECT wait_event_type, wait_event FROM pg_catalog.pg_stat_activity WHERE pid=?');
                $query->execute([$ready['pid']]); $wait = $query->fetch(PDO::FETCH_ASSOC);
                if (($wait['wait_event_type'] ?? '') === 'Lock') break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && $worker->isRunning());
        assertMaterials(($wait['wait_event_type'] ?? '') === 'Lock', 'Second real connection was not observed waiting on a lock.');
        DB::commit();
        assertMaterials($worker->wait() === 0, 'Scratch worker failed: '.$worker->getErrorOutput());
        $result = json_decode(file_get_contents($scratch->directory.'/materials-'.$case.'-result.json'), true, 512, JSON_THROW_ON_ERROR);
        assertMaterials($result['status'] === 409, 'Waiting stale writer must return conflict.');
        assertMaterials(materialsFacts() === $expected && materialsRecord()->materials_version === $version, 'Stale writer changed facts or version.');
        $evidence[] = ['case' => $case, 'second_connection_wait' => $wait, 'stale_status' => 409,
            'rows' => count($expected), 'materials_version' => $version];
    } finally {
        if (DB::transactionLevel() > 0) DB::rollBack();
        if ($worker?->isRunning()) $worker->stop(1);
    }
}

$before = materialsFacts(); $version = materialsRecord()->materials_version;
DB::beginTransaction();
try {
    DB::unprepared("CREATE FUNCTION pg_temp.reject_materials_version() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic rollback'; END \$\$");
    DB::unprepared('CREATE TRIGGER scratch_reject_materials_version BEFORE UPDATE OF materials_version ON monitoring_records FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_materials_version()');
    try {
        app(MonitoringService::class)->updateSection(materialsRecord(), 'materials', materialsPayload([$entry(2)], [$before[0]['id']]), materialsActor('system_admin'));
        throw new RuntimeException('Expected native late failure.');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        assertMaterials($exception->status === 503 && materialsFacts() === $before && materialsRecord()->materials_version === $version, 'Late failure did not roll back facts and version.');
    }
} finally { DB::rollBack(); }
$evidence[] = ['case' => 'native late failure rollback', 'unchanged' => true, 'materials_version' => $version];

foreach (['completed', 'terminated'] as $state) {
    DB::beginTransaction();
    try {
        DB::table('projects')->update(['status' => $state, 'closed_at' => '2026-03-01']);
        foreach (["UPDATE monitoring_materials SET condition='Forbidden'", 'DELETE FROM monitoring_materials',
            'UPDATE monitoring_records SET materials_version=materials_version+1',
            "INSERT INTO monitoring_materials(monitoring_record_id,project_material_id,condition) VALUES ('".materialsRecord()->id."','".$materialIds[2]."','Forbidden')"] as $sql) {
            rejectedMaterialsSql($sql, '23514');
        }
    } finally { DB::rollBack(); }
    assertMaterials(materialsFacts() === $before && materialsRecord()->materials_version === $version, 'History guard did not preserve facts.');
    $evidence[] = ['case' => $state.' history guard', 'insert_update_delete_and_counter_rejected' => true];
}

app(MonitoringService::class)->updateSection(materialsRecord(), 'materials', materialsPayload([], [$before[0]['id']]), materialsActor('field_officer'));
assertMaterials(materialsFacts() === [] && materialsRecord()->materials_version === $version + 1, 'Fresh explicit deletion failed.');
$evidence[] = ['case' => 'fresh explicit deletion', 'rows' => 0, 'materials_version' => materialsRecord()->materials_version];
file_put_contents($scratch->directory.'/monitoring-materials-results.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
echo 'PASS: migration 016, native constraints/history triggers, three lock races, deletion and rollback. No Supabase connection.'.PHP_EOL;
