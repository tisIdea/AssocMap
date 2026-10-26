<?php

declare(strict_types=1);
require __DIR__.'/bootstrap-application.php';
require __DIR__.'/LocalTestRun.php';

use App\Models\Project;
use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\Associations\AssociationLifecycleService;
use App\Services\Audit\AuditLogService;
use App\Services\GIS\GisAddressResolver;
use App\Services\GIS\GisFeatureSerializer;
use App\Services\GIS\GisGeometry;
use App\Services\GIS\GisLocationService;
use App\Services\GIS\GisQueryService;
use App\Services\Monitoring\MonitoringService;
use App\Services\Monitoring\ProductionMonitoringService;
use App\Services\Projects\ProjectClosureService;
use App\Services\Projects\ProjectLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$scratch = new ScratchDatabase($argv[1] ?? '');
$app = scratchApplication($scratch);
$pdo = $scratch->pdo;
$tests = new LocalTestRun($scratch->directory.'/functional-results.json', $argv[2] ?? '');
$cases = [];
foreach ($pdo->query('SELECT label, ids::text FROM assocmap_test.cases') as $row) $cases[$row['label']] = json_decode($row['ids'], true, 512, JSON_THROW_ON_ERROR);
$identities = $pdo->query('SELECT label, id FROM assocmap_test.identities')->fetchAll(PDO::FETCH_KEY_PAIR);
$q = fn ($value) => $pdo->quote((string) $value);
$actor = fn ($label) => UserProfile::with('role')->findOrFail($identities[$label]);
$request = static function (UserProfile $actor): Request {
    $request = Request::create('/synthetic-local-test', 'PATCH');
    $request->attributes->set('current_user', $actor);
    return $request;
};
$lifecycle = new ProjectLifecycleService(new AssociationLifecycleService);
$closures = new ProjectClosureService($lifecycle, new AssociationLifecycleService, new AuditLogService);
$queries = new GisQueryService(new GisFeatureSerializer, new GisAddressResolver);
$gis = new GisLocationService(new GisGeometry, $queries, $lifecycle);
$fullProject = fn ($id) => $pdo->query('SELECT to_jsonb(p)::text FROM public.projects p WHERE id='.$q($id))->fetchColumn();
$operationalSnapshot = static function () use ($pdo): string {
    $snapshot = json_decode($pdo->query('SELECT assocmap_test.core_snapshot()::text')->fetchColumn(), true);
    unset($snapshot['projects'], $snapshot['gis_locations'], $snapshot['audit_logs']);
    return hash('sha256', json_encode($snapshot));
};

foreach (['completed'=>'completed_candidate','terminated'=>'terminated_candidate'] as $outcome=>$label) {
    $tests->check('actual ProjectClosureService '.$outcome, function () use ($outcome,$label,$cases,$actor,$request,$closures,$fullProject,$operationalSnapshot,$pdo,$q) {
        $project = Project::findOrFail($cases[$label]['project']);
        $before = $project->getAttributes(); $history = $operationalSnapshot();
        $user = $actor($outcome === 'completed' ? 'admin' : 'officer');
        $data = ['closure_remarks'=>'Synthetic verified '.$outcome.' evidence.',
            ($outcome==='completed'?'completion_confirmation':'termination_confirmation')=>($outcome==='completed'?'COMPLETED':'TERMINATE'),
            'expected_updated_at'=>$project->updated_at->format('Y-m-d\TH:i:s.uP')];
        if ($project->status === $outcome) {
            $retained=$fullProject($project->id);
            LocalTestRun::assert($project->closure_remarks===$data['closure_remarks'] && $project->closed_by===$user->id
                && $project->closed_at!==null, 'Existing fixture was not finalized by this harness.');
            LocalTestRun::denied(fn()=> $closures->close($request($user),$project,array_replace($data,['completion_confirmation'=>'COMPLETED'])));
            LocalTestRun::denied(fn()=> $closures->terminate($request($user),$project,array_replace($data,['termination_confirmation'=>'TERMINATE'])));
            LocalTestRun::assert($retained===$fullProject($project->id) && $history===$operationalSnapshot(), 'Repeated finalization changed history.');
            LocalTestRun::assert((int)$pdo->query('SELECT count(*) FROM public.audit_logs WHERE entity_id='.$q($project->id))->fetchColumn()===1,'Repeated finalization added an audit.');
            return ['existing_service_result_rechecked'=>true,'duplicate_and_opposite_outcomes_denied'=>true];
        }
        LocalTestRun::assert($project->status === 'ongoing', 'Do not reset existing finalization fixtures.');
        $final = $outcome === 'completed' ? $closures->close($request($user),$project,$data) : $closures->terminate($request($user),$project,$data);
        LocalTestRun::assert($final->status === $outcome && $final->closed_at !== null && $final->closed_by === $user->id
            && $final->closure_remarks === $data['closure_remarks'], 'Final evidence mismatch.');
        foreach (['status','closed_at','closed_by','closure_remarks','updated_at','updated_by'] as $key) unset($before[$key]);
        $after = $final->getAttributes();
        foreach (['status','closed_at','closed_by','closure_remarks','updated_at','updated_by'] as $key) unset($after[$key]);
        LocalTestRun::assert($before === $after && $history === $operationalSnapshot(), 'Finalization changed project profile/operational history.');
        LocalTestRun::assert((int) $pdo->query('SELECT count(*) FROM public.audit_logs WHERE entity_id='.$q($project->id))->fetchColumn() === 1, 'Required finalization audit count mismatch.');
        return json_decode($fullProject($project->id), true);
    });
}

