<?php

// Standalone synthetic verification. Never loads .env or accepts a remote server.
use App\Exceptions\InvitationActivationException;
use App\Models\UserProfile;
use App\Services\Auth\AccountReactivationService;
use App\Services\Auth\InvitationActivationService;
use App\Services\Auth\SupabaseSessionBoundary;
use App\Services\Auth\UserAccessRevocationService;
use App\Services\Auth\UserInvitationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const ADMIN = '10000000-0000-4000-8000-000000000001';
const MIGRATION = '2026_10_22_010000_support_association_account_replacement';

function connection(string $database): PDO
{
    if ($database !== 'postgres' && ! preg_match('/\Aassocmap_replacement_[a-f0-9]{12}\z/D', $database)) {
        throw new RuntimeException('Disposable database name required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port=55439;dbname='.$database.';sslmode=disable;connect_timeout=5',
        'assocmap_verify', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() AS db, current_user AS actor, current_setting('server_version') AS version, host(inet_server_addr()) AS host, inet_server_port() AS port")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $database || $identity['actor'] !== 'assocmap_verify' || $identity['host'] !== '127.0.0.1'
        || (int) $identity['port'] !== 55439 || ! str_starts_with($identity['version'], '17.11')) {
        throw new RuntimeException('Disposable server identity mismatch.');
    }
    $pdo->exec("SET statement_timeout = '10s'; SET lock_timeout = '5s'");

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
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => $database,
                'username' => 'assocmap_verify', 'password' => '', 'charset' => 'utf8', 'prefix' => '',
                'search_path' => 'public', 'sslmode' => 'disable',
            ]], 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic',
            'services.supabase.service_role_key' => 'synthetic.server.signature',
            'services.supabase.invitation_redirect_url' => 'https://app.example.test/invitation',
            'services.supabase.activation_cache_store' => 'array', 'services.supabase.account_reactivation_enabled' => true]);
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

function uuid(): string
{
    return Str::uuid()->toString();
}

function profile(int $role = 3, bool $activated = true, ?string $id = null): string
{
    $id ??= uuid();
    DB::table('auth.users')->insert(['id' => $id, 'email' => $id.'@example.test']);
    DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic holder',
        'is_active' => true, 'activated_at' => $activated ? now()->subDay() : null]);

    return $id;
}

function association(): string
{
    $id = uuid();
    DB::table('associations')->insert(['id' => $id, 'name' => 'Synthetic association', 'status' => 'active']);

    return $id;
}

function appointment(string $user, string $association): string
{
    $id = uuid();
    DB::table('association_accounts')->insert(['id' => $id, 'user_id' => $user, 'association_id' => $association,
        'is_active' => true, 'linked_at' => now()->subHour()]);

    return $id;
}

function invitation(string $association, string $status = 'pending', ?string $user = null): string
{
    $id = uuid();
    DB::table('user_invitations')->insert(['id' => $id, 'email' => ($user ?? $id).'@example.test',
        'display_name' => 'Synthetic invitation', 'role_id' => 3, 'association_id' => $association,
        'invited_by' => ADMIN, 'status' => $status, 'auth_user_id' => $user,
        'accepted_at' => $status === 'accepted' ? now()->subDay() : null,
        'last_error_code' => $status === 'sent' ? 'synthetic_confirmed_claim' : null]);

    return $id;
}

function actorRequest(): Request
{
    $request = new Request;
    $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(ADMIN));

    return $request;
}

function proof(string $id): array
{
    $invitation = DB::table('user_invitations')->where('id', $id)->first();

    return ['invitation_id' => $id, 'auth_user_id' => $invitation->auth_user_id,
        'email' => $invitation->email, 'role_id' => '3', 'association_id' => $invitation->association_id,
        'phase' => 'password_confirmed', 'expires_at' => time() + 300];
}

