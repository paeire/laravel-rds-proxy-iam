<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\CredentialsInterface;
use Aws\Rds\AuthTokenGenerator;
use Closure;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDO;
use PDOException;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class IamMySqlConnector extends MySqlConnector
{
    private const DEFAULT_PORT = 3306;

    private const DEFAULT_REGION = 'us-east-1';

    private const DEFAULT_CONNECT_TIMEOUT = 5;

    private const TOKEN_LIFETIME_MINUTES = 15;

    private const TOKEN_REUSE_SECONDS = 600;

    private const CREDENTIALS_EXPIRY_MARGIN_SECONDS = 60;

    private const PROXY_HOST_PATTERN = '/\.proxy-[a-z0-9]+\.[a-z0-9-]+\.rds\.amazonaws\.com(\.cn)?$/i';

    private const TLS_OPTIONS = [
        'PDO::MYSQL_ATTR_SSL_CA',
        'PDO::MYSQL_ATTR_SSL_CAPATH',
        'PDO::MYSQL_ATTR_SSL_CERT',
        'PDO::MYSQL_ATTR_SSL_KEY',
        'PDO::MYSQL_ATTR_SSL_CIPHER',
    ];

    private ?Closure $credentials;

    private Closure $clock;

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokens = [];

    /**
     * @param  (callable(): PromiseInterface)|null  $credentials  AWS credential provider; defaults to the SDK chain.
     * @param  (Closure(): int)|null  $clock  Current Unix time.
     * @param  (Closure(): ?string)|null  $defaultConnection  Name of the default connection, the only one that reads DB_* env vars.
     */
    public function __construct(
        ?callable $credentials = null,
        private readonly ?AuthTokenGenerator $generator = null,
        ?Closure $clock = null,
        private readonly ?Closure $defaultConnection = null,
    ) {
        $this->credentials = $credentials === null ? null : Closure::fromCallable($credentials);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        $runtimeConfig = $this->normalizeConfig($config);
        $runtimeConfig['options'] = $this->buildOptions($runtimeConfig);
        $this->ensureTls($runtimeConfig);

        $token = $this->getIamToken($runtimeConfig);

        try {
            $pdo = parent::connect([...$runtimeConfig, 'password' => $token]);
        } catch (Throwable $exception) {
            $this->forgetIamToken($runtimeConfig);

            if (! $exception instanceof PDOException) {
                throw $exception;
            }

            // Rebuilt without the original as $previous: its trace carries the token in the
            // arguments of Laravel's connector frames whenever zend.exception_ignore_args is off.
            $sanitized = new PDOException(str_replace($token, '[redacted]', $exception->getMessage()));
            $sanitized->errorInfo = $exception->errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($sanitized, $exception->getCode());

            throw $sanitized;
        }

        $this->applySessionConfiguration($pdo, $runtimeConfig);

        return $pdo;
    }

    /**
     * Tokens are cached per process and reused while they are younger than 10 minutes and
     * the credentials that signed them have not expired.
     *
     * @param  array<string, mixed>  $config
     */
    protected function getIamToken(array $config): string
    {
        $endpoint = sprintf('%s:%d', $config['token_host'], $config['token_port']);
        $key = $this->tokenCacheKey($config);
        $now = ($this->clock)();

        if (isset($this->tokens[$key]) && $this->tokens[$key]['expires'] > $now) {
            return $this->tokens[$key]['token'];
        }

        try {
            Log::debug('[RDSProxyIam] Generating IAM token', [
                'connection' => $config['name'] ?? null,
                'region' => $config['aws_region'],
                'endpoint' => $endpoint,
            ]);

            $credentials = $this->resolveCredentials();
            $generator = $this->generator ?? new AuthTokenGenerator($credentials);
            $token = $generator->createToken($endpoint, $config['aws_region'], $config['username'], self::TOKEN_LIFETIME_MINUTES);
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

        $expires = $now + self::TOKEN_REUSE_SECONDS;
        $credentialsExpiration = $credentials->getExpiration();
        if ($credentialsExpiration !== null) {
            $expires = min($expires, (int) $credentialsExpiration - self::CREDENTIALS_EXPIRY_MARGIN_SECONDS);
        }

        $this->tokens[$key] = ['token' => $token, 'expires' => $expires];

        return $token;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function forgetIamToken(array $config): void
    {
        unset($this->tokens[$this->tokenCacheKey($config)]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function tokenCacheKey(array $config): string
    {
        return sprintf('%s:%d|%s|%s', $config['token_host'], $config['token_port'], $config['aws_region'], $config['username']);
    }

    private function resolveCredentials(): CredentialsInterface
    {
        // defaultProvider() memoizes internally, so keeping one instance per process means
        // IMDS/STS are only hit again when the credentials expire.
        $this->credentials ??= Closure::fromCallable(CredentialProvider::defaultProvider());

        return ($this->credentials)()->wait();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function normalizeConfig(array $config): array
    {
        $host = $this->getString($config, ['host', 'DB_HOST']);
        $port = $this->getInt($config, ['port', 'DB_PORT'], self::DEFAULT_PORT);
        $username = $this->getString($config, ['username', 'DB_USERNAME']);
        $database = $this->getString($config, ['database', 'DB_DATABASE'], allowEmpty: true);
        $tokenHost = $this->getString($config, ['token_host', 'DB_TOKEN_HOST'], $host);
        $tokenPort = $this->getInt($config, ['token_port', 'DB_TOKEN_PORT'], $port);
        $region = $this->getString($config, ['aws_region', 'region', 'AWS_REGION'], self::DEFAULT_REGION);

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

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    protected function buildOptions(array $config): array
    {
        $options = $this->getOptions($config);

        $sslCa = $this->getString($config, ['ssl_ca', 'DB_SSL_CA'], allowEmpty: true);
        if ($sslCa !== null) {
            if (! is_readable($sslCa)) {
                throw new InvalidArgumentException(sprintf('The SSL CA file is not readable: %s', $sslCa));
            }

            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            }
        }

        $verifySsl = $this->getBool($config, ['ssl_verify', 'DB_SSL_VERIFY'], true);
        if (! $verifySsl && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $timeout = $this->getInt($config, ['connect_timeout', 'DB_CONNECT_TIMEOUT'], self::DEFAULT_CONNECT_TIMEOUT);
        if ($timeout > 0) {
            $options[PDO::ATTR_TIMEOUT] = $timeout;
        }

        if (defined('PDO::ATTR_EMULATE_PREPARES') && ! array_key_exists(PDO::ATTR_EMULATE_PREPARES, $options)) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        // Only pdo_mysql builds linked against libmysqlclient read this; mysqlnd answers the
        // server's mysql_clear_password auth switch on its own.
        if ($this->getBool($config, ['enable_cleartext_plugin'], true) && getenv('MYSQL_ENABLE_CLEARTEXT_PLUGIN') !== '1') {
            putenv('MYSQL_ENABLE_CLEARTEXT_PLUGIN=1');
        }

        return $options;
    }

    /**
     * AWS rejects IAM authentication through RDS Proxy without TLS, so a proxy endpoint
     * requires it unless `require_tls` is explicitly disabled.
     *
     * @param  array<string, mixed>  $config
     */
    protected function ensureTls(array $config): void
    {
        $isProxy = preg_match(self::PROXY_HOST_PATTERN, (string) $config['token_host']) === 1;
        $required = $this->getBool($config, ['require_tls', 'DB_REQUIRE_TLS'], $isProxy);

        if ($this->usesTls($config['options'] ?? [])) {
            return;
        }

        if ($required) {
            throw new InvalidArgumentException(sprintf(
                'Connection "%s" (%s) requires TLS but none is configured. Set ssl_ca (DB_SSL_CA) to the Amazon RDS CA bundle, or set require_tls to false.',
                $config['name'] ?? 'default',
                $config['token_host'],
            ));
        }

        if ($isProxy) {
            Log::warning('[RDSProxyIam] TLS is disabled for an RDS Proxy connection; AWS requires TLS for IAM authentication through a proxy.', [
                'connection' => $config['name'] ?? null,
                'endpoint' => $config['token_host'],
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private function usesTls(array $options): bool
    {
        foreach (self::TLS_OPTIONS as $option) {
            if (defined($option) && ! empty($options[constant($option)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function applySessionConfiguration(PDO $pdo, array $config): void
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

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function getSessionInitStatements(array $config): array
    {
        $value = $config['session_init_statements'] ?? $config['DB_SESSION_INIT_STATEMENTS'] ?? null;
        if ($value === null) {
            $value = $this->readsEnvironment($config, 'DB_SESSION_INIT_STATEMENTS')
                ? getenv('DB_SESSION_INIT_STATEMENTS')
                : false;
        }

        if ($value === false || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(';', $value)));
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('session_init_statements must be a string or array.');
        }

        $statements = [];
        foreach ($value as $statement) {
            if (! is_string($statement)) {
                throw new InvalidArgumentException('session_init_statements entries must be strings.');
            }

            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * DB_* variables describe the default connection, so any other connection reads only
     * its own config and never inherits the default's host, user or token endpoint.
     *
     * @param  array<string, mixed>  $config
     */
    protected function readsEnvironment(array $config, string $key): bool
    {
        if ($key !== strtoupper($key)) {
            return false;
        }

        if (! str_starts_with($key, 'DB_')) {
            return true;
        }

        $name = $config['name'] ?? null;
        if ($name === null || $this->defaultConnection === null) {
            return true;
        }

        return $name === ($this->defaultConnection)();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $keys
     */
    protected function getString(array $config, array $keys, ?string $default = null, bool $allowEmpty = false): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(sprintf('Invalid value type for "%s".', $key));
            }

            $value = $value === null ? null : trim((string) $value);
            if ($value === null || (! $allowEmpty && $value === '')) {
                continue;
            }

            return $value;
        }

        foreach ($keys as $key) {
            if (! $this->readsEnvironment($config, $key)) {
                continue;
            }

            $envValue = getenv($key);
            if ($envValue === false) {
                continue;
            }

            $envValue = trim((string) $envValue);
            if (! $allowEmpty && $envValue === '') {
                continue;
            }

            return $envValue;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $keys
     */
    protected function getInt(array $config, array $keys, int $default): int
    {
        $value = $this->getString($config, $keys, (string) $default);

        if ($value === null || ! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Invalid numeric value for "%s".', $keys[0]));
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $keys
     */
    protected function getBool(array $config, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $config)) {
                continue;
            }

            return filter_var($config[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        foreach ($keys as $key) {
            if (! $this->readsEnvironment($config, $key)) {
                continue;
            }

            $envValue = getenv($key);
            if ($envValue === false) {
                continue;
            }

            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }
}
