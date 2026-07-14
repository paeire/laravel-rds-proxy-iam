<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use InvalidArgumentException;
use Paeire\RdsProxyIam\Tests\Concerns\ClearsEnvironment;
use Paeire\RdsProxyIam\Tests\Support\TestableIamMySqlConnector;

class SessionStatementsTest extends TestCase
{
    use ClearsEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnv(['DB_SESSION_INIT_STATEMENTS']);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        parent::tearDown();
    }

    private function connector(): TestableIamMySqlConnector
    {
        return new TestableIamMySqlConnector;
    }

    public function test_it_returns_no_statements_when_unset(): void
    {
        $this->assertSame([], $this->connector()->exposeSessionInitStatements([]));
    }

    public function test_it_parses_a_semicolon_delimited_string(): void
    {
        $statements = $this->connector()->exposeSessionInitStatements([
            'session_init_statements' => "SET time_zone = '+00:00'; SET wait_timeout = 30",
        ]);

        $this->assertSame([
            "SET time_zone = '+00:00'",
            'SET wait_timeout = 30',
        ], $statements);
    }

    public function test_it_accepts_an_array_and_trims_empties(): void
    {
        $statements = $this->connector()->exposeSessionInitStatements([
            'session_init_statements' => ['SET a = 1', '   ', '', 'SET b = 2'],
        ]);

        $this->assertSame(['SET a = 1', 'SET b = 2'], $statements);
    }

    public function test_it_rejects_non_string_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->connector()->exposeSessionInitStatements([
            'session_init_statements' => ['SET a = 1', 123],
        ]);
    }
}
