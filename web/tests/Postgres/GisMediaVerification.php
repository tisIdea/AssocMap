<?php

// Synthetic, task-owned PostgreSQL only. Never loads .env or contacts object storage.
use App\Exceptions\MediaLifecycleException;
use App\Models\GisLocation;
use App\Models\MediaPhoto;
use App\Models\MonitoringMaterial;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Media\PhotoLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function connection(string $name = 'gismedia_supervisor'): PDO
{
    $directory = getenv('ASSOCMAP_GISMEDIA_DIRECTORY');
    $password = getenv('ASSOCMAP_GISMEDIA_PASSWORD');
    $port = getenv('ASSOCMAP_GISMEDIA_PORT');
    if (! $directory || ! $password || ! ctype_digit($port ?: '') || strlen($password) < 24) {
        throw new RuntimeException('Task-owned cluster settings are required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;sslmode=disable;connect_timeout=5',
        'assocmap_gismedia_verify', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_user actor, current_setting('data_directory') directory, host(inet_server_addr()) host, inet_server_port() port")->fetch(PDO::FETCH_ASSOC);
    if ($identity['actor'] !== 'assocmap_gismedia_verify' || $identity['host'] !== '127.0.0.1'
        || (string) $identity['port'] !== $port
        || strcasecmp(str_replace('\\', '/', $identity['directory']), str_replace('\\', '/', $directory)) !== 0) {
        throw new RuntimeException('Disposable PostgreSQL identity mismatch.');
    }
    $pdo->exec("SET lock_timeout='8s'; SET statement_timeout='15s'");
    $pdo->prepare("SELECT set_config('application_name', ?, false)")->execute([$name]);

    return $pdo;
}

function application(string $name): void
{
    connection($name);
    putenv('APP_ENV=testing');
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $temporary = dirname(getenv('ASSOCMAP_GISMEDIA_DIRECTORY'));
    $app->useEnvironmentPath($temporary.'/no-environment')->loadEnvironmentFrom('.env.testing');
    $app->useStoragePath($temporary.'/storage');
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached config prohibited.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => getenv('ASSOCMAP_GISMEDIA_PORT'), 'database' => 'postgres',
                'username' => 'assocmap_gismedia_verify', 'password' => getenv('ASSOCMAP_GISMEDIA_PASSWORD'),
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
            ]], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'sync', 'logging.default' => 'null', 'associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
    });
    $app->extend(Factory::class, fn ($http) => $http->preventStrayRequests());
    $app->make(Kernel::class)->bootstrap();
    set_exception_handler(static function (Throwable $exception): never {
        fwrite(STDERR, 'FAILED: '.$exception::class.' '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
        exit(1);
    });
    DB::select("SELECT set_config('application_name', ?, false)", [$name]);
    $audit = Mockery::mock(AuditLogService::class);
    $audit->shouldReceive('record')->andReturn((string) Str::uuid());
    $app->instance(AuditLogService::class, $audit);
}

function startWorker(array $payload): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Worker failed to start.');
    }
    foreach ([1, 2] as $i) {
        stream_set_timeout($pipes[$i], 20);
    }

    return ['process' => $process, 'pipes' => $pipes];
}

function receive(array $worker): string
{
    $line = fgets($worker['pipes'][1]);
    if ($line === false) {
        throw new RuntimeException('Worker failed: '.stream_get_contents($worker['pipes'][2]));
    }

    return trim($line);
}

function finish(array $worker): void
{
    foreach ($worker['pipes'] as $pipe) {
        fclose($pipe);
    }
    if (proc_close($worker['process']) !== 0) {
        throw new RuntimeException('Worker failed.');
    }
}

const ADMIN = '11111111-1111-4111-8111-111111111111';
const OFFICER = '22222222-2222-4222-8222-222222222222';
const REPLACEMENT = '33333333-3333-4333-8333-333333333333';
const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
const PROJECT = '55555555-5555-4555-8555-555555555555';
const LOCATION = '66666666-6666-4666-8666-666666666666';

function requestFor(string $id): Request
{
    $request = Request::create('/synthetic-gis');
    $request->attributes->set('current_user', UserProfile::with('role')->findOrFail($id));
    return $request;
}

