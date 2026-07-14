<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\ServiceProvider;

class IamServiceProvider extends ServiceProvider
{
    /**
     * The database driver name registered by this package.
     */
    public const DRIVER = 'mysql-iam-proxy';

    public function register(): void
    {
        // Bind the connector under the key Laravel's ConnectionFactory looks up first
        // (`db.connector.{driver}`). This makes the connector available regardless of
        // when the `db` manager is resolved.
        $this->app->singleton('db.connector.'.self::DRIVER, static function (): IamMySqlConnector {
            return new IamMySqlConnector;
        });

        // Register the connection resolver on the static registry consulted by
        // ConnectionFactory::createConnection(). Because this lives in a static registry
        // (not on the resolved `db` manager), the driver is ready the moment this
        // provider's register() runs — no matter when the database is first resolved.
        // The PDO is built lazily by the factory, so the IAM token is generated on first
        // use rather than at connection resolution time.
        Connection::resolverFor(self::DRIVER, static function ($pdo, $database, $prefix, array $config): MySqlConnection {
            return new MySqlConnection($pdo, $database, $prefix, $config);
        });
    }
}
