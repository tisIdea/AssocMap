<?php

declare(strict_types=1);
require __DIR__.'/bootstrap-application.php';
require __DIR__.'/LocalTestRun.php';
use App\Models\Project;
use App\Services\GisAddressResolver;
use App\Services\GisFeatureSerializer;
use App\Services\GisQueryService;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;

$manifest=$argv[1]??''; $scratch=new ScratchDatabase($manifest); scratchApplication($scratch);
$pdo=$scratch->pdo; $tests=new LocalTestRun($scratch->directory.'/concurrency-results.json',$argv[2]??'');
$identities=$pdo->query('SELECT label,id FROM assocmap_test.identities')->fetchAll(PDO::FETCH_KEY_PAIR);
$newCase=function(string $label)use($pdo):array{
    return json_decode($pdo->query('SELECT assocmap_test.make_case('.$pdo->quote('race_'.$label.'_'.bin2hex(random_bytes(3))).')::text')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
};
$start=function(array $job)use($scratch,$manifest):array{
    $stem=$scratch->directory.'/worker-'.bin2hex(random_bytes(6));
    foreach(['pid','ready','release','result']as$field)$job[$field]=$stem.'.'.$field.'.json';
    $jobPath=$stem.'.job.json'; file_put_contents($jobPath,json_encode($job,JSON_THROW_ON_ERROR));
    $process=proc_open([PHP_BINARY,__DIR__.'/worker.php',$manifest,$jobPath],
        [0=>['pipe','r'],1=>['file',$stem.'.stdout.log','w'],2=>['file',$stem.'.stderr.log','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Cannot start isolated worker.');
    fclose($pipes[0]); return ['process'=>$process,'job'=>$job,'stderr'=>$stem.'.stderr.log'];
};
$waitFile=function(string $path,array $worker):void{
    $deadline=microtime(true)+12;
    while(!is_file($path)){
        if(is_file($worker['job']['result']))throw new RuntimeException('Worker finished before barrier: '.file_get_contents($worker['job']['result']));
        if(!proc_get_status($worker['process'])['running'])throw new RuntimeException('Worker exited before barrier: '.file_get_contents($worker['stderr']));
        if(microtime(true)>$deadline)throw new RuntimeException('Worker barrier timed out.');
        usleep(10000);
    }
};
$await=function(array $worker):array{
    $deadline=microtime(true)+15;
    while(!is_file($worker['job']['result'])){
        if(!proc_get_status($worker['process'])['running']||microtime(true)>$deadline)throw new RuntimeException('Worker did not produce a result: '.file_get_contents($worker['stderr']));
        usleep(10000);
    }
    $result=json_decode(file_get_contents($worker['job']['result']),true,512,JSON_THROW_ON_ERROR);
    proc_close($worker['process']);return$result;
};
$blocked=function(array $worker)use($pdo,$waitFile):array{
    $waitFile($worker['job']['pid'],$worker);
    $backend=json_decode(file_get_contents($worker['job']['pid']),true)['pid']; $deadline=microtime(true)+8;
    do{
        $row=$pdo->query('SELECT wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid='.(int)$backend)->fetch(PDO::FETCH_ASSOC);
        if($row && $row['wait_event_type']==='Lock' && $row['blockers']!=='{}')return$row;
        if(is_file($worker['job']['result']))throw new RuntimeException('Expected a real blocked connection: '.file_get_contents($worker['job']['result']));
        usleep(10000);
    }while(microtime(true)<$deadline);
    throw new RuntimeException('No server-observed lock wait.');
};
$release=fn(array $worker,string $mode='commit')=>file_put_contents($worker['job']['release'],$mode);
$version=fn(array$c)=>Project::findOrFail($c['project'])->updated_at->format('Y-m-d\TH:i:s.uP');
$gisVersion=fn(array$c)=>(new GisQueryService(new GisFeatureSerializer,new GisAddressResolver))->detail(UserProfile::with('role')->findOrFail($identities['admin']),$c['gis'])['properties']['version'];
$finalJob=fn(array$c,string$outcome,string$who='admin',bool$hold=false)=>['operation'=>'finalize','project'=>$c['project'],
    'actor'=>$identities[$who],'outcome'=>$outcome,'version'=>$version($c),'hold'=>$hold];
$gisJob=fn(array$c,string$who='officer',bool$hold=false)=>['operation'=>'gis','project'=>$c['project'],'association'=>$c['association'],
    'gis'=>$c['gis'],'actor'=>$identities[$who],'version'=>$gisVersion($c),'hold'=>$hold];

foreach([['completed','terminated'],['terminated','completed'],['completed','completed'],['terminated','terminated']]as[$first,$second]){
    $tests->check('competing actual finalization services '.$first.' / '.$second,function()use($newCase,$start,$waitFile,$await,$blocked,$release,$finalJob,$first,$second,$pdo){
        $c=$newCase('outcomes');$jobA=$finalJob($c,$first,'admin',true);$jobB=$finalJob($c,$second,'officer');
        $a=$start($jobA);$waitFile($a['job']['ready'],$a);$b=$start($jobB);$wait=$blocked($b);
        $release($a);$ra=$await($a);$rb=$await($b);
        LocalTestRun::assert($ra['status']==='PASS'&&$rb['status']==='DENIED'&&$rb['http_status']===409,'Exactly one finalization must win.');
        $row=$pdo->query('SELECT status FROM public.projects WHERE id='.$pdo->quote($c['project']))->fetchColumn();
        $audits=(int)$pdo->query('SELECT count(*) FROM public.audit_logs WHERE entity_id='.$pdo->quote($c['project']))->fetchColumn();
        LocalTestRun::assert($row===$first&&$audits===1,'Final status/audit uniqueness failed.');return['wait'=>$wait,'loser_http'=>409,'audits'=>$audits];
    });
}

foreach(['completed','terminated']as$outcome){
    $tests->check('finalization first then authorized GIS '.$outcome,function()use($newCase,$start,$waitFile,$await,$blocked,$release,$finalJob,$gisJob,$outcome,$pdo){
        $c=$newCase('final_first');$ga=$gisJob($c);$fa=$finalJob($c,$outcome,'admin',true);
        $a=$start($fa);$waitFile($a['job']['ready'],$a);$b=$start($ga);$wait=$blocked($b);$release($a);
        LocalTestRun::assert($await($a)['status']==='PASS'&&$await($b)['status']==='PASS','Final GIS maintenance must succeed after waiting.');
        $row=$pdo->query('SELECT status,closure_remarks FROM public.projects WHERE id='.$pdo->quote($c['project']))->fetch(PDO::FETCH_ASSOC);
        LocalTestRun::assert($row['status']===$outcome&&$row['closure_remarks']==='Synthetic concurrent '.$outcome.' evidence.','GIS changed closure evidence.');return$wait;
    });
    $tests->check('GIS first then finalization '.$outcome,function()use($newCase,$start,$waitFile,$await,$blocked,$release,$finalJob,$gisJob,$outcome){
        $c=$newCase('gis_first');$fa=$finalJob($c,$outcome,'admin');$a=$start($gisJob($c,'officer',true));$waitFile($a['job']['ready'],$a);
        $b=$start($fa);$wait=$blocked($b);$release($a);
        LocalTestRun::assert($await($a)['status']==='PASS'&&$await($b)['status']==='PASS','Pre-finalization GIS must commit and remain preserved.');return$wait;
    });
}

$tests->check('same-version actual GIS editors conflict',function()use($newCase,$start,$waitFile,$await,$blocked,$release,$gisJob){
    $c=$newCase('editors');$ja=$gisJob($c,'admin',true);$jb=$gisJob($c,'officer');
    $a=$start($ja);$waitFile($a['job']['ready'],$a);$b=$start($jb);$wait=$blocked($b);$release($a);
    $ra=$await($a);$rb=$await($b);
    LocalTestRun::assert($ra['status']==='PASS'&&$rb['status']==='DENIED'&&$rb['class']===Symfony\Component\HttpKernel\Exception\HttpException::class,'Stale GIS editor must fail after serialization.');
    return['wait'=>$wait,'stale_error'=>$rb['error']];
});

$tests->check('actual GIS mutation waits for existing per-ID advisory lock',function()use($newCase,$start,$await,$blocked,$gisJob,$pdo){
    $c=$newCase('advisory');$pdo->beginTransaction();
    try{
        $statement=$pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$statement->execute([$c['gis']]);
        $b=$start($gisJob($c));$wait=$blocked($b);
        LocalTestRun::assert($wait['wait_event']==='advisory','GIS did not wait on advisory lock.');
        $pdo->rollBack();LocalTestRun::assert($await($b)['status']==='PASS','GIS failed after advisory release.');return$wait;
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
});

foreach([true,false]as$rollbackFirst){
    $tests->check('actual finalization versus Monitoring; first '.($rollbackFirst?'rolls back':'commits'),function()use($newCase,$start,$waitFile,$await,$blocked,$release,$finalJob,$identities,$rollbackFirst,$pdo){
        $c=$newCase('monitoring');$a=$start($finalJob($c,'completed','admin',true));$waitFile($a['job']['ready'],$a);
        $b=$start(['operation'=>'monitoring','project'=>$c['project'],'monitoring'=>$c['monitoring'],'actor'=>$identities['officer']]);
        $wait=$blocked($b);$release($a,$rollbackFirst?'rollback':'commit');$await($a);$rb=$await($b);
        LocalTestRun::assert($rb['status']===($rollbackFirst?'PASS':'DENIED'),'Monitoring eligibility did not recheck after waiting.');
        $row=$pdo->query('SELECT site_validated FROM public.monitoring_records WHERE id='.$pdo->quote($c['monitoring']))->fetchColumn();
        LocalTestRun::assert($row===$rollbackFirst,'Monitoring result changed contrary to finalization outcome.');return$wait;
    });
}

$tests->check('actual Laravel PostgreSQL transaction retries a real 40P01 deadlock',function()use($newCase,$start,$waitFile,$await,$blocked,$release,$identities,$pdo){
    $c=$newCase('deadlock_retry');$attempts=0;$peer=null;$wait=null;
    DB::transaction(function()use(&$attempts,&$peer,&$wait,$c,$start,$waitFile,$blocked,$release,$identities){
        $attempts++;DB::statement("SET LOCAL deadlock_timeout='100ms'");DB::statement("SET LOCAL lock_timeout='10s'");
        DB::select('SELECT id FROM public.associations WHERE id=? FOR UPDATE',[$c['association']]);
        if($attempts===1){
            $peer=$start(['operation'=>'deadlock_peer','project'=>$c['project'],'actor'=>$identities['admin']]);
            $waitFile($peer['job']['ready'],$peer);$release($peer);$wait=$blocked($peer);
        }
        DB::select('SELECT id FROM public.projects WHERE id=? FOR UPDATE',[$c['project']]);
    },3);
    LocalTestRun::assert($attempts===2,'Expected one deadlock and one successful whole-transaction retry; got '.$attempts);
    LocalTestRun::assert($await($peer)['status']==='PASS','Deadlock peer did not finish.');
    LocalTestRun::assert($pdo->query('SELECT status FROM public.projects WHERE id='.$pdo->quote($c['project']))->fetchColumn()==='completed','Retry/peer state mismatch.');
    return['actual_framework_attempts'=>$attempts,'peer_wait'=>$wait,'deadlock_state'=>'40P01'];
});
$tests->check('additional Monitoring first then actual finalization',function()use($newCase,$start,$waitFile,$await,$blocked,$release,$finalJob,$identities,$pdo){
    $c=$newCase('monitor_first');$f=$finalJob($c,'terminated','admin');
    $a=$start(['operation'=>'monitoring','project'=>$c['project'],'monitoring'=>$c['monitoring'],'actor'=>$identities['officer'],'hold'=>true]);
    $waitFile($a['job']['ready'],$a);$b=$start($f);$wait=$blocked($b);$release($a);
    LocalTestRun::assert($await($a)['status']==='PASS'&&$await($b)['status']==='PASS','Monitoring-first operation/finalization failed.');
    LocalTestRun::assert($pdo->query('SELECT site_validated FROM public.monitoring_records WHERE id='.$pdo->quote($c['monitoring']))->fetchColumn()===true,'Committed monitoring history was not preserved.');return$wait;
});
$tests->check('additional rolled-back finalization then authorized GIS',function()use($newCase,$start,$waitFile,$await,$blocked,$release,$finalJob,$gisJob,$pdo){
    $c=$newCase('rollback_gis');$g=$gisJob($c);$a=$start($finalJob($c,'completed','admin',true));$waitFile($a['job']['ready'],$a);
    $b=$start($g);$wait=$blocked($b);$release($a,'rollback');$await($a);
    LocalTestRun::assert($await($b)['status']==='PASS','GIS could not continue after finalization rollback.');
    $row=$pdo->query('SELECT status,closed_at FROM public.projects WHERE id='.$pdo->quote($c['project']))->fetch(PDO::FETCH_ASSOC);
    LocalTestRun::assert($row['status']==='ongoing'&&$row['closed_at']===null,'Partial closure survived rollback.');
    LocalTestRun::assert((int)$pdo->query("SELECT count(*) FROM public.audit_logs WHERE entity_id=".$pdo->quote($c['project']))->fetchColumn()===0,'Rolled-back final audit survived.');return$wait;
});
foreach([true,false]as$finalFirst){
    $tests->check('additional direct Income trigger versus finalization; final first '.($finalFirst?'yes':'no'),function()use($scratch,$newCase,$start,$waitFile,$await,$release,$finalJob,$blocked,$pdo,$finalFirst){
        $c=$newCase('income_trigger');$native=$scratch->asyncConnection();pg_query($native,'BEGIN');
        $sql="UPDATE public.monitoring_income SET remarks='Synthetic concurrent income' WHERE id=".$pdo->quote($c['income']);
        try{
            if($finalFirst){
                $a=$start($finalJob($c,'completed','admin',true));$waitFile($a['job']['ready'],$a);
                pg_send_query($native,$sql);$pid=pg_get_pid($native);$deadline=microtime(true)+8;$wait=null;
                do{
                    $row=$pdo->query('SELECT wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid='.(int)$pid)->fetch(PDO::FETCH_ASSOC);
                    if($row&&$row['wait_event_type']==='Lock'&&$row['blockers']!=='{}'){$wait=$row;break;}
                    usleep(10000);
                }while(microtime(true)<$deadline);
                LocalTestRun::assert($wait!==null,'Direct descendant trigger did not block on finalization.');$release($a);$await($a);
                $deadline=microtime(true)+8;
                do{pg_consume_input($native);if(!pg_connection_busy($native))break;usleep(10000);}while(microtime(true)<$deadline);
                LocalTestRun::assert(!pg_connection_busy($native),'Direct descendant statement did not finish.');
                $result=pg_get_result($native);$state=pg_result_error_field($result,PGSQL_DIAG_SQLSTATE);
                LocalTestRun::assert($state==='23514','Descendant guard did not see the committed final status.');
                while(pg_get_result($native)!==false){}pg_query($native,'ROLLBACK');
                LocalTestRun::assert($pdo->query('SELECT remarks FROM public.monitoring_income WHERE id='.$pdo->quote($c['income']))->fetchColumn()===null,'Rejected Income write changed history.');
                return['wait'=>$wait,'sqlstate'=>$state];
            }
            pg_query($native,$sql);$a=$start($finalJob($c,'terminated','admin'));$wait=$blocked($a);pg_query($native,'COMMIT');
            LocalTestRun::assert($await($a)['status']==='PASS','Finalization failed after valid descendant write committed.');
            LocalTestRun::assert($pdo->query('SELECT remarks FROM public.monitoring_income WHERE id='.$pdo->quote($c['income']))->fetchColumn()==='Synthetic concurrent income','Committed pre-finalization Income history changed.');return$wait;
        }finally{
            if(pg_connection_busy($native))pg_cancel_query($native);
            while(pg_get_result($native)!==false){}pg_query($native,'ROLLBACK');pg_close($native);
        }
    });
}
$tests->finish();
