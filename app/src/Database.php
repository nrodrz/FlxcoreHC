<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
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
            $prefix = (string) config('db_prefix', '');   // v1.174.0: fchc_ on a new installation; '' on an older one (its tables have no prefix)
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = $prefix === '' ? new PDO($dsn, config('db_user'), config('db_pass'), $opts)
                                        : new PrefixedPdo($dsn, config('db_user'), config('db_pass'), $opts, $prefix);
            self::$pdo->exec("SET time_zone = '+00:00'");
            self::syncToday();
        }
        return self::$pdo;
    }

    /** v1.174.0: the table prefix of this installation ('' or e.g. 'fchc_'). */
    public static function prefix(): string
    {
        return (string) config('db_prefix', '');
    }

    /** The real name of a logical table, for the few places that put a table name in a value (information_schema, SHOW TABLES LIKE). */
    public static function table(string $logical): string
    {
        return SqlPrefixer::name($logical, self::prefix());
    }

    /** A real table name back to its logical name (drops the prefix; names that are not ours are returned as they are). */
    public static function logical(string $real): string
    {
        $p = self::prefix();
        return ($p !== '' && str_starts_with($real, $p) && isset(SqlPrefixer::tables()[substr($real, strlen($p))])) ? substr($real, strlen($p)) : $real;
    }

    /** v1.172.11: "today" for expiry / due-date maths is the ORGANIZATION's calendar day, not the database's (UTC) day;
     *  queries use @flx_today for it, timestamps stay UTC. v1.172.16: re-run whenever the app zone changes (organization chosen). */
    public static function syncToday(): void
    {
        if (self::$pdo === null) return;
        try { self::$pdo->exec("SET @flx_today = DATE('" . (function_exists('today_local') ? today_local() : gmdate('Y-m-d')) . "')"); }
        catch (\Throwable $e) { self::$pdo->exec("SET @flx_today = DATE(UTC_TIMESTAMP())"); }
    }
}
