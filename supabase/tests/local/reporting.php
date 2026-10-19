<?php

declare(strict_types=1);
require __DIR__.'/bootstrap-application.php';
require __DIR__.'/LocalTestRun.php';

use App\Models\Project;
use App\Models\UserProfile;
use App\Services\AssociationLifecycleService;
use App\Services\AuditLogService;
use App\Services\DashboardService;
use App\Services\ProjectClosureService;
use App\Services\ProjectLifecycleService;
use App\Services\Reporting\PythonReportEngine;
use App\Services\Reporting\ReportCatalog;
use App\Services\Reporting\ReportDataset;
use App\Services\Reporting\ReportScope;
use App\Services\Reporting\ReportService;
use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$scratch = new ScratchDatabase($argv[1] ?? '');
$app = scratchApplication($scratch);
$app->useStoragePath($scratch->directory.'/reporting-storage');
$app['config']->set('cache', ['default'=>'array','stores'=>['array'=>['driver'=>'array','serialize'=>false]]]);
$app['config']->set('reporting', ['python'=>dirname(__DIR__,3).'/reporting/.venv/Scripts/python.exe',
    'timeout'=>45,'max_rows'=>5000,'max_total_rows'=>20000,'max_json_bytes'=>8*1024*1024,
    'max_artifact_bytes'=>12*1024*1024,'concurrency'=>2,'font_regular'=>'','font_bold'=>'']);
$app->instance('cache',new CacheManager($app));
$app->instance('files',new Filesystem);
$tests = new LocalTestRun($scratch->directory.'/reporting-results.json',$argv[2] ?? '');
$pdo = $scratch->pdo;
$q = fn ($value) => $pdo->quote((string)$value);
$identities = $pdo->query('SELECT label,id FROM assocmap_test.identities')->fetchAll(PDO::FETCH_KEY_PAIR);
$actor = fn ($label) => UserProfile::with('role')->findOrFail($identities[$label]);
$request = static function (UserProfile $user): Request {
    $request = Request::create('/synthetic-local-report');
    $request->attributes->set('current_user',$user);
    return $request;
};
$scope = new ReportScope;
$datasets = new ReportDataset($scope);
$engine = new PythonReportEngine;
$service = new ReportService($scope,$datasets,$engine);
$section = static function (array $result,string $key): array {
    return collect($result['sections'])->firstWhere('key',$key) ?? throw new RuntimeException('Missing section '.$key);
};
$case = static function (string $label) use ($pdo,$q): array {
    $value = $pdo->query('SELECT ids::text FROM assocmap_test.cases WHERE label='.$q($label))->fetchColumn();
    if ($value===false) $value=$pdo->query('SELECT assocmap_test.make_case('.$q($label).')::text')->fetchColumn();
    return json_decode($value,true,512,JSON_THROW_ON_ERROR);
};
$main = $case('reporting_main');

$tests->check('prepare synthetic reporting records and both final outcomes', function () use ($case,$main,$pdo,$q,$actor,$request) {
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE public.projects SET title=\'=1+1\' WHERE id='.$q($main['project']));
        $pdo->exec("UPDATE public.associations SET name='Synthetic Peñafrancia Association' WHERE id=".$q($main['association']));
        $pdo->exec("UPDATE public.program_activity_records SET status='ongoing' WHERE id=".$q($main['activity']));
        $pdo->exec("UPDATE public.program_activity_records SET activity_date='2026-03-31' WHERE id=".$q($main['activity']));
        $pdo->exec("UPDATE public.trainings SET start_date='2026-03-31' WHERE id=".$q($main['training']));
        $pdo->exec("UPDATE public.trainings SET start_date='2026-04-01' WHERE id=".$q($main['training_extra']));
        $pdo->exec('UPDATE public.monitoring_production_monthly SET activity_count=0,total_quantity=0 WHERE id='.$q($main['monthly']));
        $pdo->exec('UPDATE public.monitoring_production SET actual_output=20 WHERE id='.$q($main['production']));
        if (!(int)$pdo->query('SELECT count(*) FROM public.monitoring_income WHERE monitoring_production_id='.$q($main['production_extra']))->fetchColumn()) {
            $pdo->exec('INSERT INTO public.monitoring_income(monitoring_production_id,period_type,month,gross_income) VALUES ('
                .$q($main['production_extra']).",'monthly',1,0.10),(".$q($main['production_extra']).",'monthly',2,0.20)");
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    $closures = new ProjectClosureService(new ProjectLifecycleService(new AssociationLifecycleService),new AssociationLifecycleService,new AuditLogService);
    foreach (['completed','terminated'] as $outcome) {
        $c=$case('reporting_'.$outcome); $project=Project::findOrFail($c['project']);
        if ($project->status===$outcome) continue;
        $data=['closure_remarks'=>'Synthetic reporting '.$outcome.' evidence.',
            'expected_updated_at'=>$project->updated_at->format('Y-m-d\TH:i:s.uP'),
            ($outcome==='completed'?'completion_confirmation':'termination_confirmation')=>($outcome==='completed'?'COMPLETED':'TERMINATE')];
        $outcome==='completed' ? $closures->close($request($actor('admin')),$project,$data)
            : $closures->terminate($request($actor('admin')),$project,$data);
    }
    return ['synthetic_only'=>true,'ongoing_activity'=>true,'zero_activity_month'=>true,'both_final_outcomes'=>true];
});