function change(string $mode): void
{
    if ($mode === 'revoke') {
        app(\App\Services\Auth\UserAccessRevocationService::class)->revoke(requestFor(ADMIN), UserProfile::findOrFail(OFFICER));
    } elseif ($mode === 'reassign') {
        app(\App\Services\Associations\FieldOfficerAssignmentService::class)->assign(requestFor(ADMIN), UserProfile::findOrFail(REPLACEMENT), [
            'association_ids' => [ASSOCIATION], 'expected_officers' => [ASSOCIATION => OFFICER], 'confirm_reassignment' => '1',
        ]);
    } elseif ($mode === 'cutoff') {
        DB::table('user_profiles')->where('id', OFFICER)->update(['auth_sessions_valid_after' => now()]);
    } elseif ($mode === 'role') {
        DB::table('user_profiles')->where('id', OFFICER)->update(['role_id' => 1]);
    } elseif ($mode === 'unpublish') {
        $version = DB::selectOne('SELECT updated_at::text version FROM gis_locations WHERE id = ?', [LOCATION])->version;
        app(\App\Services\GIS\GisLocationService::class)->lifecycle(LOCATION, 'unpublish', $version,
            UserProfile::with('role')->findOrFail(ADMIN), requestFor(ADMIN));
    } else {
        $gis = app(\App\Services\GIS\GisPhotoService::class);
        app(PhotoLifecycleService::class)->approveGis($mode, false, requestFor(ADMIN), $gis->authorize(requestFor(ADMIN), LOCATION, true));
    }
}

if (($argv[1] ?? '') === 'worker') {
    $payload = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    application($payload['name']);
    try {
        if ($payload['operation'] === 'change') {
            DB::transaction(function () use ($payload) {
                change($payload['mode']);
                echo "LOCKED\n"; flush();
                if (trim(fgets(STDIN)) !== 'release') throw new RuntimeException('Missing release.');
            });
        } else {
            $request = requestFor(OFFICER);
            echo "SNAPSHOT\n"; flush();
            if (trim(fgets(STDIN)) !== 'go') throw new RuntimeException('Missing start.');
            if ($payload['gate'] ?? false) {
                $gate = static function (): void {
                    echo "LOCKED\n"; flush();
                    if (trim(fgets(STDIN)) !== 'release') throw new RuntimeException('Missing release.');
                };
                if ($payload['operation'] === 'photo') {
                    MediaPhoto::creating($gate);
                } else {
                    $gated = false;
                    DB::listen(function ($query) use ($gate, &$gated) {
                        if (! $gated && str_contains($query->sql, 'geometry_json') && str_contains($query->sql, 'for update')) {
                            $gated = true; $gate();
                        }
                    });
                }
            }
            if ($payload['operation'] === 'gis') {
                $version = DB::selectOne('SELECT updated_at::text version FROM gis_locations WHERE id = ?', [LOCATION])->version;
                app(\App\Services\GIS\GisLocationService::class)->lifecycle(LOCATION, 'publish', $version,
                    $request->attributes->get('current_user'), $request);
            } else {
                $gis = app(\App\Services\GIS\GisPhotoService::class);
                app(PhotoLifecycleService::class)->reserve($gis->parent(LOCATION), UploadedFile::fake()->image('site.png', 8, 4),
                    (string) Str::uuid(), $request, $gis->authorize($request, LOCATION, true));
            }
        }
        echo "SAVED\n";
    } catch (MediaLifecycleException $exception) {
        echo 'REJECTED:'.$exception->reason."\n";
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        echo 'DENIED:'.$exception->getStatusCode()."\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class.' '.$exception->getMessage()."\n"); exit(1);
    }
    exit(0);
}

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}

