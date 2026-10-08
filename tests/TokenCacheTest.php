<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use Closure;
use Paeire\RdsProxyIam\Tests\Support\FakeCredentials;
use Paeire\RdsProxyIam\Tests\Support\FakeTokenGenerator;
use Paeire\RdsProxyIam\Tests\Support\TestableIamMySqlConnector;
use RuntimeException;

class TokenCacheTest extends TestCase
{
    private int $now = 1_700_000_000;

    private FakeTokenGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new FakeTokenGenerator;
    }

    private function connector(?Closure $credentials = null): TestableIamMySqlConnector
    {
        return new TestableIamMySqlConnector(
            credentials: $credentials ?? FakeCredentials::provider(),
            generator: $this->generator,
            clock: fn (): int => $this->now,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'name' => 'rds',
            'token_host' => 'proxy.internal',
            'token_port' => 3306,
            'aws_region' => 'us-east-1',
            'username' => 'iam_user',
        ], $overrides);
    }

    public function test_it_reuses_a_token_within_its_ttl(): void
    {
        $connector = $this->connector();

        $first = $connector->exposeIamToken($this->config());
        $this->now += 599;
        $second = $connector->exposeIamToken($this->config());

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->generator->calls);
        $this->assertSame([
            'endpoint' => 'proxy.internal:3306',
            'region' => 'us-east-1',
            'username' => 'iam_user',
            'lifetime' => 15,
        ], $this->generator->calls[0]);
    }

    public function test_it_regenerates_the_token_once_the_ttl_elapses(): void
    {
        $connector = $this->connector();

        $first = $connector->exposeIamToken($this->config());
        $this->now += 600;
        $second = $connector->exposeIamToken($this->config());

        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->generator->calls);
    }

    public function test_tokens_are_cached_per_endpoint_region_and_user(): void
    {
        $connector = $this->connector();

        $connector->exposeIamToken($this->config());
        $connector->exposeIamToken($this->config(['username' => 'other_user']));
        $connector->exposeIamToken($this->config(['aws_region' => 'us-west-2']));
        $connector->exposeIamToken($this->config(['token_port' => 3307]));
        $connector->exposeIamToken($this->config(['token_host' => 'reader.internal']));
        $connector->exposeIamToken($this->config(['name' => 'another_connection']));

        $this->assertCount(5, $this->generator->calls);
    }

    public function test_temporary_credentials_shorten_the_ttl(): void
    {
        $connector = $this->connector(FakeCredentials::provider($this->now + 300));

        $first = $connector->exposeIamToken($this->config());
        $this->now += 239;
        $this->assertSame($first, $connector->exposeIamToken($this->config()));

        $this->now += 1;
        $this->assertNotSame($first, $connector->exposeIamToken($this->config()));
        $this->assertCount(2, $this->generator->calls);
    }

    public function test_credentials_about_to_expire_are_never_reused(): void
    {
        $connector = $this->connector(FakeCredentials::provider($this->now + 30));

        $connector->exposeIamToken($this->config());
        $connector->exposeIamToken($this->config());

        $this->assertCount(2, $this->generator->calls);
    }

    public function test_it_wraps_credential_failures_with_the_connection_and_endpoint(): void
    {
        $connector = $this->connector(FakeCredentials::failing('no credentials found'));

        try {
            $connector->exposeIamToken($this->config());
            $this->fail('Expected the token generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Unable to generate IAM token for "rds" (proxy.internal:3306): no credentials found',
                $exception->getMessage()
            );
        }

        $this->assertCount(0, $this->generator->calls);
    }
}
