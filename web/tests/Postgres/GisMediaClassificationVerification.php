<?php

// Full canonical prerequisite schema; 020-024 run only through Laravel's migrator.
use App\Models\GisLocation;
use App\Models\MediaPhoto;
use App\Models\MonitoringMaterial;
use App\Models\MonitoringRecord;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\GIS\GisLocationService;
use App\Services\GIS\GisPhotoService;
use App\Services\GIS\GisQueryService;
use App\Services\Media\PhotoLifecycleService;
use App\Services\Monitoring\MonitoringPhotoService;
use App\Services\Projects\ProjectCoverService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'FAIL: '.$error::class.' '.$error->getMessage()."\n".$error->getTraceAsString()."\n");
    exit(1);
});

$directory = getenv('ASSOCMAP_GISCLASS_DIRECTORY');
$password = getenv('ASSOCMAP_GISCLASS_PASSWORD');
$port = getenv('ASSOCMAP_GISCLASS_PORT');
if (! $directory || ! $password || ! ctype_digit($port ?: '') || strlen($password) < 24) {
    throw new RuntimeException('Task-owned cluster settings required.');
}
$pdo = new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;sslmode=disable;connect_timeout=5',
    'assocmap_gisclass_verify', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $pdo->query("SELECT current_user actor, current_database() db, current_setting('data_directory') directory,
    host(inet_server_addr()) host, inet_server_port() port")->fetch(PDO::FETCH_ASSOC);
if ($identity['actor'] !== 'assocmap_gisclass_verify' || $identity['db'] !== 'postgres' || $identity['host'] !== '127.0.0.1'
    || (string) $identity['port'] !== $port
    || strcasecmp(str_replace('\\', '/', $identity['directory']), str_replace('\\', '/', $directory)) !== 0
    || $pdo->query("SELECT to_regclass('public.gis_locations') IS NOT NULL")->fetchColumn()) {
    throw new RuntimeException('Fresh disposable PostgreSQL identity mismatch.');
}
$pdo->exec("SET lock_timeout='5s'; SET statement_timeout='60s';
    CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN;
    CREATE SCHEMA auth; CREATE TABLE auth.users(id uuid PRIMARY KEY, email text)");
$sqlRoot = dirname(__DIR__, 3).'/supabase/migrations/';
// These prerequisites have no Laravel wrappers. Never execute 020-024 SQL here.
foreach (glob($sqlRoot.'*.sql') as $file) {
    if ((int) basename($file) <= 6) {
        $pdo->exec(file_get_contents($file));
    }
}

putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(dirname($directory).'/no-environment')->loadEnvironmentFrom('.env.testing');
$app->useStoragePath(dirname($directory).'/storage');
if ($app->configurationIsCached()) {
    throw new RuntimeException('Cached configuration prohibited.');
}
$app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($port, $password) {
    $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
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
// Laravel installs its handler during bootstrap; keep failures visible to this standalone runner.
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'FAIL: '.$error::class.' '.$error->getMessage()."\n".$error->getTraceAsString()."\n");
    exit(1);
});

