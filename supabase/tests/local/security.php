<?php

declare(strict_types=1);
require __DIR__.'/bootstrap-application.php';
require __DIR__.'/LocalTestRun.php';
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

$scratch=new ScratchDatabase($argv[1]??'');scratchApplication($scratch);$pdo=$scratch->pdo;
$tests=new LocalTestRun($scratch->directory.'/security-results.json',$argv[2]??'');
$ids=$pdo->query('SELECT label,id FROM assocmap_test.identities')->fetchAll(PDO::FETCH_KEY_PAIR);
$cases=[];foreach($pdo->query('SELECT label,ids::text FROM assocmap_test.cases')as$row)$cases[$row['label']]=json_decode($row['ids'],true);
$q=fn($value)=>$pdo->quote((string)$value);
$actor=fn()=>UserProfile::with('role')->findOrFail($ids['admin']);
$request=static function($actor){$r=Request::create('/synthetic-security','PATCH');$r->attributes->set('current_user',$actor);return$r;};
$lifecycle=new ProjectLifecycleService(new AssociationLifecycleService);
$queries=new GisQueryService(new GisFeatureSerializer,new GisAddressResolver);
$gis=new GisLocationService(new GisGeometry,$queries,$lifecycle);
$closures=new ProjectClosureService($lifecycle,new AssociationLifecycleService,new AuditLogService);

