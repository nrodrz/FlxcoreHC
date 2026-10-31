<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/** ITOps — the role permission check, shared by the API and the background jobs (BT66: moved here from api/index.php, unchanged). */
if (!function_exists('itops_can')) {
/**
 * Server-side permission check (defence in depth — the UI also hides modules).
 * Mirrors the client `can()` logic against the role's stored permissions JSON.
 */
function itops_can($user, $module, $action = 'view') {
    if (!$user) return false;
    // BT101: a permission of a module that is not part of this installation (not licensed, or switched off) is refused for
    // everyone, Super Admin included. The data stays; switching the module on brings it back.
    require_once __DIR__ . '/Modules.php';
    if (!itops_perm_enabled((string)$module)) return false;
    if (($user['role_slug'] ?? '') === 'super_admin') return true;
    $perms = $user['role_perms'] ?? null;
    if (is_string($perms)) { $d = json_decode($perms, true); $perms = is_array($d) ? $d : []; }
    if (!is_array($perms)) $perms = [];
    if (!empty($perms['*'])) return true;
    if ($module === 'dashboard') return true;                       // every signed-in user has a dashboard
    $v = $perms[$module] ?? null;
    if ($v === null) {
        // Personal self-service pages are ON unless the role explicitly switches them off ([]).
        if ($module === 'my_signatures' || $module === 'my_equipment') return $action === 'view';
        // A role saved by the granular editor (any per-action array present) means EXACTLY what
        // is checked — nothing is inherited. Inheritance below only keeps OLD-format roles
        // (plain strings like "crud") working until an admin re-saves them.
        foreach ($perms as $pv) { if (is_array($pv)) return false; }
        static $inherit = ['calendar' => ['tickets','view'], 'kb_assist' => ['kb','view'], 'kb_gaps' => ['kb','edit'],
                           'event_log' => ['audit','view'], 'integrations' => ['settings','edit'],
                           // BT18: the Settings areas follow the old single "settings" grant until the role is re-saved
                           'branding' => ['settings','edit'], 'email_cfg' => ['settings','edit'], 'sla_cfg' => ['settings','edit'],
                           // BT21: Microsoft 365 area (granular split) follows the old grants until the role is re-saved
                           'm365_users' => ['m365_admin','edit'], 'm365_credentials' => ['m365_admin','edit'],
                           'm365_licenses' => ['m365_admin','edit'], 'm365_groups' => ['m365_admin','edit'],
                           'provision_onboard' => ['onboarding','edit'], 'provision_offboard' => ['onboarding','edit'],
                           // BT97: License details (About & ownership in full) follows Settings on a role saved before BT97
                           'license' => ['settings','edit'],
                           // BT102: Security assessment follows Microsoft 365 · Tenant configuration on a role saved before BT102
                           'security_assessment' => ['m365_admin','edit'],
                           // BT113: Microsoft 365 · Mailboxes follows Microsoft 365 · Tenant configuration on a role saved before BT113
                           'm365_mail' => ['m365_admin','edit']];
        if ($module === 'changelog') return $action === 'view';
        if (isset($inherit[$module])) {
            [$pm, $need] = $inherit[$module];
            return itops_can($user, $pm, $action === 'view' ? 'view' : $need);
        }
        return false;
    }
    if (is_array($v)) return $action === 'view' ? count($v) > 0 : in_array($action, $v, true);
    if ($v === 'none' || $v === false) return false;
    if ($action === 'view') return true;
    if ($action === 'create' || $action === 'edit') return in_array($v, ['crud','create_edit','dept_manage','own','full',true], true);
    if ($action === 'delete') return in_array($v, ['crud','dept_manage','full',true], true);
    if ($action === 'all')    return in_array($v, ['crud','dept_manage','full','read_all','all',true], true);   // legacy: 'own' = own records only
    return false;   // BT90: an action this check does not know is refused (it used to be allowed)
}
}

