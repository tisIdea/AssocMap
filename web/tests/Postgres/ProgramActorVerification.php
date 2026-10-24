<?php

// Standalone disposable PostgreSQL verification; never loads the application's .env.
use App\Models\Association;
use App\Models\ProgramActivityRecord;
use App\Models\Project;
use App\Models\Training;
use App\Models\UserProfile;
use App\Services\FieldOfficerAssignmentService;
use App\Services\ProgramActivityService;
use App\Services\ProjectService;
use App\Services\TrainingService;
use App\Services\UserAccessRevocationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const OPERATOR = '10000000-0000-4000-8000-000000000001';
const OFFICER = '20000000-0000-4000-8000-000000000002';
const REPLACEMENT = '20000000-0000-4000-8000-000000000003';
const ADMIN_ACTOR = '10000000-0000-4000-8000-000000000004';
const ASSOCIATION = '30000000-0000-4000-8000-000000000001';
const PROJECT = '40000000-0000-4000-8000-000000000001';
const TRAINING = '50000000-0000-4000-8000-000000000001';
const ACTIVITY = '60000000-0000-4000-8000-000000000001';
const PORT = 55442;

function connect(string $database): PDO
{
    if ($database !== 'postgres' && ! preg_match('/\Aassocmap_actor_[a-f0-9]{12}\z/D', $database)) {
        throw new RuntimeException('Disposable database name required.');
    }
    $password = getenv('ASSOCMAP_ACTOR_PASSWORD');
    $directory = getenv('ASSOCMAP_ACTOR_DIRECTORY');
    if (! is_string($password) || strlen($password) < 24 || ! is_string($directory)) {
        throw new RuntimeException('Disposable credentials and data-directory identity required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.PORT.';dbname='.$database.';sslmode=disable;connect_timeout=5',
        'assocmap_actor_verify', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = $pdo->query("SELECT current_database() db, current_user actor, current_setting('server_version') version,
        current_setting('data_directory') directory, host(inet_server_addr()) host, inet_server_port() port")->fetch(PDO::FETCH_ASSOC);
    if ($row['db'] !== $database || $row['actor'] !== 'assocmap_actor_verify' || $row['host'] !== '127.0.0.1'
        || (int) $row['port'] !== PORT || ! str_starts_with($row['version'], '17.11')
        || strtolower(str_replace('\\', '/', $row['directory'])) !== strtolower(str_replace('\\', '/', $directory))) {
        throw new RuntimeException('Disposable PostgreSQL identity mismatch.');
    }
    $pdo->exec("SET lock_timeout='8s'; SET statement_timeout='15s'; SET timezone='UTC'");

    return $pdo;
}

function application(string $database): void
{
    connect($database);
    putenv('APP_ENV=testing');
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(dirname(getenv('ASSOCMAP_ACTOR_DIRECTORY')).'/no-environment')->loadEnvironmentFrom('.env.testing');
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached configuration is prohibited.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($database) {
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => PORT, 'database' => $database,
                'username' => 'assocmap_actor_verify', 'password' => getenv('ASSOCMAP_ACTOR_PASSWORD'),
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
            ]], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'sync', 'logging.default' => 'null', 'associations.geography_schema_ready' => false,
            'associations.direct_geography' => false, 'services.supabase.invitation_restart_enabled' => false,
            'services.supabase.account_reactivation_enabled' => false, 'services.supabase.account_email_change_enabled' => false]);
    });
    $app->extend(Factory::class, fn ($http) => $http->preventStrayRequests());
    $app->make(Kernel::class)->bootstrap();
    set_exception_handler(static function (Throwable $exception): never {
        fwrite(STDERR, 'FAILED: '.get_class($exception).' '.$exception->getMessage().PHP_EOL);
        exit(1);
    });
    if (DB::connection()->getPdo()->query('SELECT current_database()')->fetchColumn() !== $database) {
        throw new RuntimeException('Laravel target mismatch.');
    }
    DB::statement("SET lock_timeout='8s'");
    DB::statement("SET statement_timeout='15s'");
}

function actor(string $role = 'officer'): UserProfile
{
    return UserProfile::with('role')->findOrFail($role === 'admin' ? ADMIN_ACTOR : OFFICER);
}

function actorRequest(): Request
{
    $request = new Request;
    $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(OPERATOR));

    return $request;
}