$outcomeSql = function (array $case, string $outcome, ?string $remarks, ?string $user = null) use ($q,$identities): string {
    return 'UPDATE public.projects SET status='.$q($outcome).', closed_at=now(), closure_remarks='
        .($remarks === null ? 'NULL' : $q($remarks)).', closed_by='.$q($user ?? $identities['admin']).', updated_by='.$q($user ?? $identities['admin'])
        .' WHERE id='.$q($case['project']);
};
foreach (['completed','terminated'] as $outcome) {
    $tests->check($outcome.' actual remarks constraint and invalid transitions', function () use ($pdo,$outcome,$outcomeSql,$cases,$identities,$q) {
        return LocalTestRun::rollback($pdo, function () use ($pdo,$outcome,$outcomeSql,$cases,$identities,$q) {
            $bad = [null,''," \t\n\r",str_repeat('x',2001),"\u{00A0}\u{1680}\u{2007}\u{3000}",
                "\u{00AD}\u{061C}\u{200B}\u{202E}\u{2060}\u{FEFF}",
                "\u{034F}\u{115F}\u{17B4}\u{2800}\u{3164}\u{FE0F}\u{E0100}","\u{E000}\u{F0000}","\u{E0001}\u{E0020}"];
            foreach (array_merge(range(1,31),range(127,159)) as $code) $bad[] = mb_chr($code,'UTF-8');
            foreach ($bad as $remarks) LocalTestRun::sqlState($pdo,$outcomeSql($cases['other_ongoing'],$outcome,$remarks));
            foreach (['Visible evidence.',str_repeat('x',2000),"\u{00D1}","Visible\nline breaks."] as $remarks) {
                $pdo->exec('SAVEPOINT valid_remarks'); $pdo->exec($outcomeSql($cases['other_ongoing'],$outcome,$remarks));
                $pdo->exec('ROLLBACK TO SAVEPOINT valid_remarks'); $pdo->exec('RELEASE SAVEPOINT valid_remarks');
            }
            foreach (['planned','suspended','cancelled','archived_ongoing','inactive_parent','archived_parent'] as $label)
                LocalTestRun::sqlState($pdo,$outcomeSql($cases[$label],$outcome,'Valid evidence.'));
            foreach (['other_officer','account'] as $label)
                LocalTestRun::sqlState($pdo,$outcomeSql($cases['other_ongoing'],$outcome,'Valid evidence.',$identities[$label]));
            $pdo->exec('UPDATE public.user_profiles SET is_active=false WHERE id='.$q($identities['cleanup_actor']));
            LocalTestRun::sqlState($pdo,$outcomeSql($cases['other_ongoing'],$outcome,'Valid evidence.',$identities['cleanup_actor']));
            return ['invalid_remarks'=>count($bad),'valid_remarks'=>4,'invalid_transitions'=>6,'unauthorized_actors'=>3];
        });
    });
}

