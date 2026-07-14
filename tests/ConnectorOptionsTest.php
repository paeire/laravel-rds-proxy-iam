<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use InvalidArgumentException;
use Paeire\RdsProxyIam\Tests\Concerns\ClearsEnvironment;
use Paeire\RdsProxyIam\Tests\Support\TestableIamMySqlConnector;
use PDO;

class ConnectorOptionsTest extends TestCase
{
    use ClearsEnvironment;

    private const ENV_KEYS = [
        'connect_timeout', 'DB_CONNECT_TIMEOUT', 'ssl_ca', 'DB_SSL_CA',
        'ssl_verify', 'DB_SSL_VERIFY', 'enable_cleartext_plugin',
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

    public function test_it_sets_a_default_connect_timeout(): void
    {
        $options = $this->connector()->exposeBuildOptions([]);

        $this->assertSame(5, $options[PDO::ATTR_TIMEOUT]);
    }

    public function test_it_honours_a_custom_connect_timeout(): void
    {
        $options = $this->connector()->exposeBuildOptions(['connect_timeout' => 12]);

        $this->assertSame(12, $options[PDO::ATTR_TIMEOUT]);
    }

    public function test_it_disables_prepared_statement_emulation(): void
    {
        $options = $this->connector()->exposeBuildOptions([]);

        $this->assertArrayHasKey(PDO::ATTR_EMULATE_PREPARES, $options);
        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
    }

    public function test_it_enables_the_cleartext_auth_plugin_by_default(): void
    {
        if (! defined('PDO::MYSQL_ATTR_DEFAULT_AUTH')) {
            $this->markTestSkipped('pdo_mysql is not available.');
        }

        $options = $this->connector()->exposeBuildOptions([]);

        $this->assertSame('mysql_clear_password', $options[PDO::MYSQL_ATTR_DEFAULT_AUTH]);
    }

    public function test_it_can_disable_server_certificate_verification(): void
    {
        if (! defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $this->markTestSkipped('pdo_mysql is not available.');
        }

        $options = $this->connector()->exposeBuildOptions(['ssl_verify' => false]);

        $this->assertFalse($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function test_it_rejects_an_unreadable_ssl_ca_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL CA');

        $this->connector()->exposeBuildOptions(['ssl_ca' => '/does/not/exist/ca.pem']);
    }

    public function test_it_accepts_a_readable_ssl_ca_file(): void
    {
        if (! defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $this->markTestSkipped('pdo_mysql is not available.');
        }

        $caFile = tempnam(sys_get_temp_dir(), 'ca');
        $this->assertIsString($caFile);

        try {
            $options = $this->connector()->exposeBuildOptions(['ssl_ca' => $caFile]);
            $this->assertSame($caFile, $options[PDO::MYSQL_ATTR_SSL_CA]);
        } finally {
            @unlink($caFile);
        }
    }
}
