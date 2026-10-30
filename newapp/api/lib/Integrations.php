<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Integrations
 *
 * Inbound : API keys (per system, scoped, hashed at rest, revocable)
 * Outbound: HMAC-signed webhooks fired on record create/update/delete
 *
 * Key format ....... opsk_live_<40 hex>   (shown once at creation; only a
 *                    SHA-256 hash + a short prefix are stored)
 * Signature ........ X-OpsHub-Signature: sha256=<hmac(secret, timestamp . '.' . body)>
 *                    X-OpsHub-Timestamp: <unix seconds>   (receiver should reject if skewed)
 */

if (!function_exists('opsh_api_key_generate')) {

/** Generate a new plaintext API key. Returned once, never stored in the clear. */
function opsh_api_key_generate(): string {
    return 'opsk_live_' . bin2hex(random_bytes(20));
}

function opsh_api_key_hash(string $key): string {
    return hash('sha256', $key);
}

/** First 18 chars ("opsk_live_1a2b3c4d"), stored so the UI can show which key is which. */
function opsh_api_key_prefix(string $key): string {
    return substr($key, 0, 18);
}

/**
 * Resolve a plaintext API key into a pseudo-user that opsh_can() understands.
 * Returns null when the key is unknown, revoked, or expired.
 */
function opsh_api_key_user(PDO $db, string $rawKey): ?array {
    if (strpos($rawKey, 'opsk_') !== 0) return null;
    try {
        $st = $db->prepare("SELECT * FROM opsh_api_keys WHERE key_hash = ? AND status = 'active' LIMIT 1");
        $st->execute([opsh_api_key_hash($rawKey)]);
        $k = $st->fetch();
    } catch (\Throwable $e) {
        return null; // table not migrated yet
    }
    if (!$k) return null;
    if (!empty($k['expires_at']) && strtotime($k['expires_at']) < time()) return null;

    // Record usage (best effort — never block the request on this)
    try { $db->prepare('UPDATE opsh_api_keys SET last_used_at = NOW() WHERE id = ?')->execute([(int)$k['id']]); } catch (\Throwable $e) {}

    $scopes = json_decode($k['scopes'] ?? '{}', true);
    if (!is_array($scopes)) $scopes = [];

    // A synthetic user: never a super_admin, permissions limited to the key's scopes.
    return [
        'id'            => 0,
        'email'         => 'apikey:' . $k['name'],
        'display_name'  => 'API: ' . $k['name'],
        'role_slug'     => 'api_key',
        'role_perms'    => json_encode($scopes),
        'department_id' => null,
        'status'        => 'active',
        'is_api_key'    => true,
        'api_key_id'    => (int)$k['id'],
    ];
}

// ─────────────────────────────────────────────────────────────
//  Outbound webhooks
// ─────────────────────────────────────────────────────────────

function opsh_webhook_sign(string $secret, string $timestamp, string $body): string {
    return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

/**
 * Deliver one payload to one subscription. Returns [ok, httpStatus, error].
 * Short timeout so an unresponsive receiver can never hang the API request.
 */
function opsh_webhook_send(array $hook, string $event, array $payload, ?string $deliveryId = null): array {
    $deliveryId = $deliveryId ?: bin2hex(random_bytes(8));
    $body = json_encode([
        'event'      => $event,
        'delivered'  => gmdate('c'),
        'delivery_id'=> $deliveryId,
        'data'       => $payload,
    ], JSON_UNESCAPED_SLASHES);

    $ts  = (string)time();
    require_once __DIR__ . '/Secrets.php';
    try { $hookSecret = opsh_secret_unwrap((string)($hook['secret'] ?? '')); } catch (\Throwable $e) { $hookSecret = ''; }   // BT14: stored encrypted
    $sig = opsh_webhook_sign($hookSecret, $ts, $body);

    if (!function_exists('curl_init')) return [false, 0, 'curl unavailable on this server'];
    require_once __DIR__ . '/NetGuard.php';   // BT88 (code audit M-30): public addresses only, pinned to the address checked
    $chk = opsh_net_check((string)$hook['url']);
    if (!$chk['ok']) return [false, 0, $chk['error']];

    $ch = curl_init($hook['url']);
    opsh_net_pin($ch, $chk);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'User-Agent: OpsHub-Webhook/1.0',
            'X-OpsHub-Event: ' . $event,
            'X-OpsHub-Delivery: ' . $deliveryId,
            'X-OpsHub-Timestamp: ' . $ts,
            'X-OpsHub-Signature: ' . $sig,
        ],
    ]);
    $resp   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    $ok = ($status >= 200 && $status < 300);
    return [$ok, $status, $ok ? '' : ($err ?: ('The receiver answered HTTP ' . $status . '.'))];   // BT88: what the receiver wrote back is never shown
}

/**
 * Fire an event to every active subscription that listens for it.
 * Called from CrudEngine after create / update / delete — safe to call anywhere.
 * Never throws: an integration problem must not break the core app.
 */