$checks = [];
function verify(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
}
function migrate(array $names): void
{
    $paths = array_map(fn ($name) => 'database/migrations/'.$name.'.php', $names);
    $exit = Artisan::call('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true]);
    verify($exit === 0, 'Laravel migrate succeeds: '.implode(', ', $names));
}
function snapshot(array $tables): array
{
    $result = [];
    foreach ($tables as $table) {
        if (! preg_match('/\A[a-z_]+\z/D', $table)) {
            throw new RuntimeException('Unsafe fixture table.');
        }
        $row = $table === 'gis_locations' ? "to_jsonb(t) - 'site_type_id'" : 'to_jsonb(t)';
        $result[$table] = DB::selectOne('SELECT COALESCE(jsonb_agg('.$row.' ORDER BY ('.$row.')::text), \'[]\'::jsonb)::text AS value FROM public.'.$table.' t')->value;
    }

    return $result;
}
function rejectedSql(Closure $work, string $state, ?string $constraint = null): void
{
    DB::beginTransaction();
    try {
        $work();
        throw new RuntimeException('Expected SQL rejection '.$state);
    } catch (QueryException $error) {
        verify(($error->errorInfo[0] ?? null) === $state, 'SQLSTATE '.$state.' '.($constraint ?? 'permission/integrity'));
        if ($constraint !== null) {
            verify(str_contains($error->errorInfo[2] ?? '', '"'.$constraint.'"'), 'constraint '.$constraint);
        }
    } finally {
        DB::rollBack();
    }
}
function denied(Closure $work, string $class, ?int $status = null): void
{
    try {
        $work();
        throw new RuntimeException('Expected application rejection.');
    } catch (Throwable $error) {
        verify($error instanceof $class, 'application rejects as '.$class);
        if ($status !== null) {
            verify($error->getStatusCode() === $status, 'application status '.$status);
        }
    }
}

migrate(['0001_01_01_000000_create_users_table', '0001_01_01_000001_create_cache_table',
    '0001_01_01_000002_create_jobs_table', '2026_09_28_091014_create_personal_access_tokens_table',
    '2026_10_05_102947_update_program_activity_status_constraint']);
foreach (glob($sqlRoot.'*.sql') as $file) {
    $number = (int) basename($file);
    // 010 is the canonical withdrawn, comment-only migration; 018 is absent/deferred.
    if ($number === 10) {
        continue;
    }
    if ($number >= 7 && $number <= 19) {
        try {
            $pdo->exec(file_get_contents($file));
        } catch (PDOException $error) {
            throw new RuntimeException('Prerequisite '.basename($file).': '.json_encode($error->errorInfo), previous: $error);
        }
    }
}
migrate(['2026_10_22_000000_add_account_reactivation', '2026_10_22_010000_support_association_account_replacement',
    '2026_10_22_020000_support_verified_email_change']);

const ADMIN = '11111111-1111-4111-8111-111111111111';
const OFFICER = '22222222-2222-4222-8222-222222222222';
const ACCOUNT = '33333333-3333-4333-8333-333333333333';
const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
const PROJECT = '55555555-5555-4555-8555-555555555555';
const OTHER_PROJECT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const LEGACY = '66666666-6666-4666-8666-666666666666';
const MONITORING = '77777777-7777-4777-8777-777777777777';
const VISIT = '88888888-8888-4888-8888-888888888888';
const MATERIAL = '99999999-9999-4999-8999-999999999999';
const REPLACEMENT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
foreach ([ADMIN => 'system_admin', OFFICER => 'field_officer', ACCOUNT => 'association_account', REPLACEMENT => 'field_officer'] as $id => $role) {
    DB::table('auth.users')->insert(['id' => $id, 'email' => $id.'@example.test']);
    DB::table('user_profiles')->insert(['id' => $id, 'display_name' => 'Synthetic '.$role,
        'role_id' => DB::table('roles')->where('code', $role)->value('id'), 'activated_at' => now()]);
}
$municipality = DB::table('municipalities')->insertGetId(['name' => 'Synthetic municipality'], 'id');
$barangay = DB::table('barangays')->insertGetId(['name' => 'Synthetic barangay', 'municipality_id' => $municipality], 'id');
$area = DB::table('areas')->insertGetId(['barangay_id' => $barangay, 'address' => 'Synthetic area'], 'id');
DB::table('associations')->insert(['id' => ASSOCIATION, 'name' => 'Synthetic association', 'area_id' => $area,
    'saad_phase2_join_date' => '2026-01-01', 'primary_field_officer_id' => OFFICER]);
DB::table('association_accounts')->insert(['association_id' => ASSOCIATION, 'user_id' => ACCOUNT, 'linked_by' => ADMIN]);
DB::table('projects')->insert(['id' => PROJECT, 'association_id' => ASSOCIATION,
    'saad_activity_id' => DB::table('saad_activities')->value('id'), 'fisheries_category_id' => DB::table('fisheries_project_categories')->value('id'),
    'title' => 'Synthetic project', 'program_year' => 2026, 'implementation_start_date' => '2026-01-01', 'status' => 'ongoing']);
DB::table('projects')->insert(array_replace((array) DB::table('projects')->where('id', PROJECT)->first(),
    ['id' => OTHER_PROJECT, 'title' => 'Synthetic other project']));
DB::table('monitoring_records')->insert(['id' => MONITORING, 'project_id' => PROJECT, 'year' => 2026, 'quarter_id' => 1,
    'monitoring_date' => '2026-01-15', 'project_status' => 'ongoing', 'monitored_by' => OFFICER]);
DB::table('monitoring_visit_reports')->insert(['id' => VISIT, 'monitoring_record_id' => MONITORING,
    'monitoring_date' => '2026-01-15', 'monitored_by' => OFFICER, 'site_validated' => true]);
$projectMaterial = DB::table('project_materials')->insertGetId(['project_id' => PROJECT, 'item_name' => 'Synthetic equipment',
    'quantity_planned' => 1, 'unit' => 'piece'], 'id');
DB::table('monitoring_materials')->insert(['id' => MATERIAL, 'monitoring_record_id' => MONITORING,
    'project_material_id' => $projectMaterial, 'condition' => 'Good']);
DB::insert("INSERT INTO gis_locations(id,project_id,location_type,location_name,public_title,geom,is_published,published_by,published_at)
    VALUES (?,?,'project_point','Private legacy','Public legacy',extensions.ST_SetSRID(extensions.ST_MakePoint(123.8,10.3),4326),true,?,now())",
    [LEGACY, PROJECT, OFFICER]);
$baseTables = DB::table('information_schema.tables')->where('table_schema', 'public')->where('table_type', 'BASE TABLE')
    ->where('table_name', '!=', 'migrations')->orderBy('table_name')->pluck('table_name')->all();
$before023 = snapshot($baseTables);
$migration023 = '2026_10_27_000000_add_media_photos';
$migration024 = '2026_10_28_000000_add_gis_site_types';
migrate([$migration023]);
verify(snapshot($baseTables) === $before023, '023 preserves every existing public table row');

function photo(string $parent, string $id, array $change = []): array
{
    return array_replace(['id' => (string) Str::uuid(), $parent => $id, 'uploaded_by' => OFFICER,
        'request_id' => (string) Str::uuid(), 'object_prefix' => 'media/'.Str::uuid(), 'input_sha256' => str_repeat('a', 64),
        'state' => 'ready', 'image_format' => 'png', 'display_width' => 64, 'display_height' => 32, 'display_bytes' => 100,
        'display_sha256' => str_repeat('b', 64), 'thumbnail_width' => 64, 'thumbnail_height' => 32, 'thumbnail_bytes' => 100,
        'thumbnail_sha256' => str_repeat('c', 64), 'display_order' => 0], $change);
}
$parents = ['project_id' => PROJECT, 'gis_location_id' => LEGACY, 'monitoring_visit_report_id' => VISIT, 'monitoring_material_id' => MATERIAL];
$photos = [];
foreach ($parents as $column => $parentId) {
    $row = photo($column, $parentId, $column === 'gis_location_id' ? ['approved_by' => ADMIN, 'approved_at' => now()] : []);
    DB::table('media_photos')->insert($row);
    $photos[$column] = $row;
}
$before024 = snapshot([...$baseTables, 'media_photos']);
$historyBefore = DB::selectOne("SELECT pg_get_functiondef('public.protect_finalized_project_history()'::regprocedure) definition,
    proowner, proacl::text acl FROM pg_proc WHERE oid='public.protect_finalized_project_history()'::regprocedure");
migrate([$migration024]);
$historyAfter = DB::selectOne("SELECT pg_get_functiondef('public.protect_finalized_project_history()'::regprocedure) definition,
    proowner, proacl::text acl FROM pg_proc WHERE oid='public.protect_finalized_project_history()'::regprocedure");
verify(str_replace(["'location_type', 'site_type_id',", "\r"], ["'location_type',", ''], $historyAfter->definition)
    === str_replace("\r", '', $historyBefore->definition), 'history function changes only two GIS maintenance whitelist entries');
verify($historyBefore->proowner === $historyAfter->proowner && $historyBefore->acl === $historyAfter->acl, 'history function owner and ACL unchanged');
verify(snapshot([...$baseTables, 'media_photos']) === $before024, '024 preserves all base rows and existing four-parent photo metadata');
verify(DB::table('gis_site_types')->count() === 0, 'migration leaves production catalog empty');
verify(GisLocation::findOrFail(LEGACY)->site_type_id === null, 'legacy location classification remains null');
$ledger = DB::table('migrations')->whereIn('migration', [$migration023, $migration024])->orderBy('id')->get();
verify($ledger->pluck('migration')->all() === [$migration023, $migration024], 'canonical Laravel ledger has 023 then 024 exactly once');
verify($ledger[0]->batch < $ledger[1]->batch, 'upgrade applies media before classification');
$beforeReplay = snapshot([...$baseTables, 'media_photos', 'gis_site_types', 'migrations']);
migrate([$migration023, $migration024]);
verify(snapshot([...$baseTables, 'media_photos', 'gis_site_types', 'migrations']) === $beforeReplay, 'Laravel rerun does not execute SQL or wrappers again');

$foreignKeys = DB::select("SELECT c.conname, a.attname AS column, c.confrelid::regclass::text AS target, c.confdeltype, c.convalidated
    FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=c.conkey[1]
    WHERE c.contype='f' AND c.conrelid IN ('media_photos'::regclass,'gis_locations'::regclass)
    AND (c.conrelid='media_photos'::regclass OR a.attname='site_type_id')");
verify(count($foreignKeys) === 8, 'seven media foreign keys and one classification foreign key');
$targets = ['project_id' => 'projects', 'gis_location_id' => 'gis_locations', 'monitoring_visit_report_id' => 'monitoring_visit_reports',
    'monitoring_material_id' => 'monitoring_materials', 'uploaded_by' => 'user_profiles', 'approved_by' => 'user_profiles',
    'replaces_photo_id' => 'media_photos', 'site_type_id' => 'gis_site_types'];
foreach ($foreignKeys as $key) {
    verify($key->target === $targets[$key->column] && $key->confdeltype === 'r' && $key->convalidated,
        'validated RESTRICT foreign key '.$key->column);
}
foreach (['media_exactly_one_parent', 'media_replacement', 'media_project_order', 'media_approval', 'media_dimensions', 'media_finalizable'] as $name) {
    verify((bool) DB::selectOne('SELECT convalidated FROM pg_constraint WHERE conrelid=\'media_photos\'::regclass AND conname=?', [$name])?->convalidated,
        'validated CHECK '.$name);
}
foreach (['media_photos' => ['media_photos_pkey', 'media_photos_request_id_key', 'media_photos_object_prefix_key',
    'media_photos_disk_check', 'media_photos_object_prefix_check', 'media_photos_input_sha256_check',
    'media_photos_state_check', 'media_photos_image_format_check', 'media_photos_display_order_check'],
    'gis_site_types' => ['gis_site_types_pkey', 'gis_site_types_code_key', 'gis_site_types_code_check', 'gis_site_types_name_check']] as $table => $names) {
    foreach ($names as $name) {
        verify((bool) DB::selectOne('SELECT convalidated FROM pg_constraint WHERE conrelid=to_regclass(?) AND conname=?', [$table, $name])?->convalidated,
            'validated key/column constraint '.$name);
    }
}
foreach (['media_photos' => 22, 'gis_site_types' => 4] as $table => $expected) {
    verify((int) DB::selectOne('SELECT count(*) total FROM pg_constraint WHERE conrelid=to_regclass(?) AND convalidated', [$table])->total === $expected,
        'complete validated constraint catalog '.$table);
    verify(! DB::selectOne('SELECT EXISTS(SELECT 1 FROM pg_index WHERE indrelid=to_regclass(?) AND (NOT indisvalid OR NOT indisready)) bad', [$table])->bad,
        'all primary/unique/supporting indexes valid '.$table);
}
$columns = DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'gis_site_types')->get()->keyBy('column_name');
verify($columns['id']->data_type === 'bigint' && $columns['id']->identity_generation === 'ALWAYS', 'catalog BIGINT identity schema');
verify($columns['code']->character_maximum_length === 50 && $columns['name']->character_maximum_length === 100, 'catalog bounded code/name columns');
verify(DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'gis_locations')
    ->where('column_name', 'site_type_id')->value('is_nullable') === 'YES', 'location classification is nullable at database level');
foreach (['media_project_parent', 'media_gis_parent', 'media_visit_parent', 'media_material_parent', 'media_recovery',
    'media_one_ready_cover', 'media_one_pending_cover', 'idx_gis_locations_site_type'] as $name) {
    $index = DB::selectOne('SELECT i.indisvalid, i.indisready FROM pg_index i WHERE i.indexrelid=to_regclass(?)', [$name]);
    verify($index?->indisvalid && $index->indisready, 'valid ready index '.$name);
}
foreach (['media_photos', 'gis_site_types'] as $table) {
    verify((bool) DB::selectOne('SELECT relrowsecurity FROM pg_class WHERE oid=to_regclass(?)', [$table])->relrowsecurity, 'RLS enabled '.$table);
    verify(DB::table('pg_policies')->where('schemaname', 'public')->where('tablename', $table)->count() === 0, 'no direct client policies '.$table);
    foreach (['anon', 'authenticated'] as $role) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
            verify(! DB::selectOne('SELECT has_table_privilege(?,?,?) permitted', [$role, $table, $privilege])->permitted,
                'client permission denied '.$role.' '.$table.' '.$privilege);
        }
    }
    // Even a mistaken future SELECT grant does not bypass default-deny RLS.
    DB::beginTransaction();
    DB::statement('GRANT SELECT ON '.$table.' TO anon');
    DB::statement('SET LOCAL ROLE anon');
    verify(DB::table($table)->count() === 0, 'default-deny RLS hides rows '.$table);
    DB::rollBack();
}

