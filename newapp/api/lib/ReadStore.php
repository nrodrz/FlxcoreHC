<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT72: Microsoft 365 reads kept on the server and served at once.
 *
 * Before BT72 only some Microsoft 365 pages kept a copy of what they read (api/routes/m365hub.php, 5–10 minutes, longer
 * while the background refresh covers them). The pages under /api/m365 (Entra ID → Access & sign-in, Security → Alerts,
 * Device policies, Email security, Rollout…) and several lists (licenses, domains, groups, roles) asked Microsoft every
 * time, and an old copy was thrown away: the person waited for Microsoft whenever a copy was older than its time.
 *
 * Now:
 *  - every read named here keeps its answer on the server (the same folder as the other copies, files "rs_<hash>.json");
 *  - a copy younger than its time (10 minutes) is served at once ("X-ITOps-Cache: fresh");
 *  - an older copy, up to 24 hours, is ALSO served at once, marked "X-ITOps-Cache: stale": the page shows it and asks the
 *    server to read Microsoft again in the background (a "warm" request), so the next look is current;
 *  - with no copy, the read is made, kept and answered ("X-ITOps-Cache: live");
 *  - a "warm" request (header X-ITOps-Warm: 1) only fills the store: with a fresh copy it answers at once without asking
 *    Microsoft; when another request is already reading the same thing (lock under 2 minutes) it answers "busy".
 *    The app sends warm requests for everything the person's role may see as soon as they sign in, then every 15 minutes
 *    while it is open (index.html, itopsM365Warm), two at a time;
 *  - ?refresh=1 always reads Microsoft;
 *  - any change made through the Microsoft 365 routes (a POST/PUT/DELETE answered without error) drops every copy kept
 *    here, so a change is never hidden behind an old copy.
 * Only answers with status 200 and no "error" at the top are kept. Copies hold what the page shows, nothing more; the
 * permission of the route is checked before any copy is served, exactly as before.
 *
 * How a read is captured without changing its code: the route's own code ends with json_response() (echo + exit). An
 * output buffer is opened before it runs; a shutdown function (which PHP runs before it sends the buffers) reads the
 * answer and keeps it.
 */