function record(string $module): Project|Training|ProgramActivityRecord
{
    return match ($module) {
        'project' => Project::findOrFail(PROJECT), 'training' => Training::findOrFail(TRAINING),
        default => ProgramActivityRecord::findOrFail(ACTIVITY),
    };
}

function payload(string $module): array
{
    $code = match ($module) {
        'project' => 'FPL_LPD', 'training' => 'SP_LT', default => 'MAED_SI'
    };
    if ($module === 'activity') {
        $activity = DB::table('saad_activities')->whereNotIn('code', ['FPL_LPD', 'SP_LT', 'FPL_CST', 'MAED_ERT', 'PM_CB', 'PM_CD'])->value('id');
    } else {
        $activity = DB::table('saad_activities')->where('code', $code)->value('id');
    }

    return ['association_id' => ASSOCIATION, 'title' => 'Synthetic program mutation', 'program_year' => 2026,
        'saad_activity_id' => $activity] + match ($module) {
            'project' => ['fisheries_category_id' => DB::table('fisheries_project_categories')->value('id'),
                'implementation_start_date' => '2026-01-01', 'status' => 'ongoing'],
            'training' => ['start_date' => '2026-01-01', 'status' => 'scheduled'],
            default => ['activity_date' => '2026-01-01', 'status' => 'planned'],
        };
}

function mutate(string $module, string $operation, UserProfile $cached): void
{
    $service = app(match ($module) {
        'project' => ProjectService::class, 'training' => TrainingService::class, default => ProgramActivityService::class,
    });
    if ($operation === 'create') {
        $service->create(payload($module), $cached);
    } elseif ($operation === 'update') {
        $row = record($module);
        $service->update($row, payload($module) + ['expected_updated_at' => $row->updated_at?->format('Y-m-d\TH:i:s.uP')], $cached);
    } else {
        $service->$operation(record($module), $cached);
    }
}

function revoke(string $role): void
{
    app(UserAccessRevocationService::class)->revoke(actorRequest(), actor($role));
}

function transfer(): void
{
    app(FieldOfficerAssignmentService::class)->assign(actorRequest(), UserProfile::with('role')->findOrFail(REPLACEMENT), [
        'association_ids' => [ASSOCIATION], 'expected_officers' => [ASSOCIATION => OFFICER], 'confirm_reassignment' => '1',
    ]);
}

function check(bool $value, string $label): void
{
    if (! $value) {
        throw new RuntimeException('FAILED: '.$label);
    }
    $GLOBALS['checks'][] = $label;
}

