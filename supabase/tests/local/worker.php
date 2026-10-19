<?php

declare(strict_types=1);
require __DIR__.'/bootstrap-application.php';
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\AssociationLifecycleService;
use App\Services\AuditLogService;
use App\Services\GisAddressResolver;
use App\Services\GisFeatureSerializer;
use App\Services\GisGeometry;
use App\Services\GisLocationService;
use App\Services\GisQueryService;
use App\Services\ProjectClosureService;
use App\Services\ProjectLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$scratch = new ScratchDatabase($argv[1] ?? '');
$jobPath = realpath($argv[2] ?? '');
if ($jobPath === false || dirname($jobPath) !== $scratch->directory) throw new RuntimeException('Worker job must be in the verified run directory.');
$job=json_decode(file_get_contents($jobPath),true,512,JSON_THROW_ON_ERROR);
foreach (['pid','ready','release','result'] as $field) {
    if (dirname($job[$field]) !== $scratch->directory) throw new RuntimeException('Unsafe worker IPC path.');
}
$result=[];
try {
    if ($job['operation']==='deadlock_peer') {
        $pdo=$scratch->pdo; $pdo->beginTransaction();
        $pdo->exec("SET LOCAL deadlock_timeout='5s'; SET LOCAL lock_timeout='10s'");
        $pdo->query('SELECT id FROM public.projects WHERE id='.$pdo->quote($job['project']).' FOR UPDATE')->fetchAll();
        file_put_contents($job['pid'],json_encode(['pid'=>(int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()]));
        file_put_contents($job['ready'],'project locked');
        $deadline=microtime(true)+15;
        while (!is_file($job['release'])) { if(microtime(true)>$deadline) throw new RuntimeException('Deadlock peer release timed out.'); usleep(10000); }
        $pdo->exec('UPDATE public.projects SET status=\'completed\',closed_at=now(),closure_remarks=\'Synthetic retry peer evidence.\',closed_by='
            .$pdo->quote($job['actor']).',updated_by='.$pdo->quote($job['actor']).' WHERE id='.$pdo->quote($job['project']));
        $pdo->commit(); $result=['status'=>'PASS','operation'=>'deadlock_peer'];
    } else {
        scratchApplication($scratch);
        file_put_contents($job['pid'],json_encode(['pid'=>(int)DB::selectOne('SELECT pg_backend_pid() AS id')->id]));
        $user=UserProfile::with('role')->findOrFail($job['actor']);
        $request=Request::create('/synthetic-concurrency','PATCH'); $request->attributes->set('current_user',$user);
        $lifecycle=new ProjectLifecycleService(new AssociationLifecycleService);
        if ($job['hold'] ?? false) DB::beginTransaction();
        if ($job['operation']==='finalize') {
            $project=Project::findOrFail($job['project']);
            $data=['closure_remarks'=>'Synthetic concurrent '.$job['outcome'].' evidence.',
                ($job['outcome']==='completed'?'completion_confirmation':'termination_confirmation')=>($job['outcome']==='completed'?'COMPLETED':'TERMINATE'),
                'expected_updated_at'=>$job['version']];
            $service=new ProjectClosureService($lifecycle,new AssociationLifecycleService,new AuditLogService);
            $value=$job['outcome']==='completed' ? $service->close($request,$project,$data) : $service->terminate($request,$project,$data);
            $result=['status'=>'PASS','outcome'=>$value->status,'closed_at'=>$value->closed_at->format('c')];
        } elseif ($job['operation']==='gis') {
            $queries=new GisQueryService(new GisFeatureSerializer,new GisAddressResolver);
            $service=new GisLocationService(new GisGeometry,$queries,$lifecycle);
            $value=$service->update($job['gis'],['project_id'=>$job['project'],'parent_association_id'=>$job['association'],
                'location_type'=>'project_point','location_name'=>$job['name'] ?? 'Synthetic concurrent GIS correction',
                'geometry'=>['type'=>'Point','coordinates'=>[124.2,10.7]],'public_title'=>'Synthetic approved title',
                'version'=>$job['version']],$user,$request);
            $result=['status'=>'PASS','version'=>$value['properties']['version']];
        } elseif ($job['operation']==='monitoring') {
            $service=new App\Services\MonitoringService(new AssociationLifecycleService,$lifecycle,new App\Services\ProductionMonitoringService);
            $service->updateOverview(App\Models\MonitoringRecord::findOrFail($job['monitoring']),
                ['monitored_by'=>null,'site_validated'=>true],$user);
            $result=['status'=>'PASS','site_validated'=>true];
        } else throw new RuntimeException('Unknown worker operation.');
        file_put_contents($job['ready'],'operation complete');
        if ($job['hold'] ?? false) {
            $deadline=microtime(true)+15;
            while(!is_file($job['release'])) { if(microtime(true)>$deadline) throw new RuntimeException('Worker release timed out.'); usleep(10000); }
            if(trim(file_get_contents($job['release']))==='rollback') { DB::rollBack(); $result['rolled_back']=true; }
            else DB::commit();
        }
    }
} catch (Throwable $error) {
    if ($scratch->pdo->inTransaction()) $scratch->pdo->rollBack();
    if (class_exists(DB::class) && DB::getFacadeRoot() && DB::transactionLevel()>0) while(DB::transactionLevel()>0) DB::rollBack();
    $result=['status'=>'DENIED','class'=>get_class($error),'code'=>$error->getCode(),
        'http_status'=>$error instanceof Illuminate\Validation\ValidationException ? $error->status : null,
        'error'=>$error->getMessage()];
}
file_put_contents($job['result'],json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
