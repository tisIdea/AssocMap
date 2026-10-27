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

function connection(string $name = 'media2_supervisor'): PDO
{
    $directory = getenv('ASSOCMAP_MEDIA2_DIRECTORY');
    $password = getenv('ASSOCMAP_MEDIA2_PASSWORD');
    $port = getenv('ASSOCMAP_MEDIA2_PORT');
    if (! $directory || ! $password || ! ctype_digit($port ?: '') || strlen($password) < 24) {
        throw new RuntimeException('Task-owned cluster settings are required.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;sslmode=disable;connect_timeout=5',
        'assocmap_media2_verify', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_user actor, current_setting('data_directory') directory, host(inet_server_addr()) host, inet_server_port() port")->fetch(PDO::FETCH_ASSOC);
    if ($identity['actor'] !== 'assocmap_media2_verify' || $identity['host'] !== '127.0.0.1'
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
    $temporary = dirname(getenv('ASSOCMAP_MEDIA2_DIRECTORY'));
    $app->useEnvironmentPath($temporary.'/no-environment')->loadEnvironmentFrom('.env.testing');
    $app->useStoragePath($temporary.'/storage');
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached config prohibited.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => getenv('ASSOCMAP_MEDIA2_PORT'), 'database' => 'postgres',
                'username' => 'assocmap_media2_verify', 'password' => getenv('ASSOCMAP_MEDIA2_PASSWORD'),
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
            ]], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'sync', 'logging.default' => 'null']);
    });
    $app->extend(Factory::class, fn ($http) => $http->preventStrayRequests());
    $app->make(Kernel::class)->bootstrap();
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

if (($argv[1] ?? '') === 'worker') {
    $payload = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    application($payload['name']);
    $class = $payload['class'];
    $parent = new $class;
    $parent->setRawAttributes(['id' => $payload['parent']]);
    $actor = new UserProfile;
    $actor->setRawAttributes(['id' => $payload['actor']]);
    $actor->exists = true;
    $request = Request::create('/synthetic-media');
    $request->attributes->set('current_user', $actor);
    // Authorization is synthetic here; prove the foundation's own parent lock.
    $authorize = fn ($parent) => $parent->newQuery()->whereKey($parent->id)->firstOrFail();
    if ($payload['gate']) {
        MediaPhoto::creating(function () {
            echo "LOCKED\n";
            flush();
            if (trim(fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Missing release.');
            }
        });
    }

    try {
        $photo = app(PhotoLifecycleService::class)->reserve($parent, UploadedFile::fake()->image('synthetic.png', 8, 4),
            $payload['request'], $request, $authorize, $payload['replaces']);
        echo 'RESERVED:'.$photo['id']."\n";
    } catch (MediaLifecycleException $exception) {
        echo 'REJECTED:'.$exception->reason."\n";
    }
    exit(0);
}

$pdo = connection();
$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $checks++;
}
$pdo->exec('CREATE ROLE anon; CREATE ROLE authenticated');
foreach (['projects', 'gis_locations', 'monitoring_visit_reports', 'monitoring_materials', 'user_profiles'] as $table) {
    $pdo->exec('CREATE TABLE '.$table.' (id uuid PRIMARY KEY)');
}
$pdo->beginTransaction();
$pdo->exec(file_get_contents(dirname(__DIR__, 3).'/supabase/migrations/023_add_media_photos.sql'));
$pdo->commit();
$actor = (string) Str::uuid();
$pdo->prepare('INSERT INTO user_profiles(id) VALUES (?)')->execute([$actor]);
$types = [[Project::class, 'projects', 'project_id', 1], [GisLocation::class, 'gis_locations', 'gis_location_id', 5],
    [MonitoringVisitReport::class, 'monitoring_visit_reports', 'monitoring_visit_report_id', 5],
    [MonitoringMaterial::class, 'monitoring_materials', 'monitoring_material_id', 5]];
