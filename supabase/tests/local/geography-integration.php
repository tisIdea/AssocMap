<?php

declare(strict_types=1);

require __DIR__.'/bootstrap-application.php';
require __DIR__.'/LocalTestRun.php';

use App\Models\Association;
use App\Models\UserProfile;
use App\Services\Areas\AreaManagementQueryService;
use App\Services\Associations\AssociationService;
use App\Services\DashboardService;
use App\Services\GIS\GisAddressResolver;
use App\Services\GIS\GisLocationService;
use App\Models\Project;
use App\Services\GIS\GisQueryService;
use App\Services\Members\MemberDirectoryQueryService;
use App\Services\Monitoring\MonitoringDirectoryQueryService;
use App\Services\Programs\ProgramDirectoryQueryService;
use App\Services\Reporting\PythonReportEngine;
use App\Services\Reporting\ReportCatalog;
use App\Services\Reporting\ReportDataset;
use App\Services\Reporting\ReportScope;
use App\Services\Reporting\ReportService;
use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$scratch = new ScratchDatabase($argv[1] ?? '');
$pdo = $scratch->pdo;
if (($argv[2] ?? '') === 'stale-worker') {
    $app = scratchApplication($scratch);
    $app['config']->set('associations', ['geography_schema_ready'=>true,'direct_geography'=>false]);
    $data = json_decode(file_get_contents($scratch->directory.'/geography-stale-payload.json'), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($scratch->directory.'/geography-stale-ready.json', json_encode(['pid'=>(int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]));
    try {
        app(AssociationService::class)->update(Association::findOrFail($data['id']), $data['payload'], UserProfile::with('role')->findOrFail($data['actor']));
        $result = ['status'=>200];
    } catch (Illuminate\Validation\ValidationException $exception) {
        $result = ['status'=>422,'fields'=>array_keys($exception->errors())];
    }
    file_put_contents($scratch->directory.'/geography-stale-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}
if ((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname IN ('public','auth')")->fetchColumn() !== 0) {
    throw new RuntimeException('A newly created empty disposable database is required; nothing was changed.');
}
$root = dirname(__DIR__, 3);
$child = static function (string $script, array $arguments = []) use ($argv): void {
    $process = new Process([PHP_BINARY, __DIR__.'/'.$script, $argv[1], ...$arguments], dirname(__DIR__, 3), timeout: 180);
    $process->mustRun();
    echo $process->getOutput();
};
$pdo->exec('CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN; CREATE ROLE service_role NOLOGIN;
    CREATE SCHEMA auth; CREATE TABLE auth.users(id UUID PRIMARY KEY, email TEXT)');
foreach (['001','002','003','004','005','006','008','009'] as $number) {
    $files = glob($root.'/supabase/migrations/'.$number.'_*.sql');
    if (count($files) !== 1) throw new RuntimeException('Ambiguous migration '.$number);
    $pdo->exec(file_get_contents($files[0]));
}
$child('bootstrap-framework.php');
$pdo->exec(file_get_contents($root.'/supabase/migrations/007_secure_laravel_infrastructure.sql'));
$child('safety.php', ['C:/Program Files/PostgreSQL/18/bin/psql.exe']);
$child('migrate-pending.php');
foreach (['015','016'] as $number) $pdo->exec(file_get_contents(glob($root.'/supabase/migrations/'.$number.'_*.sql')[0]));

$app = scratchApplication($scratch);
$app->useStoragePath($scratch->directory.'/reporting-storage');
$app['config']->set('associations', ['geography_schema_ready' => false, 'direct_geography' => false]);
$app['config']->set('cache', ['default'=>'array','stores'=>['array'=>['driver'=>'array','serialize'=>false]]]);
$app['config']->set('reporting', ['python'=>$root.'/reporting/.venv/Scripts/python.exe', 'timeout'=>45,
    'max_rows'=>5000,'max_total_rows'=>20000,'max_json_bytes'=>8*1024*1024,'max_artifact_bytes'=>12*1024*1024,
    'concurrency'=>2,'font_regular'=>'','font_bold'=>'']);
$app->instance('cache', new CacheManager($app));
$app->instance('files', new Filesystem);
$tests = new LocalTestRun($scratch->directory.'/geography-integration-results.json');
$identities = $pdo->query('SELECT label,id FROM assocmap_test.identities')->fetchAll(PDO::FETCH_KEY_PAIR);
$actor = fn (string $role) => UserProfile::with('role')->findOrFail($identities[$role]);
$request = static function (UserProfile $user): Request {
    $request = Request::create('/synthetic-geography-test');
    $request->attributes->set('current_user', $user);

    return $request;
};
$measure = static function (callable $read): array {
    DB::flushQueryLog(); DB::enableQueryLog();
    $started = microtime(true);
    try { $value = $read(); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); }

    return ['value'=>$value,'queries'=>count($queries),'db_ms'=>array_sum(array_column($queries,'time')),
        'elapsed_ms'=>round((microtime(true)-$started)*1000, 2)];
};
$reads = static function () use ($actor, $request, $measure): array {
    $officer = $actor('officer');
    return [
        'barangay_directory'=>$measure(fn () => app(AreaManagementQueryService::class)->barangays()->paginate(25)),
        'member_listing'=>$measure(fn () => app(MemberDirectoryQueryService::class)->members($officer,
            ['status'=>'','association'=>'','q'=>'','sort'=>'az'])),
        'project_listing'=>$measure(fn () => app(ProgramDirectoryQueryService::class)->records($officer, 'projects',
            ['status'=>'','association'=>'','q'=>'','sort'=>'az','year'=>''], app(ProgramDirectoryQueryService::class)->summary($officer,'projects'))),
        'monitoring_lookup'=>$measure(fn () => app(MonitoringDirectoryQueryService::class)->records(
            Project::whereHas('association', fn ($query) => $query->where('primary_field_officer_id',$officer->id)->whereNull('archived_at')),
            ['year'=>2026,'quarter'=>'','validated'=>'','search'=>'','sort'=>'newest'])),
        'dashboard'=>$measure(fn () => app(DashboardService::class)->load($request($officer))),
        'report_options'=>$measure(fn () => app(ReportService::class)->options($request($officer), [])),
        'gis_features'=>$measure(fn () => app(GisQueryService::class)->features($officer, ['mode'=>'map'])),
        'gis_associations'=>$measure(fn () => app(GisQueryService::class)->parents($officer, ['kind'=>'association'])),
    ];
};
$before = $reads();
$datasetBefore = app(ReportDataset::class)->extract($actor('admin'), ['report_type'=>'comprehensive','year'=>2026],
    app(ReportScope::class)->ids($actor('admin'), []));
$pdo->exec(file_get_contents($root.'/supabase/migrations/017_expand_association_geography.sql'));
$children = static function () use ($pdo): string {
    return hash('sha256', $pdo->query("SELECT (assocmap_test.core_snapshot() - 'associations')::text")->fetchColumn());
};
$preservedChildren = $children();
$associationIds = $pdo->query('SELECT id FROM associations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$geometry = $pdo->query('SELECT id,encode(extensions.ST_AsEWKB(geom),\'hex\') FROM gis_locations ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec("SET assocmap.geography_cutover_approved='batch2'");
$pdo->exec(file_get_contents($root.'/supabase/deferred/018_cutover_association_geography.sql'));
$app['config']->set('associations.geography_schema_ready', true);
$tests->check('full schema cutover preserves every Association ID child record and geometry', function () use ($pdo, $associationIds, $children, $preservedChildren, $geometry) {
    LocalTestRun::assert($associationIds === $pdo->query('SELECT id FROM associations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'Association identities changed.');
    LocalTestRun::assert($children() === $preservedChildren, 'Historical child data changed.');
    LocalTestRun::assert($geometry === $pdo->query('SELECT id,encode(extensions.ST_AsEWKB(geom),\'hex\') FROM gis_locations ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), 'Geometry changed.');
    LocalTestRun::assert((int) $pdo->query('SELECT count(*) FROM associations WHERE barangay_id IS NULL OR specific_address IS NOT NULL')->fetchColumn() === 0, 'Backfill incomplete or address copied.');
});
$tests->check('State 3 AssociationService writes required Barangay with activation disabled and rolls back', function () use ($actor, $identities) {
    $before = Association::count();
    DB::beginTransaction();
    try {
        $area = Association::findOrFail($identities['association'])->area_id;
        $created = app(AssociationService::class)->create(['name'=>'Synthetic State 3 registration','area_id'=>$area,
            'primary_field_officer_id'=>$identities['officer'],'saad_phase2_join_date'=>'2026-01-01','status'=>'active'], $actor('admin'));
        LocalTestRun::assert($created->barangay_id !== null && $created->area_id === $area, 'State 3 writer omitted Barangay.');
    } finally { DB::rollBack(); }
    LocalTestRun::assert(Association::count() === $before, 'Transaction rollback lost isolation.');
});

DB::table('associations')->where('id', $identities['association'])->update(['area_id'=>null,'specific_address'=>'PRIVATE-GEOGRAPHY-ADDRESS-DO-NOT-EXPOSE']);
$after = $reads();
$tests->check('Area-less scoped GIS dashboard and report options retain records without query growth', function () use ($before, $after, $scratch) {
    LocalTestRun::assert($before['dashboard']['value']['scope_count'] === $after['dashboard']['value']['scope_count'], 'Dashboard dropped an Association.');
    foreach (['report_options','gis_features','gis_associations'] as $key) {
        LocalTestRun::assert(count($before[$key]['value']) === count($after[$key]['value']), 'Projection shape changed.');
    }
    LocalTestRun::assert($before['gis_features']['value']['meta'] === $after['gis_features']['value']['meta'], 'GIS visibility changed.');
    LocalTestRun::assert(array_column($before['gis_features']['value']['features'],'id') === array_column($after['gis_features']['value']['features'],'id'), 'GIS project features disappeared.');
    LocalTestRun::assert(
        json_encode($before['report_options']['value'], JSON_THROW_ON_ERROR) === json_encode($after['report_options']['value'], JSON_THROW_ON_ERROR),
        'Report options changed or lost records.',
    );
    LocalTestRun::assert(array_column($before['gis_associations']['value']['options'],'value') === array_column($after['gis_associations']['value']['options'],'value'), 'GIS parents disappeared.');
    $evidence = [];
    foreach ($before as $key => $row) {
        LocalTestRun::assert($after[$key]['queries'] <= $row['queries'], 'Unexpected query growth: '.$key);
        $evidence[$key] = ['before'=>array_diff_key($row,['value'=>true]),'after'=>array_diff_key($after[$key],['value'=>true])];
    }
    file_put_contents($scratch->directory.'/geography-performance.json', json_encode($evidence, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));

    return $evidence;
});
$tests->check('native report datasets retain exact keys rows and geography after Area removal', function () use ($actor, $datasetBefore) {
    $after = app(ReportDataset::class)->extract($actor('admin'), ['report_type'=>'comprehensive','year'=>2026],
        app(ReportScope::class)->ids($actor('admin'), []));
    LocalTestRun::assert($after === $datasetBefore, 'Report datasets lost rows or changed the Python contract.');
});
$tests->check('GIS Area choices exclude null pseudo-Areas and private addresses stay private', function () use ($actor, $identities) {
    $gis = app(GisQueryService::class);
    $areas = $gis->parents($actor('officer'), ['kind'=>'area']);
    foreach ($areas['options'] as $area) LocalTestRun::assert($area['value'] !== null, 'Null pseudo-Area returned.');
    $association = Association::findOrFail($identities['association']);
    $address = app(GisAddressResolver::class)->forAssociation($association);
    LocalTestRun::assert($address['address'] === null && $address['context'] !== '', 'Missing Area erased Barangay context.');
    LocalTestRun::assert(! str_contains(json_encode($gis->features($actor('officer'), ['mode'=>'map'])), 'PRIVATE-GEOGRAPHY'), 'Private address leaked through GIS.');
});
$tests->check('State 4 AssociationService accepts all four combinations on native cutover constraints', function () use ($app, $actor, $identities) {
    $association = Association::with('barangay')->findOrFail($identities['association']);
    $area = DB::table('areas')->where('barangay_id',$association->barangay_id)->value('id');
    $app['config']->set('associations.direct_geography', true);
    DB::beginTransaction();
    try {
        foreach ([[null,null],[$area,null],[null,'Independent / Niño #2'],[$area,'Independent / Niño #2']] as $index => [$selectedArea,$address]) {
            $created = app(AssociationService::class)->create(['name'=>'Synthetic combination '.$index,'area_id'=>$selectedArea,
                'barangay_id'=>$association->barangay_id,'municipality_id'=>$association->barangay->municipality_id,
                'specific_address'=>$address,'primary_field_officer_id'=>$identities['officer'],
                'saad_phase2_join_date'=>'2026-01-01','status'=>'active'], $actor('admin'));
            LocalTestRun::assert($created->area_id === $selectedArea && $created->barangay_id === $association->barangay_id
                && $created->specific_address === $address, 'Incorrect native geographic combination.');
        }
    } finally {
        DB::rollBack();
        $app['config']->set('associations.direct_geography', false);
    }
});
$tests->check('public PostGIS feature projection keeps publication and private address boundaries', function () use ($pdo, $actor, $identities) {
    DB::beginTransaction();
    try {
        DB::table('gis_locations')->whereIn('project_id', DB::table('projects')->where('association_id',$identities['association'])->select('id'))
            ->update(['is_published'=>true,'published_by'=>$identities['admin'],'published_at'=>now(),'public_address'=>'Approved public site']);
        $features = app(GisQueryService::class)->features(null, ['mode'=>'map']);
        LocalTestRun::assert(count($features['features']) > 0, 'Published Area-less project features disappeared.');
        foreach ($features['features'] as $feature) LocalTestRun::assert(array_keys($feature['properties']) === ['location_type','title','address','description'], 'Public feature contract expanded.');
        LocalTestRun::assert(! str_contains(json_encode($features), 'PRIVATE-GEOGRAPHY'), 'Private address became public.');
    } finally { DB::rollBack(); }
});
$tests->check('native GIS write-return detail retains Area-less parent and exact geometry', function () use ($pdo, $actor, $request, $identities) {
    $case = json_decode($pdo->query("SELECT ids::text FROM assocmap_test.cases WHERE label='ongoing'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    DB::beginTransaction();
    try {
        $user = $actor('admin');
        $data = ['idempotency_key'=>Illuminate\Support\Str::uuid()->toString(),'association_id'=>null,'project_id'=>$case['project'],
            'location_type'=>'project_point','location_name'=>'Synthetic geography write-return','address'=>null,
            'public_title'=>null,'public_description'=>null,'public_address'=>null,
            'geometry'=>['type'=>'Point','coordinates'=>[123.8,10.3]]];
        $created = app(GisLocationService::class)->store($data, $user, $request($user));
        LocalTestRun::assert($created['created'] && $created['feature']['properties']['parent_association_id'] === $identities['association'], 'GIS write-return lost parent.');
        LocalTestRun::assert($created['feature']['geometry']['coordinates'] === [123.8,10.3], 'Returned geometry changed.');
    } finally { DB::rollBack(); }
});
$tests->check('all eight actual Laravel Python analytics and PDF XLSX exports work after cutover', function () use ($actor, $request, $scratch) {
    $directory = $scratch->directory.'/export-evidence';
    mkdir($directory, 0700);
    $files = [];
    foreach (array_keys(ReportCatalog::TYPES) as $type) {
        $filters = ['report_type'=>$type];
        if (in_array('year', ReportCatalog::filters($type), true)) $filters['year'] = 2026;
        foreach (['analytics','pdf','xlsx'] as $action) {
            $generated = app(ReportService::class)->generate($request($actor('admin')), $filters, $action);
            LocalTestRun::assert($generated['result']['version'] === ReportCatalog::VERSION, 'Report contract changed.');
            LocalTestRun::assert(! str_contains(json_encode($generated['result']), 'PRIVATE-GEOGRAPHY'), 'Private address entered report dataset.');
            if ($action !== 'analytics') {
                LocalTestRun::assert(strlen($generated['artifact']) > 100, 'Empty export.');
                file_put_contents($directory.'/'.$type.'.'.$action, $generated['artifact']);
                file_put_contents($directory.'/'.$type.'.json', json_encode($generated['result'], JSON_THROW_ON_ERROR));
                $files[] = $type.'.'.$action;
            }
        }
    }
    LocalTestRun::assert(glob($scratch->directory.'/reporting-storage/app/private/reporting/*') === [], 'Private reporting workspace retained.');

    return ['analytics'=>8,'exports'=>$files];
});
$tests->check('native dashboard mobile contract and reporting release recheck deny revoked links', function () use ($actor, $request, $pdo, $identities) {
    $user = $actor('account');
    $dashboard = app(DashboardService::class)->load($request($user));
    LocalTestRun::assert($dashboard['version'] === 'assocmap.dashboard.v1' && $dashboard['association']['id'] === $identities['association'], 'Linked dashboard contract changed.');
    LocalTestRun::assert(is_string($dashboard['association']['municipality']), 'Mobile municipality missing.');
    DB::beginTransaction();
    try {
        DB::table('association_accounts')->where('user_id',$identities['account'])->update(['is_active'=>false]);
        LocalTestRun::denied(fn () => app(DashboardService::class)->load($request($actor('account'))));
        LocalTestRun::denied(fn () => app(ReportService::class)->options($request($actor('account')), []));
    } finally { DB::rollBack(); }
});
$tests->check('actual Python PDF release rejects an Area-less assignment revoked after rendering', function () use ($actor, $request, $identities, $scratch) {
    $engine = new class extends PythonReportEngine {
        public Closure $afterRendering;
        public function run(array $payload, string $actorId): array
        {
            $result = parent::run($payload, $actorId);
            ($this->afterRendering)();

            return $result;
        }
    };
    $engine->afterRendering = fn () => DB::table('associations')->where('id',$identities['association'])
        ->update(['primary_field_officer_id'=>$identities['other_officer']]);
    $scope = app(ReportScope::class);
    $reports = new ReportService($scope, new ReportDataset($scope), $engine);
    try {
        LocalTestRun::denied(fn () => $reports->generate($request($actor('officer')), ['report_type'=>'associations'], 'pdf'));
        LocalTestRun::assert(glob($scratch->directory.'/reporting-storage/app/private/reporting/*') === [], 'Denied export retained private files.');
    } finally {
        DB::table('associations')->where('id',$identities['association'])->update(['primary_field_officer_id'=>$identities['officer']]);
    }
});
$tests->check('native concurrency prevents referenced Area reparent after a concurrent Association insert', function () use ($scratch, $pdo, $identities) {
    $barangay = $pdo->query('SELECT barangay_id FROM associations WHERE id='.$pdo->quote($identities['association']))->fetchColumn();
    $municipality = $pdo->query('SELECT municipality_id FROM barangays WHERE id='.$pdo->quote($barangay))->fetchColumn();
    $other = $pdo->query('INSERT INTO barangays(municipality_id,name,source) VALUES ('.$pdo->quote($municipality).",'Synthetic concurrency Barangay','manual') RETURNING id")->fetchColumn();
    $area = $pdo->query('INSERT INTO areas(barangay_id,address) VALUES ('.$pdo->quote($barangay).",'Synthetic concurrency Area') RETURNING id")->fetchColumn();
    $worker = $scratch->asyncConnection();
    $pdo->beginTransaction();
    try {
        $pdo->exec('SELECT id FROM areas WHERE id='.$pdo->quote($area).' FOR SHARE');
        $pdo->exec('INSERT INTO associations(name,barangay_id,area_id,primary_field_officer_id,saad_phase2_join_date) VALUES (\'Synthetic concurrent registration\','
            .$pdo->quote($barangay).','.$pdo->quote($area).','.$pdo->quote($identities['officer']).",'2026-01-01')");
        pg_send_query($worker, 'UPDATE areas SET barangay_id='.$pdo->quote($other).' WHERE id='.$pdo->quote($area));
        usleep(100000); pg_consume_input($worker);
        LocalTestRun::assert(pg_connection_busy($worker), 'Concurrent reparent did not wait on the protected Area.');
        $pdo->commit();
        $result = pg_get_result($worker);
        LocalTestRun::assert(pg_result_error_field($result, PGSQL_DIAG_SQLSTATE) === '23001', 'Committed pair did not reject reparent.');
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        pg_close($worker);
    }
});
$tests->check('native queued stale AssociationService write cannot overwrite a newer committed address', function () use ($scratch, $pdo, $identities, $argv) {
    $record = Association::where('name','Synthetic concurrent registration')->firstOrFail();
    $payload = ['name'=>$record->name,'area_id'=>$record->area_id,'primary_field_officer_id'=>$record->primary_field_officer_id,
        'status'=>'active','saad_phase2_join_date'=>'2026-01-01','expected_updated_at'=>$record->updated_at->format('Y-m-d\TH:i:s.uP')];
    file_put_contents($scratch->directory.'/geography-stale-payload.json', json_encode(['id'=>$record->id,'actor'=>$identities['admin'],'payload'=>$payload], JSON_THROW_ON_ERROR));
    $worker = new Process([PHP_BINARY, __FILE__, $argv[1], 'stale-worker'], timeout: 15);
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE associations SET specific_address=\'Newest concurrent address\' WHERE id='.$pdo->quote($record->id));
        $worker->start();
        $deadline = microtime(true)+5;
        $waiting = false;
        do {
            usleep(20000);
            if (is_file($scratch->directory.'/geography-stale-ready.json')) {
                $ready = json_decode(file_get_contents($scratch->directory.'/geography-stale-ready.json'), true, 512, JSON_THROW_ON_ERROR);
                $waiting = $pdo->query('SELECT wait_event_type FROM pg_stat_activity WHERE pid='.(int) $ready['pid'])->fetchColumn() === 'Lock';
            }
        } while (! $waiting && microtime(true)<$deadline);
        LocalTestRun::assert($waiting, 'Stale writer did not reach the Association row lock.');
        $pdo->commit();
        $worker->wait();
        LocalTestRun::assert($worker->isSuccessful(), 'Stale worker failed: '.$worker->getErrorOutput());
        $result = json_decode(file_get_contents($scratch->directory.'/geography-stale-result.json'), true, 512, JSON_THROW_ON_ERROR);
        LocalTestRun::assert($result['status'] === 422 && in_array('association',$result['fields'],true), 'Queued stale form was accepted.');
        LocalTestRun::assert(Association::findOrFail($record->id)->specific_address === 'Newest concurrent address', 'Newest address was overwritten.');
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($worker->isRunning()) $worker->stop(1);
    }
});
$tests->finish();
