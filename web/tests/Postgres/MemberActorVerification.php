<?php

// Standalone synthetic verification against a task-owned loopback cluster. Never loads .env.
use App\Models\Association;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Services\Auth\AccountEmailChangeService;
use App\Services\Auth\UserAccessRevocationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MemberMutationFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function nativeConnection(string $database, string $name = 'sec2_supervisor'): PDO
{
    if ($database !== 'postgres' && ! preg_match('/\Aassocmap_sec2_[a-f0-9]{12}\z/D', $database)) {
        throw new RuntimeException('A disposable SEC-2 database is required.');
    }
    $directory = getenv('ASSOCMAP_SEC2_DIRECTORY');
    $password = getenv('ASSOCMAP_SEC2_PASSWORD');
    if (! is_string($directory) || ! is_string($password) || strlen($password) < 24) {
        throw new RuntimeException('Task-owned cluster identity and credentials are required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port=55444;dbname='.$database.';sslmode=disable;connect_timeout=5',
        'assocmap_sec2_verify', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() db, current_user actor, current_setting('server_version') version, current_setting('data_directory') directory,
        host(inet_server_addr()) host, inet_server_port() port")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $database || $identity['actor'] !== 'assocmap_sec2_verify'
        || ! str_starts_with($identity['version'], '17.11')
        || $identity['host'] !== '127.0.0.1' || (int) $identity['port'] !== 55444
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
    $app->useEnvironmentPath(dirname(getenv('ASSOCMAP_SEC2_DIRECTORY')).'/no-environment')->loadEnvironmentFrom('.env.testing');
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached application configuration is prohibited.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($database) {
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55444, 'database' => $database,
                'username' => 'assocmap_sec2_verify', 'password' => getenv('ASSOCMAP_SEC2_PASSWORD'),
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
            ]], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'sync', 'logging.default' => 'null', 'services.supabase.account_email_change_enabled' => true]);
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

function fixture(): object
{
    return new class
    {
        use MemberMutationFixture;
    };
}

function change(PDO $pdo, string $role, string $state): void
{
    $actorId = match ($role) {
        'admin' => fixture()::ADMIN, 'officer' => fixture()::OFFICER, 'account' => fixture()::ACCOUNT
    };
    [$sql, $bindings] = match ($state) {
        'revoked' => ['UPDATE user_profiles SET is_active=false WHERE id=?', [$actorId]],
        'inactive_role' => ['UPDATE roles SET is_active=false WHERE code=?', [match ($role) {
            'admin' => 'system_admin', 'officer' => 'field_officer', 'account' => 'association_account',
        }]],
        'changed_role' => ['UPDATE user_profiles SET role_id=? WHERE id=?', [$role === 'admin' ? 2 : 1, $actorId]],
        'cutoff' => ['UPDATE user_profiles SET auth_sessions_valid_after=now() WHERE id=?', [$actorId]],
        'email_hold' => ['UPDATE user_profiles SET email_change_hold_id=? WHERE id=?', [fixture()::APPLICATION, $actorId]],
        'reassigned' => ['UPDATE associations SET primary_field_officer_id=? WHERE id=?', [fixture()::OTHER_OFFICER, fixture()::ASSOCIATION]],
        'archived' => ['UPDATE associations SET archived_at=now() WHERE id=?', [fixture()::ASSOCIATION]],
        'inactive' => ["UPDATE associations SET status='inactive' WHERE id=?", [fixture()::ASSOCIATION]],
        'unlinked' => ['UPDATE association_accounts SET is_active=false WHERE id=?', [fixture()::LINK]],
    };
    $pdo->prepare($sql)->execute($bindings);
}

function resetFixture(object $fixture, string $operation): array
{
    $fixture->sec2Fixtures();
    DB::table('user_profiles')->update(['activated_at' => '2026-01-01 00:00:00']);

    return $fixture->sec2Context($operation);
}

function context(string $operation): array
{
    $member = Member::findOrFail(fixture()::CANDIDATE);
    $data = ['member_record_id' => $member->id, 'expected_updated_at' => $member->updated_at->format('Y-m-d\TH:i:s.uP'),
        ...($operation === 'update' ? ['first_name' => 'Changed'] : ['confirm_change' => '1'])];
    if (in_array($operation, ['assign', 'replace', 'remove'], true)) {
        $data = ['action' => $operation, 'member_id' => $member->id, 'position_id' => 2,
            'expected_position_id' => $member->position_id, 'expected_updated_at' => $data['expected_updated_at'], 'confirm_change' => '1'];
    }
    if ($operation === 'replace') {
        $former = Member::findOrFail(fixture()::FORMER);
        $data += ['former_member_id' => $former->id, 'expected_former_position_id' => $former->position_id,
            'expected_former_updated_at' => $former->updated_at->format('Y-m-d\TH:i:s.uP')];
    }

    return ['member' => $member, 'application' => MemberApplication::findOrFail(fixture()::APPLICATION),
        'association' => Association::findOrFail(fixture()::ASSOCIATION), 'data' => $data];
}