$tests->check('recorded reporting years never contain a null option',function () use ($service,$actor,$request) {
    $options=$service->options($request($actor('admin')),['report_type'=>'comprehensive','year'=>2026]);
    LocalTestRun::assert(!in_array(null,$options['years'],true),'Nullable project start dates produced a null reporting-year option.');
    LocalTestRun::assert(in_array(2026,$options['years'],true),'Recorded reporting year missing.');
    return ['years'=>$options['years'],'projects'=>count($options['projects'])];
});

foreach (array_keys(ReportCatalog::TYPES) as $type) {
    $tests->check('actual Laravel Python analytics '.$type,function () use ($service,$type,$actor,$request) {
        $filters=['report_type'=>$type];
        if (in_array('year',ReportCatalog::filters($type),true)) $filters['year']=2026;
        $generated=$service->generate($request($actor('admin')),$filters);
        LocalTestRun::assert($generated['result']['version']===ReportCatalog::VERSION,'Incorrect result version.');
        LocalTestRun::assert($generated['artifact']===null,'Analytics produced an unexpected artifact.');
        return ['sections'=>count($generated['result']['sections']),'empty'=>$generated['result']['empty']];
    });
}

$tests->check('native filtered Production Income and ongoing activities',function () use ($service,$main,$actor,$request,$section) {
    $filters=['report_type'=>'comprehensive','year'=>2026,'association_id'=>$main['association'],'project_id'=>$main['project']];
    $result=$service->generate($request($actor('admin')),$filters)['result'];
    LocalTestRun::assert(count($section($result,'projects')['rows'])===1,'Project filter leaked another project.');
    LocalTestRun::assert($section($result,'activities')['rows'][0]['status']==='ongoing','Valid ongoing activity was lost.');
    LocalTestRun::assert(array_column($section($result,'production')['rows'],'actual')===['20.00','0.00']
        || array_column($section($result,'production')['rows'],'actual')===['0.00','20.00'],'Quarterly actual observations changed.');
    LocalTestRun::assert(count($section($result,'monthly_production')['rows'])===3,'Monthly records were multiplied by Income joins.');
    LocalTestRun::assert($section($result,'income')['measures'][1]['value']==='1200.30','Income was rounded or multiplied by sibling rows.');
    $filters['report_type']='income'; $filters['month']=1;
    $income=$section($service->generate($request($actor('admin')),$filters)['result'],'income');
    LocalTestRun::assert($income['measures'][1]['value']==='0.10','Month selection included quarterly income.');
    $filters['report_type']='production';
    $production=$service->generate($request($actor('admin')),$filters)['result'];
    $monthly=$section($production,'monthly_production')['rows'];
    LocalTestRun::assert(count($monthly)===1 && $monthly[0]['activities']===0 && $monthly[0]['quantity']==='0.00','Recorded zero month was lost.');
    return ['income'=>'1200.30','month_income'=>'0.10','monthly_rows'=>3,'zero_activities_preserved'=>true];
});

