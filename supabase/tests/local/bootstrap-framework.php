<?php

declare(strict_types=1);

require __DIR__.'/ScratchDatabase.php';
require dirname(__DIR__, 3).'/web/vendor/autoload.php';

$scratch = new ScratchDatabase($argv[1] ?? '');
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $scratch->run['Port'],
    'database' => $scratch->run['Database'], 'username' => $scratch->run['User'], 'password' => $scratch->password(),
    'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => 'disable']);
$container = $capsule->getContainer();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$capsule->setAsGlobal();
$identity = $capsule->getConnection()->selectOne("SELECT current_database() AS db, host(inet_server_addr()) AS host, inet_server_port() AS port, current_setting('data_directory') AS data");
if ($identity->db !== $scratch->run['Database'] || $identity->host !== '127.0.0.1'
    || (int) $identity->port !== (int) $scratch->run['Port'] || realpath($identity->data) !== realpath($scratch->run['Data'])) {
    throw new RuntimeException('Framework connection isolation mismatch.');
}
$migrationDirectory = dirname(__DIR__, 3).'/web/database/migrations/';
foreach (['0001_01_01_000000_create_users_table.php','0001_01_01_000001_create_cache_table.php',
    '0001_01_01_000002_create_jobs_table.php','2026_09_28_091014_create_personal_access_tokens_table.php',
    '2026_10_05_102947_update_program_activity_status_constraint.php'] as $file) {
    (require $migrationDirectory.$file)->up();
    echo 'PASS actual isolated framework migration: '.$file.PHP_EOL;
}
$repository = new Illuminate\Database\Migrations\DatabaseMigrationRepository($capsule->getDatabaseManager(), 'migrations');
$repository->createRepository();
echo 'PASS framework migration repository prerequisite'.PHP_EOL;
