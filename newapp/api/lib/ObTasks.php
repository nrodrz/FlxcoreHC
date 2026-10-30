<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Onboarding / offboarding task execution, shared by the API route and the scheduler.
 *
 * itops_ob_task_action($db, $runId, $taskId, $body, $actor)
 *   $body['action'] = run | done | skip | preview (+ to_id, modules, override_reason, note)
 *   Returns the response array (['ok','status','result', 'secret'?, 'verify'?] or ['preview'=>…]).
 *   Throws ItopsObError(message, httpCode, extra) for validation problems (task NOT marked failed)
 *   and for a failed step (task marked failed, code 502).
 */
require_once __DIR__ . '/EventLog.php';
require_once __DIR__ . '/Offboarding.php';
require_once __DIR__ . '/Onboarding.php';

if (!class_exists('ItopsObError')) {
    class ItopsObError extends RuntimeException {
        public $http; public $extra;
        public function __construct(string $m, int $http = 400, array $extra = []) { parent::__construct($m); $this->http = $http; $this->extra = $extra; }
    }
}
if (!function_exists('itops_ob_fail')) {
    function itops_ob_fail(string $m, int $code = 400) { throw new ItopsObError($m, $code); }
    function itops_ob_fire(string $event, array $payload) {
        $f = __DIR__ . '/Integrations.php';
        if (is_file($f)) { require_once $f; if (function_exists('itops_fire_event')) { try { itops_fire_event($event, $payload); } catch (\Throwable $e) {} } }
    }

    /**
     * BT21: which permission a Microsoft 365 step needs — the granular split (accounts / licenses / groups).
     * The old single "M365 Admin (edit)" grant is still accepted so roles saved before BT21 keep working.
     */
    function itops_ob_type_module(string $type): string {
        if (strpos($type, 'm365_license') === 0) return 'm365_licenses';
        if ($type === 'm365_group' || $type === 'm365_groups_remove_all') return 'm365_groups';
        return 'm365_users';   // create, disable, revoke sessions, auto-reply, forwarding, OneDrive handover
    }
    function itops_ob_type_perm_label(string $type): string {
        return ['m365_licenses' => 'Microsoft 365 · Licenses', 'm365_groups' => 'Microsoft 365 · Groups', 'm365_users' => 'Microsoft 365 · Accounts'][itops_ob_type_module($type)];
    }
    /** BT89 (H-11): a vTiger step needs the vTiger provisioning permission, not only Onboarding · Edit (same split as the Offboard wizard). */
    function itops_ob_type_allowed(array $user, string $type): bool {
        if (!function_exists('itops_can')) return true;
        if (strpos($type, 'm365_') !== 0) return true;
        return itops_can($user, itops_ob_type_module($type), 'edit') || itops_can($user, 'm365_admin', 'edit');
    }

    /**
     * BT89 (M-37): which onboarding / offboarding records a person may open. A Super Admin, and a role with
     * Onboarding · All records, sees every one; anybody else sees the ones they started and the ones of their own department.
     */
    function itops_ob_sees_all(array $user): bool {
        if (!empty($user['_scheduler']) || ($user['role_slug'] ?? '') === 'super_admin') return true;
        return function_exists('itops_can') && itops_can($user, 'onboarding', 'all');
    }
    function itops_ob_run_visible(array $user, array $run): bool {
        if (itops_ob_sees_all($user)) return true;
        if ((int)($run['created_by'] ?? 0) > 0 && (int)$run['created_by'] === (int)($user['id'] ?? 0)) return true;
        $d = (int)($user['department_id'] ?? 0);
        return $d > 0 && (int)($run['department_id'] ?? 0) === $d;
    }
    /** SQL condition (and its values) limiting itops_ob_runs (alias $a) to the records this person may open. */
    function itops_ob_scope_sql(array $user, string $a = 'r'): array {
        if (itops_ob_sees_all($user)) return ['1=1', []];
        $d = (int)($user['department_id'] ?? 0);
        if ($d > 0) return ['(' . $a . '.created_by = ? OR ' . $a . '.department_id = ?)', [(int)($user['id'] ?? 0), $d]];
        return [$a . '.created_by = ?', [(int)($user['id'] ?? 0)]];
    }

    /** BT89: a temporary password: 16 characters, upper and lower case, digits and symbols, no look-alike characters. */
    function itops_ob_temp_password(int $len = 16): string {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%*+-=?@'];
        $all = implode('', $sets); $out = [];
        foreach ($sets as $set) $out[] = $set[random_int(0, strlen($set) - 1)];
        while (count($out) < $len) $out[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($out) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$out[$i], $out[$j]] = [$out[$j], $out[$i]]; }
        return implode('', $out);
    }

    /**
     * BT89 (M-35): one step at a time. The step is locked while it runs (a second click, another tab or the scheduler
     * waits its turn and then finds the step done), and the checks of itops_ob_task_do run on what the database holds now.
     */
    /**
     * BT124 (code review SEC-1, SEC-2, M365B-3): a Microsoft 365 step of an onboarding or offboarding never
     *  - touches an emergency access account (Posture → Tenant profile);
     *  - changes an account that holds a Microsoft 365 admin role, unless a Super Admin runs it (a scheduled run counts as
     *    the person who scheduled it);
     *  - forwards mail to, or shares a OneDrive with, someone outside the organization, unless a Super Admin runs it;
     *  - adds the person to a group that grants admin roles (role-assignable), unless a Super Admin runs it.
     * Each refusal goes through $refuse (Audit Log) and leaves the step as it was.
     */
    function itops_ob_m365_guard(PDO $db, array $run, array $task, array $cfg, array $currentUser, callable $refuse): void {
        $type = (string)$task['task_type'];
        $actor = $currentUser;
        if (!empty($currentUser['_scheduler'])) {
            $q = $db->prepare("SELECT u.id, u.display_name, u.status, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.id=?"); $q->execute([(int)$run['created_by']]);
            $actor = $q->fetch(PDO::FETCH_ASSOC) ?: ['role_slug' => '', 'status' => 'gone'];
            // BT124 (review SEC-15): a scheduled step runs only while the person who scheduled it still may run it
            if (($actor['status'] ?? '') !== 'active' || !itops_ob_type_allowed($actor, $type)) $refuse('The person who scheduled this no longer has the ' . itops_ob_type_perm_label($type) . ' permission (or is no longer active): run the step by hand.', 403, 'scheduler: permission gone');
        }
        $super = ($actor['role_slug'] ?? '') === 'super_admin';
        $subj = strtolower(trim((string)($run['subject_upn'] ?: $run['subject_email'])));
        $em = []; try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='sa_emergency'")->fetchColumn(); $em = array_map('strtolower', array_filter((array)json_decode((string)$v, true), 'is_string')); } catch (\Throwable $e) {}
        if ($subj !== '' && in_array($subj, $em, true)) $refuse('This is an emergency access account (Posture → Tenant profile). Onboarding and offboarding never change it.', 409, 'emergency access account');
        if ($super) return;
        if ($type !== 'm365_create' && $subj !== '') {
            require_once __DIR__ . '/M365Guard.php';
            try { $roles = itops_m365_admin_roles($subj); } catch (\Throwable $e) { $refuse('The admin roles of ' . $subj . ' could not be checked (' . mb_substr($e->getMessage(), 0, 200) . '). A Super Admin must run this step.', 403, 'admin roles not checked'); return; }
            if ($roles) $refuse($subj . ' holds a Microsoft 365 admin role (' . implode(', ', array_slice($roles, 0, 3)) . '): only a Super Admin can run this step' . (!empty($currentUser['_scheduler']) ? ' (the run was scheduled by someone who is not).' : '.'), 403, 'admin account');
        }
        if (in_array($type, ['m365_forward', 'm365_delegate_onedrive'], true)) {
            $to = trim((string)($cfg['to'] ?? ''));
            $inside = false;
            try { $u = itops_graph_get('users/' . rawurlencode($to) . '?$select=id,userType,accountEnabled'); $inside = !empty($u['id']) && ($u['userType'] ?? 'Member') === 'Member'; } catch (\Throwable $e) {}
            if (!$inside) $refuse(($type === 'm365_forward' ? 'Forwarding mail' : 'Sharing the OneDrive') . ' to ' . $to . ' is only done to someone in your organization (a member account). A Super Admin can send it outside.', 403, 'outside the organization');
        }
        if ($type === 'm365_group') {
            foreach (array_keys(itops_ob_groups($cfg)) as $gid) {
                try { $g = itops_graph_get('groups/' . rawurlencode((string)$gid) . '?$select=id,displayName,isAssignableToRole'); }
                catch (\Throwable $e) { $refuse('The group ' . $gid . ' could not be checked (' . mb_substr($e->getMessage(), 0, 200) . ').', 409, 'group not checked'); return; }
                if (!empty($g['isAssignableToRole'])) $refuse('"' . ($g['displayName'] ?? $gid) . '" grants admin roles: only a Super Admin adds people to it.', 403, 'admin group');
            }
        }
    }

    function itops_ob_task_action(PDO $db, int $id, int $sid, array $body, array $currentUser): array {
        if (($body['action'] ?? 'done') === 'preview') return itops_ob_task_do($db, $id, $sid, $body, $currentUser);
        $lk = $db->quote('itops_ob_task_' . $sid);
        $got = (int)$db->query('SELECT GET_LOCK(' . $lk . ', 0)')->fetchColumn();
        if ($got !== 1) throw new ItopsObError('This step is running right now (another click, another window or the scheduler). Wait a moment and reload: nothing was changed by this click.', 409);
        try { return itops_ob_task_do($db, $id, $sid, $body, $currentUser); }
        finally { try { $db->query('SELECT RELEASE_LOCK(' . $lk . ')'); } catch (\Throwable $e) {} }
    }

    function itops_ob_task_do(PDO $db, int $id, int $sid, array $body, array $currentUser): array {
        $action = $body['action'] ?? 'done';
        if (!in_array($action, ['run', 'done', 'skip', 'preview', 'leave'], true)) itops_ob_fail('Unknown action for a step', 400);
        $rq = $db->prepare("SELECT * FROM itops_ob_runs WHERE id=?"); $rq->execute([$id]); $run = $rq->fetch();
        if (!$run || !itops_ob_run_visible($currentUser, $run)) itops_ob_fail('Run not found', 404);
        $tq = $db->prepare("SELECT * FROM itops_ob_run_tasks WHERE id=? AND run_id=?"); $tq->execute([$sid, $id]); $task = $tq->fetch();
        if (!$task) itops_ob_fail('Task not found', 404);
        $cfg = json_decode($task['config'] ?? '{}', true) ?: [];
        $result = ''; $status = 'done'; $secret = null; $detail = [];
        $who = (string)($currentUser['display_name'] ?? 'an administrator');
        $isSuper = ($currentUser['role_slug'] ?? '') === 'super_admin';
        // BT89: every refusal is in the Audit Log (who, which step, what was wanted, why), then the step is left as it was.
        $refuse = function (string $msg, int $code, string $why, array $more = []) use ($id, $task, $action) {
            try { audit_log('ob_task_refused', 'onboarding', $id, mb_substr((string)$task['title'], 0, 250), null, ['Step' => (string)$task['title'], 'Type' => (string)$task['task_type'], 'Wanted' => $action, 'Why' => $why, 'Result' => 'Nothing was changed'] + $more); } catch (\Throwable $e) {}
            throw new ItopsObError($msg, $code);
        };

        // BT89 (M-35): only a process in progress has steps to work, and a step that is done or skipped stays that way.
        if ($run['status'] !== 'in_progress') $refuse('This ' . ($run['kind'] === 'offboard' ? 'offboarding' : 'onboarding') . ' is ' . str_replace('_', ' ', (string)$run['status']) . ': its steps cannot change any more.', 409, 'the process is ' . $run['status']);
        if ($task['status'] === 'done' || $task['status'] === 'skipped') $refuse('This step is already ' . ($task['status'] === 'done' ? 'done' : 'skipped') . (trim((string)$task['result']) !== '' ? ': ' . mb_substr((string)$task['result'], 0, 200) : '.'), 409, 'the step is already ' . $task['status']);
        if ($action === 'skip' && (int)$task['required'] === 1) $refuse('This step is required: do it, or mark it done with a note that says what happened.', 409, 'required step');
        // BT124 (code review SEC-22): marking an automated Microsoft 365 or vTiger step done (or skipping it) needs the same
        // permission as running it — otherwise "Disable account" could be closed by someone who may not disable accounts
        if ((in_array($action, ['run', 'done', 'skip'], true) && (strpos($task['task_type'], 'm365_') === 0 && $task['task_type'] !== 'm365_exchange_manual')) && !itops_ob_type_allowed($currentUser, $task['task_type'])
            && (function_exists('itops_can') && empty($currentUser['_scheduler'])))
            $refuse('Insufficient permissions — this step needs the ' . itops_ob_type_perm_label($task['task_type']) . ' permission', 403, 'needs ' . itops_ob_type_perm_label($task['task_type']));

        if ($action === 'skip') {
            $status = 'skipped';
        } elseif ($action === 'run') {
            $isM365 = strpos($task['task_type'], 'm365_') === 0;
            if ($isM365) {
                require_once __DIR__ . '/Graph.php';
                if (!function_exists('itops_graph_configured') || !itops_graph_configured()) itops_ob_fail('Microsoft 365 is not configured', 400);
                itops_ob_m365_guard($db, $run, $task, $cfg, $currentUser, $refuse);   // BT124 (review SEC-1, SEC-2, M365B-3)
            }
            try {
                switch ($task['task_type']) {
                    case 'm365_create': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        if (!$upn) itops_ob_fail('This run has no user principal name / email to create');
                        // Already there (a re-run, or created by hand)? Link it instead of failing.
                        [$xc, $xu] = itops_graph_http('GET', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($upn) . '?$select=id,userPrincipalName');
                        if ($xc === 200 && !empty($xu['id'])) {
                            $db->prepare('UPDATE itops_ob_runs SET m365_id=? WHERE id=?')->execute([$xu['id'], $id]);
                            $result = 'Account ' . $upn . ' already exists — linked to this process (no new password generated)';
                            break;
                        }
                        $pass = itops_ob_temp_password();
                        $payload = ['accountEnabled' => true, 'displayName' => $run['subject_name'],
                            'mailNickname' => preg_replace('/@.*/', '', $upn), 'userPrincipalName' => $upn,
                            'passwordProfile' => ['forceChangePasswordNextSignIn' => true, 'password' => $pass]];
                        if (!empty($cfg['usageLocation'])) $payload['usageLocation'] = strtoupper(substr($cfg['usageLocation'], 0, 2));
                        // Extra Entra properties from the template (company, employee type, address…) — allow-listed
                        // by itops_graph_settable_fields(). Person details from the process override them below.
                        foreach (itops_graph_clean_fields($cfg['fields'] ?? []) as $fk => $fv) $payload[$fk] = $fv;
                        // Person details from the process override the template defaults.
                        $dep = ($run['department'] ?? '') ?: ($cfg['department'] ?? ''); if ($dep !== '') $payload['department'] = (string)$dep;
                        $jt  = ($run['job_title'] ?? '') ?: ($cfg['jobTitle'] ?? '');    if ($jt !== '')  $payload['jobTitle']   = (string)$jt;
                        foreach (['first_name' => 'givenName', 'last_name' => 'surname', 'employee_id' => 'employeeId', 'mobile_phone' => 'mobilePhone', 'office_location' => 'officeLocation'] as $rk => $gk)
                            if (!empty($run[$rk])) $payload[$gk] = (string)$run[$rk];
                        $u = itops_graph_send('POST', 'users', $payload);
                        if (!empty($u['id'])) $db->prepare('UPDATE itops_ob_runs SET m365_id=? WHERE id=?')->execute([$u['id'], $id]);
                        $warn = [];
                        if (!empty($run['manager_upn']) && !empty($u['id'])) {   // best effort: the account is never lost because of this
                            try {
                                $mg = itops_graph_get('users/' . rawurlencode((string)$run['manager_upn']) . '?$select=id');
                                itops_graph_retry(fn() => itops_graph_send('PUT', 'users/' . rawurlencode($u['id']) . '/manager/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . $mg['id']]));
                                $detail['manager'] = $run['manager_upn'];
                            } catch (\Throwable $e) { $warn[] = 'manager not set (' . $e->getMessage() . ')'; }
                        }
                        $result = 'Created ' . $upn . ($detail ? ' (manager ' . $run['manager_upn'] . ')' : '') . ' — temporary password generated and shown once to ' . $who . ' (not stored; must be changed at first sign-in)'
                                . ($warn ? ' · WARNING: ' . implode('; ', $warn) : '');
                        $secret = ['label' => 'Microsoft 365 temporary password', 'account' => $upn, 'value' => $pass];
                        break;
                    }
                    case 'm365_license': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $skus = itops_ob_skus($cfg);
                        if (!$skus) itops_ob_fail('This task has no license configured');
                        // Seat check first, so a full subscription is reported clearly instead of a Graph error.
                        $noSeat = [];
                        try { $seats = itops_ob_seats(); foreach ($skus as $skuId => $lab) if (isset($seats[$skuId]) && $seats[$skuId]['available'] <= 0) $noSeat[$skuId] = $lab; } catch (\Throwable $e) {}
                        $have = [];
                        try { $cu = itops_graph_retry(fn() => itops_graph_get('users/' . rawurlencode($upn) . '?$select=assignedLicenses')); $have = array_column($cu['assignedLicenses'] ?? [], 'skuId'); } catch (\Throwable $e) { throw $e; }
                        $toAdd = array_values(array_diff(array_keys($skus), $have, array_keys($noSeat)));
                        if ($toAdd) itops_graph_retry(fn() => itops_graph_send('POST', 'users/' . rawurlencode($upn) . '/assignLicense', ['addLicenses' => array_map(fn($x) => ['skuId' => $x], $toAdd), 'removeLicenses' => []]));
                        $parts = [];
                        foreach ($skus as $skuId => $lab) $parts[] = $lab . (in_array($skuId, $toAdd, true) ? ' assigned' : (isset($noSeat[$skuId]) ? ' NOT assigned — no seats left' : ' already assigned'));
                        $result = 'Licenses: ' . implode(', ', $parts);
                        $detail = ['assigned' => $toAdd, 'no_seats' => array_keys($noSeat)];
                        if ($noSeat) {   // partial failure is reported, not hidden: mark failed so it can be retried after buying seats
                            $db->prepare("UPDATE itops_ob_run_tasks SET status='failed', result=?, completed_by=?, completed_at=NOW() WHERE id=?")->execute([substr($result, 0, 500), (int)$currentUser['id'], $sid]);
                            audit_log('ob_task_failed', 'onboarding', $id, $task['title'], null, ['type' => $task['task_type'], 'result' => $result]);
                            itops_ob_fail($result, 409);
                        }
                        break;
                    }
                    case 'm365_license_remove': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        if (empty($cfg['skuId'])) itops_ob_fail('This task has no license configured');
                        itops_graph_send('POST', 'users/' . rawurlencode($upn) . '/assignLicense', ['addLicenses' => [], 'removeLicenses' => [$cfg['skuId']]]);
                        $result = 'License removed (' . ($cfg['skuLabel'] ?? $cfg['skuId']) . ')';
                        break;
                    }
                    case 'm365_group': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $gs = itops_ob_groups($cfg);
                        if (!$gs) itops_ob_fail('This task has no group configured');
                        $u = itops_graph_retry(fn() => itops_graph_get('users/' . rawurlencode($upn) . '?$select=id'));
                        $parts = []; $failed = [];
                        foreach ($gs as $gid => $gname) {
                            try {
                                itops_graph_retry(fn() => itops_graph_send('POST', 'groups/' . rawurlencode($gid) . '/members/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/directoryObjects/' . $u['id']]));
                                $parts[] = $gname . ' ✓';
                            } catch (\Throwable $e) {
                                if (stripos($e->getMessage(), 'already exist') !== false) $parts[] = $gname . ' (already a member)';
                                else { $parts[] = $gname . ' ✕'; $failed[] = $gname . ': ' . $e->getMessage(); }
                            }
                        }
                        $result = 'Groups: ' . implode(', ', $parts) . ($failed ? ' — FAILED: ' . implode('; ', $failed) : '');
                        if ($failed) throw new \RuntimeException($result);
                        break;
                    }
                    case 'm365_groups_remove_all': {
                        // Opt-in: removes the person from every group they can be removed from. Dynamic groups
                        // (membership by rule) and groups synced from on-premises AD are skipped and listed.
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $me = itops_graph_get('users/' . rawurlencode($upn) . '?$select=id');
                        $keep = array_filter(array_map('trim', explode(',', strtolower((string)($cfg['keep'] ?? '')))));
                        // BT124 (review M365A-9): every page of memberships, not only the first
                        $mo = itops_graph_paged('users/' . rawurlencode($upn) . '/memberOf?$select=id,displayName,groupTypes,onPremisesSyncEnabled&$top=100', 50);
                        $removed = []; $skipped = []; $failed = [];
                        foreach ($mo as $g) {
                            if (($g['@odata.type'] ?? '#microsoft.graph.group') !== '#microsoft.graph.group') continue;   // directory roles etc.
                            $gn = (string)($g['displayName'] ?? $g['id']);
                            if (in_array(strtolower($gn), $keep, true)) { $skipped[] = $gn . ' (kept by template)'; continue; }
                            if (in_array('DynamicMembership', $g['groupTypes'] ?? [], true)) { $skipped[] = $gn . ' (dynamic)'; continue; }
                            if (!empty($g['onPremisesSyncEnabled'])) { $skipped[] = $gn . ' (synced from on-premises AD)'; continue; }
                            try { itops_graph_send('DELETE', 'groups/' . rawurlencode($g['id']) . '/members/' . rawurlencode($me['id']) . '/$ref'); $removed[] = $gn; }
                            catch (\Throwable $e) { $failed[] = $gn . ': ' . $e->getMessage(); }
                        }
                        $result = 'Removed from ' . count($removed) . ' group(s)' . ($removed ? ' (' . implode(', ', $removed) . ')' : '')
                                . ($skipped ? ' · skipped: ' . implode(', ', $skipped) : '') . ($failed ? ' · FAILED: ' . implode('; ', $failed) : '');
                        $detail = ['removed' => $removed, 'skipped' => $skipped, 'failed' => $failed];
                        if ($failed) throw new \RuntimeException($result);
                        break;
                    }
                    case 'm365_license_remove_all': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $lu = itops_graph_get('users/' . rawurlencode($upn) . '?$select=assignedLicenses');
                        $skus = array_values(array_filter(array_map(fn($l) => $l['skuId'] ?? '', $lu['assignedLicenses'] ?? [])));
                        if ($skus) itops_graph_send('POST', 'users/' . rawurlencode($upn) . '/assignLicense', ['addLicenses' => [], 'removeLicenses' => $skus]);
                        $result = $skus ? count($skus) . ' license(s) removed' : 'No licenses were assigned';
                        $detail = ['removed_skus' => $skus];
                        break;
                    }
                    case 'm365_disable': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        if (strcasecmp((string)$upn, (string)($currentUser['email'] ?? '')) === 0) itops_ob_fail('You cannot disable your own account from here', 400);
                        itops_graph_send('PATCH', 'users/' . rawurlencode($upn), ['accountEnabled' => false]);
                        $result = 'Account disabled (sign-in blocked)';
                        break;
                    }
                    case 'm365_revoke_sessions': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        itops_graph_send('POST', 'users/' . rawurlencode($upn) . '/revokeSignInSessions', []);   // empty body is sent as {}
                        $result = 'All sign-in sessions & refresh tokens revoked';
                        break;
                    }
                    case 'm365_autoreply': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $msg = trim((string)($cfg['message'] ?? '')) !== '' ? $cfg['message'] : ('This mailbox is no longer actively monitored. Your message has been received; please contact the appropriate team for assistance.');
                        itops_graph_send('PATCH', 'users/' . rawurlencode($upn) . '/mailboxSettings', [
                            'automaticRepliesSetting' => ['status' => 'alwaysEnabled', 'internalReplyMessage' => $msg, 'externalReplyMessage' => $msg],
                        ]);
                        $result = 'Auto-reply (out-of-office) enabled';
                        break;
                    }
                    case 'm365_forward': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $to = trim((string)($cfg['to'] ?? ''));
                        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) itops_ob_fail('This task needs a valid forwarding address');
                        itops_graph_send('POST', 'users/' . rawurlencode($upn) . '/mailFolders/inbox/messageRules', [
                            'displayName' => (function_exists('itops_app_name') ? itops_app_name() : 'ITOps') . ' offboarding forward', 'sequence' => 1, 'isEnabled' => true,
                            'actions' => ['forwardTo' => [['emailAddress' => ['address' => $to]]], 'stopProcessingRules' => false],
                        ]);
                        $result = 'Inbox forwarding rule created to ' . $to;
                        break;
                    }
                    case 'm365_delegate_onedrive': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $to = trim((string)($cfg['to'] ?? ''));
                        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) itops_ob_fail('This task needs a valid delegate address');
                        itops_graph_send('POST', 'users/' . rawurlencode($upn) . '/drive/root/invite', [
                            'recipients' => [['email' => $to]], 'roles' => ['write'], 'requireSignIn' => true, 'sendInvitation' => true,
                            'message' => 'Access to a departing colleague\'s OneDrive for handover.',
                        ]);
                        $result = 'OneDrive shared with ' . $to . ' (write access)';
                        break;
                    }
                    case 'send_welcome': {
                        $to = trim((string)(($cfg['to'] ?? '') ?: $run['subject_email']));
                        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) itops_ob_fail('No valid address to send the welcome email to', 400);
                        require_once __DIR__ . '/Mail.php';
                        $mgrName = '';
                        if (!empty($run['manager_upn'])) { $mq = $db->prepare('SELECT display_name FROM itops_users WHERE email=?'); $mq->execute([$run['manager_upn']]); $mgrName = (string)($mq->fetchColumn() ?: $run['manager_upn']); }
                        $r = itops_email('onboarding_welcome', $to, [
                            'person_name' => (string)$run['subject_name'], 'recipient_name' => (string)$run['subject_name'],
                            'start_date' => itops_tpl_d2($run['start_date'] ?? ''), 'job_title' => (string)($run['job_title'] ?? ''),
                            'manager_name' => $mgrName, 'account' => (string)(($run['subject_upn'] ?? '') ?: $run['subject_email']),
                            'message' => (string)($cfg['message'] ?? '')]);
                        if (empty($r['ok'])) throw new \RuntimeException('Welcome email not sent: ' . ($r['error'] ?? 'unknown error'));
                        $result = 'Welcome email sent to ' . $to;
                        break;
                    }
                    case 'notify': {
                        $msg = $cfg['message'] ?? ('Onboarding task: ' . $task['title']);
                        $to  = $cfg['email'] ?? '';
                        if ($to) { try { require_once __DIR__ . '/Mail.php';
                            itops_email('onboarding_notice', $to, ['person_name' => (string)$run['subject_name'], 'task' => (string)($task['title'] ?? ''), 'message' => (string)$msg]); } catch (\Throwable $e) {} }
                        // in-app notification to admins as a fallback
                        try {
                            $admins = $db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE u.status='active' AND r.slug IN ('super_admin','admin','dept_admin')")->fetchAll(PDO::FETCH_COLUMN);
                            $ni = $db->prepare("INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
                            foreach ($admins as $uid) { try { $ni->execute([(int)$uid, 'Onboarding: ' . $run['subject_name'], $msg, 'onboarding', '#onboarding_runs']); } catch (\Throwable $e) {} }
                        } catch (\Throwable $e) {}
                        $result = 'Notification sent';
                        break;
                    }
                    default:
                        $result = 'Marked done';
                }
            } catch (ItopsObError $e) { throw $e; }
            catch (\Throwable $e) {
                $db->prepare("UPDATE itops_ob_run_tasks SET status='failed', result=?, completed_by=?, completed_at=NOW() WHERE id=?")
                   ->execute([substr($e->getMessage(), 0, 500), (int)$currentUser['id'], $sid]);
                itops_log('warning', 'onboarding', 'Task failed: ' . $task['title'] . ' — ' . $e->getMessage(), ['run' => $id]);
                itops_ob_fail('Task failed: ' . $e->getMessage(), 502);
            }
        } else {
            $result = trim($body['note'] ?? '') !== '' ? $body['note'] : 'Marked done';
        }

        $db->prepare("UPDATE itops_ob_run_tasks SET status=?, result=?, completed_by=?, completed_at=NOW() WHERE id=?")
           ->execute([$status, substr($result, 0, 500), (int)$currentUser['id'], $sid]);
        // Audit: what happened, never a password (the result text never contains one).
        audit_log('ob_task_' . $status, 'onboarding', $id, $task['title'], null, ['type' => $task['task_type'], 'result' => $result] + ($detail ? ['detail' => $detail] : []));

        // auto-complete the run when every task is resolved
        // A run is complete only when nothing is pending AND nothing failed (a failed step must be retried, marked done or skipped).
        // BT44: a line the Onboard wizard recorded as failed (config "recorded") is history, not work left to do.
        $pend = (int)$db->query("SELECT COUNT(*) FROM itops_ob_run_tasks WHERE run_id=" . (int)$id . " AND (status='pending' OR (status='failed' AND COALESCE(config,'') NOT LIKE '%\"recorded\":true%'))")->fetchColumn();
        $fin = null;
        if ($pend === 0) { $fin = $db->prepare("UPDATE itops_ob_runs SET status='completed', completed_at=NOW() WHERE id=? AND status='in_progress'"); $fin->execute([$id]); }
        if ($fin && $fin->rowCount() === 1) {   // BT89 (M-35): completed once — the evidence, the check and the summary happen one time
            itops_log('info', 'onboarding', ($run['kind'] === 'offboard' ? 'Offboarding' : 'Onboarding') . ' completed: ' . $run['subject_name'], ['run' => $id]);
            if ($run['kind'] === 'offboard') {   // close the loop: verify both systems right away, then tell the requester/manager
                try { $rr = $db->prepare('SELECT * FROM itops_ob_runs WHERE id=?'); $rr->execute([$id]); $verifyRes = itops_ob_verify($db, $rr->fetch() ?: $run, true, 'on completion'); } catch (\Throwable $e) {}
                try { itops_ob_send_summary($db, (int)$id, 'completed', $verifyRes ?? null); } catch (\Throwable $e) {}
            }
            itops_ob_fire($run['kind'] === 'offboard' ? 'offboarding.completed' : 'onboarding.completed', ['run_id' => (int)$id, 'subject' => $run['subject_name']]);
        }
        return ['ok' => true, 'status' => $status, 'result' => $result] + ($secret ? ['secret' => $secret] : []) + (isset($verifyRes) ? ['verify' => $verifyRes] : []);
    }

    function itops_ob_send_summary(PDO $db, int $runId, string $status, ?array $verify = null): void {
        require_once __DIR__ . '/Mail.php';
        $rq = $db->prepare('SELECT * FROM itops_ob_runs WHERE id=?'); $rq->execute([$runId]); $run = $rq->fetch(); if (!$run) return;
        $lines = [];
        foreach ($db->query("SELECT title, status, result FROM itops_ob_run_tasks WHERE run_id=" . $runId . " ORDER BY sort_order, id")->fetchAll() as $t) {
            $mark = ['done' => '✓', 'skipped' => '–', 'failed' => '✕', 'pending' => '…'][$t['status']] ?? '';
            $lines[] = '- ' . $mark . ' ' . $t['title'] . ($t['result'] ? ': ' . mb_substr((string)$t['result'], 0, 160) : '');
        }
        $ver = ['en' => 'Not verified yet.', 'es' => 'Aún no verificado.'];
        if ($verify) {
            $ver = $verify['clean'] ? ['en' => '✓ Access verified removed in every system.', 'es' => '✓ Se verificó que el acceso fue retirado en todos los sistemas.']
                                    : ['en' => '✕ Access check found: ' . implode('; ', $verify['issues']), 'es' => '✕ La verificación encontró: ' . implode('; ', $verify['issues'])];
        }
        $st = ['completed' => ['en' => 'completed', 'es' => 'completado'], 'partial' => ['en' => 'partly done — some steps need you', 'es' => 'parcialmente completado — algunos pasos requieren su atención']][$status] ?? ['en' => $status, 'es' => $status];
        $to = [];
        if (!empty($run['created_by'])) { $u = $db->prepare("SELECT id,email,display_name FROM itops_users WHERE id=? AND status='active'"); $u->execute([(int)$run['created_by']]); if ($r = $u->fetch()) $to[strtolower($r['email'])] = $r; }
        if (!empty($run['auto_run_by'])) { $u = $db->prepare("SELECT id,email,display_name FROM itops_users WHERE id=? AND status='active'"); $u->execute([(int)$run['auto_run_by']]); if ($r = $u->fetch()) $to[strtolower($r['email'])] = $r; }
        if (!empty($run['manager_upn']) && filter_var($run['manager_upn'], FILTER_VALIDATE_EMAIL) && !isset($to[strtolower($run['manager_upn'])])) $to[strtolower($run['manager_upn'])] = ['id' => null, 'email' => $run['manager_upn'], 'display_name' => ''];
        $vars = ['person_name' => (string)$run['subject_name'], 'status' => $st, 'report' => implode("\n", $lines), 'verification' => $ver,
                 'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#onboarding_runs' : ''];
        foreach ($to as $r) { if (!empty($r['email'])) { try { itops_email('offboarding_summary', $r['email'], $vars + ['recipient_name' => $r['display_name'] ?? ''], ['user_id' => $r['id'] ? (int)$r['id'] : null]); } catch (\Throwable $e) {} } }
    }

    /**
     * Run every pending automated step of a run, server-side (used by scheduled offboarding).
     * Best effort for offboarding: a failed step does not stop the others (blocking sign-in must still
     * happen even if, say, the forwarding rule fails). Steps that need a person are left pending.
     * Returns ['status'=>'completed'|'partial', 'log'=>[...]].
     */
    function itops_ob_run_automated(PDO $db, int $runId, array $actor): array {
        $manual = ['manual', 'm365_exchange_manual', 'm365_create'];   // creates need someone to see the password; a company-system step is done by a person in that system
        $log = [];
        $tq = $db->prepare("SELECT * FROM itops_ob_run_tasks WHERE run_id=? AND status IN ('pending','failed') ORDER BY sort_order, id"); $tq->execute([$runId]);
        // BT124 (code review SEC-16): the Microsoft 365 steps of an offboarding run in a fixed order whatever the template
        // says — sign-in blocked and sessions ended, then the mailbox and OneDrive handover, then groups, then licenses — and
        // licenses are not removed when a handover step failed (removing them starts deleting the mailbox and OneDrive)
        $phase = ['m365_disable' => 1, 'm365_revoke_sessions' => 1, 'm365_autoreply' => 2, 'm365_forward' => 2, 'm365_delegate_onedrive' => 2, 'm365_groups_remove_all' => 3, 'm365_license_remove_all' => 4, 'm365_license_remove' => 4];
        $tasks = $tq->fetchAll(); $pos = array_flip(array_keys($tasks));
        usort($tasks, fn($a, $b) => [$phase[$a['task_type']] ?? 0, (int)$a['sort_order'], (int)$a['id']] <=> [$phase[$b['task_type']] ?? 0, (int)$b['sort_order'], (int)$b['id']]);
        $handoverFailed = false;
        foreach ($tasks as $t) {
            if (in_array($t['task_type'], $manual, true)) { $log[] = ['task' => $t['title'], 'ok' => null, 'msg' => 'left for a person']; continue; }
            if ($handoverFailed && ($phase[$t['task_type']] ?? 0) === 4) { $log[] = ['task' => $t['title'], 'ok' => false, 'msg' => 'not run: a mailbox or OneDrive handover step failed first (removing licenses would start deleting them)']; continue; }
            try { $r = itops_ob_task_action($db, $runId, (int)$t['id'], ['action' => 'run'], $actor); $log[] = ['task' => $t['title'], 'ok' => true, 'msg' => (string)($r['result'] ?? '')]; }
            catch (\Throwable $e) { $log[] = ['task' => $t['title'], 'ok' => false, 'msg' => $e->getMessage()]; if (($phase[$t['task_type']] ?? 0) === 2) $handoverFailed = true; }
        }
        $pend = (int)$db->query("SELECT COUNT(*) FROM itops_ob_run_tasks WHERE run_id=" . $runId . " AND status IN ('pending','failed')")->fetchColumn();
        return ['status' => $pend ? 'partial' : 'completed', 'log' => $log];
    }
}