$tests->check('all report formats preserve operational history and private-workspace cleanup',function () use ($service,$actor,$request,$pdo,$scratch) {
    $snapshot = fn () => [$pdo->query('SELECT assocmap_test.core_snapshot()::text')->fetchColumn(),
        $pdo->query('SELECT jsonb_agg(to_jsonb(p) ORDER BY id)::text FROM public.projects p')->fetchColumn()];
    $before=$snapshot(); $files=[];
    $directory=$scratch->directory.'/export-evidence';
    if (!is_dir($directory)) mkdir($directory,0700);
    foreach (array_keys(ReportCatalog::TYPES) as $type) foreach (['pdf','xlsx'] as $format) {
        $filters=['report_type'=>$type];
        if (in_array('year',ReportCatalog::filters($type),true)) $filters['year']=2026;
        $result=$service->generate($request($actor('admin')),$filters,$format);
        $path=$directory.'/'.$type.'.'.$format;
        file_put_contents($path,$result['artifact']);
        file_put_contents($directory.'/'.$type.'.json',json_encode($result['result'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        LocalTestRun::assert(strlen($result['artifact'])>100,'Empty generated export.');
        $files[basename($path)]=['bytes'=>strlen($result['artifact']),'sha256'=>hash('sha256',$result['artifact'])];
    }
    LocalTestRun::assert($before===$snapshot(),'Reporting changed operational records or closure evidence.');
    LocalTestRun::assert(glob($scratch->directory.'/reporting-storage/app/private/reporting/*')===[],'A private report workspace was retained.');
    return ['files'=>$files,'history_unchanged'=>true,'workspace_cleanup'=>true];
});

$tests->check('native scope historical visibility and dashboard metric definitions',function () use ($scope,$datasets,$service,$actor,$request,$section,$pdo,$case,$main) {
    $admin=$actor('admin'); $officer=$actor('officer');
    $adminIds=$scope->ids($admin,[]); $officerIds=$scope->ids($officer,[]);
    LocalTestRun::assert(count($adminIds)>count($officerIds),'Admin archived/inactive scope was not retained.');
    foreach (['completed','terminated'] as $status) {
        $c=$case('reporting_'.$status);
        $result=$service->generate($request($officer),['report_type'=>'projects','project_id'=>$c['project']])['result'];
        LocalTestRun::assert($section($result,'projects')['rows'][0]['status']===$status,'Final status missing or merged.');
    }
    $dashboard=(new DashboardService($scope))->load($request($admin));
    $result=$service->generate($request($admin),['report_type'=>'comprehensive','year'=>2026])['result'];
    $metrics=array_column($dashboard['metrics'],'value','key');
    LocalTestRun::assert($metrics['associations_total']===$section($result,'associations')['measures'][0]['value'],'Association definitions disagree.');
    LocalTestRun::assert($metrics['members_total']===$section($result,'members')['measures'][0]['value'],'Member definitions disagree.');
    LocalTestRun::assert($metrics['projects_total']===$section($result,'projects')['measures'][0]['value'],'Portfolio definitions disagree.');
    $q=fn($value)=>$pdo->quote((string)$value);
    $account=$actor('account');
    if (!(int)$pdo->query('SELECT count(*) FROM public.association_accounts WHERE user_id='.$q($account->id))->fetchColumn())
        $pdo->exec('INSERT INTO public.association_accounts(user_id,association_id,is_active) VALUES ('.$q($account->id).','.$q($main['association']).',true)');
    LocalTestRun::assert($scope->ids($account,[])===[$main['association']],'Account scope expanded.');
    file_put_contents(dirname($pdo->query("SELECT current_setting('data_directory')")->fetchColumn()).'/export-evidence/association-dashboard.json',
        json_encode((new DashboardService($scope))->load($request($account)),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    foreach (array_keys(ReportCatalog::TYPES) as $type) {
        $filters=['report_type'=>$type];
        if (in_array('year',ReportCatalog::filters($type),true)) $filters['year']=2026;
        $result=$service->generate($request($account),$filters)['result'];
        LocalTestRun::assert($result['meta']['scope']===['Synthetic Peñafrancia Association'],'Account report scope leaked.');
    }
    foreach (['pdf','xlsx'] as $format) LocalTestRun::denied(fn ()=>$service->generate($request($account),['report_type'=>'members'],$format));
    LocalTestRun::denied(fn ()=>$scope->ids($officer,['association_id'=>array_values(array_diff($adminIds,$officerIds))[0]]));
    return ['historical_final_outcomes'=>true,'shared_dashboard_metrics_match'=>true,'account_export_denied'=>true];
});

$tests->check('native quarter date boundaries and empty periods',function () use ($service,$main,$actor,$request,$section) {
    $filters=['report_type'=>'comprehensive','year'=>2026,'quarter'=>1,'project_id'=>$main['project']];
    $first=$service->generate($request($actor('admin')),$filters)['result'];
    LocalTestRun::assert(array_column($section($first,'trainings')['rows'],'date')===['2026-03-31'],'Q1 boundary did not use training start date.');
    LocalTestRun::assert(array_column($section($first,'activities')['rows'],'date')===['2026-03-31'],'Q1 activity boundary mismatch.');
    $filters['quarter']=2;
    $second=$service->generate($request($actor('admin')),$filters)['result'];
    LocalTestRun::assert(array_column($section($second,'trainings')['rows'],'date')===['2026-04-01'],'Q2 boundary included a Q1 training.');
    LocalTestRun::assert($section($second,'activities')['rows']===[],'Q2 included a Q1 activity.');
    $empty=$service->generate($request($actor('admin')),['report_type'=>'trainings','year'=>2099])['result'];
    LocalTestRun::assert($empty['empty'] && $section($empty,'trainings')['rows']===[],'Empty period was presented as recorded data.');
    return ['q1_last_day'=>'2026-03-31','q2_first_day'=>'2026-04-01','empty_period'=>true];
});

$tests->check('actual report release denies assignment revocation after Python and cleans exports',function () use ($scope,$datasets,$main,$actor,$request,$pdo,$q,$identities,$scratch) {
    foreach (['analytics','pdf','xlsx'] as $action) {
        $callback=fn ()=>$pdo->exec('UPDATE public.associations SET primary_field_officer_id='.$q($identities['other_officer']).' WHERE id='.$q($main['association']));
        $changingEngine=new class($callback) extends PythonReportEngine {
            public function __construct(private Closure $changed) {}
            public function run(array $payload,string $actorId): array {
                $result=parent::run($payload,$actorId);
                ($this->changed)();
                return $result;
            }
        };
        try {
            LocalTestRun::denied(fn ()=>(new ReportService($scope,$datasets,$changingEngine))->generate($request($actor('officer')),
                ['report_type'=>'projects','project_id'=>$main['project']],$action));
        } finally {
            $pdo->exec('UPDATE public.associations SET primary_field_officer_id='.$q($identities['officer']).' WHERE id='.$q($main['association']));
        }
    }
    LocalTestRun::assert(glob($scratch->directory.'/reporting-storage/app/private/reporting/*')===[],'Denied release retained a report workspace.');
    return ['analytics_release_denied'=>true,'pdf_release_denied'=>true,'xlsx_release_denied'=>true,'cleanup'=>true];
});

$tests->check('actual Python export failure remains sanitized and releases locks/workspace',function () use ($service,$main,$actor,$request,$app,$scratch) {
    $app['config']->set('reporting.font_regular',$scratch->directory.'/missing-test-font.ttf');
    try {
        $service->generate($request($actor('admin')),['report_type'=>'projects','project_id'=>$main['project']],'pdf');
        throw new RuntimeException('Missing configured font was accepted.');
    } catch (App\Exceptions\ReportingUnavailableException $error) {
        LocalTestRun::assert($error->getMessage()==='font_unavailable' && $error->stage==='export','Failure diagnostics exposed unexpected process text.');
    } finally { $app['config']->set('reporting.font_regular',''); }
    LocalTestRun::assert(glob($scratch->directory.'/reporting-storage/app/private/reporting/*')===[],'Failed export retained a private workspace.');
    $next=$service->generate($request($actor('admin')),['report_type'=>'projects']);
    LocalTestRun::assert(isset($next['result']['sections']),'Failed export retained a user/global lock.');
    return ['error'=>'font_unavailable','stage'=>'export','cleanup'=>true,'locks_released'=>true];
});

$tests->check('authorized finalized GIS maintenance cannot change reporting totals',function () use ($scope,$service,$case,$actor,$request,$section,$pdo) {
    $user=$actor('admin');
    $queries=new App\Services\GisQueryService(new App\Services\GisFeatureSerializer,new App\Services\GisAddressResolver);
    $gis=new App\Services\GisLocationService(new App\Services\GisGeometry,$queries,new ProjectLifecycleService(new AssociationLifecycleService));
    foreach (['completed','terminated'] as $status) {
        $c=$case('reporting_'.$status); $project=Project::findOrFail($c['project'])->getAttributes();
        $filters=['report_type'=>'comprehensive','project_id'=>$c['project'],'year'=>2026];
        $before=$service->generate($request($user),$filters)['result']['sections'];
        $feature=$queries->detail($user,$c['gis']);
        $gis->update($c['gis'],['version'=>$feature['properties']['version'],'project_id'=>$c['project'],
            'parent_association_id'=>$c['association'],'location_type'=>'project_point','location_name'=>'Synthetic reporting-neutral correction',
            'geometry'=>['type'=>'Point','coordinates'=>[124.1,10.6]]],$user,$request($user));
        $after=$service->generate($request($user),$filters)['result']['sections'];
        LocalTestRun::assert(json_encode($before)===json_encode($after),'GIS maintenance changed report rows, charts or totals.');
        LocalTestRun::assert($project===Project::findOrFail($c['project'])->getAttributes(),'GIS changed final project evidence.');
    }
    return ['completed_report_unchanged'=>true,'terminated_report_unchanged'=>true,'native_gis_service'=>true];
});
$tests->finish();
