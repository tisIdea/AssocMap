<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\Support\TestEnvironmentGuard;
use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    private function safeConfiguration(): array
    {
        return [
            'app' => ['env' => 'testing'],
            'database' => ['default' => 'sqlite', 'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => ''],
            ]],
            'cache' => ['default' => 'array'], 'session' => ['driver' => 'array'],
            'queue' => ['default' => 'sync'], 'mail' => ['default' => 'array'],
        ];
    }

    private function configurationApp(Repository $config, bool $cached = false): Application
    {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('bound')->with('config_loaded_from_cache')->andReturn(true);
        $app->shouldReceive('make')->with('config_loaded_from_cache')->andReturn($cached);
        $app->shouldReceive('make')->with('config')->andReturn($config);

        return $app;
    }

    public function test_safe_configuration_is_accepted_and_effective_services_are_guarded(): void
    {
        TestEnvironmentGuard::assertConfiguration($this->configurationApp(new Repository($this->safeConfiguration())));
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertTrue(Http::preventingStrayRequests());
        $this->assertSame('testing', config('app.env'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_unsafe_loaded_configuration_is_rejected(): void
    {
        foreach (['app.env' => 'production', 'database.default' => 'pgsql',
            'database.connections.sqlite.database' => 'synthetic.sqlite',
            'database.connections.sqlite.url' => 'sqlite:///:memory:',
            'database.connections.sqlite.host' => 'database.example.test',
            'cache.default' => 'database', 'session.driver' => 'database',
            'queue.default' => 'database', 'mail.default' => 'smtp'] as $key => $value) {
            $config = new Repository($this->safeConfiguration());
            $config->set($key, $value);
            try {
                TestEnvironmentGuard::assertConfiguration($this->configurationApp($config));
                $this->fail('Unsafe test configuration was accepted: '.$key);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Test isolation', $exception->getMessage());
            }
        }
    }

    public function test_cached_configuration_is_rejected_before_loading(): void
    {
        $app = Mockery::mock(Application::class);
        // An existing source file stands in for a cache file; its contents are never loaded.
        $app->shouldReceive('getCachedConfigPath')->andReturn(__FILE__);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refuses cached application configuration');
        TestEnvironmentGuard::assertUncached($app);
    }

    public function test_configuration_marked_as_cached_is_also_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refuses cached application configuration');
        TestEnvironmentGuard::assertConfiguration($this->configurationApp(new Repository($this->safeConfiguration()), true));
    }

    public function test_unsafe_named_connections_are_rejected_before_connectors_run(): void
    {
        foreach (['sqlite', 'pgsql', 'mysql', 'mariadb'] as $driver) {
            $connector = Mockery::mock(ConnectorInterface::class);
            $connector->shouldNotReceive('connect');
            $this->app->instance('db.connector.'.$driver, $connector);
        }
        $safe = ['driver' => 'sqlite', 'database' => ':memory:'];
        foreach ([null, 'sqlite:///:memory:', ['driver' => 'pgsql', 'database' => ':memory:'],
            array_replace($safe, ['database' => 'synthetic.sqlite']),
            array_replace($safe, ['host' => 'database.example.test']),
            array_replace($safe, ['url' => 'sqlite:///:memory:']),
            array_replace($safe, ['url' => 'postgresql://database.example.test/synthetic']),
            array_replace($safe, ['read' => ['database' => 'synthetic.sqlite']]),
            array_replace($safe, ['write' => ['database' => 'synthetic.sqlite']])] as $index => $config) {
            $name = 'unsafe_isolation_'.$index;
            config(['database.connections.'.$name => $config]);
            try {
                DB::connection($name)->getPdo();
                $this->fail('An unsafe named connection was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Test isolation', $exception->getMessage());
                $this->assertArrayNotHasKey($name, DB::getConnections());
            }
        }
    }

    public function test_direct_dynamic_connection_cannot_bypass_the_guard(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permits only SQLite :memory:');
        DB::connectUsing('unsafe_dynamic', ['driver' => 'sqlite', 'database' => ':memory:',
            'url' => 'sqlite:///:memory:']);
    }

    public function test_existing_named_in_memory_fixture_pattern_remains_supported(): void
    {
        config(['database.connections.isolation_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $connection = DB::connection('isolation_fixture');
        $this->assertSame('sqlite', $connection->getConfig('driver'));
        $this->assertSame(':memory:', $connection->getConfig('database'));
        DB::purge('isolation_fixture');
    }

    public function test_unfaked_http_requests_are_blocked(): void
    {
        $this->expectException(StrayRequestException::class);
        Http::get('https://unfaked.example.test/isolation-probe');
    }

    public function test_unsafe_configuration_stops_bootstrap_before_provider_registration(): void
    {
        $probe = (object) ['providers_registered' => false];
        $originalContainer = Container::getInstance();
        try {
            $app = new Application(dirname(__DIR__, 2));
            $app->instance(LoadConfiguration::class, new class {
                public function bootstrap(Application $app): void
                {
                    $app->instance('config', new Repository(['app' => ['env' => 'production']]));
                }
            });
            $app->instance(RegisterProviders::class, new class($probe) {
                public function __construct(private object $probe) {}

                public function bootstrap(Application $app): void
                {
                    $this->probe->providers_registered = true;
                }
            });
            TestEnvironmentGuard::install($app);
            try {
                $app->bootstrapWith([LoadConfiguration::class, RegisterProviders::class]);
                $this->fail('Unsafe bootstrap reached provider registration.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Test isolation', $exception->getMessage());
                $this->assertFalse($probe->providers_registered);
            }
        } finally {
            Container::setInstance($originalContainer);
        }
    }

    public function test_http_guard_is_present_during_provider_registration(): void
    {
        $probe = (object) ['http_guarded' => false];
        $originalContainer = Container::getInstance();
        try {
            $app = new Application(dirname(__DIR__, 2));
            $app->instance(RegisterProviders::class, new class($probe) {
                public function __construct(private object $probe) {}

                public function bootstrap(Application $app): void
                {
                    $this->probe->http_guarded = $app->make(Factory::class)->preventingStrayRequests();
                }
            });
            TestEnvironmentGuard::install($app);
            $app->bootstrapWith([RegisterProviders::class]);
            $this->assertTrue($probe->http_guarded);
        } finally {
            Container::setInstance($originalContainer);
        }
    }
}
