<?php
declare(strict_types=1);

namespace FlxCoreHC;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                config('db_host'),
                config('db_name')
            );
            self::$pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            // v1.172.11: "today" for expiry / due-date maths is the ORGANIZATION's calendar day, not the database's (UTC) day.
            // Queries use @flx_today where they used @flx_today; timestamps stay UTC.
            try { self::$pdo->exec("SET @flx_today = DATE('" . (function_exists('today_local') ? today_local() : gmdate('Y-m-d')) . "')"); }
            catch (\Throwable $e) { self::$pdo->exec("SET @flx_today = DATE(UTC_TIMESTAMP())"); }
        }
        return self::$pdo;
    }
}
