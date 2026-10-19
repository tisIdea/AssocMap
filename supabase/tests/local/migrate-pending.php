<?php

declare(strict_types=1);
require __DIR__.'/ScratchDatabase.php';
$scratch = new ScratchDatabase($argv[1] ?? '');
$pdo = $scratch->pdo;
$reportPath = $scratch->directory.'/migration-results.json';
if (is_file($reportPath)) throw new RuntimeException('Migration results already exist; refusing to replay pending migrations.');
$results = [];
$files = ['011_add_permanent_project_closure.sql','012_allow_zero_production_activities.sql',
    '013_add_permanent_project_finalization.sql','014_protect_finalized_project_history.sql'];
$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
foreach ($files as $index => $file) {
    $path = dirname(__DIR__, 2).'/migrations/'.$file;
    $before = $pdo->query('SELECT assocmap_test.core_snapshot()::text')->fetchColumn();
    try {
        $pdo->exec(file_get_contents($path));
        $after = $pdo->query('SELECT assocmap_test.core_snapshot()::text')->fetchColumn();
        $assert($before === $after, 'Existing public records changed during DDL (new null closure columns excluded).');
        $legacy = $pdo->query("SELECT status, closed_at, closure_remarks, closed_by FROM public.projects
            WHERE id = (SELECT (ids->>'project')::uuid FROM assocmap_test.cases WHERE label='legacy_completed')")->fetch(PDO::FETCH_ASSOC);
        $assert($legacy['status'] === 'completed' && $legacy['closed_at'] === null && $legacy['closed_by'] === null
            && $legacy['closure_remarks'] === null, 'Legacy completed evidence changed.');
        if ($index === 0) {
            $columns = $pdo->query("SELECT attname, atttypid::regtype::text AS type FROM pg_attribute
                WHERE attrelid='public.projects'::regclass AND attname IN ('closed_at','closed_by','closure_remarks')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $assert($columns === array_intersect_key($columns, array_flip(['closed_at','closed_by','closure_remarks']))
                && count($columns) === 3 && $columns['closed_at'] === 'timestamp with time zone'
                && $columns['closed_by'] === 'uuid' && $columns['closure_remarks'] === 'text', 'Closure column types mismatch.');
            $assert((int) $pdo->query("SELECT count(*) FROM pg_constraint WHERE conrelid='public.projects'::regclass
                AND contype='f' AND confrelid='public.user_profiles'::regclass AND confdeltype='n'
                AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (closed_by)%'")->fetchColumn() === 1, 'Closure actor FK mismatch.');
            $assert((int) $pdo->query("SELECT count(*) FROM pg_trigger WHERE tgrelid='public.projects'::regclass
                AND tgname='trg_protect_project_completion' AND tgtype=29 AND tgenabled='O'
                AND tgfoid='public.protect_project_completion()'::regprocedure")->fetchColumn() === 1, 'Completion trigger mismatch.');
            $pdo->beginTransaction();
            try {
                $pdo->exec("UPDATE public.projects SET status='completed', closed_at=now(), closed_by=(SELECT id FROM assocmap_test.identities WHERE label='admin'),
                    closure_remarks='Stage 011 verified completion evidence.' WHERE id=(SELECT (ids->>'project')::uuid FROM assocmap_test.cases WHERE label='completed_candidate')");
                $assert($pdo->query("SELECT status FROM public.projects WHERE id=(SELECT (ids->>'project')::uuid FROM assocmap_test.cases WHERE label='completed_candidate')")->fetchColumn() === 'completed', 'Stage 011 completion failed.');
            } finally { $pdo->rollBack(); }
        }
        if ($index === 1) {
            $pdo->beginTransaction();
            try {
                $assert($pdo->exec("UPDATE public.monitoring_production_monthly SET activity_count=0, total_quantity=0
                    WHERE id=(SELECT (ids->>'monthly')::uuid FROM assocmap_test.cases WHERE label='ongoing')") === 1, 'Zero activity update failed.');
            } finally { $pdo->rollBack(); }
            $constraint = $pdo->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint
                WHERE conrelid='public.monitoring_production_monthly'::regclass AND conname='chk_monitoring_production_activity_count'")->fetchColumn();
            $assert(str_contains($constraint, 'activity_count >= 0'), 'Zero activity constraint mismatch.');
        }
        if ($index === 2) {
            $definition = $pdo->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint
                WHERE conrelid='public.projects'::regclass AND conname='projects_status_check'")->fetchColumn();
            foreach (['planned','ongoing','completed','suspended','cancelled','terminated'] as $status) $assert(str_contains($definition, $status), 'Missing project status '.$status);
            $pdo->beginTransaction();
            try {
                $pdo->exec("UPDATE public.projects SET status='terminated', closed_at=now(), closed_by=(SELECT id FROM assocmap_test.identities WHERE label='admin'),
                    closure_remarks='Stage 013 verified termination evidence.' WHERE id=(SELECT (ids->>'project')::uuid FROM assocmap_test.cases WHERE label='terminated_candidate')");
            } finally { $pdo->rollBack(); }
        }
        if ($index === 3) {
            $assert((int) $pdo->query("SELECT count(*) FROM pg_trigger WHERE tgname='trg_protect_finalized_project_history'
                AND NOT tgisinternal AND tgtype=29 AND tgenabled='O'
                AND tgfoid='public.protect_finalized_project_history()'::regprocedure")->fetchColumn() === 12, 'History trigger registration mismatch.');
            foreach (['public.project_history_project_ids(text,jsonb)','public.protect_finalized_project_history()','public.protect_project_completion()'] as $signature) {
                $row = $pdo->query("SELECT proconfig::text, has_function_privilege('anon', oid, 'EXECUTE') AS anon,
                    has_function_privilege('authenticated', oid, 'EXECUTE') AS account FROM pg_proc WHERE oid=".$pdo->quote($signature).'::regprocedure')->fetch(PDO::FETCH_ASSOC);
                $assert(str_contains($row['proconfig'], 'pg_temp') && str_contains($row['proconfig'], 'row_security=off')
                    && $row['anon'] === false && $row['account'] === false, 'Private function security mismatch: '.$signature);
            }
        }
        $results[] = ['file'=>$file,'status'=>'PASS','sha256'=>hash_file('sha256',$path),
            'existing_records_preserved'=>true,'legacy_completed_preserved'=>true,'snapshot_sha256'=>hash('sha256',$after)];
        echo 'PASS '.$file.'; catalog assertions and existing-record preservation'.PHP_EOL;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $results[] = ['file'=>$file,'status'=>'FAIL','sqlstate'=>$error instanceof PDOException ? $error->getCode() : null,'error'=>$error->getMessage()];
        file_put_contents($reportPath,json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        fwrite(STDERR, 'FAIL '.$file.': '.$error->getMessage().PHP_EOL);
        exit(1);
    }
}
file_put_contents($reportPath,json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo $reportPath.PHP_EOL;
