<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class IamServiceProvider extends ServiceProvider
{
    private bool $connectorRegistered = false;
    private bool $driverExtended = false;

    public function register(): void
    {
        $this->registerConnector();

        // Register as early as possible for apps that resolve `db` during container setup.
        $this->app->beforeResolving('db', function (): void {
            $this->registerConnector();
        });

        // Extend the manager immediately after it is resolved.
        $this->app->afterResolving('db', function (DatabaseManager $db): void {
            $this->extendDriver($db);
        });

        if ($this->app->resolved('db')) {
            /** @var DatabaseManager $db */
            $db = $this->app->make('db');
            $this->extendDriver($db);
            $this->warnIfDatabaseWasResolvedEarly($db);
        }
    }

    private function registerConnector(): void
    {
        if ($this->connectorRegistered) {
            return;
        }

        $this->app->singleton('db.connector.mysql-iam-proxy', static function (): IamMySqlConnector {
            return new IamMySqlConnector();
        });

        $this->connectorRegistered = true;
    }

    private function extendDriver(DatabaseManager $db): void
    {
        if ($this->driverExtended) {
            return;
        }

        $db->extend('mysql-iam-proxy', function (array $config, string $name): MySqlConnection {
            $config['name'] = $name;

            /** @var IamMySqlConnector $connector */
            $connector = $this->app->make('db.connector.mysql-iam-proxy');
            $pdo = $connector->connect($config);

            return new MySqlConnection(
                $pdo,
                $config['database'] ?? null,
                $config['prefix'] ?? '',
                $config
            );
        });

        $this->driverExtended = true;
    }

    private function warnIfDatabaseWasResolvedEarly(DatabaseManager $db): void
    {
        if (!is_callable([$db, 'getConnections'])) {
            return;
        }

        $connections = $db->getConnections();
        if (count($connections) === 0) {
            return;
        }

        Log::warning('[RDSProxyIam] Database connections were opened before IamServiceProvider registration.', [
            'open_connections' => array_keys($connections),
            'recommendation' => 'Register this provider before any provider that resolves DB connections.',
        ]);
    }
}
