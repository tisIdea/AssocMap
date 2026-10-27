<?php

// Standalone native checks against a task-owned loopback cluster; never loads .env.
use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\Monitoring\DailyHarvestMonitoringService;
use App\Services\Monitoring\MonitoringService;
use App\Services\Monitoring\ProductionMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DailyHarvestFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function nativeConnection(string $database, string $name = 'sec1_supervisor'): PDO
{
    if ($database !== 'postgres' && ! preg_match('/\Aassocmap_sec1_[a-f0-9]{12}\z/D', $database)) {
        throw new RuntimeException('A disposable SEC-1 database is required.');
    }
    $directory = getenv('ASSOCMAP_SEC1_DIRECTORY');
    $password = getenv('ASSOCMAP_SEC1_PASSWORD');
    if (! is_string($directory) || ! is_string($password) || strlen($password) < 24) {
        throw new RuntimeException('Task-owned cluster identity and credentials are required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port=55443;dbname='.$database.';sslmode=disable;connect_timeout=5',
        'assocmap_sec1_verify', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() db, current_user actor, current_setting('data_directory') directory,
        host(inet_server_addr()) host, inet_server_port() port")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $database || $identity['actor'] !== 'assocmap_sec1_verify'
        || $identity['host'] !== '127.0.0.1' || (int) $identity['port'] !== 55443
        || strcasecmp(str_replace('\\', '/', $identity['directory']), str_replace('\\', '/', $directory)) !== 0) {
        throw new RuntimeException('Disposable PostgreSQL identity mismatch.');
    }
    $pdo->exec("SET lock_timeout='8s'; SET statement_timeout='15s'; SET timezone='UTC'");
    $pdo->prepare("SELECT set_config('application_name', ?, false)")->execute([$name]);

    return $pdo;
}

function nativeApplication(string $database, string $name): void
{
    nativeConnection($database, $name);
    putenv('APP_ENV=testing');
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(dirname(getenv('ASSOCMAP_SEC1_DIRECTORY')).'/no-environment')->loadEnvironmentFrom('.env.testing');
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached application configuration is prohibited.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($database) {
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55443, 'database' => $database,
                'username' => 'assocmap_sec1_verify', 'password' => getenv('ASSOCMAP_SEC1_PASSWORD'),
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
            ]], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'sync', 'logging.default' => 'null']);
    });
    $app->extend(Factory::class, fn ($http) => $http->preventStrayRequests());
    $app->make(Kernel::class)->bootstrap();
    DB::statement("SET lock_timeout='8s'");
    DB::statement("SET statement_timeout='15s'");
    DB::select("SELECT set_config('application_name', ?, false)", [$name]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'Asia/Manila'));
    set_exception_handler(static function (Throwable $exception): never {
        fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
        exit(1);
    });
}

