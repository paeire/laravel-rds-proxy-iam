<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use Illuminate\Log\Events\MessageLogged;
use InvalidArgumentException;
use Paeire\RdsProxyIam\Tests\Concerns\ClearsEnvironment;
use Paeire\RdsProxyIam\Tests\Concerns\MakesPdoDoubles;
use Paeire\RdsProxyIam\Tests\Support\FakeCredentials;
use Paeire\RdsProxyIam\Tests\Support\FakeTokenGenerator;
use Paeire\RdsProxyIam\Tests\Support\RecordingIamMySqlConnector;
use Paeire\RdsProxyIam\Tests\Support\TestableIamMySqlConnector;
use PDO;

class TlsRequirementTest extends TestCase
{
    use ClearsEnvironment;
    use MakesPdoDoubles;

    private const PROXY = 'app.proxy-c1a2b3c4d5e6.us-east-1.rds.amazonaws.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnv(['DB_REQUIRE_TLS', 'DB_SSL_CA']);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge(['name' => 'rds', 'token_host' => self::PROXY, 'options' => []], $overrides);
    }

    public function test_a_proxy_endpoint_without_tls_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Connection "rds" ('.self::PROXY.') requires TLS but none is configured. Set ssl_ca (DB_SSL_CA)');

        (new TestableIamMySqlConnector)->exposeEnsureTls($this->config());
    }

    public function test_custom_proxy_endpoints_are_detected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TestableIamMySqlConnector)->exposeEnsureTls($this->config([
            'token_host' => 'reads.endpoint.proxy-c1a2b3c4d5e6.us-east-1.rds.amazonaws.com',
        ]));
    }

    public function test_a_proxy_endpoint_with_a_ca_bundle_is_accepted(): void
    {
        (new TestableIamMySqlConnector)->exposeEnsureTls($this->config([
            'options' => [PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/rds-global-bundle.pem'],
        ]));

        $this->addToAssertionCount(1);
    }

    public function test_disabling_tls_for_a_proxy_logs_a_warning(): void
    {
        $logged = [];
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        (new TestableIamMySqlConnector)->exposeEnsureTls($this->config(['require_tls' => false]));

        $this->assertCount(1, $logged);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertStringContainsString('TLS is disabled for an RDS Proxy connection', $logged[0]->message);
    }

    public function test_a_non_proxy_host_does_not_require_tls_by_default(): void
    {
        (new TestableIamMySqlConnector)->exposeEnsureTls($this->config(['token_host' => 'db.internal']));

        $this->addToAssertionCount(1);
    }

    public function test_tls_can_be_required_for_any_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TestableIamMySqlConnector)->exposeEnsureTls($this->config(['token_host' => 'db.internal', 'require_tls' => true]));
    }

    public function test_the_check_runs_before_any_token_is_generated(): void
    {
        $generator = new FakeTokenGenerator;
        $connector = new RecordingIamMySqlConnector(
            fn () => $this->livePdo(),
            credentials: FakeCredentials::provider(),
            generator: $generator,
        );

        try {
            $connector->connect(['name' => 'rds', 'host' => '127.0.0.1', 'token_host' => self::PROXY, 'username' => 'iam_user']);
            $this->fail('Expected the TLS check to reject the connection.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $generator->calls);
            $this->assertSame([], $connector->connections);
        }
    }
}
