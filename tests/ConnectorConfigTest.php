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
        'DB_SESSION_INIT_STATEMENTS',
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

    public function test_region_is_accepted_as_an_alias_of_aws_region(): void
    {
        $config = $this->connector()->exposeNormalizeConfig([
            'host' => 'db.internal',
            'username' => 'iam_user',
            'region' => 'us-west-2',
        ]);

        $this->assertSame('us-west-2', $config['aws_region']);
    }

    public function test_aws_region_takes_precedence_over_region(): void
    {
        $config = $this->connector()->exposeNormalizeConfig([
            'host' => 'db.internal',
            'username' => 'iam_user',
            'aws_region' => 'eu-west-1',
            'region' => 'us-west-2',
        ]);

        $this->assertSame('eu-west-1', $config['aws_region']);
    }

    private function connectorWithDefault(string $default): TestableIamMySqlConnector
    {
        return new TestableIamMySqlConnector(defaultConnection: static fn (): string => $default);
    }

    private function exportDefaultConnectionEnv(): void
    {
        putenv('DB_HOST=main.internal');
        putenv('DB_USERNAME=main_user');
        putenv('DB_TOKEN_HOST=main.proxy.internal');
        putenv('DB_SESSION_INIT_STATEMENTS=SET a = 1');
        putenv('AWS_REGION=us-west-2');
    }

    public function test_the_default_connection_falls_back_to_db_env_vars(): void
    {
        $this->exportDefaultConnectionEnv();

        $config = $this->connectorWithDefault('mysql')->exposeNormalizeConfig(['name' => 'mysql']);

        $this->assertSame('main.internal', $config['host']);
        $this->assertSame('main_user', $config['username']);
        $this->assertSame('main.proxy.internal', $config['token_host']);
    }

    public function test_a_secondary_connection_does_not_inherit_db_env_vars(): void
    {
        $this->exportDefaultConnectionEnv();
        $connector = $this->connectorWithDefault('mysql');

        $config = $connector->exposeNormalizeConfig([
            'name' => 'reporting',
            'host' => 'reporting.internal',
            'username' => 'report_user',
        ]);

        $this->assertSame('reporting.internal', $config['token_host']);
        $this->assertSame('us-west-2', $config['aws_region']);
        $this->assertSame([], $connector->exposeSessionInitStatements(['name' => 'reporting']));
    }

    public function test_a_secondary_connection_without_a_host_fails_instead_of_borrowing_one(): void
    {
        $this->exportDefaultConnectionEnv();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host');

        $this->connectorWithDefault('mysql')->exposeNormalizeConfig(['name' => 'reporting', 'username' => 'report_user']);
    }
}
