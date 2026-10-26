<?php

// Task 12 standalone synthetic verification. Never loads .env or accepts a remote server.
use App\Models\UserProfile;
use App\Services\Auth\AccountEmailChangeService;
use App\Services\Auth\InvitationActivationContext;
use App\Services\Auth\SupabaseSessionBoundary;
use App\Services\Auth\UserAccessRevocationService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const ADMIN = '10000000-0000-4000-8000-000000000001';
const BASELINE_OFFICER = '20000000-0000-4000-8000-000000000002';
const BASELINE_ASSOCIATION_ACCOUNT = '30000000-0000-4000-8000-000000000003';
const BASELINE_ASSOCIATION = '40000000-0000-4000-8000-000000000004';
const BASELINE_ASSOCIATION_LINK = '50000000-0000-4000-8000-000000000005';
const MIGRATION = '2026_10_22_020000_support_verified_email_change';
const PG17_PORT = 55439;

function testPassword(): string
{
    $password = getenv('ASSOCMAP_PG17_TEST_PASSWORD');
    if (! is_string($password) || strlen($password) < 24) {
        throw new RuntimeException('A temporary PostgreSQL 17 test password is required.');
    }

    return $password;
}

function connection(string $database): PDO
{
    if ($database !== 'postgres' && ! preg_match('/\Aassocmap_emailchange_[a-f0-9]{12}\z/D', $database)) {
        throw new RuntimeException('Disposable database name required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.PG17_PORT.';dbname='.$database.';sslmode=disable;connect_timeout=5',
        'assocmap_verify', testPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() AS db, current_user AS actor, current_setting('server_version') AS version, host(inet_server_addr()) AS host, inet_server_port() AS port")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $database || $identity['actor'] !== 'assocmap_verify' || $identity['host'] !== '127.0.0.1'
        || (int) $identity['port'] !== PG17_PORT || ! str_starts_with($identity['version'], '17.11')) {
        throw new RuntimeException('Disposable server identity mismatch.');
    }
    $pdo->exec("SET statement_timeout = '10s'; SET lock_timeout = '5s'");

    return $pdo;
}

function administrativeConnection(string $database): PDO
{
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.PG17_PORT.';dbname='.$database.';sslmode=disable;connect_timeout=5',
        'postgres', testPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() AS db, current_user AS actor, current_setting('server_version') AS version, host(inet_server_addr()) AS host, inet_server_port() AS port")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $database || $identity['actor'] !== 'postgres' || $identity['host'] !== '127.0.0.1'
        || (int) $identity['port'] !== PG17_PORT || ! str_starts_with($identity['version'], '17.11')) {
        throw new RuntimeException('Disposable administrative connection identity mismatch.');
    }

    return $pdo;
}

function application(string $database): void
{
    putenv('APP_ENV=testing');
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(sys_get_temp_dir().'/assocmap-no-environment-'.basename(__FILE__))->loadEnvironmentFrom('.env.testing');
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Verification refuses cached configuration.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($database) {
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('v', 32)),
            'app.url' => 'https://app.example.test', 'cache.default' => 'array', 'session.driver' => 'array',
            'mail.default' => 'array', 'queue.default' => 'sync', 'logging.default' => 'null',
            'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => PG17_PORT, 'database' => $database,
                'username' => 'assocmap_verify', 'password' => testPassword(), 'charset' => 'utf8', 'prefix' => '',
                'search_path' => 'public', 'sslmode' => 'disable',
                'timezone' => 'UTC',
            ]], 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic',
            'services.supabase.service_role_key' => 'synthetic.server.signature',
            'services.supabase.invitation_redirect_url' => 'https://app.example.test/invitation',
            'services.supabase.activation_cache_store' => 'array', 'services.supabase.account_reactivation_enabled' => true, 'services.supabase.account_email_change_enabled' => true]);
    });
    $app->extend(Factory::class, fn ($http) => $http->preventStrayRequests());
    $app->make(Kernel::class)->bootstrap();
    $pdo = DB::connection()->getPdo();
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== $database) {
        throw new RuntimeException('Laravel connection identity mismatch.');
    }
}

function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAILED: '.$label);
    }
    $GLOBALS['checks'][] = $label;
}

