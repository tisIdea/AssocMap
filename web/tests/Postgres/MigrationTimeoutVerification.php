<?php

// Synthetic migration rehearsal; never loads .env or accepts a remote database.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const TIMEOUT_PORT = 55439;
const MIGRATION_NAMES = [
    '021' => '2026_10_22_010000_support_association_account_replacement',
    '022' => '2026_10_22_020000_support_verified_email_change',
];

$checks = [];
$timings = [];

function verifyTimeout(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAILED: '.$label);
    }
    $GLOBALS['checks'][] = $label;
}

function disposableConnection(string $database, string $role = 'postgres'): PDO
{
    $password = getenv('ASSOCMAP_PG17_TEST_PASSWORD');
    $root = getenv('ASSOCMAP_PG17_TEST_ROOT');
    if (! is_string($password) || strlen($password) < 24 || ! is_string($root)
        || ! preg_match('/\AAssocMap-Pg17-Final-[a-f0-9]{32}\z/D', basename(str_replace('\\', '/', $root)))
        || ($database !== 'postgres' && ! preg_match('/\Aassocmap_timeouts_[a-f0-9]{12}\z/D', $database))) {
        throw new RuntimeException('Verified disposable connection parameters required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.TIMEOUT_PORT.';dbname='.$database.';sslmode=disable;connect_timeout=5',
        $role, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() AS db,current_user AS actor,current_setting('server_version') AS version,
        host(inet_server_addr()) AS host,inet_server_port() AS port,current_setting('data_directory') AS data")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $database || $identity['actor'] !== $role || $identity['host'] !== '127.0.0.1'
        || (int) $identity['port'] !== TIMEOUT_PORT || ! str_starts_with($identity['version'], '17.11')
        || str_replace('\\', '/', $identity['data']) !== str_replace('\\', '/', $root).'/data') {
        throw new RuntimeException('Disposable server identity mismatch.');
    }

    return $pdo;
}

function runWrapper(string $stage): int
{
    return Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION_NAMES[$stage].'.php',
        '--force' => true, '--no-interaction' => true]);
}

function rejectedWrapper(string $stage, string $state, float $minimum, float $maximum): void
{
    $started = microtime(true);
    $actual = null;
    try {
        runWrapper($stage);
    } catch (Throwable $exception) {
        $actual = $exception->errorInfo[0] ?? (string) $exception->getCode();
    }
    $elapsed = microtime(true) - $started;
    $GLOBALS['timings'][] = ['stage' => $stage, 'sqlstate' => $actual, 'seconds' => round($elapsed, 3)];
    verifyTimeout($actual === $state, $stage.' rejects with SQLSTATE '.$state);
    verifyTimeout($elapsed >= $minimum && $elapsed < $maximum, $stage.' failure occurs within the required bound');
    echo $stage.' '.$state.' after '.round($elapsed, 3).' seconds'.PHP_EOL;
}

