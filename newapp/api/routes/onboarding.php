<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Onboarding / Offboarding workflows
 *
 * Templates define reusable checklists for different scenarios. Running a template
 * for a person snapshots its tasks into a run you work through; automated tasks call
 * Microsoft 365 (Phase C) or the signature engine; the rest are manual check-offs.
 *
 * Routes:
 *   GET    /onboarding/templates            list templates
 *   GET    /onboarding/templates/:id        template + its tasks
 *   POST   /onboarding/templates            create template
 *   PUT    /onboarding/templates/:id        update template
 *   DELETE /onboarding/templates/:id        delete template
 *   PUT    /onboarding/templates/:id/tasks  replace the template's task list (array)
 *   GET    /onboarding/runs                 list runs
 *   GET    /onboarding/runs/:id             run + its tasks
 *   POST   /onboarding/runs                 start a run from a template (snapshots tasks)
 *   POST   /onboarding/runs/:id/tasks/:tid  execute / mark a run task  {action:'run'|'done'|'skip'|'leave', ...}  (BT93: leave = vTiger records left for later, {reason})
 *   GET    /onboarding/runs?vt_left=1       BT93: only the processes whose vTiger records were left for later and are not moved yet
 *   POST   /onboarding/runs/:id/tasks/:tid  {action:'preview'}  vTiger tasks: records owned + possible new owners (read-only)
 *   POST   /onboarding/runs/:id/cancel      cancel a run
 *   GET    /onboarding/runs/:id/preflight   pre-flight: will each pending automated step work? (read-only)
 *   POST   /onboarding/runs/:id/verify      "Verify offboarding" — re-reads M365 + vTiger, stores + audits the checklist
 *   POST   /onboarding/recertify            re-verify every completed offboarding (access recertification)
 *
 * BT89: a person sees the records they started and the ones of their department; a Super Admin and a role with
 * Onboarding · All records see every one (opsh_ob_scope_sql). Steps change only while the process is in progress, a step
 * runs once at a time, a vTiger step needs the vTiger provisioning permission, and every refusal is in the Audit Log.
 *
 * Task types: manual, m365_create, m365_license, m365_license_remove, m365_license_remove_all, m365_group,
 *             m365_disable, m365_revoke_sessions, m365_autoreply, m365_forward, m365_delegate_onedrive,
 *             assign_reading, assign_equipment, notify, vtiger_create, vtiger_reassign, vtiger_deactivate.
 * M365 task types additionally require the m365_admin permission to execute.
 *
 * Temporary passwords (M365 / vTiger create) are returned ONCE in the response as `secret` and are
 * never stored — the task result only records that one was generated and who saw it.
 */

require_once __DIR__ . '/../lib/EventLog.php';
require_once __DIR__ . '/../lib/Offboarding.php';
require_once __DIR__ . '/../lib/Onboarding.php';
require_once __DIR__ . '/../lib/ObTasks.php';

$db  = getDB();
$sec = $segments[1] ?? '';
$id  = isset($segments[2]) && $segments[2] !== '' ? (int)$segments[2] : 0;
$sub = $segments[3] ?? '';
$sid = isset($segments[4]) && $segments[4] !== '' ? (int)$segments[4] : 0;

opsh_require($currentUser, 'onboarding', $method === 'GET' ? 'view' : 'edit');

