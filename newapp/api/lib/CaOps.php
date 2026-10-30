<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT118: security baselines, build 4c (part 1). Conditional Access changed from OpsHub, safely.
 *
 *  - Templates tied to the security rules. A policy is always created in report-only, with the emergency access accounts
 *    left out (OpsHub refuses to create one when none can be found). Turning it on is a separate step, after the impact
 *    preview (what the policy would have done to the last days' sign-ins).
 *  - Every change (create, state, delete, allowed-countries location, security defaults) keeps the policy as it was
 *    before, so "Put back" can undo it. Deleting is offered only for policies OpsHub created.
 *  - The security defaults switch: turns security defaults off and creates, switched on, the four policies that do the
 *    same (MFA for everyone, MFA for admins, legacy sign-in blocked, device code sign-in blocked). If a policy cannot be
 *    created, security defaults are turned back on and the policies already created are removed.
 *  - The emergency accounts are watched: every sign-in by one of them raises a bell and an Audit Log entry.
 *
 * Graph (application permissions): Policy.Read.All, Policy.ReadWrite.ConditionalAccess, Application.Read.All (CA writes),
 * AuditLog.Read.All (impact preview, emergency sign-ins), User.Read.All (emergency account ids).
 * Uses opsh_graph_get / opsh_graph_send / opsh_graph_paged (lib/Graph.php); tables opsh_sa_ca_changes.
 */

if (!function_exists('opsh_ca_templates')) {

    /** Every policy and location made here starts with the app name (Settings → Branding), so admins can tell them apart in Entra. */
    function opsh_ca_prefix(): string { return (function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub') . ' - '; }

    /** The admin roles Microsoft recommends a policy for admins covers (role template ids). */
    function opsh_ca_admin_roles(): array {
        return ['62e90394-69f5-4237-9190-012177145e10', '9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3', 'c4e39bd9-1100-46d3-8c65-fb160da0071f', 'b0f54661-2d74-4c50-afa3-1ec803f12efe',
                '158c047a-c907-4556-b7ef-446551a6b5f7', 'b1be1c3e-b65d-4f19-8427-f6fa0d97feb9', '29232cdf-9323-42fd-ade2-1d097af3e4de', '729827e3-9c14-49f7-bb1b-9608f156bbb8',
                '966707d0-3269-4727-9be2-8c3a10f19b9d', '7be44c8a-adaf-4e2a-84d6-ab2649e08a13', 'e8611ab8-c189-46e8-94e1-60213ab1f814', '194ae4cb-b126-40b2-bd5b-6091b380977d',
                'f28a1f50-f6e7-4571-818b-6a12f2af6b6c', 'fe930be7-5e62-47db-91af-98c3a49a38b1'];
    }

    /** key => [title, rule id it fixes, license key, what it does] */
    function opsh_ca_templates(): array {
        return [
            'block_legacy'      => ['Block legacy sign-in', 'legacy_auth', 'p1', 'Blocks old protocols (IMAP, POP, SMTP basic sign-in, old Office) that cannot ask for multifactor sign-in. Everyone.'],
            'mfa_all'           => ['Multifactor sign-in for everyone', 'mfa_required', 'p1', 'Asks every person for multifactor sign-in on every app.'],
            'mfa_admins'        => ['Multifactor sign-in for admins', 'admin_mfa_policy', 'p1', 'Asks the 14 admin roles for multifactor sign-in every time.'],
            'phish_admins'      => ['Phishing-resistant sign-in for admins', 'admin_phish_resistant', 'p1', 'Admins must use a passkey, security key or Windows Hello. Register them first.'],
            'block_device_code' => ['Block device code sign-in', 'device_code_blocked', 'p1', 'Blocks the "enter this code at microsoft.com/devicelogin" sign-in used in phishing. Everyone.'],
            'compliant_admins'  => ['Compliant device for admins', 'compliant_device_admins', 'p1', 'Admins sign in only from a device Intune marks compliant.'],
            'compliant_all'     => ['Compliant device or protected app for everyone', 'compliant_device_all', 'p1', 'Everyone signs in from a compliant device, or a protected app on a phone. Prepare devices first.'],
            'risky_signin'      => ['Challenge risky sign-ins', 'risky_signin_policy', 'p2', 'A sign-in Microsoft rates medium or high risk must pass multifactor sign-in again.'],
            'risky_user'        => ['Fix high-risk users', 'risky_user_policy', 'p2', 'A person Microsoft rates high risk must pass multifactor sign-in and change their password.'],
            'block_countries'   => ['Block sign-in from other countries', '', 'p1', 'Blocks sign-in from outside the countries you list (allowed countries location). Everyone.'],
        ];
    }

    /** The policy body for a template: always report-only, the emergency accounts always left out. */
    function opsh_ca_body(string $key, array $emIds, array $params = []): array {
        $T = opsh_ca_templates(); if (!isset($T[$key])) throw new InvalidArgumentException('Unknown template.');
        if (!$emIds) throw new InvalidArgumentException('No emergency access account could be found: mark them in Posture → Tenant profile first. ' . opsh_app_name() . ' never creates a Conditional Access policy without leaving them out.');
        $all = ['includeUsers' => ['All'], 'excludeUsers' => array_values($emIds)];
        $admins = ['includeRoles' => opsh_ca_admin_roles(), 'excludeUsers' => array_values($emIds)];
        $c = ['users' => $all, 'applications' => ['includeApplications' => ['All']], 'clientAppTypes' => ['all']];
        $g = fn(array $b, string $op = 'OR') => ['operator' => $op, 'builtInControls' => $b];
        $p = ['displayName' => opsh_ca_prefix() . $T[$key][0], 'state' => 'enabledForReportingButNotEnforced', 'conditions' => $c];
        switch ($key) {
            case 'block_legacy':      $p['conditions']['clientAppTypes'] = ['exchangeActiveSync', 'other']; $p['grantControls'] = $g(['block']); break;
            case 'mfa_all':           $p['grantControls'] = $g(['mfa']); break;
            case 'mfa_admins':        $p['conditions']['users'] = $admins; $p['grantControls'] = $g(['mfa']); break;
            case 'phish_admins':      $p['conditions']['users'] = $admins; $p['grantControls'] = ['operator' => 'OR', 'authenticationStrength' => ['id' => '00000000-0000-0000-0000-000000000004']]; break;
            case 'block_device_code': $p['conditions']['authenticationFlows'] = ['transferMethods' => 'deviceCodeFlow']; $p['grantControls'] = $g(['block']); break;
            case 'compliant_admins':  $p['conditions']['users'] = $admins; $p['grantControls'] = $g(['compliantDevice']); break;
            case 'compliant_all':     $p['grantControls'] = $g(['compliantDevice', 'compliantApplication']); break;
            case 'risky_signin':      $p['conditions']['signInRiskLevels'] = ['high', 'medium']; $p['grantControls'] = $g(['mfa']);
                                      $p['sessionControls'] = ['signInFrequency' => ['isEnabled' => true, 'frequencyInterval' => 'everyTime', 'authenticationType' => 'primaryAndSecondaryAuthentication']]; break;
            case 'risky_user':        $p['conditions']['userRiskLevels'] = ['high']; $p['grantControls'] = $g(['mfa', 'passwordChange'], 'AND'); break;
            case 'block_countries':
                $loc = (string)($params['location_id'] ?? ''); if ($loc === '') throw new InvalidArgumentException('The allowed countries are not set.');
                $p['conditions']['locations'] = ['includeLocations' => ['All'], 'excludeLocations' => [$loc]]; $p['grantControls'] = $g(['block']); break;
        }
        return $p;
    }

    /** The emergency accounts' object ids (from their sign-in addresses). [ids by upn, missing upns] */
    function opsh_ca_emergency_ids(array $upns): array {
        $ids = []; $miss = [];
        foreach ($upns as $u) {
            try { $j = opsh_graph_get('users/' . rawurlencode($u) . '?$select=id,userPrincipalName'); if (!empty($j['id'])) $ids[strtolower($u)] = (string)$j['id']; else $miss[] = $u; }
            catch (Throwable $e) { if (str_contains($e->getMessage(), 'HTTP 404')) $miss[] = $u; else throw $e; }
        }
        return [$ids, $miss];
    }

    /** Every policy, summarized. $emIds: to say whether each leaves them out. */
    function opsh_ca_list(array $emIds, array $created = []): array {
        $out = [];
        foreach ((opsh_graph_get('identity/conditionalAccess/policies')['value'] ?? []) as $p) {
            $u = $p['conditions']['users'] ?? []; $ex = array_map('strtolower', $u['excludeUsers'] ?? []);
            $who = in_array('All', $u['includeUsers'] ?? [], true) ? 'Everyone' : (count($u['includeRoles'] ?? []) ? count($u['includeRoles']) . ' admin roles' : (count($u['includeUsers'] ?? []) + count($u['includeGroups'] ?? [])) . ' people or groups');
            $gc = $p['grantControls'] ?? []; $ctl = (array)($gc['builtInControls'] ?? []);
            $what = in_array('block', $ctl, true) ? 'Block' : implode(' + ', array_filter(array_merge(array_map(fn($x) => ['mfa' => 'Multifactor', 'compliantDevice' => 'Compliant device', 'compliantApplication' => 'Protected app', 'passwordChange' => 'Password change', 'domainJoinedDevice' => 'Joined device', 'approvedApplication' => 'Approved app'][$x] ?? $x, $ctl), !empty($gc['authenticationStrength']) ? ['Strength: ' . ($gc['authenticationStrength']['displayName'] ?? 'custom')] : [])));
            $id = (string)$p['id'];
            $out[] = ['id' => $id, 'name' => (string)($p['displayName'] ?? ''), 'state' => (string)($p['state'] ?? ''), 'who' => $who, 'what' => $what ?: '—',
                      'emergency_out' => $emIds ? !array_diff(array_map('strtolower', array_values($emIds)), $ex) : null,
                      'opshub' => isset($created[$id]), 'modified' => $p['modifiedDateTime'] ?? ($p['createdDateTime'] ?? null)];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }
    function opsh_ca_get(string $id): array {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) throw new InvalidArgumentException('Not a policy id.');
        return opsh_graph_get('identity/conditionalAccess/policies/' . $id);
    }
    /** A policy body that can be sent again (read-only fields removed). */
    function opsh_ca_clean(array $p): array {
        foreach (['id', 'createdDateTime', 'modifiedDateTime', '@odata.context', 'templateId', 'partialEnablementStrategy', 'deletedDateTime'] as $k) unset($p[$k]);
        if (isset($p['grantControls']['authenticationStrength'])) $p['grantControls']['authenticationStrength'] = ['id' => (string)($p['grantControls']['authenticationStrength']['id'] ?? '')];
        return $p;
    }

    /** What a policy did, or would have done (report-only), to the sign-ins of the last $days days. */
    function opsh_ca_impact(string $id, int $days = 7, int $pages = 10): array {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) throw new InvalidArgumentException('Not a policy id.');
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
        $rows = opsh_graph_paged('auditLogs/signIns?$filter=' . rawurlencode('createdDateTime ge ' . $since) . '&$top=999', $pages);
        $res = ['seen' => count($rows), 'days' => $days, 'block' => [], 'challenge' => [], 'pass' => 0, 'not_applied' => 0, 'truncated' => !empty($GLOBALS['OPSH_GRAPH_TRUNCATED'])];
        foreach ($rows as $s) foreach ((array)($s['appliedConditionalAccessPolicies'] ?? []) as $a) {
            if (strtolower((string)($a['id'] ?? '')) !== strtolower($id)) continue;
            $r = (string)($a['result'] ?? ''); $who = strtolower((string)($s['userPrincipalName'] ?? '')); $app = (string)($s['appDisplayName'] ?? '');
            if (in_array($r, ['failure', 'reportOnlyFailure'], true)) $res['block'][$who][] = $app;
            elseif ($r === 'reportOnlyInterrupted') $res['challenge'][$who][] = $app;
            elseif (in_array($r, ['success', 'reportOnlySuccess'], true)) $res['pass']++;
            else $res['not_applied']++;
        }
        $fold = function (array $m) { $o = []; foreach ($m as $u => $apps) $o[] = ['user' => $u, 'count' => count($apps), 'apps' => array_values(array_unique($apps))]; usort($o, fn($a, $b) => $b['count'] <=> $a['count']); return $o; };
        $res['block_people'] = $fold($res['block']); $res['challenge_people'] = $fold($res['challenge']);
        $res['block'] = array_sum(array_column($res['block_people'], 'count')); $res['challenge'] = array_sum(array_column($res['challenge_people'], 'count'));
        return $res;
    }

    /** Create or update the "allowed countries" named location. Returns its id. */
    function opsh_ca_countries(array $codes, ?string $existing): string {
        $codes = array_values(array_unique(array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), $codes), fn($c) => preg_match('/^[A-Z]{2}$/', $c))));
        if (!$codes) throw new InvalidArgumentException('Name at least one country by its two-letter code (US, PR, CA…).');
        $body = ['@odata.type' => '#microsoft.graph.countryNamedLocation', 'displayName' => opsh_ca_prefix() . 'Allowed countries', 'countriesAndRegions' => $codes, 'includeUnknownCountriesAndRegions' => false];
        if ($existing) { opsh_graph_send('PATCH', 'identity/conditionalAccess/namedLocations/' . $existing, $body); return $existing; }
        $j = opsh_graph_send('POST', 'identity/conditionalAccess/namedLocations', $body);
        return (string)($j['id'] ?? '');
    }

    /* ─────────── changes, recorded so they can be put back ─────────── */

    function opsh_ca_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM opsh_sa_ca_changes LIMIT 1'); $db->query('SELECT 1 FROM opsh_sa_change_requests LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
    function opsh_ca_record(PDO $db, string $action, string $pid, string $name, $before, $after, int $by, ?int $req = null): int {
        $db->prepare('INSERT INTO opsh_sa_ca_changes (action, policy_id, policy_name, before_json, after_json, by_user, at, request_id) VALUES (?,?,?,?,?,?,NOW(),?)')
           ->execute([$action, $pid, mb_substr($name, 0, 255), $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $by, $req]);
        return (int)$db->lastInsertId();
    }
    /** Policy ids OpsHub created (and that were not deleted since). */
    function opsh_ca_created(PDO $db): array {
        $out = [];
        foreach ($db->query("SELECT action, policy_id FROM opsh_sa_ca_changes WHERE action IN ('create','delete','secdef_swap') AND undone_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['action'] === 'secdef_swap') { foreach (explode(',', $r['policy_id']) as $x) if ($x !== '') $out[$x] = true; continue; }
            if ($r['action'] === 'create') $out[$r['policy_id']] = true; else unset($out[$r['policy_id']]);
        }
        return $out;
    }
    function opsh_ca_em(PDO $db): array {
        $upns = []; try { $st = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='sa_emergency'"); $st->execute(); $upns = json_decode((string)$st->fetchColumn(), true) ?: []; } catch (Throwable $e) {}
        return $upns ? opsh_ca_emergency_ids($upns) : [[], []];
    }
    /**
     * BT124 (code review SEC-9): a policy is switched on only when every emergency access account is marked, found in the
     * tenant, and left out of it (directly, or through a group it leaves out).
     */
    function opsh_ca_em_check(PDO $db, array $b): void {
        [$em, $miss] = opsh_ca_em($db);
        if (!$em && !$miss) throw new InvalidArgumentException('No emergency access account is marked (Posture → Tenant profile): ' . opsh_app_name() . ' does not switch a policy on without them.');
        if ($miss) throw new InvalidArgumentException('An emergency access account was not found in the tenant (' . implode(', ', array_slice((array)$miss, 0, 3)) . '): fix the list in Posture → Tenant profile first.');
        $ex = array_map('strtolower', $b['conditions']['users']['excludeUsers'] ?? []);
        $notOut = array_diff(array_map('strtolower', array_values($em)), $ex);
        foreach ((array)($b['conditions']['users']['excludeGroups'] ?? []) as $g) {
            if (!$notOut) break;
            try { $mem = array_map(fn($m) => strtolower((string)($m['id'] ?? '')), (array)(opsh_graph_get('groups/' . rawurlencode((string)$g) . '/members?$select=id&$top=999')['value'] ?? [])); $notOut = array_diff($notOut, $mem); } catch (Throwable $e) {}
        }
        if ($notOut) throw new InvalidArgumentException('This policy does not leave out every emergency access account. Edit it in the Entra admin center first, or create it from a template here.');
    }
    function opsh_ca_em_upns(PDO $db): array { $j = json_decode(opsh_ca_setting($db, 'sa_emergency'), true); return is_array($j) ? array_values(array_filter($j, 'is_string')) : []; }
    function opsh_ca_setting(PDO $db, string $k): string { try { $st = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $st->execute([$k]); return (string)$st->fetchColumn(); } catch (Throwable $e) { return ''; } }

    /** A plain-words label for a change, for requests and the Audit Log. */
    function opsh_ca_label(string $kind, array $p): string {
        $T = opsh_ca_templates(); $S = ['enabled' => 'On', 'enabledForReportingButNotEnforced' => 'Report-only', 'disabled' => 'Off'];
        switch ($kind) {
            case 'create': return 'Create "' . opsh_ca_prefix() . ($T[$p['template'] ?? ''][0] ?? '?') . '" in report-only' . (!empty($p['countries']) ? ' (allowed: ' . implode(', ', (array)$p['countries']) . ')' : '');
            case 'state': return 'Set "' . ($p['name'] ?? $p['id'] ?? '') . '" to ' . ($S[$p['state'] ?? ''] ?? '?');
            case 'delete': return 'Delete "' . ($p['name'] ?? $p['id'] ?? '') . '"';
            case 'restore': return 'Put back change #' . (int)($p['change_id'] ?? 0) . (!empty($p['label']) ? ' (' . $p['label'] . ')' : '');
            case 'secdef_swap': return 'Turn security defaults off and switch on the four Conditional Access policies that replace them';
        }
        return $kind;
    }

    /** Carry out one change. Throws InvalidArgumentException (refused, nothing changed) or RuntimeException (Microsoft refused). */
    function opsh_ca_exec(PDO $db, string $kind, array $p, int $by, ?int $req = null): array {
        $S = ['enabled', 'enabledForReportingButNotEnforced', 'disabled'];
        switch ($kind) {
            case 'create': {
                $key = (string)($p['template'] ?? ''); $T = opsh_ca_templates(); if (!isset($T[$key])) throw new InvalidArgumentException('Unknown template.');
                [$em, $miss] = opsh_ca_em($db);
                if ($miss) throw new InvalidArgumentException('These emergency access accounts were not found in the tenant: ' . implode(', ', $miss) . '. Correct them in Posture → Tenant profile first.');
                $name = opsh_ca_prefix() . $T[$key][0];
                foreach ((opsh_graph_get('identity/conditionalAccess/policies')['value'] ?? []) as $x) if (strcasecmp((string)($x['displayName'] ?? ''), $name) === 0) throw new InvalidArgumentException('A policy named "' . $name . '" already exists.');
                $params = [];
                if ($key === 'block_countries') {
                    $before = null; $locId = opsh_ca_setting($db, 'ca_country_location') ?: null;
                    if ($locId) { try { $before = opsh_graph_get('identity/conditionalAccess/namedLocations/' . $locId); } catch (Throwable $e) { $locId = null; } }
                    $loc = opsh_ca_countries((array)($p['countries'] ?? []), $locId);
                    $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('ca_country_location',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$loc]);
                    opsh_ca_record($db, 'location', $loc, opsh_ca_prefix() . 'Allowed countries', $before, ['countries' => array_values((array)$p['countries'])], $by, $req);
                    $params['location_id'] = $loc;
                }
                $body = opsh_ca_body($key, $em, $params);
                $j = opsh_graph_send('POST', 'identity/conditionalAccess/policies', $body); $id = (string)($j['id'] ?? '');
                $cid = opsh_ca_record($db, 'create', $id, $name, null, $body, $by, $req);
                return ['policy_id' => $id, 'name' => $name, 'change_id' => $cid, 'summary' => 'Created in report-only: ' . $name];
            }
            case 'state': {
                $id = (string)($p['id'] ?? ''); $to = (string)($p['state'] ?? ''); if (!in_array($to, $S, true)) throw new InvalidArgumentException('Unknown state.');
                $b = opsh_ca_get($id); $name = (string)($b['displayName'] ?? '');
                if (($b['state'] ?? '') === $to) throw new InvalidArgumentException('The policy is already in that state.');
                if ($to === 'enabled') opsh_ca_em_check($db, $b);   // a policy that would lock out the emergency accounts is never switched on from here
                opsh_graph_send('PATCH', 'identity/conditionalAccess/policies/' . $id, ['state' => $to]);
                $cid = opsh_ca_record($db, 'state', $id, $name, ['state' => $b['state'] ?? ''], ['state' => $to], $by, $req);
                return ['policy_id' => $id, 'name' => $name, 'change_id' => $cid, 'summary' => $name . ': ' . ($b['state'] ?? '') . ' → ' . $to];
            }
            case 'delete': {
                $id = (string)($p['id'] ?? ''); if (!isset(opsh_ca_created($db)[$id])) throw new InvalidArgumentException('Only policies created here can be deleted. Turn others off instead.');
                $b = opsh_ca_get($id); $name = (string)($b['displayName'] ?? '');
                opsh_graph_send('DELETE', 'identity/conditionalAccess/policies/' . $id);
                $cid = opsh_ca_record($db, 'delete', $id, $name, $b, null, $by, $req);
                return ['policy_id' => $id, 'name' => $name, 'change_id' => $cid, 'summary' => 'Deleted: ' . $name];
            }
            case 'restore': {
                $c = $db->prepare('SELECT * FROM opsh_sa_ca_changes WHERE id=?'); $c->execute([(int)($p['change_id'] ?? 0)]); $ch = $c->fetch(PDO::FETCH_ASSOC);
                if (!$ch) throw new InvalidArgumentException('No such change.');
                if ($ch['undone_at']) throw new InvalidArgumentException('That change was already put back.');
                $before = json_decode((string)$ch['before_json'], true); $after = json_decode((string)$ch['after_json'], true); $sum = '';
                switch ($ch['action']) {
                    case 'create': try { opsh_graph_send('DELETE', 'identity/conditionalAccess/policies/' . $ch['policy_id']); } catch (RuntimeException $e) { if (!str_contains($e->getMessage(), '404')) throw $e; } $sum = 'Removed the policy it created'; break;
                    case 'state':   // BT124 (review SEC-8, M365B-11): only when nobody changed it since, and never on without the emergency check
                        $now = opsh_ca_get((string)$ch['policy_id']);
                        if (($now['state'] ?? '') !== (string)($after['state'] ?? '')) throw new InvalidArgumentException('The policy was changed since (it is now ' . ($now['state'] ?? '?') . '): look at it again instead of putting this change back.');
                        if ((string)$before['state'] === 'enabled') opsh_ca_em_check($db, $now);
                        opsh_graph_send('PATCH', 'identity/conditionalAccess/policies/' . $ch['policy_id'], ['state' => (string)$before['state']]); $sum = 'State back to ' . $before['state']; break;
                    case 'delete': $before = (array)$before; if (($before['state'] ?? '') === 'enabled') { $before['state'] = 'enabledForReportingButNotEnforced'; }   // BT124: created again in report-only; switched on by a person
                        $j = opsh_graph_send('POST', 'identity/conditionalAccess/policies', opsh_ca_clean((array)$before)); $sum = 'Policy created again (new id ' . ($j['id'] ?? '') . ')';
                        opsh_ca_record($db, 'create', (string)($j['id'] ?? ''), $ch['policy_name'], null, opsh_ca_clean((array)$before), $by, $req); break;
                    case 'location': if (!$before) throw new InvalidArgumentException('That location did not exist before; delete the policy that uses it instead.');
                        opsh_graph_send('PATCH', 'identity/conditionalAccess/namedLocations/' . $ch['policy_id'], ['@odata.type' => '#microsoft.graph.countryNamedLocation', 'countriesAndRegions' => $before['countriesAndRegions'] ?? [], 'includeUnknownCountriesAndRegions' => (bool)($before['includeUnknownCountriesAndRegions'] ?? false)]); $sum = 'Allowed countries put back'; break;
                    case 'secdef_swap':   // the policies go first: Microsoft does not turn security defaults on while policies are on
                        foreach (explode(',', $ch['policy_id']) as $x) if ($x !== '') { try { opsh_graph_send('DELETE', 'identity/conditionalAccess/policies/' . $x); } catch (RuntimeException $e) { if (!str_contains($e->getMessage(), '404')) throw $e; } }
                        foreach ((array)($after['switched_on'] ?? []) as $id => $st) opsh_graph_send('PATCH', 'identity/conditionalAccess/policies/' . $id, ['state' => (string)$st]);   // existing policies back to what they were
                        opsh_ca_secdef_set(true);
                        $n = count(array_filter(explode(',', (string)$ch['policy_id']))); $sum = 'Security defaults back on; the ' . $n . ' ' . ($n === 1 ? 'policy' : 'policies') . ' removed'; break;
                    default: throw new InvalidArgumentException('That change cannot be put back.');
                }
                $db->prepare('UPDATE opsh_sa_ca_changes SET undone_at=NOW(), undone_by=? WHERE id=?')->execute([$by, (int)$ch['id']]);
                $cid = opsh_ca_record($db, 'restore', (string)$ch['policy_id'], (string)$ch['policy_name'], $after, $before, $by, $req);
                return ['policy_id' => $ch['policy_id'], 'name' => $ch['policy_name'], 'change_id' => $cid, 'summary' => 'Put back: ' . $ch['policy_name'] . ' (' . $sum . ')'];
            }
            case 'secdef_swap': {
                if (!opsh_ca_secdef_on()) throw new InvalidArgumentException('Security defaults are already off.');
                [$em, $miss] = opsh_ca_em($db);
                if (!$em || $miss) throw new InvalidArgumentException('Mark the emergency access accounts first (Posture → Tenant profile); they must be found in the tenant.');
                $names = []; foreach ((opsh_graph_get('identity/conditionalAccess/policies')['value'] ?? []) as $x) $names[strtolower((string)($x['displayName'] ?? ''))] = $x;
                // BT124 (review SEC-7): a policy of the same name that is report-only or off would leave the tenant without
                // that protection once security defaults are off (and Microsoft does not let it be switched on while security
                // defaults are on). It is switched on as part of the swap, after the emergency check, and put back with it.
                $turn = [];
                foreach (['mfa_all', 'mfa_admins', 'block_legacy', 'block_device_code'] as $k) {
                    $x = $names[strtolower(opsh_ca_body($k, $em)['displayName'])] ?? null;
                    if ($x && ($x['state'] ?? '') !== 'enabled') { opsh_ca_em_check($db, $x); $turn[(string)$x['id']] = (string)($x['state'] ?? 'disabled'); }
                }
                $made = []; $done = [];
                opsh_ca_secdef_set(false);
                try {
                    foreach ($turn as $id => $st) { opsh_graph_send('PATCH', 'identity/conditionalAccess/policies/' . $id, ['state' => 'enabled']); $done[$id] = $st; }
                    foreach (['mfa_all', 'mfa_admins', 'block_legacy', 'block_device_code'] as $k) {
                        $b = opsh_ca_body($k, $em); if (isset($names[strtolower($b['displayName'])])) continue;
                        $b['state'] = 'enabled';
                        $j = opsh_graph_send('POST', 'identity/conditionalAccess/policies', $b); $made[] = (string)($j['id'] ?? '');
                    }
                } catch (Throwable $e) {
                    foreach ($made as $x) { try { opsh_graph_send('DELETE', 'identity/conditionalAccess/policies/' . $x); } catch (Throwable $z) {} }
                    foreach ($done as $id => $st) { try { opsh_graph_send('PATCH', 'identity/conditionalAccess/policies/' . $id, ['state' => $st]); } catch (Throwable $z) {} }
                    $back = true; try { opsh_ca_secdef_set(true); } catch (Throwable $z) { $back = false; }
                    if (!$back) throw new RuntimeException('a policy could not be created and security defaults could NOT be turned back on (' . $z->getMessage() . '). Check Entra now: Conditional Access policies that are on still apply (' . $e->getMessage() . ')');
                    throw new RuntimeException('a policy could not be created, so security defaults were turned back on and nothing else changed (' . $e->getMessage() . ')');
                }
                $cid = opsh_ca_record($db, 'secdef_swap', implode(',', $made), 'Security defaults → Conditional Access', ['security_defaults' => true], ['security_defaults' => false, 'policies' => $made, 'switched_on' => $done], $by, $req);
                $sum = 'Security defaults off; ' . count($made) . ' Conditional Access ' . (count($made) === 1 ? 'policy' : 'policies') . ' made and on';
                if ($done) $sum .= '; ' . count($done) . ' existing ' . (count($done) === 1 ? 'policy' : 'policies') . ' switched on';
                return ['policy_id' => implode(',', $made), 'name' => 'Security defaults', 'change_id' => $cid, 'summary' => $sum];
            }
        }
        throw new InvalidArgumentException('Unknown change.');
    }

    /** Called by the scheduled job: every sign-in of an emergency account since the last look. Returns how many were found. */
    function opsh_ca_watch_emergency(PDO $db): int {
        $upns = []; try { $upns = json_decode(opsh_ca_setting($db, 'sa_emergency'), true) ?: []; } catch (Throwable $e) {}
        if (!$upns || !function_exists('opsh_graph_configured') || !opsh_graph_configured()) return 0;
        $last = opsh_ca_setting($db, 'ca_em_watch'); $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($last === '') { $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('ca_em_watch',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$now]); return 0; }
        $f = 'createdDateTime gt ' . $last . ' and (' . implode(' or ', array_map(fn($u) => "userPrincipalName eq '" . str_replace("'", "''", strtolower($u)) . "'", $upns)) . ')';
        // BT124 (code review SEC-23): every page (up to 10 × 50), so a burst of sign-ins is alerted in full
        $rows = []; $p = 'auditLogs/signIns?$filter=' . rawurlencode($f) . '&$top=50';
        for ($i = 0; $i < 10 && $p !== ''; $i++) {
            $j = opsh_graph_get($p); foreach ((array)($j['value'] ?? []) as $v) $rows[] = $v;
            $n = (string)($j['@odata.nextLink'] ?? ''); $p = strpos($n, 'https://graph.microsoft.com/v1.0/') === 0 ? substr($n, 33) : '';
        }
        if (!$rows) return 0;   // the mark stays: a sign-in reaches the log minutes late, and is still found next time
        $mark = $last; foreach ($rows as $s) { $t = (string)($s['createdDateTime'] ?? ''); if (preg_match('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/', $t) && strcmp($t, $mark) > 0) $mark = $t; }
        $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('ca_em_watch',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$mark]);
        $who = [];
        foreach ($db->query("SELECT u.id, r.slug AS role_slug, r.permissions AS role_perms FROM opsh_users u LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE u.status='active'")->fetchAll(PDO::FETCH_ASSOC) as $u)
            if ($u['role_slug'] === 'super_admin' || (function_exists('opsh_can') && opsh_can($u, 'm365_ca', 'edit'))) $who[] = (int)$u['id'];
        foreach ($rows as $s) {
            $upn = (string)($s['userPrincipalName'] ?? ''); $ok = (int)($s['status']['errorCode'] ?? 0) === 0;
            $where = trim(($s['location']['city'] ?? '') . ', ' . ($s['location']['countryOrRegion'] ?? ''), ', ');
            $txt = $upn . ($ok ? ' signed in' : ' tried to sign in') . ' at ' . (string)($s['createdDateTime'] ?? '') . ' UTC from ' . ($s['ipAddress'] ?? '?') . ($where ? ' (' . $where . ')' : '') . ' to ' . ($s['appDisplayName'] ?? '?') . '.';
            foreach ($who as $uid) try { $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([$uid, 'Emergency account used: ' . $upn, $txt . ' If nobody planned this, treat it as an incident.', 'warning', '#ca_approvals']); } catch (Throwable $e) {}
            if (function_exists('audit_log')) audit_log('m365_emergency_signin', 'm365', null, $upn, null, ['Sign-in' => $txt]);
            if (function_exists('opsh_log')) { try { opsh_log('warning', 'm365', 'Emergency account sign-in: ' . $txt); } catch (Throwable $e) {} }
        }
        return count($rows);
    }

    function opsh_ca_secdef_on(): bool { return !empty(opsh_graph_get('policies/identitySecurityDefaultsEnforcementPolicy')['isEnabled']); }
    function opsh_ca_secdef_set(bool $on): void { opsh_graph_send('PATCH', 'policies/identitySecurityDefaultsEnforcementPolicy', ['isEnabled' => $on]); }
}
