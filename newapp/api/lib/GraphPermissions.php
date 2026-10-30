<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT36 — Microsoft 365 Diagnose: the app registration next to the token.
 *
 * "I added the permission and it still says 403" has three different causes that look the same from outside:
 *   · the permission is on the app as Application, but nobody clicked "Grant admin consent"   → no_consent
 *   · it was added as a Delegated permission (an app-only token never carries those)          → delegated
 *   · it was added to a different app registration                                             → absent
 * and one that is not a permission problem at all:
 *   · the permission IS in the token and Microsoft still refused (licence, feature off)        → in_token
 * These helpers read the app registration (Application.Read.All — already used for the secret-expiry check) and
 * say which one it is. Read-only; nothing here changes the tenant. Kept apart from Graph.php so the logic can be
 * exercised with a stubbed itops_graph_http().
 */
require_once __DIR__ . '/Brand.php';

if (!function_exists('itops_graph_perm_names')) {

    /** Permission names in a "Needs" label: "User.Read.All / User.ReadWrite.All (+ Entra ID P1)" → [User.Read.All, User.ReadWrite.All]. */
    function itops_graph_perm_names(string $label): array {
        preg_match_all('/\b[A-Z][A-Za-z]+(?:\.[A-Za-z]+){1,3}\b/', $label, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    /** True when $have (permission names) covers $name: the name itself or its ReadWrite sibling ("X.Read.All" is covered by "X.ReadWrite.All"). */
    function itops_graph_perm_covered(string $name, array $have): bool {
        $h = array_map('strtolower', $have); $n = strtolower($name);
        if (in_array($n, $h, true)) return true;
        $rw = preg_replace('/\.read(basic)?\./', '.readwrite.', $n);
        return $rw !== $n && in_array($rw, $h, true);
    }

    /**
     * BT36 — what the Azure app registration ASKS for, next to what the token CARRIES. Read-only, never throws.
     * A permission can be on the app three ways, and only one of them reaches the token:
     *   Application + admin consent  → in the token (works)
     *   Application, no consent      → on the app, not in the token   ("Not granted for <tenant>" in Azure)
     *   Delegated                    → never in an app-only token, whatever the consent
     * Needs Application.Read.All (the same permission the secret-expiry check uses). Without it: ['readable' => false].
     */
    function itops_graph_app_permissions(array $tokenRoles): array {
        $out = ['readable' => false, 'app_name' => '', 'application' => [], 'delegated' => [], 'pending' => [], 'error' => ''];
        $c = itops_graph_cfg();
        if (!preg_match('/^[0-9a-fA-F-]{32,40}$/', (string)$c['client'])) { $out['error'] = 'The Client ID is not a GUID.'; return $out; }
        $graphApp = '00000003-0000-0000-c000-000000000000';   // Microsoft Graph's own app id — the same in every tenant
        try {
            $url = 'https://graph.microsoft.com/v1.0/applications?$filter=' . rawurlencode("appId eq '" . $c['client'] . "'") . '&$select=id,displayName,requiredResourceAccess';
            [$code, $d] = itops_graph_http('GET', $url, null, [], 20);
            if ($code >= 400) { $out['error'] = (string)($d['error']['message'] ?? ('HTTP ' . $code)); return $out; }
            $app = $d['value'][0] ?? null;
            if (!$app) { $out['error'] = 'No app registration with this Client ID exists in this tenant (the app may be registered in another tenant).'; return $out; }
            $out['app_name'] = (string)($app['displayName'] ?? '');
            $roleIds = []; $scopeIds = [];
            foreach ((array)($app['requiredResourceAccess'] ?? []) as $rra) {
                if (strcasecmp((string)($rra['resourceAppId'] ?? ''), $graphApp) !== 0) continue;
                foreach ((array)($rra['resourceAccess'] ?? []) as $ra) {
                    if (($ra['type'] ?? '') === 'Role') $roleIds[(string)$ra['id']] = true; else $scopeIds[(string)$ra['id']] = true;
                }
            }
            $url = 'https://graph.microsoft.com/v1.0/servicePrincipals?$filter=' . rawurlencode("appId eq '" . $graphApp . "'") . '&$select=id,appRoles,oauth2PermissionScopes';
            [$code, $d] = itops_graph_http('GET', $url, null, [], 25);
            if ($code >= 400) { $out['error'] = (string)($d['error']['message'] ?? ('HTTP ' . $code)); return $out; }
            $sp = $d['value'][0] ?? [];
            foreach ((array)($sp['appRoles'] ?? []) as $r) if (isset($roleIds[(string)($r['id'] ?? '')])) $out['application'][] = (string)($r['value'] ?? '');
            foreach ((array)($sp['oauth2PermissionScopes'] ?? []) as $r) if (isset($scopeIds[(string)($r['id'] ?? '')])) $out['delegated'][] = (string)($r['value'] ?? '');
            sort($out['application']); sort($out['delegated']);
            $have = array_map('strtolower', $tokenRoles);
            $out['pending'] = array_values(array_filter($out['application'], fn($n) => !in_array(strtolower($n), $have, true)));
            $out['readable'] = true;
        } catch (\Throwable $e) { $out['error'] = $e->getMessage(); }
        return $out;
    }

    /**
     * BT36 — why a live test was refused, from the token and the app registration. Returns [state, text].
     * States: in_token (Microsoft refused for another reason) · no_consent · delegated · absent · unknown.
     */
    function itops_graph_why_refused(string $permLabel, array $tokenRoles, array $app): array {
        $names = itops_graph_perm_names($permLabel);
        if (!$names) return ['unknown', ''];
        foreach ($names as $n) if (itops_graph_perm_covered($n, $tokenRoles)) {
            return ['in_token', $n . ' IS in the token, so this is not a missing permission: Microsoft refused for another reason (a licence the tenant lacks, a feature that is switched off, or the change is still spreading — wait a few minutes and re-test).'];
        }
        if (empty($app['readable'])) return ['unknown', 'Not in the token. In Azure → App registrations → your app → API permissions, check that it is listed with Type "Application" (not "Delegated") and Status "Granted for <your organization>".'];
        $list = implode(' or ', $names);
        foreach ($names as $n) if (itops_graph_perm_covered($n, $app['application'])) {
            return ['no_consent', $n . ' is on the app as an Application permission, but admin consent has not been given, so Microsoft leaves it out of the token. In Azure → API permissions click "Grant admin consent for <your organization>" (a Global Administrator or Privileged Role Administrator must do it) and wait for the Status column to turn green.'];
        }
        foreach ($names as $n) if (itops_graph_perm_covered($n, $app['delegated'])) {
            return ['delegated', $n . ' was added as a DELEGATED permission. ' . itops_app_name() . ' signs in as an application, so it must be an APPLICATION permission: Add a permission → Microsoft Graph → "Application permissions" → ' . $n . ', then Grant admin consent. The delegated one can be removed.'];
        }
        return ['absent', $list . ' is not on this app registration' . (($app['app_name'] ?? '') !== '' ? ' ("' . $app['app_name'] . '")' : '') . ' at all. If you added it, it went to a different app — compare the Application (client) ID in Azure with the Client ID shown here.'];
    }

    /** Adds $out['app'] and the "waiting for consent" advice. Call once the token's roles are known. */
    function itops_graph_explain_app(array &$out): void {
        $roles = (array)($out['token']['roles'] ?? []);
        $out['app'] = itops_graph_app_permissions($roles);
        if (!empty($out['app']['pending'])) $out['advice'][] = 'On the app as Application permissions but NOT in the token (admin consent is missing): ' . implode(', ', $out['app']['pending']) . '. In Azure → App registrations → ' . ($out['app']['app_name'] !== '' ? '"' . $out['app']['app_name'] . '"' : 'your app') . ' → API permissions, click "Grant admin consent for <your organization>", then "Refresh token & re-test" here.';
    }

    /** Adds state / why to every test Microsoft refused (401 / 403) and a "What to do" line for each (consent cases share one line). */
    function itops_graph_explain_checks(array &$out): void {
        $roles = (array)($out['token']['roles'] ?? []); $app = (array)($out['app'] ?? []);
        foreach ((array)($out['checks'] ?? []) as $i => $r) {
            if (!empty($r['ok']) || !in_array((int)($r['status'] ?? 0), [401, 403], true)) continue;
            [$state, $why] = itops_graph_why_refused((string)($r['permission'] ?? ''), $roles, $app);
            $out['checks'][$i]['state'] = $state; $out['checks'][$i]['why'] = $why;
            if ($state === 'no_consent' && !empty($app['pending'])) continue;   // already covered by the one "admin consent is missing" line
            $out['advice'][] = $r['label'] . ': ' . ($why !== '' ? $why : 'the token lacks "' . $r['permission'] . '" — add it as an APPLICATION permission and Grant admin consent, then Refresh token.');
        }
    }
}