function fakeProvider(string $identity): void
{
    Http::fake(function ($request) use ($identity) {
        if ($request->method() === 'GET' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/admin/users')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['users' => []], 200, ['X-Total-Count' => '0',
                'Link' => '</auth/v1/admin/users?'.http_build_query(['filter' => $query['filter'], 'page' => 0, 'per_page' => 100]).'>; rel="last"']);
        }
        if ($request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/invite')) {
            DB::table('auth.users')->insert(['id' => $identity, 'email' => $request['email']]);

            return Http::response(['id' => $identity, 'email' => $request['email'], 'created_at' => now()->utc()->addSecond()->toIso8601String()]);
        }
        throw new RuntimeException('Unexpected provider request blocked.');
    });
}

function operation(string $job, array $arguments): string
{
    if ($job === 'revoke') {
        app(UserAccessRevocationService::class)->revoke(actorRequest(), UserProfile::findOrFail($arguments[0]));
    } elseif ($job === 'activate') {
        app(InvitationActivationService::class)->finish(proof($arguments[0]), 'synthetic_confirmed_claim');
    } elseif ($job === 'invite') {
        fakeProvider($arguments[1]);
        $result = app(UserInvitationService::class)->initiate(['email' => $arguments[1].'@example.test',
            'display_name' => 'Synthetic replacement', 'role_id' => 3, 'association_id' => $arguments[0]], UserProfile::findOrFail(ADMIN));

        return (string) $result['http_status'];
    } elseif ($job === 'link') {
        DB::transaction(function () use ($arguments) {
            appointment($arguments[0], $arguments[1]);
            DB::select('SELECT pg_sleep(0.2)');
        });
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
                throw new RuntimeException('Could not start disposable worker.');
            }
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
            stream_set_timeout($pipes[1], 20);
            if (trim((string) fgets($pipes[1])) !== 'READY') {
                throw new RuntimeException('Disposable worker did not reach its barrier.');
            }
        }
    } finally {
        DB::select('SELECT pg_advisory_unlock(?)', [$barrier]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $result = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') {
            throw new RuntimeException('Disposable worker failed.');
        }
        $results[] = $result;
    }

    return $results;
}

function rejected(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (QueryException $exception) {
        check(in_array($exception->errorInfo[0] ?? '', ['23505', '23514', '23503', 'P0001'], true), $label);

        return;
    }
    check(false, $label);
}

$worker = getopt('', ['worker:'])['worker'] ?? null;
if ($worker !== null) {
    [$database, $barrier, $job, $arguments] = json_decode(base64_decode($worker, true), true, 8, JSON_THROW_ON_ERROR);
    connection($database);
    application($database);
    echo "READY\n";
    flush();
    DB::select('SELECT pg_advisory_lock_shared(?)', [$barrier]);
    DB::select('SELECT pg_advisory_unlock_shared(?)', [$barrier]);
    try {
        echo operation($job, $arguments);
    } catch (ValidationException|InvitationActivationException $exception) {
        echo 'denied';
    } catch (QueryException $exception) {
        echo $exception->errorInfo[0];
    }
    exit(0);
}

