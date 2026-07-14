<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Paeire\RdsProxyIam\IamMySqlConnector;
use Paeire\RdsProxyIam\IamServiceProvider;

class ServiceProviderTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.rds', [
            'driver' => IamServiceProvider::DRIVER,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'testing',
            'username' => 'iam_user',
            'aws_region' => 'us-east-1',
        ]);
    }

    public function test_it_binds_the_iam_connector(): void
    {
        $key = 'db.connector.'.IamServiceProvider::DRIVER;

        $this->assertTrue($this->app->bound($key));
        $this->assertInstanceOf(IamMySqlConnector::class, $this->app->make($key));
    }

    public function test_it_registers_a_connection_resolver(): void
    {
        $this->assertNotNull(Connection::getResolver(IamServiceProvider::DRIVER));
    }

    /**
     * Regression test for order-independent registration: resolving the
     * connection must yield a MySqlConnection without opening a PDO (and thus
     * without generating an AWS IAM token). The PDO is created lazily on first
     * query, so this proves the driver is ready no matter when the database is
     * first resolved.
     */
    public function test_it_resolves_a_mysql_connection_lazily(): void
    {
        $connection = DB::connection('rds');

        $this->assertInstanceOf(MySqlConnection::class, $connection);
        $this->assertSame(IamServiceProvider::DRIVER, $connection->getConfig('driver'));
    }
}
