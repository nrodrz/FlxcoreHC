<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the app inside Microsoft Teams (BT29): setup and switches.
 *
 *   GET  /api/teams_app            status, checklist, the values to type into Azure, switches, last notification run
 *                                  (Super Admin, or Integrations · View)
 *   PUT  /api/teams_app            switch it on/off, choose which notifications go to Teams, device report on/off  (Super Admin)
 *   GET  /api/teams_app/package    download the Teams app package (zip: manifest.json + two icons)               (Super Admin)
 *   POST /api/teams_app/test       send a test notification to my own Teams activity feed                          (Super Admin)
 *
 * Sign-in from Teams is POST /api/auth/teams; the notification run is itops_teams_feed_run() in cron-sla.php.
 */
require_once __DIR__ . '/../lib/TeamsApp.php';
require_once __DIR__ . '/../lib/O365.php';
require_once __DIR__ . '/../lib/Graph.php';

$db = getDB();
$isSuper = (($currentUser['role_slug'] ?? '') === 'super_admin');
if (!$isSuper && !itops_can($currentUser, 'integrations', 'view')) error_response('Insufficient permissions', 403);
$superOnly = function () use ($isSuper) { if (!$isSuper) error_response('Only a Super Admin can set up the Teams app.', 403); };
$o365 = o365_cfg();

$status = function () use ($db, $o365, $isSuper): array {
    $cfg = itops_teams_cfg($db); $b = itops_teams_base();
    $checks = itops_teams_checks($o365);
    $ready = true; foreach ($checks as $c) if (in_array($c[0], ['https', 'sso', 'icons'], true) && !$c[1]) $ready = false;
    $pending = 0;
    if ($cfg['cursor'] !== null) { try { $s = $db->prepare('SELECT COUNT(*) FROM itops_notifications WHERE id > ?'); $s->execute([$cfg['cursor']]); $pending = (int)$s->fetchColumn(); } catch (Throwable $e) {} }
    return [
        'enabled' => $cfg['enabled'], 'ready' => $ready, 'can_edit' => $isSuper,
        'checks' => array_map(fn($c) => ['key' => $c[0], 'ok' => (bool)$c[1], 'text' => $c[2]], $checks),
        'app_url' => $b['url'], 'tab_url' => $b['url'] . '/index.html?host=teams',
        'client_id' => (string)$o365['client_id'], 'tenant' => (string)$o365['tenant'],
        'resource' => $o365['client_id'] !== '' ? itops_teams_resource((string)$o365['client_id']) : '',
        'scope' => ITOPS_TEAMS_SCOPE,
        'clients' => array_map(fn($c) => ['id' => $c[0], 'name' => $c[1], 'required' => $c[2]], ITOPS_TEAMS_CLIENTS),
        'app_id' => $cfg['app_id'], 'package_version' => $cfg['app_id'] !== '' ? '1.0.' . $cfg['pkg_ver'] : '',
        'notify' => $cfg['notify'], 'inventory' => $cfg['inventory'],
        'last_run' => $cfg['last_run'], 'pending' => $pending,
    ];
};

if ($method === 'GET' && $id === null) json_response($status());

if ($method === 'PUT' && $id === null) {
    $superOnly();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $cfg = itops_teams_cfg($db); $before = ['enabled' => $cfg['enabled'], 'notify' => $cfg['notify'], 'inventory' => $cfg['inventory']];
    if (array_key_exists('enabled', $body)) {
        $on = !empty($body['enabled']);
        if ($on && !$cfg['enabled']) {
            foreach (itops_teams_checks($o365) as $c) if (in_array($c[0], ['https', 'sso', 'icons'], true) && !$c[1]) error_response('Not ready yet: ' . $c[2]);
            if ($cfg['app_id'] === '') $cfg['app_id'] = itops_teams_guid();
        }
        $cfg['enabled'] = $on;
    }
    if (is_array($body['notify'] ?? null)) foreach (array_keys(ITOPS_TEAMS_KINDS) as $k) if (array_key_exists($k, $body['notify'])) $cfg['notify'][$k] = !empty($body['notify'][$k]);
    if (array_key_exists('inventory', $body)) $cfg['inventory'] = !empty($body['inventory']);
    // Whatever changed, Teams notifications start from "now": switching a kind on never replays old notifications.
    $cfg['cursor'] = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM itops_notifications')->fetchColumn();
    itops_teams_save($db, $cfg);
    audit_log('teams_app_settings', 'settings', null, 'Teams app', $before, ['enabled' => $cfg['enabled'], 'notify' => $cfg['notify'], 'inventory' => $cfg['inventory']]);
    json_response($status());
}

if ($method === 'GET' && $id === 'package') {
    $superOnly();
    foreach (itops_teams_checks($o365) as $c) if (in_array($c[0], ['https', 'sso', 'icons'], true) && !$c[1]) error_response('The package cannot be built yet: ' . $c[2]);
    try { [$name, $bytes] = itops_teams_package($db, $o365); } catch (Throwable $e) { error_response($e->getMessage(), 500); }
    audit_log('teams_app_package', 'settings', null, $name);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}

if ($method === 'POST' && $id === 'test') {
    $superOnly();
    $cfg = itops_teams_cfg($db);
    if (!$cfg['enabled'] || $cfg['app_id'] === '') error_response('Switch the Teams app on first.');
    if (!itops_graph_configured()) error_response('Microsoft Graph is not connected, so Teams notifications cannot be sent.');
    $ref = (string)($currentUser['o365_oid'] ?: $currentUser['email']);
    $r = itops_teams_activity_send($cfg, $ref, 'announcements', 'Test from ' . itops_app_name(), 'If you can read this in Teams, notifications are working.', 'announcements');
    $msg = ['sent' => 'Sent. Look at Activity in Teams.',
            'not_installed' => 'Teams did not deliver it: the app is not installed for your account in Teams yet (or the package was uploaded before notifications were added — upload the current package again). Microsoft said: ' . $r['error'],
            'denied' => 'Teams refused it: the app has no permission to send notifications. Upload the current app package, or add the Graph application permission TeamsActivity.Send with admin consent. Microsoft said: ' . $r['error'],
            'throttled' => 'Microsoft asked to slow down. Try again in a minute.',
            'failed' => 'It could not be sent: ' . $r['error']][$r['status']];
    json_response(['ok' => $r['status'] === 'sent', 'status' => $r['status'], 'message' => $msg]);
}

error_response('Not found', 404);
