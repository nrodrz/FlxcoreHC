<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Database update API (super_admin only)
 * GET  /api/dbupdate   → { applied[], pending[], pending_count, total, up_to_date }
 * POST /api/dbupdate   → { ok, report[] }  (runs all pending migrations)
 * BT26 — database health (Settings → Maintenance):
 * GET  /api/dbupdate/health     → server facts, tables, checks, size history, housekeeping counts, retention
 * POST /api/dbupdate/table      → {table, op: analyze|check|optimize}   (OpsHub tables only)
 * POST /api/dbupdate/purge      → {key, days}  trims one log table (audit log is never purged)
 * PUT  /api/dbupdate/retention  → {enabled, <key>: days}  daily automatic cleanup (cron-sla.php)
 * GET  /api/dbupdate/backup?confirm=BACKUP → streams a .sql.gz of the OpsHub tables
 * BT140: GET /api/dbupdate/sample → the sample records found; POST /api/dbupdate/sample {confirm:'REMOVE'} removes them
 */
if (($currentUser['role_slug'] ?? '') !== 'super_admin') error_response('Insufficient permissions', 403);
require_once __DIR__ . '/../lib/Migrations.php';
require_once __DIR__ . '/../lib/DbHealth.php';

$db = getDB();

$sub = (string)($id ?? '');

if ($method === 'GET' && $sub === 'health') {
    $tables = opsh_dbh_tables($db);
    opsh_dbh_snapshot($db, $tables);
    $server = opsh_dbh_server($db);
    $pending = opsh_pending_versions($db);
    $own = array_values(array_filter($tables, fn($t) => $t['opshub']));
    $sum = fn($a, $k) => array_sum(array_column($a, $k));
    $lb = null; try { $x = $db->query("SELECT sval FROM opsh_settings WHERE skey='db_last_backup'")->fetchColumn(); $lb = $x ? json_decode($x, true) : null; } catch (Throwable $e) {}
    json_response([
        'server' => $server,
        'totals' => ['tables' => count($own), 'other_tables' => count($tables) - count($own), 'rows' => $sum($own, 'rows'), 'data' => $sum($own, 'data'),
                     'index' => $sum($own, 'index'), 'total' => $sum($own, 'total'), 'free' => $sum($own, 'free'), 'other_total' => $sum($tables, 'total') - $sum($own, 'total')],
        'tables' => $tables,
        'checks' => opsh_dbh_checks($db, $tables, $server, $pending),
        'history' => opsh_dbh_history($db, $tables),
        'housekeeping' => opsh_dbh_purge_stats($db, opsh_dbh_retention_get($db)),
        'retention' => opsh_dbh_retention_get($db),
        'last_backup' => $lb,
        'backup_gzip' => function_exists('deflate_init'),
    ]);
}

