<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — /api/risk_users (BT106): risky people and their follow-up. Part of the Security assessment module.
 *
 * GET  /risk_users                         the latest list (score, level, reasons, follow-up), what could be read, the
 *                                           follow-up settings, the proposals waiting, counts                      (view)
 * GET  /risk_users/followups               every follow-up, newest first (status, dates, ticket)                    (view)
 * PUT  /risk_users/settings                the follow-up settings                                                   (edit)
 * POST /risk_users/followups/:id/dismiss   {reason} stop following this person for now (30 days)                   (edit)
 * POST /risk_users/actions/:id/approve     carry out a proposed change (also needs the Microsoft 365 permission)   (edit)
 * POST /risk_users/actions/:id/dismiss     {reason} drop a proposed change                                          (edit)
 * BT144 — Microsoft's own list (Identity Protection), read live:
 * GET  /risk_users/microsoft               every account Microsoft flags (disabled ones too): level, state, why, last
 *                                           updated, and OpsHub's score and follow-up for the same person          (view)
 * GET  /risk_users/microsoft/:id/history   that person's risk history and the detections behind it                  (view)
 * POST /risk_users/microsoft/dismiss       {ids, reason} tell Microsoft the risk is dismissed (also needs Microsoft 365 ·
 *                                           Tenant configuration · Edit; admin accounts: Super Admin only)          (edit)
 * POST /risk_users/microsoft/compromised   {ids, reason} tell Microsoft the accounts are compromised (same rights)    (edit)
 *
 * Messages, tickets and proposals are made by the assessment run (scheduled or Run now). Nothing here changes Microsoft
 * 365 except an approved proposal. Everything, refusals included, is in the Audit Log (entity type "security_assessment").
 */
require_once __DIR__ . '/../lib/RiskUsers.php';
require_once __DIR__ . '/../lib/M365Guard.php';
$db = getDB();
$what = (string)($segments[1] ?? ''); $arg = (string)($segments[2] ?? ''); $sub = (string)($segments[3] ?? '');
$need = function (string $act, string $wanted) use ($currentUser) {
    if (opsh_can($currentUser, 'security_assessment', $act)) return;
    if ($act !== 'view') audit_log('rf_refused', 'security_assessment', null, 'Risky people', null, ['Wanted' => $wanted, 'Needs' => 'Security assessment · ' . ($act === 'edit' ? 'Run' : 'View')]);
    error_response('Insufficient permissions', 403);
};
if (!opsh_rk_ready($db)) error_response('Risky people needs a database update: run db-update.php once.', 409);
$body = in_array($method, ['POST', 'PUT'], true) ? json_decode(file_get_contents('php://input') ?: '', true) : null;
if (in_array($method, ['POST', 'PUT'], true) && !is_array($body)) error_response('JSON object expected.', 400);
$actRow = function (array $a) { $L = opsh_rk_actions(); return ['id' => (int)$a['id'], 'followup_id' => (int)$a['followup_id'], 'upn' => $a['upn'], 'name' => $a['name'], 'action' => $a['action'],
    'label' => $L[$a['action']][0] ?? $a['action'], 'explains' => $L[$a['action']][2] ?? '', 'needs' => $L[$a['action']][1] ?? '', 'reason' => $a['reason'], 'status' => $a['status'],
    'created_at' => $a['created_at'], 'decided_at' => $a['decided_at'], 'result' => $a['result'], 'decided_by_name' => $a['decided_by_name'] ?? null]; };