function opsh_fire_event(string $event, array $payload): void {
    static $depth = 0;
    if ($depth > 0) return;          // no webhook-triggered-by-webhook loops
    $depth++;
    try {
        $db = getDB();
        $hooks = $db->query("SELECT * FROM opsh_webhooks WHERE status = 'active'")->fetchAll();
        $queueMode = false;
        try { $queueMode = ($db->query("SELECT sval FROM opsh_settings WHERE skey='webhook_queue_mode'")->fetchColumn() === '1'); } catch (\Throwable $e) {}
        foreach ($hooks as $hook) {
            $events = json_decode($hook['events'] ?? '[]', true);
            if (!is_array($events)) $events = [];
            $match = in_array('*', $events, true) || in_array($event, $events, true);
            if (!$match) {
                // allow wildcards like "ticket.*"
                foreach ($events as $e) {
                    if (substr($e, -2) === '.*' && strpos($event, substr($e, 0, -1)) === 0) { $match = true; break; }
                }
            }
            if (!$match) continue;

            $deliveryId = bin2hex(random_bytes(8));
            if ($queueMode) {
                try {
                    $db->prepare('INSERT INTO opsh_webhook_deliveries (webhook_id, delivery_id, event, payload, response_status, error, attempts, status, created_at) VALUES (?,?,?,?,0,\'\',0,\'queued\',NOW())')
                       ->execute([(int)$hook['id'], $deliveryId, $event, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
                } catch (\Throwable $e) {}
                continue;
            }
            [$ok, $status, $err] = opsh_webhook_send($hook, $event, $payload, $deliveryId);

            if (!$ok) {
                $elf = __DIR__ . '/EventLog.php';
                if (is_file($elf)) require_once $elf;
                if (function_exists('opsh_log')) opsh_log('warning', 'webhook', 'Delivery failed: ' . ($hook['name'] ?? 'hook') . ' (HTTP ' . $status . ')', ['event' => $event, 'error' => substr((string)$err, 0, 200)]);
            }

            try {
                $db->prepare('INSERT INTO opsh_webhook_deliveries
                        (webhook_id, delivery_id, event, payload, response_status, error, attempts, status, created_at)
                        VALUES (?,?,?,?,?,?,?,?,NOW())')
                   ->execute([
                       (int)$hook['id'], $deliveryId, $event,
                       json_encode($payload, JSON_UNESCAPED_SLASHES),
                       $status, $err, 1, $ok ? 'delivered' : 'failed',
                   ]);
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        // swallow — integrations must never break a core write
    }
    $depth--;
}

/**
 * Drain the queued-delivery backlog (called by cron when queue mode is on).
 */
function opsh_webhook_process_queue(PDO $db, int $max = 50): int {
    $sent = 0;
    try {
        $rows = $db->query("SELECT d.id, d.delivery_id, d.event, d.payload, w.url AS hook_url, w.secret AS hook_secret, w.name AS hook_name
                            FROM opsh_webhook_deliveries d JOIN opsh_webhooks w ON w.id = d.webhook_id
                            WHERE d.status='queued' ORDER BY d.id ASC LIMIT " . (int)$max)->fetchAll();
    } catch (\Throwable $e) { return 0; }
    foreach ($rows as $d) {
        $hook = ['url' => $d['hook_url'], 'secret' => $d['hook_secret'], 'name' => $d['hook_name']];
        $payload = json_decode($d['payload'], true) ?: [];
        list($ok, $status, $err) = opsh_webhook_send($hook, $d['event'], $payload, $d['delivery_id']);
        try { $db->prepare("UPDATE opsh_webhook_deliveries SET status=?, response_status=?, error=?, attempts=1 WHERE id=?")
                 ->execute([$ok ? 'delivered' : 'failed', $status, $err, (int)$d['id']]); } catch (\Throwable $e) {}
        $sent++;
    }
    return $sent;
}

/**
 * Retry failed deliveries (called by cron). Exponential-ish backoff, max 5 attempts.
 */
function opsh_webhook_retry_failed(PDO $db, int $max = 25): int {
    try {
        $rows = $db->query("SELECT d.*, w.url, w.secret, w.status AS hook_status
                            FROM opsh_webhook_deliveries d
                            JOIN opsh_webhooks w ON w.id = d.webhook_id
                            WHERE d.status = 'failed' AND d.attempts < 5 AND w.status = 'active'
                              AND d.created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)
                            ORDER BY d.id ASC LIMIT " . (int)$max)->fetchAll();
    } catch (\Throwable $e) { return 0; }

    $done = 0;
    foreach ($rows as $d) {
        $wait = 60 * pow(3, max(0, (int)$d['attempts'] - 1));   // 1m, 3m, 9m, 27m
        if (strtotime($d['created_at']) + $wait > time()) continue;

        $payload = json_decode($d['payload'] ?? '{}', true);
        if (!is_array($payload)) $payload = [];
        [$ok, $status, $err] = opsh_webhook_send(
            ['url' => $d['url'], 'secret' => $d['secret']],
            $d['event'], $payload, $d['delivery_id']
        );
        try {
            $db->prepare("UPDATE opsh_webhook_deliveries
                          SET attempts = attempts + 1, response_status = ?, error = ?, status = ?
                          WHERE id = ?")
               ->execute([$status, $err, $ok ? 'delivered' : 'failed', (int)$d['id']]);
        } catch (\Throwable $e) {}
        if ($ok) $done++;
    }
    return $done;
}

} // function_exists guard