foreach (['completed_candidate','terminated_candidate','legacy_completed'] as $label) {
    $tests->check($label.' immutable project profile/evidence and deletion', function () use ($pdo,$label,$cases,$q,$identities) {
        return LocalTestRun::rollback($pdo,function () use ($pdo,$label,$cases,$q,$identities) {
            foreach (["title=title||' tamper'","status='ongoing'","archived_at=now()","implementation_end_date='2026-12-31'",
                'association_id='.$q($identities['other_association']),"closure_remarks='tampered'",
                'closed_by='.$q($identities['other_officer']), 'created_by='.$q($identities['other_officer'])] as $change)
                LocalTestRun::sqlState($pdo,'UPDATE public.projects SET '.$change.' WHERE id='.$q($cases[$label]['project']));
            LocalTestRun::sqlState($pdo,'DELETE FROM public.projects WHERE id='.$q($cases[$label]['project']),['23514','23503','23001']);
            return ['mutations_rejected'=>9,'legacy_metadata_null'=>$label==='legacy_completed'];
        });
    });
}

function operations(array $c, array $d, callable $q): array
{
    $p=$q($c['project']); $dest=$q($d['project']); $a=$q($c['association']); $saad=(int)$c['saad'];
    $id=fn($key)=>$q($c[$key]); $to=fn($key)=>$q($d[$key]);
    return [
        'project_products'=>[
            'INSERT'=>"INSERT INTO public.project_products(project_id,product_name) VALUES ($p,'Synthetic insert '||gen_random_uuid())",
            'UPDATE'=>"UPDATE public.project_products SET description='Changed' WHERE id=".$id('product'),
            'DELETE'=>"DELETE FROM public.project_products WHERE id=".$id('product_insert'),
            'REASSIGN'=>"UPDATE public.project_products SET project_id=$dest,product_name='Synthetic transfer '||gen_random_uuid() WHERE id=".$id('product_insert')],
        'project_materials'=>[
            'INSERT'=>"INSERT INTO public.project_materials(project_id,item_name,quantity_planned,unit) VALUES ($p,'Synthetic insert',1,'pieces')",
            'UPDATE'=>"UPDATE public.project_materials SET remarks='Changed' WHERE id=".$id('material'),
            'DELETE'=>"DELETE FROM public.project_materials WHERE id=".$id('material_extra'),
            'REASSIGN'=>"UPDATE public.project_materials SET project_id=$dest WHERE id=".$id('material_extra')],
        'trainings'=>[
            'INSERT'=>"INSERT INTO public.trainings(association_id,related_project_id,saad_activity_id,title,program_year,start_date) VALUES ($a,$p,$saad,'Synthetic insert',2026,'2026-01-01')",
            'UPDATE'=>"UPDATE public.trainings SET title='Changed' WHERE id=".$id('training'),
            'DELETE'=>"DELETE FROM public.trainings WHERE id=".$id('training_extra'),
            'DETACH'=>"UPDATE public.trainings SET related_project_id=NULL WHERE id=".$id('training_extra'),
            'REASSIGN'=>"UPDATE public.trainings SET related_project_id=$dest WHERE id=".$id('training_extra')],
        'training_participants'=>[
            'INSERT'=>"INSERT INTO public.training_participants(training_id,member_id) VALUES (".$id('training').','.$id('member_extra').')',
            'UPDATE'=>"UPDATE public.training_participants SET remarks='Changed' WHERE id=".$id('participant'),
            'DELETE'=>"DELETE FROM public.training_participants WHERE id=".$id('participant'),
            'REASSIGN'=>"UPDATE public.training_participants SET training_id=".$to('training_extra').' WHERE id='.$id('participant')],
        'program_activity_records'=>[
            'INSERT'=>"INSERT INTO public.program_activity_records(saad_activity_id,association_id,related_project_id,title,program_year,activity_date,status) VALUES ($saad,$a,$p,'Synthetic insert',2026,'2026-01-01','planned')",
            'UPDATE'=>"UPDATE public.program_activity_records SET title='Changed' WHERE id=".$id('activity'),
            'DELETE'=>"DELETE FROM public.program_activity_records WHERE id=".$id('activity'),
            'DETACH'=>"UPDATE public.program_activity_records SET related_project_id=NULL WHERE id=".$id('activity'),
            'REASSIGN'=>"UPDATE public.program_activity_records SET related_project_id=$dest WHERE id=".$id('activity')],
        'monitoring_records'=>[
            'INSERT'=>"INSERT INTO public.monitoring_records(project_id,year,quarter_id,monitoring_date,project_status) VALUES ($p,2029,1,'2029-01-01','ongoing')",
            'UPDATE'=>"UPDATE public.monitoring_records SET overall_remarks='Changed' WHERE id=".$id('monitoring'),
            'DELETE'=>"DELETE FROM public.monitoring_records WHERE id=".$id('monitoring_extra'),
            'REASSIGN'=>"UPDATE public.monitoring_records SET project_id=$dest,year=2029,monitoring_date='2029-04-15' WHERE id=".$id('monitoring_extra')],
        'monitoring_production'=>[
            'INSERT'=>"INSERT INTO public.monitoring_production(monitoring_record_id,project_product_id,target_output,actual_output,unit) VALUES (".$id('monitoring').','.$id('product_insert').",1,0,'kg')",
            'UPDATE'=>"UPDATE public.monitoring_production SET remarks='Changed' WHERE id=".$id('production'),
            'DELETE'=>"DELETE FROM public.monitoring_production WHERE id=".$id('production_extra'),
            'REASSIGN'=>"UPDATE public.monitoring_production SET monitoring_record_id=".$to('monitoring').',project_product_id='.$to('product_insert').' WHERE id='.$id('production_extra')],
        'monitoring_production_monthly'=>[
            'INSERT'=>"INSERT INTO public.monitoring_production_monthly(monitoring_production_id,month,activity_count,total_quantity) VALUES (".$id('production_extra').',1,1,0)',
            'UPDATE'=>"UPDATE public.monitoring_production_monthly SET remarks='Changed' WHERE id=".$id('monthly'),
            'DELETE'=>"DELETE FROM public.monitoring_production_monthly WHERE id=".$id('monthly'),
            'REASSIGN'=>"UPDATE public.monitoring_production_monthly SET monitoring_production_id=".$to('production_extra').' WHERE id='.$id('monthly')],
        'monitoring_income'=>[
            'INSERT'=>"INSERT INTO public.monitoring_income(monitoring_production_id,period_type,gross_income) VALUES (".$id('production_extra').",'quarterly',1)",
            'UPDATE'=>"UPDATE public.monitoring_income SET remarks='Changed' WHERE id=".$id('income'),
            'DELETE'=>"DELETE FROM public.monitoring_income WHERE id=".$id('income'),
            'REASSIGN'=>"UPDATE public.monitoring_income SET monitoring_production_id=".$to('production_extra').' WHERE id='.$id('income')],
        'monitoring_materials'=>[
            'INSERT'=>"INSERT INTO public.monitoring_materials(monitoring_record_id,project_material_id,condition) VALUES (".$id('monitoring').','.$id('material_extra').",'good')",
            'UPDATE'=>"UPDATE public.monitoring_materials SET remarks='Changed' WHERE id=".$id('monitoring_material'),
            'DELETE'=>"DELETE FROM public.monitoring_materials WHERE id=".$id('monitoring_material'),
            'REASSIGN'=>"UPDATE public.monitoring_materials SET monitoring_record_id=".$to('monitoring').',project_material_id='.$to('material_extra').' WHERE id='.$id('monitoring_material')],
        'monitoring_visit_reports'=>[
            'INSERT'=>"INSERT INTO public.monitoring_visit_reports(monitoring_record_id,monitoring_date) VALUES (".$id('monitoring').",'2026-02-01')",
            'UPDATE'=>"UPDATE public.monitoring_visit_reports SET overall_remarks='Changed' WHERE id=".$id('visit'),
            'DELETE'=>"DELETE FROM public.monitoring_visit_reports WHERE id=".$id('visit'),
            'REASSIGN'=>"UPDATE public.monitoring_visit_reports SET monitoring_record_id=".$to('monitoring').' WHERE id='.$id('visit')],
    ];
}