foreach ($types as [$class, $table, $column, $limit]) {
    $parent = (string) Str::uuid();
    $pdo->prepare('INSERT INTO '.$table.'(id) VALUES (?)')->execute([$parent]);
    for ($i = 1; $i < $limit; $i++) {
        $pdo->prepare('INSERT INTO media_photos (id,'.$column.',uploaded_by,request_id,object_prefix,input_sha256) VALUES (?,?,?,?,?,?)')
            ->execute([(string) Str::uuid(), $parent, $actor, (string) Str::uuid(), 'media/'.Str::uuid(), str_repeat('a', 64)]);
    }
    $payload = ['class' => $class, 'parent' => $parent, 'actor' => $actor, 'request' => (string) Str::uuid(), 'replaces' => null];
    $a = startWorker($payload + ['name' => 'media2_first', 'gate' => true]);
    check(receive($a) === 'LOCKED', 'first owns parent lock');
    $b = startWorker(array_replace($payload, ['request' => (string) Str::uuid(), 'name' => 'media2_second', 'gate' => false]));
    $blocked = false;
    for ($i = 0; $i < 100; $i++) {
        $blocked = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='media2_second' AND wait_event_type='Lock')")->fetchColumn();
        if ($blocked) {
            break;
        }
        usleep(20000);
    }
    check($blocked, 'second waits for actual PostgreSQL row lock');
    fwrite($a['pipes'][0], "release\n");
    fflush($a['pipes'][0]);
    check(str_starts_with(receive($a), 'RESERVED:'), 'one upload reserves final slot');
    check(receive($b) === 'REJECTED:capacity_reached', 'competing upload rejected at capacity');
    finish($a);
    finish($b);
    check((int) $pdo->query('SELECT count(*) FROM media_photos WHERE '.$column."='".$parent."'")->fetchColumn() === $limit, 'no excess reservations');
}
// Two cover replacements: old ready cover persists and only one pending replacement is permitted.
$parent = (string) Str::uuid();
$old = (string) Str::uuid();
$pdo->prepare('INSERT INTO projects(id) VALUES (?)')->execute([$parent]);
$pdo->prepare("INSERT INTO media_photos (id,project_id,uploaded_by,request_id,object_prefix,input_sha256,state,image_format,
    display_width,display_height,display_bytes,display_sha256,thumbnail_width,thumbnail_height,thumbnail_bytes,thumbnail_sha256)
    VALUES (?,?,?,?,?,?,'ready','png',8,4,100,?,8,4,100,?)")
    ->execute([$old, $parent, $actor, (string) Str::uuid(), 'media/'.Str::uuid(), str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)]);
$payload = ['class' => Project::class, 'parent' => $parent, 'actor' => $actor, 'request' => (string) Str::uuid(), 'replaces' => $old];
$a = startWorker($payload + ['name' => 'media2_first', 'gate' => true]);
check(receive($a) === 'LOCKED', 'replacement owns parent lock');
$b = startWorker(array_replace($payload, ['request' => (string) Str::uuid(), 'name' => 'media2_second', 'gate' => false]));
$blocked = false;
for ($i = 0; $i < 100; $i++) {
    $blocked = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='media2_second' AND wait_event_type='Lock')")->fetchColumn();
    if ($blocked) {
        break;
    }
    usleep(20000);
}
check($blocked, 'replacement contention observed');
fwrite($a['pipes'][0], "release\n");
fflush($a['pipes'][0]);
check(str_starts_with(receive($a), 'RESERVED:'), 'first replacement reserved');
check(receive($b) === 'REJECTED:capacity_reached', 'second replacement rejected');
finish($a);
finish($b);
check($pdo->query("SELECT state FROM media_photos WHERE id='".$old."'")->fetchColumn() === 'ready', 'old cover remains active');

// Simultaneous repeated requests produce one reservation, not two.
$parent = (string) Str::uuid();
$pdo->prepare('INSERT INTO gis_locations(id) VALUES (?)')->execute([$parent]);
$payload = ['class' => GisLocation::class, 'parent' => $parent, 'actor' => $actor,
    'request' => (string) Str::uuid(), 'replaces' => null];
$a = startWorker($payload + ['name' => 'media2_first', 'gate' => true]);
check(receive($a) === 'LOCKED', 'idempotent first request owns parent');
$b = startWorker($payload + ['name' => 'media2_second', 'gate' => false]);
$blocked = false;
for ($i = 0; $i < 100; $i++) {
    $blocked = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='media2_second' AND wait_event_type='Lock')")->fetchColumn();
    if ($blocked) {
        break;
    }
    usleep(20000);
}
check($blocked, 'repeated request contention observed');
fwrite($a['pipes'][0], "release\n");
fflush($a['pipes'][0]);
$first = receive($a);
check(str_starts_with($first, 'RESERVED:'), 'idempotent first reservation');
check(receive($b) === $first, 'repeated request returns identical reservation');
finish($a);
finish($b);
check((int) $pdo->query("SELECT count(*) FROM media_photos WHERE gis_location_id='".$parent."'")->fetchColumn() === 1, 'one idempotent metadata row');

// Native constraints and RLS catalog; the same canonical SQL was applied.
foreach (["UPDATE media_photos SET disk='public' WHERE id='".$old."'",
    "UPDATE media_photos SET project_id=NULL WHERE id='".$old."'",
    "UPDATE media_photos SET uploaded_by='".Str::uuid()."' WHERE id='".$old."'",
    "UPDATE media_photos SET display_width=99999 WHERE id='".$old."'",
    "UPDATE media_photos SET approved_by='".$actor."',approved_at=NOW() WHERE id='".$old."'"] as $sql) {
    try {
        $pdo->exec($sql);
        check(false, 'invalid metadata rejected');
    } catch (PDOException $exception) {
        check(in_array($exception->getCode(), ['23503', '23514'], true), 'native FK/CHECK constraint');
    }
}
check((bool) $pdo->query("SELECT relrowsecurity FROM pg_class WHERE oid='public.media_photos'::regclass")->fetchColumn(), 'RLS enabled');
check(! (bool) $pdo->query("SELECT has_table_privilege('anon','public.media_photos','SELECT')")->fetchColumn(), 'anonymous access denied');
echo 'PASS: '.$checks." native PostgreSQL checks; real parent-lock contention, cover replacement, FK/CHECK constraints and RLS.\n";