if (!function_exists('itops_rs_begin')) {
    if (is_file(__DIR__ . '/M365Background.php')) require_once __DIR__ . '/M365Background.php';

    function itops_rs_dir(): string {
        return function_exists('itops_m365_cache_dir') ? itops_m365_cache_dir() : rtrim(sys_get_temp_dir(), '/') . '/itops_m365_rs';
    }
    if (!defined('ITOPS_RS_FRESH')) define('ITOPS_RS_FRESH', 600);   // seconds a copy counts as current
    if (!defined('ITOPS_RS_KEEP'))  define('ITOPS_RS_KEEP', 86400);  // seconds an older copy may still be shown while a new read is made

    function itops_rs_is_warm(): bool { return ($_SERVER['HTTP_X_ITOPS_WARM'] ?? '') === '1'; }

    /** May an older copy be shown? Only to a plain read: not a warm request, not ?refresh=1. */
    function itops_rs_may_serve_stale(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !itops_rs_is_warm() && empty($_GET['refresh']);
    }
    /** Tell the page that what it got is an older copy (it then asks for a warm read). */
    function itops_rs_mark(string $state, int $ts): void {
        if (headers_sent()) return;
        header('X-ITOps-Cache: ' . $state);
        header('X-ITOps-Cache-At: ' . $ts);
        header('Access-Control-Expose-Headers: X-ITOps-Cache, X-ITOps-Cache-At');
    }

    function itops_rs_key(string $route, string $action, ?array $query = null): string {
        $q = $query ?? $_GET; unset($q['refresh'], $q['_']);
        foreach ($q as $k => $v) if (!is_scalar($v)) unset($q[$k]);
        ksort($q);
        return 'rs_' . substr(md5($route . '|' . $action . '|' . http_build_query($q)), 0, 24);
    }
    function itops_rs_file(string $key): string { return itops_rs_dir() . '/' . $key . '.json'; }

    /** Drop every copy kept here (after a change). */
    function itops_rs_drop_all(): int {
        $n = 0;
        foreach (glob(itops_rs_dir() . '/rs_*.json') ?: [] as $f) if (@unlink($f)) $n++;
        return $n;
    }

    /**
     * Call at the top of a route for one GET read. Serves a copy (and exits) when there is one to serve; otherwise lets
     * the route's own code run and keeps its answer. $fresh: seconds a copy counts as current.
     */
    function itops_rs_begin(string $route, string $action, int $fresh = ITOPS_RS_FRESH): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        $key = itops_rs_key($route, $action); $file = itops_rs_file($key);
        $warm = itops_rs_is_warm(); $refresh = !empty($_GET['refresh']);
        $j = null;
        if (!$refresh && is_file($file)) { $j = json_decode((string)@file_get_contents($file), true); if (!is_array($j) || !isset($j['body'])) $j = null; }
        $age = $j ? time() - (int)$j['ts'] : PHP_INT_MAX;
        if ($j && $age <= $fresh) {
            if ($warm) json_response(['warm' => 'fresh', 'at' => (int)$j['ts']]);
            itops_rs_mark('fresh', (int)$j['ts']);
            header('Content-Type: application/json'); echo $j['body']; exit;
        }
        if ($j && $age <= ITOPS_RS_KEEP && itops_rs_may_serve_stale()) {
            itops_rs_mark('stale', (int)$j['ts']);
            header('Content-Type: application/json'); echo $j['body']; exit;
        }
        // read Microsoft now: one at a time per read (a warm request does not queue behind another one)
        $lock = itops_rs_dir() . '/' . $key . '.lock';
        if ($warm && is_file($lock) && time() - (int)@filemtime($lock) < 120) json_response(['warm' => 'busy']);
        $d = itops_rs_dir(); if (!is_dir($d)) @mkdir($d, 0700, true);
        @touch($lock);
        if (!$warm) itops_rs_mark('live', time());
        ob_start();
        register_shutdown_function(function () use ($file, $lock, $warm) {
            $body = ob_get_contents(); $code = http_response_code();
            @unlink($lock);
            if ($code !== 200 || !is_string($body) || $body === '') return;
            $p = json_decode($body, true);
            if (!is_array($p) || array_key_exists('error', $p) || (array_key_exists('configured', $p) && $p['configured'] === false)) return;
            @file_put_contents($file, json_encode(['ts' => time(), 'body' => $body]), LOCK_EX); @chmod($file, 0600);
            if ($warm) { ob_clean(); echo json_encode(['warm' => 'read']); }   // the page does not need the data itself
        });
    }

    /** Call at the top of a route that changes things: once the change is answered without error, every copy is dropped. */
    function itops_rs_drop_on_change(): void {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) return;
        register_shutdown_function(function () { if (http_response_code() < 400) itops_rs_drop_all(); });
    }

    /**
     * What the app warms for this person: the reads behind the Microsoft 365 pages their role opens — only those the server
     * has no current copy of (a second sign-in a moment later, or a page reloaded during the reads, asks only for the rest).
     */
    function itops_rs_warm_list(array $user): array {
        if (!itops_can($user, 'm365', 'view')) return [];
        $hub = ['m365-accounts-grid', 'm365-skus', 'recon-directory?mode=summary', 'license-report?days=90', 'm365-intune-devices', 'm365-defender',
                'm365-mfa', 'm365-service-health', 'm365-exchange', 'm365-teams', 'm365-storage', 'm365-copilot', 'm365-recommendations',
                'm365-app-secrets', 'm365-groups-overview', 'm365-guests', 'm365-enterprise-apps', 'm365-roles',
                'm365-directory-audit'];
        $old = ['service-health', 'service-issues', 'mfa-report', 'admin-roles', 'app-secrets', 'defender', 'ca-policies', 'secure-actions',
                'auth-methods', 'intune-devices', 'intune-policies', 'app-protection', 'device-security', 'rollout', 'email-dns', 'skus',
                'exchange?period=D7', 'storage?period=D7'];
        // pages of m365hub.php with their own copy: the file it keeps, and how long it counts as current there
        $own = ['m365-accounts-grid' => ['m365-grid', 300], 'recon-directory' => ['m365-directory', 600], 'license-report' => ['license-report-90', 600],
                'm365-directory-audit' => ['m365-directory-audit-1-', 300]];
        $rsHub = ['m365-skus', 'm365-roles'];
        $out = [];
        $current = function (string $file, int $ttl): bool { return is_file($file) && time() - (int)@filemtime($file) < $ttl; };
        foreach ($hub as $h) {
            [$a, $qs] = array_pad(explode('?', $h, 2), 2, ''); parse_str($qs, $q);
            if (in_array($a, $rsHub, true)) $f = [itops_rs_file(itops_rs_key('m365hub', $a, $q)), ITOPS_RS_FRESH];
            else { [$k, $t] = $own[$a] ?? [$a, 600]; $f = [itops_rs_dir() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $k) . '.json', function_exists('itops_m365_bg_ttl') ? itops_m365_bg_ttl($k, $t) : $t]; }
            if (!$current($f[0], $f[1])) $out[] = '/m365hub/' . $h;
        }
        foreach ($old as $o) {
            [$a, $qs] = array_pad(explode('?', $o, 2), 2, ''); parse_str($qs, $q);
            if (!$current(itops_rs_file(itops_rs_key('m365', $a, $q)), ITOPS_RS_FRESH)) $out[] = '/m365/' . $o;
        }
        return $out;
    }
}