function installProvider(): void
{
    app()->instance(InvitationActivationContext::class, new class extends InvitationActivationContext
    {
        public function storage(): Repository
        {
            return Cache::store('array');
        }

        public function checkStorage(): void {}
    });
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/auth/v1/recover') {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $GLOBALS['reference'] = explode('/', $query['redirect_to'])[4];
            DB::table('synthetic_email_effects')->insert(['user_id' => DB::table('auth.users')->where('email', $request['email'])->value('id'), 'stage' => 'recover']);

            return Http::response('{}', 200, ['Content-Type' => 'application/json']);
        }
        $user = substr($request->header('Authorization')[0] ?? '', 7);
        if (! Str::isUuid($user)) {
            throw new RuntimeException('Synthetic identity required.');
        }
        $identity = ['id' => $user, 'email' => DB::table('auth.users')->where('id', $user)->value('email')];
        if ($request->method() === 'PUT' || $path === '/auth/v1/logout') {
            $stage = $path === '/auth/v1/logout' ? 'logout' : (isset($request['password']) ? 'password' : 'email');
            DB::table('synthetic_email_effects')->insert(['user_id' => $user, 'stage' => $stage]);
            DB::select('SELECT pg_sleep(0.15)');

            return $stage === 'logout' ? Http::response('', 204) : Http::response($identity);
        }
        if ($path === '/auth/v1/user' && $request->method() === 'GET') {
            return Http::response($identity);
        }
        throw new RuntimeException('External network denied in synthetic verification.');
    });
}

function actorRequest(): Request
{
    $request = new Request;
    $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(ADMIN));

    return $request;
}

function holder(int $role = 2): string
{
    $id = Str::uuid()->toString();
    DB::table('auth.users')->insert(['id' => $id, 'email' => $id.'@example.test']);
    DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'is_active' => true, 'display_name' => 'Synthetic holder', 'activated_at' => now()->subDay()]);

    return $id;
}

function issue(string $user, ?string $destination = null): object
{
    $destination ??= Str::uuid()->toString().'@example.test';
    $service = app(AccountEmailChangeService::class);
    $target = UserProfile::findOrFail($user);
    $review = $service->review(UserProfile::findOrFail(ADMIN), $target, $destination);
    $service->requestLink(actorRequest(), $target, $destination, $review['review_hash']);

    return DB::table('email_change_requests')->where('user_id', $user)->where('status', 'pending')->sole();
}

function preparePhase(object $row, string $phase): object
{
    $values = ['phase' => $phase, 'browser_hash' => hash('sha256', str_repeat('s', 40))];
    if (in_array($phase, ['verified', 'password_started'], true)) {
        $values['first_confirmation_at'] = now();
        DB::table('auth.users')->where('id', $row->user_id)->update(['email' => $row->new_email]);
    }
    DB::table('email_change_requests')->where('id', $row->id)->update($values);

    return DB::table('email_change_requests')->where('id', $row->id)->sole();
}

function recipient(object $row): Request
{
    $session = new Store('synthetic_email_change', new ArraySessionHandler(120));
    $session->setId(str_repeat('s', 40));
    $session->start();
    $session->put('email_change_context', str_repeat('c', 64));
    $request = new Request;
    $request->setLaravelSession($session);
    $context = ['request_id' => $row->id, 'auth_user_id' => $row->user_id,
        'email' => DB::table('auth.users')->where('id', $row->user_id)->value('email'), 'access_token' => $row->user_id,
        'expires_at' => time() + 3600, 'browser_hash' => hash('sha256', $session->getId())];
    Cache::store('array')->put('email-change:'.str_repeat('c', 64),
        Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)), 3600);

    return $request;
}

function operation(string $job, array $args): string
{
    $service = app(AccountEmailChangeService::class);
    if ($job === 'issue') {
        $service->requestLink(actorRequest(), UserProfile::findOrFail($args[0]), $args[1], $args[2]);
    } elseif ($job === 'revoke') {
        app(UserAccessRevocationService::class)->revoke(actorRequest(), UserProfile::findOrFail($args[0]));
    } else {
        $row = DB::table('email_change_requests')->where('id', $args[0])->sole();
        $request = recipient($row);
        if ($job === 'password') {
            $service->complete($request, 'Synthetic-Private-Password42!');
        } elseif ($job === 'begin') {
            $service->begin($request, $args[1]);
        }
    }

    return 'ok';
}

