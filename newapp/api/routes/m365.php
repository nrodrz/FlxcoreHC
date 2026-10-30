<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Microsoft 365 (read-only) surface.
 * Routes: /api/m365/{sub}. Everything here is view-only; nothing is written to
 * the tenant. Gated by the 'm365' permission module.
 *
 * Provided by the ITOps M365 + Guardian package. Requires api/lib/Graph.php.
 */
require_once __DIR__ . '/../lib/Graph.php';
itops_require($currentUser, 'm365', 'view');
// BT72: every read here is kept on the server and served at once (an older copy too, while a new read is made).
// Not kept: Diagnose, what ITOps keeps itself, and the scans a person starts with a button (they always read now).
require_once __DIR__ . '/../lib/ReadStore.php';
itops_rs_drop_on_change();
if ($method === 'GET' && !in_array((string)$id, ['diagnose', 'permissions', 'mailsec-evidence', 'policy-backups', 'mailbox-rules', 'copilot-adoption', 'coverage', 'license-report'], true)) itops_rs_begin('m365', (string)$id);

// BT125: the permissions the app registration needs, with what the tenant granted (read from the app's token).
// Any Microsoft 365 viewer may read the list; it holds no secret.
// BT145: side by side with what Microsoft 365 has for this app registration (consented, only added, not added; extras)
if ($id === 'permissions' && $method === 'GET' && (string)($segments[2] ?? '') === 'compare') {
    require_once __DIR__ . '/../lib/M365Permissions.php'; require_once __DIR__ . '/../lib/M365Hub.php';
    if (!itops_graph_configured()) json_response(['configured' => false]);
    $roles = null; try { $cl = itops_graph_jwt_claims(itops_graph_token()); $roles = is_array($cl['roles'] ?? null) ? array_values($cl['roles']) : []; } catch (Throwable $e) {}
    $cfg = itops_graph_cfg();
    json_response(['configured' => true, 'read_at' => date('Y-m-d H:i:s')] + itops_m365_permission_compare($roles, (string)($cfg['client'] ?? '')));
}
if ($id === 'permissions' && $method === 'GET') {
    require_once __DIR__ . '/../lib/M365Permissions.php';
    $roles = null; $err = '';
    if (itops_graph_configured()) { try { $cl = itops_graph_jwt_claims(itops_graph_token()); $roles = is_array($cl['roles'] ?? null) ? array_values($cl['roles']) : []; } catch (Throwable $e) { $err = 'The app\'s token could not be read: ' . $e->getMessage(); } }
    json_response(['configured' => itops_graph_configured(), 'read' => $roles !== null, 'error' => $err, 'permissions' => itops_m365_permission_status($roles)]);
}
if (!itops_graph_configured() && $id !== 'diagnose') {
    json_response(['configured' => false,
        'note' => 'Microsoft 365 is not configured. Confirm the O365 tenant/client/secret under '
                 .'Connection settings, and grant the app-only Graph permissions (see INSTALL.md).']);
}

$sub = $id; // /api/m365/{sub}
// BT124 (code review M365A-6): an answer built from a list Microsoft cut at the page limit says so ("truncated"), and the
// screens warn that what is missing may only be missing from the read
$GLOBALS['ITOPS_GRAPH_TRUNCATED'] = false;
$m365J = function ($a) { if (!empty($GLOBALS['ITOPS_GRAPH_TRUNCATED'])) { header('X-ITOps-Truncated: 1'); if (is_array($a) && array_keys($a) !== range(0, count($a) - 1)) $a['truncated'] = true; } json_response($a); };

/** Escape a value for an OData string literal. */
function itops_odata_str(string $v): string { return str_replace("'", "''", $v); }

/* itops_graph_paged() lives in lib/Graph.php (shared with m365_admin). */

