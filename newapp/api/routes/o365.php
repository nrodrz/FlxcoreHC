<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Microsoft 365 / Azure settings (super_admin only)
 * GET  /api/o365         → current settings (secret masked)
 * PUT  /api/o365          → save settings (secret encrypted)
 * POST /api/o365/test     → verify Graph token + mailbox
 */
itops_require($currentUser, 'settings', $method === 'GET' ? 'view' : 'edit');   // Settings → Sign-in & AI (General area); identity-critical changes stay Super Admin below
require_once __DIR__ . '/../lib/O365.php';
$db = getDB();

if (!function_exists('o365_set')) {
    function o365_set($db, $k, $v, $uid) {
        $db->prepare('INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)')
           ->execute([$k, $v, $uid]);
    }
}

switch ($method) {
    case 'GET':
        $c = o365_cfg();
        json_response([
            'o365_enabled'           => $c['enabled'] ? '1' : '0',
            'o365_tenant'            => $c['tenant'],
            'o365_client_id'         => $c['client_id'],
            'o365_client_secret_set' => $c['secret'] !== '' ? 1 : 0,
            'o365_redirect'          => $c['redirect'],
            'o365_mailbox'           => $c['mailbox'],
            'curl_available'         => function_exists('curl_init') ? 1 : 0,
        ]);
        break;

    case 'PUT':
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        // BT14: who may sign in, and to which tenant/app, is identity-critical — Super Admin only (matches /settings).
        $isSuperO = ($currentUser['role_slug'] ?? '') === 'super_admin';
        if (!$isSuperO) {
            $curO = o365_cfg();
            $curMap = ['o365_enabled' => $curO['enabled'] ? '1' : '0', 'o365_tenant' => $curO['tenant'], 'o365_client_id' => $curO['client_id'], 'o365_redirect' => $curO['redirect'], 'o365_mailbox' => $curO['mailbox'] ?? ''];   // BT86 (audit H-7): the mailbox mail is read from and sent as, too
            $o3Refuse = function (string $why) { audit_log('settings_refused', 'settings', null, 'Microsoft 365', null, ['reason' => $why]); error_response($why, 403); };
            if (!empty($b['o365_client_secret'])) $o3Refuse('Only a Super Admin can change the Microsoft 365 client secret');
            foreach ($curMap as $k0 => $cv) if (array_key_exists($k0, $b) && (string)$b[$k0] !== (string)$cv) $o3Refuse($k0 === 'o365_mailbox' ? 'Only a Super Admin can change the mailbox the app reads and sends mail from' : 'Only a Super Admin can change the Microsoft 365 sign-in settings');
        }
        $o3Before = o365_cfg(); $o3Map = ['o365_enabled' => 'enabled', 'o365_tenant' => 'tenant', 'o365_client_id' => 'client_id', 'o365_redirect' => 'redirect', 'o365_mailbox' => 'mailbox'];
        $o3B = []; $o3A = [];   // review CORE-25: what changed goes in the Audit Log (the secret only as "changed")
        foreach (['o365_enabled','o365_tenant','o365_client_id','o365_redirect','o365_mailbox'] as $k) {
            if (!array_key_exists($k, $b)) continue;
            $was = $k === 'o365_enabled' ? ($o3Before['enabled'] ? '1' : '0') : (string)($o3Before[$o3Map[$k]] ?? '');
            if ($was !== (string)$b[$k]) { $o3B[$k] = $was; $o3A[$k] = (string)$b[$k]; }
            o365_set($db, $k, (string)$b[$k], $currentUser['id']);
        }
        if (!empty($b['o365_client_secret'])) { $o3B['client secret'] = $o3Before['secret'] !== '' ? '(set)' : '(not set)'; $o3A['client secret'] = '(changed)'; }
        if (array_key_exists('o365_tenant', $b)) o365_set($db, 'o365_tenant_id', (string)$b['o365_tenant'], $currentUser['id']);   // one tenant for SSO + Graph
        if (!empty($b['o365_client_secret'])) {
            o365_set($db, 'o365_client_secret_enc', vault_encrypt($b['o365_client_secret']), $currentUser['id']);
            $db->prepare("DELETE FROM itops_settings WHERE skey IN ('o365_client_secret','o365_graph_secret_enc')")->execute();   // one secret, encrypted
        }
        audit_log('o365_settings_updated', 'settings', null, 'o365', $o3B ?: null, $o3A ?: null);
        json_response(['ok' => true]);
        break;

    case 'POST':
        if ($id === 'test') {
            json_response(graph_test());
        } else {
            error_response('Unknown action', 404);
        }
        break;

    default:
        error_response('Method not allowed', 405);
}
