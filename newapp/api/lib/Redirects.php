<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — trusted redirect hosts (BT14.1).
 * After a public form / web-request submission the browser may be sent to a URL. A URL typed by an ADMIN in
 * OpsHub (a form's "Redirect URL", the public web-form redirect) is trusted. A URL arriving in the page address
 * (?redirect=) is attacker-controllable, so it is followed only to an allow-listed host:
 *   - OpsHub's own host (and relative links),
 *   - the hosts of every admin-configured redirect URL,
 *   - hosts listed in the setting redirect_allowed_hosts (comma/space separated; "example.org" also allows
 *     its subdomains).
 * Only http(s) is ever followed (javascript:, data:, etc. are refused in the page).
 */
if (!function_exists('opsh_redirect_hosts')) {
    function opsh_redirect_host_of(string $u): string {
        $h = strtolower((string)(parse_url(trim($u), PHP_URL_HOST) ?? ''));
        return preg_match('/^[a-z0-9.-]{1,253}$/', $h) ? $h : '';
    }
    function opsh_redirect_hosts(PDO $db): array {
        $hosts = [];
        if (defined('APP_URL')) $hosts[] = opsh_redirect_host_of((string)APP_URL);
        if (!empty($_SERVER['HTTP_HOST'])) $hosts[] = strtolower(preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']));
        try {
            $st = $db->prepare("SELECT skey, sval FROM opsh_settings WHERE skey IN ('redirect_allowed_hosts','public_form_redirect_url')");
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if ($k === 'public_form_redirect_url') { $hosts[] = opsh_redirect_host_of((string)$v); continue; }
                foreach (preg_split('/[\s,;]+/', strtolower((string)$v)) as $h) { $h = trim($h, " ./*"); if (preg_match('/^[a-z0-9.-]{1,253}$/', $h)) $hosts[] = $h; }
            }
        } catch (\Throwable $e) {}
        try { foreach ($db->query("SELECT settings_json FROM opsh_forms")->fetchAll(PDO::FETCH_COLUMN) as $sj) {
                $s = json_decode((string)$sj, true); if (!empty($s['redirect_url'])) $hosts[] = opsh_redirect_host_of((string)$s['redirect_url']); } } catch (\Throwable $e) {}
        return array_values(array_unique(array_filter($hosts)));
    }
}