foreach (['ongoing','completed_candidate','terminated_candidate','legacy_completed'] as $label) {
    foreach (operations($cases[$label],$cases['other_ongoing'],$q) as $table=>$operations) {
        $tests->check($label.' '.$table.' INSERT/UPDATE/DELETE/relationship matrix', function () use ($pdo,$label,$operations) {
            return LocalTestRun::rollback($pdo,function () use ($pdo,$label,$operations) {
                foreach ($operations as $operation=>$sql) {
                    if ($label === 'ongoing') {
                        $pdo->exec('SAVEPOINT ongoing_operation');
                        LocalTestRun::assert($pdo->exec($sql) === 1, 'Valid ongoing operation must affect one row: '.$operation);
                        $pdo->exec('ROLLBACK TO SAVEPOINT ongoing_operation'); $pdo->exec('RELEASE SAVEPOINT ongoing_operation');
                    } else LocalTestRun::sqlState($pdo,$sql);
                }
                return array_keys($operations);
            });
        });
    }
}

foreach (['completed_candidate','terminated_candidate','ongoing'] as $label) foreach (['admin','officer'] as $who) {
    $tests->check('actual GIS service '.$label.' '.$who, function () use ($gis,$queries,$cases,$actor,$request,$label,$who) {
        return LocalTestRun::appRollback(function () use ($gis,$queries,$cases,$actor,$request,$label,$who) {
            $c=$cases[$label]; $user=$actor($who);
            $projectBefore=Project::findOrFail($c['project'])->getAttributes();
            $historyBefore=json_decode(DB::selectOne('SELECT assocmap_test.core_snapshot()::text AS value')->value,true);
            unset($historyBefore['gis_locations'],$historyBefore['audit_logs']);
            $data=['idempotency_key'=>(string)Str::uuid(),'project_id'=>$c['project'],'parent_association_id'=>$c['association'],
                'location_type'=>'project_point','location_name'=>'Synthetic service site','public_title'=>'Approved synthetic site',
                'geometry'=>['type'=>'Point','coordinates'=>[123.8,10.3]]];
            $created=$gis->store($data,$user,$request($user)); $id=$created['feature']['id'];
            LocalTestRun::assert($created['created'] && ! $gis->store($data,$user,$request($user))['created'],'Idempotency contract mismatch.');
            $data['version']=$created['feature']['properties']['version']; $data['location_name']='Corrected synthetic site';
            $data['geometry']['coordinates']=[124.1,10.6];
            $feature=$gis->update($id,$data,$user,$request($user));
            LocalTestRun::assert($feature['geometry']['coordinates']===[124.1,10.6],'Actual PostGIS coordinate correction mismatch.');
            $feature=$gis->lifecycle($id,'publish',$feature['properties']['version'],$user,$request($user));
            LocalTestRun::assert($feature['properties']['is_published'],'Publication failed.');
            $feature=$gis->lifecycle($id,'unpublish',$feature['properties']['version'],$user,$request($user));
            LocalTestRun::assert(! $feature['properties']['is_published'],'Unpublication failed.');
            if ($label === 'ongoing') {
                $gis->destroy($id,$feature['properties']['version'],$user);
                LocalTestRun::assert(! DB::table('gis_locations')->where('id',$id)->exists(),'Eligible GIS deletion failed.');
            } else {
                LocalTestRun::denied(fn()=> $gis->destroy($id,$feature['properties']['version'],$user));
                $feature=$gis->lifecycle($id,'archive',$feature['properties']['version'],$user,$request($user));
                LocalTestRun::assert($feature['properties']['archived_at']!==null,'Authorized GIS archive failed.');
            }
            LocalTestRun::assert($projectBefore===Project::findOrFail($c['project'])->getAttributes(),'GIS changed closure metadata/profile.');
            $historyAfter=json_decode(DB::selectOne('SELECT assocmap_test.core_snapshot()::text AS value')->value,true);
            unset($historyAfter['gis_locations'],$historyAfter['audit_logs']);
            LocalTestRun::assert($historyBefore===$historyAfter,'GIS changed operational records.');
            return ['native_postgis'=>true,'actual_schema_deletion_preflight'=>true,'actor'=>$who];
        });
    });
}