function snapshot(PDO $pdo): array
{
    $result = [];
    foreach (['associations', 'projects', 'project_products', 'project_materials', 'trainings', 'training_participants',
        'program_activity_records', 'monitoring_records', 'members', 'association_accounts'] as $table) {
        $result[$table] = $pdo->query('SELECT row_to_json(t)::text FROM public.'.$table.' t ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
    $result['gis_locations'] = $pdo->query("SELECT ((to_jsonb(g)-'geom') || jsonb_build_object('ewkb',encode(extensions.ST_AsEWKB(geom),'hex')))::text
        FROM gis_locations g ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    return $result;
}

function fixtures(string $module, string $operation): void
{
    DB::statement('TRUNCATE associations CASCADE');
    DB::table('account_reactivations')->delete();
    DB::table('user_invitations')->delete();
    DB::table('audit_logs')->delete();
    DB::table('user_profiles')->update(['is_active' => true, 'auth_sessions_valid_after' => null]);
    DB::table('associations')->insert(['id' => ASSOCIATION, 'name' => 'Synthetic association',
        'area_id' => DB::table('areas')->value('id'), 'saad_phase2_join_date' => '2025-01-01',
        'status' => 'active', 'primary_field_officer_id' => OFFICER]);
    DB::table('projects')->insert(['id' => PROJECT, 'created_by' => OFFICER, 'updated_by' => OFFICER] + payload('project'));
    DB::table('trainings')->insert(['id' => TRAINING, 'created_by' => OFFICER, 'updated_by' => OFFICER] + payload('training'));
    DB::table('program_activity_records')->insert(['id' => ACTIVITY, 'created_by' => OFFICER, 'updated_by' => OFFICER] + payload('activity'));
    DB::table('monitoring_records')->insert(['project_id' => PROJECT, 'year' => 2026, 'quarter_id' => 1, 'monitored_by' => OFFICER,
        'monitoring_date' => '2026-01-01', 'project_status' => 'ongoing']);
    $otherProject = '40000000-0000-4000-8000-000000000002';
    DB::table('projects')->insert(['id' => $otherProject, 'created_by' => OFFICER] + payload('project'));
    foreach ([PROJECT, $otherProject] as $index => $project) {
        DB::insert("INSERT INTO gis_locations(id,project_id,location_type,location_name,geom,is_published,public_title,published_by,published_at,created_by,updated_by)
            VALUES(?,?,'project_point','Synthetic point',extensions.ST_SetSRID(extensions.ST_MakePoint(123,10),4326),true,'Approved point',?,now(),?,?)",
            ['70000000-0000-4000-8000-00000000000'.($index + 1), $project, OFFICER, OFFICER, OFFICER]);
    }
    DB::insert("INSERT INTO gis_locations(id,project_id,location_type,location_name,geom,created_by)
        VALUES('70000000-0000-4000-8000-000000000003',?,'project_boundary','Synthetic boundary',
        extensions.ST_GeomFromText('POLYGON((123 10,124 10,124 11,123 10))',4326),?)", [PROJECT, OFFICER]);
    if ($operation === 'unarchive') {
        record($module)->update(['archived_at' => now()]);
    }
}

function startWorker(string $database, string $job, string $module, string $operation, string $role, bool $pause = false): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $database, $job, $module, $operation, $role, $pause ? 'pause' : 'run'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($process)) {
        throw new RuntimeException('Worker creation failed.');
    }
    stream_set_timeout($pipes[1], 20);
    $ready = trim((string) fgets($pipes[1]));
    if (! preg_match('/\AREADY ([0-9]+)\z/', $ready, $matches)) {
        throw new RuntimeException('Worker failed before ready: '.$ready.' '.stream_get_contents($pipes[2]));
    }

    return ['process' => $process, 'pipes' => $pipes, 'pid' => (int) $matches[1]];
}

function release(array $worker): void
{
    fwrite($worker['pipes'][0], "GO\n");
    fflush($worker['pipes'][0]);
}

function finish(array $worker): string
{
    fclose($worker['pipes'][0]);
    $result = trim(stream_get_contents($worker['pipes'][1]));
    $error = trim(stream_get_contents($worker['pipes'][2]));
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    if ($exit !== 0 || $error !== '') {
        throw new RuntimeException('Worker failed: '.$result.' '.$error);
    }

    return $result;
}

function blocked(PDO $observer, array $worker, string $label): void
{
    $deadline = microtime(true) + 5;
    $query = $observer->prepare("SELECT wait_event_type='Lock' AND cardinality(pg_blocking_pids(pid))>0 FROM pg_stat_activity WHERE pid=?");
    do {
        $query->execute([$worker['pid']]);
        if ($query->fetchColumn() === true) {
            check(true, $label.' observed real PostgreSQL lock wait');

            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('FAILED: no lock contention observed for '.$label);
}

if (($argv[1] ?? '') === 'worker') {
    [, , $database, $job, $module, $operation, $role, $pause] = $argv;
    application($database);
    $cached = actor($role);
    echo 'READY '.DB::selectOne('SELECT pg_backend_pid() pid')->pid."\n";
    flush();
    fgets(STDIN);
    if ($pause === 'pause') {
        $paused = false;
        DB::listen(function ($query) use (&$paused) {
            if (! $paused && str_contains($query->sql, '"associations"') && str_contains($query->sql, 'for update')) {
                $paused = true;
                echo "LOCKED\n";
                flush();
                fgets(STDIN);
            }
        });
    }
    try {
        match ($job) {
            'revoke' => revoke($role), 'transfer' => transfer(), default => mutate($module, $operation, $cached)
        };
        echo "ok\n";
    } catch (Throwable $exception) {
        $denied = $exception instanceof ValidationException
            || $exception instanceof HttpException;
        echo $denied ? "denied\n" : 'error '.get_class($exception).' '.$exception->getMessage()."\n";
    }
    exit;
}

$modules = match ($argv[1] ?? 'all') {
    'all' => ['project', 'activity', 'training'],
    'training' => ['training'],
    default => throw new RuntimeException('Unknown verification scope.'),
};
$GLOBALS['checks'] = [];
$database = 'assocmap_actor_'.bin2hex(random_bytes(6));
$applicationReady = false;
$admin = connect('postgres');
$admin->exec('CREATE DATABASE '.$database);
try {
    $setup = connect($database);
    foreach (['anon', 'authenticated'] as $role) {
        if (! $setup->query("SELECT 1 FROM pg_roles WHERE rolname='$role'")->fetchColumn()) {
            $setup->exec('CREATE ROLE '.$role);
        }
    }
    $setup->exec('CREATE SCHEMA auth; CREATE TABLE auth.users(id uuid PRIMARY KEY,email text)');
    $root = dirname(__DIR__, 3).'/supabase/migrations/';
    foreach (['001_assocmap_schema.sql', '002_add_locality_type_to_municipalities.sql', '003_add_monitoring_visit_reports.sql',
        '005_add_monitoring_monthly_production_and_conductors.sql', '006_create_user_invitations.sql',
        '011_add_permanent_project_closure.sql', '013_add_permanent_project_finalization.sql',
        '014_protect_finalized_project_history.sql', '020_add_account_reactivation.sql'] as $migration) {
        $setup->exec(file_get_contents($root.$migration));
    }
    application($database);
    $applicationReady = true;
    foreach ([OPERATOR => 'system_admin', ADMIN_ACTOR => 'system_admin', OFFICER => 'field_officer', REPLACEMENT => 'field_officer'] as $id => $role) {
        DB::table('auth.users')->insert(['id' => $id, 'email' => substr($id, -4).'@example.test']);
        DB::table('user_profiles')->insert(['id' => $id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'),
            'display_name' => 'Synthetic actor', 'is_active' => true, 'activated_at' => now()->subDay()]);
    }
    $municipality = DB::table('municipalities')->insertGetId(['name' => 'Synthetic municipality'], 'id');
    $barangay = DB::table('barangays')->insertGetId(['name' => 'Synthetic barangay', 'municipality_id' => $municipality], 'id');
    DB::table('areas')->insert(['barangay_id' => $barangay, 'address' => 'Synthetic area']);
    $scenarios = 0;
    foreach ($modules as $module) {
        foreach (['create', 'update', 'archive', 'unarchive'] as $operation) {
            foreach (['revoke:officer', 'revoke:admin', 'transfer:officer', 'cutoff:officer', 'cutoff:admin'] as $case) {
                [$job, $role] = explode(':', $case);
                fixtures($module, $operation);
                $worker = startWorker($database, 'mutation', $module, $operation, $role);
                DB::beginTransaction();
                match ($job) {
                    'revoke' => revoke($role),
                    'transfer' => transfer(),
                    default => DB::table('user_profiles')->where('id', actor($role)->id)->update(['auth_sessions_valid_after' => now()]),
                };
                $expected = snapshot(DB::connection()->getPdo());
                release($worker);
                $label = $module.' '.$operation.' '.$case.' first';
                blocked($setup, $worker, $label);
                DB::commit();
                check(finish($worker) === 'denied', $label.' denies stale write');
                check(snapshot($setup) === $expected, $label.' preserves all business/GIS rows');
                $scenarios++;
            }
            foreach (['revoke', 'transfer'] as $job) {
                fixtures($module, $operation);
                $before = snapshot($setup);
                $writer = startWorker($database, 'mutation', $module, $operation, 'officer', true);
                release($writer);
                check(trim((string) fgets($writer['pipes'][1])) === 'LOCKED', $module.' '.$operation.' locks actor and association');
                $other = startWorker($database, $job, $module, $operation, 'officer');
                release($other);
                $label = $module.' '.$operation.' write before '.$job;
                blocked($setup, $other, $label);
                release($writer);
                check(finish($writer) === 'ok' && finish($other) === 'ok', $label.' serializes both operations');
                $after = snapshot($setup);
                check($after['gis_locations'] === $before['gis_locations'] && $after['monitoring_records'] === $before['monitoring_records'],
                    $label.' preserves native EWKB/publication/provenance and monitoring history');
                check($job === 'revoke' ? ! UserProfile::findOrFail(OFFICER)->is_active
                    : Association::findOrFail(ASSOCIATION)->primary_field_officer_id === REPLACEMENT, $label.' applies final security state');
                $scenarios++;
            }
            echo $module.' '.$operation.": 7 native concurrency scenarios passed\n";
        }
    }
    echo json_encode(['postgres' => $setup->query('SHOW server_version')->fetchColumn(), 'checks' => count($GLOBALS['checks']),
        'concurrency_scenarios' => $scenarios, 'failed' => 0], JSON_PRETTY_PRINT)."\n";
} finally {
    if ($applicationReady) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::disconnect();
    }
    $setup = null;
    $admin->exec('DROP DATABASE '.$database.' WITH (FORCE)');
}
