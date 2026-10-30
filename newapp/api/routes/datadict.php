<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT137 — Administration → Data: the data dictionary, the data grid and the table report (lib/DataDict.php).
 * Super Admin only.
 *
 *   GET /api/datadict                                   every table: area, kind, what it is for, rows, size, editable; the report
 *   GET /api/datadict/t/<table>                         the table's columns (type, key, link, description, hidden, editable)
 *   GET /api/datadict/t/<table>/rows?page&size&sort&dir&f=[{c,op,v}]&group=<col>   one page of rows (secrets hidden)
 *   GET /api/datadict/t/<table>/row/<id>                one row in full (secrets hidden)
 *   GET /api/datadict/t/<table>/export?format=xlsx|csv&sort&dir&f   the filtered rows as a file (up to 50,000; recorded)
 *   PUT /api/datadict/t/<table>/rows/<id>               {col, value, expect}  change one cell of a reference table (recorded)
 *   GET /api/datadict/doc                               BT138: the whole dictionary as a Markdown document (recorded)
 *
 * Opening a table's rows is recorded in the Audit Log once an hour per person and table; every export and every change
 * always. A refusal is recorded too.
 */
require_once __DIR__ . '/../lib/DataDict.php';

$db = getDB();
$uid = (int)($currentUser['id'] ?? 0);
if (($currentUser['role_slug'] ?? '') !== 'super_admin') { itops_refusal_log($currentUser, 'super_admin', 'view', 'Administration → Data'); error_response('Only a Super Admin can open the data of the installation.', 403); }
$s1 = (string)($segments[1] ?? ''); $t = (string)($segments[2] ?? ''); $s3 = (string)($segments[3] ?? ''); $rid = (string)($segments[4] ?? '');
$refuse = function (string $what, string $why, int $code = 400) { audit_log('data_refused', 'data', null, mb_substr($what, 0, 250), null, ['Why' => $why]); error_response($why, $code); };
$viewed = function (string $t) use ($db, $uid) {
    try { $q = $db->prepare("SELECT COUNT(*) FROM itops_audit_log WHERE action='data_view' AND user_id <=> ? AND entity_name=? AND created_at > NOW() - INTERVAL 1 HOUR"); $q->execute([$uid ?: null, $t]);
          if ((int)$q->fetchColumn() === 0) audit_log('data_view', 'data', null, $t, null, ['Opened' => 'the rows of ' . $t . ' (Administration → Data)']); } catch (Throwable $e) {}
};

if ($method === 'GET' && $s1 === '') {
    $cat = itops_dd_catalog(); $facts = itops_dd_tables($db); $ed = itops_dd_editable();
    $use = itops_dd_code_use(array_keys($facts));
    $list = [];
    foreach ($facts as $tn => $f) {
        $c = $cat[$tn] ?? ['Other', 'system', 'Not described yet (a table this build does not know).'];
        $rows = $f['rows_est'];
        if ($rows < 200000) { try { $rows = (int)$db->query('SELECT COUNT(*) FROM `' . $tn . '`')->fetchColumn(); } catch (Throwable $e) {} }
        $list[] = ['name' => $tn, 'area' => $c[0], 'kind' => $c[1], 'description' => $c[2], 'rows' => $rows, 'rows_exact' => $f['rows_est'] < 200000, 'size' => $f['size'], 'updated' => $f['updated'],
                   'editable' => isset($ed[$tn]), 'used_in' => count($use[$tn] ?? []), 'described' => isset($cat[$tn])];
    }
    $fam = array_map(fn($x) => ['name' => $x[0], 'tables' => array_values(array_filter($x[1], fn($tn) => isset($facts[$tn]))), 'note' => $x[2], 'advice' => $x[3]], itops_dd_families());
    $cq = $db->prepare("SELECT COUNT(*) FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
                        WHERE c.table_schema = DATABASE() AND t.table_type = 'BASE TABLE' AND c.table_name LIKE ?");
    $cq->execute([str_replace('_', '\\_', itops_dd_app()['prefix']) . '%']); $cols = (int)$cq->fetchColumn();
    json_response(['tables' => $list, 'families' => $fam, 'columns' => $cols,
                   'report' => ['empty' => array_values(array_map(fn($x) => $x['name'], array_filter($list, fn($x) => $x['rows'] === 0 && $x['rows_exact']))),
                                'unused' => array_values(array_map(fn($x) => $x['name'], array_filter($list, fn($x) => $x['used_in'] === 0))),
                                'undescribed' => array_values(array_map(fn($x) => $x['name'], array_filter($list, fn($x) => !$x['described'])))]]);
}

// BT138: the whole dictionary as a document (Markdown), recorded like every export
if ($method === 'GET' && $s1 === 'doc') {
    if (!function_exists('itops_app_name')) @include_once __DIR__ . '/../lib/Brand.php';
    if (!defined('ITOPS_BUILD')) @include_once __DIR__ . '/../lib/Build.php';
    $product = function_exists('itops_app_name') ? itops_app_name() : 'App';
    $build = defined('ITOPS_BUILD') ? ITOPS_BUILD : '';
    $md = itops_dd_markdown($db, $product, $build);
    audit_log('data_export', 'data', null, 'Data dictionary', null, ['Format' => 'Markdown', 'Size' => strlen($md) . ' bytes']);
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9-]+/', '-', $product) . '-data-dictionary-' . date('Y-m-d') . '.md"');
    header('X-Content-Type-Options: nosniff');
    echo $md; exit;
}

