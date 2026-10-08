<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Concerns;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Laravel 10 configures the session through prepare()->execute() and 11+ through exec(),
 * so the doubles answer both and a dead connection only fails on application queries.
 */
trait MakesPdoDoubles
{
    protected function livePdo(): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($this->createStub(PDOStatement::class));

        return $pdo;
    }

    protected function deadPdo(): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $query) use ($statement): PDOStatement {
            if (stripos($query, 'set ') === 0) {
                return $statement;
            }

            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        });

        return $pdo;
    }
}
