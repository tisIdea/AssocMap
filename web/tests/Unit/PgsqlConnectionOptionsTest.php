<?php

namespace Tests\Unit;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;
use Tests\TestCase;

class PgsqlConnectionOptionsTest extends TestCase
{
    public function test_postgres_connector_keeps_native_binding_without_named_prepares(): void
    {
        $connection = config('database.connections.pgsql');

        if (! extension_loaded('pdo_pgsql')) {
            $this->assertSame([], $connection['options']);

            return;
        }

        $options = (new PostgresConnector)->getOptions($connection);

        $this->assertSame(false, $options[PDO::ATTR_EMULATE_PREPARES]);
        $this->assertSame(true, $options[PDO::PGSQL_ATTR_DISABLE_PREPARES]);
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