foreach (['completed_candidate','terminated_candidate'] as $label) {
    $tests->check('actual GIS denials '.$label,function () use ($gis,$queries,$cases,$actor,$request,$label) {
        return LocalTestRun::appRollback(function () use ($gis,$queries,$cases,$actor,$request,$label) {
            $c=$cases[$label]; $admin=$actor('admin'); $feature=$queries->detail($admin,$c['gis']); $version=$feature['properties']['version'];
            $data=['project_id'=>$c['project'],'parent_association_id'=>$c['association'],'location_type'=>'project_point',
                'location_name'=>'Synthetic correction','version'=>$version,'geometry'=>['type'=>'Point','coordinates'=>[124,11]]];
            foreach (['other_officer','account'] as $who) {
                $user=$actor($who);
                LocalTestRun::denied(fn()=> $gis->update($c['gis'],$data,$user,$request($user)));
                LocalTestRun::denied(fn()=> $gis->lifecycle($c['gis'],'publish',$version,$user,$request($user)));
            }
            LocalTestRun::denied(fn()=> $gis->update($c['gis'],array_replace($data,['project_id'=>$cases['ongoing']['project']]),$admin,$request($admin)));
            LocalTestRun::denied(fn()=> $gis->update($c['gis'],array_replace($data,['project_id'=>null]),$admin,$request($admin)));
            LocalTestRun::denied(fn()=> $gis->update($c['gis'],array_replace($data,['version'=>'stale']),$admin,$request($admin)));
            LocalTestRun::denied(fn()=> $gis->destroy($c['gis'],$version,$admin));
            DB::table('associations')->where('id',$c['association'])->update(['status'=>'inactive']);
            LocalTestRun::denied(fn()=> $gis->lifecycle($c['gis'],'publish',$version,$admin,$request($admin)));
            return ['authorization_and_identity'=>'denied','association_eligibility'=>'preserved'];
        });
    });
}

