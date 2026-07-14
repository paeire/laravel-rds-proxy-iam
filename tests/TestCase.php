<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Paeire\RdsProxyIam\IamServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [IamServiceProvider::class];
    }
}
