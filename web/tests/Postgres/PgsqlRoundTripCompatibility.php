<?php

declare(strict_types=1);

// Standalone synthetic PostgreSQL checks; never loads application environment files.
use App\Models\MonitoringIncome;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Programs\ProgramActorService;
use App\Services\Reporting\ReportDataset;
use App\Services\Reporting\ReportScope;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class RoundTripValue extends Model
{
    protected $table = 'roundtrip_values';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'amount' => 'decimal:2', 'event_date' => 'date', 'payload' => 'array'];
    }
}

function verifyRoundTrip(bool $condition, string $label): void
{
    if (! $condition) throw new RuntimeException($label);
    $GLOBALS['checks'][] = $label;
}

function rejectedRoundTrip(callable $operation, string $state): void
{
    try {
        DB::transaction($operation);
    } catch (Throwable $error) {
        verifyRoundTrip((string) $error->getCode() === $state, 'Expected database rejection '.$state.'; observed '.$error->getCode());
        return;
    }
    throw new RuntimeException('Expected database rejection was absent.');
}

function deniedRoundTrip(callable $operation): void
{
    try { $operation(); }
    catch (HttpExceptionInterface $error) {
        verifyRoundTrip(in_array($error->getStatusCode(), [403, 404], true), 'Authorization denies access');
        return;
    }
    throw new RuntimeException('Expected authorization denial was absent.');
}

function roundTripApplication(array $connection, string $directory): void
{
    $app = new Application(dirname(__DIR__, 2));
    $app->useEnvironmentPath($directory.'/no-application-environment');
    $app->instance('config', new Illuminate\Config\Repository([
        'app' => ['env' => 'testing', 'timezone' => 'UTC'],
        'database' => ['default' => 'default', 'connections' => []],
        'associations' => ['geography_schema_ready' => false, 'direct_geography' => false],
        'reporting' => ['max_rows' => 1000, 'max_total_rows' => 5000],
    ]));
    $capsule = new Manager($app);
    $capsule->addConnection($connection);
    $events = new Illuminate\Events\Dispatcher($app);
    $app->instance('events', $events);
    $capsule->setEventDispatcher($events);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('log', new Psr\Log\NullLogger);
    $app->instance(Factory::class, (new Factory)->preventStrayRequests());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);
}