// Proposed BFAR labels exist only inside this task-owned disposable database.
foreach (['fish_cage' => 'Fish cage', 'fish_pen' => 'Fish pen', 'seaweed_cultivation_site' => 'Seaweed cultivation site',
    'fishing_gear_site' => 'Fishing gear site', 'post_harvest_facility' => 'Post-harvest facility'] as $code => $name) {
    DB::table('gis_site_types')->insert(['code' => $code, 'name' => $name]);
}
verify(DB::table('gis_site_types')->count() === 5, 'five proposed categories seeded only in isolated test data');
DB::beginTransaction();
DB::statement('GRANT SELECT ON gis_site_types TO authenticated');
DB::statement('SET LOCAL ROLE authenticated');
verify(DB::table('gis_site_types')->count() === 0, 'RLS hides all five seeded types even with a client SELECT grant');
DB::rollBack();
rejectedSql(fn () => DB::table('gis_site_types')->insert(['code' => 'fish_cage', 'name' => 'Duplicate']), '23505');
rejectedSql(fn () => DB::table('gis_site_types')->insert(['code' => 'INVALID', 'name' => 'Invalid']), '23514');
rejectedSql(fn () => DB::table('gis_site_types')->insert(['code' => 'empty_name', 'name' => ' ']), '23514');
$ready = photo('gis_location_id', LEGACY);
foreach (['project_id' => PROJECT, 'gis_location_id' => LEGACY, 'monitoring_visit_report_id' => VISIT,
    'monitoring_material_id' => MATERIAL, 'uploaded_by' => OFFICER, 'approved_by' => ADMIN, 'replaces_photo_id' => $photos['project_id']['id']] as $column => $unused) {
    $row = in_array($column, array_keys($parents), true) ? photo($column, (string) Str::uuid())
        : photo($column === 'replaces_photo_id' ? 'project_id' : 'gis_location_id', $column === 'replaces_photo_id' ? PROJECT : LEGACY,
            [$column => (string) Str::uuid()] + ($column === 'approved_by' ? ['approved_at' => now()] : []));
    // Pending covers avoid the unrelated one-ready-cover uniqueness rule.
    if ($column === 'replaces_photo_id') {
        $row['state'] = 'reserved';
    }
    rejectedSql(fn () => DB::table('media_photos')->insert($row), '23503', 'media_photos_'.$column.'_fkey');
}
foreach ([['project_id' => PROJECT], ['gis_location_id' => null]] as $change) {
    rejectedSql(fn () => DB::table('media_photos')->insert(array_replace($ready, $change)), '23514', 'media_exactly_one_parent');
}
foreach ([['display_width' => 2000, 'constraint' => 'media_dimensions'], ['display_bytes' => null, 'constraint' => 'media_finalizable'],
    ['replaces_photo_id' => $photos['project_id']['id'], 'constraint' => 'media_replacement']] as $change) {
    $constraint = $change['constraint'];
    unset($change['constraint']);
    rejectedSql(fn () => DB::table('media_photos')->insert(array_replace($ready, $change)), '23514', $constraint);
}
rejectedSql(fn () => DB::table('media_photos')->insert(photo('project_id', PROJECT, ['display_order' => 1])), '23514', 'media_project_order');
rejectedSql(fn () => DB::table('media_photos')->insert(photo('project_id', PROJECT, ['approved_by' => ADMIN, 'approved_at' => now()])), '23514', 'media_approval');
rejectedSql(fn () => DB::table('media_photos')->insert(photo('project_id', PROJECT)), '23505', 'media_one_ready_cover');
rejectedSql(function () {
    DB::table('media_photos')->insert(photo('project_id', PROJECT, ['state' => 'reserved']));
    DB::table('media_photos')->insert(photo('project_id', PROJECT, ['state' => 'uploading']));
}, '23505', 'media_one_pending_cover');
foreach ($parents as $column => $parentId) {
    rejectedSql(fn () => DB::table($targets[$column])->where('id', $parentId)->delete(), '23503');
}