function nativeFixture(): object
{
    return new class
    {
        use DailyHarvestFixture;

        public function install(): void
        {
            $this->harvestSchema();
            Schema::table('user_profiles', function ($table) {
                $table->timestampTz('auth_sessions_valid_after')->nullable();
                $table->uuid('email_change_hold_id')->nullable();
            });
        }

        public function reset(): void
        {
            foreach (['roles', 'user_profiles', 'associations', 'association_accounts', 'projects', 'project_products',
                'monitoring_records', 'monitoring_production', 'quarters', 'monitoring_harvest_entries',
                'monitoring_production_monthly', 'audit_logs'] as $table) {
                DB::table($table)->delete();
            }
            $this->harvestFixtures();
            DB::table('monitoring_production')->where('id', self::PRODUCTION)->update(['quantity_source' => 'daily_harvest']);
            DB::table('monitoring_harvest_entries')->insert(['id' => self::PRODUCTION, 'monitoring_production_id' => self::PRODUCTION,
                'harvest_date' => '2026-01-01', 'quantity' => '5.00', 'created_by' => self::ADMIN, 'updated_by' => self::ADMIN]);
        }

        public function actor(string $role): UserProfile
        {
            $actor = UserProfile::with('role')->findOrFail($role === 'admin' ? self::ADMIN : self::OFFICER);
            if (! $actor->canAccessApplication()) {
                throw new RuntimeException('The authenticated snapshot must initially be eligible.');
            }

            return $actor;
        }

        public function change(PDO $pdo, string $role, string $state): void
        {
            $id = $role === 'admin' ? self::ADMIN : self::OFFICER;
            [$sql, $bindings] = match ($state) {
                'revoked' => ['UPDATE user_profiles SET is_active=false WHERE id=?', [$id]],
                'inactive_role' => ['UPDATE roles SET is_active=false WHERE id=?', [$role === 'admin' ? 1 : 2]],
                'changed_role' => ['UPDATE user_profiles SET role_id=? WHERE id=?', [$role === 'admin' ? 2 : 1, $id]],
                'cutoff' => ['UPDATE user_profiles SET auth_sessions_valid_after=now() WHERE id=?', [$id]],
                'email_hold' => ['UPDATE user_profiles SET email_change_hold_id=? WHERE id=?', [self::ACCOUNT, $id]],
                'reassigned' => ['UPDATE associations SET primary_field_officer_id=null WHERE id=?', [self::ASSOCIATION]],
                'archived' => ['UPDATE projects SET archived_at=now() WHERE id=?', [self::PROJECT]],
                'completed', 'terminated' => ['UPDATE projects SET status=? WHERE id=?', [$state, self::PROJECT]],
            };
            $pdo->prepare($sql)->execute($bindings);
        }

        public function mutate(string $operation, UserProfile $actor): void
        {
            $record = MonitoringRecord::findOrFail(self::RECORD);
            if ($operation === 'overview') {
                app(MonitoringService::class)->updateOverview($record, ['monitored_by' => self::OFFICER, 'site_validated' => false], $actor);
            } else {
                $request = Request::create('/synthetic-harvest', 'POST', ['harvest_date' => '2026-02-01', 'quantity' => '2.25',
                    'production_revision' => app(ProductionMonitoringService::class)->revision($record)]);
                $request->attributes->set('current_user', $actor);
                app(DailyHarvestMonitoringService::class)->saveHarvest($record, self::PRODUCTION, $request);
            }
        }

        public function snapshot(): array
        {
            $result = [];
            foreach (['monitoring_records', 'monitoring_visit_reports', 'monitoring_production', 'monitoring_production_monthly',
                'monitoring_income', 'monitoring_materials', 'monitoring_harvest_entries', 'audit_logs', 'project_products', 'project_materials'] as $table) {
                $result[$table] = DB::table($table)->orderBy('id')->get()->all();
            }

            return $result;
        }
    };
}

function signal(string $value): void
{
    echo $value.PHP_EOL;
    flush();
}

function receive(array $worker): string
{
    $line = fgets($worker['pipes'][1]);
    if ($line === false) {
        throw new RuntimeException('Worker failed: '.stream_get_contents($worker['pipes'][2]));
    }

    return trim($line);
}

function launch(string $database, string $job, string $operation, string $role, string $state): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $database, $job, $operation, $role, $state],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start native worker.');
    }
    stream_set_timeout($pipes[1], 20);

    return ['process' => $process, 'pipes' => $pipes];
}

function finish(array $worker): void
{
    foreach ($worker['pipes'] as $pipe) {
        fclose($pipe);
    }
    if (proc_close($worker['process']) !== 0) {
        throw new RuntimeException('Native worker exited unsuccessfully.');
    }
}

function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAILED: '.$label);
    }
    $GLOBALS['checks']++;
}