if ($method === 'GET' && $what === '') {
    $need('view', 'See risky people');
    $rid = (int)$db->query('SELECT MAX(run_id) FROM opsh_risk_runs')->fetchColumn();
    $run = null; $people = [];
    if ($rid) {
        $q = $db->prepare('SELECT r.*, s.started_at FROM opsh_risk_runs r JOIN opsh_sa_runs s ON s.id=r.run_id WHERE r.run_id=?'); $q->execute([$rid]); $run = $q->fetch(PDO::FETCH_ASSOC);
        $run['sources'] = json_decode((string)$run['sources'], true) ?: [];
        $fu = []; foreach ($db->query("SELECT * FROM opsh_risk_followups WHERE status IN ('open','escalated') OR (closed_at >= NOW() - INTERVAL 30 DAY)")->fetchAll(PDO::FETCH_ASSOC) as $f) { $k = strtolower($f['user_oid']); if (!isset($fu[$k]) || $f['id'] > $fu[$k]['id']) $fu[$k] = $f; }
        $prev = []; $pq = $db->prepare('SELECT user_oid, score FROM opsh_risk_people WHERE run_id=(SELECT MAX(run_id) FROM opsh_risk_runs WHERE run_id < ?)'); $pq->execute([$rid]);
        foreach ($pq->fetchAll(PDO::FETCH_KEY_PAIR) as $o => $sc) $prev[strtolower($o)] = (int)$sc;
        $F = opsh_rk_factors(); $q = $db->prepare('SELECT * FROM opsh_risk_people WHERE run_id=? ORDER BY score DESC, name'); $q->execute([$rid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $codes = json_decode($p['factors'], true) ?: []; $det = json_decode((string)$p['details'], true) ?: []; $f = $fu[strtolower($p['user_oid'])] ?? null;
            $people[] = ['oid' => $p['user_oid'], 'upn' => $p['upn'], 'name' => $p['name'], 'mail' => $p['mail'], 'admin' => (bool)$p['is_admin'], 'score' => (int)$p['score'], 'level' => $p['level'],
                'before' => $prev[strtolower($p['user_oid'])] ?? null,
                'factors' => array_map(fn($c) => ['code' => $c, 'label' => $F[$c][0] ?? $c, 'points' => $F[$c][1] ?? 0, 'detail' => $det[$c] ?? '', 'steps' => $F[$c][3] ?? ''], $codes),
                'followup' => $f ? ['id' => (int)$f['id'], 'status' => $f['status'], 'deadline' => $f['deadline'], 'started_at' => $f['started_at'], 'reminded_at' => $f['reminded_at'], 'escalated_at' => $f['escalated_at'], 'ticket_id' => $f['ticket_id'] ? (int)$f['ticket_id'] : null] : null];
        }
    }
    $pending = []; foreach ($db->query("SELECT a.* FROM opsh_risk_actions a WHERE a.status='pending' ORDER BY a.created_at")->fetchAll(PDO::FETCH_ASSOC) as $a) $pending[] = $actRow($a);
    $cats = []; try { foreach ($db->query('SELECT id, name FROM opsh_ticket_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $c) $cats[] = ['id' => (int)$c['id'], 'name' => $c['name']]; } catch (Throwable $e) {}
    json_response(['run' => $run, 'people' => $people, 'settings' => opsh_rf_cfg($db), 'can_edit' => opsh_can($currentUser, 'security_assessment', 'edit'), 'pending' => $pending,
                   'categories' => $cats, 'actions' => array_map(fn($a) => ['label' => $a[0], 'needs' => $a[1], 'explains' => $a[2]], opsh_rk_actions()),
                   'factor_catalog' => array_map(fn($f) => ['label' => $f[0], 'points' => $f[1]], opsh_rk_factors())]);
}
if ($method === 'GET' && $what === 'followups') {
    $need('view', 'See the follow-ups');
    $rows = $db->query('SELECT f.*, t.ticket_number FROM opsh_risk_followups f LEFT JOIN opsh_tickets t ON t.id=f.ticket_id ORDER BY f.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    $acts = []; foreach ($db->query('SELECT a.*, u.display_name AS decided_by_name FROM opsh_risk_actions a LEFT JOIN opsh_users u ON u.id=a.decided_by ORDER BY a.id')->fetchAll(PDO::FETCH_ASSOC) as $a) $acts[(int)$a['followup_id']][] = $actRow($a);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['factors'] = json_decode($r['factors'], true) ?: []; $r['actions'] = $acts[$r['id']] ?? []; }
    json_response(['data' => $rows]);
}
if ($method === 'PUT' && $what === 'settings') {
    $need('edit', 'Change the follow-up settings');
    try { $c = opsh_rf_clean($body); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
    if (array_key_exists('ticket_category_id', $c) && $c['ticket_category_id'] !== null) { $q = $db->prepare('SELECT COUNT(*) FROM opsh_ticket_categories WHERE id=?'); $q->execute([$c['ticket_category_id']]); if (!(int)$q->fetchColumn()) error_response('Unknown ticket category.', 400); }
    $old = opsh_rf_cfg($db); $new = array_merge($old, $c);
    $db->prepare("INSERT INTO opsh_settings (skey,sval,updated_by) VALUES ('rf_cfg',?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)")->execute([json_encode($new), (int)$currentUser['id']]);
    $diffO = []; $diffN = []; foreach ($new as $k => $v) if ($old[$k] !== $v) { $diffO[$k] = is_array($old[$k]) ? implode(', ', $old[$k]) : $old[$k]; $diffN[$k] = is_array($v) ? implode(', ', $v) : $v; }
    if ($diffN) audit_log('rf_settings', 'security_assessment', null, 'Risky people follow-up', $diffO, $diffN);
    json_response(['ok' => true, 'settings' => $new]);
}
if ($method === 'POST' && $what === 'followups' && ctype_digit($arg) && $sub === 'dismiss') {
    $need('edit', 'Dismiss a follow-up');
    $q = $db->prepare('SELECT * FROM opsh_risk_followups WHERE id=?'); $q->execute([(int)$arg]); $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f) error_response('Unknown follow-up.', 404);
    if (!in_array($f['status'], ['open', 'escalated'], true)) error_response('It is already closed.', 409);
    $why = trim((string)($body['reason'] ?? '')); if ($why === '' || mb_strlen($why) > 300) error_response('Say why (up to 300 characters).', 400);
    opsh_rf_close($db, $f, 'dismissed', 'Dismissed by ' . ($currentUser['display_name'] ?? 'an administrator') . ': ' . $why, (int)$currentUser['id']);
    json_response(['ok' => true]);
}
if ($method === 'POST' && $what === 'actions' && ctype_digit($arg) && in_array($sub, ['approve', 'dismiss'], true)) {
    $need('edit', $sub === 'approve' ? 'Approve a change' : 'Dismiss a change');
    $q = $db->prepare('SELECT * FROM opsh_risk_actions WHERE id=?'); $q->execute([(int)$arg]); $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) error_response('Unknown change.', 404);
    if ($a['status'] !== 'pending') error_response('This change was already ' . $a['status'] . '.', 409);
    $L = opsh_rk_actions(); $label = $L[$a['action']][0] ?? $a['action'];
    if ($sub === 'dismiss') {
        $why = trim((string)($body['reason'] ?? '')); if ($why === '' || mb_strlen($why) > 300) error_response('Say why (up to 300 characters).', 400);
        $db->prepare("UPDATE opsh_risk_actions SET status='dismissed', decided_by=?, decided_at=?, result=? WHERE id=?")->execute([(int)$currentUser['id'], date('Y-m-d H:i:s'), 'Dismissed: ' . $why, (int)$a['id']]);
        audit_log('rf_change_dismissed', 'security_assessment', (int)$a['followup_id'], $label . ' · ' . $a['name'] . ' (' . $a['upn'] . ')', null, ['Why' => $why]);
        json_response(['ok' => true]);
    }
    $perm = $L[$a['action']][1] ?? 'm365_admin';
    if (!opsh_can($currentUser, $perm, 'edit')) {
        audit_log('rf_refused', 'security_assessment', (int)$a['followup_id'], $label . ' · ' . $a['upn'], null, ['Why' => 'needs ' . $perm . ' · edit']);
        error_response('Approving "' . $label . '" also needs the permission Microsoft 365 · ' . ['m365_groups' => 'Groups', 'm365_users' => 'Accounts', 'm365_admin' => 'Tenant configuration'][$perm] . '.', 403);
    }
    try { $res = opsh_rf_execute($db, $a, $currentUser); }
    catch (InvalidArgumentException $e) {   // BT124 (review SEC-12): refused, not failed — the change stays waiting for someone else
        audit_log('rf_refused', 'security_assessment', (int)$a['followup_id'], $label . ' · ' . $a['upn'], null, ['Why' => mb_substr($e->getMessage(), 0, 300)]);
        error_response($e->getMessage(), 409);
    }
    catch (Throwable $e) {
        $db->prepare("UPDATE opsh_risk_actions SET status='failed', decided_by=?, decided_at=?, result=? WHERE id=?")->execute([(int)$currentUser['id'], date('Y-m-d H:i:s'), mb_substr($e->getMessage(), 0, 500), (int)$a['id']]);
        audit_log('rf_change_failed', 'security_assessment', (int)$a['followup_id'], $label . ' · ' . $a['name'] . ' (' . $a['upn'] . ')', null, ['Why' => mb_substr($e->getMessage(), 0, 300)]);
        error_response('Not done: ' . $e->getMessage(), 409);
    }
    $db->prepare("UPDATE opsh_risk_actions SET status='done', decided_by=?, decided_at=?, result=? WHERE id=?")->execute([(int)$currentUser['id'], date('Y-m-d H:i:s'), $res, (int)$a['id']]);
    audit_log('rf_change_done', 'security_assessment', (int)$a['followup_id'], $label . ' · ' . $a['name'] . ' (' . $a['upn'] . ')', null, ['Result' => $res]);
    json_response(['ok' => true, 'result' => $res]);
}
if ($what === 'microsoft') {
    $userMap = function () use ($db): array {   // OpsHub's own score and follow-up for each person, from the latest run
        $m = []; $rid = (int)$db->query('SELECT MAX(run_id) FROM opsh_risk_runs')->fetchColumn(); if (!$rid) return $m;
        $q = $db->prepare('SELECT user_oid, score, level FROM opsh_risk_people WHERE run_id=?'); $q->execute([$rid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) $m[strtolower($p['user_oid'])] = ['score' => (int)$p['score'], 'level' => $p['level'], 'followup' => null];
        foreach ($db->query("SELECT user_oid, status FROM opsh_risk_followups WHERE status IN ('open','escalated') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) { $k = strtolower($f['user_oid']); $m[$k] = ($m[$k] ?? ['score' => null, 'level' => null]); $m[$k]['followup'] = $f['status']; }
        return $m;
    };
    if ($method === 'GET' && $arg === '') {
        $need('view', 'See Microsoft\'s risky users');
        // BT169: ?fresh=1 asks Microsoft for a new token first, so a permission or license added a minute ago is seen at once
        if (($_GET['fresh'] ?? '') === '1') { require_once __DIR__ . '/../lib/Graph.php'; if (function_exists('opsh_graph_token_clear')) opsh_graph_token_clear(); }
        try { $rows = opsh_rk_ms_list(); }
        catch (Throwable $e) { $dg = opsh_rk_ms_diag($e); json_response(['available' => false, 'reason' => $dg['kind'], 'message' => $dg['message'], 'data' => []]); }
        $m = $userMap(); foreach ($rows as &$r) $r['opshub'] = $m[strtolower($r['id'])] ?? null; unset($r);
        json_response(['available' => true, 'data' => $rows, 'read_at' => date('Y-m-d H:i:s'),
            'can_answer' => opsh_can($currentUser, 'security_assessment', 'edit') && opsh_can($currentUser, 'm365_admin', 'edit'),
            'truncated' => !empty($GLOBALS['OPSH_GRAPH_TRUNCATED'])]);
    }
    if ($method === 'GET' && $sub === 'history') {
        $need('view', 'See a risk history');
        try { json_response(['data' => opsh_rk_ms_history($arg)]); }
        catch (InvalidArgumentException $e) { error_response($e->getMessage(), 404); }
        catch (Throwable $e) { error_response(opsh_rk_ms_why($e), 409); }
    }
    if ($method === 'POST' && in_array($arg, ['dismiss', 'compromised'], true)) {
        $label = $arg === 'dismiss' ? 'Dismiss Microsoft\'s risk' : 'Confirm compromised to Microsoft';
        $need('edit', $label);
        if (!opsh_can($currentUser, 'm365_admin', 'edit')) {
            audit_log('rf_refused', 'security_assessment', null, $label, null, ['Why' => 'needs m365_admin · edit']);
            error_response($label . ' also needs the permission Microsoft 365 · Tenant configuration · Edit.', 403);
        }
        $ids = array_values(array_unique(array_filter(array_map('strval', (array)($body['ids'] ?? [])), fn($x) => preg_match('/^[0-9a-fA-F-]{36}$/', $x))));
        if (!$ids) error_response('Choose at least one person.', 400);
        if (count($ids) > 60) error_response('Up to 60 people at a time.', 400);
        $why = trim((string)($body['reason'] ?? '')); if ($why === '' || mb_strlen($why) > 300) error_response('Say why (up to 300 characters).', 400);
        try { $live = []; foreach (opsh_rk_ms_list() as $r) $live[strtolower($r['id'])] = $r; }
        catch (Throwable $e) { error_response(opsh_rk_ms_why($e), 409); }
        $people = [];
        foreach ($ids as $id) {
            $r = $live[strtolower($id)] ?? null;
            if (!$r) error_response('One of the people chosen is not on Microsoft\'s list any more. Reload the list.', 409);
            if ($arg === 'dismiss' && $r['state'] !== 'atRisk' && $r['state'] !== 'confirmedCompromised') error_response($r['name'] . ' is not at risk now (' . $r['state_label'] . ').', 409);
            if ($arg === 'compromised' && $r['state'] === 'confirmedCompromised') error_response($r['name'] . ' is already confirmed compromised.', 409);
            $self = (!empty($currentUser['o365_oid']) && strcasecmp((string)$currentUser['o365_oid'], $r['id']) === 0) || strcasecmp((string)($currentUser['email'] ?? ''), $r['upn']) === 0;
            if ($self) { audit_log('rf_refused', 'security_assessment', null, $label . ' · ' . $r['upn'], null, ['Why' => 'own account']); error_response('This is your own account: ask another administrator.', 403); }
            if ($r['upn'] !== '') opsh_m365_guard_target($currentUser, $r['upn'], strtolower($label));   // admin accounts: Super Admin only (audited)
            $people[] = $r;
        }
        try { opsh_rk_ms_answer($arg, array_map(fn($r) => $r['id'], $people)); }
        catch (Throwable $e) {
            $msg = $e->getMessage(); $plain = preg_match('/403|denied|Authorization|Forbidden/i', $msg) ? 'Microsoft refused it: the app needs the Microsoft Graph permission IdentityRiskyUser.ReadWrite.All (with admin consent).' : 'Not done: ' . mb_substr($msg, 0, 200);
            audit_log('rf_ms_failed', 'security_assessment', null, $label, null, ['People' => implode(', ', array_map(fn($r) => $r['upn'], $people)), 'Why' => mb_substr($msg, 0, 300)]);
            error_response($plain, 409);
        }
        foreach ($people as $r) audit_log($arg === 'dismiss' ? 'rf_ms_dismissed' : 'rf_ms_compromised', 'security_assessment', null, $label . ' · ' . $r['name'] . ' (' . $r['upn'] . ')',
            ['Level' => $r['level'], 'State' => $r['state_label']], ['State' => $arg === 'dismiss' ? 'Dismissed' : 'Confirmed compromised', 'Why' => $why]);
        json_response(['ok' => true, 'done' => count($people), 'note' => 'Microsoft may take a few minutes to show the new state.']);
    }
}
error_response('Not found', 404);
