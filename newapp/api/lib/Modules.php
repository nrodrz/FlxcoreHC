<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the module catalog (BT101).
 *
 * Every part of ITOps that can be sold or left out is a module. Which modules an installation has:
 *   1. licensed  — what the license record (itops-license.php) grants: a plan ('plan' => 'service_desk' …) plus any extra
 *                  modules listed by key ('modules' => ['budget', …]). No license record, or one that names no plan and no
 *                  module key, licenses everything (free installations and records written before BT101 keep working).
 *   2. switched off — a Super Admin may switch licensed modules off (setting `modules_off`), never on beyond the license.
 *   3. dependencies — a module that needs another one is off while that one is off.
 * The core (sign-in, users, roles, settings, Audit Log, Event Log, scheduled jobs, Help, About & ownership, Terms of Use,
 * integrations and API keys) is always on.
 *
 * A module that is off: its permissions are refused for everyone, Super Admin included (itops_can), its API routes answer
 * 404 (api/index.php), and the menu, the role editor and the Help Center leave it out. Its data is kept: switching it on
 * again brings everything back.
 */
require_once __DIR__ . '/Brand.php';   // the license record
if (!function_exists('itops_modules_catalog')) {

    /** key => [label, description, depends on, permission keys, API routes, menu pages] — in the order the screens show them. */
    function itops_modules_catalog(): array {
        return [
            'service_desk' => ['Service Desk', 'Tickets, ticket categories, forms, the help-desk mailbox, SLAs, reports and the improvement register.', [],
                ['tickets', 'categories', 'forms', 'reports', 'sla_cfg'], ['tickets', 'ticket_categories', 'forms', 'public_ticket', 'public_form', 'public_upload', 'public_csat', 'reports', 'improvements'], ['tickets', 'categories', 'forms', 'reports', 'improvements']],
            'cases' => ['Cases & Projects', 'Work that needs several people: cases, projects, tasks, the timeline and My tasks.', ['service_desk'],
                ['cases'], ['cases'], ['cases', 'my_tasks']],
            'calendar' => ['Calendar', 'The shared calendar and the sync with each person\'s Outlook / Teams calendar.', [],
                ['calendar'], ['calendar'], ['calendar']],
            'scorecard' => ['Scorecard', 'Weekly measurables, targets and the data behind each number.', [],
                ['scorecard'], ['scorecard'], ['scorecard']],
            'standards' => ['Standards & Audits', 'The standards the organization is held to: requirements, evidence, owners and gaps.', [],
                ['standards'], ['standards', 'standards_domains'], ['standards']],
            'announcements' => ['Announcements', 'Announcements in the app, by email and to Teams, with read confirmations.', [],
                ['announcements'], ['announcements', 'public_ann'], ['announcements']],
            'knowledge' => ['Knowledge Base', 'Articles, Ask IT and the knowledge gaps it finds; the public knowledge pages.', [],
                ['kb', 'kb_assist', 'kb_gaps'], ['kb', 'kb_public'], ['kb', 'kb_assist', 'kb_gaps']],
            'documents' => ['SOPs & Job Descriptions', 'Procedures, job descriptions, acknowledgements and e-signatures.', [],
                ['sops', 'job_descriptions', 'my_signatures'], ['sops', 'job_descriptions', 'signatures', 'public_sign', 'documents'], ['sops', 'job_descriptions', 'my_signatures']],
            'equipment' => ['Equipment', 'Equipment inventory, assignments, loans and My Equipment.', [],
                ['assets', 'my_equipment'], ['assets'], ['assets', 'my_equipment']],
            'vault' => ['Credentials', 'The credential vault.', [],
                ['creds'], ['credentials'], ['credentials']],
            'contracts' => ['Contracts & Vendors', 'Contracts, renewals, vendors and their contacts, invoices and software licenses.', [],
                ['contracts', 'vendors', 'invoices'], ['contracts', 'vendors', 'vendor_contacts', 'invoices'], ['contracts', 'vendors', 'invoices']],   // BT120: invoices
            'm365' => ['Microsoft 365', 'Microsoft 365: people, email, devices, licenses, security, collaboration and service health of the tenant.', [],
                ['m365', 'm365_users', 'm365_credentials', 'm365_licenses', 'm365_groups', 'm365_devices', 'm365_keys', 'm365_roles', 'm365_admin', 'm365_mail', 'm365_device_wipe', 'm365_ca', 'm365_incidents'], ['m365', 'm365hub', 'm365_admin'], ['m365_overview', 'm365_accounts', 'm365_email', 'm365_devices', 'm365_licensing', 'm365_entra', 'm365_security', 'm365_usage']],
            'onboarding' => ['Onboarding & offboarding', 'Onboarding templates and the onboarding and offboarding processes.', ['m365'],
                ['onboarding'], ['onboarding'], ['onboarding_templates']],
            'vtiger' => ['vTiger', 'vTiger users, their profiles and employee records, and vTiger provisioning at onboarding and offboarding.', [],
                ['vtiger_users', 'vtiger_credentials', 'provision_onboard', 'provision_offboard'], ['vtiger_users', 'vtiger_provision'], ['vtiger_users']],
            'company_systems' => ['Company systems', 'The other systems a person has an account in (phone, fax, forms, CRM…), in onboarding and offboarding.', [],
                ['access'], ['access'], []],
            'servers' => ['Servers', 'Server Guardian, server performance, uptime monitoring and backup monitoring.', [],
                ['guardian', 'uptime', 'backups'], ['guardian', 'guardian_action', 'uptime', 'backups', 'mon360', 'server_perf'], ['guardian', 'server_perf', 'uptime', 'backups']],   // BT155: server_perf
            'budget' => ['IT Budget', 'The IT budget per fiscal year, offices, license costs and spend against the plan.', [],
                ['budget'], ['budget'], ['budget']],
            'integration_audit' => ['Integration Audit', 'Evidence of what connects to the systems: registry, findings, reports.', [],
                ['integration_audit'], ['integration_audit'], ['integration_audit']],
            'security_assessment' => ['Security assessment', 'A read-only check of the Microsoft 365 tenant\'s security settings: findings, the accounts they affect and how to fix them.', ['m365'],
                ['security_assessment'], ['security_assessment', 'risk_users'], ['security_assessment', 'risk_users']],
            'teams_app' => ['Teams app', 'This app inside Microsoft Teams.', [],
                [], ['teams_app'], []],
        ];
    }

    /** NEW APP: the modules this product has. Every other module of the catalog is always off here (its permissions refused,
     *  its routes 404, its pages hidden, its scheduled steps skipped); the screens do not list it. */
    function itops_product_modules(): array { return ['m365', 'onboarding', 'security_assessment', 'teams_app']; }

    /** The plans that can be sold. A license names one; it may add modules by key on top. */
    function itops_plans(): array {
        $all = itops_product_modules();
        return [
            'complete' => ['Complete', 'Every module of this product.', $all],
            'essentials' => ['Essentials', 'The Microsoft 365 administration without the security assessment.', array_values(array_intersect(['m365', 'onboarding', 'teams_app'], $all))],
        ];
    }

    /** The state of this installation, computed once per request (itops_modules_reset() after a change). */
    function itops_modules_state(?PDO $db = null, bool $fresh = false): array {
        static $st = null;
        if ($st !== null && !$fresh) return $st;
        $cat = itops_modules_catalog(); $plans = itops_plans(); $keys = array_keys($cat);
        $db = $db ?: (function_exists('getDB') ? getDB() : null);
        // 1. licensed
        $rec = null;
        if ($db && function_exists('itops_license_record')) { try { $rec = itops_license_record($db); } catch (Throwable $e) { $rec = null; } }
        // BT105: with a build that enforces licenses, only a genuine file decides; otherwise a trial, then the core only
        require_once __DIR__ . '/License.php';
        $lic = $db ? itops_license_status($db, $rec) : ['mode' => 'off', 'why' => ''];
        $use = ($lic['mode'] === 'off' || $lic['mode'] === 'licensed') && $rec && empty($rec['error']);
        $plan = $use ? (string)($rec['plan'] ?? '') : '';
        $extra = $use ? array_values(array_intersect((array)($rec['module_keys'] ?? []), $keys)) : [];
        if ($plan !== '' && !isset($plans[$plan])) $plan = '';
        if ($lic['mode'] === 'trial') { $licensed = $keys; $source = 'trial'; }
        elseif ($lic['mode'] === 'unlicensed') { $licensed = []; $source = 'unlicensed'; }
        elseif ($plan === '' && !$extra) { $licensed = $keys; $source = $use ? 'license_all' : 'none'; }
        else { $licensed = array_values(array_unique(array_merge($plan !== '' ? $plans[$plan][2] : [], $extra))); $source = 'license'; }
        $licensed = array_values(array_intersect($licensed, itops_product_modules()));   // NEW APP: only this product's modules
        // 2. switched off by a Super Admin
        $off = [];
        if ($db) { try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='modules_off'")->fetchColumn(); $j = json_decode((string)$v, true); if (is_array($j)) $off = array_values(array_intersect($j, $keys)); } catch (Throwable $e) {} }
        // 3. dependencies
        $on = array_values(array_diff(array_intersect($keys, $licensed), $off));
        $needs = [];
        do { $changed = false;
            foreach ($on as $i => $k) foreach ($cat[$k][2] as $d) if (!in_array($d, $on, true)) { $needs[$k] = $d; unset($on[$i]); $changed = true; break; }
            $on = array_values($on);
        } while ($changed);
        $st = ['license' => $lic + ['notice' => itops_license_notice($lic)], 'source' => $source, 'plan' => $plan, 'plan_label' => $plan !== '' ? $plans[$plan][0] : '', 'licensed' => array_values(array_intersect($keys, $licensed)),
               'off' => $off, 'on' => $on, 'needs' => $needs];
        return $st;
    }
    function itops_modules_reset(): void { itops_modules_state(null, true); }
    function itops_module_on(string $key): bool {
        $cat = itops_modules_catalog();
        if (!isset($cat[$key])) return true;   // core
        return in_array($key, itops_modules_state()['on'], true);
    }
    /** The module a permission key belongs to (null = core). */
    function itops_perm_module(string $perm): ?string {
        static $map = null;
        if ($map === null) { $map = []; foreach (itops_modules_catalog() as $k => $m) foreach ($m[3] as $p) $map[$p] = $k; }
        return $map[$perm] ?? null;
    }
    /** The module an API route belongs to (null = core). */
    function itops_route_module(string $resource): ?string {
        static $map = null;
        if ($map === null) { $map = []; foreach (itops_modules_catalog() as $k => $m) foreach ($m[4] as $r) $map[$r] = $k; }
        return $map[$resource] ?? null;
    }
    function itops_perm_enabled(string $perm): bool { $m = itops_perm_module($perm); return $m === null || itops_module_on($m); }

    /** BT101: the module an email template belongs to (null = core: digests of the whole system, job alerts, admin messages). */
    function itops_tpl_module(string $key): ?string {
        if (strpos($key, 'ticket_') === 0 || in_array($key, ['sla_breach', 'sla_warning', 'digest_sla_weekly'], true)) return 'service_desk';
        if (strpos($key, 'case_') === 0) return 'cases';
        if (strpos($key, 'announcement_') === 0) return 'announcements';
        if (strpos($key, 'sop_') === 0 || $key === 'signature_request') return 'documents';
        if (strpos($key, 'std_review_') === 0) return 'standards';
        if (strpos($key, 'cred_rotation_') === 0) return 'vault';
        if (strpos($key, 'contract_') === 0) return 'contracts';
        $map = ['uptime_alert' => 'servers', 'server_security_alert' => 'servers', 'backup_alert' => 'servers', 'calendar_reminder' => 'calendar', 'equipment_assigned' => 'equipment',
                'onboarding_sign' => 'onboarding', 'onboarding_notice' => 'onboarding', 'onboarding_welcome' => 'onboarding', 'offboarding_summary' => 'onboarding',
                'access_recert_alert' => 'onboarding', 'device_enroll_computer' => 'm365', 'device_enroll_phone' => 'm365', 'mfa_setup_request' => 'm365',
                'mfa_enforce_notice' => 'm365', 'personal_computer_notice' => 'm365', 'security_key_setup' => 'm365', 'device_enforce_notice' => 'm365'];
        if (isset($map[$key])) return $map[$key];
        if (strpos($key, 'rollout_') === 0) return 'm365';
        if (strpos($key, 'risk_') === 0) return 'security_assessment';   // BT106
        return null;
    }
    /** BT101: the module a bell notification's link opens (null = core). Links look like "tickets", "#assets", "#cases/12". */
    function itops_link_module(?string $link): ?string {
        static $map = null;
        if ($map === null) {
            $map = []; foreach (itops_modules_catalog() as $k => $m) foreach ($m[5] as $pg) $map[$pg] = $k;
            $map += ['tickets' => 'service_desk', 'signatures' => 'documents', 'onboarding' => 'onboarding', 'onboarding_runs' => 'onboarding', 'access' => 'company_systems',
                     'guardian' => 'servers', 'budget' => 'budget', 'standards_dashboard' => 'standards', 'm365' => 'm365', 'improvements' => 'service_desk'];
        }
        if (!preg_match('~^[#/]*([a-z0-9_]+)~', (string)$link, $mm)) return null;
        $pg = $mm[1];
        if (isset($map[$pg])) return $map[$pg];
        return strpos($pg, 'm365') === 0 ? 'm365' : null;
    }
    function itops_link_enabled(?string $link): bool { $m = itops_link_module($link); return $m === null || itops_module_on($m); }
    /** BT101: a scheduled-job step of a module that is off is skipped, and says so in Scheduled jobs. */
    function itops_cron_off(string ...$mods): bool {
        foreach ($mods as $m) if (itops_module_on($m)) return false;
        if (function_exists('itops_cron_note')) { $c = itops_modules_catalog(); itops_cron_note('Skipped: ' . implode(', ', array_map(fn($m) => $c[$m][0] ?? $m, $mods)) . ' is not part of this installation.'); }
        return true;
    }

    /** For the screens: the catalog, the plans and this installation's state. */
    function itops_modules_public(): array {
        $st = itops_modules_state(); $out = [];
        foreach (itops_modules_catalog() as $k => $m) {
            if (!in_array($k, itops_product_modules(), true)) continue;   // NEW APP: not part of this product
            $out[] = ['key' => $k, 'label' => $m[0], 'description' => $m[1], 'depends' => $m[2], 'perms' => $m[3], 'pages' => $m[5],
                      'licensed' => in_array($k, $st['licensed'], true), 'switched_off' => in_array($k, $st['off'], true),
                      'on' => in_array($k, $st['on'], true), 'needs' => $st['needs'][$k] ?? null];
        }
        $plans = []; foreach (itops_plans() as $k => $p) $plans[] = ['key' => $k, 'label' => $p[0], 'description' => $p[1], 'modules' => $p[2]];
        return ['source' => $st['source'], 'plan' => $st['plan'], 'plan_label' => $st['plan_label'], 'modules' => $out, 'plans' => $plans, 'license' => $st['license']];
    }
}
