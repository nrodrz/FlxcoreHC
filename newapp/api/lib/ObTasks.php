<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Onboarding / offboarding task execution, shared by the API route and the scheduler.
 *
 * opsh_ob_task_action($db, $runId, $taskId, $body, $actor)
 *   $body['action'] = run | done | skip | preview (+ to_id, modules, override_reason, note)
 *   Returns the response array (['ok','status','result', 'secret'?, 'verify'?] or ['preview'=>…]).
 *   Throws OpshObError(message, httpCode, extra) for validation problems (task NOT marked failed)
 *   and for a failed step (task marked failed, code 502).
 */
require_once __DIR__ . '/EventLog.php';
require_once __DIR__ . '/Offboarding.php';
require_once __DIR__ . '/Onboarding.php';

if (!class_exists('OpshObError')) {
    class OpshObError extends RuntimeException {
        public $http; public $extra;
        public function __construct(string $m, int $http = 400, array $extra = []) { parent::__construct($m); $this->http = $http; $this->extra = $extra; }
    }
}
if (!function_exists('opsh_ob_fail')) {
    function opsh_ob_fail(string $m, int $code = 400) { throw new OpshObError($m, $code); }
    function opsh_ob_fire(string $event, array $payload) {
        $f = __DIR__ . '/Integrations.php';
        if (is_file($f)) { require_once $f; if (function_exists('opsh_fire_event')) { try { opsh_fire_event($event, $payload); } catch (\Throwable $e) {} } }
    }

    /**
     * BT21: which permission a Microsoft 365 step needs — the granular split (accounts / licenses / groups).
     * The old single "M365 Admin (edit)" grant is still accepted so roles saved before BT21 keep working.
     */
    function opsh_ob_type_module(string $type): string {
        if (strpos($type, 'm365_license') === 0) return 'm365_licenses';
        if ($type === 'm365_group' || $type === 'm365_groups_remove_all') return 'm365_groups';
        return 'm365_users';   // create, disable, revoke sessions, auto-reply, forwarding, OneDrive handover
    }
    function opsh_ob_type_perm_label(string $type): string {
        if ($type === 'vtiger_create') return 'Provisioning · Onboard to vTiger';
        if ($type === 'vtiger_reassign' || $type === 'vtiger_deactivate') return 'Provisioning · Offboard from vTiger';
        return ['m365_licenses' => 'Microsoft 365 · Licenses', 'm365_groups' => 'Microsoft 365 · Groups', 'm365_users' => 'Microsoft 365 · Accounts'][opsh_ob_type_module($type)];
    }
    /** BT89 (H-11): a vTiger step needs the vTiger provisioning permission, not only Onboarding · Edit (same split as the Offboard wizard). */
    function opsh_ob_type_allowed(array $user, string $type): bool {
        if (!function_exists('opsh_can')) return true;
        if ($type === 'vtiger_create') return opsh_can($user, 'provision_onboard', 'edit');
        if ($type === 'vtiger_reassign' || $type === 'vtiger_deactivate') return opsh_can($user, 'provision_offboard', 'edit');
        if (strpos($type, 'm365_') !== 0) return true;
        return opsh_can($user, opsh_ob_type_module($type), 'edit') || opsh_can($user, 'm365_admin', 'edit');
    }

    /**
     * BT89 (M-37): which onboarding / offboarding records a person may open. A Super Admin, and a role with
     * Onboarding · All records, sees every one; anybody else sees the ones they started and the ones of their own department.
     */
    function opsh_ob_sees_all(array $user): bool {
        if (!empty($user['_scheduler']) || ($user['role_slug'] ?? '') === 'super_admin') return true;
        return function_exists('opsh_can') && opsh_can($user, 'onboarding', 'all');
    }
    function opsh_ob_run_visible(array $user, array $run): bool {
        if (opsh_ob_sees_all($user)) return true;
        if ((int)($run['created_by'] ?? 0) > 0 && (int)$run['created_by'] === (int)($user['id'] ?? 0)) return true;
        $d = (int)($user['department_id'] ?? 0);
        return $d > 0 && (int)($run['department_id'] ?? 0) === $d;
    }
    /** SQL condition (and its values) limiting opsh_ob_runs (alias $a) to the records this person may open. */
    function opsh_ob_scope_sql(array $user, string $a = 'r'): array {
        if (opsh_ob_sees_all($user)) return ['1=1', []];
        $d = (int)($user['department_id'] ?? 0);
        if ($d > 0) return ['(' . $a . '.created_by = ? OR ' . $a . '.department_id = ?)', [(int)($user['id'] ?? 0), $d]];
        return [$a . '.created_by = ?', [(int)($user['id'] ?? 0)]];
    }

    /** BT89: a temporary password: 16 characters, upper and lower case, digits and symbols, no look-alike characters. */
    function opsh_ob_temp_password(int $len = 16): string {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%*+-=?@'];
        $all = implode('', $sets); $out = [];
        foreach ($sets as $set) $out[] = $set[random_int(0, strlen($set) - 1)];
        while (count($out) < $len) $out[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($out) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$out[$i], $out[$j]] = [$out[$j], $out[$i]]; }
        return implode('', $out);
    }

    /**
     * BT89 (M-35): one step at a time. The step is locked while it runs (a second click, another tab or the scheduler
     * waits its turn and then finds the step done), and the checks of opsh_ob_task_do run on what the database holds now.
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
    function opsh_ob_m365_guard(PDO $db, array $run, array $task, array $cfg, array $currentUser, callable $refuse): void {
        $type = (string)$task['task_type'];
        $actor = $currentUser;
        if (!empty($currentUser['_scheduler'])) {
            $q = $db->prepare("SELECT u.id, u.display_name, u.status, r.slug AS role_slug, r.permissions AS role_perms FROM opsh_users u LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE u.id=?"); $q->execute([(int)$run['created_by']]);
            $actor = $q->fetch(PDO::FETCH_ASSOC) ?: ['role_slug' => '', 'status' => 'gone'];
            // BT124 (review SEC-15): a scheduled step runs only while the person who scheduled it still may run it
            if (($actor['status'] ?? '') !== 'active' || !opsh_ob_type_allowed($actor, $type)) $refuse('The person who scheduled this no longer has the ' . opsh_ob_type_perm_label($type) . ' permission (or is no longer active): run the step by hand.', 403, 'scheduler: permission gone');
        }
        $super = ($actor['role_slug'] ?? '') === 'super_admin';
        $subj = strtolower(trim((string)($run['subject_upn'] ?: $run['subject_email'])));
        $em = []; try { $v = $db->query("SELECT sval FROM opsh_settings WHERE skey='sa_emergency'")->fetchColumn(); $em = array_map('strtolower', array_filter((array)json_decode((string)$v, true), 'is_string')); } catch (\Throwable $e) {}
        if ($subj !== '' && in_array($subj, $em, true)) $refuse('This is an emergency access account (Posture → Tenant profile). Onboarding and offboarding never change it.', 409, 'emergency access account');
        if ($super) return;
        if ($type !== 'm365_create' && $subj !== '') {
            require_once __DIR__ . '/M365Guard.php';
            try { $roles = opsh_m365_admin_roles($subj); } catch (\Throwable $e) { $refuse('The admin roles of ' . $subj . ' could not be checked (' . mb_substr($e->getMessage(), 0, 200) . '). A Super Admin must run this step.', 403, 'admin roles not checked'); return; }
            if ($roles) $refuse($subj . ' holds a Microsoft 365 admin role (' . implode(', ', array_slice($roles, 0, 3)) . '): only a Super Admin can run this step' . (!empty($currentUser['_scheduler']) ? ' (the run was scheduled by someone who is not).' : '.'), 403, 'admin account');
        }
        if (in_array($type, ['m365_forward', 'm365_delegate_onedrive'], true)) {
            $to = trim((string)($cfg['to'] ?? ''));
            $inside = false;
            try { $u = opsh_graph_get('users/' . rawurlencode($to) . '?$select=id,userType,accountEnabled'); $inside = !empty($u['id']) && ($u['userType'] ?? 'Member') === 'Member'; } catch (\Throwable $e) {}
            if (!$inside) $refuse(($type === 'm365_forward' ? 'Forwarding mail' : 'Sharing the OneDrive') . ' to ' . $to . ' is only done to someone in your organization (a member account). A Super Admin can send it outside.', 403, 'outside the organization');
        }
        if ($type === 'm365_group') {
            foreach (array_keys(opsh_ob_groups($cfg)) as $gid) {
                try { $g = opsh_graph_get('groups/' . rawurlencode((string)$gid) . '?$select=id,displayName,isAssignableToRole'); }
                catch (\Throwable $e) { $refuse('The group ' . $gid . ' could not be checked (' . mb_substr($e->getMessage(), 0, 200) . ').', 409, 'group not checked'); return; }
                if (!empty($g['isAssignableToRole'])) $refuse('"' . ($g['displayName'] ?? $gid) . '" grants admin roles: only a Super Admin adds people to it.', 403, 'admin group');
            }
        }
    }

    function opsh_ob_task_action(PDO $db, int $id, int $sid, array $body, array $currentUser): array {
        if (($body['action'] ?? 'done') === 'preview') return opsh_ob_task_do($db, $id, $sid, $body, $currentUser);
        $lk = $db->quote('opsh_ob_task_' . $sid);
        $got = (int)$db->query('SELECT GET_LOCK(' . $lk . ', 0)')->fetchColumn();
        if ($got !== 1) throw new OpshObError('This step is running right now (another click, another window or the scheduler). Wait a moment and reload: nothing was changed by this click.', 409);
        try { return opsh_ob_task_do($db, $id, $sid, $body, $currentUser); }
        finally { try { $db->query('SELECT RELEASE_LOCK(' . $lk . ')'); } catch (\Throwable $e) {} }
    }

    function opsh_ob_task_do(PDO $db, int $id, int $sid, array $body, array $currentUser): array {
        $action = $body['action'] ?? 'done';
        if (!in_array($action, ['run', 'done', 'skip', 'preview', 'leave'], true)) opsh_ob_fail('Unknown action for a step', 400);
        $rq = $db->prepare("SELECT * FROM opsh_ob_runs WHERE id=?"); $rq->execute([$id]); $run = $rq->fetch();
        if (!$run || !opsh_ob_run_visible($currentUser, $run)) opsh_ob_fail('Run not found', 404);
        $tq = $db->prepare("SELECT * FROM opsh_ob_run_tasks WHERE id=? AND run_id=?"); $tq->execute([$sid, $id]); $task = $tq->fetch();
        if (!$task) opsh_ob_fail('Task not found', 404);
        $cfg = json_decode($task['config'] ?? '{}', true) ?: [];
        $result = ''; $status = 'done'; $secret = null; $detail = [];
        $who = (string)($currentUser['display_name'] ?? 'an administrator');
        $isSuper = ($currentUser['role_slug'] ?? '') === 'super_admin';
        // BT89: every refusal is in the Audit Log (who, which step, what was wanted, why), then the step is left as it was.
        $refuse = function (string $msg, int $code, string $why, array $more = []) use ($id, $task, $action) {
            try { audit_log('ob_task_refused', 'onboarding', $id, mb_substr((string)$task['title'], 0, 250), null, ['Step' => (string)$task['title'], 'Type' => (string)$task['task_type'], 'Wanted' => $action, 'Why' => $why, 'Result' => 'Nothing was changed'] + $more); } catch (\Throwable $e) {}
            throw new OpshObError($msg, $code);
        };
        $isVt = in_array($task['task_type'], ['vtiger_create', 'vtiger_reassign', 'vtiger_deactivate'], true);

        if ($action === 'preview') {
            // Read-only: what the vTiger user owns + who can receive it. Nothing changes.
            if (!in_array($task['task_type'], ['vtiger_reassign', 'vtiger_deactivate'], true)) opsh_ob_fail('Preview is only available for vTiger offboarding steps', 400);
            if (!opsh_ob_type_allowed($currentUser, $task['task_type'])) $refuse('Looking at the vTiger records of this person needs the ' . opsh_ob_type_perm_label($task['task_type']) . ' permission.', 403, 'needs ' . opsh_ob_type_perm_label($task['task_type']));
            $vu = opsh_vt_run_user($db, $run);
            if (empty($vu['ok'])) opsh_ob_fail($vu['error'] ?? 'Could not reach the vTiger bridge', 502);
            if (empty($vu['user'])) return ['preview' => ['user' => null, 'note' => 'No vTiger user found with ' . ($run['subject_email'] ?: 'this email') . '.']];
            $pv = opsh_vt_call($db, 'reassign_preview', ['from' => (string)$vu['user']['id']]);
            if (empty($pv['ok'])) opsh_ob_fail($pv['error'] ?? 'Could not count records', 502);
            $out = ['user' => $vu['user'], 'modules' => $pv['modules'] ?? [], 'total' => (int)($pv['total'] ?? 0)];
            if ($task['task_type'] === 'vtiger_reassign') {
                $lu = opsh_vt_call($db, 'list_users', ['status' => 'Active']);
                $out['targets'] = array_values(array_filter($lu['users'] ?? [], fn($u) => (string)$u['id'] !== (string)$vu['user']['id']));
                // BT89: the new owner set on the step (by the Offboard wizard, the template, or the first run) is fixed.
                if (trim((string)($cfg['to_id'] ?? '')) !== '') { $out['default_target'] = (string)$cfg['to_id']; $out['fixed_target'] = true; }
                elseif (!empty($cfg['to_email'])) {
                    foreach ($out['targets'] as $t) if (strcasecmp($t['email'], (string)$cfg['to_email']) === 0) { $out['default_target'] = (string)$t['id']; $out['fixed_target'] = true; break; }
                }
                $out['can_change_target'] = $isSuper;
                $out['left_remind_days'] = function_exists('opsh_ob_vt_left_days') ? opsh_ob_vt_left_days($db) : 14;   // BT93
            }
            return ['preview' => $out];
        }

        // BT89 (M-35): only a process in progress has steps to work, and a step that is done or skipped stays that way.
        if ($run['status'] !== 'in_progress') $refuse('This ' . ($run['kind'] === 'offboard' ? 'offboarding' : 'onboarding') . ' is ' . str_replace('_', ' ', (string)$run['status']) . ': its steps cannot change any more.', 409, 'the process is ' . $run['status']);
        if ($task['status'] === 'done' || $task['status'] === 'skipped') $refuse('This step is already ' . ($task['status'] === 'done' ? 'done' : 'skipped') . (trim((string)$task['result']) !== '' ? ': ' . mb_substr((string)$task['result'], 0, 200) : '.'), 409, 'the step is already ' . $task['status']);
        if ($action === 'skip' && (int)$task['required'] === 1) $refuse('This step is required: do it, or mark it done with a note that says what happened.', 409, 'required step');
        // BT124 (code review SEC-22): marking an automated Microsoft 365 or vTiger step done (or skipping it) needs the same
        // permission as running it — otherwise "Disable account" could be closed by someone who may not disable accounts
        if ((in_array($action, ['run', 'done', 'skip'], true) && ($isVt || (strpos($task['task_type'], 'm365_') === 0 && $task['task_type'] !== 'm365_exchange_manual'))) && !opsh_ob_type_allowed($currentUser, $task['task_type'])
            && ($isVt || (function_exists('opsh_can') && empty($currentUser['_scheduler']))))
            $refuse('Insufficient permissions — this step needs the ' . opsh_ob_type_perm_label($task['task_type']) . ' permission', 403, 'needs ' . opsh_ob_type_perm_label($task['task_type']));

        // BT44: a company-system step is finished by saying what was done in that system (removed, handed over, no account
        // there; or set up, not needed). A bare "mark done" would close the step and leave the account open in the records.
        if ($task['task_type'] === 'system_access' && $action !== 'run' && $action !== 'skip') opsh_ob_fail('Say what was done in the system: use the buttons on the step (reload the page if it shows none).', 400);

        if ($action === 'leave') {
            // BT93 (decision 10/17/2026): the records stay with the person who left, to be moved by hand later. The step is
            // finished as "left for later" (a decision, written down with its reason); deactivation then does not ask again,
            // and the scheduler reminds the process owner until the records are moved.
            if ($task['task_type'] !== 'vtiger_reassign') opsh_ob_fail('Only the "Reassign vTiger records" step can leave the records for later.', 400);
            if (!opsh_ob_type_allowed($currentUser, $task['task_type'])) $refuse('Leaving the vTiger records for later needs the ' . opsh_ob_type_perm_label($task['task_type']) . ' permission.', 403, 'needs ' . opsh_ob_type_perm_label($task['task_type']));
            $why = mb_substr(trim((string)($body['reason'] ?? '')), 0, 300);
            if (mb_strlen($why) < 3) opsh_ob_fail('Say why the records stay for now and who will move them (for example "Sales will split them by region").', 400);
            $vu = opsh_vt_run_user($db, $run);
            if (empty($vu['ok'])) opsh_ob_fail($vu['error'] ?? 'Could not reach the vTiger bridge', 502);
            $n = 0; $mods = [];
            if (!empty($vu['user'])) {
                $pv = opsh_vt_call($db, 'reassign_preview', ['from' => (string)$vu['user']['id']]);
                if (empty($pv['ok'])) opsh_ob_fail($pv['error'] ?? 'Could not count records', 502);
                $n = (int)($pv['total'] ?? 0); $mods = (array)($pv['modules'] ?? []);
            }
            $cfg['left_for_later'] = ['count' => $n, 'modules' => $mods, 'reason' => $why, 'at' => date('Y-m-d H:i:s'), 'by' => (int)$currentUser['id'],
                                      'vtiger_id' => !empty($vu['user']['id']) ? (string)$vu['user']['id'] : null];
            $db->prepare('UPDATE opsh_ob_run_tasks SET config=? WHERE id=?')->execute([json_encode($cfg), $sid]);
            $result = empty($vu['user']) ? 'Left for later — no vTiger user found with ' . $run['subject_email'] . ' (nothing to move). Reason: ' . $why
                    : 'Left for later: ' . $n . ' record(s)' . ($mods ? ' (' . opsh_vt_mods_text($mods) . ')' : '') . ' stay with ' . opsh_vt_user_label($vu['user']) . ' to be moved by hand. Reason: ' . $why;
            $detail = ['left_for_later' => $n, 'reason' => $why];
            try { audit_log('ob_vtiger_records_left', 'onboarding', $id, mb_substr((string)$task['title'], 0, 250), null, ['Records' => $n, 'Modules' => $mods ? opsh_vt_mods_text($mods) : '', 'Reason' => $why, 'Person' => (string)$run['subject_name']]); } catch (\Throwable $e) {}
        } elseif ($action === 'skip') {
            $status = 'skipped';
        } elseif ($action === 'run') {
            $isM365 = strpos($task['task_type'], 'm365_') === 0;
            if ($isM365) {
                require_once __DIR__ . '/Graph.php';
                if (!function_exists('opsh_graph_configured') || !opsh_graph_configured()) opsh_ob_fail('Microsoft 365 is not configured', 400);
                opsh_ob_m365_guard($db, $run, $task, $cfg, $currentUser, $refuse);   // BT124 (review SEC-1, SEC-2, M365B-3)
            }
            try {
                switch ($task['task_type']) {
                    case 'm365_create': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        if (!$upn) opsh_ob_fail('This run has no user principal name / email to create');
                        // Already there (a re-run, or created by hand)? Link it instead of failing.
                        [$xc, $xu] = opsh_graph_http('GET', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($upn) . '?$select=id,userPrincipalName');
                        if ($xc === 200 && !empty($xu['id'])) {
                            $db->prepare('UPDATE opsh_ob_runs SET m365_id=? WHERE id=?')->execute([$xu['id'], $id]);
                            $result = 'Account ' . $upn . ' already exists — linked to this process (no new password generated)';
                            break;
                        }
                        $pass = opsh_ob_temp_password();
                        $payload = ['accountEnabled' => true, 'displayName' => $run['subject_name'],
                            'mailNickname' => preg_replace('/@.*/', '', $upn), 'userPrincipalName' => $upn,
                            'passwordProfile' => ['forceChangePasswordNextSignIn' => true, 'password' => $pass]];
                        if (!empty($cfg['usageLocation'])) $payload['usageLocation'] = strtoupper(substr($cfg['usageLocation'], 0, 2));
                        // Extra Entra properties from the template (company, employee type, address…) — allow-listed
                        // by opsh_graph_settable_fields(). Person details from the process override them below.
                        foreach (opsh_graph_clean_fields($cfg['fields'] ?? []) as $fk => $fv) $payload[$fk] = $fv;
                        // Person details from the process override the template defaults.
                        $dep = ($run['department'] ?? '') ?: ($cfg['department'] ?? ''); if ($dep !== '') $payload['department'] = (string)$dep;
                        $jt  = ($run['job_title'] ?? '') ?: ($cfg['jobTitle'] ?? '');    if ($jt !== '')  $payload['jobTitle']   = (string)$jt;
                        foreach (['first_name' => 'givenName', 'last_name' => 'surname', 'employee_id' => 'employeeId', 'mobile_phone' => 'mobilePhone', 'office_location' => 'officeLocation'] as $rk => $gk)
                            if (!empty($run[$rk])) $payload[$gk] = (string)$run[$rk];
                        $u = opsh_graph_send('POST', 'users', $payload);
                        if (!empty($u['id'])) $db->prepare('UPDATE opsh_ob_runs SET m365_id=? WHERE id=?')->execute([$u['id'], $id]);
                        $warn = [];
                        if (!empty($run['manager_upn']) && !empty($u['id'])) {   // best effort: the account is never lost because of this
                            try {
                                $mg = opsh_graph_get('users/' . rawurlencode((string)$run['manager_upn']) . '?$select=id');
                                opsh_graph_retry(fn() => opsh_graph_send('PUT', 'users/' . rawurlencode($u['id']) . '/manager/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . $mg['id']]));
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
                        $skus = opsh_ob_skus($cfg);
                        if (!$skus) opsh_ob_fail('This task has no license configured');
                        // Seat check first, so a full subscription is reported clearly instead of a Graph error.
                        $noSeat = [];
                        try { $seats = opsh_ob_seats(); foreach ($skus as $skuId => $lab) if (isset($seats[$skuId]) && $seats[$skuId]['available'] <= 0) $noSeat[$skuId] = $lab; } catch (\Throwable $e) {}
                        $have = [];
                        try { $cu = opsh_graph_retry(fn() => opsh_graph_get('users/' . rawurlencode($upn) . '?$select=assignedLicenses')); $have = array_column($cu['assignedLicenses'] ?? [], 'skuId'); } catch (\Throwable $e) { throw $e; }
                        $toAdd = array_values(array_diff(array_keys($skus), $have, array_keys($noSeat)));
                        if ($toAdd) opsh_graph_retry(fn() => opsh_graph_send('POST', 'users/' . rawurlencode($upn) . '/assignLicense', ['addLicenses' => array_map(fn($x) => ['skuId' => $x], $toAdd), 'removeLicenses' => []]));
                        $parts = [];
                        foreach ($skus as $skuId => $lab) $parts[] = $lab . (in_array($skuId, $toAdd, true) ? ' assigned' : (isset($noSeat[$skuId]) ? ' NOT assigned — no seats left' : ' already assigned'));
                        $result = 'Licenses: ' . implode(', ', $parts);
                        $detail = ['assigned' => $toAdd, 'no_seats' => array_keys($noSeat)];
                        if ($noSeat) {   // partial failure is reported, not hidden: mark failed so it can be retried after buying seats
                            $db->prepare("UPDATE opsh_ob_run_tasks SET status='failed', result=?, completed_by=?, completed_at=NOW() WHERE id=?")->execute([substr($result, 0, 500), (int)$currentUser['id'], $sid]);
                            audit_log('ob_task_failed', 'onboarding', $id, $task['title'], null, ['type' => $task['task_type'], 'result' => $result]);
                            opsh_ob_fail($result, 409);
                        }
                        break;
                    }
                    case 'm365_license_remove': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        if (empty($cfg['skuId'])) opsh_ob_fail('This task has no license configured');
                        opsh_graph_send('POST', 'users/' . rawurlencode($upn) . '/assignLicense', ['addLicenses' => [], 'removeLicenses' => [$cfg['skuId']]]);
                        $result = 'License removed (' . ($cfg['skuLabel'] ?? $cfg['skuId']) . ')';
                        break;
                    }
                    case 'm365_group': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $gs = opsh_ob_groups($cfg);
                        if (!$gs) opsh_ob_fail('This task has no group configured');
                        $u = opsh_graph_retry(fn() => opsh_graph_get('users/' . rawurlencode($upn) . '?$select=id'));
                        $parts = []; $failed = [];
                        foreach ($gs as $gid => $gname) {
                            try {
                                opsh_graph_retry(fn() => opsh_graph_send('POST', 'groups/' . rawurlencode($gid) . '/members/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/directoryObjects/' . $u['id']]));
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
                        $me = opsh_graph_get('users/' . rawurlencode($upn) . '?$select=id');
                        $keep = array_filter(array_map('trim', explode(',', strtolower((string)($cfg['keep'] ?? '')))));
                        // BT124 (review M365A-9): every page of memberships, not only the first
                        $mo = opsh_graph_paged('users/' . rawurlencode($upn) . '/memberOf?$select=id,displayName,groupTypes,onPremisesSyncEnabled&$top=100', 50);
                        $removed = []; $skipped = []; $failed = [];
                        foreach ($mo as $g) {
                            if (($g['@odata.type'] ?? '#microsoft.graph.group') !== '#microsoft.graph.group') continue;   // directory roles etc.
                            $gn = (string)($g['displayName'] ?? $g['id']);
                            if (in_array(strtolower($gn), $keep, true)) { $skipped[] = $gn . ' (kept by template)'; continue; }
                            if (in_array('DynamicMembership', $g['groupTypes'] ?? [], true)) { $skipped[] = $gn . ' (dynamic)'; continue; }
                            if (!empty($g['onPremisesSyncEnabled'])) { $skipped[] = $gn . ' (synced from on-premises AD)'; continue; }
                            try { opsh_graph_send('DELETE', 'groups/' . rawurlencode($g['id']) . '/members/' . rawurlencode($me['id']) . '/$ref'); $removed[] = $gn; }
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
                        $lu = opsh_graph_get('users/' . rawurlencode($upn) . '?$select=assignedLicenses');
                        $skus = array_values(array_filter(array_map(fn($l) => $l['skuId'] ?? '', $lu['assignedLicenses'] ?? [])));
                        if ($skus) opsh_graph_send('POST', 'users/' . rawurlencode($upn) . '/assignLicense', ['addLicenses' => [], 'removeLicenses' => $skus]);
                        $result = $skus ? count($skus) . ' license(s) removed' : 'No licenses were assigned';
                        $detail = ['removed_skus' => $skus];
                        break;
                    }
                    case 'm365_disable': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        if (strcasecmp((string)$upn, (string)($currentUser['email'] ?? '')) === 0) opsh_ob_fail('You cannot disable your own account from here', 400);
                        opsh_graph_send('PATCH', 'users/' . rawurlencode($upn), ['accountEnabled' => false]);
                        $result = 'Account disabled (sign-in blocked)';
                        break;
                    }
                    case 'm365_revoke_sessions': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        opsh_graph_send('POST', 'users/' . rawurlencode($upn) . '/revokeSignInSessions', []);   // empty body is sent as {}
                        $result = 'All sign-in sessions & refresh tokens revoked';
                        break;
                    }
                    case 'assign_reading': {
                        $docs = $cfg['docs'] ?? [];
                        $email = $run['subject_email'];
                        if (!$email) opsh_ob_fail('This run has no email to send the reading sign-off to');
                        $made = 0;
                        foreach ($docs as $d) {
                            $dtype = $d['type'] ?? ''; $did = (int)($d['id'] ?? 0); $dtitle = $d['title'] ?? 'Document';
                            if (!$dtype || !$did) continue;
                            $db->prepare("INSERT INTO opsh_signature_requests (doc_type,doc_id,doc_title,message,status,requested_by,created_at) VALUES (?,?,?,?, 'open', ?, NOW())")
                               ->execute([$dtype, $did, substr($dtitle, 0, 255), 'Required reading for onboarding — please review and sign.', (int)$currentUser['id']]);
                            $reqId = (int)$db->lastInsertId();
                            $tok = bin2hex(random_bytes(24));
                            $db->prepare("INSERT INTO opsh_signature_signers (request_id,user_id,signer_name,signer_email,sign_token,status) VALUES (?, NULL, ?, ?, ?, 'pending')")
                               ->execute([$reqId, $run['subject_name'], $email, $tok]);
                            if (defined('APP_URL')) {
                                require_once __DIR__ . '/MailIntake.php';
                                $link = rtrim(APP_URL, '/') . '/sign.html?token=' . $tok;
                                try { opsh_email('onboarding_sign', $email, ['person_name' => (string)$run['subject_name'], 'document' => (string)$dtitle, 'sign_link' => $link,
                                    'recipient_name' => (string)$run['subject_name']]); } catch (\Throwable $e) {}
                            }
                            $made++;
                        }
                        $result = $made . ' document(s) sent for signature to ' . $email;
                        break;
                    }
                    case 'vtiger_create': {
                        require_once __DIR__ . '/VTigerProvision.php';
                        if (!$run['subject_email']) opsh_ob_fail('This run has no email for the vTiger user');
                        if (!opsh_vtiger_url_secure((string)(opsh_vtiger_cfg($db)['prov']['bridge_url'] ?? ''))) $refuse('The vTiger bridge address is not HTTPS: ' . opsh_app_name() . ' does not send a new password over it. Use https:// in Settings → Company systems → vTiger.', 409, 'bridge address is not HTTPS');
                        $nm = trim($run['subject_name']); $sp = strpos($nm, ' ');
                        $first = ($run['first_name'] ?? '') ?: ($sp !== false ? substr($nm, 0, $sp) : $nm);
                        $last  = ($run['last_name'] ?? '') ?: ($sp !== false ? trim(substr($nm, $sp + 1)) : $nm);
                        $vres = opsh_vtiger_create_user($db, [
                            'email' => $run['subject_email'], 'first_name' => $first, 'last_name' => $last,
                            'roleid' => $cfg['roleid'] ?? '',
                        ]);
                        if (empty($vres['ok']) && ($vres['code'] ?? '') === 'already_exists') {
                            $vu = opsh_vt_run_user($db, $run);   // links vtiger_id on the run
                            $result = 'vTiger user already exists' . (!empty($vu['user']) ? ' (' . opsh_vt_user_label($vu['user']) . ', ' . $vu['user']['status'] . ')' : '') . ' — linked, no new password generated';
                            break;
                        }
                        if (empty($vres['ok'])) throw new \RuntimeException($vres['error'] ?? 'vTiger create failed');
                        if (!empty($vres['id'])) $db->prepare('UPDATE opsh_ob_runs SET vtiger_id=? WHERE id=?')->execute([(string)$vres['id'], $id]);
                        $result = 'vTiger user created (via ' . ($vres['via'] ?? 'bridge') . ')'
                                . (!empty($vres['password']) ? ' — temporary password generated and shown once to ' . $who . ' (not stored)' : '');
                        if (!empty($vres['password'])) $secret = ['label' => 'vTiger temporary password', 'account' => strstr((string)$run['subject_email'], '@', true) ?: (string)$run['subject_email'], 'value' => $vres['password']];
                        break;
                    }
                    case 'vtiger_reassign': {
                        // BT40: a user found by user name only is never acted on until a person confirmed it (vtiger_id sent with this click, or stored on the step).
                        $vtUser = opsh_vt_run_user_to_change($db, $run, is_scalar($body['vtiger_id'] ?? null) ? trim((string)$body['vtiger_id']) : '', 'Reassign vTiger records');
                        if (!$vtUser) { $result = 'No vTiger user with ' . $run['subject_email'] . ' — nothing to reassign'; break; }
                        $vu = ['ok' => true, 'user' => $vtUser];
                        // BT89 (H-11): the records of a vTiger admin are moved by a Super Admin only.
                        if (!empty($vtUser['is_admin']) && !$isSuper) $refuse('"' . opsh_vt_user_label($vtUser) . '" is a vTiger admin: only a Super Admin can move their records.', 403, 'vTiger admin target', ['vTiger user' => (string)($vtUser['user_name'] ?? '')]);
                        // BT89 (H-11): the new owner is the one set on the step (Offboard wizard, template, or the first run of the step).
                        $fixed = trim((string)($cfg['to_id'] ?? ''));   // BT21: the Offboard wizard stores the new owner on the step (also used when scheduled)
                        if ($fixed === '' && !empty($cfg['to_email'])) {
                            $tu = opsh_vt_call($db, 'find_user', ['email' => (string)$cfg['to_email']]);
                            if (!empty($tu['user']['id'])) $fixed = (string)$tu['user']['id'];
                            elseif (empty($tu['ok'])) throw new \RuntimeException($tu['error'] ?? 'Could not find the new owner in vTiger');
                            else opsh_ob_fail('The new owner set on this step (' . $cfg['to_email'] . ') is not a vTiger user. Nothing was moved.', 409);
                        }
                        $sent = is_scalar($body['to_id'] ?? null) ? trim((string)$body['to_id']) : '';
                        if ($sent !== '' && !ctype_digit($sent)) opsh_ob_fail('The new owner is not a vTiger user id', 400);
                        if ($fixed !== '' && $sent !== '' && $sent !== $fixed) {
                            if (!$isSuper) $refuse('The new owner of these records is set on this step and cannot be changed here. Only a Super Admin can change it. Nothing was moved.', 409, 'different new owner', ['Owner on the step' => $fixed, 'Owner sent' => mb_substr($sent, 0, 32)]);
                            try { audit_log('ob_vtiger_owner_changed', 'onboarding', $id, mb_substr((string)$task['title'], 0, 250), ['New owner' => $fixed], ['New owner' => $sent]); } catch (\Throwable $e) {}
                            $fixed = '';
                        }
                        $to = $fixed !== '' ? $fixed : $sent;
                        if ($to === '') opsh_ob_fail('Choose who receives the records (new owner)', 400);
                        if ($to === (string)$vtUser['id']) opsh_ob_fail('The new owner cannot be the person who is leaving', 400);
                        if ($fixed === '') {   // first choice: written on the step, so a retry or the scheduler uses the same owner
                            $cfg['to_id'] = $to; unset($cfg['to_email']);
                            $db->prepare('UPDATE opsh_ob_run_tasks SET config=? WHERE id=?')->execute([json_encode($cfg), $sid]);
                            try { audit_log('ob_vtiger_owner_set', 'onboarding', $id, mb_substr((string)$task['title'], 0, 250), null, ['New owner' => $to]); } catch (\Throwable $e) {}
                        }
                        $mods = isset($body['modules']) && is_array($body['modules']) ? array_values(array_filter(array_map('strval', $body['modules'])))
                              : (isset($cfg['modules']) && is_array($cfg['modules']) ? array_values(array_filter(array_map('strval', $cfg['modules']))) : []);
                        // BT89: bridge o8 moves records in rounds of about 15 seconds; keep going while it makes progress.
                        @set_time_limit(180);
                        $movedAll = []; $movedN = 0; $rr = null;
                        for ($round = 0; $round < 8; $round++) {
                            $rr = opsh_vt_call($db, 'reassign', ['from' => (string)$vu['user']['id'], 'to' => $to] + ($mods ? ['modules' => $mods] : []));
                            if (empty($rr['ok'])) { if ($movedN) throw new \RuntimeException('Moved ' . $movedN . ' record(s), then: ' . ($rr['error'] ?? 'Reassign failed') . '. Run the step again to move the rest.'); throw new \RuntimeException($rr['error'] ?? 'Reassign failed'); }
                            foreach ((array)($rr['moved'] ?? []) as $m => $c) $movedAll[$m] = ($movedAll[$m] ?? 0) + (int)$c;
                            $movedN += (int)($rr['moved_total'] ?? 0);
                            if (empty($rr['more']) || (int)($rr['moved_total'] ?? 0) === 0) break;
                        }
                        $rr['moved'] = $movedAll; $rr['moved_total'] = $movedN;
                        $left = $mods ? array_sum(array_map(fn($m) => (int)(($rr['remaining'] ?? [])[$m] ?? 0), $mods)) : (int)($rr['remaining_total'] ?? 0);
                        if ($left > 0) throw new \RuntimeException('Moved ' . $movedN . ' record(s) (' . opsh_vt_mods_text($movedAll) . '); ' . $left . ' still to move. Run the step again to continue.');
                        $tl = opsh_vt_call($db, 'find_user', ['id' => $to]);
                        $result = 'Reassigned ' . (int)($rr['moved_total'] ?? 0) . ' record(s) (' . opsh_vt_mods_text($rr['moved'] ?? []) . ') to ' . opsh_vt_user_label($tl['user'] ?? null)
                                . ((int)($rr['remaining_total'] ?? 0) ? ' — ' . (int)$rr['remaining_total'] . ' still owned (' . opsh_vt_mods_text($rr['remaining'] ?? []) . ')' : '');
                        $detail = ['from' => (string)$vu['user']['id'], 'to' => $to, 'moved' => $rr['moved'] ?? [], 'remaining_total' => (int)($rr['remaining_total'] ?? 0), 'modules' => $mods];
                        break;
                    }
                    case 'vtiger_deactivate': {
                        $vtUser = opsh_vt_run_user_to_change($db, $run, is_scalar($body['vtiger_id'] ?? null) ? trim((string)$body['vtiger_id']) : '', 'Deactivate vTiger user');   // BT40, as above
                        if (!$vtUser) { $result = 'No vTiger user with ' . $run['subject_email'] . ' — nothing to deactivate'; break; }
                        $vu = ['ok' => true, 'user' => $vtUser];
                        if (strcasecmp((string)$vu['user']['email'], (string)($currentUser['email'] ?? '')) === 0) opsh_ob_fail('You cannot deactivate your own vTiger account from here', 400);
                        // BT89 (H-11): a vTiger admin is deactivated by a Super Admin only.
                        if (!empty($vtUser['is_admin']) && !$isSuper) $refuse('"' . opsh_vt_user_label($vtUser) . '" is a vTiger admin: only a Super Admin can deactivate them.', 403, 'vTiger admin target', ['vTiger user' => (string)($vtUser['user_name'] ?? '')]);
                        // Order matters: reassign first, so records aren't stranded under an inactive owner.
                        $pv = opsh_vt_call($db, 'reassign_preview', ['from' => (string)$vu['user']['id']]);
                        if (empty($pv['ok'])) throw new \RuntimeException($pv['error'] ?? 'Could not count records');
                        $owned = (int)($pv['total'] ?? 0);
                        $why = trim((string)($body['override_reason'] ?? ''));
                        if ($owned > 0 && $why === '') {   // BT93: records left for later on the reassign step are that step's decision
                            $lq = $db->prepare("SELECT config FROM opsh_ob_run_tasks WHERE run_id=? AND task_type='vtiger_reassign' AND status='done'"); $lq->execute([$id]);
                            foreach ($lq->fetchAll(PDO::FETCH_COLUMN) as $lc) { $lj = json_decode((string)$lc, true); if (!empty($lj['left_for_later']['reason'])) { $why = 'left for later on the reassign step: ' . $lj['left_for_later']['reason']; break; } }
                        }
                        if ($owned > 0 && $why === '') {
                            throw new OpshObError(opsh_vt_user_label($vu['user']) . ' still owns ' . $owned . ' record(s) (' . opsh_vt_mods_text($pv['modules'] ?? []) . '). Run "Reassign vTiger records" first, or give a reason to deactivate anyway.', 409,
                                ['needs_override' => true, 'total' => $owned, 'modules' => $pv['modules'] ?? []]);
                        }
                        if (($vu['user']['status'] ?? '') !== 'Inactive') {
                            $sr = opsh_vt_call($db, 'set_status', ['id' => (string)$vu['user']['id'], 'status' => 'Inactive']);
                            if (empty($sr['ok'])) throw new \RuntimeException($sr['error'] ?? 'Deactivation failed');
                        }
                        $result = 'vTiger user ' . opsh_vt_user_label($vu['user']) . ' deactivated'
                                . ($owned > 0 ? (strpos($why, 'left for later on the reassign step') === 0 ? ' — ' . $owned . ' record(s) stay with this owner, ' . $why : ' — OVERRIDE: ' . $owned . ' record(s) left with this owner. Reason: ' . $why) : ' (no records left with this owner)');
                        $detail = ['vtiger_id' => (string)$vu['user']['id'], 'owned_at_deactivation' => $owned, 'override_reason' => $why ?: null];
                        break;
                    }
                    case 'm365_autoreply': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $msg = trim((string)($cfg['message'] ?? '')) !== '' ? $cfg['message'] : ('This mailbox is no longer actively monitored. Your message has been received; please contact the appropriate team for assistance.');
                        opsh_graph_send('PATCH', 'users/' . rawurlencode($upn) . '/mailboxSettings', [
                            'automaticRepliesSetting' => ['status' => 'alwaysEnabled', 'internalReplyMessage' => $msg, 'externalReplyMessage' => $msg],
                        ]);
                        $result = 'Auto-reply (out-of-office) enabled';
                        break;
                    }
                    case 'm365_forward': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $to = trim((string)($cfg['to'] ?? ''));
                        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) opsh_ob_fail('This task needs a valid forwarding address');
                        opsh_graph_send('POST', 'users/' . rawurlencode($upn) . '/mailFolders/inbox/messageRules', [
                            'displayName' => (function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub') . ' offboarding forward', 'sequence' => 1, 'isEnabled' => true,
                            'actions' => ['forwardTo' => [['emailAddress' => ['address' => $to]]], 'stopProcessingRules' => false],
                        ]);
                        $result = 'Inbox forwarding rule created to ' . $to;
                        break;
                    }
                    case 'm365_delegate_onedrive': {
                        $upn = $run['subject_upn'] ?: $run['subject_email'];
                        $to = trim((string)($cfg['to'] ?? ''));
                        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) opsh_ob_fail('This task needs a valid delegate address');
                        opsh_graph_send('POST', 'users/' . rawurlencode($upn) . '/drive/root/invite', [
                            'recipients' => [['email' => $to]], 'roles' => ['write'], 'requireSignIn' => true, 'sendInvitation' => true,
                            'message' => 'Access to a departing colleague\'s OneDrive for handover.',
                        ]);
                        $result = 'OneDrive shared with ' . $to . ' (write access)';
                        break;
                    }
                    case 'equipment_return': {
                        // Check a loaned item back in (same as Equipment → Return).
                        if (function_exists('opsh_can') && empty($currentUser['_scheduler']) && !opsh_can($currentUser, 'assets', 'edit')) opsh_ob_fail('Checking equipment in needs the Equipment (edit) permission', 403);
                        $lid = (int)($cfg['loan_id'] ?? 0);
                        $lq = $db->prepare("SELECT l.*, a.asset_tag FROM opsh_asset_loans l JOIN opsh_assets a ON a.id=l.asset_id WHERE l.id=?"); $lq->execute([$lid]); $loan = $lq->fetch();
                        if (!$loan) opsh_ob_fail('That loan no longer exists', 404);
                        if ($loan['status'] !== 'out') { $result = $loan['asset_tag'] . ' was already returned' . (!empty($loan['returned_at']) ? ' on ' . substr($loan['returned_at'], 0, 10) : ''); break; }
                        $cond = in_array(($body['condition_in'] ?? ''), ['new', 'good', 'fair', 'poor', 'damaged'], true) ? $body['condition_in'] : 'good';
                        $newSt = in_array(($body['asset_status'] ?? ''), ['available', 'maintenance', 'lost'], true) ? $body['asset_status'] : ($cond === 'damaged' ? 'maintenance' : 'available');
                        $note = trim((string)($body['note'] ?? ''));
                        $db->prepare("UPDATE opsh_asset_loans SET status='returned', returned_at=NOW(), condition_in=?, notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?")
                           ->execute([$cond, "\nReturned during offboarding of " . $run['subject_name'] . ($note !== '' ? ': ' . $note : ''), $lid]);
                        $db->prepare("UPDATE opsh_assets SET status=?, assigned_to=NULL, cond=? WHERE id=?")->execute([$newSt, $cond, (int)$loan['asset_id']]);
                        audit_log('asset_returned', 'assets', (int)$loan['asset_id'], 'condition: ' . $cond . ' (offboarding ' . $run['subject_name'] . ')');
                        $result = $loan['asset_tag'] . ' checked in (' . $cond . ($newSt === 'lost' ? ', marked lost' : '') . ')' . ($note !== '' ? ' — ' . $note : '');
                        break;
                    }
                    case 'system_access': {
                        // BT44: done by a person in the other system; here the outcome is recorded and the person's
                        // account in that system is closed, handed over or opened in OpsHub's records.
                        require_once __DIR__ . '/Systems.php';
                        $sr = opsh_sys_step_run($db, $run, $task, $cfg, $body, $currentUser);
                        $result = (string)$sr['result']; $detail = (array)$sr['detail'];
                        break;
                    }
                    case 'send_welcome': {
                        $to = trim((string)(($cfg['to'] ?? '') ?: $run['subject_email']));
                        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) opsh_ob_fail('No valid address to send the welcome email to', 400);
                        require_once __DIR__ . '/MailIntake.php';
                        $mgrName = '';
                        if (!empty($run['manager_upn'])) { $mq = $db->prepare('SELECT display_name FROM opsh_users WHERE email=?'); $mq->execute([$run['manager_upn']]); $mgrName = (string)($mq->fetchColumn() ?: $run['manager_upn']); }
                        $r = opsh_email('onboarding_welcome', $to, [
                            'person_name' => (string)$run['subject_name'], 'recipient_name' => (string)$run['subject_name'],
                            'start_date' => opsh_tpl_d2($run['start_date'] ?? ''), 'job_title' => (string)($run['job_title'] ?? ''),
                            'manager_name' => $mgrName, 'account' => (string)(($run['subject_upn'] ?? '') ?: $run['subject_email']),
                            'message' => (string)($cfg['message'] ?? '')]);
                        if (empty($r['ok'])) throw new \RuntimeException('Welcome email not sent: ' . ($r['error'] ?? 'unknown error'));
                        $result = 'Welcome email sent to ' . $to;
                        break;
                    }
                    case 'assign_equipment': {
                        if (($body['action'] ?? '') === 'run') opsh_ob_fail('This is a manual step: issue the equipment under Equipment (with the signed acceptance), then click Mark done.', 400);
                        $cat = trim((string)($cfg['category'] ?? 'device'));
                        $result = 'Issue equipment (' . $cat . ')' . (!empty($cfg['note']) ? ' — ' . $cfg['note'] : '') . '. Record it under Equipment, capture the signature, then mark this done.';
                        break;
                    }
                    case 'notify': {
                        $msg = $cfg['message'] ?? ('Onboarding task: ' . $task['title']);
                        $to  = $cfg['email'] ?? '';
                        if ($to) { try { require_once __DIR__ . '/MailIntake.php';
                            opsh_email('onboarding_notice', $to, ['person_name' => (string)$run['subject_name'], 'task' => (string)($task['title'] ?? ''), 'message' => (string)$msg]); } catch (\Throwable $e) {} }
                        // in-app notification to admins as a fallback
                        try {
                            $admins = $db->query("SELECT u.id FROM opsh_users u JOIN opsh_roles r ON r.id=u.role_id WHERE u.status='active' AND r.slug IN ('super_admin','admin','dept_admin')")->fetchAll(PDO::FETCH_COLUMN);
                            $ni = $db->prepare("INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
                            foreach ($admins as $uid) { try { $ni->execute([(int)$uid, 'Onboarding: ' . $run['subject_name'], $msg, 'onboarding', '#onboarding_runs']); } catch (\Throwable $e) {} }
                        } catch (\Throwable $e) {}
                        $result = 'Notification sent';
                        break;
                    }
                    default:
                        $result = 'Marked done';
                }
            } catch (OpshObError $e) { throw $e; }
            catch (\Throwable $e) {
                $db->prepare("UPDATE opsh_ob_run_tasks SET status='failed', result=?, completed_by=?, completed_at=NOW() WHERE id=?")
                   ->execute([substr($e->getMessage(), 0, 500), (int)$currentUser['id'], $sid]);
                opsh_log('warning', 'onboarding', 'Task failed: ' . $task['title'] . ' — ' . $e->getMessage(), ['run' => $id]);
                opsh_ob_fail('Task failed: ' . $e->getMessage(), 502);
            }
        } else {
            $result = trim($body['note'] ?? '') !== '' ? $body['note'] : 'Marked done';
        }

        $db->prepare("UPDATE opsh_ob_run_tasks SET status=?, result=?, completed_by=?, completed_at=NOW() WHERE id=?")
           ->execute([$status, substr($result, 0, 500), (int)$currentUser['id'], $sid]);
        // Audit: what happened, never a password (the result text never contains one).
        audit_log('ob_task_' . $status, 'onboarding', $id, $task['title'], null, ['type' => $task['task_type'], 'result' => $result] + ($detail ? ['detail' => $detail] : []));

        // auto-complete the run when every task is resolved
        // A run is complete only when nothing is pending AND nothing failed (a failed step must be retried, marked done or skipped).
        // BT44: a line the Onboard wizard recorded as failed (config "recorded") is history, not work left to do.
        $pend = (int)$db->query("SELECT COUNT(*) FROM opsh_ob_run_tasks WHERE run_id=" . (int)$id . " AND (status='pending' OR (status='failed' AND COALESCE(config,'') NOT LIKE '%\"recorded\":true%'))")->fetchColumn();
        $fin = null;
        if ($pend === 0) { $fin = $db->prepare("UPDATE opsh_ob_runs SET status='completed', completed_at=NOW() WHERE id=? AND status='in_progress'"); $fin->execute([$id]); }
        if ($fin && $fin->rowCount() === 1) {   // BT89 (M-35): completed once — the evidence, the check and the summary happen one time
            // Onboarding completion -> evidence: link the configured requirement(s) to the reading docs.
            $stdIds = json_decode($run['std_req_ids'] ?? '[]', true) ?: [];
            if ($stdIds) {
                $docs = [];
                try {
                    foreach ($db->query("SELECT config FROM opsh_ob_run_tasks WHERE run_id=" . (int)$id . " AND task_type='assign_reading'")->fetchAll(PDO::FETCH_COLUMN) as $cj) {
                        $c = json_decode($cj, true) ?: [];
                        foreach (($c['docs'] ?? []) as $d) if (!empty($d['type']) && !empty($d['id'])) $docs[] = [(string)$d['type'], (int)$d['id']];
                    }
                } catch (\Throwable $e) {}
                $linked = 0;
                foreach ($stdIds as $rid) { $rid = (int)$rid;
                    foreach ($docs as $d) { list($dt, $di) = $d;
                        try {
                            $ex = $db->prepare("SELECT COUNT(*) FROM opsh_links WHERE (a_type='std_req' AND a_id=? AND b_type=? AND b_id=?) OR (a_type=? AND a_id=? AND b_type='std_req' AND b_id=?)");
                            $ex->execute([$rid, $dt, $di, $dt, $di, $rid]);
                            if ((int)$ex->fetchColumn() === 0) {
                                $db->prepare("INSERT INTO opsh_links (a_type,a_id,b_type,b_id,created_by,created_at) VALUES ('std_req',?,?,?,?,NOW())")->execute([$rid, $dt, $di, (int)$currentUser['id']]);
                                $linked++;
                            }
                        } catch (\Throwable $e) {}
                    }
                }
                if ($linked > 0) { audit_log('onboarding_std_evidence', 'onboarding', (int)$id, $linked . ' evidence link(s)'); opsh_log('info', 'onboarding', 'Onboarding completion linked ' . $linked . ' reading doc(s) as evidence', ['run' => $id]); }
            }
            opsh_log('info', 'onboarding', ($run['kind'] === 'offboard' ? 'Offboarding' : 'Onboarding') . ' completed: ' . $run['subject_name'], ['run' => $id]);
            if ($run['kind'] === 'offboard') {   // close the loop: verify both systems right away, then tell the requester/manager
                try { $rr = $db->prepare('SELECT * FROM opsh_ob_runs WHERE id=?'); $rr->execute([$id]); $verifyRes = opsh_ob_verify($db, $rr->fetch() ?: $run, true, 'on completion'); } catch (\Throwable $e) {}
                try { opsh_ob_send_summary($db, (int)$id, 'completed', $verifyRes ?? null); } catch (\Throwable $e) {}
            }
            opsh_ob_fire($run['kind'] === 'offboard' ? 'offboarding.completed' : 'onboarding.completed', ['run_id' => (int)$id, 'subject' => $run['subject_name']]);
        }
        return ['ok' => true, 'status' => $status, 'result' => $result] + ($secret ? ['secret' => $secret] : []) + (isset($verifyRes) ? ['verify' => $verifyRes] : []);
    }

    /** Open equipment loans for the person in a run (by OpsHub user or borrower email). */
    function opsh_ob_open_loans(PDO $db, array $run): array {
        $email = trim((string)($run['subject_email'] ?? ''));
        if ($email === '') return [];
        try {
            $st = $db->prepare("SELECT l.id AS loan_id, l.asset_id, l.issued_at, l.due_date, a.asset_tag, a.make, a.model, a.category
                                FROM opsh_asset_loans l JOIN opsh_assets a ON a.id=l.asset_id
                                LEFT JOIN opsh_users u ON u.id=l.user_id
                                WHERE l.status='out' AND (LOWER(l.borrower_email)=LOWER(?) OR LOWER(u.email)=LOWER(?)) ORDER BY l.issued_at");
            $st->execute([$email, $email]);
            return $st->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    /** Add an "equipment_return" step for every open loan not yet covered by one. Returns how many were added. */
    function opsh_ob_add_equipment_tasks(PDO $db, int $runId): int {
        $rq = $db->prepare('SELECT * FROM opsh_ob_runs WHERE id=?'); $rq->execute([$runId]); $run = $rq->fetch();
        if (!$run || $run['kind'] !== 'offboard') return 0;
        $have = [];
        foreach ($db->query("SELECT config FROM opsh_ob_run_tasks WHERE run_id=" . $runId . " AND task_type='equipment_return'")->fetchAll(PDO::FETCH_COLUMN) as $c) { $j = json_decode((string)$c, true) ?: []; $have[(int)($j['loan_id'] ?? 0)] = 1; }
        $max = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM opsh_ob_run_tasks WHERE run_id=" . $runId)->fetchColumn();
        $ins = $db->prepare("INSERT INTO opsh_ob_run_tasks (run_id,sort_order,title,task_type,config,assignee_role,required,due_date,status) VALUES (?,?,?,?,?,?,1,?,'pending')");
        $n = 0;
        foreach (opsh_ob_open_loans($db, $run) as $l) {
            if (isset($have[(int)$l['loan_id']])) continue;
            $name = trim(($l['make'] ?? '') . ' ' . ($l['model'] ?? ''));
            $ins->execute([$runId, $max + 1 + $n, 'Return ' . $l['asset_tag'] . ($name !== '' ? ' (' . $name . ')' : ''), 'equipment_return',
                json_encode(['loan_id' => (int)$l['loan_id'], 'asset_id' => (int)$l['asset_id'], 'asset_tag' => $l['asset_tag'], 'asset_name' => $name]), 'it', $run['start_date'] ?: null]);
            $n++;
        }
        return $n;
    }

    /** Email a summary of an offboarding (completed or after a scheduled run) to whoever started it + the manager. */
    function opsh_ob_send_summary(PDO $db, int $runId, string $status, ?array $verify = null): void {
        require_once __DIR__ . '/MailIntake.php';
        $rq = $db->prepare('SELECT * FROM opsh_ob_runs WHERE id=?'); $rq->execute([$runId]); $run = $rq->fetch(); if (!$run) return;
        $lines = [];
        foreach ($db->query("SELECT title, status, result FROM opsh_ob_run_tasks WHERE run_id=" . $runId . " ORDER BY sort_order, id")->fetchAll() as $t) {
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
        if (!empty($run['created_by'])) { $u = $db->prepare("SELECT id,email,display_name FROM opsh_users WHERE id=? AND status='active'"); $u->execute([(int)$run['created_by']]); if ($r = $u->fetch()) $to[strtolower($r['email'])] = $r; }
        if (!empty($run['auto_run_by'])) { $u = $db->prepare("SELECT id,email,display_name FROM opsh_users WHERE id=? AND status='active'"); $u->execute([(int)$run['auto_run_by']]); if ($r = $u->fetch()) $to[strtolower($r['email'])] = $r; }
        if (!empty($run['manager_upn']) && filter_var($run['manager_upn'], FILTER_VALIDATE_EMAIL) && !isset($to[strtolower($run['manager_upn'])])) $to[strtolower($run['manager_upn'])] = ['id' => null, 'email' => $run['manager_upn'], 'display_name' => ''];
        $vars = ['person_name' => (string)$run['subject_name'], 'status' => $st, 'report' => implode("\n", $lines), 'verification' => $ver,
                 'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#onboarding_runs' : ''];
        foreach ($to as $r) { if (!empty($r['email'])) { try { opsh_email('offboarding_summary', $r['email'], $vars + ['recipient_name' => $r['display_name'] ?? ''], ['user_id' => $r['id'] ? (int)$r['id'] : null]); } catch (\Throwable $e) {} } }
    }

    /**
     * Run every pending automated step of a run, server-side (used by scheduled offboarding).
     * Best effort for offboarding: a failed step does not stop the others (blocking sign-in must still
     * happen even if, say, the forwarding rule fails). Steps that need a person are left pending.
     * Returns ['status'=>'completed'|'partial', 'log'=>[...]].
     */
    function opsh_ob_run_automated(PDO $db, int $runId, array $actor): array {
        $manual = ['manual', 'assign_equipment', 'equipment_return', 'm365_exchange_manual', 'm365_create', 'vtiger_create', 'system_access'];   // creates need someone to see the password; a company-system step is done by a person in that system
        $log = [];
        $tq = $db->prepare("SELECT * FROM opsh_ob_run_tasks WHERE run_id=? AND status IN ('pending','failed') ORDER BY sort_order, id"); $tq->execute([$runId]);
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
            try { $r = opsh_ob_task_action($db, $runId, (int)$t['id'], ['action' => 'run'], $actor); $log[] = ['task' => $t['title'], 'ok' => true, 'msg' => (string)($r['result'] ?? '')]; }
            catch (\Throwable $e) { $log[] = ['task' => $t['title'], 'ok' => false, 'msg' => $e->getMessage()]; if (($phase[$t['task_type']] ?? 0) === 2) $handoverFailed = true; }
        }
        $pend = (int)$db->query("SELECT COUNT(*) FROM opsh_ob_run_tasks WHERE run_id=" . $runId . " AND status IN ('pending','failed')")->fetchColumn();
        return ['status' => $pend ? 'partial' : 'completed', 'log' => $log];
    }
}

if (!function_exists('opsh_ob_vt_left_run')) {
    /**
     * BT93: records left for later on a "Reassign vTiger records" step. Daily (cron-sla.php): count what the person still owns
     * in vTiger; none left → the step says they were moved (ob_vtiger_records_moved); still there after the reminder period
     * (setting vtiger_left_remind_days, default 14 days; 0 = no reminders) → a bell to whoever started the process and whoever
     * left the records, again every period. Returns [checked, reminded, moved].
     */
    function opsh_ob_vt_left_days(PDO $db): int {
        try { $v = $db->query("SELECT sval FROM opsh_settings WHERE skey='vtiger_left_remind_days'")->fetchColumn(); } catch (Throwable $e) { $v = false; }
        return ($v === false || $v === null || $v === '') ? 14 : max(0, min(90, (int)$v));
    }
    function opsh_ob_vt_left_run(PDO $db): array {
        $days = opsh_ob_vt_left_days($db); $checked = 0; $reminded = 0; $moved = 0;
        $rows = $db->query("SELECT rt.id, rt.run_id, rt.title, rt.config, r.subject_name, r.created_by, r.status AS run_status FROM opsh_ob_run_tasks rt JOIN opsh_ob_runs r ON r.id=rt.run_id
                            WHERE rt.task_type='vtiger_reassign' AND rt.status='done' AND rt.config LIKE '%left_for_later%' AND r.status<>'cancelled' LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cfg = json_decode((string)$r['config'], true) ?: []; $lf = $cfg['left_for_later'] ?? null;
            if (!is_array($lf) || !empty($lf['moved_at']) || empty($lf['vtiger_id'])) continue;
            $checked++;
            $pv = opsh_vt_call($db, 'reassign_preview', ['from' => (string)$lf['vtiger_id']]);
            if (empty($pv['ok'])) continue;   // the bridge may be away: try again tomorrow
            $n = (int)($pv['total'] ?? 0);
            if ($n === 0) {
                $lf['moved_at'] = date('Y-m-d H:i:s'); $lf['count_now'] = 0; $cfg['left_for_later'] = $lf;
                $db->prepare('UPDATE opsh_ob_run_tasks SET config=?, result=CONCAT(result, ?) WHERE id=?')->execute([json_encode($cfg), ' — moved by ' . date('m/d/Y'), (int)$r['id']]);
                try { $_SERVER['OPSH_USER_NAME'] = 'System (scheduler)'; audit_log('ob_vtiger_records_moved', 'onboarding', (int)$r['run_id'], mb_substr((string)$r['title'], 0, 250), ['Records' => (int)$lf['count']], ['Records' => 0, 'Person' => (string)$r['subject_name']]); } catch (Throwable $e) {}
                $moved++; continue;
            }
            $lf['count_now'] = $n; $lf['checked_at'] = date('Y-m-d H:i:s');
            $since = strtotime((string)($lf['reminded_at'] ?? $lf['at'] ?? 'now'));
            if ($days > 0 && $since && $since <= time() - $days * 86400) {
                $msg = $r['subject_name'] . ' still owns ' . $n . ' vTiger record(s) left for later on ' . date('m/d/Y', strtotime((string)$lf['at'])) . '. Reason given: ' . mb_substr((string)$lf['reason'], 0, 120);
                $ni = $db->prepare("INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
                foreach (array_unique(array_filter([(int)$r['created_by'], (int)($lf['by'] ?? 0)])) as $uid) { try { $ni->execute([$uid, 'vTiger records still to move', $msg, 'onboarding', '#onboarding_runs']); } catch (Throwable $e) {} }
                $lf['reminded_at'] = date('Y-m-d H:i:s'); $reminded++;
            }
            $cfg['left_for_later'] = $lf;
            $db->prepare('UPDATE opsh_ob_run_tasks SET config=? WHERE id=?')->execute([json_encode($cfg), (int)$r['id']]);
        }
        return [$checked, $reminded, $moved];
    }
}
