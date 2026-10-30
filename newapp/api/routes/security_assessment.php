<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/security_assessment   (BT102: security assessment, step 1 — the read-only check catalog)
 *
 * GET  /security_assessment               the latest run with every check (catalog text + result + the status before),
 *                                          the schedule, whether Microsoft 365 is connected                         (view)
 * GET  /security_assessment/runs          the history: date, how it was started, score, counts                     (view)
 * GET  /security_assessment/runs/:id      one run with every check                                                 (view)
 * GET  /security_assessment/export?run=   one run as CSV (one row per check)                                       (view)
 * POST /security_assessment/run           run every check now (reads Microsoft 365; changes nothing)               (edit)
 * PUT  /security_assessment/settings      {schedule: off|weekly|daily}                                             (edit)
 * GET  /security_assessment/plan[?id=]    BT117: the current plan (the draft, else the latest signed) or one version, with
 *                                          its items, decisions, the versions, categories and people for tasks           (view)
 * POST /security_assessment/plan/build    build or rebuild the draft from the latest run (decisions carried)           (edit)
 * PUT  /security_assessment/plan/items/:id {decision, note, owner_id, due_date, expires_on, category_id} a decision      (edit)
 * POST /security_assessment/plan/sign     {name, title, note} the client contact signs off the draft                   (edit)
 * GET  /security_assessment/plan/export?id=   one plan version as CSV                                                  (view)
 * PUT  /security_assessment/profile       BT116: {baseline?, profile?, emergency?} the baseline used, the tenant profile and
 *                                          the emergency access accounts                                            (edit)
 *
 * BT123 (4e): GET drift · POST drift/:id/ack {note} · GET report (printable HTML) · GET house · GET house/export · POST house/import
 *              {file, use} · POST house/make {name} · PUT house/rule {rule_id, in?, note?} · DELETE house
 * Runs and schedule changes are written to the Audit Log (entity type "security_assessment"), and so are refusals of
 * the two actions. Nothing here writes to Microsoft 365.
 */
require_once __DIR__ . '/../lib/SecAssess.php';
$db = getDB();
$what = (string)($segments[1] ?? '');
$arg = (string)($segments[2] ?? '');
$need = function (string $act, string $wanted) use ($currentUser) {
    if (itops_can($currentUser, 'security_assessment', $act)) return;
    if ($act !== 'view') audit_log('sa_refused', 'security_assessment', null, 'Security assessment', null, ['Wanted' => $wanted, 'Needs' => 'Security assessment · ' . ($act === 'edit' ? 'Run' : 'View')]);
    error_response('Insufficient permissions', 403);
};
if (!itops_sa_ready($db)) error_response('The security assessment needs a database update: run db-update.php once (or Settings → Maintenance → Apply updates now).', 409);
$runRow = function (int $id) use ($db) {
    $s = $db->prepare('SELECT r.id, r.started_at, r.finished_at, r.via, r.score, r.counts, u.display_name AS started_by_name FROM itops_sa_runs r LEFT JOIN itops_users u ON u.id=r.started_by WHERE r.id=?');
    $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); if (!$r) return null;
    $r['id'] = (int)$r['id']; $r['score'] = $r['score'] === null ? null : (int)$r['score']; $r['counts'] = json_decode((string)$r['counts'], true) ?: [];
    return $r;
};
// BT116 (4a): the baselines, the rule counts per baseline, the tenant profile and the emergency access accounts
$libInfo = function (string $bl) use ($db): array {
    $meta = itops_sa_meta(); $n = [];
    foreach (array_keys(itops_sa_baselines()) as $b) $n[$b] = count(array_filter($meta, fn($m, $id) => itops_sa_in_baseline($m[0], $b, $id), ARRAY_FILTER_USE_BOTH));
    return ['baseline' => $bl, 'baselines' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'about' => $v[1], 'rules' => $n[$k]], array_keys(itops_sa_baselines()), itops_sa_baselines()),
            'profile' => itops_sa_setting_json($db, 'sa_profile', (object)[]), 'emergency' => itops_sa_setting_json($db, 'sa_emergency', []),
            'licenses' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0]], array_keys(itops_sa_licenses()), itops_sa_licenses())];
};
$extraOut = function (int $runId) use ($db): array {
    $e = itops_sa_run_extra($db, $runId);
    return ['baseline' => $e['baseline'] ?? null, 'owned' => $e['owned'] ?? null, 'owned_error' => $e['owned_error'] ?? null, 'ms' => $e['ms'] ?? null, 'ms_error' => $e['ms_error'] ?? null];
};
$configured = function () { if (!function_exists('itops_graph_configured')) require_once __DIR__ . '/../lib/Graph.php'; return itops_graph_configured(); };

