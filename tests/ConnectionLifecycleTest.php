<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Paeire\RdsProxyIam\IamServiceProvider;
use Paeire\RdsProxyIam\Tests\Concerns\MakesPdoDoubles;
use Paeire\RdsProxyIam\Tests\Support\FakeCredentials;
use Paeire\RdsProxyIam\Tests\Support\FakeTokenGenerator;
use Paeire\RdsProxyIam\Tests\Support\RecordingIamMySqlConnector;
use PDO;
use PDOException;

class ConnectionLifecycleTest extends TestCase
{
    use MakesPdoDoubles;

    private int $now = 1_700_000_000;

    private FakeTokenGenerator $generator;

    private RecordingIamMySqlConnector $connector;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $connection = [
            'driver' => IamServiceProvider::DRIVER,
            'host' => 'writer.internal',
            'port' => 3306,
            'database' => 'testing',
            'username' => 'iam_user',
            'aws_region' => 'us-east-1',
        ];

        $app['config']->set('database.connections.rds', $connection);
        $app['config']->set('database.connections.rds_split', array_merge($connection, [
            'read' => ['host' => 'reader.internal'],
            'write' => ['host' => 'writer.internal'],
        ]));

        $this->generator = new FakeTokenGenerator;
        $this->connector = new RecordingIamMySqlConnector(
            fn () => $this->livePdo(),
            credentials: FakeCredentials::provider(),
            generator: $this->generator,
            clock: fn (): int => $this->now,
        );

        $app->instance('db.connector.'.IamServiceProvider::DRIVER, $this->connector);
    }

    public function test_reconnecting_within_the_ttl_reuses_the_token(): void
    {
        $connection = DB::connection('rds');
        $connection->getPdo();

        $this->now += 300;
        $connection->reconnect();
        $connection->getPdo();

        $this->assertCount(2, $this->connector->connections);
        $this->assertCount(1, $this->generator->calls);
        $this->assertSame($this->connector->connections[0]['password'], $this->connector->connections[1]['password']);
    }

    public function test_a_lost_connection_after_the_token_expired_reconnects_with_a_new_token(): void
    {
        $pdos = [$this->deadPdo(), $this->livePdo()];
        $this->connector->pdoFactory = function () use (&$pdos): PDO {
            return array_shift($pdos);
        };

        $connection = DB::connection('rds');
        $connection->getPdo();

        $this->now += 16 * 60;
        $this->assertSame([], $connection->select('select 1'));

        $this->assertCount(2, $this->connector->connections);
        $this->assertCount(2, $this->generator->calls);
        $this->assertNotSame($this->connector->connections[0]['password'], $this->connector->connections[1]['password']);
        $this->assertStringEndsWith('X-Amz-Signature=fake2', $this->connector->connections[1]['password']);
    }

    public function test_read_and_write_pdos_are_opened_by_the_iam_connector(): void
    {
        $connection = DB::connection('rds_split');
        $connection->getReadPdo();
        $connection->getPdo();

        $this->assertSame(['reader.internal', 'writer.internal'], array_column($this->connector->connections, 'host'));
        $this->assertSame(['reader.internal:3306', 'writer.internal:3306'], array_column($this->generator->calls, 'endpoint'));
        $this->assertNotSame($this->connector->connections[0]['password'], $this->connector->connections[1]['password']);
    }

    public function test_a_failed_connection_discards_the_cached_token(): void
    {
        $this->connector->failure = fn (): PDOException => new PDOException('SQLSTATE[HY000] [2002] Connection refused');

        try {
            DB::connection('rds')->getPdo();
            $this->fail('Expected the connection to fail.');
        } catch (PDOException) {
        }

        $this->connector->failure = null;
        DB::purge('rds');
        DB::connection('rds')->getPdo();

        $this->assertCount(2, $this->generator->calls);
    }

    public function test_connection_errors_do_not_leak_the_token(): void
    {
        $previousSetting = ini_set('zend.exception_ignore_args', '0');
        $logged = [];
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });

        $this->connector->failure = function (array $config): PDOException {
            $exception = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'iam_user' (token {$config['password']})", 1045);
            $exception->errorInfo = ['HY000', 1045, 'Access denied'];

            return $exception;
        };

        try {
            DB::connection('rds')->getPdo();
            $this->fail('Expected the connection to fail.');
        } catch (PDOException $exception) {
            $token = $this->connector->connections[0]['password'];

            $this->assertIsString($token);
            $this->assertStringNotContainsString($token, $exception->getMessage());
            $this->assertStringContainsString('[redacted]', $exception->getMessage());
            $this->assertSame(1045, $exception->getCode());
            $this->assertSame(['HY000', 1045, 'Access denied'], $exception->errorInfo);
            $this->assertNull($exception->getPrevious());
            $this->assertFalse($this->containsString($exception->getTrace(), $token), 'The stack trace arguments contain the token.');
            $this->assertStringNotContainsString($token, $exception->getTraceAsString());
            $this->assertStringNotContainsString($token, (string) json_encode($logged));
            $this->assertNotEmpty($logged);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousSetting);
        }
    }

    private function containsString(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsString($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
