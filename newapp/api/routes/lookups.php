<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Lookups Route
 * GET /api/lookups  → all reference lists needed by the UI (auth-gated).
 * One round-trip so the SPA can populate dropdowns, sidebars, and filters.
 */
$db = getDB();

if ($method !== 'GET') error_response('Method not allowed', 405);

$out = [];

// Departments
$out['departments'] = $db->query(
    "SELECT id, name, slug, color FROM opsh_departments ORDER BY name"
)->fetchAll();

// Ticket categories (hierarchical: parent_id groups children)
try {
    $out['ticket_categories'] = $db->query(
        "SELECT id, name, slug, department_id, parent_id, sla_hours, sla_response_hours, icon, sort_order, default_assignee_id, backup_assignee_id
         FROM opsh_ticket_categories WHERE status='active'
         ORDER BY (SELECT COALESCE(g.sort_order,0) FROM opsh_ticket_categories g WHERE g.id=COALESCE(opsh_ticket_categories.parent_id,opsh_ticket_categories.id)), COALESCE(opsh_ticket_categories.parent_id,opsh_ticket_categories.id), parent_id IS NOT NULL, sort_order, name"
    )->fetchAll();
} catch (Throwable $e) {
  try {   // BT17 columns not there yet (database update not run)
    $out['ticket_categories'] = $db->query(
        "SELECT id, name, slug, department_id, parent_id, sla_hours, sla_response_hours, icon, sort_order
         FROM opsh_ticket_categories WHERE status='active'
         ORDER BY (SELECT COALESCE(g.sort_order,0) FROM opsh_ticket_categories g WHERE g.id=COALESCE(opsh_ticket_categories.parent_id,opsh_ticket_categories.id)), COALESCE(opsh_ticket_categories.parent_id,opsh_ticket_categories.id), parent_id IS NOT NULL, sort_order, name"
    )->fetchAll();
  } catch (Throwable $e2) {
    // sla_response_hours may not exist yet (run the in-app Database Update). Fall back gracefully.
    $out['ticket_categories'] = $db->query(
        "SELECT id, name, slug, department_id, parent_id, sla_hours, icon, sort_order
         FROM opsh_ticket_categories WHERE status='active'
         ORDER BY (SELECT COALESCE(g.sort_order,0) FROM opsh_ticket_categories g WHERE g.id=COALESCE(opsh_ticket_categories.parent_id,opsh_ticket_categories.id)), COALESCE(opsh_ticket_categories.parent_id,opsh_ticket_categories.id), parent_id IS NOT NULL, sort_order, name"
    )->fetchAll();
  }
}

// BT138: the four simple category lists are one table (kind). Until the database update has run, the old lists are read.
$catRows = function (string $kind, string $old, string $cols) use ($db): array {
    try { return $db->query("SELECT $cols FROM opsh_categories WHERE kind = '$kind' ORDER BY sort_order, name")->fetchAll(); }
    catch (Throwable $e) { try { return $db->query("SELECT $cols FROM $old ORDER BY sort_order, name")->fetchAll(); } catch (Throwable $e2) { return []; } }
};
$out['kb_categories']   = $catRows('kb',   'opsh_kb_categories',   'id, name, slug, department_id, icon, sort_order');
$out['cred_categories'] = $catRows('cred', 'opsh_cred_categories', 'id, name, icon, sort_order');
$out['doc_categories']  = $catRows('doc',  'opsh_doc_categories',  'id, name, slug, department_id, sort_order');
$out['sop_categories']  = $catRows('sop',  'opsh_sop_categories',  'id, name, slug, department_id, sort_order');

// Roles
$out['roles'] = $db->query(
    "SELECT id, name, slug, description FROM opsh_roles ORDER BY id"
)->fetchAll();

// Review CORE-9: an API key, or someone who has not finished the required 2FA yet, gets no directory and no vendor list.
$__limited = !empty($currentUser['is_api_key']) || (function_exists('opsh_mfa_pending') && opsh_mfa_pending($db, $currentUser));
// Vendors (used as a lookup for contracts)
$out['vendors'] = $__limited ? [] : $db->query(
    "SELECT id, name, category, risk_tier FROM opsh_vendors WHERE status='active' ORDER BY name"
)->fetchAll();

// Active users (for assignee pickers)
$out['users'] = $__limited ? [] : $db->query(
    "SELECT id, display_name, email, role_id, department_id FROM opsh_users WHERE status='active' ORDER BY display_name"
)->fetchAll();
// BT52: tk = 1 when the person can work tickets (role may edit tickets) — owner and backup pickers offer only them
require_once __DIR__ . '/../lib/TicketRouting.php';
foreach ($out['users'] as &$__u) { $__u['tk'] = opsh_tr_can_work($db, (int)$__u['id']) ? 1 : 0; }
unset($__u);

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
    foreach ($db->query("SELECT skey,sval FROM opsh_settings WHERE skey IN ('app_logo_url','app_favicon_url','app_name','nav_emoji','announcement_text','announcement_level')") as $r) {
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
$ms = opsh_modules_state(); $mc = opsh_modules_catalog();
// BT105: the license state, said to the people who look after it (Super Admin, License details)
// BT105: the License Manager exists only in the owner's installation (config.php) and only for a Super Admin
$out['license_manager'] = ($currentUser['role_slug'] ?? '') === 'super_admin' && defined('OPSH_LICENSE_MANAGER') && OPSH_LICENSE_MANAGER === true;
$out['license_notice'] = (($currentUser['role_slug'] ?? '') === 'super_admin' || opsh_can($currentUser, 'license', 'view')) ? (string)($ms['license']['notice'] ?? '') : '';
$out['modules'] = ['on' => $ms['on'], 'all' => array_keys($mc), 'perms' => array_map(fn($m) => $m[3], $mc), 'pages' => array_map(fn($m) => $m[5], $mc), 'labels' => array_map(fn($m) => $m[0], $mc)];

$idle = 30;
try { $r = $db->query("SELECT sval FROM opsh_settings WHERE skey='session_idle_min'")->fetchColumn(); if ($r !== false && $r !== '') $idle = (int)$r; } catch (Throwable $e) {}
$out['app_config'] = ['idle_min' => $idle];

json_response($out);