$needMig = function (\Throwable $e) {
    $c = $e->getCode();
    if ($c === '42S02' || $c === 1146 || strpos($e->getMessage(), 'Base table') !== false) {
        json_response(['data' => [], 'needs_migration' => true]);
    }
    throw $e;
};
$fire = function ($event, $payload) {
    $f = __DIR__ . '/../lib/Integrations.php';
    if (is_file($f)) { require_once $f; if (function_exists('opsh_fire_event')) { try { opsh_fire_event($event, $payload); } catch (\Throwable $e) {} } }
};
$body = in_array($method, ['POST', 'PUT'], true) ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
// BT89: the record of a process, when this person may open it (else 404, as if it did not exist).
$runFor = function (int $rid) use ($db, $currentUser) {
    $rq = $db->prepare("SELECT * FROM opsh_ob_runs WHERE id=?"); $rq->execute([$rid]); $run = $rq->fetch();
    if (!$run || !opsh_ob_run_visible($currentUser, $run)) error_response('Run not found', 404);
    return $run;
};
$obRefuse = function (?int $rid, string $name, string $wanted, string $why, string $msg, int $code) {
    audit_log('ob_refused', 'onboarding', $rid, mb_substr($name, 0, 250), null, ['Wanted' => $wanted, 'Why' => $why, 'Result' => 'Nothing was changed']);
    error_response($msg, $code);
};