if ($method === 'POST' && $sub === 'table') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $t = (string)($b['table'] ?? ''); $op = (string)($b['op'] ?? '');
    if (!in_array($op, ['analyze', 'check', 'optimize', 'collation'], true)) error_response('Unknown action', 400);
    $names = array_column(array_filter(opsh_dbh_tables($db), fn($x) => $x['opshub']), 'name');
    if (!in_array($t, $names, true)) error_response('Only ' . opsh_dbh_app() . ' tables can be maintained from here.', 400);
    $t0 = microtime(true);
    if ($op === 'collation') {
        // Align a table with the database's main collation (utf8mb4_unicode_ci), the same as the other OpsHub tables.
        $db->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        audit_log('db_table_collation', 'system', 0, $t);
        json_response(['ok' => true, 'table' => $t, 'op' => $op, 'messages' => ['status: converted to utf8mb4_unicode_ci'], 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
    }
    $rows = $db->query(strtoupper($op) . " TABLE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $msgs = array_values(array_unique(array_map(fn($r) => ($r['Msg_type'] ?? '') . ': ' . ($r['Msg_text'] ?? ''), $rows)));
    $ok = !array_filter($rows, fn($r) => in_array(strtolower((string)($r['Msg_type'] ?? '')), ['error'], true));
    audit_log('db_table_' . $op, 'system', 0, $t);
    json_response(['ok' => $ok, 'table' => $t, 'op' => $op, 'messages' => $msgs, 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
}

// BT140: sample records (the installer's "Add sample records"): what is there, and remove them (typed REMOVE).
if ($sub === 'sample') {
    require_once __DIR__ . '/../lib/SampleData.php';
    if ($method === 'GET') json_response(opsh_sample_scan($db));
    if ($method !== 'POST') error_response('Method not allowed', 405);
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b) || ($b['confirm'] ?? '') !== 'REMOVE') { audit_log('sample_remove_refused', 'system', 0, 'Sample records: not confirmed (type REMOVE)'); error_response('Type REMOVE to confirm.', 400); }
    try { $r = opsh_sample_remove($db); }
    catch (Throwable $e) { error_log('OpsHub sample remove: ' . $e->getMessage()); audit_log('sample_remove_failed', 'system', 0, 'Sample records: nothing was removed (the database refused the change)'); error_response('Nothing was removed: the database refused the change. Details are in the server log.', 500); }
    if (!$r['available']) error_response('This installation has no list of sample records (sql/sample.sql is missing).', 409);
    $parts = []; foreach ($r['tables'] as $t) if (!empty($r['removed'][$t['table']])) $parts[] = $t['label'] . ' ' . $r['removed'][$t['table']];
    $kept = []; foreach ($r['tables'] as $t) foreach ($t['keep'] as $k) $kept[] = $t['label'] . ': ' . $k['name'] . ' (' . $k['why'] . ')';
    audit_log('sample_removed', 'system', 0, 'Sample records removed: ' . ($parts ? implode(', ', $parts) : 'none found'), null, ['removed' => $r['removed'], 'kept' => $kept]);
    json_response(['ok' => true, 'removed' => array_sum($r['removed']), 'by_table' => $r['removed'], 'kept' => $kept]);
}

if ($method === 'POST' && $sub === 'purge') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = (string)($b['key'] ?? ''); $days = (int)($b['days'] ?? 0);
    try { $n = opsh_dbh_purge($db, $key, $days); }
    catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
    $def = opsh_dbh_purge_defs()[$key];
    audit_log('db_purge', 'system', 0, $def['label'] . ($def['days'] === 0 ? '' : ' older than ' . $days . ' days') . ': ' . $n . ' rows');
    json_response(['ok' => true, 'removed' => $n]);
}

if ($method === 'PUT' && $sub === 'retention') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $cur = opsh_dbh_retention_get($db); $new = ['enabled' => !empty($b['enabled']), 'last_run' => $cur['last_run'], 'last_result' => $cur['last_result']];
    foreach (opsh_dbh_purge_defs() as $k => $d) {
        if ($d['days'] === 0) continue;
        $v = (int)($b[$k] ?? $cur[$k]);
        if ($v < $d['min']) error_response($d['label'] . ' keeps at least ' . $d['min'] . ' days.', 400);
        if ($v > 3650) error_response($d['label'] . ': at most 3650 days.', 400);
        $new[$k] = $v;
    }
    $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('db_retention',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($new)]);
    audit_log('db_retention_saved', 'settings', 0, ($new['enabled'] ? 'on' : 'off') . ' · ' . implode(', ', array_map(fn($k) => $k . '=' . $new[$k], array_filter(array_keys($new), fn($k) => !in_array($k, ['enabled', 'last_run', 'last_result'], true)))));
    json_response(['ok' => true, 'retention' => opsh_dbh_retention_get($db)]);
}

if ($method === 'GET' && $sub === 'backup') {
    if (($_GET['confirm'] ?? '') !== 'BACKUP') error_response('Type BACKUP to confirm.', 400);
    audit_log('db_backup_downloaded', 'system', 0, opsh_dbh_app() . ' tables');
    try { $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('db_last_backup',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")
             ->execute([json_encode(['at' => date('Y-m-d H:i:s'), 'by' => $currentUser['display_name'] ?? ''])]); } catch (Throwable $e) {}
    opsh_dbh_backup_stream($db, opsh_dbh_app());
    exit;
}

if ($method === 'GET') {
    $applied = opsh_applied_versions($db);
    $all = array_keys(opsh_migrations_list());
    $pending = array_values(array_diff($all, $applied));
    json_response([
        'applied'       => $applied,
        'pending'       => $pending,
        'pending_count' => count($pending),
        'total'         => count($all),
        'up_to_date'    => count($pending) === 0,
        // BT26: when each update ran, newest first
        'history'       => (function () use ($db) { try { return $db->query("SELECT version, applied_at FROM opsh_migrations ORDER BY applied_at DESC, version DESC LIMIT 200")->fetchAll(); } catch (Throwable $e) { return []; } })(),
    ]);
}

if ($method === 'POST') {
    $report = opsh_run_migrations($db);
    $hadError = false;
    foreach ($report as $r) if ($r['status'] === 'error') $hadError = true;
    if (function_exists('audit_log')) audit_log('db_update', 'system', 0, $hadError ? 'error' : 'ok');
    json_response(['ok' => !$hadError, 'report' => $report]);
}

error_response('Method not allowed', 405);