function parallel(string $database, array $jobs): array
{
    $barrier = random_int(100000, 999999);
    DB::select('SELECT pg_advisory_lock(?)', [$barrier]);
    $workers = [];
    try {
        foreach ($jobs as [$job, $arguments]) {
            $payload = base64_encode(json_encode([$database, $barrier, $job, $arguments], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __FILE__, '--worker='.$payload], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                null, null, ['bypass_shell' => true, 'create_new_console' => false]);
            if (! is_resource($process)) {
                throw new RuntimeException('Synthetic worker unavailable.');
            }
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
            stream_set_timeout($pipes[1], 25);
            if (trim((string) fgets($pipes[1])) !== 'READY') {
                throw new RuntimeException('Synthetic barrier unavailable.');
            }
        }
    } finally {
        DB::select('SELECT pg_advisory_unlock(?)', [$barrier]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') {
            throw new RuntimeException('Synthetic worker failed.');
        }
    }

    return $results;
}

function rejected(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (QueryException $exception) {
        check(in_array($exception->errorInfo[0] ?? '', ['23505', '23514', '23503', 'P0001', '42501'], true), $label);

        return;
    }
    check(false, $label);
}

function denied(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        check(true, $label);

        return;
    }
    check(false, $label);
}

$worker = getopt('', ['worker:'])['worker'] ?? null;
if ($worker !== null) {
    [$database, $barrier, $job, $arguments] = json_decode(base64_decode($worker, true), true, 8, JSON_THROW_ON_ERROR);
    connection($database);
    application($database);
    installProvider();
    echo "READY\n";
    flush();
    DB::select('SELECT pg_advisory_lock_shared(?)', [$barrier]);
    DB::select('SELECT pg_advisory_unlock_shared(?)', [$barrier]);
    try {
        echo operation($job, $arguments);
    } catch (ValidationException $exception) {
        echo 'denied';
    } catch (QueryException $exception) {
        echo $exception->errorInfo[0];
    }
    exit(0);
}