try {
    switch ($sub) {

        case 'diagnose': {
            // Super-admin only: shows what the token actually carries + live capability tests.
            $isSuper = (($currentUser['role_slug'] ?? $currentUser['role'] ?? '') === 'super_admin');
            if (!$isSuper) error_response('Super admin only', 403);
            $fresh = false;
            if ($method === 'POST') {
                $b = json_decode(file_get_contents('php://input'), true) ?: [];
                if (($b['action'] ?? '') === 'clear_token') { itops_graph_token_clear(); $fresh = true; }
                if (function_exists('audit_log')) audit_log('m365_diagnose', 'integrations', null, $fresh ? 'token cleared + re-tested' : 'tested');
            }
            require_once __DIR__ . '/../lib/M365Hub.php';   // BT34: Diagnose lists the change permissions found in the token
            json_response(itops_graph_diagnose($fresh));
            break;
        }

        case 'users': {
            // Server-side search (?q=) — never pull the whole directory to filter in PHP.
            $top = min(500, max(1, (int)($_GET['top'] ?? 100)));
            $sel = '$select=id,displayName,userPrincipalName,mail,accountEnabled,jobTitle,department';
            $q = trim((string)($_GET['q'] ?? ''));
            $path = 'users?$top=' . $top . '&' . $sel;
            if ($q !== '') {
                $e = itops_odata_str($q);
                $path .= '&$filter=' . rawurlencode("startswith(displayName,'$e') or startswith(userPrincipalName,'$e') or startswith(mail,'$e')");
            }
            $r = itops_graph_get($path);
            $m365J(['configured' => true, 'value' => $r['value'] ?? []]);
            break;
        }

        case 'user': {
            $upn = trim($_GET['upn'] ?? '');
            if ($upn === '') error_response('A user id or userPrincipalName is required', 400);
            $sel = 'id,displayName,userPrincipalName,mail,accountEnabled,jobTitle,department,officeLocation,mobilePhone,createdDateTime';
            $u = null;
            try {   // sign-in activity needs AuditLog.Read.All + Entra ID P1 — fall back without it
                $u = itops_graph_get('users/'.rawurlencode($upn).'?$select='.$sel.',signInActivity');
            } catch (Throwable $e) { $u = itops_graph_get('users/'.rawurlencode($upn).'?$select='.$sel); }
            $lic = itops_graph_get('users/'.rawurlencode($upn).'/licenseDetails');
            $m365J(['configured' => true, 'user' => $u, 'licenses' => $lic['value'] ?? [],
                'last_signin' => $u['signInActivity']['lastSignInDateTime'] ?? null]);
            break;
        }

        case 'skus': {
            $r = itops_graph_get('subscribedSkus');
            $out = [];
            foreach (($r['value'] ?? []) as $s) {
                $enabled  = (int)($s['prepaidUnits']['enabled'] ?? 0);
                $consumed = (int)($s['consumedUnits'] ?? 0);
                $out[] = [
                    'skuId'         => $s['skuId'] ?? '',
                    'skuPartNumber' => $s['skuPartNumber'] ?? '',
                    'enabled'       => $enabled,
                    'consumed'      => $consumed,
                    'available'     => $enabled - $consumed,
                ];
            }
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'groups': {
            // Security/M365 groups for the onboarding template picker. ?q= searches
            // SERVER-SIDE (startswith) — big tenants must not be pulled whole and filtered here.
            $q = trim((string)($_GET['q'] ?? ''));
            $path = "groups?\$select=id,displayName,securityEnabled,mailEnabled,groupTypes&\$top=200";
            if ($q !== '') $path .= '&$filter=' . rawurlencode("startswith(displayName,'" . itops_odata_str($q) . "')");
            else $path .= '&$orderby=displayName';   // Graph dislikes $orderby combined with this $filter
            $r = itops_graph_get($path);
            $out = [];
            foreach (($r['value'] ?? []) as $g) {
                $out[] = [
                    'id'       => $g['id'] ?? '',
                    'name'     => $g['displayName'] ?? ($g['id'] ?? ''),
                    'security' => !empty($g['securityEnabled']),
                    'mail'     => !empty($g['mailEnabled']),
                ];
            }
            usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'user-fields': {
            // Catalog of Entra properties an onboarding template may set (drives the template editor).
            $out = [];
            foreach (itops_graph_settable_fields() as $k => $label) $out[] = ['key' => $k, 'label' => $label];
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'sku-users': {
            // Who holds a given license (SKU)?
            $sku = trim((string)($_GET['skuId'] ?? ''));
            if ($sku === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $sku)) error_response('A license (skuId) is required', 400);
            $rows = itops_graph_paged('users?$top=500&$select=id,displayName,userPrincipalName,accountEnabled,jobTitle'
                . '&$filter=' . rawurlencode("assignedLicenses/any(x:x/skuId eq $sku)"), 4);
            usort($rows, fn($a2, $b2) => strcasecmp($a2['displayName'] ?? '', $b2['displayName'] ?? ''));
            $m365J(['configured' => true, 'value' => $rows]);
            break;
        }

        case 'license-report': {
            // BT124 (code review M365A-4): the older reclaim report read part of a large directory and called "unknown" idle.
            // The one report is Microsoft 365 → Licenses → Reclaim (m365hub license-report).
            error_response('This report moved to /api/m365hub/license-report (Microsoft 365 → Licenses → Reclaim).', 410);
        }
        case 'license-report-old': {
            // Reclamation report: licensed accounts that are DISABLED, and (when sign-in data is
            // available) licensed accounts IDLE for ?days= (default 45). Degrades gracefully
            // without Entra ID P1 / AuditLog.Read.All — the disabled check always works.
            $days = min(365, max(7, (int)($_GET['days'] ?? 45)));
            $sel = 'id,displayName,userPrincipalName,accountEnabled,assignedLicenses';
            $signins = true; $rows = [];
            try { $rows = itops_graph_paged('users?$top=500&$select=' . $sel . ',signInActivity', 4); }
            catch (Throwable $e) { $signins = false; $rows = itops_graph_paged('users?$top=500&$select=' . $sel, 4); }
            $skuName = [];
            try { foreach ((itops_graph_get('subscribedSkus')['value'] ?? []) as $k2) $skuName[$k2['skuId'] ?? ''] = $k2['skuPartNumber'] ?? ''; } catch (Throwable $e) {}
            $cut = time() - $days * 86400; $out = []; $seats = 0;
            foreach ($rows as $u) {
                $lics = array_values(array_filter(array_column($u['assignedLicenses'] ?? [], 'skuId')));
                if (!$lics) continue;
                $last = $u['signInActivity']['lastSignInDateTime'] ?? null;
                $lastTs = $last ? strtotime($last) : null;
                $flag = '';
                if (empty($u['accountEnabled'])) $flag = 'disabled';
                elseif ($signins && ($lastTs === null || $lastTs < $cut)) $flag = 'idle';
                if ($flag === '') continue;
                $seats += count($lics);
                $out[] = ['id' => $u['id'] ?? '', 'displayName' => $u['displayName'] ?? '',
                    'userPrincipalName' => $u['userPrincipalName'] ?? '', 'flag' => $flag,
                    'last_signin' => $last, 'licenses' => array_map(fn($x) => $skuName[$x] ?? $x, $lics)];
            }
            usort($out, fn($a2, $b2) => strcmp($a2['flag'], $b2['flag']) ?: strcasecmp($a2['displayName'], $b2['displayName']));
            $m365J(['configured' => true, 'value' => $out, 'signins_available' => $signins,
                'days' => $days, 'reclaimable_seats' => $seats,
                'note' => $signins ? '' : 'Idle detection needs the AuditLog.Read.All permission and Entra ID P1 — only disabled-but-licensed accounts are shown.']);
            break;
        }

        case 'mfa-report': {
            // Tenant MFA registration (reports/authenticationMethods/userRegistrationDetails).
            try {
                $rows = itops_graph_paged('reports/authenticationMethods/userRegistrationDetails?$top=500', 4);
            } catch (Throwable $e) {
                $m365J(['configured' => true, 'value' => [], 'needs_permission' => true,
                    'error' => 'Microsoft returned: ' . $e->getMessage()]);
                break;
            }
            $out = []; $reg = 0; $adminsNo = 0;
            foreach ($rows as $r2) {
                $is = !empty($r2['isMfaRegistered']);
                if ($is) $reg++;
                $adm = !empty($r2['isAdmin']);
                if (!$is && $adm) $adminsNo++;
                $out[] = ['id' => $r2['id'] ?? '', 'userPrincipalName' => $r2['userPrincipalName'] ?? '',
                    'displayName' => $r2['userDisplayName'] ?? '', 'registered' => $is, 'admin' => $adm,
                    'capable' => !empty($r2['isMfaCapable']),
                    'methods' => array_values($r2['methodsRegistered'] ?? [])];
            }
            usort($out, fn($a2, $b2) => ($a2['registered'] <=> $b2['registered']) ?: ($b2['admin'] <=> $a2['admin']) ?: strcasecmp($a2['displayName'], $b2['displayName']));
            $m365J(['configured' => true, 'value' => $out,
                'summary' => ['total' => count($out), 'registered' => $reg, 'unregistered_admins' => $adminsNo]]);
            break;
        }

        case 'app-secrets': {
            // App-registration secret / certificate expiry — the classic "everything broke because
            // a client secret silently expired" early warning.
            try {
                $apps = itops_graph_paged('applications?$top=100&$select=id,appId,displayName,passwordCredentials,keyCredentials', 3);
            } catch (Throwable $e) {
                $m365J(['configured' => true, 'value' => [], 'needs_permission' => true,
                    'error' => 'Microsoft returned: ' . $e->getMessage()]);
                break;
            }
            $out = []; $now = time();
            foreach ($apps as $a2) {
                foreach ([['passwordCredentials', 'secret'], ['keyCredentials', 'certificate']] as [$k2, $kind]) {
                    foreach (($a2[$k2] ?? []) as $c2) {
                        $end = $c2['endDateTime'] ?? null; if (!$end) continue;
                        $ts = strtotime($end); $daysLeft = $ts ? (int)floor(($ts - $now) / 86400) : null;
                        $out[] = ['app' => $a2['displayName'] ?? ($a2['appId'] ?? ''), 'appId' => $a2['appId'] ?? '',
                            'kind' => $kind, 'name' => $c2['displayName'] ?? '', 'expires' => $end,
                            'days_left' => $daysLeft,
                            'state' => $daysLeft === null ? 'unknown' : ($daysLeft < 0 ? 'expired' : ($daysLeft <= 30 ? 'expiring' : 'ok'))];
                    }
                }
            }
            usort($out, fn($x, $y) => ($x['days_left'] ?? 99999) <=> ($y['days_left'] ?? 99999));
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'admin-roles': {
            // Who holds privileged directory roles (read-only inventory; assignment stays in Azure).
            $roles = itops_graph_get('directoryRoles?$select=id,displayName,description');
            $out = [];
            foreach (($roles['value'] ?? []) as $r2) {
                $rid = $r2['id'] ?? ''; if ($rid === '') continue;
                $members = [];
                try {
                    $m2 = itops_graph_get('directoryRoles/' . rawurlencode($rid) . '/members?$select=id,displayName,userPrincipalName,accountEnabled');
                    foreach (($m2['value'] ?? []) as $mm) $members[] = ['displayName' => $mm['displayName'] ?? '', 'userPrincipalName' => $mm['userPrincipalName'] ?? '', 'enabled' => !empty($mm['accountEnabled'])];
                } catch (Throwable $e) {}
                if ($members) $out[] = ['role' => $r2['displayName'] ?? '', 'description' => $r2['description'] ?? '', 'members' => $members];
            }
            usort($out, fn($x, $y) => strcasecmp($x['role'], $y['role']));
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'email-dns': {
            // SPF / DKIM / DMARC per verified domain — pure DNS, no Graph permission needed.
            require_once __DIR__ . '/../lib/EmailSecurity.php';
            $r = itops_graph_get('domains?$select=id,isDefault,isVerified,supportedServices');
            $out = [];
            foreach (($r['value'] ?? []) as $d2) {
                $name = strtolower((string)($d2['id'] ?? ''));
                if ($name === '' || empty($d2['isVerified'])) continue;
                $skip = itops_mailsec_skip_reason($name, isset($d2['supportedServices']) ? (array)$d2['supportedServices'] : null);
                if ($skip !== '') { $out[] = ['domain' => $name, 'managed' => true, 'note' => $skip, 'level' => 'ok']; continue; }
                $row = itops_mailsec_domain($name); $row['default'] = !empty($d2['isDefault']);
                $out[] = $row;
            }
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'mailbox-rules': {
            // Compromised-mailbox audit: inbox rules that forward/redirect OUTSIDE your domains,
            // or silently delete mail. Needs MailboxSettings.Read (Application). Bounded scan.
            // Review M365A-19: reading every mailbox's rules is a security task (Microsoft 365 · Tenant configuration), with a time budget
            if (!itops_can($currentUser, 'm365_admin', 'view') && ($currentUser['role_slug'] ?? '') !== 'super_admin') { itops_refusal_log($currentUser, 'm365_admin', 'view', 'mailbox rule scan'); error_response('The mailbox rule scan needs Microsoft 365 · Tenant configuration.', 403); }
            @set_time_limit(180); $mrT0 = time();
            $max = min(500, max(10, (int)($_GET['max'] ?? 150)));
            $doms = [];
            try { foreach ((itops_graph_get('domains?$select=id,isVerified')['value'] ?? []) as $d2) if (!empty($d2['isVerified'])) $doms[] = strtolower((string)$d2['id']); } catch (Throwable $e) {}
            $users = itops_graph_paged('users?$top=500&$select=id,displayName,userPrincipalName,accountEnabled,assignedLicenses', 4);
            $users = array_values(array_filter($users, fn($u) => !empty($u['accountEnabled']) && !empty($u['assignedLicenses'])));
            $scanned = 0; $skipped = 0; $rows = []; $permErr = '';
            foreach (array_slice($users, 0, $max) as $u) {
                if (time() - $mrT0 > 150) { $skipped++; continue; }   // out of time: the rest is counted as skipped
                try {
                    $rr = itops_graph_get('users/' . rawurlencode((string)$u['id']) . '/mailFolders/inbox/messageRules');
                    $scanned++;
                } catch (Throwable $e) {
                    $m2 = $e->getMessage(); $skipped++;
                    if (strpos($m2, '403') !== false || stripos($m2, 'denied') !== false) { $permErr = $m2; break; }
                    continue;   // no mailbox / not provisioned — normal
                }
                foreach (($rr['value'] ?? []) as $ru) {
                    $ac = $ru['actions'] ?? [];
                    $targets = [];
                    foreach (['forwardTo', 'forwardAsAttachmentTo', 'redirectTo'] as $k3)
                        foreach (($ac[$k3] ?? []) as $t2) { $ad = strtolower((string)($t2['emailAddress']['address'] ?? '')); if ($ad !== '') $targets[] = $ad; }
                    $ext = array_values(array_filter($targets, function ($ad) use ($doms) {
                        $d3 = substr(strrchr($ad, '@') ?: '', 1); return $d3 !== '' && !in_array($d3, $doms, true);
                    }));
                    $del = !empty($ac['delete']) || !empty($ac['permanentDelete']);
                    $kind = $ext ? 'external_forward' : ($del ? 'deletes_mail' : '');
                    if ($kind === '') continue;
                    $rows[] = ['user' => $u['displayName'] ?? '', 'upn' => $u['userPrincipalName'] ?? '', 'user_id' => $u['id'] ?? '',
                        'rule_id' => $ru['id'] ?? '', 'rule' => $ru['displayName'] ?? '(unnamed rule)',
                        'enabled' => !empty($ru['isEnabled']), 'kind' => $kind, 'targets' => $ext];
                }
            }
            $sev2 = ['external_forward' => 0, 'deletes_mail' => 1];
            usort($rows, fn($x, $y) => ($sev2[$x['kind']] <=> $sev2[$y['kind']]) ?: strcasecmp($x['user'], $y['user']));
            $m365J(['configured' => true, 'value' => $rows, 'scanned' => $scanned, 'skipped' => $skipped,
                'total_candidates' => count($users), 'max' => $max,
                'needs_permission' => $permErr !== '', 'error' => $permErr !== '' ? 'Microsoft returned: ' . $permErr : '']);
            break;
        }

        case 'ca-policies': {
            // Conditional Access inventory + the two checks that matter most.
            try { $r = itops_graph_get('identity/conditionalAccess/policies'); }
            catch (Throwable $e) { $m365J(['configured' => true, 'value' => [], 'needs_permission' => true, 'error' => 'Microsoft returned: ' . $e->getMessage()]); break; }
            $out = []; $mfaAll = false; $legacyBlocked = false;
            foreach (($r['value'] ?? []) as $p2) {
                $ctrl = $p2['grantControls']['builtInControls'] ?? [];
                if (!empty($p2['sessionControls']['applicationEnforcedRestrictions']['isEnabled'])) $ctrl[] = 'browser-only (app-enforced restrictions)';
                if (!empty($p2['sessionControls']['signInFrequency']['isEnabled'])) $ctrl[] = 'sign-in frequency';
                $usersInc = $p2['conditions']['users']['includeUsers'] ?? [];
                $capps = $p2['conditions']['clientAppTypes'] ?? [];
                $on = ($p2['state'] ?? '') === 'enabled';
                if ($on && in_array('mfa', $ctrl, true) && in_array('All', $usersInc, true)) $mfaAll = true;
                if ($on && in_array('block', $ctrl, true) && (in_array('exchangeActiveSync', $capps, true) || in_array('other', $capps, true))) $legacyBlocked = true;
                $out[] = ['id' => $p2['id'] ?? '', 'name' => $p2['displayName'] ?? '', 'state' => $p2['state'] ?? '',
                    'controls' => $ctrl, 'client_apps' => $capps, 'all_users' => in_array('All', $usersInc, true)];
            }
            $secDefaults = null;
            try { $sd = itops_graph_get('policies/identitySecurityDefaultsEnforcementPolicy'); $secDefaults = !empty($sd['isEnabled']); } catch (Throwable $e) {}
            $m365J(['configured' => true, 'value' => $out,
                'checks' => ['mfa_all_users' => $mfaAll, 'legacy_auth_blocked' => $legacyBlocked, 'security_defaults' => $secDefaults]]);
            break;
        }

        case 'ca-policy': {
            // BT33: one Conditional Access policy in plain words (read-only). Names are resolved where the app may read them.
            require_once __DIR__ . '/../lib/CaDetail.php';
            require_once __DIR__ . '/../lib/M365Hub.php';
            $pid = (string)($_GET['id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $pid)) error_response('That policy id is not valid.', 400);
            try { $p2 = itops_graph_get('identity/conditionalAccess/policies/' . $pid); }
            catch (Throwable $e) { $m365J(['configured' => true, 'needs_permission' => true, 'error' => 'Microsoft returned: ' . $e->getMessage()]); break; }
            $ids = itops_ca_ids($p2);
            $names = ['users' => [], 'groups' => [], 'roles' => [], 'apps' => [], 'locations' => []];
            $safe = fn($x) => (bool)preg_match('/^[A-Za-z0-9-]{1,64}$/', (string)$x);
            $reqs = [];
            foreach (array_filter($ids['users'], $safe) as $x) $reqs['users|' . $x] = ['url' => "users/{$x}?\$select=displayName,userPrincipalName"];
            foreach (array_filter($ids['groups'], $safe) as $x) $reqs['groups|' . $x] = ['url' => "groups/{$x}?\$select=displayName"];
            foreach (array_filter($ids['apps'], $safe) as $x) $reqs['apps|' . $x] = ['url' => "servicePrincipals(appId='{$x}')?\$select=displayName"];
            if ($reqs) {
                try {
                    foreach ((new ItopsGraphClient())->batch($reqs) as $k => $r2) {
                        if ($r2['status'] !== 200 || !is_array($r2['body'])) continue;
                        [$kind, $oid] = explode('|', (string)$k, 2);
                        $dn = (string)($r2['body']['displayName'] ?? '');
                        if ($kind === 'users' && $dn === '') $dn = (string)($r2['body']['userPrincipalName'] ?? '');
                        if ($dn !== '') $names[$kind][$oid] = $dn;
                    }
                } catch (Throwable $e) { /* names stay unresolved: the description says so */ }
            }
            if ($ids['roles']) { try { foreach (ItopsM365::fromConfig()->listRoleDefinitions() as $rd) $names['roles'][$rd['id']] = $rd['name']; } catch (Throwable $e) {} }
            if ($ids['locations']) { try { foreach ((itops_graph_get('identity/conditionalAccess/namedLocations')['value'] ?? []) as $nl) $names['locations'][(string)($nl['id'] ?? '')] = (string)($nl['displayName'] ?? ''); } catch (Throwable $e) {} }
            $m365J(['configured' => true, 'policy' => itops_ca_describe($p2, $names)]);
            break;
        }

        case 'secure-actions': {
            // Microsoft's own ranked "do these next" list, from the latest Secure Score snapshot.
            try { $sc = itops_graph_get('security/secureScores?$top=1'); }
            catch (Throwable $e) { $m365J(['configured' => true, 'value' => [], 'needs_permission' => true, 'error' => 'Microsoft returned: ' . $e->getMessage()]); break; }
            $row = $sc['value'][0] ?? [];
            $ctl = [];
            foreach (($row['controlScores'] ?? []) as $c2) {
                $ctl[] = ['category' => $c2['controlCategory'] ?? '', 'name' => $c2['controlName'] ?? '',
                    'description' => mb_substr((string)($c2['description'] ?? ''), 0, 300), 'score' => (float)($c2['score'] ?? 0)];
            }
            usort($ctl, fn($x, $y) => $x['score'] <=> $y['score']);
            $m365J(['configured' => true, 'value' => array_slice($ctl, 0, 10)]);
            break;
        }

        case 'app-protection': {
            // MAM policies (app protection without enrolment) — the BYOD layer for personal phones.
            try {
                $ios = itops_graph_get('deviceAppManagement/iosManagedAppProtections')['value'] ?? [];
                $and = itops_graph_get('deviceAppManagement/androidManagedAppProtections')['value'] ?? [];
            } catch (Throwable $e) {
                $m365J(['configured' => true, 'value' => [], 'needs_permission' => true, 'error' => 'Microsoft returned: ' . $e->getMessage()]);
                break;
            }
            $out = [];
            foreach ([['iOS / iPadOS', $ios], ['Android', $and]] as [$plat, $list]) foreach ($list as $p2)
                $out[] = ['id' => $p2['id'] ?? '', 'platform' => $plat, 'name' => $p2['displayName'] ?? '',
                    'pin' => !empty($p2['pinRequired']), 'backup_blocked' => !empty($p2['dataBackupBlocked']),
                    'pin_length' => (int)($p2['minimumPinLength'] ?? 4), 'save_as_blocked' => !empty($p2['saveAsBlocked']),
                    'print_blocked' => !empty($p2['printBlocked']), 'clipboard' => (string)($p2['allowedOutboundClipboardSharingLevel'] ?? ''),
                    'wipe_after_days' => preg_match('/^P(\d+)D$/', (string)($p2['periodOfflineBeforeWipeIsEnforced'] ?? ''), $wm) ? (int)$wm[1] : 90,
                    'modified' => $p2['lastModifiedDateTime'] ?? ''];
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'policies-all': {
            // Every Intune policy of every type, with assignments and device status (BT12).
            require_once __DIR__ . '/../lib/IntunePolicies.php';
            $inv = itops_ipol_inventory();
            $m365J(['configured' => true, 'value' => $inv['value'], 'errors' => $inv['errors']]);
            break;
        }

        case 'policy-backups': {
            // Stored backups (newest first), without the payloads.
            try {
                $rows = getDB()->query("SELECT b.id,b.batch,b.kind,b.policy_id,b.name,b.platform,b.created_at,u.display_name AS by_name
                    FROM itops_m365_policy_backups b LEFT JOIN itops_users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 500")->fetchAll();
            } catch (Throwable $e) { $m365J(['configured' => true, 'value' => [], 'needs_migration' => true]); break; }
            $m365J(['configured' => true, 'value' => $rows]);
            break;
        }

        case 'rollout': {
            // Business Premium rollout: checklist state + per-person readiness (MFA / company device / phone apps).
            $st = [];
            try { $v = getDB()->query("SELECT sval FROM itops_settings WHERE skey='m365_rollout'")->fetchColumn(); $st = $v ? (json_decode($v, true) ?: []) : []; } catch (Throwable $e) {}
            $bg = [];
            try { $v = getDB()->query("SELECT sval FROM itops_settings WHERE skey='sa_emergency'")->fetchColumn(); $bg = $v ? (json_decode($v, true) ?: []) : []; } catch (Throwable $e) {}
            $out = ['configured' => true, 'steps' => $st['steps'] ?? new stdClass(), 'breakglass' => $bg, 'readiness' => null];
            if (!empty($_GET['readiness'])) {
                $users = itops_graph_paged('users?$top=500&$select=id,displayName,userPrincipalName,accountEnabled,assignedLicenses,department', 4);
                $users = array_values(array_filter($users, fn($u) => !empty($u['accountEnabled']) && !empty($u['assignedLicenses'])));
                $mfa = null; $dev = null; $mam = null; $notes = [];
                $how = [];   // strongest registered method per person: key > app > text/call
                try { $mfa = []; foreach (itops_graph_paged('reports/authenticationMethods/userRegistrationDetails?$top=500', 4) as $r2) {
                        $k2 = strtolower((string)($r2['userPrincipalName'] ?? '')); $mfa[$k2] = !empty($r2['isMfaRegistered']);
                        $ms = array_map('strtolower', (array)($r2['methodsRegistered'] ?? []));
                        $how[$k2] = array_intersect($ms, ['fido2', 'passkeydevicebound', 'passkeydeviceboundauthenticator', 'windowshelloforbusiness']) ? 'key'
                            : (array_intersect($ms, ['microsoftauthenticatorpush', 'microsoftauthenticatorpasswordless', 'softwareonetimepasscode']) ? 'app'
                            : (array_intersect($ms, ['mobilephone', 'alternatemobilephone', 'officephone']) ? 'phone' : '')); } }
                catch (Throwable $e) { $mfa = null; $notes[] = 'MFA status needs AuditLog.Read.All.'; }
                try { $dev = []; foreach (itops_graph_paged('deviceManagement/managedDevices?$top=200&$select=userPrincipalName,operatingSystem,managedDeviceOwnerType,complianceState', 3) as $d2) {
                        $u2 = strtolower((string)($d2['userPrincipalName'] ?? '')); if ($u2 === '') continue;
                        $os = strtolower((string)($d2['operatingSystem'] ?? ''));
                        $isPc = strpos($os, 'windows') !== false || strpos($os, 'mac') !== false;
                        if ($isPc) { $dev[$u2]['pc'] = ($dev[$u2]['pc'] ?? 0) + 1; if (strtolower((string)($d2['complianceState'] ?? '')) !== 'compliant') $dev[$u2]['bad'] = true; }
                    } }
                catch (Throwable $e) { $dev = null; $notes[] = 'Company devices need DeviceManagementManagedDevices.Read.All.'; }
                try { $mam = []; foreach (itops_graph_paged('deviceAppManagement/managedAppRegistrations?$top=500&$select=userId', 3) as $m2) if (!empty($m2['userId'])) $mam[$m2['userId']] = true; }
                catch (Throwable $e) { $mam = null; $notes[] = 'Phone app status needs DeviceManagementApps.Read.All.'; }
                $rows = []; $sum = ['people' => 0, 'mfa' => 0, 'pc' => 0, 'phone' => 0, 'key' => 0, 'app' => 0, 'text' => 0];
                foreach ($users as $u) {
                    $upn = strtolower((string)$u['userPrincipalName']);
                    $r3 = ['id' => $u['id'], 'name' => $u['displayName'] ?? '', 'upn' => $u['userPrincipalName'], 'department' => $u['department'] ?? '',
                        'mfa' => $mfa === null ? null : !empty($mfa[$upn]), 'mfa_how' => $how[$upn] ?? '',
                        'pc' => $dev === null ? null : (int)($dev[$upn]['pc'] ?? 0), 'pc_noncompliant' => $dev !== null && !empty($dev[$upn]['bad']),
                        'phone' => $mam === null ? null : !empty($mam[$u['id']])];
                    $sum['people']++; if ($r3['mfa']) $sum['mfa']++; if ($r3['pc']) $sum['pc']++; if ($r3['phone']) $sum['phone']++;
                    if ($r3['mfa_how'] === 'key') $sum['key']++; elseif ($r3['mfa_how'] === 'app') $sum['app']++; elseif ($r3['mfa_how'] === 'phone') $sum['text']++;
                    $rows[] = $r3;
                }
                usort($rows, fn($a2, $b2) => ((int)!!$a2['mfa'] <=> (int)!!$b2['mfa']) ?: strcasecmp($a2['name'], $b2['name']));
                $out['readiness'] = ['value' => $rows, 'summary' => $sum, 'notes' => $notes];
            }
            $m365J($out);
            break;
        }

        case 'mailsec-evidence': {
            // BT14: Email Security controls that only Exchange Online PowerShell can see — the admin records the
            // assessment result here (who / when / what was found). History lives in the audit log.
            $v = null;
            try { $v = getDB()->query("SELECT sval FROM itops_settings WHERE skey='mailsec_evidence'")->fetchColumn(); } catch (Throwable $e) {}
            $m365J(['configured' => true, 'value' => $v ? (json_decode($v, true) ?: new stdClass()) : new stdClass()]);
            break;
        }

        case 'auth-methods': {
            // Tenant sign-in methods policy (which MFA methods people may use). BT13.
            $META = itops_auth_method_meta();
            try { $pol = itops_graph_get('policies/authenticationMethodsPolicy'); }
            catch (Throwable $e) { $m365J(['configured' => true, 'value' => [], 'needs_permission' => true, 'error' => 'Microsoft returned: ' . $e->getMessage()]); break; }
            $cfgs = $pol['authenticationMethodConfigurations'] ?? null;
            if (!is_array($cfgs) || !$cfgs) {
                $cfgs = [];
                foreach (array_keys($META) as $mid) { try { $cfgs[] = itops_graph_get('policies/authenticationMethodsPolicy/authenticationMethodConfigurations/' . $mid); } catch (Throwable $e) {} }
            }
            $out = [];
            foreach ($cfgs as $c) {
                $id = (string)($c['id'] ?? ''); if ($id === '') continue;
                $m = $META[$id] ?? [$id, '', 'other'];
                $tg = [];
                foreach (($c['includeTargets'] ?? []) as $t) $tg[] = ($t['id'] ?? '') === 'all_users' ? 'All users' : 'a group';
                $out[] = ['id' => $id, 'name' => $m[0], 'about' => $m[1], 'advice' => $m[2], 'state' => (string)($c['state'] ?? ''),
                    'targets' => $tg ? implode(', ', array_unique($tg)) : 'nobody', 'excluded' => count($c['excludeTargets'] ?? [])];
            }
            $order = array_flip(array_keys($META));
            usort($out, fn($a, $b) => ($order[$a['id']] ?? 99) <=> ($order[$b['id']] ?? 99));
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'domains': {
            $r = itops_graph_get('domains?$select=id,isDefault,isVerified');
            $out = [];
            foreach (($r['value'] ?? []) as $d) {
                $out[] = ['id' => $d['id'] ?? '', 'default' => !empty($d['isDefault']), 'verified' => !empty($d['isVerified'])];
            }
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'service-health': {
            $r = itops_graph_get('admin/serviceAnnouncement/healthOverviews');
            $m365J(['configured' => true, 'value' => $r['value'] ?? []]);
            break;
        }

        case 'service-issues': {
            $r = itops_graph_get('admin/serviceAnnouncement/issues?$top=25');
            $m365J(['configured' => true, 'value' => $r['value'] ?? []]);
            break;
        }

        case 'exchange': {
            json_response(itops_graph_report_safe('getMailboxUsageDetail', $_GET['period'] ?? 'D7'));
            break;
        }

        case 'teams': {
            json_response(itops_graph_report_safe('getTeamsUserActivityUserDetail', $_GET['period'] ?? 'D7'));
            break;
        }

        case 'storage': {
            json_response(itops_graph_report_safe('getOneDriveUsageAccountDetail', $_GET['period'] ?? 'D7'));
            break;
        }

        case 'copilot': {
            // Copilot adoption. If your tenant returns HTTP 404 for this report
            // name, see INSTALL.md — Microsoft has revised it over time.
            json_response(itops_graph_report_safe('getMicrosoft365CopilotUsageUserDetail', $_GET['period'] ?? 'D7'));
            break;
        }

        case 'intune-devices': {
            try {
                $rows = itops_graph_paged('deviceManagement/managedDevices?$top=200'
                    .'&$select=id,deviceName,userPrincipalName,userDisplayName,operatingSystem,osVersion,complianceState,lastSyncDateTime,model,manufacturer,isEncrypted,enrolledDateTime,managedDeviceOwnerType', 3);
            } catch (Throwable $e) {
                $m365J(['configured' => true, 'value' => [], 'needs_permission' => true, 'error' => 'Microsoft returned: ' . $e->getMessage()]);
                break;
            }
            // Non-compliant first, then by stalest sync.
            $rank = ['noncompliant' => 0, 'error' => 1, 'ingraceperiod' => 2, 'unknown' => 3, 'compliant' => 4];
            usort($rows, function ($a2, $b2) use ($rank) {
                $ra = $rank[strtolower((string)($a2['complianceState'] ?? 'unknown'))] ?? 3;
                $rb = $rank[strtolower((string)($b2['complianceState'] ?? 'unknown'))] ?? 3;
                return $ra <=> $rb ?: strcmp((string)($a2['lastSyncDateTime'] ?? ''), (string)($b2['lastSyncDateTime'] ?? ''));
            });
            $m365J(['configured' => true, 'value' => $rows]);
            break;
        }

        case 'intune-policies': {
            // Compliance policies (read-only): what the tenant requires of a device before it
            // counts as compliant. Editing policies stays in the Intune admin center.
            try {
                $r = itops_graph_get('deviceManagement/deviceCompliancePolicies?$expand=assignments,scheduledActionsForRule($expand=scheduledActionConfigurations)');
            } catch (Throwable $e) {
                $m365J(['configured' => true, 'value' => [], 'needs_permission' => true, 'error' => 'Microsoft returned: ' . $e->getMessage()]);
                break;
            }
            $out = [];
            foreach (($r['value'] ?? []) as $p2) {
                $t = (string)($p2['@odata.type'] ?? '');
                $plat = 'other';
                foreach (['windows10' => 'Windows', 'macOS' => 'macOS', 'ios' => 'iOS / iPadOS', 'android' => 'Android'] as $k2 => $lbl) if (stripos($t, $k2) !== false) { $plat = $lbl; break; }
                $grace = null;
                foreach (($p2['scheduledActionsForRule'] ?? []) as $ru) foreach (($ru['scheduledActionConfigurations'] ?? []) as $ac)
                    if (($ac['actionType'] ?? '') === 'block') $grace = (int)($ac['gracePeriodHours'] ?? 0);
                $asg = [];
                foreach (($p2['assignments'] ?? []) as $a3) {
                    $tt = (string)($a3['target']['@odata.type'] ?? '');
                    if (stripos($tt, 'allDevices') !== false) $asg[] = ['type' => 'all_devices', 'label' => 'All devices'];
                    elseif (stripos($tt, 'allLicensedUsers') !== false) $asg[] = ['type' => 'all_users', 'label' => 'All users'];
                    elseif (!empty($a3['target']['groupId'])) $asg[] = ['type' => 'group', 'id' => $a3['target']['groupId'], 'label' => 'Group'];
                }
                $S = [];   // curated settings the in-app editor understands
                foreach (['passwordRequired','passcodeRequired','passwordMinimumLength','passcodeMinimumLength','osMinimumVersion',
                          'bitLockerEnabled','storageRequireEncryption','defenderEnabled','securityBlockJailbrokenDevices',
                          'deviceThreatProtectionEnabled','deviceThreatProtectionRequiredSecurityLevel'] as $k3)
                    if (array_key_exists($k3, $p2)) $S[$k3] = $p2[$k3];
                $out[] = ['id' => $p2['id'] ?? '', 'name' => $p2['displayName'] ?? '', 'description' => $p2['description'] ?? '',
                          'platform' => $plat, 'modified' => $p2['lastModifiedDateTime'] ?? '',
                          'grace_hours' => $grace, 'assignments' => $asg, 'settings' => $S];
            }
            $m365J(['configured' => true, 'value' => $out]);
            break;
        }

        case 'device-security': {
            // BT15 — status of the ITOps device-security profiles + the enrolment guard-rail + Defender connector.
            require_once __DIR__ . '/../lib/DeviceSecurity.php';
            $out = ['configured' => true, 'items' => [], 'asr_mode' => null, 'defender_connected' => null, 'enrollment' => null, 'errors' => []];
            $have = [];
            try { $have = itops_devsec_existing(); } catch (Throwable $e) { $out['needs_permission'] = true; $out['errors'][] = 'Needs DeviceManagementConfiguration.Read.All — ' . mb_substr($e->getMessage(), 0, 160); }
            $gids = [];
            foreach ($have as $h) foreach ($h['assigned'] as $g) if (preg_match('/^[0-9a-f-]{36}$/i', $g)) $gids[$g] = true;
            $gn = [];
            foreach (array_slice(array_keys($gids), 0, 10) as $g) { try { $gn[$g] = (string)(itops_graph_get('groups/' . rawurlencode($g) . '?$select=displayName')['displayName'] ?? ''); } catch (Throwable $e) {} }
            foreach (itops_devsec_catalog() as $k => [$label, $kind, $what]) {
                $h = $have[$k] ?? null; $row = ['key' => $k, 'label' => $label, 'what' => $what, 'exists' => (bool)$h, 'id' => $h['id'] ?? '',
                    'groups' => $h ? array_map(fn($g) => $gn[$g] ?? ($g === 'all_devices' ? 'All devices' : ($g === 'all_users' ? 'All users' : $g)), $h['assigned']) : [], 'status' => null];
                if ($h && $h['kind'] === 'configuration') {
                    try { $o = itops_graph_get('deviceManagement/deviceConfigurations/' . rawurlencode($h['id']) . '/deviceStatusOverview');
                          $row['status'] = ['success' => (int)($o['successCount'] ?? 0), 'error' => (int)($o['errorCount'] ?? 0) + (int)($o['failedCount'] ?? 0),
                                            'pending' => (int)($o['pendingCount'] ?? 0), 'conflict' => (int)($o['conflictCount'] ?? 0)]; } catch (Throwable $e) {}
                }
                if ($k === 'asr' && $h) $out['asr_mode'] = $h['asr_mode'] ?? 'audit';
                $out['items'][] = $row;
            }
            $out['asr_rules'] = array_map(fn($r) => ['label' => $r[1], 'enforce' => $r[3] === false ? 'audit' : ($r[3] === 'warn' ? 'warn' : 'block')], itops_devsec_asr_rules());
            $out['defender_connected'] = itops_devsec_defender_connected();
            $out['enrollment'] = itops_devsec_enrollment();
            $m365J($out);
            break;
        }

        case 'defender': {
            // Defender for Business posture: Secure Score, active incidents, open alerts.
            // Each section degrades on its own — one missing permission never blanks the panel.
            $out = ['configured' => true, 'score' => null, 'incidents' => null, 'alerts' => null];
            try {
                $sc = itops_graph_get('security/secureScores?$top=1');
                $row = $sc['value'][0] ?? null;
                if ($row) {
                    $cur = (float)($row['currentScore'] ?? 0); $max = (float)($row['maxScore'] ?? 0);
                    $out['score'] = ['current' => $cur, 'max' => $max, 'pct' => $max > 0 ? round(100 * $cur / $max, 1) : 0,
                                     'as_of' => $row['createdDateTime'] ?? ''];
                }
            } catch (Throwable $e) { $out['score_error'] = $e->getMessage(); }
            try {
                $inc = itops_graph_get("security/incidents?\$top=25&\$filter=" . rawurlencode("status eq 'active' or status eq 'inProgress'"));   // BT119: and those being worked on
                // BT112: the incident list on Security → Alerts & actions (who has it, when it last changed, its page in Defender)
                $out['incidents'] = array_map(fn($i2) => ['id' => $i2['id'] ?? '', 'name' => $i2['displayName'] ?? '',
                    'severity' => strtolower((string)($i2['severity'] ?? '')), 'created' => $i2['createdDateTime'] ?? '',
                    'status' => $i2['status'] ?? '', 'assigned' => $i2['assignedTo'] ?? '', 'updated' => $i2['lastUpdateDateTime'] ?? '',
                    'classification' => $i2['classification'] ?? '', 'url' => $i2['incidentWebUrl'] ?? ''], $inc['value'] ?? []);
            } catch (Throwable $e) { $out['incidents_error'] = $e->getMessage(); }
            try {
                $al = itops_graph_get('security/alerts_v2?$top=50&$orderby=createdDateTime desc');
                $open = [];
                foreach (($al['value'] ?? []) as $a2) {
                    if (strtolower((string)($a2['status'] ?? '')) === 'resolved') continue;
                    $open[] = ['id' => $a2['id'] ?? '', 'title' => $a2['title'] ?? '', 'severity' => strtolower((string)($a2['severity'] ?? '')),
                        'status' => $a2['status'] ?? '', 'created' => $a2['createdDateTime'] ?? '',
                        'user' => $a2['evidence'][0]['userAccount']['userPrincipalName'] ?? '',
                        'incident' => (string)($a2['incidentId'] ?? ''), 'url' => $a2['alertWebUrl'] ?? '', 'source' => $a2['serviceSource'] ?? ''];
                }
                $out['alerts'] = array_slice($open, 0, 25);
            } catch (Throwable $e) { $out['alerts_error'] = $e->getMessage(); }
            $m365J($out);
            break;
        }

        case 'secdash': {
            // BT146: the security alerts dashboard (Security → Dashboard): alerts and incidents over 7, 30 or 90 days
            require_once __DIR__ . '/../lib/SecDash.php';
            $days = (int)($_GET['days'] ?? 30); if (!in_array($days, [7, 30, 90], true)) $days = 30;
            $since = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . $days . ' days'));
            $alerts = null; $aErr = ''; $incs = null; $iErr = '';
            try {
                $byId = [];
                foreach (itops_graph_paged('security/alerts_v2?$top=500&$filter=' . rawurlencode('createdDateTime ge ' . $since . ' or lastUpdateDateTime ge ' . $since), 6) as $a) $byId[(string)($a['id'] ?? '')] = $a;
                foreach (itops_graph_paged('security/alerts_v2?$top=500&$filter=' . rawurlencode("status eq 'new' or status eq 'inProgress'"), 4) as $a) $byId[(string)($a['id'] ?? '')] = $a;
                $alerts = array_values($byId);
            } catch (Throwable $e) { $aErr = preg_match('/403|denied|authoriz|forbidden/i', $e->getMessage()) ? 'Alerts need the Microsoft Graph permission SecurityAlert.Read.All.' : 'Alerts could not be read: ' . mb_substr($e->getMessage(), 0, 200); }
            try {
                $byId = [];
                foreach (itops_graph_paged('security/incidents?$top=50&$filter=' . rawurlencode('createdDateTime ge ' . $since), 6) as $i) $byId[(string)($i['id'] ?? '')] = $i;
                foreach (itops_graph_paged('security/incidents?$top=50&$filter=' . rawurlencode("status eq 'active' or status eq 'inProgress'"), 4) as $i) $byId[(string)($i['id'] ?? '')] = $i;
                $incs = array_values($byId);
            } catch (Throwable $e) { $iErr = preg_match('/403|denied|authoriz|forbidden/i', $e->getMessage()) ? 'Incidents need the Microsoft Graph permission SecurityIncident.Read.All (and Defender for Business, E5 or Defender XDR).' : 'Incidents could not be read: ' . mb_substr($e->getMessage(), 0, 200); }
            $m365J(['configured' => true, 'read_at' => date('Y-m-d H:i:s'), 'alerts_error' => $aErr, 'incidents_error' => $iErr] + itops_sd_build($alerts, $incs, $days));
            break;
        }

        case 'coverage': {
            // Business Premium coverage: enabled people with NO license, and people whose license
            // includes Intune but who have no enrolled device — the paid-for-but-not-used report.
            $users = itops_graph_paged('users?$top=500&$select=id,displayName,userPrincipalName,accountEnabled,assignedLicenses', 4);
            $skus = itops_graph_get('subscribedSkus')['value'] ?? [];
            $skuName = []; $intuneSkus = [];
            foreach ($skus as $k2) {
                $skuName[$k2['skuId'] ?? ''] = $k2['skuPartNumber'] ?? '';
                foreach (($k2['servicePlans'] ?? []) as $sp) {
                    if (stripos((string)($sp['servicePlanName'] ?? ''), 'INTUNE') !== false) { $intuneSkus[$k2['skuId'] ?? ''] = $k2['skuPartNumber'] ?? ''; break; }
                }
            }
            $enrolled = []; $devErr = '';
            try {
                foreach (itops_graph_paged('deviceManagement/managedDevices?$top=200&$select=userPrincipalName', 3) as $d2)
                    if (!empty($d2['userPrincipalName'])) $enrolled[strtolower($d2['userPrincipalName'])] = true;
            } catch (Throwable $e) { $devErr = $e->getMessage(); }
            $unlicensed = []; $notEnrolled = [];
            foreach ($users as $u) {
                if (empty($u['accountEnabled'])) continue;
                $lics = array_values(array_filter(array_column($u['assignedLicenses'] ?? [], 'skuId')));
                $row = ['id' => $u['id'] ?? '', 'displayName' => $u['displayName'] ?? '', 'userPrincipalName' => $u['userPrincipalName'] ?? '',
                        'licenses' => array_map(fn($x) => $skuName[$x] ?? $x, $lics)];
                if (!$lics) { $unlicensed[] = $row; continue; }
                if ($devErr === '' && array_intersect($lics, array_keys($intuneSkus)) && empty($enrolled[strtolower((string)$u['userPrincipalName'])])) $notEnrolled[] = $row;
            }
            $m365J(['configured' => true, 'unlicensed' => $unlicensed, 'not_enrolled' => $notEnrolled,
                'intune_skus' => array_values($intuneSkus), 'devices_error' => $devErr,
                'note' => $devErr !== '' ? 'Could not read enrolled devices (' . $devErr . ') — only the unlicensed check ran.' : '']);
            break;
        }

        case 'copilot-adoption': {
            // Who holds a Copilot license, and who actually uses it (last activity from the usage
            // report, 90 days). "Licensed, no recent activity" = a reclaim / training candidate.
            $skus = itops_graph_get('subscribedSkus')['value'] ?? [];
            $cop = null;
            foreach ($skus as $k2) if (stripos((string)($k2['skuPartNumber'] ?? ''), 'COPILOT') !== false) { $cop = $k2; break; }
            if (!$cop) { $m365J(['configured' => true, 'no_sku' => true,
                'note' => 'No Copilot license (SKU) found in your subscriptions.']); break; }
            $holders = itops_graph_paged('users?$top=500&$select=id,displayName,userPrincipalName,accountEnabled'
                . '&$filter=' . rawurlencode('assignedLicenses/any(x:x/skuId eq ' . $cop['skuId'] . ')'), 4);
            $usage = itops_graph_report_safe('getMicrosoft365CopilotUsageUserDetail', 'D90');
            $last = [];
            foreach (($usage['value'] ?? []) as $r2) {
                $upn = strtolower((string)($r2['User Principal Name'] ?? $r2['userPrincipalName'] ?? ''));
                $d2 = (string)($r2['Last Activity Date'] ?? $r2['lastActivityDate'] ?? '');
                if ($upn !== '' && $d2 !== '') $last[$upn] = $d2;
            }
            // Review M365A-22: when Microsoft hides names in reports, the rows carry codes instead of addresses
            $hidden = $last && !array_filter(array_keys($last), fn($k) => strpos($k, '@') !== false);
            $rows = []; $active = 0; $cut = time() - 30 * 86400;
            foreach ($holders as $u) {
                $la = $last[strtolower((string)($u['userPrincipalName'] ?? ''))] ?? '';
                $ts = $la !== '' ? strtotime($la) : false;
                $state = $hidden ? 'unknown' : (($ts && $ts >= $cut) ? 'active' : ($la !== '' ? 'stale' : 'never'));
                if ($state === 'active') $active++;
                $rows[] = ['id' => $u['id'] ?? '', 'displayName' => $u['displayName'] ?? '', 'userPrincipalName' => $u['userPrincipalName'] ?? '',
                    'enabled' => !empty($u['accountEnabled']), 'last_activity' => $la, 'state' => $state];
            }
            usort($rows, fn($a2, $b2) => strcmp($a2['state'], $b2['state']) ?: strcasecmp($a2['displayName'], $b2['displayName']));
            $enabled2 = (int)($cop['prepaidUnits']['enabled'] ?? 0);
            $m365J(['configured' => true, 'sku' => ['skuId' => $cop['skuId'] ?? '', 'name' => $cop['skuPartNumber'] ?? '',
                    'enabled' => $enabled2, 'consumed' => (int)($cop['consumedUnits'] ?? 0), 'available' => $enabled2 - (int)($cop['consumedUnits'] ?? 0)],
                'value' => $rows, 'summary' => ['holders' => count($rows), 'active_30d' => $active],
                'usage_available' => empty($usage['error']),
                'names_hidden' => $hidden,
                'note' => !empty($usage['error']) ? 'Usage report unavailable (' . $usage['error'] . ') — holders are listed without activity.'
                    : ($hidden ? 'Microsoft hides user names in its reports, so activity cannot be matched to people. Turn that off in the Microsoft 365 admin center (Settings → Org settings → Reports).' : '')]);
            break;
        }

        default:
            error_response('Unknown M365 resource', 404);
    }
} catch (Throwable $e) {
    // Clean message only — never leak the token or raw secrets.
    $msg = $e->getMessage();
    $st = (stripos($msg, 'denied') !== false || strpos($msg, '403') !== false) ? 403
        : ((stripos($msg, 'HTTP 400') !== false) ? 400 : 502);
    error_response('Microsoft 365 request failed: '.$msg, $st);
}