function assertUnapplied(string $stage): void
{
    verifyTimeout(DB::table('migrations')->where('migration', MIGRATION_NAMES[$stage])->count() === 0,
        $stage.' failure leaves no ledger entry');
    verifyTimeout(DB::table('migration_timeout_probe')->count() === 0, $stage.' failure rolls back DDL probe writes');
    verifyTimeout(DB::connection()->transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction(),
        $stage.' failure exits the aborted transaction');
    if ($stage === '021') {
        verifyTimeout(! Schema::hasColumn('association_accounts', 'ended_at') && ! Schema::hasColumn('association_accounts', 'ended_by'),
            '021 failure rolls back appointment columns');
        verifyTimeout((int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid='association_accounts'::regclass
            AND conname IN ('association_accounts_association_id_key','association_accounts_user_id_key')") === 2,
            '021 failure preserves original unique constraints');
    } else {
        verifyTimeout(! Schema::hasColumn('email_change_requests', 'phase') && ! Schema::hasColumn('user_profiles', 'approved_email')
            && ! Schema::hasColumn('user_profiles', 'email_change_hold_id') && ! Schema::hasTable('email_change_proofs'),
            '022 failure rolls back all new workflow objects');
        verifyTimeout(DB::table('migrations')->where('migration', MIGRATION_NAMES['021'])->count() === 1,
            '022 failure preserves completed 021');
    }
    verifyTimeout(DB::scalar("SELECT current_setting('lock_timeout')::interval = interval '0 seconds'")
        && DB::scalar("SELECT current_setting('statement_timeout')::interval = interval '120 seconds'"),
        $stage.' rollback restores deliberately different session defaults');
}

$database = 'assocmap_timeouts_'.bin2hex(random_bytes(6));
$control = disposableConnection('postgres');
$control->exec('CREATE DATABASE '.$database.' OWNER assocmap_verify');
$admin = disposableConnection($database);
$admin->exec('GRANT CREATE, USAGE ON SCHEMA public TO assocmap_verify');
$result = ['host' => '127.0.0.1', 'port' => TIMEOUT_PORT, 'server' => '17.11'];
try {
    putenv('APP_ENV=testing');
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(sys_get_temp_dir().'/assocmap-timeout-no-environment')->loadEnvironmentFrom('.env.testing');
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached configuration is forbidden.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($database) {
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
            'logging.default' => 'null', 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.invitation_restart_enabled' => false, 'services.supabase.account_reactivation_enabled' => false,
            'services.supabase.account_email_change_enabled' => false,
            'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => TIMEOUT_PORT, 'database' => $database,
                'username' => 'assocmap_verify', 'password' => getenv('ASSOCMAP_PG17_TEST_PASSWORD'),
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
            ]]]);
    });
    $app->extend(Factory::class, fn ($http) => $http->preventStrayRequests());
    $app->make(Kernel::class)->bootstrap();
    verifyTimeout(DB::scalar('SELECT current_database()') === $database, 'Laravel uses the verified disposable database');
    DB::unprepared(file_get_contents(__DIR__.'/association-replacement-base.sql'));
    DB::unprepared("CREATE TABLE email_change_requests (id UUID PRIMARY KEY,user_id UUID NOT NULL REFERENCES user_profiles(id),
        old_email VARCHAR(255) NOT NULL,new_email VARCHAR(255) NOT NULL,requested_by UUID REFERENCES user_profiles(id),
        status VARCHAR(20) NOT NULL DEFAULT 'pending',confirmed_at TIMESTAMPTZ,cancelled_at TIMESTAMPTZ,
        created_at TIMESTAMPTZ NOT NULL DEFAULT now(),updated_at TIMESTAMPTZ NOT NULL DEFAULT now());
        ALTER TABLE email_change_requests ENABLE ROW LEVEL SECURITY;");
    $officer = '10000000-0000-4000-8000-000000000001';
    $holder = '20000000-0000-4000-8000-000000000002';
    $association = '30000000-0000-4000-8000-000000000003';
    DB::table('auth.users')->insert([['id' => $officer, 'email' => 'officer@example.test'], ['id' => $holder, 'email' => 'holder@example.test']]);
    DB::table('user_profiles')->insert([['id' => $officer, 'role_id' => 2, 'is_active' => true, 'activated_at' => now()],
        ['id' => $holder, 'role_id' => 3, 'is_active' => true, 'activated_at' => now()]]);
    DB::table('associations')->insert(['id' => $association, 'name' => 'Synthetic association', 'status' => 'active', 'primary_field_officer_id' => $officer]);
    DB::table('association_accounts')->insert(['id' => '40000000-0000-4000-8000-000000000004',
        'association_id' => $association, 'user_id' => $holder, 'is_active' => true]);
    Artisan::call('migrate:install', ['--no-interaction' => true]);
    $admin->exec(<<<'SQL'
        CREATE TABLE migration_timeout_probe (command_tag TEXT,lock_timeout TEXT,statement_timeout TEXT,backend INTEGER,transaction_id BIGINT);
        CREATE FUNCTION capture_timeout_rehearsal() RETURNS event_trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF current_setting('lock_timeout')::interval <> interval '5 seconds'
                OR current_setting('statement_timeout')::interval <> interval '60 seconds' THEN
                RAISE EXCEPTION 'Incorrect migration-local timeout settings';
            END IF;
            INSERT INTO migration_timeout_probe VALUES (TG_TAG,current_setting('lock_timeout'),current_setting('statement_timeout'),pg_backend_pid(),txid_current());
            IF current_setting('assocmap.timeout_probe_mode',true) = 'sleep' AND (SELECT count(*) FROM migration_timeout_probe) >= 2 THEN
                PERFORM pg_sleep(65);
            END IF;
        END;
        $$;
        CREATE EVENT TRIGGER capture_timeout_rehearsal ON ddl_command_start
            WHEN TAG IN ('ALTER TABLE','CREATE TABLE','CREATE INDEX') EXECUTE FUNCTION capture_timeout_rehearsal();
        GRANT SELECT,INSERT,DELETE ON migration_timeout_probe TO assocmap_verify;
        SQL);
    foreach (MIGRATION_NAMES as $stage => $name) {
        // Simulate the observed pooler defaults; never depend on PGOPTIONS.
        DB::statement("SET lock_timeout='0'");
        DB::statement("SET statement_timeout='120s'");
        $blocker = disposableConnection($database);
        $blocker->beginTransaction();
        try {
            $blocker->exec('LOCK TABLE public.user_profiles IN ACCESS EXCLUSIVE MODE');
            rejectedWrapper($stage, '55P03', 4, 15);
        } finally {
            $blocker->rollBack();
            $blocker = null;
        }
        assertUnapplied($stage);
        DB::statement("SET assocmap.timeout_probe_mode='sleep'");
        rejectedWrapper($stage, '57014', 55, 75);
        DB::statement("SET assocmap.timeout_probe_mode=''");
        assertUnapplied($stage);
        verifyTimeout(runWrapper($stage) === 0, $stage.' retry succeeds after simulated failures are removed');
        $probe = DB::selectOne("SELECT count(*) AS total,count(DISTINCT backend) AS backends,count(DISTINCT transaction_id) AS transactions,
            count(*) FILTER(WHERE lock_timeout::interval<>interval '5 seconds' OR statement_timeout::interval<>interval '60 seconds') AS wrong FROM migration_timeout_probe");
        verifyTimeout((int) $probe->total > 1 && (int) $probe->backends === 1 && (int) $probe->transactions === 1 && (int) $probe->wrong === 0,
            $stage.' actual DDL uses both limits on one backend in one transaction');
        verifyTimeout(DB::scalar("SELECT current_setting('lock_timeout')::interval=interval '0 seconds'")
            && DB::scalar("SELECT current_setting('statement_timeout')::interval=interval '120 seconds'"),
            $stage.' commit restores session defaults');
        verifyTimeout(DB::table('migrations')->where('migration', $name)->count() === 1, $stage.' has exactly one ledger entry');
        verifyTimeout(runWrapper($stage) === 0 && DB::table('migrations')->where('migration', $name)->count() === 1,
            $stage.' repeated wrapper invocation is a ledger-preserving no-op');
        verifyTimeout(DB::table('association_accounts')->where('user_id', $holder)->where('association_id', $association)->where('is_active', true)->exists()
            && DB::table('associations')->where('id', $association)->value('primary_field_officer_id') === $officer,
            $stage.' preserves original appointment and primary officer');
        verifyTimeout((int) DB::scalar("SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid=i.indrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND NOT i.indisvalid") === 0, $stage.' leaves no invalid index');
        verifyTimeout((int) DB::scalar("SELECT count(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace
            WHERE n.nspname='public' AND NOT c.convalidated") === 0, $stage.' leaves no unvalidated constraint');
        DB::table('migration_timeout_probe')->delete();
        echo $stage.' verified'.PHP_EOL;
    }
    foreach (['association_accounts', 'email_change_requests', 'email_change_proofs'] as $table) {
        verifyTimeout((bool) DB::scalar("SELECT relrowsecurity FROM pg_class WHERE oid='$table'::regclass"), $table.' RLS remains enabled');
    }
    foreach (['email_change_requests', 'email_change_proofs'] as $table) {
        foreach (['anon', 'authenticated'] as $role) {
            verifyTimeout(! DB::scalar("SELECT has_table_privilege('$role','$table','SELECT,INSERT,UPDATE,DELETE')"), $role.' has no access to '.$table);
        }
    }
    verifyTimeout(DB::table('email_change_requests')->count() === 0 && DB::table('email_change_proofs')->count() === 0,
        'Schema rehearsal issues no email requests or proofs');
    $result += ['passed' => count($checks), 'failed' => 0, 'timings' => $timings, 'checks' => $checks];
} catch (Throwable $exception) {
    $result += ['passed' => count($checks), 'failed' => 1, 'failure_type' => get_class($exception),
        'failure' => str_starts_with($exception->getMessage(), 'FAILED:') ? $exception->getMessage() : 'Synthetic verification failed at line '.$exception->getLine(),
        'timings' => $timings];
} finally {
    if (isset($app)) {
        DB::disconnect();
    }
    $admin = null;
    $control->exec('DROP DATABASE '.$database);
    $result['disposable_database_removed'] = true;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(($result['failed'] ?? 1) === 0 ? 0 : 1);