$tests->check('legacy closure metadata and all-table triggers/security catalogs',function () use ($pdo,$cases,$q) {
    $legacy=$pdo->query('SELECT status,closed_at,closure_remarks,closed_by FROM public.projects WHERE id='.$q($cases['legacy_completed']['project']))->fetch(PDO::FETCH_ASSOC);
    LocalTestRun::assert($legacy===['status'=>'completed','closed_at'=>null,'closure_remarks'=>null,'closed_by'=>null],'Legacy metadata fabricated.');
    $functions=$pdo->query("SELECT proname,prosecdef,proconfig::text FROM pg_proc WHERE oid IN
        ('public.project_history_project_ids(text,jsonb)'::regprocedure,'public.protect_finalized_project_history()'::regprocedure,'public.protect_project_completion()'::regprocedure)")->fetchAll(PDO::FETCH_ASSOC);
    return $functions;
});
$tests->check('native Production service zero activities, totals, Income and revision', function () use ($cases,$actor) {
    return LocalTestRun::appRollback(function () use ($cases,$actor) {
        $productionService = new ProductionMonitoringService;
        $monitoring = new MonitoringService(new AssociationLifecycleService, new ProjectLifecycleService(new AssociationLifecycleService), $productionService);
        $record = MonitoringRecord::findOrFail($cases['ongoing']['monitoring']);
        $production = $record->production()->with('monthlyDetails')->findOrFail($cases['ongoing']['production']);
        $months = $production->monthlyDetails->sortBy('month')->values();
        $retained = fn () => json_encode([
            DB::table('monitoring_income')->where('monitoring_production_id',$production->id)->orderBy('id')->get(),
            DB::table('monitoring_production')->where('id',$cases['ongoing']['production_extra'])->first(),
        ], JSON_THROW_ON_ERROR);
        $history = $retained();
        $data = ['production_revision'=>$productionService->revision($record->fresh()), 'project_status'=>'ongoing',
            'production'=>[['id'=>$production->id,'project_product_id'=>$production->project_product_id,
                'unit'=>$production->unit,'target_output'=>'100','actual_output'=>'999999',
                'income'=>[['gross_income'=>'999999']],
                'monthly_details'=>$months->map(fn ($month,$index) => ['id'=>$month->id,'month'=>$month->month,
                    'activity_count'=>$index===0 ? 0 : 1,'total_quantity'=>['0','20.20','30.30'][$index]])->all()]]];
        $monitoring->updateSection($record,'production',$data,$actor('admin'));
        $saved = $production->fresh('monthlyDetails');
        LocalTestRun::assert($saved->actual_output==='50.50', 'Monthly fixed-point total was not authoritative.');
        LocalTestRun::assert($saved->monthlyDetails->sortBy('month')->pluck('id')->values()->all()===$months->pluck('id')->all(), 'Monthly identities changed.');
        LocalTestRun::assert($saved->monthlyDetails->firstWhere('month',1)->activity_count===0, 'Zero activities were not retained.');
        LocalTestRun::assert($history===$retained(), 'Production save altered Income or omitted legacy Production.');
        try { $monitoring->updateSection($record->fresh(),'production',$data,$actor('admin')); throw new RuntimeException('Stale Production revision accepted.'); }
        catch (Illuminate\Validation\ValidationException $error) { LocalTestRun::assert($error->status===409, 'Stale revision did not return 409.'); }
        foreach (['completed_candidate','terminated_candidate','legacy_completed'] as $label) {
            $final = MonitoringRecord::findOrFail($cases[$label]['monitoring']);
            LocalTestRun::denied(fn () => $monitoring->updateSection($final,'production',[],$actor('admin')));
        }
        return ['zero_activity_month'=>true,'actual_output'=>'50.50','submitted_total_ignored'=>true,
            'income_and_omitted_legacy_unchanged'=>true,'stale_revision_status'=>409,'finalized_production_denied'=>true];
    });
});
$tests->finish();
