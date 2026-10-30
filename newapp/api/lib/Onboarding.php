<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Onboarding helpers (Phase B): multi-license / multi-group task config, Graph retry for
 * a freshly created account, and the PRE-FLIGHT check that runs before anything is created.
 *
 * Pre-flight never changes anything. For every pending automated step it reports
 *   ok    — will work
 *   warn  — will work with a caveat (e.g. account already exists → it will be linked)
 *   error — will fail (no seats left, usage location missing, bridge unreachable, missing permission…)
 */
require_once __DIR__ . '/Offboarding.php';

if (!function_exists('itops_ob_skus')) {

    /** Licenses configured on a task: new multi format {skus:[{skuId,label}]} or legacy {skuId,skuLabel}. */
    function itops_ob_skus(array $cfg): array {
        $out = [];
        foreach (($cfg['skus'] ?? []) as $s) if (!empty($s['skuId'])) $out[$s['skuId']] = (string)($s['label'] ?? $s['skuId']);
        if (!empty($cfg['skuId']) && !isset($out[$cfg['skuId']])) $out[$cfg['skuId']] = (string)($cfg['skuLabel'] ?? $cfg['skuId']);
        return $out;
    }

    /** Groups configured on a task: {groups:[{id,name}]} or legacy {groupId,groupLabel}. */
    function itops_ob_groups(array $cfg): array {
        $out = [];
        foreach (($cfg['groups'] ?? []) as $g) if (!empty($g['id'])) $out[$g['id']] = (string)($g['name'] ?? $g['id']);
        if (!empty($cfg['groupId']) && !isset($out[$cfg['groupId']])) $out[$cfg['groupId']] = (string)($cfg['groupLabel'] ?? $cfg['groupId']);
        return $out;
    }

    /**
     * Run a Graph call, retrying while a just-created account is still replicating
     * (Graph answers 404 "does not exist" for a few seconds after POST /users).
     */
    function itops_graph_retry(callable $fn, int $tries = 4) {
        $wait = [3, 6, 10];
        for ($i = 0; ; $i++) {
            try { return $fn(); }
            catch (Throwable $e) {
                $m = $e->getMessage();
                $transient = strpos($m, 'HTTP 404') !== false || stripos($m, 'does not exist') !== false || stripos($m, 'ResourceNotFound') !== false;
                if (!$transient || $i >= $tries - 1) throw $e;
                sleep($wait[min($i, 2)]);
            }
        }
    }

    /** subscribedSkus → [skuId => ['name','available']] (cached per request). */
    function itops_ob_seats(): array {
        static $c = null; if ($c !== null) return $c;
        $c = [];
        $r = itops_graph_get('subscribedSkus');
        foreach (($r['value'] ?? []) as $s) {
            $c[$s['skuId'] ?? ''] = ['name' => $s['skuPartNumber'] ?? '', 'available' => (int)($s['prepaidUnits']['enabled'] ?? 0) - (int)($s['consumedUnits'] ?? 0)];
        }
        return $c;
    }

    /** Application permissions the Azure app needs per step (any one of the list is enough). */
    function itops_ob_needed_perms(string $type): array {
        $M = [
            'm365_create'             => ['User.ReadWrite.All', 'Directory.ReadWrite.All'],
            'm365_license'            => ['User.ReadWrite.All', 'Directory.ReadWrite.All'],
            'm365_license_remove'     => ['User.ReadWrite.All', 'Directory.ReadWrite.All'],
            'm365_license_remove_all' => ['User.ReadWrite.All', 'Directory.ReadWrite.All'],
            'm365_disable'            => ['User.ReadWrite.All', 'Directory.ReadWrite.All', 'User.EnableDisableAccount.All'],
            'm365_revoke_sessions'    => ['User.ReadWrite.All', 'Directory.ReadWrite.All', 'User.RevokeSessions.All'],
            'm365_group'              => ['GroupMember.ReadWrite.All', 'Group.ReadWrite.All', 'Directory.ReadWrite.All'],
            'm365_groups_remove_all'  => ['GroupMember.ReadWrite.All', 'Group.ReadWrite.All', 'Directory.ReadWrite.All'],
            'm365_autoreply'          => ['MailboxSettings.ReadWrite'],
            'm365_forward'            => ['MailboxSettings.ReadWrite', 'Mail.ReadWrite'],
            'm365_delegate_onedrive'  => ['Files.ReadWrite.All', 'Sites.ReadWrite.All'],
        ];
        return $M[$type] ?? [];
    }

    /** Pre-flight for a run. Returns ['ok'=>bool (no errors), 'checks'=>[{task_id,task,level,message}], 'counts'=>[]]. */
    function itops_ob_preflight(PDO $db, array $run, array $tasks): array {
        $checks = [];
        $add = function ($t, $level, $msg) use (&$checks) { $checks[] = ['task_id' => $t ? (int)$t['id'] : null, 'task' => $t ? $t['title'] : 'General', 'level' => $level, 'message' => $msg]; };
        $pending = array_values(array_filter($tasks, fn($t) => in_array($t['status'], ['pending', 'failed'], true) && !in_array($t['task_type'], ['manual', 'm365_exchange_manual', 'equipment_return', 'assign_equipment'], true)));
        if (!$pending) { $add(null, 'ok', 'No automated steps left to run.'); return ['ok' => true, 'checks' => $checks]; }
        $types = array_unique(array_map(fn($t) => $t['task_type'], $pending));
        $upn = (string)(($run['subject_upn'] ?? '') ?: ($run['subject_email'] ?? ''));
        $needsM365 = (bool)array_filter($types, fn($x) => strpos($x, 'm365_') === 0);

        // ── Microsoft 365 ──
        $graphOk = false; $roles = null; $userState = null;   // null = unknown, false = not found, array = found
        if ($needsM365) {
            require_once __DIR__ . '/Graph.php';
            if (!itops_graph_configured()) $add(null, 'error', 'Microsoft 365 is not connected (Microsoft 365 → settings). Every M365 step would fail.');
            elseif ($upn === '') $add(null, 'error', 'This process has no email / user principal name.');
            else {
                try {
                    $claims = function_exists('itops_graph_jwt_claims') ? itops_graph_jwt_claims(itops_graph_token()) : [];
                    $roles = isset($claims['roles']) && is_array($claims['roles']) ? $claims['roles'] : null;
                    [$code, $u] = itops_graph_http('GET', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($upn) . '?$select=id,userPrincipalName,accountEnabled,usageLocation,assignedLicenses');
                    if ($code === 200) $userState = $u; elseif ($code === 404) $userState = false;
                    else $add(null, 'error', 'Could not read Microsoft 365 (HTTP ' . $code . '): ' . ($u['error']['message'] ?? 'unknown'));
                    $graphOk = true;
                } catch (Throwable $e) { $add(null, 'error', 'Could not reach Microsoft 365: ' . $e->getMessage()); }
            }
            if ($graphOk && $roles !== null) {
                $missing = [];
                foreach ($types as $ty) { $need = itops_ob_needed_perms($ty); if ($need && !array_intersect($need, $roles)) $missing[$need[0]][] = $ty; }
                $TL = ['m365_create' => 'create account', 'm365_license' => 'assign license', 'm365_license_remove' => 'remove license', 'm365_license_remove_all' => 'remove all licenses',
                       'm365_disable' => 'disable account', 'm365_revoke_sessions' => 'revoke sessions', 'm365_group' => 'add to group', 'm365_autoreply' => 'auto-reply',
                       'm365_forward' => 'forward mail', 'm365_delegate_onedrive' => 'delegate OneDrive', 'm365_groups_remove_all' => 'remove from all groups'];
                foreach ($missing as $perm => $tys) $add(null, 'error', 'The Azure app is missing the "' . $perm . '" Application permission (needed to ' . implode(', ', array_map(fn($x) => $TL[$x] ?? $x, $tys)) . '). Azure → App registrations → API permissions → add it → Grant admin consent.');
            }
        }
        $createInRun = (bool)array_filter($pending, fn($t) => $t['task_type'] === 'm365_create');
        $willExist = $createInRun || is_array($userState);

        foreach ($pending as $t) {
            $cfg = json_decode($t['config'] ?? '{}', true) ?: [];
            switch ($t['task_type']) {
                case 'm365_create':
                    if (!$graphOk) break;
                    if (is_array($userState)) $add($t, 'warn', $upn . ' already exists in Microsoft 365 — it will be linked, no new account or password.');
                    else $add($t, 'ok', $upn . ' is free.');
                    $hasLic = (bool)array_filter($pending, fn($x) => $x['task_type'] === 'm365_license');
                    if ($hasLic && empty($cfg['usageLocation'])) $add($t, 'error', 'A license step follows but this step has no usage location (e.g. US). Microsoft refuses to license an account without one — set it in the template.');
                    if (!empty($run['manager_upn'])) {
                        [$mc] = itops_graph_http('GET', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode((string)$run['manager_upn']) . '?$select=id');
                        $add($t, $mc === 200 ? 'ok' : 'warn', $mc === 200 ? 'Manager ' . $run['manager_upn'] . ' found.' : 'Manager ' . $run['manager_upn'] . ' was not found in Microsoft 365 — the account will be created without a manager.');
                    }
                    break;
                case 'm365_license':
                    if (!$graphOk) break;
                    $skus = itops_ob_skus($cfg);
                    if (!$skus) { $add($t, 'error', 'No license chosen in the template.'); break; }
                    if (!$willExist) $add($t, 'error', $upn . ' does not exist in Microsoft 365 and no create step is pending.');
                    if (is_array($userState) && empty($userState['usageLocation']) && !$createInRun) $add($t, 'error', 'The account has no usage location — licenses cannot be assigned. Set it in the Microsoft 365 admin center.');
                    try {
                        $seats = itops_ob_seats();
                        $have = is_array($userState) ? array_column($userState['assignedLicenses'] ?? [], 'skuId') : [];
                        foreach ($skus as $id => $label) {
                            if (in_array($id, $have, true)) { $add($t, 'ok', $label . ' — already assigned.'); continue; }
                            if (!isset($seats[$id])) { $add($t, 'error', $label . ' — this license is not in your subscriptions any more.'); continue; }
                            $av = $seats[$id]['available'];
                            $add($t, $av > 0 ? 'ok' : 'error', ($seats[$id]['name'] ?: $label) . ' — ' . ($av > 0 ? $av . ' seat(s) available.' : 'NO seats available. Buy or free a license first.'));
                        }
                    } catch (Throwable $e) { $add($t, 'warn', 'Could not read license seat counts: ' . $e->getMessage()); }
                    break;
                case 'm365_group':
                    if (!$graphOk) break;
                    $gs = itops_ob_groups($cfg);
                    if (!$gs) { $add($t, 'error', 'No group chosen in the template.'); break; }
                    foreach ($gs as $gid => $gn) {
                        [$gc] = itops_graph_http('GET', 'https://graph.microsoft.com/v1.0/groups/' . rawurlencode($gid) . '?$select=id');
                        $add($t, $gc === 200 ? 'ok' : 'error', $gn . ($gc === 200 ? ' — found.' : ' — group not found (deleted or renamed?). Update the template.'));
                    }
                    if (!$willExist) $add($t, 'error', $upn . ' does not exist in Microsoft 365 and no create step is pending.');
                    break;
                case 'm365_groups_remove_all':
                case 'm365_disable': case 'm365_revoke_sessions': case 'm365_license_remove': case 'm365_license_remove_all':
                case 'm365_autoreply': case 'm365_forward': case 'm365_delegate_onedrive':
                    if (!$graphOk) break;
                    if ($userState === false) $add($t, 'warn', $upn . ' was not found in Microsoft 365 — nothing to change (already deleted?).');
                    elseif (is_array($userState)) $add($t, 'ok', 'Account found.');
                    if (in_array($t['task_type'], ['m365_forward', 'm365_delegate_onedrive'], true) && !filter_var((string)($cfg['to'] ?? ''), FILTER_VALIDATE_EMAIL)) $add($t, 'error', 'No valid "to" address in the template.');
                    if ($t['task_type'] === 'm365_disable' && strcasecmp($upn, (string)($GLOBALS['currentUser']['email'] ?? '')) === 0) $add($t, 'error', 'This is your own account — it cannot be disabled from here.');
                    break;
                case 'send_welcome':
                    $wt = trim((string)(($cfg['to'] ?? '') ?: ($run['subject_email'] ?? '')));
                    $add($t, filter_var($wt, FILTER_VALIDATE_EMAIL) ? 'ok' : 'error', filter_var($wt, FILTER_VALIDATE_EMAIL) ? 'Welcome email will go to ' . $wt . '.' : 'No valid address for the welcome email.');
                    break;
                case 'notify':
                    $add($t, !empty($cfg['email']) && filter_var($cfg['email'], FILTER_VALIDATE_EMAIL) ? 'ok' : 'warn', !empty($cfg['email']) ? 'Will email ' . $cfg['email'] . '.' : 'No email address — only an in-app notification will be sent.');
                    break;
            }
        }
        if ($needsM365 && $graphOk && $roles === null) $add(null, 'warn', 'Could not read the Azure app\'s permissions from its token — permission problems will show when a step runs.');
        $err = count(array_filter($checks, fn($c) => $c['level'] === 'error'));
        $warn = count(array_filter($checks, fn($c) => $c['level'] === 'warn'));
        return ['ok' => $err === 0, 'errors' => $err, 'warnings' => $warn, 'checks' => $checks];
    }
}
