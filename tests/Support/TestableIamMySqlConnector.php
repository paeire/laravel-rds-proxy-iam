<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support;

use Paeire\RdsProxyIam\IamMySqlConnector;

/**
 * Test-only subclass that exposes the connector's protected configuration
 * helpers so their pure logic can be asserted without opening a real
 * connection or generating an AWS IAM token.
 */
class TestableIamMySqlConnector extends IamMySqlConnector
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function exposeNormalizeConfig(array $config): array
    {
        return $this->normalizeConfig($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    public function exposeBuildOptions(array $config): array
    {
        return $this->buildOptions($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public function exposeSessionInitStatements(array $config): array
    {
        return $this->getSessionInitStatements($config);
    }
}
