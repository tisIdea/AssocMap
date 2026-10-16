<?php

declare(strict_types=1);
require __DIR__.'/ScratchDatabase.php';
$scratch = new ScratchDatabase($argv[1] ?? '');
$path = dirname(__DIR__,3).'/docs/supabase-finalization-deployment-guide.md';
preg_match_all('/```sql\R(.*?)```/s',file_get_contents($path),$blocks);
$count = 0;
$scratch->pdo->beginTransaction();
try {
    $scratch->pdo->exec('SET TRANSACTION READ ONLY');
    foreach ($blocks[1] as $block) foreach (explode(';',$block) as $sql) {
        $sql = trim($sql);
        if ($sql==='') continue;
        if (! preg_match('/\A(?:SELECT|WITH)\b/',$sql)) throw new RuntimeException('Handoff verification accepts read queries only.');
        $scratch->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $count++;
    }
    if ($count===0) throw new RuntimeException('No handoff queries were found.');
} finally { $scratch->pdo->rollBack(); }
file_put_contents($scratch->directory.'/handoff-results.json',json_encode([
    'status'=>'PASS','queries'=>$count,'read_only_transaction'=>true,'local_only'=>true,'sha256'=>hash_file('sha256',$path),
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'PASS '.$count.' manual handoff queries on verified local database in a read-only transaction.'.PHP_EOL;
