<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — /api/integrations
 *
 * GET    /integrations/keys              — list API keys (never returns the secret)
 * POST   /integrations/keys              — create a key (returns the plaintext ONCE)
 * DELETE /integrations/keys/:id          — revoke a key
 * GET    /integrations/webhooks          — list webhook subscriptions
 * POST   /integrations/webhooks          — create a subscription (generates a signing secret)
 * PUT    /integrations/webhooks/:id      — update (url / events / status)
 * DELETE /integrations/webhooks/:id      — delete
 * POST   /integrations/webhooks/:id/test — send a test event
 * GET    /integrations/deliveries        — recent delivery log
 * POST   /integrations/retry             — retry failed deliveries now
 *
 * Admin-only. API keys themselves may NOT manage integrations (no privilege escalation).
 */

require_once __DIR__ . '/../lib/Integrations.php';

// Never let an API key administer the integration system itself.
if (!empty($currentUser['is_api_key'])) error_response('API keys cannot manage integrations', 403);
opsh_require($currentUser, 'integrations', 'view');

$db  = getDB();
$sub = $segments[1] ?? '';
$id  = isset($segments[2]) ? (int)$segments[2] : null;
$act = $segments[3] ?? '';

$needsMigration = function (\Throwable $e) {
    $code = $e->getCode();
    if ($code === '42S02' || $code === 1146 || strpos($e->getMessage(), 'Base table') !== false) {
        json_response(['data' => [], 'needs_migration' => true]);
    }
    throw $e;
};

