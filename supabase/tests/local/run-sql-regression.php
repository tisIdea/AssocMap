<?php

declare(strict_types=1);
require __DIR__.'/ScratchDatabase.php';
$scratch = new ScratchDatabase($argv[1] ?? '');
$ids = [];
foreach ($scratch->pdo->query('SELECT label, ids::text FROM assocmap_test.cases') as $row) $ids[$row['label']] = json_decode($row['ids'], true);
$admin = $scratch->pdo->query("SELECT id FROM assocmap_test.identities WHERE label='admin'")->fetchColumn();
$fixtures = json_encode(['completed'=>$ids['completed_candidate']['project'],'terminated'=>$ids['terminated_candidate']['project'],
    'ongoing'=>$ids['ongoing']['project'],'admin'=>$admin], JSON_THROW_ON_ERROR);
$scratch->pdo->exec("SELECT set_config('assocmap.gis_fixture_ids', ".$scratch->pdo->quote($fixtures).", false)");
$before = $scratch->pdo->query('SELECT assocmap_test.core_snapshot()::text')->fetchColumn();
try {
    $scratch->pdo->exec(file_get_contents(dirname(__DIR__).'/finalization_gis_regression.sql'));
    $after = $scratch->pdo->query('SELECT assocmap_test.core_snapshot()::text')->fetchColumn();
    if ($before !== $after) throw new RuntimeException('The rollback regression script changed existing records.');
    file_put_contents($scratch->directory.'/sql-regression-results.json', json_encode(['status'=>'PASS','script'=>'finalization_gis_regression.sql',
        'sha256'=>hash_file('sha256',dirname(__DIR__).'/finalization_gis_regression.sql'),'history_preserved'=>true], JSON_PRETTY_PRINT));
    echo 'PASS actual SQL regression, both isolation levels, and full public-record preservation'.PHP_EOL;
} catch (Throwable $error) {
    if ($scratch->pdo->inTransaction()) $scratch->pdo->rollBack();
    file_put_contents($scratch->directory.'/sql-regression-results.json', json_encode(['status'=>'FAIL','error'=>$error->getMessage()], JSON_PRETTY_PRINT));
    throw $error;
}
