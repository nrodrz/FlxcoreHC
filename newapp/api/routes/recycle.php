<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT141: Administration → Deleted items (Super Admin only).
 * Who: a Super Admin; or a role with "Deleted items" (View / Edit = put back / Delete = delete for good) — then only the
 * entries of the parts of the app that role may see, and each action also needs that action there (lib/RecycleBin.php
 * itops_bin_rights). Emptying everything and the kept days stay Super Admin only.
 * GET  /api/recycle                      → {items[] (with can_restore, can_purge), keep_days, kinds, super}
 * POST /api/recycle/<id>/restore         → puts the entry back as it was
 * POST /api/recycle/<id>/purge           {confirm:"DELETE"}  deletes it for good (its files leave the disk)
 * POST /api/recycle/empty                {confirm:"EMPTY"}   deletes every entry for good
 * PUT  /api/recycle/settings             {keep_days: 0..3650}  (0 = until emptied by hand)
 * Every action and every refusal is in the Audit Log.
 */
require_once __DIR__ . '/../lib/RecycleBin.php';
$db = getDB();
$a1 = (string)($segments[1] ?? ''); $a2 = (string)($segments[2] ?? '');
$isSA = ($currentUser['role_slug'] ?? '') === 'super_admin';
$deny = function (string $what, string $why, int $code = 403) use ($method, $a1) {
    audit_log('bin_refused', 'system', 0, $what, null, ['Why' => $why, 'Asked' => $method . ' ' . ($a1 !== '' ? $a1 : 'list')]);
    error_response($why, $code);
};
if (!$isSA && !itops_can($currentUser, 'recycle', 'view')) $deny('Deleted items', 'Your role does not include Deleted items.');
itops_bin_table($db);
$body = in_array($method, ['POST', 'PUT'], true) ? json_decode(file_get_contents('php://input') ?: '', true) : [];
if (in_array($method, ['POST', 'PUT'], true) && !is_array($body)) error_response('JSON object expected.', 400);

if ($method === 'GET' && $a1 === '') json_response(itops_bin_list($db, $currentUser));

if ($method === 'PUT' && $a1 === 'settings') {
    if (!$isSA) $deny('Deleted items: kept days', 'Only a Super Admin changes how long deleted items are kept.');
    $v = $body['keep_days'] ?? null;
    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) error_response('Enter a number of days (0 = until emptied by hand).', 400);
    $v = (int)$v; if ($v < 0 || $v > 3650) error_response('Keep between 0 and 3650 days.', 400);
    $old = itops_bin_keep_days($db);
    $db->prepare("INSERT INTO itops_settings (skey, sval) VALUES ('bin_keep_days', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([(string)$v]);
    audit_log('bin_keep_days', 'settings', 0, 'Deleted items: kept days', ['Days' => $old ?: 'until emptied'], ['Days' => $v ?: 'until emptied']);
    json_response(['ok' => true, 'keep_days' => $v]);
}

if ($method === 'POST' && $a1 === 'empty') {
    if (!$isSA) $deny('Empty Deleted items', 'Only a Super Admin empties Deleted items.');
    if (($body['confirm'] ?? '') !== 'EMPTY') { audit_log('bin_refused', 'system', 0, 'Empty Deleted items', null, ['Why' => 'not confirmed (type EMPTY)']); error_response('Type EMPTY to confirm.', 400); }
    $ids = $db->query('SELECT id FROM itops_recycle')->fetchAll(PDO::FETCH_COLUMN);
    [$n, $f] = itops_bin_purge($db, $ids);
    audit_log('bin_emptied', 'system', 0, 'Deleted items emptied: ' . $n . ' removed for good', null, ['Entries' => $n, 'Files removed' => $f]);
    json_response(['ok' => true, 'removed' => $n, 'files' => $f]);
}

if ($method === 'POST' && ctype_digit($a1) && in_array($a2, ['restore', 'purge'], true)) {
    $e = itops_bin_get($db, (int)$a1);
    if (!$e) error_response('That entry is not in Deleted items any more. Reload the page.', 404);
    $K = itops_bin_kinds(); $label = ($K[$e['kind']][1] ?? $e['kind']) . ': ' . $e['name'];
    $rt = itops_bin_rights($currentUser, (string)$e['module']);
    if (!$rt['view']) $deny($label, 'That entry is not one you can see.', 404);
    if ($a2 === 'purge' && !$rt['purge']) $deny($label, 'Your role cannot delete this for good (it needs Delete on Deleted items and on ' . ($K[$e['kind']][1] ?? 'this kind of record') . ').');
    if ($a2 === 'restore' && !$rt['restore']) $deny($label, 'Your role cannot put this back (it needs Edit on Deleted items and on ' . ($K[$e['kind']][1] ?? 'this kind of record') . ').');
    if ($a2 === 'purge') {
        if (($body['confirm'] ?? '') !== 'DELETE') { audit_log('bin_refused', 'system', 0, $label, null, ['Why' => 'not confirmed (type DELETE)']); error_response('Type DELETE to confirm.', 400); }
        [$n, $f] = itops_bin_purge($db, [(int)$a1]);
        audit_log('bin_purged', $e['kind'], (int)$e['record_id'], $label, ['Deleted by' => $e['deleted_by_name'] ?: 'System', 'Deleted on' => $e['deleted_at']], ['Result' => 'Deleted for good', 'Files removed' => $f]);
        json_response(['ok' => true, 'files' => $f]);
    }
    try { $r = itops_bin_restore($db, (int)$a1); }
    catch (ItopsBinRefused $x) { audit_log('bin_restore_refused', $e['kind'], (int)$e['record_id'], $label, null, ['Why' => $x->getMessage()]); error_response('Not put back: ' . $x->getMessage(), 409); }
    audit_log('bin_restored', $e['kind'], (int)$e['record_id'], $label, ['Deleted by' => $e['deleted_by_name'] ?: 'System', 'Deleted on' => $e['deleted_at']], ['Result' => 'Put back'] + ($r['notes'] ? ['Notes' => implode('; ', $r['notes'])] : []));
    json_response(['ok' => true] + $r);
}
error_response('Not found', 404);
