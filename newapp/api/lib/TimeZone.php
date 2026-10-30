<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT131: the installation's own time zone (it used to be Eastern time everywhere).
 *
 * The zone is the setting `app_timezone` (an IANA name, for example America/Chicago), chosen in the installer and in
 * Settings → General (Super Admin). A `define('OPSH_TIMEZONE', '…')` in config.php still wins (for an operator who
 * needs it). Without either, America/New_York, as before.
 * Every entry point applies it before it writes or reads a time: PHP's zone (date(), strtotime()) and, through
 * opsh_db_clock_sync, the database connection's. The screens get it from /api/branding and show every time in it,
 * 12-hour.
 * Two kinds of stored times: the moments the database stamps itself (TIMESTAMP columns: created, updated) follow a
 * new zone correctly; the times the app writes as clock values (DATETIME columns: due times, schedules, many logs) keep
 * the clock value they were written with, so after a change they can look shifted by the difference. Nothing is
 * rewritten. That is why the screen asks to type CHANGE once there are records, and says to choose the zone before the
 * installation goes live.
 */
if (!function_exists('opsh_tz_valid')) {

    if (!defined('OPSH_TZ_DEFAULT')) define('OPSH_TZ_DEFAULT', 'America/New_York');

    function opsh_tz_valid(string $tz): bool {
        if ($tz === '' || strlen($tz) > 64) return false;
        return in_array($tz, DateTimeZone::listIdentifiers(), true);
    }
    /** Before the database is open: config.php's constant, else the default. */
    function opsh_tz_boot(): string {
        $tz = defined('OPSH_TIMEZONE') && is_string(OPSH_TIMEZONE) && opsh_tz_valid(OPSH_TIMEZONE) ? OPSH_TIMEZONE : OPSH_TZ_DEFAULT;
        date_default_timezone_set($tz); return $tz;
    }
    /** The installation's zone: config.php's constant, else the setting, else the default. */
    function opsh_tz_get(?PDO $db = null): string {
        static $cache = null;
        if (defined('OPSH_TIMEZONE') && is_string(OPSH_TIMEZONE) && opsh_tz_valid(OPSH_TIMEZONE)) return OPSH_TIMEZONE;
        if ($cache !== null) return $cache;
        try {
            $db = $db ?: (function_exists('getDB') ? getDB() : null);
            $v = $db ? (string)$db->query("SELECT sval FROM opsh_settings WHERE skey='app_timezone'")->fetchColumn() : '';
            $cache = opsh_tz_valid($v) ? $v : OPSH_TZ_DEFAULT;
        } catch (Throwable $e) { return OPSH_TZ_DEFAULT; }
        return $cache;
    }
    /** Applies the zone to PHP and (when given) to the database connection. Returns it. */
    function opsh_tz_apply(?PDO $db = null): string {
        $tz = opsh_tz_get($db); date_default_timezone_set($tz);
        if ($db) { try { $db->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {} }
        return $tz;
    }
    function opsh_tz_reset_cache(): void { /* the static cache lives for one request; nothing to clear across requests */ }
    /** "Eastern Time (America/New_York)" style label. */
    function opsh_tz_label(string $tz): string {
        $names = opsh_tz_common();
        return isset($names[$tz]) ? $names[$tz] . ' (' . $tz . ')' : $tz;
    }
    /** The zones offered first (the rest can be typed). */
    function opsh_tz_common(): array {
        return ['America/New_York' => 'Eastern Time', 'America/Chicago' => 'Central Time', 'America/Denver' => 'Mountain Time', 'America/Phoenix' => 'Mountain Time, Arizona (no DST)',
                'America/Los_Angeles' => 'Pacific Time', 'America/Anchorage' => 'Alaska Time', 'Pacific/Honolulu' => 'Hawaii Time', 'America/Puerto_Rico' => 'Atlantic Time, Puerto Rico (no DST)',
                'America/Halifax' => 'Atlantic Time, Canada', 'America/Toronto' => 'Eastern Time, Canada', 'America/Mexico_City' => 'Central Time, Mexico', 'America/Bogota' => 'Colombia Time',
                'America/Santo_Domingo' => 'Atlantic Time, Dominican Republic', 'America/Sao_Paulo' => 'Brasília Time', 'Europe/London' => 'United Kingdom', 'Europe/Madrid' => 'Central European Time, Spain',
                'UTC' => 'Coordinated Universal Time'];
    }
    /**
     * The Windows zone name Microsoft Graph's calendar calls expect for an IANA zone (the common ones), or the IANA
     * name itself, which Graph also accepts for most zones.
     */
    function opsh_tz_windows(string $tz): string {
        $m = ['America/New_York' => 'Eastern Standard Time', 'America/Toronto' => 'Eastern Standard Time', 'America/Chicago' => 'Central Standard Time',
              'America/Mexico_City' => 'Central Standard Time (Mexico)', 'America/Denver' => 'Mountain Standard Time', 'America/Phoenix' => 'US Mountain Standard Time',
              'America/Los_Angeles' => 'Pacific Standard Time', 'America/Anchorage' => 'Alaskan Standard Time', 'Pacific/Honolulu' => 'Hawaiian Standard Time',
              'America/Puerto_Rico' => 'SA Western Standard Time', 'America/Santo_Domingo' => 'SA Western Standard Time', 'America/Halifax' => 'Atlantic Standard Time',
              'America/Bogota' => 'SA Pacific Standard Time', 'America/Sao_Paulo' => 'E. South America Standard Time', 'Europe/London' => 'GMT Standard Time',
              'Europe/Madrid' => 'Romance Standard Time', 'UTC' => 'UTC'];
        return $m[$tz] ?? $tz;
    }
}
