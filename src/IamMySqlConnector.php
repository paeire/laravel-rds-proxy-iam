<?php

namespace Paeire\RdsProxyIam;

use Aws\Credentials\CredentialProvider;
use Aws\Rds\AuthTokenGenerator;
use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use Illuminate\Support\Facades\Log;


class IamMySqlConnector extends MySqlConnector
{
    protected function getIamToken(array $config): string
    {
        $region = $config['AWS_REGION'] ?? getenv('AWS_REGION') ?: 'us-east-1';
        //$config
        $host   = getenv('DB_TOKEN_HOST');
        $config['host'] = getenv('DB_HOST');
        // 👇 El token SIEMPRE debe firmarse para el puerto REMOTO (del proxy), NO el del túnel.
        //    Permite override con DB_TOKEN_PORT o 'token_port' en la conexión. Default: 3306.
        $tokenPort = (int)($config['DB_PORT'] ?? getenv('DB_TOKEN_PORT') ?: 3306);
        $user   = getenv('DB_USERNAME'); //$config['DB_USERNAME'];
        $config['port'] = getenv('DB_PORT') ?? 3306;
        $config['database'] = getenv('DB_DATABASE');
        $config['username'] = $user;

        $provider = CredentialProvider::defaultProvider();
        $gen = new AuthTokenGenerator($provider);

        // Auth token requiere "host:port" del destino real
        $endpoint = sprintf('%s:%d', $host, $tokenPort);

        $token = $gen->createToken($endpoint, $region, $user);
        
        Log::info('[RDSProxy] getIamToken()', [
            'region' => $region,
            'host' => $host,
            'tokenPort' => $tokenPort,
            'user' => $user,
            'token' => $token,
        ]);
        
        return $token;
    }

    public function connect(array $config)
    {
        // Genera token (válido ~15 min)
        $config['password'] = $this->getIamToken($config);

        // Opciones PDO/MySQL
        $options = $this->getOptions($config);

        // TLS: cargar CA si existe
        $sslCa = $config['ssl_ca'] ?? getenv('DB_SSL_CA') ?: null;
        if ($sslCa && is_string($sslCa) && is_readable($sslCa)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        }
        
        // TLS: permitir desactivar verificación (equivalente a --ssl-mode=REQUIRED)
        $verify = $config['ssl_verify'] ?? getenv('DB_SSL_VERIFY') ?? 'true';
        if (filter_var($verify, FILTER_VALIDATE_BOOLEAN) === false) {
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }
        
        Log::info('[RDSProxy] connect()', [
            'sslCa' => $sslCa,
            'verify' => $verify,
        ]);
        Log::info('[RDSProxy] connect()', [
            'driver'   => 'mysql',
            // 👇 Aquí sí usamos el puerto LOCAL del túnel para el socket TCP
            'host'     => $config['host'],
            'port'     => $config['port'] ?? 3306,
            'database' => $config['database'] ?? null,
            'username' => $config['username'],
            'password' => $config['password'],
            'charset'  => $config['charset'] ?? 'utf8mb4',
            'collation'=> $config['collation'] ?? 'utf8mb4_unicode_ci',
            'options'  => $options,
        ]);

        
        // Conectar con token IAM
        $pdo = parent::connect([
            'driver'   => 'mysql',
            // 👇 Aquí sí usamos el puerto LOCAL del túnel para el socket TCP
            'host'     => $config['host'],
            'port'     => $config['port'] ?? 3306,
            'database' => $config['database'] ?? null,
            'username' => $config['username'],
            'password' => $config['password'],
            'charset'  => $config['charset'] ?? 'utf8mb4',
            'collation'=> $config['collation'] ?? 'utf8mb4_unicode_ci',
            'options'  => $options,
        ]);
        
        // 🔑 IAM DB Auth: forzar el plugin cleartext (además de la env var)
        if (defined('PDO::MYSQL_ATTR_DEFAULT_AUTH')) {
            $options[PDO::MYSQL_ATTR_DEFAULT_AUTH] = 'mysql_clear_password';
        }
        // Asegura que la extensión lo permita (equivalente a --enable-cleartext-plugin del CLI)
        if (getenv('MYSQL_ENABLE_CLEARTEXT_PLUGIN') !== '1') {
            putenv('MYSQL_ENABLE_CLEARTEXT_PLUGIN=1');
        }
        
        // Conectar con token IAM
        $pdo = parent::connect([
            'driver'   => 'mysql',
            // 👇 Aquí sí usamos el puerto LOCAL del túnel para el socket TCP
            'host'     => getenv('DB_HOST'),
            'port'     => getenv('DB_PORT') ?? 3306,
            'database' => getenv('DB_DATABASE') ?? null,
            'username' => getenv('DB_USERNAME'),
            'password' => $config['password'],
            'charset'  => $config['charset'] ?? 'utf8mb4',
            'collation'=> $config['collation'] ?? 'utf8mb4_unicode_ci',
            'options'  => $options,
        ]);

        // (Opcional) refuerzo de solo-lectura en sesión
        $forceReadonly = $config['force_readonly'] ?? getenv('DB_FORCE_READONLY') ?? 'false';
        if (filter_var($forceReadonly, FILTER_VALIDATE_BOOLEAN) === true) {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('SET SESSION sql_safe_updates = 1');
        }

        return $pdo;
    }
}
