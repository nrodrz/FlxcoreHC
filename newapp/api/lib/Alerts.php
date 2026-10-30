<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Alerts
 *
 * Turns the read-only Guardian + M365 signals into edge-triggered alerts:
 *   - a condition that starts breaching  -> raise  (Event Log + admin notification + webhook)
 *   - a condition that stops breaching   -> clear  (Event Log + admin notification + webhook)
 * An ongoing condition is NOT re-notified every run; only the transitions fire.
 *
 * State lives in opsh_alerts (one row per stable alert_key). Guardian severity is
 * taken as-is from the package (it already encodes the disk/load/imunify/imap thresholds).
 *
 * Called from cron-sla.php. Read-only with respect to the tenant/server.
 */

require_once __DIR__ . '/EventLog.php';
require_once __DIR__ . '/Guardian.php';

if (!function_exists('opsh_alerts_reconcile')) {

function opsh_alert_notify_admins(PDO $db, string $title, string $body): void {
    try {
        $admins = $db->query("SELECT u.id FROM opsh_users u JOIN opsh_roles r ON r.id = u.role_id
                              WHERE u.status='active' AND r.slug IN ('super_admin','admin','dept_admin')")->fetchAll(PDO::FETCH_COLUMN);
        if (!$admins) {
            $admins = $db->query("SELECT u.id FROM opsh_users u JOIN opsh_roles r ON r.id = u.role_id
                                  WHERE u.status='active' AND r.slug='super_admin'")->fetchAll(PDO::FETCH_COLUMN);
        }
        $ins = $db->prepare("INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
        foreach ($admins as $uid) { try { $ins->execute([(int)$uid, $title, $body, 'alert', '#guardian']); } catch (\Throwable $e) {} }
    } catch (\Throwable $e) {}
}

function opsh_alert_fire_webhook(string $event, array $payload): void {
    $f = __DIR__ . '/Integrations.php';
    if (is_file($f)) {
        require_once $f;
        if (function_exists('opsh_fire_event')) { try { opsh_fire_event($event, $payload); } catch (\Throwable $e) {} }
    }
}

/**
 * Reconcile the set of currently-breaching conditions against what's already open.
 * $current = [ alert_key => ['level'=>'warning|error','channel'=>..,'message'=>..], ... ]
 * Returns ['raised'=>n,'cleared'=>n,'open'=>n].
 */
function opsh_alerts_reconcile(PDO $db, array $current): array {
    $raised = 0; $cleared = 0;
    try { $open = $db->query("SELECT * FROM opsh_alerts WHERE status='open'")->fetchAll(); }
    catch (\Throwable $e) { return ['raised' => 0, 'cleared' => 0, 'open' => 0, 'error' => 'alerts table missing']; }

    $openByKey = [];
    foreach ($open as $o) $openByKey[$o['alert_key']] = $o;

    // Configurable minimum severity: when set to 'error', warning-level conditions don't raise
    // (and any open warnings clear on the next pass). Default keeps everything.
    try {
        $ms = $db->query("SELECT sval FROM opsh_settings WHERE skey='alert_min_severity'")->fetchColumn();
        if ($ms === 'error') $current = array_filter($current, function ($a) { return (($a['level'] ?? 'warning') === 'error'); });
    } catch (\Throwable $e) {}

    // raise new / refresh ongoing
    foreach ($current as $key => $a) {
        $lvl = (($a['level'] ?? 'warning') === 'error') ? 'error' : 'warning';
        $ch  = $a['channel'] ?? 'guardian';
        $msg = (string)($a['message'] ?? $key);
        if (isset($openByKey[$key])) {
            try { $db->prepare("UPDATE opsh_alerts SET last_seen=NOW(), level=?, message=? WHERE id=?")
                     ->execute([$lvl, mb_substr((string)$msg, 0, 500), (int)$openByKey[$key]['id']]); } catch (\Throwable $e) {}
        } else {
            try {
                $db->prepare("INSERT INTO opsh_alerts (alert_key,level,channel,message,status,first_seen,last_seen)
                              VALUES (?,?,?,?, 'open', NOW(), NOW())
                              ON DUPLICATE KEY UPDATE status='open', level=VALUES(level), message=VALUES(message), last_seen=NOW(), cleared_at=NULL")
                   ->execute([$key, $lvl, $ch, mb_substr((string)$msg, 0, 500)]);
            } catch (\Throwable $e) { continue; }
            opsh_log($lvl, $ch, $msg, ['alert_key' => $key, 'state' => 'raised']);
            opsh_alert_notify_admins($db, 'Alert: ' . $msg, 'A ' . $lvl . ' condition was detected (' . $ch . ').');
            opsh_alert_fire_webhook('alert.raised', ['key' => $key, 'level' => $lvl, 'channel' => $ch, 'message' => $msg]);
            $raised++;
        }
    }

    // clear conditions that are no longer breaching
    foreach ($openByKey as $key => $o) {
        if (!isset($current[$key])) {
            try { $db->prepare("UPDATE opsh_alerts SET status='cleared', cleared_at=NOW() WHERE id=?")->execute([(int)$o['id']]); } catch (\Throwable $e) {}
            opsh_log('info', $o['channel'] ?: 'guardian', 'Cleared: ' . $o['message'], ['alert_key' => $key, 'state' => 'cleared']);
            opsh_alert_notify_admins($db, 'Cleared: ' . $o['message'], 'A previously-detected condition has cleared.');
            opsh_alert_fire_webhook('alert.cleared', ['key' => $key, 'channel' => $o['channel'], 'message' => $o['message']]);
            $cleared++;
        }
    }

    $openNow = 0;
    try { $openNow = (int)$db->query("SELECT COUNT(*) FROM opsh_alerts WHERE status='open'")->fetchColumn(); } catch (\Throwable $e) {}
    return ['raised' => $raised, 'cleared' => $cleared, 'open' => $openNow];
}

/**
 * Build the current breach set from Guardian (+ M365 if configured) and reconcile.
 */
function opsh_alerts_evaluate(PDO $db): array {
    $current = [];

    // Guardian — severity is already computed by the package.
    try {
        foreach (opsh_guardian_run_cached($db, 600) as $sec) {   // BT124 (review OPS-9): one full scan per ten minutes at most
            $lvl = $sec['level'] ?? 'ok';
            if ($lvl === 'warning' || $lvl === 'error') {
                $bad = null;
                foreach (($sec['findings'] ?? []) as $f) {
                    if (($f['severity'] ?? '') === 'error') { $bad = $f; break; }
                    if (($f['severity'] ?? '') === 'warning' && !$bad) $bad = $f;
                }
                $msg = ($sec['title'] ?? $sec['name']) . ': ' . trim(($bad['label'] ?? '') . ' ' . ($bad['value'] ?? $lvl));
                $current['guardian:' . $sec['name']] = ['level' => $lvl, 'channel' => 'guardian', 'message' => trim($msg)];
            }
        }
    } catch (\Throwable $e) {}

    // M365 — best-effort, only when Graph is configured.
    $gf = __DIR__ . '/Graph.php';
    if (is_file($gf)) {
        require_once $gf;
        if (function_exists('opsh_graph_configured') && opsh_graph_configured() && function_exists('opsh_graph_get')) {
            try {
                $r = opsh_graph_get('subscribedSkus');
                foreach (($r['value'] ?? []) as $s) {
                    $en = (int)($s['prepaidUnits']['enabled'] ?? 0);
                    $co = (int)($s['consumedUnits'] ?? 0);
                    if ($en > 0 && $co >= $en) {
                        $part = $s['skuPartNumber'] ?? ($s['skuId'] ?? 'sku');
                        $current['m365:lic:' . $part] = ['level' => 'warning', 'channel' => 'm365',
                            'message' => 'Microsoft 365 license exhausted: ' . $part . ' (' . $co . '/' . $en . ')'];
                    }
                }
            } catch (\Throwable $e) {}
            try {
                $r = opsh_graph_get('admin/serviceAnnouncement/issues?$filter=isResolved eq false&$top=25');
                $issues = $r['value'] ?? [];
                if (count($issues) > 0) {
                    $current['m365:health'] = ['level' => 'warning', 'channel' => 'm365',
                        'message' => 'Microsoft 365 service issues open: ' . count($issues)];
                }
            } catch (\Throwable $e) {}
        }
    }

    return opsh_alerts_reconcile($db, $current);
}

} // guard
