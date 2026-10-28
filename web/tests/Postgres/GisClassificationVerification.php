<?php

// Standalone disposable PostgreSQL/PostGIS fixture. Only migration 024 is applied.
use App\Models\UserProfile;
use App\Services\GIS\GisLocationService;
use App\Services\GIS\GisPhotoService;
use App\Services\GIS\GisQueryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = getenv('ASSOCMAP_GISCLASS_DIRECTORY');
$password = getenv('ASSOCMAP_GISCLASS_PASSWORD');
$port = getenv('ASSOCMAP_GISCLASS_PORT');
if (! $directory || ! $password || ! ctype_digit($port ?: '') || strlen($password) < 24) {
    throw new RuntimeException('Task-owned cluster settings required.');
}
$pdo = new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;sslmode=disable;connect_timeout=5',
    'assocmap_gisclass_verify', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $pdo->query("SELECT current_user actor, current_setting('data_directory') directory, host(inet_server_addr()) host, inet_server_port() port")->fetch(PDO::FETCH_ASSOC);
if ($identity['actor'] !== 'assocmap_gisclass_verify' || $identity['host'] !== '127.0.0.1'
    || (string) $identity['port'] !== $port
    || strcasecmp(str_replace('\\', '/', $identity['directory']), str_replace('\\', '/', $directory)) !== 0) {
    throw new RuntimeException('Disposable PostgreSQL identity mismatch.');
}
$pdo->exec("SET lock_timeout='5s'; SET statement_timeout='15s';
    CREATE SCHEMA extensions; CREATE EXTENSION postgis WITH SCHEMA extensions; CREATE ROLE anon; CREATE ROLE authenticated;
    CREATE TABLE roles (id bigint PRIMARY KEY, code text, is_active boolean);
    CREATE TABLE user_profiles (id uuid PRIMARY KEY, role_id bigint, is_active boolean, display_name text,
        auth_sessions_valid_after timestamptz, created_at timestamptz, updated_at timestamptz);
    CREATE TABLE user_invitations (auth_user_id uuid, status text, last_error_code text, accepted_at timestamptz,
        last_attempt_at timestamptz, created_at timestamptz);
    CREATE TABLE association_accounts (user_id uuid, association_id uuid, is_active boolean);
    CREATE TABLE municipalities (id uuid PRIMARY KEY, name text, province text, region text);
    CREATE TABLE barangays (id uuid PRIMARY KEY, municipality_id uuid, name text);
    CREATE TABLE areas (id uuid PRIMARY KEY, barangay_id uuid, address text);
    CREATE TABLE associations (id uuid PRIMARY KEY, area_id uuid, status text, name text, primary_field_officer_id uuid,
        archived_at timestamptz);
    CREATE TABLE projects (id uuid PRIMARY KEY, association_id uuid, title text, program_year integer, status text,
        closed_at timestamptz, archived_at timestamptz);
    CREATE TABLE gis_locations (id uuid PRIMARY KEY, association_id uuid, project_id uuid, location_type text, location_name text,
        address text, public_title text, public_description text, public_address text, geom extensions.geometry(Geometry,4326),
        is_published boolean DEFAULT false, published_by uuid, published_at timestamptz, created_by uuid, updated_by uuid,
        archived_by uuid, archived_at timestamptz, created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now());
    CREATE FUNCTION touch_gis_fixture() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.updated_at=clock_timestamp(); RETURN NEW; END \$\$;
    CREATE TRIGGER touch_gis_fixture BEFORE UPDATE ON gis_locations FOR EACH ROW EXECUTE FUNCTION touch_gis_fixture();
    CREATE TABLE audit_logs (id uuid PRIMARY KEY, actor_user_id uuid, action_type text, module text, entity_type text,
        entity_id uuid, old_values jsonb, new_values jsonb, performed_at timestamptz, ip_address text, user_agent text);
    -- Existing gallery metadata fixture, not migration 023 or storage provisioning.
    CREATE TABLE media_photos (id uuid PRIMARY KEY, gis_location_id uuid, state text, approved_by uuid, approved_at timestamptz,
        caption text, display_order integer, display_width integer, display_height integer, thumbnail_width integer, thumbnail_height integer);");

const ADMIN = '11111111-1111-4111-8111-111111111111';
const OFFICER = '22222222-2222-4222-8222-222222222222';
const ACCOUNT = '33333333-3333-4333-8333-333333333333';
const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
const PROJECT = '55555555-5555-4555-8555-555555555555';
const LEGACY = '66666666-6666-4666-8666-666666666666';
$history = file_get_contents(dirname(__DIR__, 3).'/supabase/migrations/014_protect_finalized_project_history.sql');
$start = strpos($history, 'CREATE FUNCTION public.project_history_project_ids');
$definitions = substr($history, $start, strpos($history, 'DO $triggers$') - $start);
$pdo->exec($definitions.'CREATE TRIGGER trg_protect_finalized_project_history AFTER INSERT OR UPDATE OR DELETE
    ON gis_locations FOR EACH ROW EXECUTE FUNCTION public.protect_finalized_project_history()');
$pdo->exec("INSERT INTO roles VALUES (1,'system_admin',true),(2,'field_officer',true),(3,'association_account',true)");
foreach ([ADMIN => 1, OFFICER => 2, ACCOUNT => 3] as $id => $role) {
    $pdo->prepare('INSERT INTO user_profiles(id,role_id,is_active,display_name) VALUES (?,?,true,?)')->execute([$id, $role, 'Synthetic']);
}
$pdo->prepare("INSERT INTO municipalities(id,name) VALUES (?,'Synthetic')")->execute([ASSOCIATION]);
$pdo->prepare("INSERT INTO barangays(id,municipality_id,name) VALUES (?,?,'Synthetic')")->execute([ASSOCIATION, ASSOCIATION]);
$pdo->prepare("INSERT INTO areas(id,barangay_id,address) VALUES (?,?,'Synthetic')")->execute([ASSOCIATION, ASSOCIATION]);
$pdo->prepare("INSERT INTO associations(id,area_id,status,name,primary_field_officer_id) VALUES (?,?,'active','Private association',?)")->execute([ASSOCIATION, ASSOCIATION, OFFICER]);
$pdo->prepare("INSERT INTO projects(id,association_id,status,title,program_year) VALUES (?,?,'ongoing','Private project',2026)")->execute([PROJECT, ASSOCIATION]);
$pdo->prepare('INSERT INTO association_accounts VALUES (?,?,true)')->execute([ACCOUNT, ASSOCIATION]);
$pdo->prepare("INSERT INTO gis_locations(id,project_id,location_type,location_name,public_title,geom) VALUES (?,?,'project_point','Private legacy','Public legacy',extensions.ST_SetSRID(extensions.ST_MakePoint(123.8,10.3),4326))")->execute([LEGACY, PROJECT]);
$legacy = $pdo->query('SELECT row_to_json(g)::text FROM gis_locations g')->fetchColumn();

putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$temporary = dirname($directory);
$app->useEnvironmentPath($temporary.'/no-environment')->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($temporary.'/storage');
if ($app->configurationIsCached()) {
    throw new RuntimeException('Cached config prohibited.');
}
$app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($port, $password) {
    $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'database.default' => 'pgsql', 'database.connections' => ['pgsql' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'postgres',
            'username' => 'assocmap_gisclass_verify', 'password' => $password, 'charset' => 'utf8', 'prefix' => '',
            'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
        ]], 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
        'queue.default' => 'sync', 'logging.default' => 'null', 'associations.geography_schema_ready' => false,
        'associations.direct_geography' => false]);
});
$app->extend(Factory::class, fn ($http) => $http->preventStrayRequests());
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'FAILED: '.$exception::class.' '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(1);
});

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $checks++;
}
function rejected(Closure $work, string $class, ?int $status = null): void
{
    try {
        $work();
        throw new RuntimeException('Expected rejection.');
    } catch (Throwable $exception) {
        check($exception instanceof $class, 'expected '.$class);
        if ($status !== null) {
            check($exception->getStatusCode() === $status, 'expected status '.$status);
        }
    }
}

