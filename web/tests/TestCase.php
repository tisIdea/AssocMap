<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use RuntimeException;
use Tests\Support\TestEnvironmentGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        if (isset($this->traitsUsedByTest[WithCachedConfig::class])
            || isset($this->traitsUsedByTest[WithCachedRoutes::class])) {
            throw new RuntimeException('Cached application testing requires a separately reviewed isolation setup.');
        }

        TestEnvironmentGuard::install($app);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
