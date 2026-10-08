<?php

declare(strict_types=1);

namespace Paeire\RdsProxyIam\Tests\Support\Providers;

use Paeire\RdsProxyIam\Tests\Support\RecordingIamMySqlConnector;
use PDO;
use Throwable;

class ProviderOrderState
{
    public static ?RecordingIamMySqlConnector $connector = null;

    public static ?PDO $pdo = null;

    public static ?Throwable $error = null;

    public static function reset(RecordingIamMySqlConnector $connector): void
    {
        self::$connector = $connector;
        self::$pdo = null;
        self::$error = null;
    }
}