// Test a separately authenticated non-owner Laravel role, never a hosted role or credential.
$runtimePassword = base64_encode(random_bytes(32));
$pdo->exec('CREATE ROLE assocmap_gisclass_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS PASSWORD '.$pdo->quote($runtimePassword));
$pdo->exec('GRANT CONNECT ON DATABASE postgres TO assocmap_gisclass_app;
    GRANT USAGE ON SCHEMA public, extensions TO assocmap_gisclass_app;
    GRANT EXECUTE ON FUNCTION public.user_has_role(uuid,text) TO assocmap_gisclass_app;
    GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO assocmap_gisclass_app;
    REVOKE ALL ON gis_site_types FROM assocmap_gisclass_app;
    GRANT SELECT ON gis_site_types TO assocmap_gisclass_app');
config(['database.connections.pgsql.username' => 'assocmap_gisclass_app', 'database.connections.pgsql.password' => $runtimePassword]);
DB::purge('pgsql');
$runtime = DB::selectOne('SELECT current_user actor, current_database() db, host(inet_server_addr()) host, inet_server_port() port');
verify($runtime->actor === 'assocmap_gisclass_app' && $runtime->db === 'postgres' && $runtime->host === '127.0.0.1' && (string) $runtime->port === $port,
    'Laravel authenticates as isolated non-owner runtime role');
verify(! DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname=current_user')->rolsuper, 'runtime role is not superuser');
verify(! DB::selectOne("SELECT pg_get_userbyid(relowner)=current_user owned FROM pg_class WHERE oid='media_photos'::regclass")->owned, 'runtime does not own migrated tables');
verify(DB::table('gis_site_types')->count() === 5, 'runtime SELECT reads catalog with explicit trusted-backend RLS bypass');
rejectedSql(fn () => DB::table('gis_site_types')->where('id', 1)->sharedLock()->first(), '42501');
// FOR SHARE needs UPDATE on at least one column, not permission to edit names/codes.
$pdo->exec('GRANT UPDATE(id) ON gis_site_types TO assocmap_gisclass_app');
verify(DB::transaction(fn () => DB::table('gis_site_types')->where('id', 1)->sharedLock()->first()) !== null, 'narrow UPDATE(id) grant permits catalog row locks');
rejectedSql(fn () => DB::table('gis_site_types')->where('id', 1)->update(['name' => 'Unauthorized rename']), '42501');
rejectedSql(fn () => DB::table('gis_site_types')->insert(['code' => 'not_allowed', 'name' => 'Not allowed']), '42501');
foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
    verify((bool) DB::selectOne("SELECT has_table_privilege(current_user,'media_photos',?) allowed", [$privilege])->allowed, 'runtime media permission '.$privilege);
}

$queries = app(GisQueryService::class);
$locations = app(GisLocationService::class);
$officer = UserProfile::with('role')->findOrFail(OFFICER);
$admin = UserProfile::with('role')->findOrFail(ADMIN);
$request = Request::create('/synthetic-gis-media-classification');
$request->attributes->set('current_user', $officer);
verify(count($queries->siteTypes()) === 5, 'Laravel catalog options load under runtime role');
verify($queries->detail($officer, LEGACY)['properties']['site_type_id'] === null, 'legacy detail remains readable');
$payload = ['idempotency_key' => (string) Str::uuid(), 'parent_association_id' => ASSOCIATION, 'project_id' => PROJECT,
    'location_type' => 'project_point', 'location_name' => 'Synthetic classified point', 'public_title' => 'Approved classified point',
    'geometry' => ['type' => 'Point', 'coordinates' => [123.85, 10.35]], 'site_type_id' => 1];
$created = $locations->store($payload, $officer, $request)['feature'];
$id = $created['id'];
verify($created['properties']['site_type_name'] === 'Fish cage', 'real classified GIS creation under runtime permissions');
verify(! $locations->store($payload, $officer, $request)['created'], 'classified creation retry remains idempotent');
verify($queries->features(null, ['site_type_id' => '1'])['features'] === [], 'unpublished categories do not leak');
$published = $locations->lifecycle($id, 'publish', $created['properties']['version'], $officer, $request);
verify(count($queries->features(null, ['mode' => 'map', 'bbox' => [123, 10, 125, 12], 'site_type_id' => '1'])['features']) === 1, 'public classification plus viewport filter');
verify($queries->features(null, ['site_type_id' => '999'])['features'] === [], 'unknown filter yields no locations');
verify(count($queries->features(null, ['site_type_id' => 'unclassified'])['features']) === 1, 'null classification filter retains legacy location');
$updated = $locations->update($id, array_replace($payload, ['site_type_id' => 2, 'version' => $published['properties']['version']]), $officer, $request);
verify($updated['properties']['site_type_code'] === 'fish_pen', 'classification update reaches detail');
verify(count($queries->features(null, ['site_type_id' => '2'])['features']) === 1 && $queries->features(null, ['site_type_id' => '1'])['features'] === [], 'updated category consistent across queries');
denied(fn () => $locations->update($id, array_replace($payload, ['site_type_id' => 999, 'version' => $updated['properties']['version']]), $officer, $request), ValidationException::class);
rejectedSql(fn () => DB::table('gis_locations')->where('id', $id)->update(['site_type_id' => 999]), '23503', 'gis_locations_site_type_id_fkey');
$oldClient = $payload;
unset($oldClient['site_type_id']);
$updated = $locations->update($id, $oldClient + ['version' => $updated['properties']['version']], $officer, $request);
verify($updated['properties']['site_type_id'] === 2, 'omitted classification preserves saved assignment');
$updated = $locations->update($id, array_replace($payload, ['site_type_id' => null, 'version' => $updated['properties']['version']]), $officer, $request);
verify($updated['properties']['site_type_id'] === null, 'explicit null clears classification');
foreach (['Polygon' => [[[123, 10], [124, 10], [124, 11], [123, 10]]], 'MultiPolygon' => [[[[123, 10], [124, 10], [124, 11], [123, 10]]]]] as $type => $coordinates) {
    $feature = $locations->store(array_replace($payload, ['idempotency_key' => (string) Str::uuid(), 'location_type' => 'project_boundary',
        'site_type_id' => 3, 'geometry' => ['type' => $type, 'coordinates' => $coordinates]]), $admin, $request)['feature'];
    verify($feature['geometry'] === ['type' => $type, 'coordinates' => $coordinates], 'real PostGIS '.$type.' workflow with classification');
}
rejectedSql(fn () => DB::update("UPDATE gis_locations SET geom=extensions.ST_GeomFromText('POLYGON((123 10,124 10,124 11,123 10))',4326) WHERE id=?", [$id]), 'P0001');
verify(Project::findOrFail(PROJECT)->photos()->first()?->id === $photos['project_id']['id'], 'Project cover metadata relationship');
verify(GisLocation::findOrFail(LEGACY)->photos()->first()?->id === $photos['gis_location_id']['id'], 'GIS photo metadata relationship');
verify(MonitoringVisitReport::findOrFail(VISIT)->photos()->first()?->id === $photos['monitoring_visit_report_id']['id'], 'Monitoring visit photo metadata relationship');
verify(MonitoringMaterial::findOrFail(MATERIAL)->photos()->first()?->id === $photos['monitoring_material_id']['id'], 'Monitoring material photo metadata relationship');

$project = Project::findOrFail(PROJECT);
$monitoring = MonitoringRecord::findOrFail(MONITORING);
$cover = app(ProjectCoverService::class);
$gallery = app(GisPhotoService::class);
$monitoringPhotos = app(MonitoringPhotoService::class);
verify($cover->snapshot($project, $cover->authorize($request, $project, false))['cover']['id'] === $photos['project_id']['id'], 'Project cover service reads existing metadata');
verify(count($gallery->gallery($gallery->parent(LEGACY), $gallery->publicAuthorization(LEGACY), true)['photos']) === 1, 'existing public GIS gallery with full canonical geometry policy');
$visit = MonitoringVisitReport::findOrFail(VISIT);
$material = MonitoringMaterial::findOrFail(MATERIAL);
verify(count($monitoringPhotos->gallery($visit, $monitoringPhotos->authorize($request, $monitoring, $visit, false))['photos']) === 1, 'Monitoring visit gallery after both migrations');
verify(count($monitoringPhotos->gallery($material, $monitoringPhotos->authorize($request, $monitoring, $material, false))['photos']) === 1, 'Monitoring material gallery after both migrations');
Storage::fake('media_private', ['visibility' => 'private']);
$lifecycle = app(PhotoLifecycleService::class);
$image = UploadedFile::fake()->image('synthetic.png', 64, 32);
$workflows = [
    [$project, $cover->authorize($request, $project, true), $photos['project_id']['id']],
    [$visit, $monitoringPhotos->authorize($request, $monitoring, $visit, true), null],
    [$material, $monitoringPhotos->authorize($request, $monitoring, $material, true), null],
    [$gallery->parent(LEGACY), $gallery->authorize($request, LEGACY, true), null],
];
foreach ($workflows as [$parent, $authorize, $replaces]) {
    $reservation = $lifecycle->reserve($parent, $image, (string) Str::uuid(), $request, $authorize, $replaces);
    $saved = $lifecycle->upload($reservation['id'], $image, $request, $authorize);
    verify($saved['status'] === 'ready' && MediaPhoto::findOrFail($saved['id'])->state === 'ready', 'real local photo reserve/upload '.$parent::class);
    if ($parent instanceof GisLocation) {
        $lifecycle->approveGis($saved['id'], true, $request, $authorize);
        verify(count($gallery->gallery($gallery->parent(LEGACY), $gallery->publicAuthorization(LEGACY), true)['photos']) === 2, 'GIS approval/public gallery survives classification migration');
    }
}
verify(count(Storage::disk('media_private')->allFiles()) === 8, 'four workflows produced bounded local image variants');
verify(DB::table('audit_logs')->where('entity_type', 'media_photo')->exists(), 'media audit records committed through real audit service');

DB::beginTransaction();
DB::table('projects')->where('id', PROJECT)->update(['status' => 'completed', 'closed_at' => now(), 'closed_by' => ADMIN, 'closure_remarks' => 'Synthetic completion evidence']);
$finalizedVersion = $queries->detail($officer, $id)['properties']['version'];
$finalized = $locations->update($id, array_replace($payload, ['site_type_id' => 4, 'version' => $finalizedVersion]), $officer, $request);
verify($finalized['properties']['site_type_id'] === 4, 'canonical finalized-history trigger allows GIS classification maintenance');
rejectedSql(fn () => DB::table('gis_locations')->where('id', $id)->update(['project_id' => OTHER_PROJECT]), '23514');
rejectedSql(fn () => DB::table('gis_locations')->where('id', $id)->update(['created_by' => ADMIN]), '23514');
rejectedSql(fn () => DB::table('gis_locations')->where('id', $id)->delete(), '23514');
rejectedSql(fn () => DB::table('monitoring_materials')->where('id', MATERIAL)->update(['remarks' => 'Forbidden finalized edit']), '23514');
denied(fn () => DB::transaction(fn () => ($cover->authorize($request, $project, true))($project)), ValidationException::class);
DB::rollBack();
DB::beginTransaction();
DB::table('gis_locations')->where('id', LEGACY)->update(['is_published' => false]);
denied(fn () => $gallery->gallery($gallery->parent(LEGACY), $gallery->publicAuthorization(LEGACY), true), HttpException::class, 404);
DB::rollBack();
DB::beginTransaction();
DB::table('associations')->where('id', ASSOCIATION)->update(['primary_field_officer_id' => REPLACEMENT]);
denied(fn () => $locations->update($id, $payload + ['version' => $updated['properties']['version']], $officer, $request), HttpException::class, 404);
DB::rollBack();
DB::beginTransaction();
DB::table('user_profiles')->where('id', OFFICER)->update(['auth_sessions_valid_after' => now()]);
denied(fn () => $locations->update($id, $payload + ['version' => $updated['properties']['version']], $officer, $request), HttpException::class, 403);
DB::rollBack();

require __DIR__.'/GisBoundaryPresentationVerification.php';

echo json_encode(['checks' => count($checks), 'failed' => 0, 'postgres' => $pdo->query('SHOW server_version')->fetchColumn(),
    'postgis' => $pdo->query('SELECT extensions.postgis_lib_version()')->fetchColumn(), 'migration_order' => $ledger->pluck('migration')->all(),
    'runtime_role' => 'non-owner NOSUPERUSER BYPASSRLS; explicit local grants; catalog SELECT + UPDATE(id)',
    'boundary' => 'canonical prerequisites and Laravel migration ledger; synthetic Auth rows, local fake storage; no shared services',
    'passed' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
