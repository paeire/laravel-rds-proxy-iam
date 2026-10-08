<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support;

use Closure;
use Paeire\RdsProxyIam\IamMySqlConnector;
use PDO;

/**
 * Records every PDO the connector would open and hands back a test double instead,
 * so the full Laravel connection lifecycle runs without a MySQL server.
 */
class RecordingIamMySqlConnector extends IamMySqlConnector
{
    /** @var array<int, array{dsn: string, host: mixed, password: mixed}> */
    public array $connections = [];

    /** @var (Closure(array<string, mixed>): \Throwable)|null */
    public ?Closure $failure = null;

    /**
     * @param  Closure(): PDO  $pdoFactory
     */
    public function __construct(public Closure $pdoFactory, mixed ...$arguments)
    {
        parent::__construct(...$arguments);
    }

    public function createConnection($dsn, array $config, array $options)
    {
        $this->connections[] = ['dsn' => $dsn, 'host' => $config['host'], 'password' => $config['password']];

        if ($this->failure !== null) {
            throw ($this->failure)($config);
        }

        return ($this->pdoFactory)();
    }
}
