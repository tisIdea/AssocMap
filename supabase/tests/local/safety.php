<?php

declare(strict_types=1);

require __DIR__.'/ScratchDatabase.php';
require __DIR__.'/LocalTestRun.php';

$scratch = new ScratchDatabase($argv[1] ?? '');
$psql = realpath($argv[2] ?? '');
if ($psql === false || ! is_file($psql) || strtolower(basename($psql)) !== 'psql.exe') {
    throw new RuntimeException('Pass the explicit trusted PostgreSQL psql.exe path.');
}
$pdo = $scratch->pdo;
if ((int) $pdo->query("SELECT count(*) FROM pg_namespace WHERE nspname='assocmap_test'")->fetchColumn() !== 0
    || (int) $pdo->query('SELECT count(*) FROM auth.users')->fetchColumn() !== 0
    || (int) $pdo->query('SELECT count(*) FROM public.projects')->fetchColumn() !== 0
    || (int) $pdo->query('SELECT count(*) FROM public.associations')->fetchColumn() !== 0
    || (int) $pdo->query('SELECT count(*) FROM public.members')->fetchColumn() !== 0) {
    throw new RuntimeException('Safety regressions require a fresh, empty operational scratch database before fixture loading.');
}
$tests = new LocalTestRun($scratch->directory.'/safety-results.json');
$fixtures = str_replace('\\', '/', realpath(__DIR__.'/fixtures.sql'));
$snapshot = static function () use ($pdo): array {
    $result = [];
    foreach ($pdo->query("SELECT schemaname, tablename FROM pg_tables
        WHERE schemaname IN ('public','auth','assocmap_test') ORDER BY schemaname, tablename") as $table) {
        $name = $table['schemaname'].'.'.$table['tablename'];
        $identifier = '"'.str_replace('"', '""', $table['schemaname']).'"."'.str_replace('"', '""', $table['tablename']).'"';
        $rows = $pdo->query("SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text), '[]'::jsonb)::text FROM $identifier t")->fetchColumn();
        $result[$name] = ['rows'=>count(json_decode($rows, true, 512, JSON_THROW_ON_ERROR)), 'sha256'=>hash('sha256', $rows)];
    }
    return $result;
};
$runFixtures = static function (string $label, string $expectedDatabase, string $syntheticOnly, bool $stopOnError) use ($scratch, $psql, $pdo, $fixtures): array {
    $wrapper = $scratch->directory.'/safety-'.$label.'.sql';
    $stdout = $scratch->directory.'/safety-'.$label.'.stdout.log';
    $stderr = $scratch->directory.'/safety-'.$label.'.stderr.log';
    file_put_contents($wrapper, "SET statement_timeout='15s'; SET lock_timeout='5s';\n"
        ."SELECT set_config('assocmap.expected_database', ".$pdo->quote($expectedDatabase).", false),\n"
        ."set_config('assocmap.expected_port', '".$scratch->run['Port']."', false),\n"
        ."set_config('assocmap.synthetic_only', ".$pdo->quote($syntheticOnly).", false);\n"
        ."\\i '$fixtures'\nSELECT 'ASSOCMAP_SAFETY_CLIENT_CONTINUED';\n");
    $command = [$psql, '-X', '-w', '-h', '127.0.0.1', '-p', (string) $scratch->run['Port'],
        '-U', $scratch->run['User'], '-d', $scratch->run['Database'],
        '-v', 'ON_ERROR_STOP='.($stopOnError ? '1' : '0'), '-v', 'ON_ERROR_ROLLBACK=off', '-f', $wrapper];
    $environment = ['SystemRoot'=>getenv('SystemRoot') ?: 'C:\\Windows', 'TEMP'=>$scratch->directory,
        'TMP'=>$scratch->directory, 'PGPASSFILE'=>$scratch->directory.'/pgpass.conf',
        'PGCONNECT_TIMEOUT'=>'5', 'PGSSLMODE'=>'disable', 'PGOPTIONS'=>'', 'PGAPPNAME'=>'assocmap-safety'];
    $process = proc_open($command, [0=>['pipe','r'], 1=>['file',$stdout,'w'], 2=>['file',$stderr,'w']],
        $pipes, $scratch->directory, $environment, ['bypass_shell'=>true]);
    if (! is_resource($process)) throw new RuntimeException('Cannot start the explicit local psql safety client.');
    fclose($pipes[0]);
    $exit = proc_close($process);
    return ['exit'=>$exit, 'stdout'=>file_get_contents($stdout), 'stderr'=>file_get_contents($stderr)];
};

foreach (['wrong-database'=>['not_the_expected_scratch_database','yes'], 'not-synthetic'=>[$scratch->run['Database'],'no']] as $label=>$settings) {
    $tests->check('fixture safety rejection survives continuing client '.$label, function () use ($label, $settings, $runFixtures, $snapshot, $pdo) {
        $before = $snapshot();
        $result = $runFixtures($label, $settings[0], $settings[1], false);
        LocalTestRun::assert($result['exit'] === 0 && str_contains($result['stdout'], 'ASSOCMAP_SAFETY_CLIENT_CONTINUED'), 'The negative client did not continue past the rejected fixture file.');
        LocalTestRun::assert(str_contains($result['stderr'], 'Verified disposable loopback database required.'), 'The actual fixture safety guard did not reject the invalid settings.');
        LocalTestRun::assert($before === $snapshot() && (int) $pdo->query("SELECT count(*) FROM pg_namespace WHERE nspname='assocmap_test'")->fetchColumn() === 0, 'Rejected fixture execution committed schema or record changes.');
        return ['client_continued'=>true, 'records_unchanged'=>true, 'fixture_schema_absent'=>true];
    });
}

foreach (['numeric-string'=>'5432', 'suffix-string'=>'5432junk', 'float'=>5432.0, 'true'=>true,
    'false'=>false, 'null'=>null, 'below-range'=>1023, 'above-range'=>65536] as $label=>$port) {
    $tests->check('manifest rejects invalid Port '.$label, function () use ($scratch, $label, $port) {
        $suffix = date('Ymd').'_'.bin2hex(random_bytes(4));
        $directory = dirname($scratch->directory).'/assocmap-pg-'.$suffix;
        if (! mkdir($directory) || ! mkdir($directory.'/data')) throw new RuntimeException('Cannot create isolated manifest-only probe directories.');
        $manifest = ['Host'=>'127.0.0.1', 'Port'=>$port, 'User'=>'assocmap_test_owner',
            'Database'=>'assocmap_gis_test_'.$suffix, 'Data'=>$directory.'/data'];
        file_put_contents($directory.'/run.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        try { new ScratchDatabase($directory.'/run.json'); }
        catch (RuntimeException $error) {
            LocalTestRun::assert($error->getMessage() === 'Scratch port must be an integer between 1024 and 65535.', 'Invalid Port was not rejected by the manifest preflight.');
            return ['invalid_type_or_range_rejected'=>true, 'credential_files_created'=>false];
        }
        throw new RuntimeException('Invalid manifest Port was accepted.');
    });
}
foreach ([1024,65535] as $port) {
    $tests->check('manifest accepts boundary Port '.$port.' before rejecting missing data', function () use ($scratch, $port) {
        $suffix = date('Ymd').'_'.bin2hex(random_bytes(4));
        $directory = dirname($scratch->directory).'/assocmap-pg-'.$suffix;
        if (! mkdir($directory)) throw new RuntimeException('Cannot create isolated manifest-only probe directory.');
        $manifest = ['Host'=>'127.0.0.1', 'Port'=>$port, 'User'=>'assocmap_test_owner',
            'Database'=>'assocmap_gis_test_'.$suffix, 'Data'=>$directory.'/data'];
        file_put_contents($directory.'/run.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        try { new ScratchDatabase($directory.'/run.json'); }
        catch (RuntimeException $error) {
            LocalTestRun::assert($error->getMessage() === 'Scratch data directory must resolve inside the verified run directory.', 'Valid boundary Port did not reach the data-directory guard.');
            return ['boundary_port_accepted'=>true, 'missing_data_rejected'=>true, 'credential_files_created'=>false];
        }
        throw new RuntimeException('Missing data directory was accepted.');
    });
}
foreach (['external','missing'] as $label) {
    $tests->check('manifest rejects '.$label.' data directory', function () use ($scratch, $label) {
        $suffix = date('Ymd').'_'.bin2hex(random_bytes(4));
        $directory = dirname($scratch->directory).'/assocmap-pg-'.$suffix;
        if (! mkdir($directory)) throw new RuntimeException('Cannot create isolated manifest-only probe directory.');
        if ($label === 'external' && ! mkdir($directory.'/data')) throw new RuntimeException('Cannot create the expected empty local data directory.');
        $manifest = ['Host'=>'127.0.0.1', 'Port'=>$scratch->run['Port'], 'User'=>'assocmap_test_owner',
            'Database'=>'assocmap_gis_test_'.$suffix, 'Data'=>$label === 'external' ? $scratch->run['Data'] : $directory.'/data'];
        file_put_contents($directory.'/run.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        try { new ScratchDatabase($directory.'/run.json'); }
        catch (RuntimeException $error) {
            LocalTestRun::assert($error->getMessage() === 'Scratch data directory must resolve inside the verified run directory.', 'Invalid data directory was not rejected before connection.');
            return ['invalid_data_rejected'=>true, 'credential_files_created'=>false];
        }
        throw new RuntimeException('Invalid data directory was accepted.');
    });
}

$tests->check('valid fixture load commits the complete synthetic graph', function () use ($runFixtures, $scratch, $pdo) {
    $result = $runFixtures('valid', $scratch->run['Database'], 'yes', true);
    LocalTestRun::assert($result['exit'] === 0 && str_contains($result['stdout'], 'ASSOCMAP_SAFETY_CLIENT_CONTINUED'), 'Valid actual fixture loading failed; inspect scratch-only psql logs.');
    $counts = $pdo->query("SELECT (SELECT count(*) FROM assocmap_test.identities) AS identities,
        (SELECT count(*) FROM assocmap_test.cases) AS cases, (SELECT count(*) FROM public.projects) AS projects,
        (SELECT count(*) FROM public.members) AS members, (SELECT count(*) FROM public.association_accounts) AS accounts")->fetch(PDO::FETCH_ASSOC);
    LocalTestRun::assert(array_map('intval', $counts) === ['identities'=>9,'cases'=>11,'projects'=>11,'members'=>22,'accounts'=>1], 'Synthetic fixture counts do not match the complete source graph.');
    $linked = (int) $pdo->query("SELECT count(*) FROM assocmap_test.cases c
        JOIN public.projects p ON p.id=(c.ids->>'project')::uuid AND p.association_id=(c.ids->>'association')::uuid
        JOIN public.monitoring_records m ON m.id=(c.ids->>'monitoring')::uuid AND m.project_id=p.id
        JOIN public.monitoring_production production ON production.id=(c.ids->>'production')::uuid AND production.monitoring_record_id=m.id
        JOIN public.members member ON member.id=(c.ids->>'member')::uuid AND member.association_id=p.association_id")->fetchColumn();
    LocalTestRun::assert($linked === 11, 'Synthetic fixture relationships are incomplete.');
    return ['counts'=>$counts, 'complete_linked_cases'=>$linked];
});
$tests->check('fixture replay is rejected without changing retained records', function () use ($runFixtures, $scratch, $snapshot) {
    $before = $snapshot();
    $result = $runFixtures('replay', $scratch->run['Database'], 'yes', true);
    LocalTestRun::assert($result['exit'] !== 0 && str_contains($result['stderr'], 'Fixture setup requires empty synthetic fixture/Auth tables'), 'Fixture replay was not rejected by the existing-record guard.');
    LocalTestRun::assert($before === $snapshot(), 'Rejected fixture replay changed existing records.');
    return ['replay_rejected'=>true, 'records_unchanged'=>true];
});
$tests->finish();
