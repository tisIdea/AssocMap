<?php

declare(strict_types=1);

// Explicit disposable target only; never boots Laravel's normal environment.
require __DIR__.'/bootstrap-application.php';

use App\Models\MonitoringProduction;
use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\DailyHarvestMonitoringService;
use App\Services\ProductionMonitoringService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

$scratch = new ScratchDatabase($argv[1] ?? '');
$app = scratchApplication($scratch);
$root = dirname(__DIR__, 3);

final class DailyHarvestScratchFixture
{
    use \Tests\Support\DailyHarvestFixture;
    public function seed(): void { $this->harvestSchema(false); $this->harvestFixtures(); }
}
function assertHarvest(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}
function harvestRecord(): MonitoringRecord { return MonitoringRecord::orderBy('id')->firstOrFail(); }
function harvestRequest(array $payload): Request
{
    $request = Request::create('/synthetic-harvest', 'POST', $payload);
    $request->attributes->set('current_user', UserProfile::with('role')
        ->whereHas('role', fn ($q) => $q->where('code', 'system_admin'))->firstOrFail());
    return $request;
}
function harvestPayload(array $values = []): array
{
    return $values + ['production_revision' => app(ProductionMonitoringService::class)->revision(harvestRecord())];
}
function rejectedHarvestSql(string $sql, array $bindings = [], string $state = '23514'): void
{
    DB::beginTransaction();
    try {
        try {
            DB::statement($sql, $bindings);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            throw new RuntimeException('Expected native harvest integrity rejection.');
        } catch (QueryException $exception) {
            assertHarvest(($exception->errorInfo[0] ?? null) === $state, 'Unexpected SQLSTATE in harvest integrity test.');
        }
    } finally { DB::rollBack(); }
}