if ($s1 !== 't' || !itops_dd_table_ok($db, $t)) error_response('That is not a table of this installation.', 404);
$cols = itops_dd_columns($db, $t); $byName = []; foreach ($cols as $c) $byName[$c['name']] = $c;
$cat = itops_dd_catalog()[$t] ?? ['Other', 'system', ''];
$pk = null; foreach ($cols as $c) if ($c['key'] === 'PRI') { if ($pk !== null) { $pk = false; break; } $pk = $c['name']; }

if ($method === 'GET' && $s3 === '') {
    $idx = []; try { foreach ($db->query('SHOW INDEX FROM `' . $t . '`')->fetchAll(PDO::FETCH_ASSOC) as $i) $idx[$i['Key_name']]['cols'][] = $i['Column_name']; foreach ($db->query('SHOW INDEX FROM `' . $t . '`')->fetchAll(PDO::FETCH_ASSOC) as $i) $idx[$i['Key_name']]['unique'] = (int)$i['Non_unique'] === 0; } catch (Throwable $e) {}
    $used = itops_dd_code_use(array_keys(itops_dd_tables($db)))[$t] ?? [];
    json_response(['table' => $t, 'area' => $cat[0], 'kind' => $cat[1], 'description' => $cat[2], 'columns' => $cols, 'primary' => $pk ?: null,
                   'indexes' => array_map(fn($k, $v) => ['name' => $k, 'columns' => $v['cols'], 'unique' => !empty($v['unique'])], array_keys($idx), $idx),
                   'editable' => isset(itops_dd_editable()[$t]) && is_string($pk), 'used_in' => $used]);
}

$filters = json_decode((string)($_GET['f'] ?? '[]'), true); if (!is_array($filters)) $filters = [];
$sort = (string)($_GET['sort'] ?? ''); $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
if ($sort !== '' && (!isset($byName[$sort]) || $byName[$sort]['secret'])) error_response('That column cannot be sorted.', 400);
if ($sort === '') $sort = is_string($pk) ? $pk : $cols[0]['name'];
try { [$where, $args] = itops_dd_where($cols, $filters); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
$order = ' ORDER BY `' . $sort . '` ' . $dir . (is_string($pk) && $pk !== $sort ? ', `' . $pk . '` ' . $dir : '');

if ($method === 'GET' && $s3 === 'rows') {
    $viewed($t);
    $size = max(10, min(500, (int)($_GET['size'] ?? 50))); $page = max(1, (int)($_GET['page'] ?? 1));
    $q = $db->prepare('SELECT COUNT(*) FROM `' . $t . '`' . $where); $q->execute($args); $total = (int)$q->fetchColumn();
    $q = $db->prepare('SELECT * FROM `' . $t . '`' . $where . $order . ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)); $q->execute($args);
    $rows = array_map(fn($r) => itops_dd_mask_row($t, $cols, $r), $q->fetchAll(PDO::FETCH_ASSOC));
    $groups = null; $g = (string)($_GET['group'] ?? '');
    if ($g !== '') {
        if (!isset($byName[$g]) || $byName[$g]['secret'] || in_array($byName[$g]['data_type'], ['text', 'mediumtext', 'longtext', 'blob', 'json'], true)) error_response('That column cannot be grouped.', 400);
        $q = $db->prepare('SELECT `' . $g . '` AS v, COUNT(*) AS n FROM `' . $t . '`' . $where . ' GROUP BY `' . $g . '` ORDER BY n DESC, v LIMIT 200'); $q->execute($args);
        $groups = array_map(fn($r) => ['value' => $r['v'], 'count' => (int)$r['n']], $q->fetchAll(PDO::FETCH_ASSOC));
    }
    json_response(['rows' => $rows, 'total' => $total, 'page' => $page, 'size' => $size, 'sort' => $sort, 'dir' => strtolower($dir), 'groups' => $groups, 'primary' => $pk ?: null]);
}

if ($method === 'GET' && $s3 === 'row') {
    if (!is_string($pk) || $rid === '') error_response('This table has no single key to open one row by.', 400);
    $q = $db->prepare('SELECT * FROM `' . $t . '` WHERE `' . $pk . '` = ?'); $q->execute([$rid]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) error_response('That row is not there any more.', 404);
    $viewed($t);
    json_response(['row' => itops_dd_mask_row($t, $cols, $r, true)]);
}

