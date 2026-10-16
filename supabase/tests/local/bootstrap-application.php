<?php

declare(strict_types=1);

// Standalone PostgreSQL integration harness. Never boots the normal .env/config/providers.
require_once __DIR__.'/ScratchDatabase.php';
require_once dirname(__DIR__, 3).'/web/vendor/autoload.php';

function scratchApplication(ScratchDatabase $scratch): Illuminate\Foundation\Application
{
    date_default_timezone_set('UTC');
    $app = new Illuminate\Foundation\Application(dirname(__DIR__, 3).'/web');
    $app->useEnvironmentPath($scratch->directory.'/no-project-environment');
    $app->instance('config', new Illuminate\Config\Repository([
        'app'=>['env'=>'testing','timezone'=>'UTC'],
        'database'=>['default'=>'default','connections'=>[]],
    ]));
    $capsule = new Illuminate\Database\Capsule\Manager($app);
    $capsule->addConnection(['driver'=>'pgsql','host'=>'127.0.0.1','port'=>$scratch->run['Port'],
        'database'=>$scratch->run['Database'],'username'=>$scratch->run['User'],'password'=>$scratch->password(),
        'charset'=>'utf8','prefix'=>'','schema'=>'public','sslmode'=>'disable']);
    $app->instance('db', $capsule->getDatabaseManager());
    $app->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
    $events = new Illuminate\Events\Dispatcher($app);
    $app->instance('events', $events);
    $capsule->setEventDispatcher($events);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en');
    $app->instance('translator', $translator);
    $app->instance('validator', new Illuminate\Validation\Factory($translator, $app));
    $app->instance('log', new Psr\Log\NullLogger);
    $app->instance(Illuminate\Http\Client\Factory::class, (new Illuminate\Http\Client\Factory)->preventStrayRequests());
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $identity = $capsule->getConnection()->selectOne("SELECT current_database() AS db, host(inet_server_addr()) AS host,
        inet_server_port() AS port, current_setting('data_directory') AS data");
    if ($identity->db !== $scratch->run['Database'] || $identity->host !== '127.0.0.1'
        || (int) $identity->port !== (int) $scratch->run['Port'] || realpath($identity->data) !== realpath($scratch->run['Data'])) {
        throw new RuntimeException('Application service integration connection is not the verified scratch target.');
    }
    return $app;
}
