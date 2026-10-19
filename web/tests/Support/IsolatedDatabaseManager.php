<?php

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;

final class IsolatedDatabaseManager extends DatabaseManager
{
    protected function configuration($name)
    {
        $raw = $this->dynamicConnectionConfigurations[$name]
            ?? Arr::get($this->app['config']['database.connections'], $name);
        // Validate before Laravel parses and removes the connection URL.
        TestEnvironmentGuard::assertConnection($raw);

        return parent::configuration($name);
    }

    public function connectUsing(string $name, array $config, bool $force = false)
    {
        TestEnvironmentGuard::assertConnection($config);

        return parent::connectUsing($name, $config, $force);
    }
}
