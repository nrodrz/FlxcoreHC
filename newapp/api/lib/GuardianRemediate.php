<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Server Guardian remediate actions (write tier).
 *
 * Confirm-first, owner-only, audited server actions. A safe/reversible catalog only:
 *   - whm.restart-service   restart a cPanel/WHM service (dovecot, exim, httpd, mysql)
 *   - whm.unblock-ip        remove an IP from cPHulk brute-force blocking
 * Both call WHM using the existing guardian_whm_* settings and refuse if WHM isn't configured.
 * Requires Guardian.php (opsh_guardian_settings).
 */

require_once __DIR__ . '/Guardian.php';

if (!function_exists('opsh_guardian_actions')) {

    function opsh_guardian_actions(): array {
        return [
            'whm.restart-service' => [
                'label'       => 'Restart a service',
                'danger'      => 'medium',
                'params'      => ['service'],
                'description' => 'Restart a cPanel/WHM service: dovecot, exim, httpd, mysql, named, ftpd, spamd, cpsrvd, nginx, crond, imap or pop. Brief interruption while it restarts.',
            ],
            'whm.unblock-ip' => [
                'label'       => 'Unblock an IP',
                'danger'      => 'low',
                'params'      => ['ip'],
                'description' => 'Remove an IP from cPHulk brute-force blocking. Reversible (it can be re-blocked automatically).',
            ],
        ];
    }

    /** WHM API call. Returns decoded JSON; throws on transport failure. */
    /**
     * WHM API call. Returns the decoded answer; throws when WHM cannot be reached OR refuses (BT91: its "result 0"
     * is a failure, not a success). The certificate rules of opsh_whm_call apply (BT91, M-24).
     */
    function opsh_guardian_whm_call(array $s, string $fn, array $params = []): array {
        $c = opsh_whm_conn($s);
        if (!$c) throw new RuntimeException('WHM is not configured (set guardian_whm_host and guardian_whm_token).');
        $r = opsh_whm_call($c, $fn, $params, 30);
        if (!$r['ok']) throw new RuntimeException('WHM did not do it: ' . ($r['error'] ?: ('HTTP ' . $r['code'])));
        return ['data' => $r['data'], 'metadata' => $r['meta']];
    }
    /** BT91: the services a restart may name (WHM's own names). */
    function opsh_guardian_restartable(): array { return ['dovecot', 'exim', 'httpd', 'mysql', 'named', 'ftpd', 'spamd', 'cpsrvd', 'nginx', 'crond', 'imap', 'pop']; }

    /**
     * Execute an action. Returns ['status','message','preview'].
     * When $confirmed is false, returns a PREVIEW and performs NO change.
     */
    function opsh_guardian_execute(PDO $db, string $actionId, array $params, bool $confirmed): array {
        $catalog  = opsh_guardian_actions();
        $actionId = preg_replace('/[^a-z0-9._-]/', '', $actionId);
        if (!isset($catalog[$actionId])) throw new RuntimeException('Unknown action: ' . $actionId);
        $def = $catalog[$actionId];
        $s   = opsh_guardian_settings($db);

        foreach ($def['params'] as $p) {
            if (trim((string)($params[$p] ?? '')) === '') throw new RuntimeException('Missing required parameter: ' . $p);
        }

        if (!$confirmed) {
            return [
                'status'  => 'preview',
                'message' => 'Confirm to run: ' . $def['label'] . ' — ' . $def['description'],
                'preview' => ['action' => $actionId, 'params' => $params, 'danger' => $def['danger']],
            ];
        }

        switch ($actionId) {
            case 'whm.restart-service': {
                $svc = strtolower(trim((string)$params['service']));
                if (!in_array($svc, opsh_guardian_restartable(), true)) throw new RuntimeException('That service cannot be restarted from here. Allowed: ' . implode(', ', opsh_guardian_restartable()) . '.');
                opsh_guardian_whm_call($s, 'restartservice', ['service' => $svc]);
                return ['status' => 'ok', 'message' => 'Restarted service: ' . $svc, 'preview' => null];
            }
            case 'whm.unblock-ip': {
                $ip = (string)$params['ip'];
                if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Not a valid IP address.');
                opsh_guardian_whm_call($s, 'cphulk_unblock_records', ['ip' => $ip]);
                return ['status' => 'ok', 'message' => 'Unblocked IP: ' . $ip, 'preview' => null];
            }
        }
        throw new RuntimeException('Action not implemented: ' . $actionId);
    }
}
