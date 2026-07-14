<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use InvalidArgumentException;
use Paeire\RdsProxyIam\Tests\Concerns\ClearsEnvironment;
use Paeire\RdsProxyIam\Tests\Support\TestableIamMySqlConnector;

class ConnectorConfigTest extends TestCase
{
    use ClearsEnvironment;

    private const ENV_KEYS = [
        'host', 'DB_HOST', 'port', 'DB_PORT', 'username', 'DB_USERNAME',
        'database', 'DB_DATABASE', 'token_host', 'DB_TOKEN_HOST',
        'token_port', 'DB_TOKEN_PORT', 'aws_region', 'AWS_REGION',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnv(self::ENV_KEYS);
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

    public function test_it_requires_a_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host');

        $this->connector()->exposeNormalizeConfig(['username' => 'iam_user']);
    }

    public function test_it_requires_a_username(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('username');

        $this->connector()->exposeNormalizeConfig(['host' => 'db.internal']);
    }

    public function test_it_applies_defaults(): void
    {
        $config = $this->connector()->exposeNormalizeConfig([
            'host' => 'db.internal',
            'username' => 'iam_user',
        ]);

        $this->assertSame('mysql', $config['driver']);
        $this->assertSame(3306, $config['port']);
        $this->assertSame('us-east-1', $config['aws_region']);
        $this->assertSame('utf8mb4', $config['charset']);
        $this->assertSame('utf8mb4_unicode_ci', $config['collation']);
    }

    public function test_token_host_and_port_fall_back_to_connection_host_and_port(): void
    {
        $config = $this->connector()->exposeNormalizeConfig([
            'host' => 'db.internal',
            'port' => 6033,
            'username' => 'iam_user',
        ]);

        $this->assertSame('db.internal', $config['token_host']);
        $this->assertSame(6033, $config['token_port']);
    }

    public function test_token_host_and_port_can_be_overridden(): void
    {
        $config = $this->connector()->exposeNormalizeConfig([
            'host' => '127.0.0.1',
            'port' => 3307,
            'username' => 'iam_user',
            'token_host' => 'proxy.rds.amazonaws.com',
            'token_port' => 3306,
        ]);

        $this->assertSame('proxy.rds.amazonaws.com', $config['token_host']);
        $this->assertSame(3306, $config['token_port']);
    }
}
