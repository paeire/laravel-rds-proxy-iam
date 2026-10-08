<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support\Providers;

use Illuminate\Support\ServiceProvider;

class ResolvesDatabaseOnRegisterProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make('db');
    }
}
