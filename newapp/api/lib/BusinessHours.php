<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Business-hours SLA calculator.
 * Setting key 'business_hours' holds JSON:
 *   { enabled, tz, days:[1..7 ISO], start:"HH:MM", end:"HH:MM", holidays:["YYYY-MM-DD", ...] }
 * itops_bh_config() returns a normalized config array when enabled, else null.
 * itops_bh_add() adds N working hours to a start timestamp, skipping
 * non-working days, hours outside the window, and holidays.
 */
if (!function_exists('itops_bh_config')) {
    function itops_bh_config(PDO $db): ?array {
        $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='business_hours'");
        $s->execute();
        $raw = $s->fetchColumn();
        if ($raw === false) return null;
        $c = json_decode((string)$raw, true);
        if (!is_array($c) || empty($c['enabled'])) return null;
        $days = (isset($c['days']) && is_array($c['days']) && $c['days']) ? array_values(array_unique(array_filter(array_map('intval', $c['days']), fn($d) => $d >= 1 && $d <= 7))) : [1, 2, 3, 4, 5];   // review DESK-25: 1..7 only
        if (!$days) $days = [1, 2, 3, 4, 5];
        $start = (isset($c['start']) && preg_match('/^\d{1,2}:\d{2}$/', $c['start'])) ? $c['start'] : '09:00';
        $end   = (isset($c['end'])   && preg_match('/^\d{1,2}:\d{2}$/', $c['end']))   ? $c['end']   : '17:00';
        $tzDef = date_default_timezone_get() ?: 'America/New_York';   // review DESK-25: the app's zone, not UTC (BT131: the installation's, applied at start)
        $tz = (isset($c['tz']) && $c['tz']) ? $c['tz'] : $tzDef;
        try { new DateTimeZone($tz); } catch (\Throwable $e) { $tz = $tzDef; }
        $holidays = (isset($c['holidays']) && is_array($c['holidays'])) ? array_flip($c['holidays']) : [];
        // a zero-length or inverted window would loop forever — guard it
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        if (($eh * 60 + $em) <= ($sh * 60 + $sm)) { $start = '09:00'; $end = '17:00'; }
        return ['days' => $days, 'start' => $start, 'end' => $end, 'tz' => $tz, 'holidays' => $holidays];
    }

}


if (!function_exists('itops_sla_hours')) {
    /**
     * [response hours, resolution hours] for a priority and a category (one rule for every channel — BT52).
     * The category's hours come first; without them, the priority's. For Critical and High, the shorter of the two is
     * used (setting "sla_urgent_shorter", on by default): a critical printer outage must not get the category's
     * relaxed 8-hour clock when the priority promises 4.
     */

}

if (!function_exists('itops_db_clock_sync')) {
    /**
     * BT52: the database clock follows PHP's. Due times are written with PHP's clock (date()), while replies, created
     * times and the SLA checks use the database's (NOW(), TIMESTAMP columns). When the database server runs on another
     * zone (UTC on many hosts), a reply sent at once was recorded hours late and could breach the response SLA. This
     * sets the connection's zone to PHP's current offset. Never throws.
     */
    function itops_db_clock_sync(PDO $db): void {
        try { $db->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
    }
}


