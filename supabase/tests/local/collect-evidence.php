<?php

declare(strict_types=1);
require __DIR__.'/ScratchDatabase.php';
$scratch = new ScratchDatabase($argv[1] ?? '');
$pdo = $scratch->pdo;
$root = dirname(__DIR__, 3);
$environment = $pdo->query("SELECT version() AS postgres, current_database() AS database, current_user AS actor,
    host(inet_server_addr()) AS host, inet_server_port() AS port, current_setting('data_directory') AS data_directory,
    current_setting('server_encoding') AS encoding,
    current_setting('transaction_isolation') AS isolation, current_setting('listen_addresses') AS listen_addresses,
    pg_postmaster_start_time()::text AS server_started_at, clock_timestamp()::text AS collected_at")->fetch(PDO::FETCH_ASSOC);
$environment['postgis'] = $pdo->query('SELECT extensions.postgis_full_version()')->fetchColumn();
$environment['locale'] = $pdo->query('SELECT datlocprovider, datcollate, datctype, datlocale, datcollversion FROM pg_database WHERE datname=current_database()')->fetch(PDO::FETCH_ASSOC);
$environment['extensions'] = $pdo->query('SELECT e.extname,e.extversion,n.nspname AS schema FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace ORDER BY e.extname')->fetchAll(PDO::FETCH_ASSOC);
$environment['roles'] = $pdo->query("SELECT rolname,rolsuper,rolbypassrls,rolcanlogin FROM pg_roles WHERE rolname IN ('assocmap_test_owner','anon','authenticated') ORDER BY rolname")->fetchAll(PDO::FETCH_ASSOC);
$environment['public_record_counts'] = [];
foreach ($pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $environment['public_record_counts'][$table] = (int)$pdo->query('SELECT count(*) FROM public."'.str_replace('"','""',$table).'"')->fetchColumn();
}
$environment['trigger_catalog'] = $pdo->query("SELECT c.relname,t.tgname,t.tgtype,t.tgenabled,p.proname
    FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_proc p ON p.oid=t.tgfoid
    WHERE t.tgname IN ('trg_protect_finalized_project_history','trg_protect_project_completion') ORDER BY c.relname")->fetchAll(PDO::FETCH_ASSOC);
$environment['function_security'] = $pdo->query("SELECT p.proname,pg_get_userbyid(p.proowner) AS owner,p.prosecdef,p.proconfig::text,
    has_function_privilege('anon',p.oid,'EXECUTE') AS anon_execute,
    has_function_privilege('authenticated',p.oid,'EXECUTE') AS authenticated_execute
    FROM pg_proc p WHERE p.oid IN ('public.protect_project_completion()'::regprocedure,
    'public.protect_finalized_project_history()'::regprocedure,'public.project_history_project_ids(text,jsonb)'::regprocedure)
    ORDER BY p.proname")->fetchAll(PDO::FETCH_ASSOC);
$environment['source_sha256'] = [];
foreach (glob($root.'/supabase/migrations/01[0-4]_*.sql') as $path) $environment['source_sha256'][basename($path)] = hash_file('sha256',$path);
foreach (json_decode(file_get_contents($scratch->directory.'/migration-results.json'),true,512,JSON_THROW_ON_ERROR) as $migration) {
    if ($migration['sha256'] !== $environment['source_sha256'][$migration['file']]) throw new RuntimeException('Tested migration source has changed: '.$migration['file']);
}
$environment['php'] = PHP_VERSION;
$environment['supabase_auth'] = 'Synthetic auth.users UUID FK stub only; no cloud Auth behavior tested.';
$environment['application_environment_loaded'] = false;
$environment['existing_service_touched'] = false;
file_put_contents($scratch->directory.'/environment-results.json',json_encode($environment,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'Collected verified local identity, versions, catalog, record counts and unchanged migration hashes.'.PHP_EOL;
