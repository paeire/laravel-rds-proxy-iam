<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support;

use Aws\Credentials\Credentials;
use Aws\Rds\AuthTokenGenerator;

class FakeTokenGenerator extends AuthTokenGenerator
{
    /** @var array<int, array{endpoint: string, region: string, username: string, lifetime: int}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(new Credentials('test-key', 'test-secret'));
    }

    public function createToken($endpoint, $region, $username, $lifetime = 15)
    {
        $this->calls[] = compact('endpoint', 'region', 'username', 'lifetime');

        return sprintf('%s/?Action=connect&DBUser=%s&X-Amz-Signature=fake%d', $endpoint, $username, count($this->calls));
    }
}