foreach(['completed','terminated']as$outcome){
    $tests->check('bare '.$outcome.' project cannot be deleted without dependent FKs',function()use($pdo,$cases,$q,$ids,$outcome){
        return LocalTestRun::rollback($pdo,function()use($pdo,$cases,$q,$ids,$outcome){
            $id=$pdo->query('INSERT INTO public.projects(association_id,saad_activity_id,fisheries_category_id,title,program_year,implementation_start_date,status)
                SELECT association_id,saad_activity_id,fisheries_category_id,\'Synthetic bare delete test\',2026,\'2026-01-01\',\'ongoing\'
                FROM public.projects WHERE id='.$q($cases['ongoing']['project']).' RETURNING id')->fetchColumn();
            $pdo->exec('UPDATE public.projects SET status='.$q($outcome).',closed_at=now(),closed_by='.$q($ids['admin']).",closure_remarks='Synthetic evidence.' WHERE id=".$q($id));
            LocalTestRun::sqlState($pdo,'DELETE FROM public.projects WHERE id='.$q($id),['23514'],'cannot be deleted');
            return['sqlstate'=>'23514','dependent_child_rows'=>0];
        });
    });
}

$tests->check('private functions and untrusted client roles',function()use($pdo){
    return LocalTestRun::rollback($pdo,function()use($pdo){
        foreach(['anon','authenticated']as$role){
            $pdo->exec('SET LOCAL ROLE '.$role);
            LocalTestRun::sqlState($pdo,"SELECT public.project_history_project_ids('gis_locations','{}'::jsonb)",['42501'],'permission denied for function');
            LocalTestRun::sqlState($pdo,'SELECT * FROM public.projects LIMIT 1',['42501'],'permission denied');
            $pdo->exec('RESET ROLE');
        }
        $rows=$pdo->query("SELECT proname,prosecdef,proconfig::text,has_function_privilege('anon',oid,'EXECUTE') AS anon,
            has_function_privilege('authenticated',oid,'EXECUTE') AS authenticated FROM pg_proc WHERE oid IN
            ('public.project_history_project_ids(text,jsonb)'::regprocedure,'public.protect_finalized_project_history()'::regprocedure,'public.protect_project_completion()'::regprocedure)")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as$row)LocalTestRun::assert(!$row['anon']&&!$row['authenticated']&&str_contains($row['proconfig'],'pg_temp')&&str_contains($row['proconfig'],'row_security=off'),'Function privacy/configuration mismatch.');
        return$rows;
    });
});

$tests->check('SECURITY DEFINER normal owner versus FORCE RLS fails closed',function()use($pdo,$cases,$q){
    return LocalTestRun::rollback($pdo,function()use($pdo,$cases,$q){
        $pdo->exec('CREATE ROLE assocmap_local_rls_owner NOLOGIN NOSUPERUSER NOBYPASSRLS');
        $pdo->exec('GRANT USAGE,CREATE ON SCHEMA public TO assocmap_local_rls_owner');
        $pdo->exec('GRANT USAGE ON SCHEMA extensions TO assocmap_local_rls_owner');
        $pdo->exec('GRANT SELECT,UPDATE ON ALL TABLES IN SCHEMA public TO assocmap_local_rls_owner');
        foreach(['projects','gis_locations','monitoring_records','project_products','project_materials','trainings',
            'training_participants','program_activity_records','monitoring_visit_reports','monitoring_production',
            'monitoring_production_monthly','monitoring_income','monitoring_materials','user_profiles']as$table)
            $pdo->exec('ALTER TABLE public.'.$table.' OWNER TO assocmap_local_rls_owner');
        $pdo->exec('ALTER FUNCTION public.project_history_project_ids(text,jsonb) OWNER TO assocmap_local_rls_owner');
        $pdo->exec('ALTER FUNCTION public.protect_finalized_project_history() OWNER TO assocmap_local_rls_owner');
        $row=$pdo->query("SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname='assocmap_local_rls_owner'")->fetch(PDO::FETCH_ASSOC);
        LocalTestRun::assert(!$row['rolsuper']&&!$row['rolbypassrls'],'RLS variant accidentally has bypass.');
        $c=$cases['completed_candidate'];
        LocalTestRun::sqlState($pdo,"UPDATE public.monitoring_records SET overall_remarks='tamper' WHERE id=".$q($c['monitoring']));
        LocalTestRun::assert($pdo->exec("UPDATE public.gis_locations SET location_name='Synthetic owner GIS correction' WHERE id=".$q($c['gis']))===1,'Normal nonbypass owner GIS maintenance failed.');
        $pdo->exec('ALTER TABLE public.projects FORCE ROW LEVEL SECURITY');
        LocalTestRun::sqlState($pdo,"UPDATE public.monitoring_records SET overall_remarks='tamper' WHERE id=".$q($c['monitoring']),['42501'],'row-level security');
        LocalTestRun::sqlState($pdo,"UPDATE public.gis_locations SET location_name='hidden ancestry' WHERE id=".$q($c['gis']),['42501'],'row-level security');
        return['owner_bypass_without_force'=>'integrity guard works','force_rls_without_bypass'=>'42501 fail closed'];
    });
});

$tests->check('SECURITY INVOKER cannot mistake an RLS-hidden actor for a deleted profile',function()use($pdo,$cases,$q){
    return LocalTestRun::rollback($pdo,function()use($pdo,$cases,$q){
        $pdo->exec('CREATE ROLE assocmap_local_limited_writer NOLOGIN NOSUPERUSER NOBYPASSRLS');
        $pdo->exec('GRANT USAGE ON SCHEMA public TO assocmap_local_limited_writer');
        $pdo->exec('GRANT SELECT,UPDATE ON public.projects TO assocmap_local_limited_writer');
        $pdo->exec('GRANT SELECT ON public.user_profiles TO assocmap_local_limited_writer');
        $pdo->exec('GRANT SELECT ON public.commodities TO assocmap_local_limited_writer');
        $pdo->exec('CREATE POLICY scratch_project_access ON public.projects TO assocmap_local_limited_writer USING(true) WITH CHECK(true)');
        $pdo->exec('ALTER TABLE public.user_profiles FORCE ROW LEVEL SECURITY');
        $pdo->exec('SET LOCAL ROLE assocmap_local_limited_writer');
        LocalTestRun::sqlState($pdo,'UPDATE public.projects SET closed_by=NULL WHERE id='.$q($cases['completed_candidate']['project']),['42501'],'row-level security');
        LocalTestRun::sqlState($pdo,'UPDATE public.projects SET commodity_id=NULL WHERE id='.$q($cases['completed_candidate']['project']),['42501'],'row-level security');
        $pdo->exec('RESET ROLE');return['hidden_profile'=>'42501 fail closed','hidden_commodity'=>'42501 fail closed'];
    });
});

$tests->check('temporary shadow tables do not hide finalized ancestry',function()use($pdo,$cases,$q){
    return LocalTestRun::rollback($pdo,function()use($pdo,$cases,$q){
        $pdo->exec('CREATE TEMP TABLE projects(id uuid,status text,closed_at timestamptz)');
        $pdo->exec('INSERT INTO pg_temp.projects VALUES('.$q($cases['completed_candidate']['project']).",'ongoing',NULL)");
        LocalTestRun::sqlState($pdo,"UPDATE public.monitoring_records SET overall_remarks='tamper' WHERE id=".$q($cases['completed_candidate']['monitoring']));
        return['qualified_public_ancestor'=>'protected'];
    });
});

$tests->check('genuine profile FK SET NULL cleanup preserves finalized evidence/business data',function()use($pdo,$q,$ids){
    return LocalTestRun::rollback($pdo,function()use($pdo,$q,$ids){
        $c=json_decode($pdo->query("SELECT assocmap_test.make_case('cleanup_".bin2hex(random_bytes(4))."')::text")->fetchColumn(),true);
        $profile=$q($ids['cleanup_actor']);
        foreach(['projects'=>'project','trainings'=>'training','program_activity_records'=>'activity',
            'monitoring_records'=>'monitoring','monitoring_visit_reports'=>'visit','gis_locations'=>'gis']as$table=>$key)
            $pdo->exec('UPDATE public.'.$table.' SET created_by='.$profile.',updated_by='.$profile.' WHERE id='.$q($c[$key]));
        $pdo->exec("UPDATE public.projects SET status='completed',closed_at=now(),closure_remarks='Retained cleanup evidence.',closed_by=$profile WHERE id=".$q($c['project']));
        $before=$pdo->query('SELECT closed_at FROM public.projects WHERE id='.$q($c['project']))->fetchColumn();
        $pdo->exec('DELETE FROM auth.users WHERE id='.$profile);
        $row=$pdo->query('SELECT status,closed_at,closure_remarks,closed_by,created_by,updated_by FROM public.projects WHERE id='.$q($c['project']))->fetch(PDO::FETCH_ASSOC);
        LocalTestRun::assert($row['status']==='completed'&&$row['closed_at']===$before&&$row['closure_remarks']==='Retained cleanup evidence.'
            &&$row['closed_by']===null&&$row['created_by']===null&&$row['updated_by']===null,'FK cleanup rewrote final evidence/business data.');
        foreach(['trainings'=>'training','program_activity_records'=>'activity','monitoring_records'=>'monitoring',
            'monitoring_visit_reports'=>'visit','gis_locations'=>'gis']as$table=>$key){
            $row=$pdo->query('SELECT created_by,updated_by FROM public.'.$table.' WHERE id='.$q($c[$key]))->fetch(PDO::FETCH_ASSOC);
            LocalTestRun::assert($row===['created_by'=>null,'updated_by'=>null],'Child actor cleanup failed for '.$table);
        }
        return['parent_and_child_actor_cleanup'=>'allowed','final_evidence'=>'retained'];
    });
});

$tests->check('published GIS publisher FK cannot violate existing publication evidence',function()use($pdo,$cases,$q,$ids){
    return LocalTestRun::rollback($pdo,function()use($pdo,$cases,$q,$ids){
        $pdo->exec('UPDATE public.gis_locations SET is_published=true,published_at=now(),published_by='.$q($ids['cleanup_actor']).' WHERE id='.$q($cases['ongoing']['gis']));
        LocalTestRun::sqlState($pdo,'DELETE FROM auth.users WHERE id='.$q($ids['cleanup_actor']),['23514']);
        LocalTestRun::assert((int)$pdo->query('SELECT count(*) FROM public.user_profiles WHERE id='.$q($ids['cleanup_actor']))->fetchColumn()===1,'Failed publisher cleanup partially deleted the profile.');
        return['existing_publication_constraint'=>'23514; entire deletion rolled back'];
    });
});

foreach(['unknown','name_spoof','incoming_fk']as$variant){
    $tests->check('actual GIS deletion preflight rejects '.$variant,function()use($variant,$gis,$queries,$actor,$cases,$request){
        return LocalTestRun::appRollback(function()use($variant,$gis,$queries,$actor,$cases,$request){
            $c=$cases['ongoing'];$user=$actor();$before=DB::table('audit_logs')->count();
            if($variant==='incoming_fk'){
                DB::statement('CREATE TABLE assocmap_test.gis_reference(id uuid PRIMARY KEY,location_id uuid REFERENCES public.gis_locations(id) ON DELETE CASCADE)');
                DB::insert('INSERT INTO assocmap_test.gis_reference VALUES(gen_random_uuid(),?)',[$c['gis']]);
            }else{
                DB::unprepared('CREATE FUNCTION assocmap_test.noop_delete() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN OLD; END; $$');
                if($variant==='name_spoof'){
                    DB::statement('ALTER TRIGGER trg_protect_finalized_project_history ON public.gis_locations RENAME TO trg_preserved_history_guard');
                    DB::statement('CREATE TRIGGER trg_protect_finalized_project_history AFTER DELETE ON public.gis_locations FOR EACH ROW EXECUTE FUNCTION assocmap_test.noop_delete()');
                }else DB::statement('CREATE TRIGGER trg_unexpected_test_delete AFTER DELETE ON public.gis_locations FOR EACH ROW EXECUTE FUNCTION assocmap_test.noop_delete()');
            }
            $feature=$queries->detail($user,$c['gis']);
            LocalTestRun::denied(fn()=> $gis->destroy($c['gis'],$feature['properties']['version'],$user));
            LocalTestRun::assert(DB::table('gis_locations')->where('id',$c['gis'])->exists()&&DB::table('audit_logs')->count()===$before,'Schema rejection mutated GIS/audit.');
            if($variant==='incoming_fk')LocalTestRun::assert(DB::table('assocmap_test.gis_reference')->count()===1,'Dependent record was cascaded.');
            return['definition'=>$variant,'deletion'=>'denied before DML'];
        });
    });
}

$tests->check('actual GIS rejects invalid coordinates and invalid PostGIS boundaries',function()use($gis,$queries,$actor,$request,$cases){
    return LocalTestRun::appRollback(function()use($gis,$queries,$actor,$request,$cases){
        $c=$cases['completed_candidate'];$user=$actor();$f=$queries->detail($user,$c['gis']);
        $data=['project_id'=>$c['project'],'parent_association_id'=>$c['association'],'location_name'=>'Invalid test',
            'version'=>$f['properties']['version'],'location_type'=>'project_point','geometry'=>['type'=>'Point','coordinates'=>[181,10]]];
        LocalTestRun::denied(fn()=> $gis->update($c['gis'],$data,$user,$request($user)));
        $data['location_type']='project_boundary';$data['geometry']=['type'=>'Polygon','coordinates'=>[[[123,10],[124,11],[123,11],[124,10],[123,10]]]];
        LocalTestRun::denied(fn()=> $gis->update($c['gis'],$data,$user,$request($user)));
        return['bounds'=>'Laravel rejected','self_intersection'=>'native PostGIS validation rejected'];
    });
});

$tests->check('required audit failure rolls back actual completion/termination/GIS changes',function()use($gis,$closures,$queries,$actor,$request,$cases){
    return LocalTestRun::appRollback(function()use($gis,$closures,$queries,$actor,$request,$cases){
        DB::unprepared("CREATE FUNCTION assocmap_test.reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic required audit failure' USING ERRCODE='23514'; END; $$");
        DB::statement('CREATE TRIGGER scratch_audit_failure BEFORE INSERT ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION assocmap_test.reject_audit()');
        $c=$cases['ongoing'];$user=$actor();$before=Project::findOrFail($c['project'])->getAttributes();
        foreach(['completed','terminated']as$outcome){
            $p=Project::findOrFail($c['project']);$data=['closure_remarks'=>'Valid synthetic evidence.',
                ($outcome==='completed'?'completion_confirmation':'termination_confirmation')=>($outcome==='completed'?'COMPLETED':'TERMINATE'),
                'expected_updated_at'=>$p->updated_at->format('Y-m-d\TH:i:s.uP')];
            try{if($outcome==='completed')$closures->close($request($user),$p,$data);else$closures->terminate($request($user),$p,$data);throw new RuntimeException('Audit must fail.');}
            catch(Illuminate\Database\QueryException $error){LocalTestRun::assert($error->errorInfo[0]==='23514','Wrong required audit failure.');}
            LocalTestRun::assert(Project::findOrFail($c['project'])->getAttributes()===$before,'Failed finalization partially persisted.');
        }
        $f=$queries->detail($user,$c['gis']);
        try{$gis->lifecycle($c['gis'],'publish',$f['properties']['version'],$user,$request($user));throw new RuntimeException('Publication audit must fail.');}
        catch(Illuminate\Database\QueryException $error){LocalTestRun::assert($error->errorInfo[0]==='23514','Wrong GIS audit failure.');}
        LocalTestRun::assert(!$queries->detail($user,$c['gis'])['properties']['is_published'],'Failed GIS audit left publication committed.');
        LocalTestRun::denied(fn()=> $gis->destroy($c['gis'],$f['properties']['version'],$user));
        LocalTestRun::assert(DB::table('gis_locations')->where('id',$c['gis'])->exists(),'Failed deletion audit left GIS deleted.');
        return['completion'=>'rolled back','termination'=>'rolled back','publication'=>'rolled back','deletion'=>'rolled back'];
    });
});
$tests->check('genuine commodity FK SET NULL cleanup preserves finalized project',function()use($pdo,$q,$ids,$cases){
    return LocalTestRun::rollback($pdo,function()use($pdo,$q,$ids,$cases){
        $p=$cases['other_ongoing']['project'];
        $commodity=$pdo->query("INSERT INTO public.commodities(category_id,name,source) SELECT fisheries_category_id,'Synthetic FK cleanup commodity','custom' FROM public.projects WHERE id=".$q($p).' RETURNING id')->fetchColumn();
        $pdo->exec('UPDATE public.projects SET commodity_id='.$q($commodity).' WHERE id='.$q($p));
        $pdo->exec("UPDATE public.projects SET status='completed',closed_at=now(),closure_remarks='Retained commodity evidence.',closed_by=".$q($ids['admin']).' WHERE id='.$q($p));
        $before=$pdo->query('SELECT closed_at FROM public.projects WHERE id='.$q($p))->fetchColumn();
        $pdo->exec('DELETE FROM public.commodities WHERE id='.$q($commodity));
        $row=$pdo->query('SELECT status,closed_at,closure_remarks,commodity_id FROM public.projects WHERE id='.$q($p))->fetch(PDO::FETCH_ASSOC);
        LocalTestRun::assert($row['status']==='completed'&&$row['closed_at']===$before&&$row['closure_remarks']==='Retained commodity evidence.'&&$row['commodity_id']===null,'Commodity cleanup rewrote final evidence.');
        return['commodity_reference'=>'NULL','final_evidence'=>'retained'];
    });
});
$tests->finish();
