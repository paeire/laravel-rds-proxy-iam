<?php

namespace Paeire\RdsProxyIam;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Log;

class IamServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }
    public function boot(): void
    {
        Log::info('[RDSProxy] boot()', [
            'env' => app()->environment(),
            'db_default' => config('database.default'),
            'has_mysql_iam' => isset(config('database.connections')['mysql-iam-proxy']),
            'db_name' => getenv('DB_USERNAME')
        ]);

        /** @var DatabaseManager $db */
        $db = $this->app['db'];

        
        $db->extend('mysql-iam-proxy', function ($config, $name) {
            Log::info('[RDSProxy] extend driver mysql-iam-proxy', ['conn' => $name]);
            $connector = new IamMySqlConnector();
            $pdo = $connector->connect($config);

            return new MySqlConnection(
                $pdo,
                getenv('DB_DATABASE') ?? null,
                $config['prefix'] ?? '',
                $config
            );
        });
    }
}
