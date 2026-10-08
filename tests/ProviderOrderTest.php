<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paeire\RdsProxyIam\IamServiceProvider;
use Paeire\RdsProxyIam\Tests\Concerns\MakesPdoDoubles;
use Paeire\RdsProxyIam\Tests\Support\FakeCredentials;
use Paeire\RdsProxyIam\Tests\Support\FakeTokenGenerator;
use Paeire\RdsProxyIam\Tests\Support\Providers\BindsRecordingConnectorProvider;
use Paeire\RdsProxyIam\Tests\Support\Providers\OpensConnectionOnRegisterProvider;
use Paeire\RdsProxyIam\Tests\Support\Providers\ProviderOrderState;
use Paeire\RdsProxyIam\Tests\Support\Providers\ResolvesDatabaseOnRegisterProvider;
use Paeire\RdsProxyIam\Tests\Support\RecordingIamMySqlConnector;
use PDO;
use ReflectionProperty;

class ProviderOrderTest extends TestCase
{
    use MakesPdoDoubles;

    private const PROVIDERS = [
        'test_a_provider_registered_later_can_open_the_connection_from_register' => [
            BindsRecordingConnectorProvider::class,
            IamServiceProvider::class,
            OpensConnectionOnRegisterProvider::class,
        ],
        'test_resolving_the_database_manager_before_this_provider_is_harmless' => [
            BindsRecordingConnectorProvider::class,
            ResolvesDatabaseOnRegisterProvider::class,
            IamServiceProvider::class,
        ],
        'test_opening_the_connection_before_this_provider_registers_fails_clearly' => [
            BindsRecordingConnectorProvider::class,
            OpensConnectionOnRegisterProvider::class,
            IamServiceProvider::class,
        ],
    ];

    private FakeTokenGenerator $generator;

    protected function setUp(): void
    {
        // Connection::resolverFor() is a static registry that outlives each test application.
        (new ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, []);

        $this->generator = new FakeTokenGenerator;
        ProviderOrderState::reset(new RecordingIamMySqlConnector(
            fn () => $this->livePdo(),
            credentials: FakeCredentials::provider(),
            generator: $this->generator,
        ));

        parent::setUp();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return self::PROVIDERS[$this->name()];
    }

    public function test_a_provider_registered_later_can_open_the_connection_from_register(): void
    {
        $this->assertNull(ProviderOrderState::$error);
        $this->assertInstanceOf(PDO::class, ProviderOrderState::$pdo);
        $this->assertCount(1, $this->generator->calls);
        $this->assertSame('db.internal:3306', $this->generator->calls[0]['endpoint']);
    }

    public function test_resolving_the_database_manager_before_this_provider_is_harmless(): void
    {
        DB::connection('rds')->getPdo();

        $this->assertCount(1, $this->generator->calls);
        $this->assertCount(1, ProviderOrderState::$connector->connections ?? []);
    }

    public function test_opening_the_connection_before_this_provider_registers_fails_clearly(): void
    {
        $this->assertInstanceOf(InvalidArgumentException::class, ProviderOrderState::$error);
        $this->assertSame('Unsupported driver [mysql-iam-proxy].', ProviderOrderState::$error->getMessage());
        $this->assertSame([], $this->generator->calls);

        DB::purge('rds');
        DB::connection('rds')->getPdo();
        $this->assertCount(1, $this->generator->calls);
    }
}
