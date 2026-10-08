<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support\Providers;

use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Mimics providers that hit the database from register(), e.g. a secrets loader backed by
 * the `database` cache store.
 */
class OpensConnectionOnRegisterProvider extends ServiceProvider
{
    public function register(): void
    {
        try {
            ProviderOrderState::$pdo = $this->app['db']->connection('rds')->getPdo();
        } catch (Throwable $exception) {
            ProviderOrderState::$error = $exception;
        }
    }
}
