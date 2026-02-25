<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam;

use Aws\Credentials\CredentialProvider;
use Aws\Rds\AuthTokenGenerator;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

class IamMySqlConnector extends MySqlConnector
{
    public function connect(array $config): PDO
    {
        $runtimeConfig = $this->normalizeConfig($config);
        $runtimeConfig['password'] = $this->getIamToken($runtimeConfig);
        $runtimeConfig['options'] = $this->buildOptions($runtimeConfig);

        $pdo = parent::connect($runtimeConfig);
        $this->applySessionConfiguration($pdo, $runtimeConfig);

        return $pdo;
    }

    protected function getIamToken(array $config): string
    {
        $provider = CredentialProvider::defaultProvider();
        $generator = new AuthTokenGenerator($provider);
        $endpoint = sprintf('%s:%d', $config['token_host'], $config['token_port']);

        try {
            Log::debug('[RDSProxyIam] Generating IAM token', [
                'connection' => $config['name'] ?? null,
                'region' => $config['aws_region'],
                'endpoint' => $endpoint,
            ]);

            return $generator->createToken($endpoint, $config['aws_region'], $config['username']);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    'Unable to generate IAM token for "%s" (%s): %s',
                    $config['name'] ?? 'default',
                    $endpoint,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    private function normalizeConfig(array $config): array
    {
        $host = $this->getString($config, ['host', 'DB_HOST']);
        $port = $this->getInt($config, ['port', 'DB_PORT'], 3306);
        $username = $this->getString($config, ['username', 'DB_USERNAME']);
        $database = $this->getString($config, ['database', 'DB_DATABASE'], allowEmpty: true);
        $tokenHost = $this->getString($config, ['token_host', 'DB_TOKEN_HOST'], $host);
        $tokenPort = $this->getInt($config, ['token_port', 'DB_TOKEN_PORT'], $port);
        $region = $this->getString($config, ['aws_region', 'AWS_REGION'], 'us-east-1');

        if ($host === null) {
            throw new InvalidArgumentException('Missing required database host (host/DB_HOST).');
        }

        if ($username === null) {
            throw new InvalidArgumentException('Missing required database username (username/DB_USERNAME).');
        }

        if ($tokenHost === null) {
            throw new InvalidArgumentException('Missing required token host (token_host/DB_TOKEN_HOST).');
        }

        return array_merge($config, [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'token_host' => $tokenHost,
            'token_port' => $tokenPort,
            'aws_region' => $region,
            'charset' => $config['charset'] ?? 'utf8mb4',
            'collation' => $config['collation'] ?? 'utf8mb4_unicode_ci',
        ]);
    }

    private function buildOptions(array $config): array
    {
        $options = $this->getOptions($config);

        $sslCa = $this->getString($config, ['ssl_ca', 'DB_SSL_CA'], allowEmpty: true);
        if ($sslCa !== null) {
            if (!is_readable($sslCa)) {
                throw new InvalidArgumentException(sprintf('The SSL CA file is not readable: %s', $sslCa));
            }

            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            }
        }

        $verifySsl = $this->getBool($config, ['ssl_verify', 'DB_SSL_VERIFY'], true);
        if (!$verifySsl && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $timeout = $this->getInt($config, ['connect_timeout', 'DB_CONNECT_TIMEOUT'], 5);
        if ($timeout > 0) {
            $options[PDO::ATTR_TIMEOUT] = $timeout;
        }

        if (defined('PDO::ATTR_EMULATE_PREPARES') && !array_key_exists(PDO::ATTR_EMULATE_PREPARES, $options)) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        $enableCleartext = $this->getBool($config, ['enable_cleartext_plugin'], true);
        if ($enableCleartext) {
            if (defined('PDO::MYSQL_ATTR_DEFAULT_AUTH')) {
                $options[PDO::MYSQL_ATTR_DEFAULT_AUTH] = 'mysql_clear_password';
            }

            if (getenv('MYSQL_ENABLE_CLEARTEXT_PLUGIN') !== '1') {
                putenv('MYSQL_ENABLE_CLEARTEXT_PLUGIN=1');
            }
        }

        return $options;
    }

    private function applySessionConfiguration(PDO $pdo, array $config): void
    {
        $forceReadonly = $this->getBool($config, ['force_readonly', 'DB_FORCE_READONLY'], false);
        if ($forceReadonly) {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('SET SESSION sql_safe_updates = 1');
        }

        foreach ($this->getSessionInitStatements($config) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function getSessionInitStatements(array $config): array
    {
        $value = $config['session_init_statements'] ?? $config['DB_SESSION_INIT_STATEMENTS'] ?? null;
        if ($value === null) {
            $value = getenv('DB_SESSION_INIT_STATEMENTS');
        }

        if ($value === false || $value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(';', $value)));
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('session_init_statements must be a string or array.');
        }

        $statements = [];
        foreach ($value as $statement) {
            if (!is_string($statement)) {
                throw new InvalidArgumentException('session_init_statements entries must be strings.');
            }

            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function getString(array $config, array $keys, ?string $default = null, bool $allowEmpty = false): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(sprintf('Invalid value type for "%s".', $key));
            }

            $value = $value === null ? null : trim((string) $value);
            if ($value === null || (!$allowEmpty && $value === '')) {
                continue;
            }

            return $value;
        }

        foreach ($keys as $key) {
            $envValue = getenv($key);
            if ($envValue === false) {
                continue;
            }

            $envValue = trim((string) $envValue);
            if (!$allowEmpty && $envValue === '') {
                continue;
            }

            return $envValue;
        }

        return $default;
    }

    private function getInt(array $config, array $keys, int $default): int
    {
        $value = $this->getString($config, $keys, (string) $default);

        if ($value === null || !is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Invalid numeric value for "%s".', $keys[0]));
        }

        return (int) $value;
    }

    private function getBool(array $config, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            return filter_var($config[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        foreach ($keys as $key) {
            $envValue = getenv($key);
            if ($envValue === false) {
                continue;
            }

            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }
}
