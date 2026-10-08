<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support;

use Aws\Credentials\Credentials;
use Closure;
use GuzzleHttp\Promise\Create;

class FakeCredentials
{
    public static function provider(?int $expiresAt = null): Closure
    {
        return static fn () => Create::promiseFor(new Credentials('test-key', 'test-secret', 'test-session', $expiresAt));
    }

    public static function failing(string $message): Closure
    {
        return static fn () => Create::rejectionFor(new \RuntimeException($message));
    }
}