if ($method === 'GET' && $what === '') {
    $need('view', 'See the security assessment');
    $last = (int)$db->query('SELECT MAX(id) FROM itops_sa_runs')->fetchColumn();
    $bl = itops_sa_baseline_cur($db); $checks = $last ? itops_sa_results($db, $last, $bl) : [];
    json_response(['configured' => $configured(), 'schedule' => itops_sa_schedule($db), 'can_run' => itops_can($currentUser, 'security_assessment', 'edit'),
                   'areas' => itops_sa_areas(), 'checks_total' => count(itops_sa_catalog())] + $libInfo($bl) + [
                   'run' => $last ? $runRow($last) : null, 'checks' => $checks, 'summary' => $last ? itops_sa_view_summary($checks) : null, 'extra' => $last ? $extraOut($last) : null]);
}
if ($method === 'GET' && $what === 'runs' && $arg === '') {
    $need('view', 'See the assessment history');
    $rows = $db->query('SELECT r.id, r.started_at, r.via, r.score, r.counts, u.display_name AS started_by_name FROM itops_sa_runs r LEFT JOIN itops_users u ON u.id=r.started_by ORDER BY r.id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['score'] = $r['score'] === null ? null : (int)$r['score']; $r['counts'] = json_decode((string)$r['counts'], true) ?: []; }
    json_response(['data' => $rows]);
}
if ($method === 'GET' && $what === 'runs') {
    $need('view', 'See an earlier assessment');
    if (!ctype_digit($arg)) error_response('Unknown run.', 404);
    $r = $runRow((int)$arg); if (!$r) error_response('Unknown run.', 404);
    $runBl = itops_sa_run_extra($db, (int)$arg)['baseline'] ?? null;   // review SEC-24: an earlier run is shown with the baseline it was scored against
    $checks = itops_sa_results($db, (int)$arg, is_string($runBl) && $runBl !== '' ? $runBl : null);
    json_response(['areas' => itops_sa_areas(), 'run' => $r, 'checks' => $checks, 'summary' => itops_sa_view_summary($checks), 'extra' => $extraOut((int)$arg)]);
}
if ($method === 'GET' && $what === 'export') {
    $need('view', 'Export the assessment');
    $id = (string)($_GET['run'] ?? '');
    if ($id === '') $id = (string)(int)$db->query('SELECT MAX(id) FROM itops_sa_runs')->fetchColumn();
    if (!ctype_digit($id) || !($r = $runRow((int)$id))) error_response('Unknown run.', 404);
    $cell = function ($v) { $v = (string)$v; if (preg_match('/^[=+\-@\t\r]/', $v)) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; };
    $lab = ['pass' => 'Passed', 'warn' => 'Warning', 'fail' => 'Failed', 'na' => 'Does not apply', 'unknown' => 'Could not be read'];
    $lines = [implode(',', array_map($cell, ['Area', 'Check', 'Baseline', 'In the chosen baseline', 'Severity', 'Result', 'What was found', 'Affected', 'Affected (first 50)', 'License needed', 'Tenant owns it', 'Effort', 'User impact', 'Do first', 'Permission needed', 'How to fix']))];
    foreach (itops_sa_results($db, (int)$id) as $c)
        $lines[] = implode(',', array_map($cell, [$c['area_label'], $c['title'], $c['level_label'], $c['in_baseline'] ? 'Yes' : 'No', ucfirst($c['severity']), $lab[$c['status']] ?? $c['status'], $c['detail'], $c['affected_count'],
            implode('; ', array_map(fn($a) => trim($a[0] . ($a[1] !== '' ? ' (' . $a[1] . ')' : '')), array_slice($c['affected'], 0, 50))), $c['license'], $c['owned'] === null ? 'Not known' : ($c['owned'] ? 'Yes' : 'No'),
            ucfirst($c['effort']), ucfirst($c['impact']), implode('; ', array_column($c['deps'], 1)), $c['permission'], implode(' ', $c['fix'])]));
    audit_log('sa_export', 'security_assessment', (int)$id, 'Security assessment', null, ['Run' => $r['started_at'], 'Score' => $r['score']]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="security-assessment-' . substr((string)$r['started_at'], 0, 10) . '.csv"');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n"; exit;
}
if ($method === 'POST' && $what === 'run') {
    $need('edit', 'Run the security assessment');
    if (!$configured()) error_response('Microsoft 365 is not connected: Settings → Integrations → Microsoft 365.', 409);
    try { $r = itops_sa_run($db, 'manual', (int)$currentUser['id']); }
    catch (Throwable $e) {
        // BT103: the reason is kept (Audit Log and Event Log), not only shown on the page
        audit_log('sa_run_failed', 'security_assessment', null, 'Security assessment', null, ['Why' => mb_substr($e->getMessage(), 0, 300)]);
        if (function_exists('itops_log')) { try { itops_log('error', 'm365', 'Security assessment did not run: ' . $e->getMessage(), ['where' => basename($e->getFile()) . ':' . $e->getLine()]); } catch (Throwable $x) {} }
        error_response('The assessment did not run: ' . $e->getMessage(), 409);
    }
    audit_log('sa_run', 'security_assessment', $r['id'], 'Security assessment', null,
        ['Score' => $r['score'] === null ? 'not scored' : $r['score'] . '%', 'Passed' => $r['counts']['pass'], 'Warnings' => $r['counts']['warn'], 'Failed' => $r['counts']['fail'], 'Could not be read' => $r['counts']['unknown']]);
    $checks = itops_sa_results($db, $r['id']);
    json_response(['ok' => true, 'run' => $runRow($r['id']), 'checks' => $checks, 'summary' => itops_sa_view_summary($checks), 'extra' => $extraOut($r['id'])]);
}
if ($method === 'PUT' && $what === 'settings') {
    $need('edit', 'Change the assessment schedule');
    $b = json_decode(file_get_contents('php://input'), true);
    $v = is_array($b) ? ($b['schedule'] ?? null) : null;
    if (!in_array($v, ['off', 'weekly', 'daily'], true)) error_response('Schedule must be off, weekly or daily.', 400);
    $was = itops_sa_schedule($db);
    $db->prepare("INSERT INTO itops_settings (skey,sval,updated_by) VALUES ('sa_schedule',?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)")->execute([$v, (int)$currentUser['id']]);
    if ($was !== $v) audit_log('sa_schedule', 'security_assessment', null, 'Security assessment', ['Schedule' => $was], ['Schedule' => $v]);
    json_response(['ok' => true, 'schedule' => $v]);
}
// ═════ BT117 (4b): the tenant plan and the client's decisions ═════
if ($what === 'plan') {
    require_once __DIR__ . '/../lib/SaPlan.php';
    if (!itops_sp_ready($db)) error_response('The security plan needs a database update: run db-update.php once.', 409);
    $sub = $arg; $body = in_array($method, ['POST', 'PUT'], true) ? (json_decode(file_get_contents('php://input') ?: '', true) ?: []) : [];
    $refuse = function (string $why, string $wanted, int $code = 400) { audit_log('sa_refused', 'security_assessment', null, 'Security plan', null, ['Wanted' => $wanted, 'Why' => $why]); error_response($why, $code); };
    if ($method === 'GET' && $sub === '') {
        $need('view', 'See the security plan');
        $id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : itops_sp_current_id($db);
        $v = $id ? itops_sp_view($db, $id) : null; if (isset($_GET['id']) && !$v) error_response('Unknown plan version.', 404);
        $vers = $db->query('SELECT p.id, p.version, p.status, p.baseline, p.created_at, p.signed_at, p.signed_name, (SELECT COUNT(*) FROM itops_sa_plan_items i WHERE i.plan_id=p.id) AS items FROM itops_sa_plans p ORDER BY p.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        $cats = []; try { foreach ($db->query("SELECT c.id, c.name, g.name AS grp FROM itops_ticket_categories c LEFT JOIN itops_ticket_categories g ON g.id=c.parent_id WHERE c.status IS NULL OR c.status='active' ORDER BY COALESCE(g.name,c.name), c.parent_id IS NOT NULL, c.name")->fetchAll(PDO::FETCH_ASSOC) as $c) $cats[] = ['id' => (int)$c['id'], 'name' => ($c['grp'] ? $c['grp'] . ' › ' : '') . $c['name']]; } catch (Throwable $e) {}
        $people = $db->query("SELECT id, display_name FROM itops_users WHERE status='active' ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);
        $hasDraft = (bool)$db->query("SELECT COUNT(*) FROM itops_sa_plans WHERE status='draft'")->fetchColumn();
        // BT121 (4d): the planning assistant (on when the AI integration is set up)
        $ai = ['off' => null, 'suggestions' => (object)[]];
        require_once __DIR__ . '/../lib/SaAssist.php';
        $ai['off'] = itops_ai_off($db); if ($v && itops_sx_ready($db)) $ai['suggestions'] = (object)itops_sx_suggestions($db, (int)$v['plan']['id']);
        $ai['may_kb'] = itops_module_on('knowledge') && itops_can($currentUser, 'kb', 'create'); $ai['may_ann'] = itops_module_on('announcements') && itops_can($currentUser, 'announcements', 'create');
        json_response(['ai' => $ai, 'view' => $v, 'versions' => $vers, 'stages' => itops_sp_stages(), 'decisions' => itops_sp_decisions(), 'categories' => $cats,
                       'people' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['display_name']], $people), 'has_draft' => $hasDraft,
                       'latest_run' => (int)$db->query('SELECT MAX(id) FROM itops_sa_runs')->fetchColumn(), 'can_edit' => itops_can($currentUser, 'security_assessment', 'edit')]);
    }
    if ($method === 'POST' && $sub === 'build') {
        $need('edit', 'Build the security plan');
        try { $r = itops_sp_build($db, (int)$currentUser['id']); } catch (RuntimeException $e) { $refuse($e->getMessage(), 'Build the security plan', 409); }
        audit_log('sa_plan_built', 'security_assessment', $r['id'], 'Security plan v' . $r['version'], null,
            ['Items' => $r['items'], 'Decisions carried over' => $r['carried'], 'Accepted risks expired' => $r['expired'], 'Rebuilt' => $r['rebuilt'] ? 'yes' : 'no', 'Left out: not licensed' => $r['left_out']['unlicensed'], 'Left out: could not be read' => $r['left_out']['unread']]);
        json_response(['ok' => true] + $r);
    }
    if ($method === 'PUT' && $sub === 'items' && ctype_digit((string)($segments[3] ?? ''))) {
        $need('edit', 'Decide a plan item');
        try { $r = itops_sp_decide($db, (int)$segments[3], $body, $currentUser); }
        catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 'Decide a plan item'); }
        $D = itops_sp_decisions(); $t = itops_sa_catalog()[$r['rule_id']][1] ?? $r['rule_id'];
        $lab = fn($x) => ['Decision' => $D[$x['decision']] ?? $x['decision'], 'Note' => $x['note'], 'Owner' => $x['owner_id'], 'Due' => $x['due_date'], 'Accepted until' => $x['expires_on']];
        audit_log('sa_plan_decision', 'security_assessment', (int)$segments[3], 'Security plan v' . $r['version'] . ': ' . $t, $lab($r['old']), $lab($r['new']));
        if ($r['ticket_number']) audit_log('sa_plan_task', 'security_assessment', (int)$segments[3], 'Security plan v' . $r['version'] . ': ' . $t, null, ['Ticket' => $r['ticket_number']]);
        json_response(['ok' => true, 'ticket_number' => $r['ticket_number']]);
    }
    // ── BT121 (4d): the AI planning assistant ──
    if ($method === 'POST' && $sub === 'ai') {
        require_once __DIR__ . '/../lib/SaAssist.php';
        if (!itops_sx_ready($db)) error_response('The planning assistant needs a database update: run db-update.php once.', 409);
        $act = (string)($segments[3] ?? '');
        $W = ['suggest' => 'Suggest decisions', 'explain' => 'Explain an item', 'notice' => 'Draft a staff notice', 'kb' => 'Draft a KB article', 'save' => 'Save a draft'];
        if (!isset($W[$act])) error_response('Unknown request.', 404);
        $need($act === 'explain' ? 'view' : 'edit', $W[$act]);
        if ($act === 'explain' && !itops_can($currentUser, 'security_assessment', 'edit')) {   // review SEC-27: each AI call costs; viewers get 20 an hour
            $lq = $db->prepare("SELECT COUNT(*) FROM itops_audit_log WHERE user_id=? AND action IN ('sa_ai_explain','sa_ai_failed') AND created_at > NOW() - INTERVAL 1 HOUR"); $lq->execute([(int)$currentUser['id']]);
            if ((int)$lq->fetchColumn() >= 20) $refuse('You have asked for 20 explanations in the last hour. Try again later.', $W[$act], 429);
        }
        $pid = itops_sp_current_id($db); $v = $pid ? itops_sp_view($db, $pid) : null;
        if (!$v && $act !== 'save') $refuse('Build the plan first.', $W[$act], 409);
        if ($act === 'suggest') {
            try { [$kept, $dropped, $asked] = itops_sx_suggest($db, $v, (int)$currentUser['id']); }
            catch (InvalidArgumentException $e) { $refuse($e->getMessage(), $W[$act]); }
            catch (Throwable $e) { audit_log('sa_ai_failed', 'security_assessment', $pid, 'Security plan v' . $v['plan']['version'], null, ['Asked' => $W[$act], 'Why' => $e->getMessage()]); error_response($e->getMessage(), 502); }
            audit_log('sa_ai_suggest', 'security_assessment', $pid, 'Security plan v' . $v['plan']['version'], null, ['Undecided items sent' => $asked, 'Suggestions kept' => $kept, 'Answers dropped (not an undecided item of this plan, or not a decision)' => $dropped, 'Sent' => 'rules, counts and the tenant profile; no names']);
            json_response(['ok' => true, 'kept' => $kept, 'dropped' => $dropped, 'asked' => $asked, 'suggestions' => (object)itops_sx_suggestions($db, $pid)]);
        }
        if ($act === 'save') {
            $as = (string)($body['as'] ?? '');
            if ($as === 'kb') { if (!itops_module_on('knowledge') || !itops_can($currentUser, 'kb', 'create')) $refuse('Saving a KB article needs Knowledge Base (create).', $W[$act], 403); }
            elseif ($as === 'announcement') { if (!itops_module_on('announcements') || !itops_can($currentUser, 'announcements', 'create')) $refuse('Saving an announcement needs Announcements (create).', $W[$act], 403); }
            try { $nid = itops_sx_save($db, $as, (string)($body['title'] ?? ''), (string)($body['body'] ?? ''), (int)$currentUser['id']); }
            catch (InvalidArgumentException $e) { $refuse($e->getMessage(), $W[$act]); }
            audit_log($as === 'kb' ? 'created' : 'announcement_created', $as === 'kb' ? 'kb_article' : 'announcement', $nid, mb_substr(trim((string)$body['title']), 0, 250), null, ['Status' => 'draft', 'From' => 'Security plan: planning assistant, edited by a person', 'Rule' => mb_substr((string)($body['rule_id'] ?? ''), 0, 60)]);
            json_response(['ok' => true, 'id' => $nid, 'as' => $as]);
        }
        $rid = (string)($body['rule_id'] ?? ''); $item = null; foreach ($v['items'] as $i) if ($i['rule_id'] === $rid) $item = $i;
        if (!$item) $refuse('That rule is not in the plan.', $W[$act]);
        try { $r = itops_sx_write($db, $v, $item, $act); }
        catch (InvalidArgumentException $e) { $refuse($e->getMessage(), $W[$act]); }
        catch (Throwable $e) { audit_log('sa_ai_failed', 'security_assessment', $pid, 'Security plan v' . $v['plan']['version'], null, ['Asked' => $W[$act], 'Rule' => $rid, 'Why' => $e->getMessage()]); error_response($e->getMessage(), 502); }
        audit_log('sa_ai_' . $act, 'security_assessment', $pid, 'Security plan v' . $v['plan']['version'] . ': ' . $item['title'], null, ['Asked' => $W[$act], 'Rule' => $rid, 'Sent' => 'the rule, counts and the tenant profile; no names']);
        json_response(['ok' => true] + $r);
    }
    if ($method === 'POST' && $sub === 'sign') {
        $need('edit', 'Sign off the security plan');
        $pid = (int)$db->query("SELECT id FROM itops_sa_plans WHERE status='draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if (!$pid) $refuse('There is no draft to sign off.', 'Sign off the security plan', 409);
        try { $r = itops_sp_sign($db, $pid, (string)($body['name'] ?? ''), (string)($body['title'] ?? ''), (string)($body['note'] ?? ''), $currentUser); }
        catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 'Sign off the security plan'); }
        audit_log('sa_plan_signed', 'security_assessment', $pid, 'Security plan v' . $r['version'], null, ['Signed off by' => trim((string)($body['name'] ?? '')) . (trim((string)($body['title'] ?? '')) !== '' ? ', ' . trim((string)$body['title']) : ''), 'Note' => (string)($body['note'] ?? '')] + ['Fingerprint (SHA-256)' => $r['hash']] + ($r['newer_scan'] ? ['Scan' => 'a newer scan exists than the one this plan was built from'] : []));
        json_response(['ok' => true, 'version' => $r['version'], 'hash' => $r['hash'], 'newer_scan' => $r['newer_scan']]);
    }
    if ($method === 'GET' && $sub === 'export') {
        $need('view', 'Export the security plan');
        $id = (int)($_GET['id'] ?? 0) ?: itops_sp_current_id($db); $v = $id ? itops_sp_view($db, $id) : null; if (!$v) error_response('Unknown plan version.', 404);
        $cell = function ($x) { $x = (string)$x; if (preg_match('/^[=+\-@\t\r]/', $x)) $x = "'" . $x; return '"' . str_replace('"', '""', $x) . '"'; };
        $lines = [implode(',', array_map($cell, ['Stage', 'Order', 'Rule', 'Area', 'Severity', 'Found', 'Affected', 'Now', 'Effort', 'Effect on people', 'Do first', 'Decision', 'Note', 'Owner', 'Due', 'Accepted until', 'Ticket']))];
        $n = 0; foreach ($v['items'] as $i) $lines[] = implode(',', array_map($cell, [$i['stage'] . '. ' . $i['stage_label'], ++$n, $i['title'], $i['area'], ucfirst($i['severity']), $i['found_detail'], $i['affected_count'], $i['fixed'] ? 'Fixed' : (string)$i['now'],
            ucfirst($i['effort']), ucfirst($i['impact']), implode('; ', array_column($i['deps'], 1)), $i['decision_label'], (string)$i['note'], (string)$i['owner_name'], $i['due_date'] ? date('m/d/Y', strtotime($i['due_date'])) : '', $i['expires_on'] ? date('m/d/Y', strtotime($i['expires_on'])) : '', (string)$i['ticket_number']]));
        audit_log('sa_plan_export', 'security_assessment', $id, 'Security plan v' . $v['plan']['version'], null, ['Status' => $v['plan']['status']]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="security-plan-v' . $v['plan']['version'] . '.csv"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n"; exit;
    }
    error_response('Not found', 404);
}
// ═════ BT123 (4e): proof — drift, the executive report, the house baseline ═════
if (in_array($what, ['drift', 'report', 'house'], true)) {
    require_once __DIR__ . '/../lib/SaProof.php';
    $body = in_array($method, ['POST', 'PUT'], true) ? (json_decode(file_get_contents('php://input') ?: '', true) ?: []) : [];
    $refuse = function (string $why, string $wanted, int $code = 400) { audit_log('sa_refused', 'security_assessment', null, 'Security assessment', null, ['Wanted' => $wanted, 'Why' => $why]); error_response($why, $code); };
    $houseOut = function () use ($db) { $h = itops_sa_house(true); $bl = itops_sa_baseline_cur($db); $cat = itops_sa_catalog();
        return ['house' => $h ? $h + ['add_titles' => array_map(fn($i) => $cat[$i][1] ?? $i, $h['add']), 'remove_titles' => array_map(fn($i) => $cat[$i][1] ?? $i, $h['remove'])] : null, 'in_use' => $bl === 'house']; };
    if ($what === 'drift') {
        if (!itops_pf_ready($db)) error_response('Drift needs a database update: run db-update.php once.', 409);
        if ($method === 'GET' && $arg === '') {
            $need('view', 'See the security drift');
            $rows = itops_pf_drift_list($db, 365);
            json_response(['data' => $rows, 'open' => count(array_filter($rows, fn($r) => !$r['ack_at'])), 'can_ack' => itops_can($currentUser, 'security_assessment', 'edit')]);
        }
        if ($method === 'POST' && ctype_digit($arg) && ($segments[3] ?? '') === 'ack') {
            $need('edit', 'Acknowledge security drift');
            $q = $db->prepare('SELECT * FROM itops_sa_drift WHERE id=?'); $q->execute([(int)$arg]); $d = $q->fetch(PDO::FETCH_ASSOC);
            if (!$d) error_response('Unknown drift.', 404);
            if ($d['ack_at']) $refuse('This drift was already acknowledged.', 'Acknowledge security drift', 409);
            $note = trim(strip_tags((string)($body['note'] ?? '')));
            if (mb_strlen($note) < 3) $refuse('Say in a few words what happened (who changed it and why, or what was done).', 'Acknowledge security drift');
            $db->prepare('UPDATE itops_sa_drift SET ack_by=?, ack_at=NOW(), ack_note=? WHERE id=? AND ack_at IS NULL')->execute([(int)$currentUser['id'], mb_substr($note, 0, 500), (int)$arg]);
            $t = itops_sa_catalog()[$d['rule_id']][1] ?? $d['rule_id'];
            audit_log('sa_drift_ack', 'security_assessment', (int)$arg, 'Security drift: ' . $t, ['Status' => 'Open'], ['Status' => 'Acknowledged', 'Note' => mb_substr($note, 0, 500)]);
            json_response(['ok' => true]);
        }
    }
    if ($what === 'report' && $method === 'GET') {
        $need('view', 'Open the executive security report');
        $d = itops_pf_report_data($db);
        audit_log('sa_report', 'security_assessment', null, 'Executive security report', null, ['Score' => $d['last'] ? ($d['last']['score'] ?? 'not scored') . '%' : 'no scan yet', 'Open risks' => $d['open_n']]);
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:");
        echo itops_pf_report_html($d, function_exists('itops_app_name') ? itops_app_name() : ''); exit;
    }
    if ($what === 'house') {
        if ($method === 'GET' && $arg === '') { $need('view', 'See the house baseline'); json_response($houseOut()); }
        if ($method === 'GET' && $arg === 'export') {
            $need('view', 'Export the house baseline');
            $j = itops_pf_house_export($db, (string)($_GET['name'] ?? ''));
            audit_log('sa_house_export', 'security_assessment', null, 'House baseline', null, ['Name' => $j['name'], 'Starts from' => $j['base'], 'Rules added' => count($j['add']), 'Rules left out' => count($j['remove']), 'Rules' => $j['rules']]);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="house-baseline-' . (preg_replace('/[^a-z0-9]+/', '-', strtolower($j['name'])) ?: 'export') . '.json"');
            echo json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
        }
        $need('edit', 'Change the house baseline');
        if ($method === 'POST' && $arg === 'import') {
            $j = $body['file'] ?? null; if (is_string($j)) $j = json_decode($j, true);
            if (!is_array($j)) $refuse('The file could not be read as a house baseline.', 'Import a house baseline');
            try { [$h, $drop] = itops_pf_house_clean($j); } catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 'Import a house baseline'); }
            $old = itops_sa_house(true); $was = itops_sa_baseline_cur($db);
            itops_pf_house_save($db, $h, 'import', (int)$currentUser['id']);
            if (!empty($body['use'])) $db->prepare("INSERT INTO itops_settings (skey,sval,updated_by) VALUES ('sa_baseline','house',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)")->execute([(int)$currentUser['id']]);
            audit_log('sa_house_import', 'security_assessment', null, 'House baseline', $old ? ['Name' => $old['name'], 'Baseline in use' => $was] : ['Baseline in use' => $was],
                ['Name' => $h['name'], 'Starts from' => $h['base'], 'Rules added' => count($h['add']), 'Rules left out' => count($h['remove']), 'Unknown rules dropped' => $drop ?: 'none', 'Baseline in use' => itops_sa_baseline_cur($db)]);
            json_response(['ok' => true, 'dropped' => $drop] + $houseOut() + $libInfo(itops_sa_baseline_cur($db)));
        }
        if ($method === 'POST' && $arg === 'make') {
            $name = trim(strip_tags((string)($body['name'] ?? ''))); if (mb_strlen($name) < 2) $refuse('Give the house baseline a name.', 'Make a house baseline');
            $bl = itops_sa_baseline_cur($db); $old = itops_sa_house(true);
            if ($old) {   // review UI2-8: a rename keeps the rules and notes, and does not change the baseline in use
                $h = ['name' => mb_substr($name, 0, 60)] + $old;
                itops_pf_house_save($db, $h, (string)($old['from'] ?? 'made here') === 'import' ? 'import' : 'made here', (int)$currentUser['id']);
                audit_log('sa_house_rename', 'security_assessment', null, 'House baseline', ['Name' => $old['name']], ['Name' => $h['name']]);
                json_response(['ok' => true] + $houseOut() + $libInfo($bl));
            }
            $h = ['name' => mb_substr($name, 0, 60), 'base' => $bl, 'add' => [], 'remove' => [], 'notes' => []];
            itops_pf_house_save($db, $h, 'made here', (int)$currentUser['id']);
            $db->prepare("INSERT INTO itops_settings (skey,sval,updated_by) VALUES ('sa_baseline','house',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)")->execute([(int)$currentUser['id']]);
            audit_log('sa_house_make', 'security_assessment', null, 'House baseline', ['Baseline in use' => $bl], ['Name' => $h['name'], 'Starts from' => $h['base'], 'Baseline in use' => 'house']);
            json_response(['ok' => true] + $houseOut() + $libInfo('house'));
        }
        if ($method === 'PUT' && $arg === 'rule') {
            $h = itops_sa_house(true); if (!$h) $refuse('There is no house baseline yet: make one first.', 'Change a rule of the house baseline', 409);
            $id = (string)($body['rule_id'] ?? ''); $cat = itops_sa_catalog(); if (!isset($cat[$id])) $refuse('Unknown rule.', 'Change a rule of the house baseline');
            $meta = itops_sa_meta(); $inBase = itops_sa_in_baseline($meta[$id][0] ?? 'strict', $h['base']); $was = itops_sa_in_baseline($meta[$id][0] ?? 'strict', 'house', $id); $oldNote = $h['notes'][$id] ?? '';
            $h['add'] = array_values(array_diff($h['add'], [$id])); $h['remove'] = array_values(array_diff($h['remove'], [$id]));
            $in = array_key_exists('in', $body) ? (bool)$body['in'] : $was;
            if ($in && !$inBase) $h['add'][] = $id; elseif (!$in && $inBase) $h['remove'][] = $id;
            if (array_key_exists('note', $body)) { $n = mb_substr(trim(strip_tags((string)$body['note'])), 0, 300); if ($n === '') unset($h['notes'][$id]); else $h['notes'][$id] = $n; }
            itops_pf_house_save($db, $h, $h['from'] ?: 'made here', (int)$currentUser['id']);
            $o = []; $n = [];
            if ($was !== $in) { $o['In the house baseline'] = $was ? 'Yes' : 'No'; $n['In the house baseline'] = $in ? 'Yes' : 'No'; }
            if (($h['notes'][$id] ?? '') !== $oldNote) { $o['Note'] = $oldNote; $n['Note'] = $h['notes'][$id] ?? ''; }
            if ($n) audit_log('sa_house_rule', 'security_assessment', null, 'House baseline: ' . $cat[$id][1], $o, $n);
            json_response(['ok' => true] + $houseOut() + $libInfo(itops_sa_baseline_cur($db)));
        }
        if ($method === 'DELETE' && $arg === '') {
            $h = itops_sa_house(true); if (!$h) error_response('There is no house baseline.', 404);
            $bl = itops_sa_baseline_cur($db);
            if ($bl === 'house') $db->prepare("INSERT INTO itops_settings (skey,sval,updated_by) VALUES ('sa_baseline',?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)")->execute([$h['base'], (int)$currentUser['id']]);
            $db->prepare("DELETE FROM itops_settings WHERE skey='sa_house'")->execute(); itops_sa_house(true);
            audit_log('sa_house_delete', 'security_assessment', null, 'House baseline', ['Name' => $h['name'], 'Baseline in use' => $bl], ['Name' => '', 'Baseline in use' => itops_sa_baseline_cur($db)]);
            json_response(['ok' => true] + $houseOut() + $libInfo(itops_sa_baseline_cur($db)));
        }
    }
    error_response('Not found', 404);
}
if ($method === 'PUT' && $what === 'profile') {   // BT116 (4a)
    $need('edit', 'Change the baseline, the tenant profile or the emergency accounts');
    $b = json_decode(file_get_contents('php://input'), true); if (!is_array($b)) error_response('JSON object expected.', 400);
    $old = ['baseline' => itops_sa_baseline_cur($db), 'profile' => itops_sa_setting_json($db, 'sa_profile', []), 'emergency' => itops_sa_setting_json($db, 'sa_emergency', [])];
    $new = $old; $refuse = function (string $why) { audit_log('sa_refused', 'security_assessment', null, 'Tenant profile', null, ['Wanted' => 'Save the tenant profile', 'Why' => $why]); error_response($why, 400); };
    if (array_key_exists('baseline', $b)) { if (!isset(itops_sa_baselines()[$b['baseline']])) $refuse('Unknown baseline.'); $new['baseline'] = $b['baseline']; }
    if (array_key_exists('profile', $b)) {
        $p = (array)$b['profile']; $q = [];
        if (isset($p['staff']) && $p['staff'] !== '') { if (!is_numeric($p['staff']) || $p['staff'] < 0 || $p['staff'] > 1000000) $refuse('Staff is a number.'); $q['staff'] = (int)$p['staff']; }
        if (isset($p['work'])) { if (!in_array($p['work'], ['office', 'hybrid', 'remote'], true)) $refuse('Work is office, hybrid or remote.'); $q['work'] = $p['work']; }
        foreach (['byod', 'executives'] as $k) if (isset($p[$k])) $q[$k] = (bool)$p[$k];
        if (isset($p['pressure'])) { if (!in_array($p['pressure'], ['none', 'customers', 'insurance', 'regulated'], true)) $refuse('Unknown outside requirement.'); $q['pressure'] = $p['pressure']; }
        foreach (['countries' => 200, 'notes' => 500] as $k => $max) if (isset($p[$k])) $q[$k] = mb_substr(trim((string)$p[$k]), 0, $max);
        $new['profile'] = $q;
    }
    if (array_key_exists('emergency', $b)) {
        // BT124 (review SEC-3): every Conditional Access and admin-role safeguard leaves these accounts out, so only a
        // Super Admin changes the list
        $eNew = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), (array)$b['emergency']))));
        if ($eNew !== array_values(array_map('strtolower', (array)$old['emergency'])) && ($currentUser['role_slug'] ?? '') !== 'super_admin') $refuse('Only a Super Admin can change the emergency access accounts.');
        $e = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), (array)$b['emergency']))));
        if (count($e) > 4) $refuse('At most four emergency access accounts.');
        foreach ($e as $x) if (!filter_var($x, FILTER_VALIDATE_EMAIL)) $refuse('An emergency access account is named by its sign-in address (' . mb_substr($x, 0, 80) . ').');
        $new['emergency'] = $e;
    }
    $put = $db->prepare('INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)');
    $put->execute(['sa_baseline', $new['baseline'], (int)$currentUser['id']]);
    $put->execute(['sa_profile', json_encode($new['profile'] ?: (object)[]), (int)$currentUser['id']]);
    $put->execute(['sa_emergency', json_encode($new['emergency']), (int)$currentUser['id']]);
    $o = []; $n = [];
    foreach (['baseline' => 'Baseline', 'profile' => 'Tenant profile', 'emergency' => 'Emergency access accounts'] as $k => $l) if (json_encode($old[$k]) !== json_encode($new[$k])) { $o[$l] = $old[$k]; $n[$l] = $new[$k]; }
    if ($n) audit_log('sa_profile', 'security_assessment', null, 'Tenant profile', $o, $n);
    json_response(['ok' => true] + $libInfo($new['baseline']));
}
error_response('Not found', 404);