$GLOBALS['checks'] = [];
$testPassword = testPassword();
$database = 'assocmap_emailchange_'.bin2hex(random_bytes(6));
$control = administrativeConnection('postgres');
$control->exec('CREATE DATABASE '.$database);
$databaseAdmin = administrativeConnection($database);
$databaseAdmin->exec('GRANT ALL PRIVILEGES ON DATABASE '.$database.' TO assocmap_verify; GRANT CREATE, USAGE ON SCHEMA public TO assocmap_verify');
$result = ['server' => '17.11', 'host' => '127.0.0.1', 'port' => PG17_PORT];
try {
    application($database);
    installProvider();
    DB::unprepared(file_get_contents(__DIR__.'/association-replacement-base.sql'));
    DB::statement('ALTER TABLE auth.users ADD COLUMN email_change TEXT');
    foreach (['anon', 'authenticated', 'assocmap_session_reader'] as $role) {
        if (! $control->query("SELECT 1 FROM pg_roles WHERE rolname='$role'")->fetchColumn()) {
            $control->exec('CREATE ROLE '.$role.' NOLOGIN');
        }
    }
    $control->exec('GRANT assocmap_session_reader TO assocmap_verify');
    DB::unprepared(<<<'SQL'
        CREATE TABLE email_change_requests (id UUID PRIMARY KEY, user_id UUID NOT NULL REFERENCES user_profiles(id), old_email VARCHAR(255) NOT NULL,
            new_email VARCHAR(255) NOT NULL, requested_by UUID REFERENCES user_profiles(id), status VARCHAR(20) NOT NULL DEFAULT 'pending'
            CHECK (status IN ('pending','confirmed','cancelled','expired')), confirmed_at TIMESTAMPTZ, cancelled_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), CHECK (lower(old_email) <> lower(new_email)),
            CHECK (status <> 'confirmed' OR confirmed_at IS NOT NULL));
        CREATE UNIQUE INDEX uq_email_change_pending_per_user ON email_change_requests(user_id) WHERE status='pending';
        ALTER TABLE email_change_requests ENABLE ROW LEVEL SECURITY;
        CREATE TABLE synthetic_email_effects (user_id UUID, stage TEXT);
        SQL);
    DB::table('auth.users')->insert([
        ['id' => BASELINE_OFFICER, 'email' => 'baseline-officer@example.test'],
        ['id' => BASELINE_ASSOCIATION_ACCOUNT, 'email' => 'baseline-account@example.test'],
    ]);
    DB::table('user_profiles')->insert([
        ['id' => BASELINE_OFFICER, 'role_id' => 2, 'display_name' => 'Baseline officer', 'is_active' => true, 'activated_at' => now()],
        ['id' => BASELINE_ASSOCIATION_ACCOUNT, 'role_id' => 3, 'display_name' => 'Baseline association account', 'is_active' => true, 'activated_at' => now()],
    ]);
    DB::table('associations')->insert(['id' => BASELINE_ASSOCIATION, 'name' => 'Baseline association', 'status' => 'active',
        'primary_field_officer_id' => BASELINE_OFFICER]);
    DB::table('association_accounts')->insert(['id' => BASELINE_ASSOCIATION_LINK, 'association_id' => BASELINE_ASSOCIATION,
        'user_id' => BASELINE_ASSOCIATION_ACCOUNT, 'is_active' => true, 'linked_at' => now()]);
    DB::transaction(fn () => (require database_path('migrations/2026_10_22_000000_add_account_reactivation.php'))->up());
    DB::transaction(fn () => (require database_path('migrations/2026_10_22_010000_support_association_account_replacement.php'))->up());
    DB::table('auth.users')->insert(['id' => ADMIN, 'email' => 'synthetic-admin@example.test']);
    DB::table('user_profiles')->insert(['id' => ADMIN, 'role_id' => 1, 'is_active' => true, 'activated_at' => now()->subDay()]);
    $one = holder();
    $two = holder();
    foreach ([$one, $two] as $user) {
        DB::table('email_change_requests')->insert(['id' => Str::uuid()->toString(), 'user_id' => $user,
            'old_email' => $user.'@example.test', 'new_email' => 'duplicate@example.test']);
    }
    rejected(fn () => Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION.'.php', '--force' => true]), 'conflicting legacy destinations reject migration');
    check(! Schema::hasColumn('user_profiles', 'email_change_hold_id') && ! Schema::hasColumn('email_change_requests', 'phase'), 'migration failure rolls back all added columns');
    check(! Schema::hasTable('email_change_proofs') && DB::table('migrations')->count() === 0, 'migration failure leaves no proof table or ledger');
    DB::table('email_change_requests')->delete();

    $blocker = connection($database);
    $blocker->beginTransaction();
    try {
        $blocker->exec('LOCK TABLE public.user_profiles IN ACCESS EXCLUSIVE MODE');
        $startedAt = microtime(true);
        try {
            $lockExit = Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION.'.php', '--force' => true]);
        } catch (Throwable) {
            $lockExit = 1;
        }
        $lockElapsed = microtime(true) - $startedAt;
        $lockOutput = Artisan::output();
        $blocker->rollBack();
        $hasLockTimeout = preg_match('/\b5s FAIL\b/i', $lockOutput) === 1;
        if ($lockExit === 0 || $lockElapsed < 4.0 || $lockElapsed >= 15.0 || ! $hasLockTimeout) {
            fwrite(STDERR, 'LOCK_TIMEOUT_DIAGNOSTICS='.json_encode([
                'exit_code' => $lockExit,
                'elapsed_seconds' => round($lockElapsed, 3),
                'contains_lock_timeout_signature' => $hasLockTimeout,
                'output_preview' => substr(trim($lockOutput), 0, 320),
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        check($lockExit !== 0 && $lockElapsed >= 4.0 && $lockElapsed < 15.0
            && $hasLockTimeout, 'migration fails within bounded lock timeout');
        check(! Schema::hasColumn('email_change_requests', 'phase')
            && ! Schema::hasTable('email_change_proofs')
            && ! Schema::hasColumn('user_profiles', 'approved_email')
            && DB::table('migrations')->where('migration', MIGRATION)->count() === 0,
            'lock timeout rolls back all migration schema changes and ledger entry');
    } finally {
        if ($blocker->inTransaction()) {
            $blocker->rollBack();
        }
        $blocker = null;
    }

    $controlDb = administrativeConnection($database);
    $controlDb->exec(<<<'SQL'
        CREATE TABLE public.migration_timeout_probe (
            command_tag TEXT NOT NULL,
            lock_timeout TEXT NOT NULL,
            statement_timeout TEXT NOT NULL
        );
        CREATE FUNCTION public.capture_migration_timeouts() RETURNS event_trigger
        LANGUAGE plpgsql AS $$
        BEGIN
            INSERT INTO public.migration_timeout_probe
                VALUES (TG_TAG, current_setting('lock_timeout'), current_setting('statement_timeout'));
        END;
        $$;
        CREATE EVENT TRIGGER capture_email_change_migration_timeouts
            ON ddl_command_start WHEN TAG IN ('ALTER TABLE', 'CREATE TABLE', 'CREATE INDEX')
            EXECUTE FUNCTION public.capture_migration_timeouts();
        GRANT SELECT, INSERT ON public.migration_timeout_probe TO assocmap_verify;
        SQL);

    $sessionTimeoutsBefore = DB::selectOne("SELECT current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS statement_timeout");
    Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION.'.php', '--force' => true]);
    check(DB::table('associations')->where('id', BASELINE_ASSOCIATION)->value('primary_field_officer_id') === BASELINE_OFFICER
        && DB::table('association_accounts')->where('id', BASELINE_ASSOCIATION_LINK)
            ->where('association_id', BASELINE_ASSOCIATION)->where('user_id', BASELINE_ASSOCIATION_ACCOUNT)
            ->where('is_active', true)->exists(), 'existing association and account relationships remain intact');
    check((int) DB::scalar("SELECT count(*) FROM public.migration_timeout_probe WHERE lock_timeout::interval = interval '5 seconds' AND statement_timeout::interval = interval '60 seconds'") > 0
        && (int) DB::scalar("SELECT count(*) FROM public.migration_timeout_probe WHERE lock_timeout::interval <> interval '5 seconds' OR statement_timeout::interval <> interval '60 seconds'") === 0,
        'Laravel migration transaction applies both local timeout settings to DDL statements');
    check(DB::scalar("SELECT current_setting('lock_timeout')") === $sessionTimeoutsBefore->lock_timeout
        && DB::scalar("SELECT current_setting('statement_timeout')") === $sessionTimeoutsBefore->statement_timeout,
        'migration timeout settings do not leak beyond its transaction');
    $controlDb->exec('DROP EVENT TRIGGER capture_email_change_migration_timeouts; DROP FUNCTION public.capture_migration_timeouts(); DROP TABLE public.migration_timeout_probe;');
    Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION.'.php', '--force' => true]);
    DB::transaction(fn () => (require database_path('migrations/'.MIGRATION.'.php'))->up());
    check(DB::table('migrations')->count() === 1, 'wrapper and SQL idempotency with one ledger entry');
    check((int) DB::scalar("SELECT count(*) FROM pg_index WHERE indrelid IN ('email_change_requests'::regclass,'email_change_proofs'::regclass) AND NOT indisvalid") === 0, 'all email indexes valid');
    check((int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid IN ('email_change_requests'::regclass,'email_change_proofs'::regclass,'user_profiles'::regclass) AND NOT convalidated") === 0, 'all email constraints validated');
    foreach (['email_change_requests', 'email_change_proofs'] as $table) {
        check((bool) DB::scalar("SELECT relrowsecurity FROM pg_class WHERE oid='$table'::regclass"), $table.' RLS enabled');
        check((int) DB::scalar("SELECT count(*) FROM pg_policy WHERE polrelid='$table'::regclass") === 0, $table.' has no client policies');
        foreach (['anon', 'authenticated'] as $role) {
            check(! DB::scalar("SELECT has_table_privilege('$role','$table','SELECT,INSERT,UPDATE,DELETE')"), $role.' cannot access '.$table);
        }
    }
    $row = issue($one);
    check(UserProfile::findOrFail($one)->is_active && UserProfile::findOrFail($one)->email_change_hold_id === $row->id, 'request holds access without revoking profile');
    rejected(fn () => DB::table('email_change_requests')->where('id', $row->id)->update(['phase' => 'invalid']), 'phase CHECK enforced');
    rejected(fn () => DB::table('email_change_requests')->where('id', $row->id)->update(['reference_hash' => null]), 'managed proof reference cannot be NULL');
    rejected(fn () => DB::table('email_change_requests')->where('id', $row->id)->update(['phase' => 'completed', 'status' => 'confirmed', 'confirmed_at' => now()]), 'completion requires password and signout receipts');
    rejected(fn () => DB::table('user_profiles')->where('id', $two)->update(['email_change_hold_id' => $row->id]), 'hold cannot bind a different holder');
    rejected(fn () => DB::table('email_change_proofs')->insert(['request_id' => Str::uuid()->toString(), 'proof_hash' => str_repeat('a', 64)]), 'proof request foreign key enforced');
    DB::table('email_change_proofs')->insert(['request_id' => $row->id, 'proof_hash' => str_repeat('a', 64)]);
    rejected(fn () => DB::table('email_change_proofs')->insert(['request_id' => $row->id, 'proof_hash' => str_repeat('a', 64)]), 'proof replay rejected by database');
    $duplicate = (array) $row;
    $duplicate['id'] = Str::uuid()->toString();
    $duplicate['user_id'] = $two;
    $duplicate['old_email'] = $two.'@example.test';
    $duplicate['reference_hash'] = hash('sha256', Str::random(64));
    rejected(fn () => DB::table('email_change_requests')->insert($duplicate), 'pending destination unique index enforced');
    DB::unprepared('GRANT USAGE ON SCHEMA auth TO assocmap_session_reader; GRANT SELECT (id,user_id,created_at) ON auth.sessions TO assocmap_session_reader');
    $sessionId = Str::uuid()->toString();
    DB::table('auth.sessions')->insert(['id' => $sessionId, 'user_id' => $two, 'created_at' => now()->subSeconds(10)]);
    $profile = UserProfile::findOrFail($two);
    $profile->auth_sessions_valid_after = now()->subMinute();
    $claims = ['sub' => $two, 'iss' => 'https://auth.example.test/auth/v1', 'role' => 'authenticated', 'aud' => 'authenticated',
        'exp' => now()->addHour()->timestamp, 'session_id' => $sessionId, 'amr' => [['method' => 'password', 'timestamp' => now()->subSeconds(10)->timestamp]]];
    $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
    $token = $encode(['alg' => 'HS256']).'.'.$encode($claims).'.synthetic_signature';
    DB::statement('SET ROLE assocmap_session_reader');
    check(app(SupabaseSessionBoundary::class)->allows($profile, ['id' => $two], $token), 'ID-only session boundary works with restricted column grants');
    rejected(fn () => DB::table('auth.sessions')->where('id', $sessionId)->exists(), 'unprojected session query remains forbidden');
    DB::statement('RESET ROLE');
    check(! DB::scalar("SELECT has_column_privilege('assocmap_session_reader','auth.sessions','provider_private_value','SELECT')"), 'no private session-column permission needed');
    $auditUser = holder();
    DB::statement("SET assocmap.reject_audit = 'REQUEST VERIFIED EMAIL CHANGE'");
    rejected(fn () => issue($auditUser), 'request audit failure rejects transaction');
    check(UserProfile::findOrFail($auditUser)->email_change_hold_id === null
        && DB::table('email_change_requests')->where('user_id', $auditUser)->count() === 0
        && DB::table('synthetic_email_effects')->where('user_id', $auditUser)->count() === 0, 'request audit rollback prevents hold capability and provider call');
    DB::statement("SET assocmap.reject_audit = ''");
    $auditRow = preparePhase(issue($auditUser), 'verified');
    DB::statement("SET assocmap.reject_audit = 'COMPLETE VERIFIED EMAIL CHANGE'");
    rejected(fn () => app(AccountEmailChangeService::class)->complete(recipient($auditRow), 'Synthetic-Private-Password42!'), 'final audit failure rolls back activation finalization');
    $auditProfile = UserProfile::findOrFail($auditUser);
    check($auditProfile->email_change_hold_id === $auditRow->id && $auditProfile->approved_email === null && $auditProfile->auth_sessions_valid_after === null,
        'final audit failure retains independent access hold and old authorization binding');
    check(DB::table('email_change_requests')->where('id', $auditRow->id)->value('phase') === 'signout_started', 'provider receipts cannot imply a committed completion');
    DB::statement("SET assocmap.reject_audit = ''");
    denied(fn () => app(AccountEmailChangeService::class)->complete(recipient($auditRow), 'Synthetic-Private-Password42!'), 'uncertain finalization cannot repeat a password write');
    check(DB::table('synthetic_email_effects')->where('user_id', $auditUser)->where('stage', 'password')->count() === 1, 'password write remains single after final audit rollback');

    $user = holder();
    $destination = Str::uuid()->toString().'@example.test';
    $review = app(AccountEmailChangeService::class)->review(UserProfile::findOrFail(ADMIN), UserProfile::findOrFail($user), $destination);
    $outcomes = parallel($database, [['issue', [$user, $destination, $review['review_hash']]], ['issue', [$user, $destination, $review['review_hash']]]]);
    sort($outcomes);
    check($outcomes === ['denied', 'ok'] && DB::table('email_change_requests')->where('user_id', $user)->count() === 1, 'concurrent duplicate requests issue one capability and email');
    $users = [holder(), holder()];
    $destination = Str::uuid()->toString().'@example.test';
    $jobs = [];
    foreach ($users as $user) {
        $review = app(AccountEmailChangeService::class)->review(UserProfile::findOrFail(ADMIN), UserProfile::findOrFail($user), $destination);
        $jobs[] = ['issue', [$user, $destination, $review['review_hash']]];
    }
    $outcomes = parallel($database, $jobs);
    sort($outcomes);
    check($outcomes === ['denied', 'ok'], 'concurrent destinations reserve one identity only');
    $row = preparePhase(issue(holder()), 'old_verified');
    $reference = $GLOBALS['reference'];
    $outcomes = parallel($database, [['begin', [$row->id, $reference]], ['begin', [$row->id, $reference]]]);
    sort($outcomes);
    check($outcomes === ['denied', 'ok'] && DB::table('synthetic_email_effects')->where('user_id', $row->user_id)->where('stage', 'email')->count() === 1,
        'concurrent provider email updates are claimed once');
    $row = preparePhase(issue(holder()), 'verified');
    $outcomes = parallel($database, [['password', [$row->id]], ['password', [$row->id]]]);
    sort($outcomes);
    check($outcomes === ['denied', 'ok'] && DB::table('synthetic_email_effects')->where('user_id', $row->user_id)->where('stage', 'password')->count() === 1,
        'concurrent password writes are claimed once across independent caches');
    $profile = UserProfile::findOrFail($row->user_id);
    check($profile->approved_email === $row->new_email && $profile->email_change_hold_id === null && $profile->auth_sessions_valid_after !== null, 'native completion stores current email and permanent cutoff');
    $row = preparePhase(issue(holder()), 'verified');
    $outcomes = parallel($database, [['password', [$row->id]], ['revoke', [$row->user_id]]]);
    check(count(array_diff($outcomes, ['ok', 'denied'])) === 0 && ! UserProfile::findOrFail($row->user_id)->is_active
        && DB::table('synthetic_email_effects')->where('user_id', $row->user_id)->where('stage', 'password')->count() <= 1, 'password racing revocation cannot restore access');
    $user = holder();
    $destination = Str::uuid()->toString().'@example.test';
    $review = app(AccountEmailChangeService::class)->review(UserProfile::findOrFail(ADMIN), UserProfile::findOrFail($user), $destination);
    $outcomes = parallel($database, [['issue', [$user, $destination, $review['review_hash']]], ['revoke', [$user]]]);
    check(count(array_diff($outcomes, ['ok', 'denied'])) === 0 && ! UserProfile::findOrFail($user)->is_active, 'request racing revocation preserves revocation');
    $result += ['passed' => count($GLOBALS['checks']), 'failed' => 0, 'concurrency_scenarios' => 6, 'checks' => $GLOBALS['checks']];
} catch (Throwable $exception) {
    $result += ['passed' => count($GLOBALS['checks']), 'failed' => 1, 'failure_type' => get_class($exception),
        'failure' => str_starts_with($exception->getMessage(), 'FAILED:') ? $exception->getMessage() : 'Synthetic verification failed; inspect locally.'];
    fwrite(STDERR, get_class($exception).' at line '.$exception->getLine().' code '.$exception->getCode().PHP_EOL);
    foreach (array_slice($exception->getTrace(), 0, 10) as $frame) {
        fwrite(STDERR, basename($frame['file'] ?? '').':'.($frame['line'] ?? 0).' '.($frame['function'] ?? '').PHP_EOL);
    }
} finally {
    DB::disconnect();
    $control->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$database' AND pid <> pg_backend_pid()");
    $control->exec('DROP DATABASE '.$database);
    $result['disposable_database_removed'] = true;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(($result['failed'] ?? 1) === 0 ? 0 : 1);
