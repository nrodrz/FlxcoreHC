<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Lookups Route
 * GET /api/lookups  → all reference lists needed by the UI (auth-gated).
 * One round-trip so the SPA can populate dropdowns, sidebars, and filters.
 */
$db = getDB();

if ($method !== 'GET') error_response('Method not allowed', 405);

$out = [];

// Departments
$out['departments'] = $db->query(
    "SELECT id, name, slug, color FROM itops_departments ORDER BY name"
)->fetchAll();

// Roles
$out['roles'] = $db->query(
    "SELECT id, name, slug, description FROM itops_roles ORDER BY id"
)->fetchAll();

// Review CORE-9: an API key, or someone who has not finished the required 2FA yet, gets no directory.
$__limited = !empty($currentUser['is_api_key']) || (function_exists('itops_mfa_pending') && itops_mfa_pending($db, $currentUser));
// Active users (for assignee pickers)
$out['users'] = $__limited ? [] : $db->query(
    "SELECT id, display_name, email, role_id, department_id FROM itops_users WHERE status='active' ORDER BY display_name"
)->fetchAll();

// Cast integer ids for clean JSON
foreach ($out as $k => $rows) {
    foreach ($rows as $i => $row) {
        foreach (['id','department_id','parent_id','sort_order','sla_hours','sla_response_hours','role_id'] as $intf) {
            if (array_key_exists($intf, $row) && $row[$intf] !== null) {
                $out[$k][$i][$intf] = in_array($intf, ['sla_hours','sla_response_hours'], true) ? (float)$row[$intf] + 0 : (int)$row[$intf];   // BT52: SLA hours may be 0.5
            }
        }
    }
}

// Branding (logo / favicon / app name) — available to every signed-in user
$brand = ['logo'=>null,'favicon'=>null,'name'=>null,'nav_emoji'=>null,'announcement'=>null,'announcement_level'=>null];
try {
    foreach ($db->query("SELECT skey,sval FROM itops_settings WHERE skey IN ('app_logo_url','app_favicon_url','app_name','nav_emoji','announcement_text','announcement_level')") as $r) {
        if ($r['skey']==='app_logo_url')    $brand['logo']    = $r['sval'];
        if ($r['skey']==='app_favicon_url') $brand['favicon'] = $r['sval'];
        if ($r['skey']==='app_name')        $brand['name']    = $r['sval'];
        if ($r['skey']==='nav_emoji')       { $j=json_decode($r['sval'],true); if(is_array($j)) $brand['nav_emoji']=$j; }
        if ($r['skey']==='announcement_text')  $brand['announcement']=$r['sval'];
        if ($r['skey']==='announcement_level') $brand['announcement_level']=$r['sval'];
    }
} catch (Throwable $e) {}
$out['branding'] = $brand;
// BT101: which modules this installation has (the screens hide the others; the server refuses them anyway)
require_once __DIR__ . '/../lib/Brand.php'; require_once __DIR__ . '/../lib/Modules.php';
$ms = itops_modules_state(); $mc = itops_modules_catalog();
// BT105: the license state, said to the people who look after it (Super Admin, License details)
// BT105: the License Manager exists only in the owner's installation (config.php) and only for a Super Admin
$out['license_manager'] = ($currentUser['role_slug'] ?? '') === 'super_admin' && defined('ITOPS_LICENSE_MANAGER') && ITOPS_LICENSE_MANAGER === true;
$out['license_notice'] = (($currentUser['role_slug'] ?? '') === 'super_admin' || itops_can($currentUser, 'license', 'view')) ? (string)($ms['license']['notice'] ?? '') : '';
$out['modules'] = ['on' => $ms['on'], 'all' => array_keys($mc), 'perms' => array_map(fn($m) => $m[3], $mc), 'pages' => array_map(fn($m) => $m[5], $mc), 'labels' => array_map(fn($m) => $m[0], $mc)];

$idle = 30;
try { $r = $db->query("SELECT sval FROM itops_settings WHERE skey='session_idle_min'")->fetchColumn(); if ($r !== false && $r !== '') $idle = (int)$r; } catch (Throwable $e) {}
$out['app_config'] = ['idle_min' => $idle];

json_response($out);