DB::transaction(fn () => (require database_path('migrations/2026_10_28_000000_add_gis_site_types.php'))->up());
check(DB::table('gis_site_types')->count() === 0, 'migration leaves candidate labels unseeded');
$after = json_decode($pdo->query('SELECT row_to_json(g)::text FROM gis_locations g')->fetchColumn(), true);
check(array_key_exists('site_type_id', $after) && $after['site_type_id'] === null, 'legacy classification nullable');
unset($after['site_type_id']);
check($after === json_decode($legacy, true), 'legacy geometry and metadata preserved byte for byte');
check($pdo->query("SELECT relrowsecurity FROM pg_class WHERE oid='gis_site_types'::regclass")->fetchColumn(), 'catalog RLS enabled');
foreach (['anon', 'authenticated'] as $role) {
    check(! $pdo->query("SELECT has_table_privilege('$role','gis_site_types','SELECT')")->fetchColumn(), 'catalog has no direct '.$role.' access');
}
check((bool) $pdo->query("SELECT to_regclass('idx_gis_locations_site_type') IS NOT NULL")->fetchColumn(), 'classification filter index exists');

// Proposed labels below are synthetic examples only.
foreach (['fish_cage' => 'Fish cage', 'fish_pen' => 'Fish pen', 'seaweed_cultivation_site' => 'Seaweed cultivation site',
    'fishing_gear_site' => 'Fishing gear site', 'post_harvest_facility' => 'Post-harvest facility'] as $code => $name) {
    DB::table('gis_site_types')->insert(['code' => $code, 'name' => $name]);
}
$queries = app(GisQueryService::class);
$locations = app(GisLocationService::class);
$admin = UserProfile::with('role')->findOrFail(ADMIN);
$officer = UserProfile::with('role')->findOrFail(OFFICER);
$request = Request::create('/synthetic-gis-classification');
$payload = ['idempotency_key' => (string) Str::uuid(), 'parent_association_id' => ASSOCIATION, 'project_id' => PROJECT,
    'location_type' => 'project_point', 'location_name' => 'Private name', 'public_title' => 'Public classified',
    'geometry' => ['type' => 'Point', 'coordinates' => [123.85, 10.35]], 'site_type_id' => 1];