// ───────────────────────────── TEMPLATES ─────────────────────────────
if ($sec === 'templates') {
    if ($method === 'GET' && !$id) {
        try { $rows = $db->query("SELECT t.*, (SELECT COUNT(*) FROM opsh_ob_template_tasks x WHERE x.template_id=t.id) AS task_count FROM opsh_ob_templates t ORDER BY t.kind, t.name")->fetchAll(); }
        catch (\Throwable $e) { $needMig($e); }
        json_response(['data' => $rows]);
    }
    if ($method === 'GET' && $id) {
        $t = $db->prepare("SELECT * FROM opsh_ob_templates WHERE id=?"); $t->execute([$id]); $tpl = $t->fetch();
        if (!$tpl) error_response('Template not found', 404);
        $tk = $db->prepare("SELECT * FROM opsh_ob_template_tasks WHERE template_id=? ORDER BY sort_order, id"); $tk->execute([$id]);
        $tpl['tasks'] = $tk->fetchAll();
        json_response($tpl);
    }
    if ($method === 'POST' && !$id) {
        if (trim($body['name'] ?? '') === '') error_response('Name is required');
        $kind = ($body['kind'] ?? 'onboard') === 'offboard' ? 'offboard' : 'onboard';
        try {
            $std = isset($body['std_req_ids']) && is_array($body['std_req_ids']) ? json_encode(array_values(array_map('intval', $body['std_req_ids']))) : null;
            $db->prepare("INSERT INTO opsh_ob_templates (name,kind,description,department_id,status,std_req_ids,created_by,created_at) VALUES (?,?,?,?, 'active', ?, ?, NOW())")
               ->execute([trim($body['name']), $kind, $body['description'] ?? '', ($body['department_id'] ?? '') ?: null, $std, (int)$currentUser['id']]);
            $newTplId = (int)$db->lastInsertId();
        } catch (\Throwable $e) { $needMig($e); }
        audit_log('ob_template_created', 'onboarding', $newTplId, $body['name']);
        json_response(['id' => $newTplId]);   // (was the audit-log row id — new templates opened the wrong record)
    }
    if ($method === 'PUT' && $id && $sub === 'tasks') {
        $tasks = $body['tasks'] ?? $body;
        if (!is_array($tasks)) error_response('tasks array required');
        // BT89 (H-11): adding, changing or removing a vTiger step (and the new owner it names) needs the vTiger provisioning permission.
        $vtSig = function (array $list): array { $o = [];
            foreach ($list as $t) { $ty = (string)($t['task_type'] ?? ''); if (strpos($ty, 'vtiger_') !== 0) continue;
                $c = $t['config'] ?? []; if (is_string($c)) $c = json_decode($c, true); $c = is_array($c) ? $c : []; ksort($c); $o[] = $ty . ':' . json_encode($c); }
            sort($o); return $o; };
        $curT = $db->prepare("SELECT task_type, config FROM opsh_ob_template_tasks WHERE template_id=?"); $curT->execute([$id]);
        $before = $vtSig($curT->fetchAll()); $after = $vtSig(array_filter($tasks, fn($t) => is_array($t) && trim((string)($t['title'] ?? '')) !== ''));
        if ($before !== $after) {
            foreach (array_unique(array_map(fn($x) => strstr($x, ':', true), array_merge(array_diff($after, $before), array_diff($before, $after)))) as $ty)
                if (!opsh_ob_type_allowed($currentUser, $ty)) $obRefuse($id, 'Template ' . $id, 'Change the vTiger steps of a template', 'needs ' . opsh_ob_type_perm_label($ty), 'Changing the vTiger steps of a template needs the ' . opsh_ob_type_perm_label($ty) . ' permission. Nothing was saved.', 403);
        }
        $db->prepare("DELETE FROM opsh_ob_template_tasks WHERE template_id=?")->execute([$id]);
        $ins = $db->prepare("INSERT INTO opsh_ob_template_tasks (template_id,sort_order,title,task_type,config,assignee_role,required,due_offset_days) VALUES (?,?,?,?,?,?,?,?)");
        $i = 0;
        // Review SEC-28: a step type must be one the app knows (a mistyped type was "run" as done)
        $known = ['manual', 'document', 'm365_create', 'm365_license', 'm365_license_remove', 'm365_license_remove_all', 'm365_group', 'm365_groups_remove_all', 'm365_disable', 'm365_revoke_sessions', 'm365_autoreply',
                  'm365_forward', 'm365_delegate_onedrive', 'm365_exchange_manual', 'assign_reading', 'assign_equipment', 'equipment_return', 'notify', 'send_welcome', 'system_access', 'vtiger_create', 'vtiger_reassign', 'vtiger_deactivate'];
        foreach ($tasks as $t) { $tt = (string)($t['task_type'] ?? 'manual'); if (trim($t['title'] ?? '') !== '' && !in_array($tt, $known, true)) error_response('Unknown step type "' . mb_substr($tt, 0, 40) . '" in "' . mb_substr((string)$t['title'], 0, 80) . '". Nothing was saved.', 400); }
        $oldN = (int)$db->query('SELECT COUNT(*) FROM opsh_ob_template_tasks WHERE template_id=' . (int)$id)->fetchColumn();
        foreach ($tasks as $t) {
            if (trim($t['title'] ?? '') === '') continue;
            $dueOff = (isset($t['due_offset_days']) && $t['due_offset_days'] !== '' && $t['due_offset_days'] !== null) ? (int)$t['due_offset_days'] : null;
            $ins->execute([$id, $i++, substr($t['title'], 0, 255), substr($t['task_type'] ?? 'manual', 0, 40),
                json_encode($t['config'] ?? new stdClass()), substr($t['assignee_role'] ?? 'it', 0, 40), !empty($t['required']) ? 1 : 0, $dueOff]);
        }
        audit_log('ob_template_tasks_updated', 'onboarding', $id, '', ['Steps' => $oldN], ['Steps' => $i]);
        json_response(['ok' => true, 'count' => $i]);
    }
    if ($method === 'PUT' && $id) {
        $sets = []; $bind = [];
        foreach (['name', 'description', 'status'] as $f) if (isset($body[$f])) { $sets[] = "$f=?"; $bind[] = $body[$f]; }
        if (isset($body['department_id'])) { $sets[] = "department_id=?"; $bind[] = $body['department_id'] ?: null; }
        if (isset($body['std_req_ids']) && is_array($body['std_req_ids'])) { $sets[] = "std_req_ids=?"; $bind[] = json_encode(array_values(array_map('intval', $body['std_req_ids']))); }
        $tq = $db->prepare('SELECT name, description, status, department_id, std_req_ids FROM opsh_ob_templates WHERE id=?'); $tq->execute([$id]); $tOld = $tq->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($sets) { $bind[] = $id; $db->prepare("UPDATE opsh_ob_templates SET " . implode(',', $sets) . " WHERE id=?")->execute($bind); }
        $tq->execute([$id]); $tNew = $tq->fetch(PDO::FETCH_ASSOC) ?: [];   // review SEC-28: template changes are in the Audit Log with values
        $tb = []; $ta = []; foreach ($tNew as $k => $v) if ((string)($tOld[$k] ?? '') !== (string)$v) { $tb[$k] = mb_substr((string)($tOld[$k] ?? ''), 0, 300); $ta[$k] = mb_substr((string)$v, 0, 300); }
        if ($ta) audit_log('ob_template_updated', 'onboarding', $id, (string)($tNew['name'] ?? ''), $tb, $ta);
        json_response(['ok' => true]);
    }
    if ($method === 'DELETE' && $id) {
        require_once __DIR__ . '/../lib/RecycleBin.php'; try { opsh_bin_put($db, 'ob_template', (int)($id), $currentUser); } catch (OpshBinRefused $__e) { error_response('Not deleted: ' . $__e->getMessage(), 409); }
        $db->prepare("DELETE FROM opsh_ob_template_tasks WHERE template_id=?")->execute([$id]);
        $db->prepare("DELETE FROM opsh_ob_templates WHERE id=?")->execute([$id]);
        audit_log('ob_template_deleted', 'onboarding', $id, '');
        json_response(['ok' => true]);
    }
    error_response('Not found', 404);
}

