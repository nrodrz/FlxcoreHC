<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — in-app scheduler ("no-cron mode").
 *
 * POST /api/tick   (any signed-in user; the open app calls it every few minutes)
 *
 * Runs the background jobs that otherwise need a cPanel cron:
 *   - email-to-ticket intake (mail_intake_poll) when intake is enabled
 *   - SLA approaching/breach scan (cron-sla.php, called over loopback with its token)
 *
 * Safety:
 *   - Throttled globally: at most one run per TICK_INTERVAL seconds across ALL users,
 *     claimed with an atomic UPDATE so two browsers can't run it at the same time.
 *   - The caller never waits: the response is sent first, the work runs afterwards
 *     (litespeed_finish_request / fastcgi_finish_request).
 *   - Returns no data; nothing sensitive is exposed to the caller.
 *   - Can be switched off (setting mail_auto_tick = 0) when a real cron is configured.
 */
if ($method !== 'POST') error_response('Method not allowed', 405);

const ITOPS_TICK_INTERVAL = 180;   // seconds between runs

$db = getDB();
$get = function (string $k, $d = null) use ($db) {
    $s = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $s->execute([$k]);
    $v = $s->fetchColumn(); return $v === false ? $d : $v;
};

if ($get('mail_auto_tick', '1') !== '1') json_response(['ok' => true, 'ran' => false, 'reason' => 'disabled']);

// Atomic claim so only one request per interval does the work.
$now = time();
$db->prepare("INSERT IGNORE INTO itops_settings (skey, sval) VALUES ('auto_tick_at', '0')")->execute();
$claim = $db->prepare("UPDATE itops_settings SET sval=? WHERE skey='auto_tick_at' AND CAST(sval AS UNSIGNED) < ?");
$claim->execute([(string)$now, $now - ITOPS_TICK_INTERVAL]);
if ($claim->rowCount() !== 1) json_response(['ok' => true, 'ran' => false, 'reason' => 'throttled']);

// ---- Reply immediately, then keep working in the background ----
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // don't block this user's other requests
ignore_user_abort(true);
@set_time_limit(150);
$payload = json_encode(['ok' => true, 'ran' => true]);
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: ' . strlen($payload));
header('Connection: close');
echo $payload;
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { while (ob_get_level() > 0) @ob_end_flush(); @flush(); }

$summary = [];
// BT60: this run is recorded (Logs → Scheduled jobs)
require_once __DIR__ . '/../lib/CronMonitor.php';
itops_cron_begin($db, 'tick', 'tick');
// The main scheduler — reuse cron-sla.php (single source of truth) via a loopback call with its token.
itops_cron_step('Main scheduler (cron-sla.php over loopback)');
try {
    $tok = itops_cron_token($db);   // 0001-A004: made when missing
    if ($tok && function_exists('curl_init')) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host  = $_SERVER['HTTP_HOST'] ?? '';
        $dir   = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');  // /itops
        $base  = (defined('APP_URL') && APP_URL !== '' && strpos((string)APP_URL, '%%') === false) ? rtrim((string)APP_URL, '/')   // BT87 (code audit M-17): the token goes to the app's own address, never to the Host header
               : '';   // review CORE-18: without the app's address set, the token is never sent to the Host header
        if ($base === '') { $summary['sla'] = 'skipped: set the app address (APP_URL) so the scheduler can run here'; itops_cron_fail('The app address (APP_URL) is not set, so the main scheduler could not be started from here.'); }
        if ($base) {
            $ch = curl_init($base . '/cron-sla.php');   // BT14: token in a header, never in the URL (keeps it out of access logs)
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['X-ITOps-Cron-Token: ' . $tok, 'X-ITOps-Run: tick']]);
            $out  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $summary['sla'] = $code === 200 ? 'ok' : ('HTTP ' . $code);
            if ($code !== 200) itops_cron_fail('cron-sla.php answered HTTP ' . $code);
        }
    } else {
        $summary['sla'] = 'skipped (no cron token yet — open Logs & Integrations → Scheduled jobs once)';
    }
} catch (Throwable $e) {
    itops_cron_fail($e);
    $summary['sla'] = 'error: ' . $e->getMessage();
    error_log('[ITOps tick] sla: ' . $e->getMessage());
}

itops_cron_step('Check the scheduled jobs');
try { itops_cron_watch($db); } catch (Throwable $e) {}
itops_cron_end(null, 'Main scheduler: ' . ($summary['sla'] ?? '—'));
// Record the outcome so admins can see the scheduler is alive.
try {
    $db->prepare("INSERT INTO itops_settings (skey, sval) VALUES ('auto_tick_last', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")
       ->execute([json_encode(['at' => date('c'), 'result' => $summary])]);
} catch (Throwable $e) {}
exit;
