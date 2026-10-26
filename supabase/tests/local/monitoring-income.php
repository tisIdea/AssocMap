<?php

declare(strict_types=1);

require __DIR__.'/bootstrap-application.php';

use App\Exceptions\MonitoringIncomeConflictException;
use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\Monitoring\IncomeMonitoringService;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$scratch = new ScratchDatabase($argv[1] ?? '');
$app = scratchApplication($scratch);

final class IncomeScratchFixture
{
    use \Tests\Support\MonitoringIncomeFixture;
    public function seed(): void { $this->schema(); $this->fixtures(); }
}

function assertIncome(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function incomeRecord(): MonitoringRecord { return MonitoringRecord::firstOrFail(); }
function incomeActor(string $role): UserProfile { return UserProfile::with('role')->whereHas('role', fn ($query) => $query->where('code', $role))->firstOrFail(); }
function incomePayload(array $entries = [], array $removed = []): array
{
    $record = incomeRecord(); $production = $record->production()->firstOrFail();
    return ['income_revision' => app(IncomeMonitoringService::class)->revision($record),
        'production' => [['id' => $production->id, 'project_product_id' => $production->project_product_id,
            'income' => $entries, 'deleted_income_ids' => $removed]]];
}
function incomeFacts(): array
{
    return DB::table('monitoring_income')->orderBy('month')->get(['id', 'period_type', 'month', 'gross_income', 'remarks'])
        ->map(fn ($row) => (array) $row)->all();
}

if (($argv[2] ?? '') === 'worker') {
    $name = $argv[3] ?? '';
    if (! in_array($name, ['addition', 'removal'], true)) throw new RuntimeException('Unknown scratch worker.');
    $payload = json_decode(file_get_contents($scratch->directory.'/income-'.$name.'-payload.json'), true, 512, JSON_THROW_ON_ERROR);
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    file_put_contents($scratch->directory.'/income-'.$name.'-ready.json', json_encode(['pid' => (int) $pid], JSON_THROW_ON_ERROR));
    try {
        app(MonitoringService::class)->updateSection(incomeRecord(), 'income', $payload, incomeActor('field_officer'));
        $result = ['status' => 200];
    } catch (MonitoringIncomeConflictException $exception) {
        $result = ['status' => $exception->getStatusCode()];
    }
    file_put_contents($scratch->directory.'/income-'.$name.'-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

// This runner creates only a new synthetic fixture, never resets an existing schema.
$tables = $scratch->pdo->query("SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")->fetchColumn();
assertIncome((int) $tables === 0, 'A fresh empty disposable database is required; no tables were changed.');
(new IncomeScratchFixture)->seed();
DB::statement("ALTER TABLE monitoring_income ADD CHECK (gross_income >= 0),
    ADD CHECK ((period_type='monthly' AND month BETWEEN 1 AND 12) OR (period_type='quarterly' AND month IS NULL))");
DB::statement("CREATE UNIQUE INDEX scratch_income_month ON monitoring_income(monitoring_production_id, month) WHERE period_type='monthly'");
DB::statement("CREATE UNIQUE INDEX scratch_income_quarter ON monitoring_income(monitoring_production_id) WHERE period_type='quarterly'");

$evidence = [];
$entry = static fn (int $month, string $amount): array => ['period_type' => 'monthly', 'month' => $month, 'gross_income' => $amount, 'remarks' => 'Synthetic PostgreSQL Income'];

foreach (['addition', 'removal'] as $case) {
    $before = incomeFacts();
    $stale = $case === 'addition' ? incomePayload([$entry(2, '200.20')]) : incomePayload([], [$before[0]['id']]);
    $winner = incomePayload([$entry($case === 'addition' ? 1 : 2, $case === 'addition' ? '100.10' : '200.20')]);
    file_put_contents($scratch->directory.'/income-'.$case.'-payload.json', json_encode($stale, JSON_THROW_ON_ERROR));
    DB::beginTransaction();
    $worker = null;
    try {
        app(MonitoringService::class)->updateSection(incomeRecord(), 'income', $winner, incomeActor('system_admin'));
        $expected = incomeFacts();
        $worker = new Process([PHP_BINARY, __FILE__, realpath($argv[1]), 'worker', $case], dirname(__DIR__, 3));
        $worker->setTimeout(20)->start();
        $deadline = microtime(true) + 10;
        $wait = null;
        do {
            $readyPath = $scratch->directory.'/income-'.$case.'-ready.json';
            if (is_file($readyPath)) {
                $ready = json_decode(file_get_contents($readyPath), true, 512, JSON_THROW_ON_ERROR);
                $query = $scratch->pdo->prepare('SELECT wait_event_type, wait_event FROM pg_catalog.pg_stat_activity WHERE pid=?');
                $query->execute([$ready['pid']]);
                $wait = $query->fetch(PDO::FETCH_ASSOC);
                if (($wait['wait_event_type'] ?? '') === 'Lock') break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && $worker->isRunning());
        assertIncome(($wait['wait_event_type'] ?? '') === 'Lock', 'The second real connection must be observed waiting on a lock.');
        DB::commit();
        assertIncome($worker->wait() === 0, 'Scratch worker failed: '.$worker->getErrorOutput());
        $result = json_decode(file_get_contents($scratch->directory.'/income-'.$case.'-result.json'), true, 512, JSON_THROW_ON_ERROR);
        assertIncome($result['status'] === 409, 'The waiting stale writer must return conflict.');
        assertIncome(incomeFacts() === $expected, 'A stale writer changed saved Income facts.');
        $evidence[] = ['case' => $case, 'second_connection_wait' => $wait, 'stale_status' => 409,
            'rows' => count($expected), 'income_version' => incomeRecord()->income_version,
            'gross_income' => $scratch->pdo->query('SELECT SUM(gross_income)::text FROM monitoring_income')->fetchColumn()];
    } finally {
        if (DB::transactionLevel() > 0) DB::rollBack();
        if ($worker?->isRunning()) $worker->stop(1);
    }
}

$before = incomeFacts();
app(MonitoringService::class)->updateSection(incomeRecord(), 'income', incomePayload([], [$before[0]['id']]), incomeActor('field_officer'));
assertIncome(count(incomeFacts()) === 1 && incomeFacts()[0]['gross_income'] === '200.20', 'A current authorized explicit deletion must succeed.');
$evidence[] = ['case' => 'current explicit deletion', 'income_version' => incomeRecord()->income_version, 'gross_income' => '200.20'];

$before = incomeFacts(); $version = incomeRecord()->income_version;
DB::beginTransaction();
try {
    DB::unprepared("CREATE FUNCTION pg_temp.reject_income_version() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic Income rollback'; END \$\$");
    DB::unprepared('CREATE TRIGGER scratch_reject_income_version BEFORE UPDATE OF income_version ON monitoring_records FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_income_version()');
    try {
        app(MonitoringService::class)->updateSection(incomeRecord(), 'income', incomePayload([$entry(3, '9.99')], [$before[0]['id']]), incomeActor('system_admin'));
        throw new RuntimeException('The native synthetic failure was not reached.');
    } catch (\Illuminate\Database\QueryException|\Illuminate\Validation\ValidationException) {
        assertIncome(incomeFacts() === $before && incomeRecord()->income_version === $version, 'Native rollback did not restore Income and its version.');
    }
} finally { DB::rollBack(); }
$evidence[] = ['case' => 'native failure rollback', 'unchanged' => true, 'income_version' => $version];

file_put_contents($scratch->directory.'/monitoring-income-results.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
echo 'PASS: two native PostgreSQL lock races, explicit deletion and rollback. No migration file was executed.'.PHP_EOL;