switch ($method . ':' . $sub) {

    // ── API keys ──────────────────────────────────────────────
    case 'GET:keys':
        try {
            $rows = $db->query("SELECT id, name, key_prefix, scopes, status, last_used_at, expires_at, created_at
                                FROM opsh_api_keys ORDER BY id DESC")->fetchAll();
        } catch (\Throwable $e) { $needsMigration($e); }
        foreach ($rows as &$r) { $r['scopes'] = json_decode($r['scopes'] ?? '{}', true); }
        json_response(['data' => $rows]);
        break;

    case 'POST:keys':
        opsh_require($currentUser, 'integrations', 'edit');
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $name   = trim($body['name'] ?? '');
        $scopes = $body['scopes'] ?? [];
        if ($name === '') error_response('Name is required');
        if (!is_array($scopes) || !$scopes) error_response('Select at least one permission scope');
        $scopesNorm = [];
        // A key may never hold more than its creator: no wildcard, and every module/action must be one
        // the creator holds (super admins may grant anything except the wildcard).
        foreach ($scopes as $mod => $acts) {
            if ($mod === '*' || !preg_match('/^[a-z0-9_]{1,40}$/', (string)$mod)) error_response('Invalid scope "' . $mod . '"', 400);
            // BT124 (review M365B-2): the guarded Microsoft 365 actions need a person (and, where set, a second person)
            if (in_array((string)$mod, ['m365_device_wipe', 'm365_ca', 'm365_roles'], true)) {
                audit_log('integration_refused', 'integration', null, 'API key', null, ['Wanted' => 'scope ' . $mod, 'Why' => 'Guarded Microsoft 365 actions are never given to an API key.']);
                error_response('API keys cannot hold "' . $mod . '": wipe, Conditional Access and admin role changes are done by a person in the app.', 400);
            }
            // levels used by the UI: view | create_edit | crud (arrays of actions are also accepted)
            $need = is_array($acts) ? array_map('strval', $acts)
                  : (['view' => ['view'], 'read' => ['view'], 'create_edit' => ['view', 'create', 'edit'], 'crud' => ['view', 'create', 'edit', 'delete']][(string)$acts] ?? null);
            if ($need === null) error_response('Invalid access level for "' . $mod . '"', 400);
            foreach ($need as $act) {
                if (!opsh_can($currentUser, (string)$mod, $act)) error_response('You can\'t give an API key "' . $mod . ':' . $act . '" — you don\'t have it yourself', 403);
            }
            $scopesNorm[(string)$mod] = array_values(array_unique($need));   // BT14: stored as explicit action arrays (a legacy "crud" string would also imply tickets "all")
        }
        $scopes = $scopesNorm;

        $expires = null;   // review OPS-23: an expiry that is not a date in the future is refused (it used to make a key that was already expired)
        if (!empty($body['expires_at'])) { $et = strtotime((string)$body['expires_at']); if ($et === false || $et <= time()) error_response('The expiry must be a date in the future.', 400); $expires = date('Y-m-d H:i:s', $et); }
        $plain   = opsh_api_key_generate();

        try {
            $db->prepare('INSERT INTO opsh_api_keys (name, key_prefix, key_hash, scopes, status, expires_at, created_by, created_at)
                          VALUES (?,?,?,?,?,?,?,NOW())')
               ->execute([
                   $name,
                   opsh_api_key_prefix($plain),
                   opsh_api_key_hash($plain),
                   json_encode($scopes),
                   'active',
                   $expires,
                   (int)($currentUser['id'] ?? 0),
               ]);
        } catch (\Throwable $e) { $needsMigration($e); }

        $newKeyId = (int)$db->lastInsertId();
        audit_log('api_key_created', 'integration', $newKeyId, $name, null, ['scopes' => $scopes, 'expires' => $expires ?: 'never']);
        // The plaintext key is returned exactly once and never stored.
        json_response(['id' => $newKeyId, 'name' => $name, 'key' => $plain]);
        break;

    case 'DELETE:keys':
        opsh_require($currentUser, 'integrations', 'edit');
        if (!$id) error_response('ID required');
        $kq = $db->prepare('SELECT name, status FROM opsh_api_keys WHERE id=?'); $kq->execute([(int)$id]); $kr = $kq->fetch(PDO::FETCH_ASSOC);
        if (!$kr) error_response('API key not found', 404);
        $db->prepare("UPDATE opsh_api_keys SET status='revoked' WHERE id=?")->execute([$id]);
        audit_log('api_key_revoked', 'integration', $id, (string)$kr['name'], ['status' => $kr['status']], ['status' => 'revoked']);
        json_response(['ok' => true]);
        break;

    // ── Webhooks ──────────────────────────────────────────────
    case 'GET:webhooks':
        try {
            $rows = $db->query("SELECT id, name, url, events, status, secret, created_at
                                FROM opsh_webhooks ORDER BY id DESC")->fetchAll();
        } catch (\Throwable $e) { $needsMigration($e); }
        // BT124 (review OPS-2, UI1-3): a signing secret is shown once, when it is made (or made again); never in the list
        foreach ($rows as &$r) { $r['events'] = json_decode($r['events'] ?? '[]', true); $r['secret'] = ''; $r['secret_hidden'] = true; }
        unset($r);
        json_response(['data' => $rows]);
        break;

    case 'POST:webhooks':
        opsh_require($currentUser, 'integrations', 'edit');
        // BT124 (review OPS-2): a webhook sends records of every module it listens to out of the app, whatever the role of
        // the person who made it can see: only a Super Admin makes one, changes where it goes or what it listens to, or
        // makes a new signing secret
        $whSuper = function (string $what) use ($currentUser) {
            if (($currentUser['role_slug'] ?? '') === 'super_admin') return;
            audit_log('webhook_refused', 'integration', null, 'Webhook', null, ['Wanted' => $what, 'Needs' => 'Super Admin']);
            error_response('Only a Super Admin can ' . $what . ': a webhook sends records out of the app.', 403);
        };
        if ($id && $act === 'secret') {
            $whSuper('make a new signing secret');
            $h = $db->prepare('SELECT id, name FROM opsh_webhooks WHERE id=?'); $h->execute([$id]); $h = $h->fetch();
            if (!$h) error_response('Webhook not found', 404);
            require_once __DIR__ . '/../lib/Secrets.php';
            $secret = bin2hex(random_bytes(24));
            $db->prepare('UPDATE opsh_webhooks SET secret=? WHERE id=?')->execute([opsh_secret_wrap($secret), (int)$id]);
            audit_log('webhook_secret_renewed', 'integration', (int)$id, (string)$h['name']);
            json_response(['ok' => true, 'secret' => $secret]);
        }
        if ($id && $act === 'test') {
            $h = $db->prepare('SELECT * FROM opsh_webhooks WHERE id=?'); $h->execute([$id]); $h = $h->fetch();
            if (!$h) error_response('Webhook not found', 404);
            [$ok, $status, $err] = opsh_webhook_send($h, 'ping', ['message' => 'Test event from ' . opsh_app_name(), 'at' => gmdate('c')]);
            json_response(['ok' => $ok, 'response_status' => $status, 'error' => $err]);
            break;
        }
        $whSuper('make a webhook');
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $name   = trim($body['name'] ?? '');
        $url    = trim($body['url'] ?? '');
        $events = $body['events'] ?? [];
        if ($name === '') error_response('Name is required');
        if (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0) {
            error_response('A valid https:// URL is required');
        }
        require_once __DIR__ . '/../lib/NetGuard.php';   // BT88 (code audit M-30)
        $nc = opsh_net_check($url); if (!$nc['ok']) { audit_log('webhook_refused', 'integration', null, mb_substr($url, 0, 200), null, ['reason' => $nc['error']]); error_response($nc['error'], 400); }
        if (!is_array($events) || !$events) error_response('Select at least one event');

        $secret = bin2hex(random_bytes(24));
        try {
            $db->prepare('INSERT INTO opsh_webhooks (name, url, secret, events, status, created_by, created_at)
                          VALUES (?,?,?,?,?,?,NOW())')
               ->execute([$name, $url, (function ($s) { require_once __DIR__ . '/../lib/Secrets.php'; return opsh_secret_wrap($s); })($secret), json_encode(array_values($events)), 'active', (int)($currentUser['id'] ?? 0)]);
            $__newId = (int)$db->lastInsertId();   // capture now — audit_log() inserts a row too
        } catch (\Throwable $e) { $needsMigration($e); }

        audit_log('webhook_created', 'integration', $__newId, $name);
        json_response(['id' => $__newId, 'name' => $name, 'secret' => $secret]);
        break;

    case 'PUT:webhooks':
        opsh_require($currentUser, 'integrations', 'edit');
        if (!$id) error_response('ID required');
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $sets = []; $bind = [];
        $wq = $db->prepare('SELECT name, url, events, status FROM opsh_webhooks WHERE id=?'); $wq->execute([(int)$id]); $wOld = $wq->fetch(PDO::FETCH_ASSOC);
        if (!$wOld) error_response('Webhook not found', 404);
        if ((isset($body['url']) || isset($body['events'])) && ($currentUser['role_slug'] ?? '') !== 'super_admin') {   // BT124 (review OPS-2)
            audit_log('webhook_refused', 'integration', (int)$id, 'Webhook', null, ['Wanted' => 'change where it goes or what it listens to', 'Needs' => 'Super Admin']);
            error_response('Only a Super Admin can change where a webhook goes or what it listens to.', 403);
        }
        if (isset($body['name']))   { $sets[] = 'name = ?';   $bind[] = trim($body['name']); }
        if (isset($body['url']))    {
            $u = trim($body['url']);
            if (!filter_var($u, FILTER_VALIDATE_URL) || stripos($u, 'https://') !== 0) error_response('A valid https:// URL is required');
            require_once __DIR__ . '/../lib/NetGuard.php';   // BT124: the same address check as when it is made
            $nc = opsh_net_check($u); if (!$nc['ok']) { audit_log('webhook_refused', 'integration', (int)$id, mb_substr($u, 0, 200), null, ['reason' => $nc['error']]); error_response($nc['error'], 400); }
            $sets[] = 'url = ?'; $bind[] = $u;
        }
        if (isset($body['events'])) { $sets[] = 'events = ?'; $bind[] = json_encode(array_values((array)$body['events'])); }
        if (isset($body['status'])) { $sets[] = 'status = ?'; $bind[] = ($body['status'] === 'paused' ? 'paused' : 'active'); }
        if (!$sets) error_response('Nothing to update');
        $bind[] = $id;
        $db->prepare('UPDATE opsh_webhooks SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($bind);
        $wq->execute([(int)$id]); $wNew = $wq->fetch(PDO::FETCH_ASSOC) ?: [];
        $wb = []; $wa = []; foreach ($wNew as $k => $v) if ((string)($wOld[$k] ?? '') !== (string)$v) { $wb[$k] = mb_substr((string)($wOld[$k] ?? ''), 0, 300); $wa[$k] = mb_substr((string)$v, 0, 300); }
        audit_log('webhook_updated', 'integration', $id, (string)($wNew['name'] ?? ''), $wb ?: null, $wa ?: null);   // review OPS-23
        json_response(['ok' => true]);
        break;

    case 'DELETE:webhooks':
        opsh_require($currentUser, 'integrations', 'edit');
        if (!$id) error_response('ID required');
        $db->prepare('DELETE FROM opsh_webhooks WHERE id=?')->execute([$id]);
        audit_log('webhook_deleted', 'integration', $id, '');
        json_response(['ok' => true]);
        break;

    // ── Delivery log ──────────────────────────────────────────
    case 'GET:deliveries':
        try {
            $rows = $db->query("SELECT d.id, d.delivery_id, d.event, d.response_status, d.error,
                                       d.attempts, d.status, d.created_at, w.name AS webhook_name
                                FROM opsh_webhook_deliveries d
                                LEFT JOIN opsh_webhooks w ON w.id = d.webhook_id
                                ORDER BY d.id DESC LIMIT 100")->fetchAll();
        } catch (\Throwable $e) { $needsMigration($e); }
        json_response(['data' => $rows]);
        break;

    case 'POST:retry':
        opsh_require($currentUser, 'integrations', 'edit');
        $n = opsh_webhook_retry_failed($db, 25);
        json_response(['retried' => $n]);
        break;

    default:
        error_response('Not found', 404);
}