if ($method === 'GET' && $s3 === 'export') {
    $fmt = (string)($_GET['format'] ?? 'xlsx'); if (!in_array($fmt, ['xlsx', 'csv'], true)) error_response('Choose Excel or CSV.', 400);
    $q = $db->prepare('SELECT COUNT(*) FROM `' . $t . '`' . $where); $q->execute($args); $total = (int)$q->fetchColumn();
    if ($total > 50000) $refuse($t . ' - export', 'That is ' . number_format($total) . ' rows; an export holds up to 50,000. Filter first. Nothing was exported.', 413);
    $q = $db->prepare('SELECT * FROM `' . $t . '`' . $where . $order); $q->execute($args);
    $head = array_map(fn($c) => $c['name'], $cols);
    $gen = (function () use ($q, $t, $cols) { while ($r = $q->fetch(PDO::FETCH_ASSOC)) yield itops_dd_mask_row($t, $cols, $r, true); })();
    audit_log('data_export', 'data', null, $t, null, ['Exported' => $total . ' row(s) of ' . $t, 'Format' => $fmt === 'xlsx' ? 'Excel' : 'CSV', 'Filters' => $filters ? mb_substr(json_encode($filters, JSON_UNESCAPED_UNICODE), 0, 500) : '(none)', 'Hidden' => 'secrets never exported']);
    $name = $t . '-' . date('Ymd-His') . '.' . $fmt;
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: attachment; filename="' . $name . '"');
    if ($fmt === 'xlsx') { $bytes = itops_dd_xlsx(substr($t, 5), $head, $gen); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit; }
    header('Content-Type: text/csv; charset=utf-8'); echo "\xEF\xBB\xBF" . implode(',', array_map('itops_dd_csv_cell', $head)) . "\r\n";
    foreach ($gen as $r) echo implode(',', array_map('itops_dd_csv_cell', array_values($r))) . "\r\n";
    exit;
}

if ($method === 'PUT' && $s3 === 'rows') {
    $b = json_decode(file_get_contents('php://input'), true); if (!is_array($b)) error_response('The request is not in the expected form. Nothing was changed.', 400);
    $col = (string)($b['col'] ?? '');
    $ed = itops_dd_editable()[$t] ?? null;
    if ($ed === null) $refuse($t . ' - ' . $col, $t . ' is not a reference table: it is changed on its own screen, not here. Nothing was changed.', 403);
    if (!in_array($col, $ed, true) || !isset($byName[$col])) $refuse($t . ' - ' . $col, '"' . $col . '" is not changed here (only ' . implode(', ', $ed) . '). Nothing was changed.', 403);
    if (!is_string($pk) || $rid === '' || !preg_match('/^\d{1,10}$/', $rid)) error_response('Not a row of this table.', 400);
    if (!array_key_exists('value', $b) || !array_key_exists('expect', $b)) error_response('The request is not in the expected form. Nothing was changed.', 400);
    try { $v = itops_dd_clean_value($byName[$col], $b['value']); } catch (InvalidArgumentException $e) { $refuse($t . ' #' . $rid . ' - ' . $col, $e->getMessage() . ' Nothing was changed.'); }
    $db->beginTransaction();
    $q = $db->prepare('SELECT * FROM `' . $t . '` WHERE `' . $pk . '` = ? FOR UPDATE'); $q->execute([(int)$rid]); $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $db->rollBack(); error_response('That row is not there any more. Reload.', 404); }
    $cur = $row[$col];
    $same = fn($a, $b2) => ($a === null && ($b2 === null || $b2 === '')) || ($a !== null && $b2 !== null && (string)$a === (string)$b2) || (is_numeric($a) && is_numeric($b2) && (float)$a === (float)$b2);
    if (!$same($cur, $b['expect'])) { $db->rollBack(); error_response('Someone changed this value after the grid was loaded (it is now "' . mb_substr((string)$cur, 0, 80) . '"). Reload. Nothing was changed.', 409); }
    if ($same($cur, $v)) { $db->rollBack(); json_response(['ok' => true, 'unchanged' => true, 'row' => itops_dd_mask_row($t, $cols, $row)]); }
    $db->prepare('UPDATE `' . $t . '` SET `' . $col . '` = ? WHERE `' . $pk . '` = ?')->execute([$v, (int)$rid]);
    $db->commit();
    $label = (string)($row['name'] ?? $row['title'] ?? ('#' . $rid));
    audit_log('data_edit', 'data', (int)$rid, $t . ' - ' . mb_substr($label, 0, 150), [$col => $cur === null ? '(empty)' : mb_substr((string)$cur, 0, 1000)], [$col => $v === null ? '(empty)' : mb_substr((string)$v, 0, 1000), 'Where' => 'Administration → Data']);
    $q = $db->prepare('SELECT * FROM `' . $t . '` WHERE `' . $pk . '` = ?'); $q->execute([(int)$rid]);
    json_response(['ok' => true, 'row' => itops_dd_mask_row($t, $cols, $q->fetch(PDO::FETCH_ASSOC))]);
}
error_response('Not found', 404);