function waitForLock(PDO $pdo, string $job): void
{
    $query = $pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'");
    $deadline = microtime(true) + 5;
    do {
        $query->execute([$job]);
        if ((int) $query->fetchColumn() > 0) {
            check(true, $job.' waits for the conflicting row lock');

            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Expected a blocking row lock for '.$job);
}

$GLOBALS['checks'] = 0;
if (($argv[1] ?? '') === 'worker') {
    [, , $database, $job, $operation, $role, $state] = $argv;
    nativeApplication($database, $job);
    $fixture = nativeFixture();
    if ($job === 'sec1_lifecycle') {
        signal('STARTED');
        $fixture->change(nativeConnection($database, $job), $role, $state);
        signal('APPLIED');
        exit;
    }
    $actor = $fixture->actor($role);
    signal('READY');
    fgets(STDIN);
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (preg_match('/from "(roles|user_profiles|associations|projects|monitoring_records)".*for (?:share|update)$/', $query->sql, $match)) {
            $locks[] = $match[1];
        }
    });
    try {
        DB::transaction(function () use ($fixture, $operation, $actor, $job, &$locks) {
            $fixture->mutate($operation, $actor);
            if ($job === 'sec1_mutation_first') {
                check($locks === ['roles', 'user_profiles', 'associations', 'projects', 'monitoring_records'], 'native lock order');
                signal('LOCKED');
                fgets(STDIN);
            }
        });
        signal('SAVED');
    } catch (HttpException|ValidationException $exception) {
        signal('DENIED:'.($exception instanceof HttpException ? $exception->getStatusCode() : $exception->status));
    }
    exit;
}

$GLOBALS['checks'] = 0;
$database = 'assocmap_sec1_'.bin2hex(random_bytes(6));
$supervisor = nativeConnection('postgres');
$supervisor->exec('CREATE DATABASE '.$database);
$workers = [];
try {
    nativeApplication($database, 'sec1_supervisor');
    $fixture = nativeFixture();
    $fixture->install();
    $control = nativeConnection($database);
    $scenarios = 0;
    foreach (['overview', 'harvest'] as $operation) {
        foreach (['admin', 'officer'] as $role) {
            foreach (['revoked', 'inactive_role', 'changed_role', 'cutoff', 'email_hold', 'reassigned', 'archived', 'completed', 'terminated'] as $state) {
                if ($role === 'admin' && $state === 'reassigned') {
                    continue;
                }
                $fixture->reset();
                $before = $fixture->snapshot();
                $workers[] = $mutation = launch($database, 'sec1_lifecycle_first', $operation, $role, $state);
                check(receive($mutation) === 'READY', 'authentication precedes lifecycle change');
                $control->beginTransaction();
                $fixture->change($control, $role, $state);
                fwrite($mutation['pipes'][0], "GO\n");
                waitForLock($control, 'sec1_lifecycle_first');
                $control->commit();
                check(receive($mutation) === 'DENIED:'.(in_array($state, ['reassigned', 'archived', 'completed', 'terminated'], true) ? 422 : 403), 'lifecycle wins and mutation is denied');
                finish($mutation);
                array_pop($workers);
                check($before == $fixture->snapshot(), 'denial preserves Monitoring, Production, Harvest and audit');
                $scenarios++;

                $fixture->reset();
                $before = $fixture->snapshot();
                $workers[] = $mutation = launch($database, 'sec1_mutation_first', $operation, $role, $state);
                check(receive($mutation) === 'READY', 'mutation actor initially eligible');
                fwrite($mutation['pipes'][0], "GO\n");
                check(receive($mutation) === 'LOCKED', 'mutation holds locks in the expected order');
                $workers[] = $lifecycle = launch($database, 'sec1_lifecycle', $operation, $role, $state);
                check(receive($lifecycle) === 'STARTED', 'conflicting lifecycle transaction started');
                waitForLock($control, 'sec1_lifecycle');
                fwrite($mutation['pipes'][0], "COMMIT\n");
                check(receive($mutation) === 'SAVED', 'authorized mutation commits first');
                check(receive($lifecycle) === 'APPLIED', 'lifecycle proceeds after mutation commit');
                finish($lifecycle);
                array_pop($workers);
                finish($mutation);
                array_pop($workers);
                check($before != $fixture->snapshot(), 'authorized mutation persisted');
                $scenarios++;
            }
        }
    }
    signal(json_encode(['scenarios' => $scenarios, 'checks' => $GLOBALS['checks'], 'result' => 'PASS'], JSON_THROW_ON_ERROR));
} finally {
    if (isset($control) && $control->inTransaction()) {
        $control->rollBack();
    }
    foreach ($workers as $worker) {
        proc_terminate($worker['process']);
        foreach ($worker['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($worker['process']);
    }
    DB::purge();
    $control = null;
    $supervisor->exec('DROP DATABASE '.$database.' WITH (FORCE)');
}