if (!function_exists('itops_audit_types')) {
/**
 * BT70: which Audit Log entries a role may read. Each kind of entry (entity_type) belongs to a module; a role sees the
 * entries of the modules it can see (any one of the listed grants is enough). A person always sees what they did
 * themselves. Kinds not listed here are shown to a Super Admin only.
 */
/** BT76: the reference app added Cases & Projects rights here; 0001-A004/A005: this product has no such overlay. */
function itops_perm_overlay($perms, int $uid) {
    return $perms;
}
function itops_audit_types(): array {
    $s = [['settings', 'view']];
    return [
        'ticket' => ['Tickets', [['tickets', 'all']]], 'case' => ['Cases & Projects', [['cases', 'all'], ['cases', 'create']]], 'ticket_category' => ['Ticket categories', [['categories', 'view'], ['tickets', 'all']]],
        'kb' => ['Knowledge Base', [['kb', 'view']]], 'kb_article' => ['Knowledge Base articles', [['kb', 'view']]],
        'sop' => ['SOPs', [['sops', 'view']]], 'sops' => ['SOPs', [['sops', 'view']]], 'document' => ['Documents', [['sops', 'view']]],
        'job_description' => ['Job descriptions', [['job_descriptions', 'view']]],
        'credential' => ['Credentials', [['creds', 'view']]], 'contract' => ['Contracts', [['contracts', 'view']]],
        'vendor' => ['Vendors', [['vendors', 'view']]], 'invoice' => ['Invoices', [['invoices', 'view']]], 'vendor_contact' => ['Vendor contacts', [['vendors', 'view']]],
        'asset' => ['Equipment', [['assets', 'view']]], 'assets' => ['Equipment', [['assets', 'view']]],
        'calendar_event' => ['Calendar', [['calendar', 'view']]], 'calendar_report' => ['Calendar reports', [['calendar', 'view']]],
        'std_requirement' => ['Standards & Audits', [['standards', 'view']]], 'std_domain' => ['Standards & Audits', [['standards', 'view']]],
        'role' => ['Roles', [['roles', 'view']]], 'user' => ['Users', [['users', 'view']]], 'users' => ['Users', [['users', 'view']]], 'auth' => ['Sign-ins', [['users', 'view']]],
        'access' => ['Company systems', [['access', 'view']]],
        'm365' => ['Microsoft 365', [['m365', 'view']]],
        'vtiger' => ['vTiger', [['vtiger_users', 'view'], ['provision_onboard', 'view'], ['provision_offboard', 'view']]],
        'vtiger_user' => ['vTiger users', [['vtiger_users', 'view']]],
        'onboarding' => ['Onboarding / offboarding', [['onboarding', 'view'], ['provision_onboard', 'view'], ['provision_offboard', 'view']]],
        'guardian' => ['Server Guardian', [['guardian', 'view']]], 'uptime' => ['Uptime', [['uptime', 'view']]], 'backups' => ['Backups', [['backups', 'view']]],
        'budget' => ['IT Budget', [['budget', 'view']]], 'security_assessment' => ['Security assessment', [['security_assessment', 'view']]], 'license' => ['Licenses', [['license', 'view']]], 'scorecard' => ['Scorecard', [['scorecard', 'view']]], 'report' => ['Reports', [['reports', 'view']]],
        'announcement' => ['Announcements', [['announcements', 'view']]], 'form' => ['Forms', [['forms', 'view']]],
        'integration' => ['Integrations', [['integrations', 'view']]], 'integrations' => ['Integrations', [['integrations', 'view']]], 'integration_audit' => ['Integration Audit', [['integration_audit', 'view']]],
        'event_log' => ['Event Log', [['event_log', 'view']]], 'email_template' => ['Email templates', [['email_cfg', 'view'], ['settings', 'view']]], 'mail' => ['Email', [['email_cfg', 'view'], ['settings', 'view']]],
        'settings' => ['Settings', $s], 'setting' => ['Settings', $s], 'terms' => ['Terms of Use', [['users', 'view']]], 'help_visual' => ['Help Center', $s], 'system' => ['System', $s], 'notification' => ['Notifications', $s], 'branding' => ['Branding', [['branding', 'view'], ['settings', 'view']]],
    ];
}
/** The kinds this person may read, or null for everything (Super Admin). */
function itops_audit_allowed(array $user): ?array {
    if (($user['role_slug'] ?? '') === 'super_admin') return null;
    $out = [];
    foreach (itops_audit_types() as $t => [$label, $grants]) foreach ($grants as [$m, $a]) if (itops_can($user, $m, $a)) { $out[] = $t; break; }
    return $out;
}
/** SQL condition (and its values) limiting itops_audit_log to what this person may read. */
function itops_audit_scope(array $user, string $alias = ''): array {
    $ok = itops_audit_allowed($user); $c = $alias !== '' ? $alias . '.' : '';
    if ($ok === null) return ['1=1', []];
    $own = $c . 'user_id = ?';
    if (!$ok) return ['(' . $own . ')', [(int)$user['id']]];
    return ['(' . $c . 'entity_type IN (' . implode(',', array_fill(0, count($ok), '?')) . ') OR ' . $own . ')', array_merge($ok, [(int)$user['id']])];
}
}

/**
 * BT143 (role validation): permissions that only work together with another one. A Microsoft 365 sub-permission (People,
 * Licenses, Mailboxes…) is useless without Microsoft 365 · View (the screens and every read need it); vTiger passwords
 * without vTiger users · View. The Roles editor ticks the
 * base for you; a role saved through the API gets it too (itops_perm_normalize).
 */
function itops_perm_bases(): array {
    $b = [];
    foreach (['m365_users', 'm365_credentials', 'm365_licenses', 'm365_groups', 'm365_roles', 'm365_devices', 'm365_keys', 'm365_mail', 'm365_admin', 'm365_ca', 'm365_incidents', 'm365_device_wipe'] as $k) $b[$k] = 'm365';
    $b['vtiger_credentials'] = 'vtiger_users';   // Ask IT and KB Content Gaps work on their own (no base)
    return $b;
}
/** Adds the base View where a sub-permission is held without it. Returns [permissions, list of "sub → base" added]. */
function itops_perm_normalize(array $p): array {
    $added = [];
    foreach (itops_perm_bases() as $sub => $base) {
        $v = $p[$sub] ?? null;
        if (!is_array($v) || !$v) continue;
        $bv = $p[$base] ?? null;
        if (is_array($bv) && in_array('view', $bv, true)) continue;
        $p[$base] = array_values(array_unique(array_merge(is_array($bv) ? $bv : [], ['view'])));
        $added[] = $sub . ' → ' . $base;
    }
    return [$p, $added];
}