// ───────────────────────────── RUNS ─────────────────────────────
if ($sec === 'runs') {
    if ($method === 'GET' && !$id) {
        [$scW, $scA] = opsh_ob_scope_sql($currentUser, 'r');   // BT89 (M-37)
        try {
            $q = $db->prepare("SELECT r.*, t.name AS template_name,
                                  (SELECT COUNT(*) FROM opsh_ob_run_tasks x WHERE x.run_id=r.id) AS total,
                                  (SELECT COUNT(*) FROM opsh_ob_run_tasks x WHERE x.run_id=r.id AND x.status IN ('done','skipped')) AS done,
                                  (SELECT x.config FROM opsh_ob_run_tasks x WHERE x.run_id=r.id AND x.task_type='vtiger_reassign' AND x.status='done' AND x.config LIKE '%left_for_later%' LIMIT 1) AS vt_left_cfg
                                FROM opsh_ob_runs r LEFT JOIN opsh_ob_templates t ON t.id=r.template_id
                                WHERE $scW
                                ORDER BY (r.status='in_progress') DESC, r.created_at DESC LIMIT 200");
            $q->execute($scA); $rows = $q->fetchAll();
        } catch (\Throwable $e) { $needMig($e); }
        // BT93: vTiger records left for later and not moved yet (the daily check keeps the count; "moved" once it reaches 0)
        foreach ($rows as &$rw) {
            $lf = $rw['vt_left_cfg'] ? ((json_decode((string)$rw['vt_left_cfg'], true) ?: [])['left_for_later'] ?? null) : null; unset($rw['vt_left_cfg']);
            $rw['vt_left'] = (is_array($lf) && empty($lf['moved_at'])) ? ['count' => (int)($lf['count_now'] ?? $lf['count'] ?? 0), 'since' => $lf['at'] ?? null, 'reason' => (string)($lf['reason'] ?? '')] : null;
        }
        unset($rw);
        if (!empty($_GET['vt_left'])) $rows = array_values(array_filter($rows, fn($x) => $x['vt_left'] !== null));
        json_response(['data' => $rows, 'all_departments' => opsh_ob_sees_all($currentUser)]);
    }
    if ($method === 'GET' && $id && $sub === '') {
        $r = $db->prepare("SELECT r.*, t.name AS template_name FROM opsh_ob_runs r LEFT JOIN opsh_ob_templates t ON t.id=r.template_id WHERE r.id=?");
        $r->execute([$id]); $run = $r->fetch();
        if (!$run || !opsh_ob_run_visible($currentUser, $run)) error_response('Run not found', 404);
        $tk = $db->prepare("SELECT * FROM opsh_ob_run_tasks WHERE run_id=? ORDER BY sort_order, id"); $tk->execute([$id]);
        $run['tasks'] = $tk->fetchAll();
        if ($run['kind'] === 'offboard') {
            $covered = [];
            foreach ($run['tasks'] as $t) if ($t['task_type'] === 'equipment_return') { $j = json_decode((string)$t['config'], true) ?: []; $covered[(int)($j['loan_id'] ?? 0)] = 1; }
            $run['open_loans'] = array_map(fn($l) => $l + ['covered' => isset($covered[(int)$l['loan_id']])], opsh_ob_open_loans($db, $run));
        }
        json_response($run);
    }
    if ($method === 'POST' && !$id) {
        $tplId = (int)($body['template_id'] ?? 0);
        if (trim($body['subject_name'] ?? '') === '') error_response('The person\'s name is required');
        if (trim((string)($body['manager_upn'] ?? '')) !== '' && !filter_var(trim((string)$body['manager_upn']), FILTER_VALIDATE_EMAIL)) error_response('Manager must be an email / user principal name', 400);
        if (trim((string)($body['subject_email'] ?? '')) !== '' && !filter_var(trim((string)$body['subject_email']), FILTER_VALIDATE_EMAIL)) error_response('Enter a valid email address for the person', 400);
        // BT89: a start date is a real calendar date, within three years of today.
        $sd = trim((string)($body['start_date'] ?? ''));
        if ($sd !== '') {
            $sdOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $sd, $sm) && checkdate((int)$sm[2], (int)$sm[3], (int)$sm[1]);
            if (!$sdOk || abs(strtotime($sd . ' 12:00:00') - time()) > 3 * 366 * 86400) error_response('Enter the start date as a real date (year-month-day) within three years of today.', 400);
        }
        $t = $db->prepare("SELECT * FROM opsh_ob_templates WHERE id=?"); $t->execute([$tplId]); $tpl = $t->fetch();
        if (!$tpl) error_response('Template not found', 404);
        $kind = $tpl['kind'];
        try {
            $db->prepare("INSERT INTO opsh_ob_runs (template_id,kind,subject_name,subject_email,subject_upn,department_id,start_date,status,notes,std_req_ids,created_by,created_at)
                          VALUES (?,?,?,?,?,?,?, 'in_progress', ?, ?, ?, NOW())")
               ->execute([$tplId, $kind, trim($body['subject_name']), ($body['subject_email'] ?? '') ?: null, ($body['subject_upn'] ?? '') ?: (($body['subject_email'] ?? '') ?: null),
                          ($body['department_id'] ?? '') ?: $tpl['department_id'], ($body['start_date'] ?? '') ?: null, $body['notes'] ?? '', ($tpl['std_req_ids'] ?? null), (int)$currentUser['id']]);
        } catch (\Throwable $e) { $needMig($e); }
        $runId = (int)$db->lastInsertId();
        // Optional person details used by "M365: create account" / "vTiger: create user" (Phase B).
        $prof = [];
        foreach (['first_name' => 80, 'last_name' => 80, 'job_title' => 120, 'department' => 120, 'manager_upn' => 190, 'employee_id' => 60, 'mobile_phone' => 40, 'office_location' => 120] as $pk => $len) {
            if (isset($body[$pk]) && trim((string)$body[$pk]) !== '') $prof[$pk] = mb_substr(trim((string)$body[$pk]), 0, $len);
        }
        if (!empty($prof['manager_upn']) && !filter_var($prof['manager_upn'], FILTER_VALIDATE_EMAIL)) error_response('Manager must be an email / user principal name', 400);
        if ($prof) {
            try { $db->prepare('UPDATE opsh_ob_runs SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($prof))) . ' WHERE id=?')->execute(array_merge(array_values($prof), [$runId])); }
            catch (\Throwable $e) { /* columns arrive with db-update 2026_09_24_03 */ }
        }
        // snapshot template tasks
        $tk = $db->prepare("SELECT * FROM opsh_ob_template_tasks WHERE template_id=? ORDER BY sort_order, id"); $tk->execute([$tplId]);
        $ins = $db->prepare("INSERT INTO opsh_ob_run_tasks (run_id,sort_order,title,task_type,config,assignee_role,required,due_date,status) VALUES (?,?,?,?,?,?,?,?, 'pending')");
        $startDate = ($body['start_date'] ?? '') ?: null;
        foreach ($tk->fetchAll() as $x) {
            $due = null;
            if ($startDate && isset($x['due_offset_days']) && $x['due_offset_days'] !== null && $x['due_offset_days'] !== '') {
                $due = date('Y-m-d', strtotime($startDate . ' ' . (int)$x['due_offset_days'] . ' days'));
            }
            // BT40: "this vTiger user is the same person" belongs to one departure — it is never inherited from a template.
            $xc = $x['config'];
            if (in_array($x['task_type'], ['vtiger_reassign', 'vtiger_deactivate'], true)) { $cj = json_decode((string)$xc, true); if (is_array($cj) && array_key_exists('vtiger_id', $cj)) { unset($cj['vtiger_id']); $xc = json_encode($cj ?: new stdClass()); } }
            $ins->execute([$runId, $x['sort_order'], $x['title'], $x['task_type'], $xc, $x['assignee_role'], $x['required'], $due]);
        }
        if ($kind === 'offboard') { try { opsh_ob_add_equipment_tasks($db, $runId); } catch (\Throwable $e) {} }   // one "Return …" step per item on loan
        audit_log($kind === 'offboard' ? 'offboarding_started' : 'onboarding_started', 'onboarding', $runId, $body['subject_name']);
        opsh_log('info', 'onboarding', ($kind === 'offboard' ? 'Offboarding' : 'Onboarding') . ' started: ' . $body['subject_name'], ['run' => $runId]);
        $fire($kind === 'offboard' ? 'offboarding.started' : 'onboarding.started', ['run_id' => $runId, 'subject' => $body['subject_name']]);
        json_response(['id' => $runId]);
    }
    if ($method === 'POST' && $id && $sub === 'cancel') {
        // BT89 (M-36): only a process in progress can be cancelled (a completed one stays completed and keeps its recertification).
        $run = $runFor($id);
        if ($run['status'] !== 'in_progress') $obRefuse($id, (string)$run['subject_name'], 'Cancel', 'the process is ' . $run['status'], 'Only a process in progress can be cancelled. This one is ' . str_replace('_', ' ', (string)$run['status']) . '.', 409);
        if (($run['auto_run_status'] ?? '') === 'running') $obRefuse($id, (string)$run['subject_name'], 'Cancel', 'the scheduled run is running', 'The scheduled run is in progress right now. Wait for it to finish, then cancel.', 409);
        $c = $db->prepare("UPDATE opsh_ob_runs SET status='cancelled' WHERE id=? AND status='in_progress' AND (auto_run_status IS NULL OR auto_run_status<>'running')"); $c->execute([$id]);
        if ($c->rowCount() !== 1) $obRefuse($id, (string)$run['subject_name'], 'Cancel', 'changed meanwhile', 'This process changed meanwhile. Reload the page.', 409);
        try { $db->prepare("UPDATE opsh_ob_runs SET auto_run_status=NULL, auto_run_at=NULL WHERE id=? AND auto_run_status='scheduled'")->execute([$id]); } catch (\Throwable $e) {}
        audit_log('ob_run_cancelled', 'onboarding', $id, (string)$run['subject_name']);
        json_response(['ok' => true]);
    }
    if ($method === 'POST' && $id && $sub === 'tasks' && $sid) {
        try { $out = opsh_ob_task_action($db, (int)$id, (int)$sid, $body, $currentUser); }
        catch (OpshObError $e) { json_response(['ok' => false, 'error' => ['code' => $e->http, 'message' => $e->getMessage()]] + $e->extra, $e->http); }
        json_response(isset($out['preview']) ? $out['preview'] : $out);
    }
    if ($method === 'POST' && $id && $sub === 'equipment_sync') {
        $run = $runFor($id);
        if (!in_array($run['status'], ['in_progress', 'completed'], true)) $obRefuse($id, (string)$run['subject_name'], 'Add return steps', 'the process is ' . $run['status'], 'This process is ' . str_replace('_', ' ', (string)$run['status']) . ': no steps can be added.', 409);
        $n = opsh_ob_add_equipment_tasks($db, (int)$id);
        if ($n) { $db->prepare("UPDATE opsh_ob_runs SET status='in_progress', completed_at=NULL WHERE id=? AND status='completed'")->execute([$id]); audit_log('ob_equipment_tasks_added', 'onboarding', (int)$id, $n . ' return step(s)'); }
        json_response(['ok' => true, 'added' => $n]);
    }
    if ($method === 'PUT' && $id && $sub === 'schedule') {
        // Schedule the automated steps of an OFFBOARDING (e.g. 5:00 pm on the last day). Times are stored in UTC.
        $run = $runFor($id);
        if ($run['kind'] !== 'offboard') error_response('Only offboarding can be scheduled — onboarding creates passwords that someone has to see.', 400);
        $at = trim((string)($body['at_utc'] ?? ''));
        if ($at === '') {
            $db->prepare("UPDATE opsh_ob_runs SET auto_run_at=NULL, auto_run_status=NULL WHERE id=? AND (auto_run_status IS NULL OR auto_run_status<>'running')")->execute([$id]);
            audit_log('offboarding_schedule_cancelled', 'onboarding', (int)$id, $run['subject_name']);
            json_response(['ok' => true, 'scheduled' => null]);
        }
        if ($run['status'] !== 'in_progress') error_response('This process is not in progress', 400);
        if (($run['auto_run_status'] ?? '') === 'running') error_response('The scheduled run is in progress right now — wait for it to finish.', 409);
        // BT89: a time without a zone is read as UTC (the server's own zone never changes it).
        try { $ts = (new DateTime($at, new DateTimeZone('UTC')))->getTimestamp(); } catch (\Throwable $e) { $ts = false; }
        if ($ts === false) error_response('Invalid date/time', 400);
        if ($ts < time() - 60) error_response('Pick a time in the future', 400);
        if ($ts > time() + 400 * 86400) error_response('Pick a time within the next year', 400);
        $tk = $db->prepare("SELECT task_type FROM opsh_ob_run_tasks WHERE run_id=? AND status IN ('pending','failed')"); $tk->execute([$id]); $types = $tk->fetchAll(PDO::FETCH_COLUMN);
        foreach ($types as $tt) if (!opsh_ob_type_allowed($currentUser, $tt)) error_response('Scheduling this process needs the ' . opsh_ob_type_perm_label($tt) . ' permission', 403);
        $utc = gmdate('Y-m-d H:i:s', $ts);
        $db->prepare("UPDATE opsh_ob_runs SET auto_run_at=?, auto_run_status='scheduled', auto_run_by=?, auto_run_log=NULL WHERE id=?")->execute([$utc, (int)$currentUser['id'], $id]);
        audit_log('offboarding_scheduled', 'onboarding', (int)$id, $run['subject_name'] . ' at ' . $utc . ' UTC');
        json_response(['ok' => true, 'scheduled' => $utc]);
    }
    if ($method === 'GET' && $id && $sub === 'preflight') {
        $run = $runFor($id);
        $tk = $db->prepare("SELECT * FROM opsh_ob_run_tasks WHERE run_id=? ORDER BY sort_order, id"); $tk->execute([$id]);
        json_response(opsh_ob_preflight($db, $run, $tk->fetchAll()));
    }
    if ($method === 'POST' && $id && $sub === 'verify') {
        $run = $runFor($id);
        if ($run['kind'] !== 'offboard') error_response('Verification applies to offboarding processes', 400);
        $res = opsh_ob_verify($db, $run, true, 'manual');
        opsh_log($res['clean'] ? 'info' : 'warning', 'onboarding', 'Offboarding verification for ' . $run['subject_name'] . ': ' . ($res['clean'] ? 'clean' : count($res['issues']) . ' issue(s)'), ['run' => $id]);
        json_response($res);
    }
    error_response('Not found', 404);
}

if ($sec === 'recertify') {
    // Access recertification: re-verify every completed offboarding now (same check the monthly job runs).
    if ($method !== 'POST') error_response('Method not allowed', 405);
    @set_time_limit(300);
    [$scW, $scA] = opsh_ob_scope_sql($currentUser, 'r');   // BT89 (M-37): the records this person may open
    $rq = $db->prepare("SELECT r.* FROM opsh_ob_runs r WHERE r.kind='offboard' AND r.status='completed' AND $scW ORDER BY r.completed_at DESC LIMIT 300"); $rq->execute($scA);
    $rows = $rq->fetchAll();
    $out = ['checked' => 0, 'clean' => 0, 'failed' => []];
    foreach ($rows as $r) {
        $v = opsh_ob_verify($db, $r, true, 'recertification');
        $out['checked']++;
        if ($v['clean']) $out['clean']++; elseif (!empty($v['unchecked'])) $out['unchecked'] = ($out['unchecked'] ?? 0) + 1; else $out['failed'][] = ['id' => (int)$r['id'], 'name' => $r['subject_name'], 'issues' => $v['issues']];
    }
    audit_log('access_recertification', 'onboarding', null, $out['checked'] . ' checked, ' . count($out['failed']) . ' with issues', null, ['failed' => $out['failed']]);
    json_response($out);
}

if ($sec === 'metrics') {
    opsh_require($currentUser, 'onboarding', 'view');
    try {
        $counts = ['onboard' => ['in_progress' => 0, 'completed' => 0, 'cancelled' => 0], 'offboard' => ['in_progress' => 0, 'completed' => 0, 'cancelled' => 0]];
        foreach ($db->query("SELECT kind, status, COUNT(*) c FROM opsh_ob_runs GROUP BY kind, status")->fetchAll() as $r) {
            if (!isset($counts[$r['kind']])) $counts[$r['kind']] = ['in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
            $counts[$r['kind']][$r['status']] = (int)$r['c'];
        }
        $avg = $db->query("SELECT kind, ROUND(AVG(DATEDIFF(completed_at, created_at)),1) days, COUNT(*) n
                           FROM opsh_ob_runs WHERE status='completed' AND completed_at IS NOT NULL GROUP BY kind")->fetchAll();
        $perTpl = $db->query("SELECT t.id, t.name, t.kind, COUNT(r.id) completed, ROUND(AVG(DATEDIFF(r.completed_at, r.created_at)),1) avg_days
                              FROM opsh_ob_runs r JOIN opsh_ob_templates t ON t.id = r.template_id
                              WHERE r.status='completed' AND r.completed_at IS NOT NULL
                              GROUP BY t.id, t.name, t.kind ORDER BY completed DESC LIMIT 20")->fetchAll();
        $overdue = 0;
        try { $overdue = (int)$db->query("SELECT COUNT(*) FROM opsh_ob_run_tasks rt JOIN opsh_ob_runs r ON r.id=rt.run_id
                                          WHERE rt.status='pending' AND r.status='in_progress' AND rt.due_date IS NOT NULL AND rt.due_date < CURDATE()")->fetchColumn(); } catch (\Throwable $e) {}
        $bottle = $db->query("SELECT rt.title, COUNT(*) cnt FROM opsh_ob_run_tasks rt JOIN opsh_ob_runs r ON r.id=rt.run_id
                              WHERE rt.status='pending' AND r.status='in_progress' GROUP BY rt.title ORDER BY cnt DESC LIMIT 6")->fetchAll();
        json_response(['counts' => $counts, 'avg_days' => $avg, 'per_template' => $perTpl, 'overdue_tasks' => $overdue, 'bottlenecks' => $bottle]);
    } catch (\Throwable $e) { json_response(['counts' => [], 'avg_days' => [], 'per_template' => [], 'overdue_tasks' => 0, 'bottlenecks' => [], 'error' => 'metrics unavailable']); }
    return;
}

error_response('Not found', 404);