$created = $locations->store($payload, $officer, $request);
$id = $created['feature']['id'];
check($created['feature']['properties']['site_type_name'] === 'Fish cage', 'real service create joins classification');
check(! $locations->store($payload, $officer, $request)['created'], 'same classification retry idempotent');
rejected(fn () => $locations->store(array_replace($payload, ['site_type_id' => 2]), $officer, $request), HttpException::class, 409);
check($queries->features(null, ['mode' => 'list'])['features'] === [], 'unpublished classified sites stay private');
$published = $locations->lifecycle($id, 'publish', $created['feature']['properties']['version'], $officer, $request);
$public = $queries->features(null, ['mode' => 'map', 'bbox' => [123, 10, 125, 12], 'site_type_id' => '1']);
check(count($public['features']) === 1 && $public['features'][0]['properties']['site_type_code'] === 'fish_cage', 'public viewport classification filter');
check(! str_contains(json_encode($public), 'Private'), 'public projection hides internal metadata');
check(array_keys($public['features'][0]['properties']) === ['location_type', 'title', 'address', 'description', 'site_type_id', 'site_type_code', 'site_type_name', 'display_point'], 'public contract only additive');
check($queries->features(null, ['site_type_id' => 'unclassified'])['features'] === [], 'unclassified filter excludes classified sites');
check(count($queries->features($admin, ['site_type_id' => 'unclassified'])['features']) === 1, 'legacy unclassified filter');
check($queries->features(null, ['site_type_id' => '999'])['features'] === [], 'unknown filter returns no records');

$version = $published['properties']['version'];
$updated = $locations->update($id, array_replace($payload, ['version' => $version, 'site_type_id' => 2]), $officer, $request);
check($updated['properties']['site_type_name'] === 'Fish pen', 'update consistently joins changed catalog');
rejected(fn () => $locations->update($id, array_replace($payload, ['version' => $version]), $officer, $request), HttpException::class, 409);
$legacyPayload = $payload;
unset($legacyPayload['site_type_id']);
$updated = $locations->update($id, $legacyPayload + ['version' => $updated['properties']['version']], $officer, $request);
check($updated['properties']['site_type_id'] === 2, 'older clients preserve assigned classification');
rejected(fn () => $locations->update($id, array_replace($payload, ['version' => $updated['properties']['version'], 'site_type_id' => 999]), $officer, $request), ValidationException::class);
rejected(fn () => DB::table('gis_locations')->where('id', $id)->update(['site_type_id' => 999]), QueryException::class);
rejected(fn () => DB::table('gis_site_types')->where('id', 2)->delete(), QueryException::class);
$updated = $locations->update($id, array_replace($payload, ['version' => $updated['properties']['version'], 'site_type_id' => null]), $officer, $request);
check($updated['properties']['site_type_id'] === null, 'explicit null clears classification');
check(DB::table('audit_logs')->where('entity_id', $id)->where('action_type', 'UPDATE')->whereRaw("new_values->'site_type_id' = 'null'::jsonb")->exists(), 'classification clearing audited');

