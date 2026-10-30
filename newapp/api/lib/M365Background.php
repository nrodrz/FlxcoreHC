<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT54: Microsoft 365 data kept fresh in the background.
 *
 * Every Microsoft 365 page keeps what it read from Microsoft in a small file cache on the server (api/routes/m365hub.php,
 * 5–10 minutes). When that copy is old, the next person who opens the page waits for Microsoft. This file lets the
 * scheduled job (cron-sla.php, every 15 minutes; the in-app scheduler calls the same file) read the chosen pages ahead
 * of time and put them in that same cache, so pages open at once. While the background refresh is on and working, a page
 * serves its copy until the next refresh is due (plus a margin), instead of 5–10 minutes.
 *
 * Settings (opsh_settings):
 *   m365_bg        {"enabled":true,"every":15,"off":60,"sets":["accounts",…]}   — every / off = minutes in / outside
 *                  business hours (Settings → SLA & Hours; when those are off: Monday–Friday 7:00–19:00); off 0 = not
 *                  outside business hours.
 *   m365_bg_state  {"started","finished","ok","by","duration","items":{key:{at,ok,error,ms}}}
 *   m365_bg_lock   unix time of the run in progress (a run older than 10 minutes is considered gone)
 * Nothing here changes anything in Microsoft: reads only. Changes made in OpsHub still drop the copies they affect at once.
 */
require_once __DIR__ . '/M365Hub.php';
if (is_file(__DIR__ . '/EventLog.php')) require_once __DIR__ . '/EventLog.php';
if (is_file(__DIR__ . '/BusinessHours.php')) require_once __DIR__ . '/BusinessHours.php';