$admin = null;
$databaseCreated = false;
$GLOBALS['checks'] = [];
try {
    date_default_timezone_set('UTC');
    $manifest = realpath($argv[1] ?? '');
    $directory = $manifest ? dirname($manifest) : '';
    $temporary = realpath(sys_get_temp_dir());
    if (! $manifest || basename($manifest) !== 'run.json' || dirname($directory) !== $temporary
        || ! preg_match('/\Aassocmap-pg-roundtrips-[a-f0-9]{12}\z/D', basename($directory))) {
        throw new RuntimeException('A task-owned temporary cluster manifest is required.');
    }
    $run = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($manifest)), true, 16, JSON_THROW_ON_ERROR);
    if (($run['Host'] ?? '') !== '127.0.0.1' || ! is_int($run['Port'] ?? null) || $run['Port'] < 1024 || $run['Port'] > 65535
        || ($run['User'] ?? '') !== 'assocmap_roundtrip_verify' || ($run['Database'] ?? '') !== 'assocmap_roundtrip_verify'
        || realpath($run['Data'] ?? '') !== $directory.DIRECTORY_SEPARATOR.'data'
        || realpath($run['PasswordFile'] ?? '') !== $directory.DIRECTORY_SEPARATOR.'password.txt'
        || (int) file($directory.'/data/postmaster.pid')[0] !== ($run['Pid'] ?? 0)) {
        throw new RuntimeException('Disposable cluster manifest identity mismatch.');
    }
    $password = trim(file_get_contents($run['PasswordFile']));
    $base = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $run['Port'],
        'database' => $run['Database'], 'username' => $run['User'], 'password' => $password,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC'];
    $adminConfig = $base;
    $adminConfig['database'] = 'postgres';
    $admin = (new Illuminate\Database\Connectors\PostgresConnector)->connect($adminConfig);
    $identity = $admin->query("SELECT current_user AS actor, host(inet_server_addr()) AS host,
        inet_server_port() AS port, current_setting('data_directory') AS directory")->fetch(PDO::FETCH_ASSOC);
    if ($identity['actor'] !== $run['User'] || $identity['host'] !== '127.0.0.1' || (int) $identity['port'] !== $run['Port']
        || realpath($identity['directory']) !== realpath($run['Data'])) {
        throw new RuntimeException('Connected server is not the task-owned loopback cluster.');
    }
    if ($admin->query("SELECT 1 FROM pg_database WHERE datname='assocmap_roundtrip_verify'")->fetchColumn()) {
        throw new RuntimeException('The synthetic verification database must not already exist.');
    }
    $admin->exec('CREATE DATABASE assocmap_roundtrip_verify');
    $databaseCreated = true;
    roundTripApplication($base, $directory);
    foreach (['anon', 'authenticated'] as $role) {
        if (! DB::selectOne('SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname=?) AS present', [$role])->present) {
            DB::unprepared($role === 'anon' ? 'CREATE ROLE anon' : 'CREATE ROLE authenticated');
        }
    }
    DB::unprepared('CREATE SCHEMA auth; CREATE TABLE auth.users(id uuid PRIMARY KEY, email text)');
    foreach (['001_assocmap_schema.sql', '002_add_locality_type_to_municipalities.sql', '003_add_monitoring_visit_reports.sql',
        '005_add_monitoring_monthly_production_and_conductors.sql', '006_create_user_invitations.sql',
        '011_add_permanent_project_closure.sql', '012_allow_zero_production_activities.sql',
        '013_add_permanent_project_finalization.sql', '014_protect_finalized_project_history.sql', '020_add_account_reactivation.sql'] as $migration) {
        DB::unprepared(file_get_contents(dirname(__DIR__, 3).'/supabase/migrations/'.$migration));
    }
    DB::unprepared("CREATE TABLE roundtrip_values(id uuid PRIMARY KEY, label text NOT NULL, nullable_value text,
        enabled boolean NOT NULL, event_date date NOT NULL, amount numeric(14,2) NOT NULL, payload jsonb NOT NULL);
        ALTER TABLE audit_logs ADD CONSTRAINT roundtrip_rejected_audit CHECK(action_type <> 'ROUNDTRIP REJECT')");
    $lockId = '00000000-0000-4000-8000-000000000001';
    DB::table('roundtrip_values')->insert(['id' => $lockId, 'label' => 'lock', 'enabled' => true,
        'event_date' => '2026-10-26', 'amount' => '0.00', 'payload' => '{}']);
    DB::disconnect();
    $results = [];
    foreach (['A' => false, 'B' => true] as $mode => $disablePrepares) {
        $before = count($GLOBALS['checks']);
        $connection = $base;
        $connection['options'] = [PDO::ATTR_EMULATE_PREPARES => false, PDO::PGSQL_ATTR_DISABLE_PREPARES => $disablePrepares];
        roundTripApplication($connection, $directory);
        $pdo = DB::connection()->getPdo();
        verifyRoundTrip($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false, 'Emulated prepares remain disabled');
        verifyRoundTrip($pdo->getAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES) === $disablePrepares, 'Requested driver mode is active');
        $statement = $pdo->prepare('SELECT CAST(:id AS uuid) AS id, CAST(:flag AS boolean) AS flag, CAST(:empty AS text) AS empty');
        $statement->bindValue(':id', $lockId, PDO::PARAM_STR);
        $statement->bindValue(':flag', false, PDO::PARAM_BOOL);
        $statement->bindValue(':empty', null, PDO::PARAM_NULL);
        $statement->execute();
        $bound = $statement->fetch(PDO::FETCH_ASSOC);
        verifyRoundTrip($bound === ['id' => $lockId, 'flag' => false, 'empty' => null], 'Native named UUID, boolean and null binding');
        unset($statement);
        $second = (new Illuminate\Database\Connectors\PostgresConnector)->connect($connection);
        DB::beginTransaction();
        DB::table('roundtrip_values')->where('id', $lockId)->lockForUpdate()->first();
        $second->beginTransaction();
        $second->exec("SET LOCAL lock_timeout='150ms'");
        try {
            $statement = $second->prepare('SELECT id FROM roundtrip_values WHERE id=? FOR UPDATE');
            $statement->execute([$lockId]);
            throw new RuntimeException('Competing row lock unexpectedly succeeded.');
        } catch (PDOException $error) {
            verifyRoundTrip($error->getCode() === '55P03', 'Competing connection observes the row lock');
        } finally { $second->rollBack(); unset($statement); DB::rollBack(); }
        $second->beginTransaction();
        $statement = $second->prepare('SELECT id FROM roundtrip_values WHERE id=? FOR UPDATE');
        $statement->execute([$lockId]);
        verifyRoundTrip($statement->fetchColumn() === $lockId, 'Rollback releases the row lock');
        $second->rollBack();
        DB::transaction(fn () => DB::table('roundtrip_values')->where('id', $lockId)->update(['label' => 'committed']));
        $statement = $second->prepare('SELECT label FROM roundtrip_values WHERE id=?');
        $statement->execute([$lockId]);
        verifyRoundTrip($statement->fetchColumn() === 'committed', 'Commit is visible to another connection');
        DB::beginTransaction();
        DB::table('roundtrip_values')->where('id', $lockId)->update(['label' => 'rolled back']);
        DB::rollBack();
        $statement->execute([$lockId]);
        verifyRoundTrip($statement->fetchColumn() === 'committed', 'Rollback preserves the committed value');
        unset($statement, $second);
        DB::beginTransaction();
        try {
            $literal = "Synthetic '; SELECT false; -- ? and Unicode \u{00F1}";
            $typed = RoundTripValue::create(['id' => '00000000-0000-4000-8000-000000000002', 'label' => $literal,
                'nullable_value' => null, 'enabled' => false, 'event_date' => '2026-10-26', 'amount' => '1234567890.12',
                'payload' => ['nested' => ['enabled' => true, 'empty' => null], 'label' => $literal]]);
            $typed->refresh();
            verifyRoundTrip($typed->label === $literal && RoundTripValue::where('label', $literal)->count() === 1, 'SQL-like text remains literal data');
            verifyRoundTrip($typed->enabled === false && $typed->nullable_value === null, 'Eloquent boolean and nullable casts');
            verifyRoundTrip($typed->event_date->format('Y-m-d') === '2026-10-26' && $typed->amount === '1234567890.12', 'Date and decimal precision');
            verifyRoundTrip($typed->payload['nested'] === ['empty' => null, 'enabled' => true] || $typed->payload['nested'] === ['enabled' => true, 'empty' => null], 'Nested JSON values');
            verifyRoundTrip(DB::table('roundtrip_values')->whereJsonContains('payload->nested', ['enabled' => true])->count() === 1, 'Query Builder JSON binding');
            DB::beginTransaction();
            $typed->update(['enabled' => true, 'nullable_value' => 'changed']);
            DB::rollBack();
            verifyRoundTrip($typed->fresh()->enabled === false && $typed->fresh()->nullable_value === null, 'Nested transaction savepoint rollback');
            DB::transaction(fn () => DB::table('roundtrip_values')->where('id', $typed->id)->update(['amount' => '0.10']));
            verifyRoundTrip($typed->fresh()->amount === '0.10', 'Nested transaction commit');
            rejectedRoundTrip(fn () => DB::selectOne('SELECT CAST(? AS uuid)', ['invalid synthetic UUID']), '22P02');
            $ids = ['admin' => '10000000-0000-4000-8000-000000000001', 'officer' => '20000000-0000-4000-8000-000000000001',
                'other' => '20000000-0000-4000-8000-000000000002', 'account' => '30000000-0000-4000-8000-000000000001'];
            foreach ($ids as $key => $id) {
                $role = match ($key) {'admin' => 'system_admin', 'account' => 'association_account', default => 'field_officer'};
                DB::table('auth.users')->insert(['id' => $id, 'email' => $key.'@example.test']);
                DB::table('user_profiles')->insert(['id' => $id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'),
                    'display_name' => 'Synthetic '.$key, 'is_active' => true, 'activated_at' => now()->subDay()]);
            }
            $town = DB::table('municipalities')->insertGetId(['name' => 'Synthetic municipality'], 'id');
            $barangay = DB::table('barangays')->insertGetId(['name' => 'Synthetic barangay', 'municipality_id' => $town], 'id');
            $area = DB::table('areas')->insertGetId(['barangay_id' => $barangay, 'address' => 'Synthetic address'], 'id');
            $own = '40000000-0000-4000-8000-000000000001';
            $outside = '40000000-0000-4000-8000-000000000002';
            foreach ([$own => $ids['officer'], $outside => $ids['other']] as $id => $officer) {
                DB::table('associations')->insert(['id' => $id, 'name' => 'Synthetic '.$id, 'area_id' => $area,
                    'primary_field_officer_id' => $officer, 'saad_phase2_join_date' => '2026-01-01']);
            }
            DB::table('association_accounts')->insert(['user_id' => $ids['account'], 'association_id' => $own]);
            $officer = UserProfile::findWithRole($ids['officer'], useWritePdo: true);
            verifyRoundTrip($officer?->role?->code === 'field_officer' && $officer->canAccessApplication(), 'Joined profile, role and invitation eligibility');
            $request = new Request;
            $request->attributes->set('current_user', $officer);
            $scope = new ReportScope;
            verifyRoundTrip($scope->ids($scope->actor($request), []) === [$own], 'Officer association scope');
            deniedRoundTrip(fn () => $scope->selected($officer, ['association_id' => $outside]));
            $account = UserProfile::findWithRole($ids['account']);
            verifyRoundTrip($scope->ids($account, []) === [$own], 'Association account link scope');
            DB::table('association_accounts')->update(['is_active' => false]);
            deniedRoundTrip(fn () => $scope->associations(UserProfile::findWithRole($ids['account'])));
            DB::table('association_accounts')->update(['is_active' => true]);
            verifyRoundTrip((new ProgramActorService)->lock($officer)->id === $officer->id, 'Transactional actor lock accepts current state');
            DB::table('user_profiles')->where('id', $officer->id)->update(['auth_sessions_valid_after' => now()]);
            deniedRoundTrip(fn () => (new ProgramActorService)->lock($officer));
            DB::table('user_profiles')->where('id', $officer->id)->update(['auth_sessions_valid_after' => null, 'is_active' => false]);
            deniedRoundTrip(fn () => $scope->actor($request));
            DB::table('user_profiles')->where('id', $officer->id)->update(['is_active' => true]);
            DB::table('associations')->where('id', $own)->update(['primary_field_officer_id' => $ids['other']]);
            deniedRoundTrip(fn () => $scope->recheck($request, [], [$own]));
            DB::table('associations')->where('id', $own)->update(['primary_field_officer_id' => $ids['officer']]);
            $project = '50000000-0000-4000-8000-000000000001';
            DB::table('projects')->insert(['id' => $project, 'association_id' => $own, 'title' => 'Synthetic project',
                'program_year' => 2026, 'saad_activity_id' => DB::table('saad_activities')->where('code', 'FPL_LPD')->value('id'),
                'fisheries_category_id' => DB::table('fisheries_project_categories')->value('id'),
                'implementation_start_date' => '2026-01-01', 'status' => 'ongoing']);
            $product = DB::table('project_products')->insertGetId(['project_id' => $project, 'product_name' => 'Synthetic product'], 'id');
            $record = DB::table('monitoring_records')->insertGetId(['project_id' => $project, 'year' => 2026, 'quarter_id' => 1,
                'monitoring_date' => '2026-01-01', 'project_status' => 'ongoing'], 'id');
            $production = DB::table('monitoring_production')->insertGetId(['monitoring_record_id' => $record, 'project_product_id' => $product,
                'target_output' => '10.00', 'actual_output' => '1.00', 'unit' => 'kg'], 'id');
            $income = MonitoringIncome::create(['monitoring_production_id' => $production, 'period_type' => 'monthly', 'month' => 1,
                'gross_income' => '100.10', 'remarks' => null])->fresh();
            verifyRoundTrip($income->gross_income === '100.10' && $income->month === 1 && $income->remarks === null, 'Actual Monitoring model casts and persistence');
            rejectedRoundTrip(fn () => MonitoringIncome::create(['monitoring_production_id' => $production,
                'period_type' => 'monthly', 'month' => 13, 'gross_income' => '1.00']), 'P0001');
            rejectedRoundTrip(fn () => MonitoringIncome::create(['monitoring_production_id' => $production,
                'period_type' => 'monthly', 'month' => 2, 'gross_income' => '-1.00']), '23514');
            $dataset = (new ReportDataset($scope))->extract($officer, ['report_type' => 'projects', 'year' => 2026], [$own]);
            verifyRoundTrip(array_column($dataset['projects'], 'id') === [$project]
                && array_column($dataset['associations'], 'id') === [$own], 'Actual reporting dataset preserves association scope');
            $administrator = UserProfile::findWithRole($ids['admin']);
            $auditRequest = new Request;
            $auditRequest->attributes->set('current_user', $administrator);
            $audit = new AuditLogService;
            $auditId = $audit->record($auditRequest, 'ROUNDTRIP CHECK', 'Performance', 'association', $own,
                null, ['primary_field_officer_id' => $officer->id], ['primary_field_officer_id']);
            verifyRoundTrip(json_decode(DB::table('audit_logs')->where('id', $auditId)->value('new_values'), true)
                === ['primary_field_officer_id' => $officer->id], 'Actual audit JSON snapshot persistence');
            rejectedRoundTrip(function () use ($project, $audit, $auditRequest, $own): void {
                DB::table('projects')->where('id', $project)->update(['title' => 'Must roll back']);
                $audit->record($auditRequest, 'ROUNDTRIP REJECT', 'Performance', 'association', $own);
            }, '23514');
            verifyRoundTrip(DB::table('projects')->where('id', $project)->value('title') === 'Synthetic project', 'Audit failure rolls back business mutation');
            DB::beginTransaction();
            DB::table('projects')->where('id', $project)->update(['status' => 'completed', 'closed_at' => now(),
                'closed_by' => $administrator->id, 'closure_remarks' => 'Synthetic finalization']);
            rejectedRoundTrip(fn () => $income->update(['gross_income' => '200.20']), '23514');
            verifyRoundTrip($income->fresh()->gross_income === '100.10', 'Finalized Monitoring history remains unchanged');
            DB::rollBack();
        } finally { while (DB::transactionLevel() > 0) DB::rollBack(); }
        verifyRoundTrip(RoundTripValue::count() === 1 && DB::table('user_profiles')->count() === 0, 'Outer rollback preserves the clean fixture');
        $results[$mode] = count($GLOBALS['checks']) - $before;
        echo json_encode(['mode' => $mode, 'checks_passed' => $results[$mode]]).PHP_EOL;
        DB::disconnect();
        unset($pdo);
    }
    $evidence = ['passed' => count($GLOBALS['checks']), 'failed' => 0, 'by_mode' => $results,
        'postgres' => $admin->query('SHOW server_version')->fetchColumn(), 'isolation' => 'task-owned loopback cluster'];
    file_put_contents($directory.'/compatibility.json', json_encode($evidence + ['checks' => $GLOBALS['checks']], JSON_PRETTY_PRINT));
    echo json_encode($evidence).PHP_EOL;
} catch (Throwable $error) {
    $testFrames = array_values(array_filter($error->getTrace(), fn (array $frame) => ($frame['file'] ?? '') === __FILE__));
    echo json_encode(['failed' => true, 'exception' => get_class($error), 'code' => (string) $error->getCode(),
        'location' => basename($error->getFile()).':'.$error->getLine(),
        'test_line' => $testFrames[0]['line'] ?? null, 'last_passed_check' => end($GLOBALS['checks']) ?: null,
        'check' => get_class($error) === RuntimeException::class ? $error->getMessage() : 'Database details withheld']).PHP_EOL;
    $failed = true;
} finally {
    if (Facade::getFacadeApplication()) {
        try { while (DB::transactionLevel() > 0) DB::rollBack(); DB::disconnect(); } catch (Throwable) {}
    }
    unset($pdo, $second, $statement);
    if ($databaseCreated) $admin->exec('DROP DATABASE assocmap_roundtrip_verify WITH (FORCE)');
}
exit(isset($failed) ? 1 : 0);
