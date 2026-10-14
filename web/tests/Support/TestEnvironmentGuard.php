<?php

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Env;
use RuntimeException;

final class TestEnvironmentGuard
{
    public static function install(Application $app): void
    {
        $app->extend('db', static function (DatabaseManager $manager, Application $app) {
            if ($manager->getConnections() !== []) {
                throw new RuntimeException('Test isolation must be installed before database connections are created.');
            }

            return new IsolatedDatabaseManager($app, $app['db.factory']);
        });
        $app->extend(Factory::class, static fn (Factory $http) => $http->preventStrayRequests());

        $app->beforeBootstrapping(LoadEnvironmentVariables::class, static function (Application $app): void {
            self::assertUncached($app);
            if (Env::get('APP_ENV') !== 'testing') {
                throw new RuntimeException('Test isolation requires APP_ENV=testing before bootstrap.');
            }
        });
        // Recheck after environment loading, which can change the configured cache path.
        $app->beforeBootstrapping(LoadConfiguration::class, self::assertUncached(...));
        $app->afterBootstrapping(LoadConfiguration::class, self::assertConfiguration(...));
        $app->afterBootstrapping(BootProviders::class, self::assertConfiguration(...));
    }

    public static function assertUncached(Application $app): void
    {
        if (file_exists($app->getCachedConfigPath())) {
            throw new RuntimeException('Test isolation refuses cached application configuration. Use an uncached isolated test workspace.');
        }
    }

    public static function assertConfiguration(Application $app): void
    {
        if ($app->bound('config_loaded_from_cache') && $app->make('config_loaded_from_cache') !== false) {
            throw new RuntimeException('Test isolation refuses cached application configuration.');
        }

        $config = $app->make('config');
        foreach (['app.env' => 'testing', 'database.default' => 'sqlite', 'cache.default' => 'array',
            'session.driver' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array'] as $key => $expected) {
            if ($config->get($key) !== $expected) {
                throw new RuntimeException('Test isolation requires '.$key.'='.$expected.'.');
            }
        }
        self::assertConnection($config->get('database.connections.sqlite'));
    }

    public static function assertConnection(mixed $config): void
    {
        if (! is_array($config) || ($config['driver'] ?? null) !== 'sqlite'
            || ($config['database'] ?? null) !== ':memory:'
            || ! in_array($config['url'] ?? null, [null, ''], true)
            || ! in_array($config['host'] ?? null, [null, ''], true)
            || array_key_exists('read', $config) || array_key_exists('write', $config)) {
            throw new RuntimeException('Test isolation permits only SQLite :memory: connections without URLs, hosts or read/write overrides.');
        }
    }
}