foreach (['Polygon' => [[[123, 10], [124, 10], [124, 11], [123, 10]]],
    'MultiPolygon' => [[[[123, 10], [124, 10], [124, 11], [123, 10]]], [[[125, 10], [126, 10], [126, 11], [125, 10]]]]] as $type => $coordinates) {
    $boundary = $locations->store(array_replace($payload, ['idempotency_key' => (string) Str::uuid(), 'location_type' => 'project_boundary',
        'geometry' => ['type' => $type, 'coordinates' => $coordinates], 'site_type_id' => 3]), $admin, $request)['feature'];
    check($boundary['geometry']['type'] === $type && $boundary['geometry']['coordinates'] === $coordinates, 'PostGIS preserves '.$type.' parts');
}

$account = UserProfile::with('role')->findOrFail(ACCOUNT);
check(count($queries->features($account, ['site_type_id' => '3'])['features']) === 2, 'association viewer classification scope');
rejected(fn () => $locations->update($id, $payload + ['version' => $updated['properties']['version']], $account, $request), HttpException::class, 403);
DB::table('associations')->update(['primary_field_officer_id' => ADMIN]);
rejected(fn () => $locations->update($id, $payload + ['version' => $updated['properties']['version']], $officer, $request), HttpException::class, 404);
DB::table('associations')->update(['primary_field_officer_id' => OFFICER]);
DB::table('user_profiles')->where('id', OFFICER)->update(['auth_sessions_valid_after' => now()]);
rejected(fn () => $locations->update($id, $payload + ['version' => $updated['properties']['version']], $officer, $request), HttpException::class, 403);
DB::table('user_profiles')->where('id', OFFICER)->update(['auth_sessions_valid_after' => null]);

$photoId = (string) Str::uuid();
DB::table('media_photos')->insert(['id' => $photoId, 'gis_location_id' => $id, 'state' => 'ready', 'approved_by' => ADMIN,
    'approved_at' => now(), 'display_order' => 0, 'caption' => 'Synthetic approved photo']);
$gallery = app(GisPhotoService::class);
check(count($gallery->gallery($gallery->parent($id), $gallery->publicAuthorization($id), true)['photos']) === 1, 'approved photo gallery survives classification changes');
foreach (['gis_locations' => ['is_published' => false], 'projects' => ['archived_at' => now()], 'associations' => ['status' => 'inactive']] as $table => $change) {
    DB::beginTransaction();
    DB::table($table)->update($change);
    check($queries->features(null, ['site_type_id' => 'unclassified'])['features'] === [], 'public classification filter preserves '.$table.' eligibility');
    rejected(fn () => $gallery->gallery($gallery->parent($id), $gallery->publicAuthorization($id), true), HttpException::class, 404);
    DB::rollBack();
}

DB::enableQueryLog();
DB::flushQueryLog();
$one = $queries->features(null, ['mode' => 'map', 'bbox' => [123, 10, 125, 12]]);
check(count(DB::getQueryLog()) === 1, 'nonempty public map uses one joined query');
DB::disableQueryLog();
for ($index = 0; $index < 101; $index++) {
    DB::statement('INSERT INTO gis_locations SELECT ?, association_id, project_id, location_type, location_name, address,
        public_title, public_description, public_address, geom, is_published, published_by, published_at, created_by, updated_by,
        archived_by, archived_at, created_at, updated_at, site_type_id FROM gis_locations WHERE id = ?', [(string) Str::uuid(), $id]);
}
DB::enableQueryLog();
DB::flushQueryLog();
$many = $queries->features(null, ['mode' => 'map', 'bbox' => [123, 10, 125, 12]]);
check(count($many['features']) === 100 && $many['meta']['truncated'] && $many['meta']['limit'] === 100, 'existing map query limit retained');
check(count(DB::getQueryLog()) === 1, 'many public markers still use one query');
DB::disableQueryLog();
echo 'PASS: '.$checks." independent GIS classification PostgreSQL/PostGIS checks; migration 023 not applied.\n";
