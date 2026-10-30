<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/modules (BT101): the module catalog of this installation.
 *   GET /modules   the plans, every module with licensed / switched off / on / needs — for Super Admin and the roles with
 *                  "License details"; everyone else gets only which modules are on
 *   PUT /modules   {off: [module keys]} — Super Admin only. A module can be switched off only while it is licensed; nothing
 *                  can be switched on beyond the license (itops-license.php). Audited (modules_changed) with the names.
 */
require_once __DIR__ . '/../lib/Brand.php';
require_once __DIR__ . '/../lib/Modules.php';
$db = getDB();
$isSA = ($currentUser['role_slug'] ?? '') === 'super_admin';
if ($method === 'GET') {
    // perms_off: the permission keys of the modules that are off (the Help Center leaves their topics out)
    $permsOff = []; foreach (itops_modules_catalog() as $k => $m) if (!itops_module_on($k)) foreach ($m[3] as $p) $permsOff[] = $p;
    if ($isSA || itops_can($currentUser, 'license', 'view')) json_response(itops_modules_public() + ['can_edit' => $isSA, 'on' => itops_modules_state()['on'], 'perms_off' => $permsOff]);
    json_response(['on' => itops_modules_state()['on'], 'perms_off' => $permsOff]);
}
if ($method === 'PUT') {
    if (!$isSA) error_response('Only a Super Admin switches modules on or off.', 403);
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b) || !isset($b['off']) || !is_array($b['off'])) error_response('Send {off: [module keys]}.', 400);
    $cat = itops_modules_catalog(); $st = itops_modules_state();
    $off = [];
    foreach ($b['off'] as $k) {
        if (!is_string($k) || !isset($cat[$k])) error_response('Unknown module.', 400);
        if (!in_array($k, $st['licensed'], true)) continue;   // not licensed: it is off anyway, nothing to keep
        $off[] = $k;
    }
    $off = array_values(array_unique($off)); sort($off);
    $was = $st['off']; sort($was);
    if ($off === $was) json_response(['ok' => true, 'changed' => false] + itops_modules_public());
    $db->prepare("INSERT INTO itops_settings (skey, sval) VALUES ('modules_off', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($off)]);
    $nm = fn($ks) => $ks ? implode(', ', array_map(fn($k) => $cat[$k][0], $ks)) : '(none)';
    audit_log('modules_changed', 'settings', null, 'Modules', ['Switched off' => $nm($was)], ['Switched off' => $nm($off), 'Turned off now' => $nm(array_values(array_diff($off, $was))), 'Turned on now' => $nm(array_values(array_diff($was, $off)))]);
    itops_modules_reset();
    json_response(['ok' => true, 'changed' => true] + itops_modules_public());
}
error_response('Not found', 404);