$pdo = connection();
$pdo->exec("CREATE SCHEMA extensions; CREATE EXTENSION postgis WITH SCHEMA extensions; CREATE ROLE anon; CREATE ROLE authenticated;
    CREATE TABLE roles (id bigint PRIMARY KEY, code text, is_active boolean);
    CREATE TABLE user_profiles (id uuid PRIMARY KEY, role_id bigint, is_active boolean, display_name text, activated_at timestamptz,
        auth_sessions_valid_after timestamptz, email_change_hold_id uuid, created_at timestamptz, updated_at timestamptz);
    CREATE TABLE user_invitations (id uuid PRIMARY KEY, auth_user_id uuid, status text, last_error_code text, association_id uuid,
        accepted_at timestamptz, last_attempt_at timestamptz, created_at timestamptz, updated_at timestamptz);
    CREATE TABLE association_accounts (id uuid PRIMARY KEY, user_id uuid, association_id uuid, is_active boolean);
    CREATE TABLE account_reactivations (id uuid PRIMARY KEY, user_id uuid, state text, cancelled_at timestamptz);
    CREATE TABLE municipalities (id uuid PRIMARY KEY, name text, province text, region text);
    CREATE TABLE barangays (id uuid PRIMARY KEY, municipality_id uuid, name text);
    CREATE TABLE areas (id uuid PRIMARY KEY, barangay_id uuid, address text);
    CREATE TABLE associations (id uuid PRIMARY KEY, area_id uuid, status text, name text, primary_field_officer_id uuid,
        archived_at timestamptz, updated_by uuid, created_at timestamptz, updated_at timestamptz);
    CREATE TABLE projects (id uuid PRIMARY KEY, association_id uuid, title text, program_year integer, status text,
        closed_at timestamptz, archived_at timestamptz, created_at timestamptz, updated_at timestamptz);
    CREATE TABLE gis_locations (id uuid PRIMARY KEY, association_id uuid, project_id uuid, location_type text, location_name text,
        address text, public_title text, public_description text, public_address text, geom extensions.geometry,
        is_published boolean DEFAULT false, published_by uuid, published_at timestamptz, created_by uuid, updated_by uuid,
        archived_by uuid, archived_at timestamptz, created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now());
    CREATE TABLE monitoring_visit_reports (id uuid PRIMARY KEY); CREATE TABLE monitoring_materials (id uuid PRIMARY KEY);
    CREATE TABLE audit_logs (id uuid PRIMARY KEY, actor_user_id uuid, action_type text, module text, entity_type text,
        entity_id uuid, old_values jsonb, new_values jsonb, performed_at timestamptz, ip_address text, user_agent text);");
$pdo->beginTransaction();
$pdo->exec(file_get_contents(dirname(__DIR__, 3).'/supabase/migrations/023_add_media_photos.sql'));
$pdo->commit();
$pdo->exec("INSERT INTO roles VALUES (1,'system_admin',true),(2,'field_officer',true)");
foreach ([ADMIN => 1, OFFICER => 2, REPLACEMENT => 2] as $id => $role) {
    $pdo->prepare('INSERT INTO user_profiles(id,role_id,is_active,display_name,activated_at) VALUES (?,?,true,?,now())')->execute([$id, $role, 'Synthetic']);
}
$pdo->prepare("INSERT INTO associations(id,status,name,primary_field_officer_id) VALUES (?,'active','Synthetic',?)")->execute([ASSOCIATION, OFFICER]);
$pdo->prepare("INSERT INTO municipalities(id,name) VALUES (?,'Synthetic')")->execute([ASSOCIATION]);
$pdo->prepare("INSERT INTO barangays(id,municipality_id,name) VALUES (?,?,'Synthetic')")->execute([ASSOCIATION, ASSOCIATION]);
$pdo->prepare("INSERT INTO areas(id,barangay_id,address) VALUES (?,?,'Synthetic')")->execute([ASSOCIATION, ASSOCIATION]);
$pdo->prepare('UPDATE associations SET area_id=?')->execute([ASSOCIATION]);
$pdo->prepare("INSERT INTO projects(id,association_id,status,title,program_year) VALUES (?,?,'ongoing','Synthetic',2026)")->execute([PROJECT, ASSOCIATION]);
$pdo->prepare("INSERT INTO gis_locations(id,project_id,location_type,location_name,public_title,geom) VALUES (?,?,'project_point','Private','Public',extensions.ST_SetSRID(extensions.ST_MakePoint(123.8,10.3),4326))")->execute([LOCATION, PROJECT]);
application('gismedia_supervisor');

function resetFixture(): void
{
    DB::table('media_photos')->delete(); DB::table('audit_logs')->delete();
    DB::table('user_profiles')->where('id', OFFICER)->update(['is_active' => true, 'role_id' => 2, 'auth_sessions_valid_after' => null]);
    DB::table('associations')->update(['primary_field_officer_id' => OFFICER, 'status' => 'active', 'archived_at' => null]);
    DB::table('projects')->update(['status' => 'ongoing', 'closed_at' => null, 'archived_at' => null]);
    DB::table('gis_locations')->update(['is_published' => false, 'published_by' => null, 'published_at' => null, 'archived_at' => null]);
}

function waitBlocked(PDO $pdo, string $name): void
{
    $until = microtime(true) + 8;
    do {
        $statement = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock')");
        $statement->execute([$name]);
        if ($statement->fetchColumn()) return;
        usleep(20000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Worker did not reach the expected lock wait.');
}

// Real services commit revocation/reassignment while a request holds an older actor snapshot.
foreach (['gis', 'photo'] as $operation) {
    foreach (['revoke', 'reassign', 'cutoff', 'role'] as $mode) {
        resetFixture();
        $writer = startWorker(['name' => 'gismedia_writer', 'operation' => $operation]);
        check(receive($writer) === 'SNAPSHOT', 'stale snapshot captured');
        $changer = startWorker(['name' => 'gismedia_changer', 'operation' => 'change', 'mode' => $mode]);
        check(receive($changer) === 'LOCKED', 'change owns locks');
        fwrite($writer['pipes'][0], "go\n");
        waitBlocked($pdo, 'gismedia_writer'); check(true, 'writer waits rather than racing');
        fwrite($changer['pipes'][0], "release\n");
        check(receive($changer) === 'SAVED', 'change committed'); finish($changer);
        $result = receive($writer); finish($writer);
        check($result === ($mode === 'reassign' ? 'DENIED:404' : 'DENIED:403'), $operation.' rejects concurrent '.$mode);
        check(! DB::table('media_photos')->exists() && ! DB::table('gis_locations')->value('is_published'), 'no stale write committed');
    }
}

// Reverse order: an authorized write finishes before revocation or reassignment can commit.
foreach (['gis', 'photo'] as $operation) {
    foreach (['revoke', 'reassign'] as $mode) {
        resetFixture();
        $writer = startWorker(['name' => 'gismedia_writer', 'operation' => $operation, 'gate' => true]);
        check(receive($writer) === 'SNAPSHOT', 'writer actor snapshot captured');
        fwrite($writer['pipes'][0], "go\n"); check(receive($writer) === 'LOCKED', 'writer owns eligibility and parent locks');
        $changer = startWorker(['name' => 'gismedia_changer', 'operation' => 'change', 'mode' => $mode]);
        waitBlocked($pdo, 'gismedia_changer'); check(true, 'change waits for authorized writer');
        fwrite($writer['pipes'][0], "release\n"); check(receive($writer) === 'SAVED', 'writer committed before access change'); finish($writer);
        check(receive($changer) === 'LOCKED', 'change acquired locks after write');
        fwrite($changer['pipes'][0], "release\n"); check(receive($changer) === 'SAVED', 'change committed without deadlock'); finish($changer);
    }
}

resetFixture();
$request = requestFor(ADMIN); $gis = app(\App\Services\GIS\GisPhotoService::class); $photos = app(PhotoLifecycleService::class);
// Competing authorized reservations serialize at the actual GIS parent, admitting only slot five.
$officerRequest = requestFor(OFFICER);
for ($index = 0; $index < 4; $index++) {
    $photos->reserve($gis->parent(LOCATION), UploadedFile::fake()->image('site.png', 8, 4), (string) Str::uuid(),
        $officerRequest, $gis->authorize($officerRequest, LOCATION, true));
}
$first = startWorker(['name' => 'gismedia_first_slot', 'operation' => 'photo', 'gate' => true]);
check(receive($first) === 'SNAPSHOT', 'first slot request captured'); fwrite($first['pipes'][0], "go\n");
check(receive($first) === 'LOCKED', 'fifth slot reservation locked');
$second = startWorker(['name' => 'gismedia_second_slot', 'operation' => 'photo']);
check(receive($second) === 'SNAPSHOT', 'second slot request captured'); fwrite($second['pipes'][0], "go\n");
waitBlocked($pdo, 'gismedia_second_slot');
fwrite($first['pipes'][0], "release\n"); check(receive($first) === 'SAVED', 'fifth slot admitted'); finish($first);
check(receive($second) === 'REJECTED:capacity_reached', 'sixth concurrent slot rejected'); finish($second);
check(MediaPhoto::count() === 5, 'capacity remains five');
resetFixture();
\Illuminate\Support\Facades\Storage::fake('media_private');
$file = UploadedFile::fake()->image('site.png', 64, 32);
$reservation = $photos->reserve($gis->parent(LOCATION), $file, (string) Str::uuid(), $request, $gis->authorize($request, LOCATION, true));
$photo = $photos->upload($reservation['id'], $file, $request, $gis->authorize($request, LOCATION, true));
$photos->approveGis($photo['id'], true, $request, $gis->authorize($request, LOCATION, true));
DB::table('gis_locations')->update(['is_published' => true, 'published_by' => ADMIN, 'published_at' => now()]);
check(count($gis->gallery($gis->parent(LOCATION), $gis->publicAuthorization(LOCATION), true)['photos']) === 1, 'full PostGIS public policy admits approved image');
DB::beginTransaction();
DB::table('projects')->update(['status' => 'completed', 'closed_at' => now()]);
check(count($gis->gallery($gis->parent(LOCATION), $gis->publicAuthorization(LOCATION), true)['photos']) === 1, 'finalized project retains existing public GIS eligibility');
DB::rollBack();
foreach (["UPDATE gis_locations SET geom=NULL", "UPDATE gis_locations SET public_title=' '",
    "UPDATE gis_locations SET published_by=NULL", "UPDATE projects SET archived_at=now()", "UPDATE associations SET status='inactive'"] as $sql) {
    DB::beginTransaction(); DB::statement($sql);
    try { $gis->gallery($gis->parent(LOCATION), $gis->publicAuthorization(LOCATION), true); throw new RuntimeException('Ineligible parent accepted.'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode() === 404, 'full public policy rejects invalid parent'); }
    finally { DB::rollBack(); }
}

// Changes commit on a separate connection during the unlocked storage read.
foreach (['unpublish', $photo['id']] as $mode) {
    DB::table('gis_locations')->update(['is_published' => true]);
    $photos->approveGis($photo['id'], true, $request, $gis->authorize($request, LOCATION, true));
    $disk = \Illuminate\Support\Facades\Storage::disk('media_private'); $mock = Mockery::mock($disk);
    $mock->shouldReceive('readStream')->once()->andReturnUsing(function ($key) use ($disk, $mode, $pdo) {
        check(DB::transactionLevel() === 0, 'storage read holds no database locks');
        $changer = startWorker(['name' => 'gismedia_public_change', 'operation' => 'change', 'mode' => $mode]);
        check(receive($changer) === 'LOCKED', 'publication or approval change acquired locks');
        fwrite($changer['pipes'][0], "release\n"); check(receive($changer) === 'SAVED', 'public access change committed'); finish($changer);
        return $disk->readStream($key);
    });
    \Illuminate\Support\Facades\Storage::set('media_private', $mock);
    try { $photos->open($photo['id'], 'thumbnail', $gis->publicAuthorization(LOCATION, $photo['id'])); throw new RuntimeException('Revoked public read accepted.'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode() === 404, 'delivery denies concurrent public withdrawal'); }
    finally { \Illuminate\Support\Facades\Storage::set('media_private', $disk); }
}
DB::table('gis_locations')->update(['is_published' => true]);
$photos->approveGis($photo['id'], true, $request, $gis->authorize($request, LOCATION, true));
$publisher = startWorker(['name' => 'gismedia_publisher', 'operation' => 'change', 'mode' => 'unpublish']);
check(receive($publisher) === 'LOCKED', 'publication owns parent fence');
$approver = startWorker(['name' => 'gismedia_approver', 'operation' => 'change', 'mode' => $photo['id']]);
waitBlocked($pdo, 'gismedia_approver'); check(true, 'approval waits for concurrent publication');
fwrite($publisher['pipes'][0], "release\n"); check(receive($publisher) === 'SAVED', 'publication committed'); finish($publisher);
check(receive($approver) === 'LOCKED', 'approval acquired parent fence');
fwrite($approver['pipes'][0], "release\n"); check(receive($approver) === 'SAVED', 'approval committed'); finish($approver);
check(! DB::table('gis_locations')->value('is_published') && MediaPhoto::findOrFail($photo['id'])->approved_at === null,
    'concurrent publication/approval changes retained without deadlock');

echo 'PASS: '.$checks." GIS authorization/public-delivery PostgreSQL checks.\n";