$GLOBALS['checks'] = 0;
if (($argv[1] ?? '') === 'worker') {
    [, , $database, $job, $operation, $role, $state] = $argv;
    nativeApplication($database, $job);
    $fixture = fixture();
    if ($job === 'sec2_lifecycle') {
        signal('STARTED');
        change(nativeConnection($database, $job), $role, $state);
        signal('APPLIED');
        exit;
    }
    if ($job === 'sec2_email') {
        $actor = $fixture->sec2Actor('admin');
        $target = $fixture->sec2Actor($role);
        $associationLocked = false;
        $paused = false;
        DB::listen(function ($query) use (&$associationLocked) {
            if (str_contains($query->sql, 'from "associations"') && str_ends_with($query->sql, 'for update')) {
                $associationLocked = true;
            }
        });
        DB::connection()->beforeExecuting(function ($sql, $bindings) use ($target, &$associationLocked, &$paused) {
            if (! $paused && str_contains($sql, 'from "user_profiles"') && str_ends_with($sql, 'for update')
                && in_array($target->id, $bindings, true)) {
                $paused = true;
                signal('BEFORE_TARGET:'.(int) $associationLocked);
                fgets(STDIN);
            }
        });
        signal('READY');
        fgets(STDIN);
        app(AccountEmailChangeService::class)->review($actor, $target, 'new-synthetic@example.invalid');
        signal('REVIEWED');
        exit;
    }
    if ($job === 'sec2_revoke') {
        $actor = $fixture->sec2Actor('admin');
        $target = $fixture->sec2Actor('account');
        $request = new Request;
        $request->attributes->set('current_user', $actor);
        signal('READY');
        fgets(STDIN);
        DB::transaction(function () use ($request, $target, $state) {
            app(UserAccessRevocationService::class)->revoke($request, $target);
            if ($state === 'first') {
                signal('LOCKED');
                fgets(STDIN);
            }
        });
        signal('REVOKED');
        exit;
    }
    $actor = $fixture->sec2Actor($role);
    $context = context($operation);
    signal('READY');
    fgets(STDIN);
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (preg_match('/from "(roles|user_profiles|associations|members|member_applications|association_accounts|association_positions)".*for (?:share|update)$/', $query->sql, $match)) {
            $locks[] = $match[1];
            if ($match[1] === 'members' && str_contains($query->sql, ' in (')) {
                check(str_contains($query->sql, 'order by "id" asc for update'), 'multiple member locks are sorted');
            }
        }
    });
    try {
        DB::transaction(function () use ($fixture, $operation, $actor, $context, $job, &$locks) {
            $fixture->sec2Mutate($operation, $actor, $context);
            if ($job === 'sec2_mutation_first') {
                $expected = $operation === 'submit'
                    ? ['roles', 'associations', 'user_profiles', 'association_accounts']
                    : ['roles', 'user_profiles', 'associations', in_array($operation, ['approve', 'reject'], true) ? 'member_applications' : 'members'];
                check(array_slice($locks, 0, 4) === $expected, 'native lock order: '.json_encode($locks));
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

$database = 'assocmap_sec2_'.bin2hex(random_bytes(6));
$supervisor = nativeConnection('postgres');
$supervisor->exec('CREATE DATABASE '.$database);
$workers = [];
try {
    nativeApplication($database, 'sec2_supervisor');
    $fixture = fixture();
    $fixture->sec2Schema();
    Schema::table('user_profiles', function ($table) {
        $table->timestampTz('activated_at')->nullable();
        $table->string('approved_email')->nullable();
    });
    Schema::table('association_accounts', function ($table) {
        $table->timestampTz('ended_at')->nullable();
        $table->uuid('ended_by')->nullable();
    });
    Schema::table('user_invitations', function ($table) {
        $table->uuid('association_id')->nullable();
    });
    DB::statement('CREATE SCHEMA auth');
    DB::statement('CREATE TABLE auth.users (id uuid PRIMARY KEY, email text NOT NULL, email_change text)');
    DB::statement('CREATE TABLE account_reactivations (id uuid PRIMARY KEY, user_id uuid, state text, cancelled_at timestamp)');
    DB::statement('CREATE TABLE email_change_requests (id uuid PRIMARY KEY, user_id uuid, status text, new_email text)');
    $control = nativeConnection($database);
    $scenarios = 0;
    foreach ($fixture::sec2Operations() as [$operation, $role]) {
        if (($argv[1] ?? '') === 'extended') {
            break;
        }
        $states = ['revoked', 'inactive_role', 'changed_role', 'cutoff', 'email_hold', 'archived', 'inactive'];
        if ($role === 'officer') {
            $states[] = 'reassigned';
        }
        if ($role === 'account') {
            $states[] = 'unlinked';
        }
        foreach ($states as $state) {
            resetFixture($fixture, $operation);
            $before = $fixture->sec2State();
            $workers[] = $mutation = launch($database, 'sec2_lifecycle_first', $operation, $role, $state);
            check(receive($mutation) === 'READY', 'authentication precedes lifecycle change');
            $control->beginTransaction();
            change($control, $role, $state);
            fwrite($mutation['pipes'][0], "GO\n");
            waitForLock($control, 'sec2_lifecycle_first');
            $control->commit();
            $status = in_array($state, ['archived', 'inactive', 'unlinked'], true)
                || ($state === 'reassigned' && in_array($operation, ['approve', 'reject'], true)) ? 422 : 403;
            check(receive($mutation) === 'DENIED:'.$status, $operation.' '.$role.' '.$state.' lifecycle wins');
            finish($mutation);
            array_pop($workers);
            check($before == $fixture->sec2State(), 'denial preserves all business, classification and audit state');
            $scenarios++;

            resetFixture($fixture, $operation);
            $before = $fixture->sec2State();
            $workers[] = $mutation = launch($database, 'sec2_mutation_first', $operation, $role, $state);
            check(receive($mutation) === 'READY', 'mutation actor initially eligible');
            fwrite($mutation['pipes'][0], "GO\n");
            check(receive($mutation) === 'LOCKED', 'mutation holds locks in the required order');
            $workers[] = $lifecycle = launch($database, 'sec2_lifecycle', $operation, $role, $state);
            check(receive($lifecycle) === 'STARTED', 'conflicting lifecycle starts');
            waitForLock($control, 'sec2_lifecycle');
            fwrite($mutation['pipes'][0], "COMMIT\n");
            check(receive($mutation) === 'SAVED', 'authorized mutation commits first');
            check(receive($lifecycle) === 'APPLIED', 'lifecycle proceeds after commit');
            finish($lifecycle);
            array_pop($workers);
            finish($mutation);
            array_pop($workers);
            check($before != $fixture->sec2State(), 'authorized mutation persisted');
            $scenarios++;
        }
        signal('PASS '.$operation.' '.$role);
    }
    foreach (['officer' => 'update', 'account' => 'submit'] as $role => $operation) {
        resetFixture($fixture, $operation);
        DB::table('auth.users')->delete();
        foreach ([fixture()::ADMIN, fixture()::OFFICER, fixture()::ACCOUNT] as $id) {
            DB::table('auth.users')->insert(['id' => $id, 'email' => $id.'@example.invalid']);
        }
        $workers[] = $email = launch($database, 'sec2_email', $operation, $role, 'none');
        check(receive($email) === 'READY', 'production email review initially eligible');
        fwrite($email['pipes'][0], "GO\n");
        check(receive($email) === 'BEFORE_TARGET:'.($role === 'account' ? '1' : '0'), 'email review preserves staff/account lock order');
        $workers[] = $mutation = launch($database, 'sec2_mutation_first', $operation, $role, 'none');
        check(receive($mutation) === 'READY', 'mutation authenticated during email review');
        fwrite($mutation['pipes'][0], "GO\n");
        if ($role === 'officer') {
            check(receive($mutation) === 'LOCKED', 'staff mutation holds profile and association first');
            fwrite($email['pipes'][0], "GO\n");
            waitForLock($control, 'sec2_email');
            fwrite($mutation['pipes'][0], "COMMIT\n");
            check(receive($mutation) === 'SAVED', 'staff mutation commits without deadlock');
            check(receive($email) === 'REVIEWED', 'staff email review proceeds after profile release');
        } else {
            waitForLock($control, 'sec2_mutation_first');
            fwrite($email['pipes'][0], "GO\n");
            check(receive($email) === 'REVIEWED', 'account email review completes while submission waits on association');
            check(receive($mutation) === 'LOCKED', 'account submission proceeds after association release');
            fwrite($mutation['pipes'][0], "COMMIT\n");
            check(receive($mutation) === 'SAVED', 'account submission commits without deadlock');
        }
        finish($mutation);
        array_pop($workers);
        finish($email);
        array_pop($workers);
        $scenarios++;
    }
    foreach ([['approve', 'approve', 'officer', 'officer'], ['approve', 'reject', 'officer', 'officer'],
        ['reject', 'approve', 'officer', 'officer'], ['reject', 'reject', 'officer', 'officer'],
        ['replace', 'replace', 'admin', 'officer'], ['replace', 'replace', 'officer', 'admin'],
        ['assign', 'approve', 'admin', 'officer'], ['approve', 'assign', 'officer', 'admin']] as [$first, $second, $firstRole, $secondRole]) {
        resetFixture($fixture, $first);
        $workers[] = $winner = launch($database, 'sec2_mutation_first', $first, $firstRole, 'none');
        check(receive($winner) === 'READY', 'first decision loaded');
        fwrite($winner['pipes'][0], "GO\n");
        check(receive($winner) === 'LOCKED', 'first decision holds ordered locks');
        $workers[] = $loser = launch($database, 'sec2_lifecycle_first', $second, $secondRole, 'none');
        check(receive($loser) === 'READY', 'competing decision loaded before first commit');
        fwrite($loser['pipes'][0], "GO\n");
        waitForLock($control, 'sec2_lifecycle_first');
        fwrite($winner['pipes'][0], "COMMIT\n");
        check(receive($winner) === 'SAVED', 'first decision commits');
        check(receive($loser) === 'DENIED:409', 'duplicate, replacement or executive conflict rejected');
        finish($loser);
        array_pop($workers);
        finish($winner);
        array_pop($workers);
        $audits = $first === 'replace' ? 2 : ($first === 'reject' ? 0 : 1);
        check(DB::table('audit_logs')->count() === $audits, 'only winner audit events persisted');
        check(DB::table('members')->where('position_id', 2)->where('member_status', 'active')->whereNull('archived_at')->count()
            === ($first === 'reject' ? 0 : 1), 'executive uniqueness and rejected decision preserved');
        check(DB::table('members')->where('source_application_id', fixture()::APPLICATION)->count()
            === ($first === 'approve' ? 1 : 0), 'application admitted at most once');
        $scenarios++;
    }
    foreach (['first', 'second'] as $order) {
        resetFixture($fixture, 'submit');
        $workers[] = $mutation = launch($database, $order === 'first' ? 'sec2_lifecycle_first' : 'sec2_mutation_first', 'submit', 'account', 'none');
        check(receive($mutation) === 'READY', 'submission authenticates before revocation');
        $workers[] = $revocation = launch($database, 'sec2_revoke', 'submit', 'account', $order);
        check(receive($revocation) === 'READY', 'production revocation authorized');
        if ($order === 'first') {
            fwrite($revocation['pipes'][0], "GO\n");
            check(receive($revocation) === 'LOCKED', 'production revocation holds association, target profile and link');
            fwrite($mutation['pipes'][0], "GO\n");
            waitForLock($control, 'sec2_lifecycle_first');
            fwrite($revocation['pipes'][0], "COMMIT\n");
            check(receive($revocation) === 'REVOKED', 'revocation commits first');
            check(receive($mutation) === 'DENIED:403', 'in-flight submission denied after revocation');
        } else {
            fwrite($mutation['pipes'][0], "GO\n");
            check(receive($mutation) === 'LOCKED', 'submission holds association before account profile');
            fwrite($revocation['pipes'][0], "GO\n");
            waitForLock($control, 'sec2_revoke');
            fwrite($mutation['pipes'][0], "COMMIT\n");
            check(receive($mutation) === 'SAVED', 'authorized submission commits first');
            check(receive($revocation) === 'REVOKED', 'revocation proceeds without deadlock');
        }
        finish($revocation);
        array_pop($workers);
        finish($mutation);
        array_pop($workers);
        check(DB::table('member_applications')->count() === ($order === 'first' ? 1 : 2), 'only authorized submission persisted');
        check(DB::table('audit_logs')->count() === 2, 'revocation and unlink audits persisted atomically');
        check(! DB::table('association_accounts')->where('id', fixture()::LINK)->value('is_active'), 'replacement can end the former account link');
        $scenarios++;
    }
    signal('PASS production email review and revocation, competing decisions, replacements and executive conflicts');
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