if (($argv[2] ?? '') === 'worker') {
    $case = $argv[3] ?? '';
    assertHarvest(in_array($case, ['create', 'edit', 'void', 'goal', 'initialize'], true), 'Unknown harvest worker.');
    $job = json_decode(file_get_contents($scratch->directory.'/harvest-'.$case.'-payload.json'), true, 512, JSON_THROW_ON_ERROR);
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $ready = $scratch->directory.'/harvest-'.$case.'-ready.json';
    file_put_contents($ready.'.tmp', json_encode(['pid' => (int) $pid], JSON_THROW_ON_ERROR));
    if (! rename($ready.'.tmp', $ready)) throw new RuntimeException('Cannot publish harvest worker readiness.');
    $service = app(DailyHarvestMonitoringService::class);
    try {
        $request = harvestRequest($job['payload']);
        match ($case) {
            'create' => $service->saveHarvest(harvestRecord(), $job['production_id'], $request),
            'edit' => $service->saveHarvest(harvestRecord(), $job['production_id'], $request, $job['entry_id']),
            'void' => $service->voidHarvest(harvestRecord(), $job['production_id'], $job['entry_id'], $request),
            'goal' => $service->reviseGoal(harvestRecord(), $job['production_id'], $request),
            'initialize' => $service->initialize(harvestRecord(), $request),
        };
        $result = ['status' => 200];
    } catch (ValidationException $exception) { $result = ['status' => $exception->status]; }
    file_put_contents($scratch->directory.'/harvest-'.$case.'-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

assertHarvest((int) $scratch->pdo->query("SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")->fetchColumn() === 0,
    'A fresh empty disposable database is required; no tables were changed.');
(new DailyHarvestScratchFixture)->seed();
$baseline = file_get_contents($root.'/supabase/migrations/001_assocmap_schema.sql');
$monthly = file_get_contents($root.'/supabase/migrations/005_add_monitoring_monthly_production_and_conductors.sql');

// Install exact production/monthly/audit definitions over empty synthetic ancillary fixtures.
$aggregate = (array) DB::table('monitoring_production')->first();
foreach (['monitoring_production' => $baseline, 'monitoring_production_monthly' => $monthly, 'audit_logs' => $baseline] as $table => $source) {
    assertHarvest(preg_match('/CREATE TABLE public\.'.$table.' \([\s\S]*?\n\);/', $source, $match) === 1, 'Actual table definition unavailable.');
    DB::statement('DROP TABLE public.'.$table);
    DB::unprepared($match[0]);
}
unset($aggregate['created_at'], $aggregate['updated_at']);
DB::table('monitoring_production')->insert($aggregate);
$aggregate = (array) DB::table('monitoring_production')->where('id', $aggregate['id'])->first();
assertHarvest(preg_match('/CREATE OR REPLACE FUNCTION public\.set_updated_at\(\)[\s\S]*?\$\$;/', $baseline, $match) === 1,
    'Actual timestamp function unavailable.');
DB::unprepared($match[0]);
$start = strpos($monthly, 'CREATE FUNCTION public.validate_monitoring_production_monthly_period()');
$end = strpos($monthly, '-- 8. Add Visit Report conductor fields.', $start);
assertHarvest($start !== false && $end !== false, 'Actual monthly guards unavailable.');
DB::unprepared(substr($monthly, $start, $end - $start));
DB::unprepared(file_get_contents($root.'/supabase/migrations/012_allow_zero_production_activities.sql'));
$history = file_get_contents($root.'/supabase/migrations/014_protect_finalized_project_history.sql');
$start = strpos($history, 'CREATE FUNCTION public.project_history_project_ids(');
$end = strpos($history, 'REVOKE ALL ON FUNCTION', $start);
assertHarvest($start !== false && $end !== false, 'Actual finalized ancestry functions unavailable.');
DB::unprepared(substr($history, $start, $end - $start));
foreach (['monitoring_records', 'monitoring_production', 'monitoring_production_monthly'] as $table) {
    DB::unprepared('CREATE TRIGGER trg_protect_finalized_project_history AFTER INSERT OR UPDATE OR DELETE ON public.'.$table
        .' FOR EACH ROW EXECUTE FUNCTION public.protect_finalized_project_history()');
}
// Same private role names as Supabase, without any login or external connectivity.
DB::unprepared("DO \$roles\$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='anon') THEN CREATE ROLE anon NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='authenticated') THEN CREATE ROLE authenticated NOLOGIN; END IF;
END \$roles\$;");
DB::unprepared(file_get_contents($root.'/supabase/migrations/019_add_daily_harvest_production.sql'));
$after = (array) DB::table('monitoring_production')->where('id', $aggregate['id'])->first();
assertHarvest(array_intersect_key($after, $aggregate) === $aggregate && $after['quantity_source'] === 'aggregate',
    'Migration changed historical aggregate facts.');
$evidence = [['case' => 'actual migration 019', 'aggregate_facts_preserved' => true]];
$service = app(DailyHarvestMonitoringService::class);
$product = DB::table('project_products')->where('product_name', 'Synthetic daily product')->value('id');
$row = $service->initialize(harvestRecord(), harvestRequest(harvestPayload(['project_product_id' => $product])));
assertHarvest($row->monthlyDetails()->count() === 3 && $row->harvestEntries()->count() === 0,
    'Initialization must create projections without fabricated daily entries.');
$service->saveHarvest(harvestRecord(), $row->id, harvestRequest(harvestPayload(['harvest_date' => '2026-01-01', 'quantity' => '0.10'])));
$service->saveHarvest(harvestRecord(), $row->id, harvestRequest(harvestPayload(['harvest_date' => '2026-02-01', 'quantity' => '0.20'])));
assertHarvest($row->fresh()->actual_output === '0.30', 'Exact PostgreSQL projections failed.');
$entry = $row->harvestEntries()->firstOrFail();
$actor = $entry->created_by;
$insert = 'INSERT INTO monitoring_harvest_entries(monitoring_production_id,harvest_date,quantity,created_by,updated_by) VALUES (?,?,?,?,?)';
rejectedHarvestSql($insert, [$row->id, '2026-01-01', '1', $actor, $actor], '23505');
foreach ([['2026-01-03', '1.001'], ['2026-01-03', '1000000000000'], ['2026-01-03', '0'],
    ['2026-01-03', '-1'], ['2025-01-01', '1'], ['2026-04-01', '1']] as [$date, $quantity]) {
    rejectedHarvestSql($insert, [$row->id, $date, $quantity, $actor, $actor]);
}
rejectedHarvestSql("INSERT INTO monitoring_harvest_entries(monitoring_production_id,harvest_date,quantity,created_by,updated_by)
    VALUES (?, (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date + 1, 1, ?, ?)", [$row->id, $actor, $actor]);
rejectedHarvestSql('UPDATE monitoring_production SET actual_output=999 WHERE id=?', [$row->id]);
rejectedHarvestSql('UPDATE monitoring_production_monthly SET total_quantity=999 WHERE monitoring_production_id=? AND month=1', [$row->id]);
rejectedHarvestSql('UPDATE monitoring_production_monthly SET activity_count=99 WHERE monitoring_production_id=? AND month=1', [$row->id]);
rejectedHarvestSql('UPDATE monitoring_harvest_entries SET quantity=9 WHERE id=?', [$entry->id]);
rejectedHarvestSql('DELETE FROM monitoring_harvest_entries WHERE id=?', [$entry->id]);
rejectedHarvestSql('DELETE FROM monitoring_production WHERE id=?', [$row->id]);
rejectedHarvestSql('DELETE FROM monitoring_production_monthly WHERE monitoring_production_id=?', [$row->id]);
rejectedHarvestSql("UPDATE monitoring_production SET quantity_source='aggregate' WHERE id=?", [$row->id]);
rejectedHarvestSql("UPDATE monitoring_production SET quantity_source='daily_harvest' WHERE id=?", [$aggregate['id']]);
rejectedHarvestSql('UPDATE monitoring_harvest_entries SET monitoring_production_id=? WHERE id=?', [$aggregate['id'], $entry->id]);
rejectedHarvestSql('UPDATE monitoring_records SET year=2025 WHERE id=?', [harvestRecord()->id], 'P0001');
assertHarvest($row->fresh()->actual_output === '0.30', 'Rejected writes changed the saved quarter.');
$evidence[] = ['case' => 'native uniqueness, decimal, period, projection, identity and deletion guards', 'status' => 'passed'];
$privacy = DB::selectOne("SELECT relrowsecurity AS rls,
    has_table_privilege('anon','public.monitoring_harvest_entries','SELECT') AS anon_read,
    has_table_privilege('authenticated','public.monitoring_harvest_entries','INSERT') AS authenticated_write
    FROM pg_class WHERE oid='public.monitoring_harvest_entries'::regclass");
assertHarvest($privacy->rls && ! $privacy->anon_read && ! $privacy->authenticated_write, 'Harvest privacy privileges are too broad.');
$evidence[] = ['case' => 'RLS and direct-client privileges', 'status' => 'passed'];

foreach (['create', 'edit', 'void', 'goal', 'initialize'] as $case) {
    $values = match ($case) {
        'create' => ['harvest_date' => '2026-03-02', 'quantity' => '3'],
        'edit' => ['harvest_date' => '2026-03-03', 'quantity' => '4'],
        'void' => ['void_reason' => 'Stale correction'],
        'goal' => ['target_output' => '50', 'reason' => 'Stale plan'],
        'initialize' => ['project_product_id' => (string) Str::uuid()],
    };
    if ($case === 'initialize') DB::table('project_products')->insert(['id' => $values['project_product_id'],
        'project_id' => harvestRecord()->project_id, 'product_name' => 'Concurrent product', 'product_type' => 'raw',
        'default_unit' => 'kg', 'is_active' => true]);
    $job = ['production_id' => $row->id, 'entry_id' => $entry->id, 'payload' => harvestPayload($values)];
    file_put_contents($scratch->directory.'/harvest-'.$case.'-payload.json', json_encode($job, JSON_THROW_ON_ERROR));
    DB::beginTransaction(); $worker = null;
    try {
        if ($case === 'initialize') $service->initialize(harvestRecord(), harvestRequest($job['payload']));
        else $service->saveHarvest(harvestRecord(), $row->id,
            harvestRequest(harvestPayload(['harvest_date' => '2026-01-01', 'quantity' => '0.10', 'remarks' => 'Winner '.$case])), $entry->id);
        $expectedAudit = DB::table('audit_logs')->count();
        $worker = new Process([PHP_BINARY, __FILE__, realpath($argv[1]), 'worker', $case], $root);
        $worker->setTimeout(25)->start(); $deadline = microtime(true) + 10; $waiting = false;
        do {
            $ready = $scratch->directory.'/harvest-'.$case.'-ready.json';
            if (is_file($ready)) {
                $pid = json_decode(file_get_contents($ready), true, 512, JSON_THROW_ON_ERROR)['pid'];
                $query = $scratch->pdo->prepare('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?');
                $query->execute([$pid]); $waiting = $query->fetchColumn() === 'Lock';
                if ($waiting) break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && $worker->isRunning());
        assertHarvest($waiting, 'Second real connection did not wait for the parent lock.');
        DB::commit(); $worker->wait();
        assertHarvest($worker->isSuccessful(), 'Harvest worker failed.');
        $result = json_decode(file_get_contents($scratch->directory.'/harvest-'.$case.'-result.json'), true, 512, JSON_THROW_ON_ERROR);
        assertHarvest($result['status'] === 409 && DB::table('audit_logs')->count() === $expectedAudit,
            'Stale concurrent mutation changed data or audit history.');
        $evidence[] = ['case' => 'concurrent '.$case, 'observed_lock_wait' => true, 'loser_status' => 409];
    } finally {
        if (DB::transactionLevel() > 0) DB::rollBack();
        if ($worker?->isRunning()) $worker->stop();
    }
}

$service->voidHarvest(harvestRecord(), $row->id, $entry->id, harvestRequest(harvestPayload(['void_reason' => 'Incorrect entry'])));
assertHarvest($row->fresh()->actual_output === '0.20' && $entry->fresh()->quantity === '0.10', 'Voiding lost history or failed to recalculate.');
rejectedHarvestSql('UPDATE monitoring_harvest_entries SET remarks=? WHERE id=?', ['Attempted void edit', $entry->id]);
$service->saveHarvest(harvestRecord(), $row->id, harvestRequest(harvestPayload(['harvest_date' => '2026-01-01', 'quantity' => '0.40'])));
assertHarvest($row->fresh()->actual_output === '0.60', 'Active replacement after void failed.');
$service->reviseGoal(harvestRecord(), $row->id, harvestRequest(harvestPayload(['target_output' => '0.30', 'reason' => 'Approved revised plan'])));
$goal = DB::table('audit_logs')->where('entity_type', 'monitoring_production_goal')->first();
assertHarvest(json_decode($goal->old_values, true)['target_output'] === null
    && json_decode($goal->new_values, true)['reason'] === 'Approved revised plan', 'Goal audit snapshots incomplete.');
$evidence[] = ['case' => 'void history, replacement and audited goal', 'status' => 'passed'];

// A real audit INSERT failure must roll back entry, projections, version and totals.
DB::unprepared("CREATE FUNCTION public.reject_synthetic_harvest_audit() RETURNS trigger LANGUAGE plpgsql AS \$f\$
    BEGIN RAISE EXCEPTION 'Synthetic audit failure' USING ERRCODE='23514'; END; \$f\$;
    CREATE TRIGGER trg_reject_synthetic_harvest_audit BEFORE INSERT ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION public.reject_synthetic_harvest_audit();");
$beforeRevision = app(ProductionMonitoringService::class)->revision(harvestRecord());
$beforeCount = $row->harvestEntries()->count();
try {
    $service->saveHarvest(harvestRecord(), $row->id, harvestRequest(harvestPayload(['harvest_date' => '2026-03-04', 'quantity' => '9'])));
    throw new RuntimeException('Expected audit failure.');
} catch (ValidationException $exception) { assertHarvest($exception->status === 422, 'Unexpected audit failure response.'); }
DB::unprepared('DROP TRIGGER trg_reject_synthetic_harvest_audit ON audit_logs; DROP FUNCTION public.reject_synthetic_harvest_audit()');
assertHarvest($beforeRevision === app(ProductionMonitoringService::class)->revision(harvestRecord())
    && $beforeCount === $row->harvestEntries()->count() && $row->fresh()->actual_output === '0.60', 'Audit failure left partial changes.');
$evidence[] = ['case' => 'native audit failure rollback', 'status' => 'passed'];

DB::table('projects')->where('id', harvestRecord()->project_id)->update(['status' => 'completed', 'closed_at' => now()]);
rejectedHarvestSql($insert, [$row->id, '2026-03-05', '1', $actor, $actor]);
rejectedHarvestSql('UPDATE monitoring_production SET target_output=50 WHERE id=?', [$row->id]);
$evidence[] = ['case' => 'finalized ancestry guards', 'status' => 'passed'];
file_put_contents($scratch->directory.'/daily-harvest-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Daily harvest disposable PostgreSQL checks passed: '.count($evidence).PHP_EOL;
