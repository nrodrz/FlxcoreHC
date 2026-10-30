<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/audit   the Audit Log (Audit Log · View)
 *   GET /audit?page=&limit=&search=&entity_type=     entries, newest first
 *     BT98, a filter per column and the order: user=, action=, item= (contains), from=, to= (YYYY-MM-DD, both days
 *     included), sort= time | user | action | item, dir= desc | asc (asc on time = the first entries first)
 *   GET /audit?types=1                                the kinds of entry this person may read (for the filter)
 * BT70: a role reads only the entries of the modules it can see (Credentials entries need Credentials · View, and so on —
 * itops_audit_types() in lib/Perms.php), plus what the person did themselves. A Super Admin reads everything.
 */
itops_require($currentUser, 'audit', 'view');
$db = getDB();
[$scope, $sb] = itops_audit_scope($currentUser);
if (!empty($_GET['types'])) {
    $T = itops_audit_types(); $st = $db->prepare("SELECT entity_type, COUNT(*) AS n FROM itops_audit_log WHERE $scope GROUP BY entity_type ORDER BY entity_type"); $st->execute($sb);
    $by = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $t = (string)$r['entity_type']; if ($t === '') continue; $l = $T[$t][0] ?? ucfirst(str_replace('_', ' ', $t));
        $by[$l]['types'][] = $t; $by[$l]['count'] = ($by[$l]['count'] ?? 0) + (int)$r['n']; }   // kinds with the same name are one choice
    $out = []; foreach ($by as $l => $x) $out[] = ['type' => implode(',', $x['types']), 'label' => $l, 'count' => $x['count']];
    usort($out, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    json_response(['types' => $out, 'limited' => itops_audit_allowed($currentUser) !== null]);
}
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$where = $scope; $bind = $sb;
if ($search) { $where .= ' AND (user_name LIKE ? OR action LIKE ? OR entity_name LIKE ?)'; $bind = array_merge($bind, ["%$search%","%$search%","%$search%"]); }
// BT98: a filter per column
foreach (['user' => 'user_name', 'action' => 'action', 'item' => 'entity_name'] as $k => $col) {
    $v = trim((string)($_GET[$k] ?? ''));
    if ($v === '') continue;
    if (mb_strlen($v) > 200) error_response('A filter is longer than 200 characters.', 400);
    $where .= " AND $col LIKE ?"; $bind[] = '%' . addcslashes($v, '%_\\') . '%';
}
foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
    $v = trim((string)($_GET[$k] ?? ''));
    if ($v === '') continue;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) error_response('Dates are YYYY-MM-DD.', 400);
    $where .= " AND created_at $op ?"; $bind[] = $k === 'to' ? date('Y-m-d', strtotime($v . ' +1 day')) : $v;
}
if (!empty($_GET['entity_type'])) { $ts = array_values(array_filter(array_map('trim', explode(',', (string)$_GET['entity_type'])))); if ($ts) { $where .= ' AND entity_type IN (' . implode(',', array_fill(0, count($ts), '?')) . ')'; $bind = array_merge($bind, $ts); } }
$stmt = $db->prepare("SELECT COUNT(*) FROM itops_audit_log WHERE $where"); $stmt->execute($bind);
$total = (int)$stmt->fetchColumn();
$SORT = ['time' => 'created_at', 'user' => 'user_name', 'action' => 'action', 'item' => 'entity_name'];
$sortK = (string)($_GET['sort'] ?? 'time'); if (!isset($SORT[$sortK])) error_response('Sort by time, user, action or item.', 400);
$dir = strtolower((string)($_GET['dir'] ?? 'desc')); if (!in_array($dir, ['asc', 'desc'], true)) error_response('The order is asc or desc.', 400);
$order = $SORT[$sortK] . ' ' . strtoupper($dir) . ($sortK === 'time' ? '' : ', created_at DESC') . ', id ' . strtoupper($dir === 'asc' && $sortK === 'time' ? 'asc' : 'desc');
$stmt = $db->prepare("SELECT * FROM itops_audit_log WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset"); $stmt->execute($bind);
$rows = $stmt->fetchAll();
/* BT92 (code audit M-7): an entry follows the rights of the record it is about — the details of a private calendar event are
   for the people who can open it, and IT Budget amounts (a project's budget lines) for IT Budget · View. The entry stays
   listed; only what it says is withheld. A Super Admin, and the person who made the entry, read it in full. */
$isSA = ($currentUser['role_slug'] ?? '') === 'super_admin';
if (!$isSA) {
    $budgetOk = itops_can($currentUser, 'budget', 'view');
    $calSeen = [];
    foreach ($rows as &$r) {
        if ((int)($r['user_id'] ?? 0) === (int)$currentUser['id']) continue;
        if (($r['entity_type'] ?? '') === 'calendar_event') {
            $priv = strpos((string)$r['old_value'], '"Visibility":"private"') !== false || strpos((string)$r['new_value'], '"Visibility":"private"') !== false;
            $eid = (int)($r['entity_id'] ?? 0);
            if ($eid && !array_key_exists($eid, $calSeen)) {
                $calSeen[$eid] = null;
                try { require_once __DIR__ . '/../lib/Calendar.php'; $ev = itops_cal_get($db, $eid); if ($ev) $calSeen[$eid] = ['private' => ($ev['visibility'] ?? '') === 'private', 'see' => itops_cal_can_see($ev, $currentUser)]; } catch (Throwable $e) {}
            }
            $cs = $eid ? $calSeen[$eid] : null;
            if (($cs && $cs['private'] && !$cs['see']) || ($priv && !($cs && $cs['see']))) { $r['entity_name'] = 'Private event'; $r['old_value'] = null; $r['new_value'] = null; $r['withheld'] = 'private event'; }
        }
        if (!$budgetOk && in_array((string)($r['action'] ?? ''), ['case_budget_linked', 'case_budget_unlinked'], true)) {
            foreach (['old_value', 'new_value'] as $k) { $j = json_decode((string)$r[$k], true); if (is_array($j)) { foreach (['planned', 'amount', 'Amount', 'Planned'] as $x) if (isset($j[$x])) $j[$x] = '(IT Budget only)'; $r[$k] = json_encode($j); } }
            $r['withheld'] = 'budget amounts';
        }
    }
    unset($r);
}
json_response(['data' => $rows, 'pagination' => ['page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>(int)ceil($total/$limit)], 'limited' => itops_audit_allowed($currentUser) !== null]);