$GLOBALS['checks'] = [];
$database = 'assocmap_replacement_'.bin2hex(random_bytes(6));
$control = connection('postgres');
$control->exec('CREATE DATABASE '.$database);
$result = ['server' => '17.11', 'host' => '127.0.0.1', 'port' => 55439];
try {
    application($database);
    DB::unprepared(file_get_contents(__DIR__.'/association-replacement-base.sql'));
    foreach (['anon', 'authenticated'] as $role) {
        if (! $control->query("SELECT 1 FROM pg_roles WHERE rolname='$role'")->fetchColumn()) {
            $control->exec('CREATE ROLE '.$role.' NOLOGIN');
        }
    }
    DB::transaction(fn () => (require database_path('migrations/2026_10_22_000000_add_account_reactivation.php'))->up());
    profile(1, true, ADMIN);
    $a = association();
    $legacyLink = appointment(profile(), $a);
    $originalLinkFields = ['id', 'user_id', 'association_id', 'is_active', 'linked_at', 'linked_by', 'created_at', 'updated_at'];
    $originalLink = DB::table('association_accounts')->where('id', $legacyLink)->first($originalLinkFields);
    $duplicate = invitation($a);
    invitation($a);
    rejected(fn () => Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION.'.php', '--force' => true]), 'migration rejects conflicting reservations');
    check(DB::table('migrations')->where('migration', MIGRATION)->count() === 0, 'failed migration writes no ledger entry');
    check(! Schema::hasColumn('association_accounts', 'ended_at'), 'failed migration rolls back columns');
    check((int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid='association_accounts'::regclass AND contype='u'") === 2, 'failed migration preserves original uniqueness');
    DB::table('user_invitations')->where('id', $duplicate)->delete();
    $rlsBefore = DB::selectOne("SELECT relrowsecurity, relacl::text FROM pg_class WHERE oid='association_accounts'::regclass");
    Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION.'.php', '--force' => true]);
    check(DB::table('migrations')->where('migration', MIGRATION)->count() === 1, 'Laravel wrapper ledger exactly once');
    Artisan::call('migrate', ['--path' => 'database/migrations/'.MIGRATION.'.php', '--force' => true]);
    DB::transaction(fn () => (require database_path('migrations/'.MIGRATION.'.php'))->up());
    check(DB::table('migrations')->count() === 1, 'ledger and SQL are idempotent');
    check(DB::table('association_accounts')->where('id', $legacyLink)->first($originalLinkFields) == $originalLink, 'migration preserves pre-existing link records');
    check(DB::selectOne("SELECT relrowsecurity, relacl::text FROM pg_class WHERE oid='association_accounts'::regclass") == $rlsBefore, 'existing RLS and table grants preserved');
    check((int) DB::scalar("SELECT count(*) FROM pg_policy WHERE polrelid='association_accounts'::regclass") === 0, 'no client policies introduced');
    check((int) DB::scalar("SELECT count(*) FROM pg_index WHERE indrelid IN ('association_accounts'::regclass,'user_invitations'::regclass) AND NOT indisvalid") === 0, 'all indexes valid');
    check((int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid='association_accounts'::regclass AND NOT convalidated") === 0, 'all constraints validated');
    foreach (['anon', 'authenticated'] as $role) {
        check(! DB::scalar("SELECT has_table_privilege('$role','association_accounts','SELECT')"), $role.' has no link-table access');
    }
    if (! $control->query("SELECT 1 FROM pg_roles WHERE rolname='assocmap_session_reader'")->fetchColumn()) {
        $control->exec('CREATE ROLE assocmap_session_reader NOLOGIN');
    }
    DB::unprepared('GRANT USAGE ON SCHEMA auth TO assocmap_session_reader; GRANT SELECT (id,user_id,created_at) ON auth.sessions TO assocmap_session_reader');
    check(! DB::scalar("SELECT has_column_privilege('assocmap_session_reader','auth.sessions','provider_private_value','SELECT')"), 'private session metadata remains inaccessible');
    $sessionUser = profile();
    $sessionId = uuid();
    DB::table('auth.sessions')->insert(['id' => $sessionId, 'user_id' => $sessionUser, 'created_at' => now()->subSeconds(10)]);
    $sessionProfile = UserProfile::findOrFail($sessionUser);
    $sessionProfile->auth_sessions_valid_after = now()->subMinute();
    $claims = ['sub' => $sessionUser, 'iss' => 'https://auth.example.test/auth/v1', 'role' => 'authenticated',
        'aud' => 'authenticated', 'exp' => now()->addHour()->timestamp, 'session_id' => $sessionId,
        'amr' => [['method' => 'password', 'timestamp' => now()->subSeconds(5)->timestamp]]];
    $token = fn ($claims) => 'synthetic.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.signature';
    $boundary = app(SupabaseSessionBoundary::class);
    $restricted = function (array $claims) use ($sessionProfile, $token, $boundary, $sessionUser) {
        return DB::transaction(function () use ($claims, $sessionProfile, $token, $boundary, $sessionUser) {
            DB::statement('SET LOCAL ROLE assocmap_session_reader');

            return $boundary->allows($sessionProfile, ['id' => $sessionUser], $token($claims));
        });
    };
    check($restricted($claims), 'session boundary works with column-only SELECT permissions');
    check(! $restricted(array_replace($claims, ['exp' => now()->subSecond()->timestamp])), 'expired session evidence denied');
    check(! $restricted(array_replace($claims, ['session_id' => uuid()])), 'missing provider session denied');
    DB::table('auth.sessions')->where('id', $sessionId)->update(['created_at' => now()->subDays(2)]);
    check(! $restricted($claims), 'freshly issued JWT cannot promote an old originating session');
    DB::unprepared('REVOKE SELECT (created_at) ON auth.sessions FROM assocmap_session_reader');
    try {
        $restricted($claims);
        check(false, 'inaccessible session evidence denied');
    } catch (HttpExceptionInterface $exception) {
        check($exception->getStatusCode() === 503, 'inaccessible session evidence fails closed');
    }
    $holder = profile();
    $next = profile();
    $a = association();
    $b = association();
    $oldLink = appointment($holder, $a);
    rejected(fn () => appointment($next, $a), 'one active holder per association');
    rejected(fn () => appointment($holder, $b), 'one active association per holder');
    rejected(fn () => DB::table('association_accounts')->where('id', $oldLink)->update(['is_active' => false]), 'ending requires actor and timestamp');
    rejected(fn () => DB::table('association_accounts')->where('id', $oldLink)->update(['association_id' => $b]), 'relationship identity is immutable');
    rejected(fn () => appointment(profile(3, false), association()), 'unactivated holder rejected');
    rejected(fn () => appointment(profile(2), association()), 'wrong-role holder rejected');
    rejected(fn () => DB::table('association_accounts')->where('id', $oldLink)->update(['is_active' => false, 'ended_at' => now(), 'ended_by' => uuid()]), 'ending actor foreign key enforced');
    invitation($a, 'accepted', $holder);
    foreach (['END ASSOCIATION ACCOUNT LINK', 'REVOKE USER ACCESS'] as $action) {
        DB::statement("SELECT set_config('assocmap.reject_audit', ?, false)", [$action]);
        rejected(fn () => app(UserAccessRevocationService::class)->revoke(actorRequest(), UserProfile::findOrFail($holder)), $action.' failure propagated');
        check(UserProfile::findOrFail($holder)->is_active && DB::table('association_accounts')->where('id', $oldLink)->value('is_active'), $action.' failure rolls back profile and link');
    }
    DB::statement("SELECT set_config('assocmap.reject_audit','',false)");
    check(app(UserAccessRevocationService::class)->revoke(actorRequest(), UserProfile::findOrFail($holder)), 'production revocation ends appointment');
    $history = DB::table('association_accounts')->where('id', $oldLink)->first();
    check(! app(UserAccessRevocationService::class)->revoke(actorRequest(), UserProfile::findOrFail($holder)), 'repeated revocation is idempotent');
    rejected(fn () => DB::table('association_accounts')->where('id', $oldLink)->update(['is_active' => true, 'ended_at' => null, 'ended_by' => null]), 'ended appointment cannot be restored');
    try {
        app(AccountReactivationService::class)->review(UserProfile::findOrFail(ADMIN), UserProfile::findOrFail($holder));
        check(false, 'unlinked holder reactivation denied');
    } catch (ValidationException $exception) {
        check($exception->errors()['reactivation'][0] === AccountReactivationService::ASSOCIATION_APPOINTMENT_REQUIRED, 'unlinked holder requires separate appointment');
    }
    $recipient = uuid();
    check(operation('invite', [$a, $recipient]) === '201', 'production replacement invitation succeeds');
    $id = DB::table('user_invitations')->where('auth_user_id', $recipient)->value('id');
    DB::table('user_invitations')->where('id', $id)->update(['last_error_code' => 'synthetic_confirmed_claim']);
    DB::statement("SELECT set_config('assocmap.reject_audit','ACTIVATE USER ACCOUNT',false)");
    rejected(fn () => operation('activate', [$id]), 'activation audit failure propagated');
    check(! UserProfile::findOrFail($recipient)->is_active && DB::table('association_accounts')->where('association_id', $a)->where('is_active', true)->count() === 0,
        'activation audit failure rolls back profile and replacement link');
    DB::statement("SELECT set_config('assocmap.reject_audit','',false)");
    operation('activate', [$id]);
    operation('activate', [$id]);
    check(DB::table('association_accounts')->where('association_id', $a)->where('is_active', true)->sole()->user_id === $recipient, 'replacement is the sole active holder');
    check(DB::table('association_accounts')->where('id', $oldLink)->first() == $history && ! UserProfile::findOrFail($holder)->is_active, 'former identity and ended history preserved');
    $a = association();
    $u = profile();
    $v = profile();
    $outcomes = parallel($database, [['link', [$u, $a]], ['link', [$v, $a]]]);
    sort($outcomes);
    check($outcomes === ['23505', 'ok'], 'concurrent holders: exactly one winner');
    $u = profile();
    $outcomes = parallel($database, [['link', [$u, association()]], ['link', [$u, association()]]]);
    sort($outcomes);
    check($outcomes === ['23505', 'ok'], 'concurrent account appointments: exactly one winner');
    $a = association();
    $outcomes = parallel($database, [['invite', [$a, uuid()]], ['invite', [$a, uuid()]]]);
    sort($outcomes);
    check($outcomes === ['201', 'denied'], 'competing production invitations: one reservation');
    $u = profile(3, false);
    DB::table('user_profiles')->where('id', $u)->update(['is_active' => false]);
    $id = invitation(association(), 'sent', $u);
    check(parallel($database, [['activate', [$id]], ['activate', [$id]]]) === ['ok', 'ok'], 'duplicate production activation is idempotent');
    check(DB::table('association_accounts')->where('user_id', $u)->count() === 1 && DB::table('audit_logs')->where('entity_id', $id)->count() === 1, 'duplicate activation creates one link and audit');
    check(parallel($database, [['revoke', [$u]], ['revoke', [$u]]]) === ['ok', 'ok'], 'parallel production revocations complete');
    check(DB::table('association_accounts')->where('user_id', $u)->where('is_active', true)->count() === 0 && DB::table('audit_logs')->where('entity_id', $u)->count() === 1, 'parallel revocation creates one revocation event');
    $u = profile(3, false);
    DB::table('user_profiles')->where('id', $u)->update(['is_active' => false]);
    $id = invitation(association(), 'sent', $u);
    $outcomes = parallel($database, [['activate', [$id]], ['revoke', [$u]]]);
    check(count(array_diff($outcomes, ['ok', 'denied'])) === 0 && ! UserProfile::findOrFail($u)->is_active
        && DB::table('association_accounts')->where('user_id', $u)->where('is_active', true)->count() === 0, 'activation racing revocation leaves no authorized holder');
    $result += ['passed' => count($GLOBALS['checks']), 'failed' => 0, 'concurrency_scenarios' => 6, 'checks' => $GLOBALS['checks']];
} catch (Throwable $exception) {
    $result += ['passed' => count($GLOBALS['checks']), 'failed' => 1, 'failure_type' => get_class($exception),
        'failure' => str_starts_with($exception->getMessage(), 'FAILED:') ? $exception->getMessage() : 'Synthetic verification failed; inspect locally.'];
    fwrite(STDERR, get_class($exception).' at line '.$exception->getLine().' code '.$exception->getCode().PHP_EOL);
} finally {
    DB::disconnect();
    $control->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$database' AND pid <> pg_backend_pid()");
    $control->exec('DROP DATABASE '.$database);
    $result['disposable_database_removed'] = true;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['failed'] === 0 ? 0 : 1);
