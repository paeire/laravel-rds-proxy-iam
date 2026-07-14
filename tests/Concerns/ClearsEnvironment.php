<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Concerns;

/**
 * Helper to temporarily clear environment variables so tests exercising the
 * connector's config/env resolution are deterministic regardless of the host
 * environment (CI, local `.env`, etc.).
 */
trait ClearsEnvironment
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /**
     * @param  array<int, string>  $keys
     */
    protected function clearEnv(array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $this->originalEnv)) {
                $this->originalEnv[$key] = getenv($key);
            }

            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function restoreEnv(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }

        $this->originalEnv = [];
    }
}