if (!function_exists('opsh_m365_cache_dir')) {

    /** The folder the Microsoft 365 pages cache in (the same folder api/routes/m365hub.php uses). */
    function opsh_m365_cache_dir(): string {
        require_once __DIR__ . '/SafeCache.php';   // BT124 (review M365A-1)
        return opsh_cache_dir('m365');
    }
    function opsh_m365_cache_file(string $k): string { return opsh_m365_cache_dir() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $k) . '.json'; }
    function opsh_m365_cache_put(string $k, $payload): int {
        $d = opsh_m365_cache_dir(); if (!is_dir($d)) @mkdir($d, 0700, true);
        $ts = time(); $f = opsh_m365_cache_file($k);
        @file_put_contents($f, json_encode(['ts' => $ts, 'payload' => $payload]), LOCK_EX); @chmod($f, 0600);
        return $ts;
    }
    function opsh_m365_cache_drop(string $k): void { @unlink(opsh_m365_cache_file($k)); }   // (the background's own drops do not stamp: they follow its own fresh read)

    function opsh_m365_auth_err(string $m): bool {
        return stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403')
            || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false;
    }

    /** What can be kept fresh: id => [label, what it covers, cache keys, on by default]. */
    function opsh_m365_bg_sets(): array {
        return [
            'accounts' => ['Accounts', 'The Accounts list and the directory behind Reconcile', ['m365-grid', 'm365-directory'], true],
            'licenses' => ['Licenses', 'License report (30 days idle)', ['license-report-30'], true],
            'devices'  => ['Devices', 'Intune devices', ['m365-intune-devices'], true],
            'security' => ['Security', 'Defender, MFA registration, app secrets', ['m365-defender', 'm365-mfa', 'm365-app-secrets'], true],
            'health'   => ['Service health', 'Service health and message center', ['m365-service-health'], true],
            'groups'   => ['Groups', 'Groups overview', ['m365-groups-overview'], true],
            'guests'   => ['Guests', 'Guest accounts', ['m365-guests'], true],
            'apps'     => ['Apps', 'Enterprise apps and Entra recommendations', ['m365-enterprise-apps', 'm365-recommendations'], false],
            'intune'   => ['Intune apps & configuration', 'Apps, software found on devices, configuration profiles and update rings', ['intune-apps', 'intune-software', 'intune-config'], false],
            'usage'    => ['Usage reports', 'Mailboxes, Teams, storage, Copilot (Microsoft updates these once a day)', ['m365-exchange', 'm365-teams', 'm365-storage', 'm365-copilot'], false],
        ];
    }

    function opsh_m365_bg_get(PDO $db, string $k): array {
        try { $st = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $st->execute([$k]); $j = json_decode((string)$st->fetchColumn(), true); return is_array($j) ? $j : []; }
        catch (Throwable $e) { return []; }
    }
    function opsh_m365_bg_set(PDO $db, string $k, array $v): void {
        try { $db->prepare('INSERT INTO opsh_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')->execute([$k, json_encode($v)]); } catch (Throwable $e) {}
    }

    /** The configuration, with defaults (on; 15 minutes in business hours, 60 outside; the sets on by default). */
    function opsh_m365_bg_config(PDO $db): array {
        $j = opsh_m365_bg_get($db, 'm365_bg'); $defs = opsh_m365_bg_sets();
        $sets = isset($j['sets']) && is_array($j['sets']) ? array_values(array_intersect(array_keys($defs), $j['sets'])) : array_keys(array_filter($defs, fn($d) => $d[3]));
        $every = (int)($j['every'] ?? 15); if (!in_array($every, [10, 15, 30, 60], true)) $every = 15;
        $off = array_key_exists('off', $j) ? (int)$j['off'] : 60; if (!in_array($off, [0, 30, 60, 120, 240], true)) $off = 60;
        return ['enabled' => array_key_exists('enabled', $j) ? (bool)$j['enabled'] : true, 'every' => $every, 'off' => $off, 'sets' => $sets];
    }

    /** True inside business hours (Settings → SLA & Hours when on; else Monday–Friday 7:00–19:00, the app's time zone). */
    function opsh_m365_bg_business(PDO $db, int $ts): bool {
        $bh = function_exists('opsh_bh_config') ? opsh_bh_config($db) : null;
        if ($bh) {
            $t = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone($bh['tz']));
            if (!in_array((int)$t->format('N'), $bh['days'], true) || isset($bh['holidays'][$t->format('Y-m-d')])) return false;
            $m = fn($x) => (int)explode(':', $x)[0] * 60 + (int)explode(':', $x)[1];
            $now = (int)$t->format('G') * 60 + (int)$t->format('i'); return $now >= $m($bh['start']) && $now < $m($bh['end']);
        }
        $n = (int)date('N', $ts); $hm = date('H:i', $ts);
        return $n <= 5 && $hm >= '07:00' && $hm < '19:00';
    }

    /** Minutes between refreshes right now (0 = no refresh now: outside business hours with "off" 0). */
    function opsh_m365_bg_interval(PDO $db, ?int $ts = null): int {
        $c = opsh_m365_bg_config($db); $ts = $ts ?? time();
        return opsh_m365_bg_business($db, $ts) ? $c['every'] : $c['off'];
    }

    /** The keys of the chosen sets. */
    function opsh_m365_bg_keys(array $cfg): array {
        $defs = opsh_m365_bg_sets(); $k = [];
        foreach ($cfg['sets'] as $s) if (isset($defs[$s])) foreach ($defs[$s][2] as $x) $k[] = $x;
        return $k;
    }

    /**
     * How long a page may serve its copy of $key: its own time, or — while the background refresh is on, covers that key
     * and last finished without failing that key — until the next refresh is due plus 15 minutes. Read once per request.
     */
    function opsh_m365_bg_ttl(string $key, int $ttl): int {
        static $memo = null;
        if ($memo === null) {
            $memo = ['keys' => [], 'ttl' => 0];
            try {
                $db = getDB(); $cfg = opsh_m365_bg_config($db);
                if ($cfg['enabled']) {
                    $st = opsh_m365_bg_get($db, 'm365_bg_state');
                    $iv = opsh_m365_bg_interval($db); if ($iv <= 0) $iv = 240;   // no refresh until business hours: keep the last one
                    $okKeys = [];
                    foreach (opsh_m365_bg_keys($cfg) as $k) if (!empty($st['items'][$k]['ok'])) $okKeys[$k] = true;
                    $memo = ['keys' => $okKeys, 'ttl' => $iv * 60 + 900];
                }
            } catch (Throwable $e) {}
        }
        return isset($memo['keys'][$key]) ? max($ttl, $memo['ttl']) : $ttl;
    }

    /** When the next run is due (unix time), or null when the refresh is off. */
    function opsh_m365_bg_next(PDO $db): ?int {
        $cfg = opsh_m365_bg_config($db); if (!$cfg['enabled'] || !$cfg['sets']) return null;
        $st = opsh_m365_bg_get($db, 'm365_bg_state'); $last = (int)($st['finished'] ?? 0); $now = time();
        if (!$last) return $now;
        // Review M365A-21: the settings and business hours are read once, not once per five-minute step
        $bh = function_exists('opsh_bh_config') ? opsh_bh_config($db) : null;
        $isBiz = function (int $ts) use ($bh, $db): bool {
            if (!$bh) { $nn = (int)date('N', $ts); $hm = date('H:i', $ts); return $nn <= 5 && $hm >= '07:00' && $hm < '19:00'; }
            $t = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone($bh['tz']));
            if (!in_array((int)$t->format('N'), $bh['days'], true) || isset($bh['holidays'][$t->format('Y-m-d')])) return false;
            $m = fn($x) => (int)explode(':', $x)[0] * 60 + (int)explode(':', $x)[1];
            $n = (int)$t->format('G') * 60 + (int)$t->format('i'); return $n >= $m($bh['start']) && $n < $m($bh['end']);
        };
        for ($t = $last + 60; $t < $now + 7 * 86400; $t += 300) {   // the first moment the interval of that moment has passed
            $iv = $isBiz($t) ? $cfg['every'] : $cfg['off'];
            if ($iv > 0 && $t - $last >= $iv * 60) return max($t, $now);
        }
        return null;
    }

    /** Is a run in progress? (a lock older than 10 minutes is considered gone) */
    function opsh_m365_bg_running(PDO $db): bool {
        try { $st = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='m365_bg_lock'"); $st->execute(); return time() - (int)$st->fetchColumn() < 600; }
        catch (Throwable $e) { return false; }
    }

    /** Called by the scheduled job: runs when the refresh is on and due. */
    function opsh_m365_bg_tick(PDO $db): string {
        $cfg = opsh_m365_bg_config($db);
        if (!$cfg['enabled'] || !$cfg['sets']) return 'off';
        $next = opsh_m365_bg_next($db);
        if ($next === null || $next > time() + 60) return 'not due';
        $r = opsh_m365_bg_run($db, 'schedule');
        return $r['ran'] ? (($r['ok'] ? 'ok' : 'with errors') . ' (' . $r['count'] . ' reads, ' . $r['duration'] . ' s)') : $r['reason'];
    }

    /** One read for one cache key: the payload to keep, or one carrying '_nocache' (an error to show, nothing kept). */
    function opsh_m365_build(string $key): array {
        switch ($key) {
            case 'm365-grid':
                $svc = OpshM365::fromConfig(); $skuMap = []; try { $skuMap = $svc->skus()['map'] ?? []; } catch (Throwable $e) {}
                return $svc->gridUsers($skuMap);
            case 'm365-directory':       return OpshM365::fromConfig()->directoryForRecon();
            case 'license-report-30':    return OpshM365::fromConfig()->licenseReport(30);
            case 'm365-intune-devices':  return opsh_m365_build_intune_devices();
            case 'm365-defender':        return opsh_m365_build_defender();
            case 'm365-mfa':             return opsh_m365_build_mfa();
            case 'm365-service-health':  return opsh_m365_build_service_health();
            case 'm365-exchange':        return opsh_m365_build_exchange();
            case 'm365-teams':           return opsh_m365_build_teams();
            case 'm365-storage':         return opsh_m365_build_storage();
            case 'm365-copilot':         return opsh_m365_build_copilot();
            case 'm365-recommendations': return opsh_m365_build_recommendations();
            case 'm365-app-secrets':     return opsh_m365_build_app_secrets();
            case 'm365-groups-overview': return opsh_m365_build_groups_overview();
            case 'm365-guests':          return opsh_m365_build_guests();
            case 'm365-enterprise-apps': return opsh_m365_build_enterprise_apps();
            case 'intune-apps':          return opsh_m365_build_intune('apps');       // BT114
            case 'intune-software':      return opsh_m365_build_intune('software');
            case 'intune-config':        return opsh_m365_build_intune('config');
        }
        throw new InvalidArgumentException('Unknown Microsoft 365 read: ' . $key);
    }

    /**
     * Read the chosen sets now and keep them. $by: 'schedule' or the name of the person who asked. Never two at once.
     * Returns ['ran'=>bool, 'reason'=>…, 'ok'=>bool, 'count'=>n, 'failed'=>n, 'duration'=>s].
     */
    function opsh_m365_bg_run(PDO $db, string $by = 'schedule'): array {
        $cfg = opsh_m365_bg_config($db); $keys = opsh_m365_bg_keys($cfg);
        if (!$keys) return ['ran' => false, 'reason' => 'nothing chosen'];
        $now = time();
        try {
            $db->prepare("INSERT IGNORE INTO opsh_settings (skey, sval) VALUES ('m365_bg_lock', '0')")->execute();
            $cl = $db->prepare("UPDATE opsh_settings SET sval=? WHERE skey='m365_bg_lock' AND CAST(sval AS UNSIGNED) < ?");
            $cl->execute([(string)$now, $now - 600]);
            if ($cl->rowCount() !== 1) return ['ran' => false, 'reason' => 'already running'];
        } catch (Throwable $e) { return ['ran' => false, 'reason' => 'lock: ' . $e->getMessage()]; }
        @set_time_limit(300);
        try { $db->exec('SET SESSION wait_timeout=1800'); } catch (Throwable $e) {}
        $prev = opsh_m365_bg_get($db, 'm365_bg_state');
        $items = is_array($prev['items'] ?? null) ? $prev['items'] : [];
        $state = ['started' => $now, 'finished' => null, 'ok' => null, 'by' => $by, 'items' => $items];
        opsh_m365_bg_set($db, 'm365_bg_state', $state + ['running' => true]);
        $failed = 0; $done = 0; $fatal = null;
        try { OpshM365::fromConfig(); }
        catch (Throwable $e) { $fatal = 'Microsoft 365 is not connected: ' . $e->getMessage(); }
        foreach ($keys as $k) {
            $t0 = microtime(true);
            if ($fatal) { $items[$k] = ['at' => time(), 'ok' => false, 'error' => $fatal, 'ms' => 0]; $failed++; continue; }
            try {
                $readStart = time(); clearstatcache();
                $p = opsh_m365_build($k);
                if (!empty($p['_nocache'])) { $items[$k] = ['at' => time(), 'ok' => false, 'error' => (string)($p['error'] ?? 'Microsoft refused the read'), 'ms' => (int)((microtime(true) - $t0) * 1000)]; $failed++; continue; }
                // BT124 (code review M365A-2): something was changed in the app while this was read from Microsoft: the copy may be
                // older than the change, so it is not kept (the page reads it again)
                clearstatcache(); $st = @filemtime(opsh_m365_cache_dir() . '/_dropped.stamp');
                if ($st !== false && $st >= $readStart) { $items[$k] = ['at' => time(), 'ok' => true, 'error' => null, 'ms' => (int)((microtime(true) - $t0) * 1000), 'note' => 'not kept: changed in the app during the read']; $done++; continue; }
                opsh_m365_cache_put($k, $p);
                if ($k === 'm365-directory') opsh_m365_cache_drop('recon-computed');   // worked out from the directory: again from the new copy
                $items[$k] = ['at' => time(), 'ok' => true, 'error' => null, 'ms' => (int)((microtime(true) - $t0) * 1000)]; $done++;
            } catch (Throwable $e) {
                $items[$k] = ['at' => time(), 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'ms' => (int)((microtime(true) - $t0) * 1000)]; $failed++;
            }
        }
        foreach (array_keys($items) as $k) if (!in_array($k, $keys, true)) unset($items[$k]);   // sets no longer chosen
        $dur = time() - $now;
        $state = ['started' => $now, 'finished' => time(), 'ok' => $failed === 0, 'by' => $by, 'duration' => $dur, 'items' => $items];
        opsh_m365_bg_set($db, 'm365_bg_state', $state);
        try { $db->prepare("UPDATE opsh_settings SET sval='0' WHERE skey='m365_bg_lock'")->execute(); } catch (Throwable $e) {}
        if (function_exists('opsh_log')) {
            $msg = 'Microsoft 365 background refresh: ' . $done . ' read' . ($done === 1 ? '' : 's') . ' kept' . ($failed ? ', ' . $failed . ' failed' : '') . ' in ' . $dur . ' s (' . ($by === 'schedule' ? 'scheduled' : 'asked by ' . $by) . ').';
            try { opsh_log($failed ? 'warning' : 'info', 'm365', $msg, $failed ? ['failed' => array_keys(array_filter($items, fn($i) => !$i['ok']))] : []); } catch (Throwable $e) {}
        }
        return ['ran' => true, 'ok' => $failed === 0, 'count' => $done + $failed, 'failed' => $failed, 'duration' => $dur];
    }

    /** BT114: intune-apps / intune-software / intune-config (lib/IntuneOps.php) */
    function opsh_m365_build_intune(string $what): array {
        require_once __DIR__ . '/IntuneOps.php';
        $perm = ['apps' => 'DeviceManagementApps.Read.All', 'software' => 'DeviceManagementManagedDevices.Read.All', 'config' => 'DeviceManagementConfiguration.Read.All'][$what];
        try {
            OpshM365::fromConfig();
            if ($what === 'apps') { $a = opsh_io_apps(); return ['apps' => $a, 'total' => count($a), 'cached' => false]; }
            if ($what === 'software') { $a = opsh_io_software(); return ['software' => $a, 'total' => count($a), 'cached' => false]; }
            return opsh_io_config() + ['cached' => false];
        } catch (Throwable $e) {
            $m = $e->getMessage();
            return ['error' => $m, 'needs_permission' => opsh_m365_auth_err($m) ? $perm : null, '_nocache' => true];
        }
    }

    /** m365-intune-devices */
    function opsh_m365_build_intune_devices(): array {
        try {
            $devices = OpshM365::fromConfig()->managedDevices();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $needs = (stripos($msg, 'authoriz') !== false || stripos($msg, 'forbidden') !== false
                || str_contains($msg, '403') || stripos($msg, 'permission') !== false || stripos($msg, 'privilege') !== false);
            return [
                'error' => $msg,
                'needs_permission' => $needs ? 'DeviceManagementManagedDevices.Read.All' : null,
                'devices' => [], 'summary' => [], 'total' => 0,
            ] + ['_nocache' => true];
        }
        $summary = [];
        foreach ($devices as $d) { $k = $d['compliance'] !== '' ? $d['compliance'] : 'unknown'; $summary[$k] = ($summary[$k] ?? 0) + 1; }
        $payload = ['devices' => $devices, 'summary' => $summary, 'total' => count($devices), 'cached' => false];
        return $payload;
    }

    /** m365-defender */
    function opsh_m365_build_defender(): array {
        $svc = OpshM365::fromConfig();
        $isPerm = fn($m) => stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false
            || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false;
        $needs = [];
        $score = null;
        try { $score = $svc->secureScore(); }
        catch (Throwable $e) { if ($isPerm($e->getMessage())) $needs['secure_score'] = 'SecurityEvents.Read.All'; }
        $alerts = [];
        try { $alerts = $svc->securityAlerts(); }
        catch (Throwable $e) { if ($isPerm($e->getMessage())) $needs['alerts'] = 'SecurityAlert.Read.All'; }
        $summary = [];
        foreach ($alerts as $a) { $k = $a['severity'] !== '' ? $a['severity'] : 'unknown'; $summary[$k] = ($summary[$k] ?? 0) + 1; }
        $payload = ['secure_score' => $score, 'alerts' => $alerts, 'summary' => $summary, 'total' => count($alerts), 'needs_permission' => $needs ?: null, 'cached' => false];
        return $payload;
    }

    /** m365-mfa */
    function opsh_m365_build_mfa(): array {
        try {
            $users = OpshM365::fromConfig()->mfaRegistration();
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $needs = (stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false
                || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false);
            return ['error' => $m, 'needs_permission' => $needs ? 'AuditLog.Read.All' : null, 'users' => [], 'summary' => []] + ['_nocache' => true];
        }
        $reg = 0; $cap = 0; $adminNo = 0;
        foreach ($users as $u) { if ($u['mfa_registered']) $reg++; if ($u['mfa_capable']) $cap++; if ($u['is_admin'] && !$u['mfa_registered']) $adminNo++; }
        $total = count($users);
        $payload = ['users' => $users, 'summary' => ['total' => $total, 'registered' => $reg, 'not_registered' => $total - $reg, 'capable' => $cap, 'admins_no_mfa' => $adminNo], 'cached' => false];
        return $payload;
    }

    /** m365-service-health */
    function opsh_m365_build_service_health(): array {
        $svc = OpshM365::fromConfig();
        $isPerm = fn($m) => stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false
            || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false;
        $needs = []; $errors = [];
        $health = ['overviews' => [], 'issues' => []];
        try { $health = $svc->serviceHealth(); }
        catch (Throwable $e) { $isPerm($e->getMessage()) ? $needs['health'] = 'ServiceHealth.Read.All' : $errors['health'] = $e->getMessage(); }
        $messages = [];
        try { $messages = $svc->messageCenter(); }
        catch (Throwable $e) { $isPerm($e->getMessage()) ? $needs['messages'] = 'ServiceMessage.Read.All' : $errors['messages'] = $e->getMessage(); }
        $degraded = 0;
        foreach ($health['overviews'] as $o) { if ($o['status'] !== '' && $o['status'] !== 'serviceOperational') $degraded++; }
        $active = 0;
        foreach ($health['issues'] as $i) { if (!$i['resolved']) $active++; }
        $actions = 0;
        foreach ($messages as $m) { if ($m['major_change'] || $m['act_by']) $actions++; }
        $payload = [
            'overviews' => $health['overviews'], 'issues' => $health['issues'], 'messages' => $messages,
            'summary' => ['services_degraded' => $degraded, 'services_total' => count($health['overviews']), 'active_issues' => $active, 'action_messages' => $actions],
            'needs_permission' => $needs ?: null, 'errors' => $errors ?: null, 'cached' => false,
        ];
        return $payload;
    }

    /** m365-exchange */
    function opsh_m365_build_exchange(): array {
        try {
            $rows = OpshM365::fromConfig()->mailboxUsage();
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $needs = (stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false);
            return ['error' => $m, 'needs_permission' => $needs ? 'Reports.Read.All' : null, 'mailboxes' => [], 'summary' => []] + ['_nocache' => true];
        }
        $cut = gmdate('Y-m-d', strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC') - 30 * 86400);   // BT62: Microsoft dates activity in UTC
        $totalUsed = 0; $near = 0; $inactive = 0;
        foreach ($rows as $r) {
            $totalUsed += $r['used_bytes'];
            if ($r['pct'] !== null && $r['pct'] >= 90) $near++;
            if (empty($r['last_activity']) || $r['last_activity'] < $cut) $inactive++;
        }
        $payload = ['mailboxes' => $rows, 'summary' => ['count' => count($rows), 'used_bytes' => $totalUsed, 'near_quota' => $near, 'inactive' => $inactive], 'cached' => false];
        return $payload;
    }

    /** m365-teams */
    function opsh_m365_build_teams(): array {
        try {
            $rows = OpshM365::fromConfig()->teamsActivity();
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $needs = (stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false);
            return ['error' => $m, 'needs_permission' => $needs ? 'Reports.Read.All' : null, 'users' => [], 'summary' => []] + ['_nocache' => true];
        }
        $cut = gmdate('Y-m-d', strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC') - 30 * 86400);   // BT62: Microsoft dates activity in UTC
        $active = 0; $msgs = 0; $meetings = 0;
        foreach ($rows as $r) {
            if (!empty($r['last_activity']) && $r['last_activity'] >= $cut) $active++;
            $msgs += $r['team_chat'] + $r['private_chat'];
            $meetings += $r['meetings'];
        }
        $payload = ['users' => $rows, 'summary' => ['count' => count($rows), 'active' => $active, 'inactive' => count($rows) - $active, 'messages' => $msgs, 'meetings' => $meetings], 'cached' => false];
        return $payload;
    }

    /** m365-storage */
    function opsh_m365_build_storage(): array {
        $svc = OpshM365::fromConfig();
        $isPerm = fn($m) => stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false;
        $needs = null; $errors = [];
        $onedrive = []; $sharepoint = [];
        try { $onedrive = $svc->oneDriveUsage(); }
        catch (Throwable $e) { $isPerm($e->getMessage()) ? $needs = 'Reports.Read.All' : $errors['onedrive'] = $e->getMessage(); }
        try { $sharepoint = $svc->sharePointUsage(); }
        catch (Throwable $e) { $isPerm($e->getMessage()) ? $needs = 'Reports.Read.All' : $errors['sharepoint'] = $e->getMessage(); }
        $sum = function ($rows) {
            $used = 0; $near = 0;
            foreach ($rows as $r) { $used += $r['used_bytes']; if ($r['pct'] !== null && $r['pct'] >= 90) $near++; }
            return ['count' => count($rows), 'used_bytes' => $used, 'near' => $near];
        };
        $payload = [
            'onedrive' => $onedrive, 'sharepoint' => $sharepoint,
            'summary' => ['onedrive' => $sum($onedrive), 'sharepoint' => $sum($sharepoint), 'used_bytes' => $sum($onedrive)['used_bytes'] + $sum($sharepoint)['used_bytes']],
            'needs_permission' => $needs, 'errors' => $errors ?: null, 'cached' => false,
        ];
        return $payload;
    }

    /** m365-copilot */
    function opsh_m365_build_copilot(): array {
        $svc = null;
        try {
            $svc = OpshM365::fromConfig(); $rows = $svc->copilotUsage();
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $needs = (stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false);
            return ['error' => $m, 'needs_permission' => $needs ? 'Reports.Read.All' : null, 'users' => [], 'summary' => [], 'app_adoption' => []] + ['_nocache' => true];
        }
        $period = $svc->copilotPeriod; $days = OpshM365::periodDays($period);   // BT55: Microsoft may answer for 28 days (RL28), not 30
        $cut = gmdate('Y-m-d', strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC') - $days * 86400);   // BT62: UTC, as Microsoft's report dates
        $active = 0; $adoption = [];
        foreach ($rows as $r) {
            if (!empty($r['last_activity']) && $r['last_activity'] >= $cut) $active++;
            foreach ($r['apps_used'] as $a) { $adoption[$a] = ($adoption[$a] ?? 0) + 1; }
        }
        arsort($adoption);
        $trend = ['series' => [], 'per_app' => [], 'latest' => null];
        try { $trend = $svc->copilotTrend('D30'); } catch (Throwable $e) { /* trend is a bonus; detail already succeeded */ }
        $payload = ['period' => $period, 'days' => $days, 'users' => $rows, 'summary' => ['licensed' => count($rows), 'active' => $active, 'inactive' => count($rows) - $active], 'app_adoption' => $adoption, 'trend' => $trend, 'cached' => false];
        return $payload;
    }

    /** m365-recommendations */
    function opsh_m365_build_recommendations(): array {
        try {
            $rows = OpshM365::fromConfig()->directoryRecommendations();
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $needs = (stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false);
            return ['error' => $m, 'needs_permission' => $needs ? 'DirectoryRecommendations.Read.All' : null, 'recommendations' => [], 'summary' => []] + ['_nocache' => true];
        }
        $active = 0; $high = 0; $med = 0; $low = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'active') {
                $active++;
                if ($r['priority'] === 'high') $high++; elseif ($r['priority'] === 'medium') $med++; elseif ($r['priority'] === 'low') $low++;
            }
        }
        $payload = ['recommendations' => $rows, 'summary' => ['total' => count($rows), 'active' => $active, 'high' => $high, 'medium' => $med, 'low' => $low], 'cached' => false];
        return $payload;
    }

    /** m365-app-secrets */
    function opsh_m365_build_app_secrets(): array {
        try {
            $apps = OpshM365::fromConfig()->appCredentials();
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $needs = (stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403') || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false);
            return ['error' => $m, 'needs_permission' => $needs ? 'Application.Read.All' : null, 'apps' => [], 'summary' => []] + ['_nocache' => true];
        }
        $withExpired = 0; $withSoon = 0; $noCreds = 0;
        foreach ($apps as $a) {
            if ($a['expired'] > 0) $withExpired++;
            if ($a['expiring_soon'] > 0) $withSoon++;
            if ($a['cred_count'] === 0) $noCreds++;
        }
        $payload = ['apps' => $apps, 'summary' => ['total' => count($apps), 'expired' => $withExpired, 'expiring_soon' => $withSoon, 'no_creds' => $noCreds], 'cached' => false];
        return $payload;
    }

    /** m365-groups-overview */
    function opsh_m365_build_groups_overview(): array {
        try { $r = OpshM365::fromConfig()->groupsOverview(); }
        catch (Throwable $e) {
            $m = $e->getMessage();
            return ['error' => $m, 'needs_permission' => opsh_m365_auth_err($m) ? 'Group.Read.All' : null, 'groups' => [], 'summary' => []] + ['_nocache' => true];
        }
        $sum = ['total' => 0, 'm365' => 0, 'security' => 0, 'mail' => 0, 'dynamic' => 0, 'synced' => 0, 'no_owner' => 0, 'empty' => 0];
        foreach ($r['groups'] as &$g) {
            $sum['total']++;
            if ($g['type'] === 'm365') $sum['m365']++; elseif ($g['type'] === 'security') $sum['security']++; else $sum['mail']++;
            if ($g['dynamic']) $sum['dynamic']++;
            if ($g['synced']) $sum['synced']++;
            $g['owner_count'] = $g['owners'] === null ? null : count($g['owners']);
            $flags = [];
            // Owners matter where people manage the group themselves (Microsoft 365 groups and Teams); synced groups are owned on-premises.
            if ($g['owner_count'] === 0 && !$g['synced'] && $g['type'] === 'm365') { $flags[] = 'no_owner'; $sum['no_owner']++; }
            if ($g['members'] === 0 && !$g['dynamic']) { $flags[] = 'empty'; $sum['empty']++; }
            $g['flags'] = $flags;
        }
        unset($g);
        $payload = ['groups' => $r['groups'], 'summary' => $sum, 'counts_ok' => $r['counts_ok'], 'cached' => false];
        return $payload;
    }

    /** m365-guests */
    function opsh_m365_build_guests(): array {
        try { $r = OpshM365::fromConfig()->guests(); }
        catch (Throwable $e) {
            $m = $e->getMessage();
            return ['error' => $m, 'needs_permission' => opsh_m365_auth_err($m) ? 'User.Read.All' : null, 'guests' => [], 'summary' => []] + ['_nocache' => true];
        }
        $now = time(); $d30 = $now - 30 * 86400; $d90 = $now - 90 * 86400;
        $sum = ['total' => 0, 'pending' => 0, 'pending_old' => 0, 'stale' => 0, 'disabled' => 0, 'domains' => []];
        foreach ($r['guests'] as &$g) {
            $created = $g['created'] ? (int)strtotime((string)$g['created']) : 0;
            $last = $g['last_sign_in'] ? (int)strtotime((string)$g['last_sign_in']) : 0;
            $flags = [];
            if ($g['state'] === 'PendingAcceptance') { $flags[] = 'pending'; $sum['pending']++; if ($created && $created < $d30) { $flags[] = 'pending_old'; $sum['pending_old']++; } }
            // "Not used" needs the sign-in dates. Without them (no Entra ID P1) nobody is flagged: unknown is not "never".
            elseif ($r['has_sign_in'] && $g['enabled'] && (($last && $last < $d90) || (!$last && $created && $created < $d90))) { $flags[] = 'stale'; $sum['stale']++; }
            if (!$g['enabled']) { $flags[] = 'disabled'; $sum['disabled']++; }
            $g['flags'] = $flags;
            $sum['total']++;
            if ($g['domain'] !== '') $sum['domains'][$g['domain']] = ($sum['domains'][$g['domain']] ?? 0) + 1;
        }
        unset($g);
        arsort($sum['domains']);
        $dom = []; foreach (array_slice($sum['domains'], 0, 12, true) as $k => $n) $dom[] = ['domain' => $k, 'count' => $n];
        $sum['domain_count'] = count($sum['domains']); $sum['domains'] = $dom;
        $payload = ['guests' => $r['guests'], 'summary' => $sum, 'has_sign_in' => $r['has_sign_in'], 'cached' => false];
        return $payload;
    }

    /** m365-enterprise-apps */
    function opsh_m365_build_enterprise_apps(): array {
        try { $r = OpshM365::fromConfig()->enterpriseApps(); }
        catch (Throwable $e) {
            $m = $e->getMessage();
            return ['error' => $m, 'needs_permission' => opsh_m365_auth_err($m) ? 'Application.Read.All' : null, 'apps' => [], 'summary' => []] + ['_nocache' => true];
        }
        $sum = ['total' => count($r['apps']), 'third_party' => 0, 'high' => 0, 'user_consent' => 0, 'unverified' => 0, 'disabled' => 0];
        foreach ($r['apps'] as $a) {
            if ($a['kind'] === 'third_party') $sum['third_party']++;
            if (in_array('high', $a['flags'], true)) $sum['high']++;
            if (in_array('user_consent', $a['flags'], true) || in_array('user_consent_high', $a['flags'], true)) $sum['user_consent']++;
            if (in_array('unverified', $a['flags'], true)) $sum['unverified']++;
            if (in_array('disabled', $a['flags'], true)) $sum['disabled']++;
        }
        $payload = ['apps' => $r['apps'], 'summary' => $sum, 'needs' => $r['needs'] ?: null, 'tenant_known' => $r['tenant_known'], 'cached' => false];
        return $payload;
    }

}
