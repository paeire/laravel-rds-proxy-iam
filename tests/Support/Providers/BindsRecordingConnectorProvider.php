<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support\Providers;

use Illuminate\Support\ServiceProvider;
use Paeire\RdsProxyIam\IamServiceProvider;

/**
 * Stands in for config/database.php, which is loaded before any provider registers.
 */
class BindsRecordingConnectorProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('database.connections.rds', [
            'driver' => IamServiceProvider::DRIVER,
            'host' => 'db.internal',
            'database' => 'testing',
            'username' => 'iam_user',
            'aws_region' => 'us-east-1',
        ]);

        $this->app->instance('db.connector.'.IamServiceProvider::DRIVER, ProviderOrderState::$connector);
    }
}
