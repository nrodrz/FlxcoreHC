<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT44 — company systems and people links ("one person, every account").
 *
 * A SYSTEM is a place where a person has an account, a seat or a number: HubSpot, JustCall, eFax, Jotform, the phone
 * system, and any the company adds. Microsoft 365 and vTiger are not in this list: they are connected and have their
 * own steps. In this build a system is either not used, or "tracked by hand": ITOps puts a step for it in every
 * onboarding and offboarding, a person does the work in that system and records what was done, and ITOps keeps the
 * record. A direct connection per system comes in later builds and replaces "by hand" where the system's API allows.
 *
 * A PEOPLE LINK says "this person (work email) has this account in this system". Links are the memory: the offboarding
 * step shows them, completing the step closes them, and "Verify offboarding" fails while one is still open.
 *
 *   settings  itops_settings.access_systems = {"systems": {key: {...}}, "order": [custom keys]}
 *   table     itops_people_links (migration 2026_10_07_01_people_links)
 *
 * Rules to keep:
 *   - Nothing here calls another system. Every change is to ITOps's own records. A system set to "Connected"
 *     (BT45, SystemConnect.php) is READ: its accounts become links with an ext_id, and they are not changed by hand.
 *   - Every change writes the Audit Log: who, when, the person, the system, old and new values. A refusal that follows
 *     a decision of the app (not a typing mistake) is written too.
 *   - A link is matched to a person by the exact work email or sign-in name. No guessing by name.
 */
if (!defined('ITOPS_SYS_MAX_CUSTOM')) define('ITOPS_SYS_MAX_CUSTOM', 20);
if (!function_exists('itops_sys_catalog')) {

    /** The systems ITOps knows by name. A company switches on the ones it uses. */
    function itops_sys_catalog(): array {
        return [
            'hubspot' => ['name' => 'HubSpot', 'what' => 'CRM and marketing: contacts, deals, email campaigns. Each person who works in it has a user and often a paid seat.',  'account_hint' => 'the user (email) in HubSpot', 'url' => 'https://app.hubspot.com/',
                'on_text'  => 'Create the user under Settings → Users & Teams, with the role, team and seat this person needs.',
                'off_text' => 'First reassign the records this person owns. Then deactivate or remove the user under Settings → Users & Teams. Removing cannot be undone.'],
            'justcall' => ['name' => 'JustCall', 'what' => 'Cloud phone and SMS: each person has a user, and usually a phone number and an extension.',  'account_hint' => 'the user, or their number', 'url' => 'https://app.justcall.io/',
                'on_text'  => 'Add the person under Teams → Team Members and give them a number.',
                'off_text' => 'Reassign the numbers this person has, then remove them under Teams → Team Members.'],
            'efax' => ['name' => 'eFax (Documo)', 'what' => 'Online fax: each person who sends or receives faxes has a user, and fax numbers are assigned to users.',  'account_hint' => 'the user name, or the fax number', 'url' => 'https://app.documo.com/',
                'on_text'  => 'Add the user under Toolbox → Users and give them a fax number.',
                'off_text' => 'Give their fax numbers to somebody else, then deactivate the user under Toolbox → Users. A user that holds an API key cannot be deactivated: move the key first.'],
            'jotform' => ['name' => 'Jotform', 'what' => 'Online forms and workflows: team members build forms and receive their submissions.',  'account_hint' => 'the member (email)', 'url' => 'https://www.jotform.com/myaccount/',
                'on_text'  => 'Add the person to the team with the role they need.',
                'off_text' => 'Move the forms this person owns to a colleague, then remove them from the team.'],
            // BT148: call tracking and the contact center
            'ctm' => ['name' => 'CallTrackingMetrics', 'what' => 'Call tracking and contact center: tracking numbers ring the company\'s lines, and each agent who answers calls has a user.',  'account_hint' => 'the user (email)', 'url' => 'https://app.calltrackingmetrics.com/',
                'on_text'  => 'Add the person as a user (Settings → Users) with the role and the queues they need.',
                'off_text' => 'Take the person out of the queues and routing they answer, then remove their user (Settings → Users).'],
            'pbx' => ['name' => 'Phone system (PBX)', 'what' => 'The office phone system: each person has an extension and a voicemail box.',  'account_hint' => 'the extension number', 'url' => '',
                'on_text'  => 'Create or assign an extension and set the voicemail.',
                'off_text' => 'Take the extension out of service, reset its voicemail password and clear call forwarding.'],
            // BT57: Paylocity is HR's record of the person (a step, like the systems above). The hosting provider, WHM / cPanel and
            // CodeTwo are company tools ('scope' => 'company'): listed with their set-up guide, no per-person step.
            'paylocity' => ['name' => 'Paylocity', 'what' => 'HR and payroll: the record of every employee, with hire and termination dates.',  'account_hint' => 'the employee ID', 'url' => 'https://access.paylocity.com/',
                'on_text'  => 'HR enters the new hire in Paylocity (or confirms it is there) and the person gets the Employee Self-Service invitation. Record the employee ID.',
                'off_text' => 'HR enters the termination (date and reason) in Paylocity. Agree with HR whether the person keeps Self-Service access for pay statements and W-2s, and record the answer here.'],
            'hosting' => ['name' => 'Hosting provider', 'scope' => 'company', 'url' => '',
                'what' => 'The hosting company: servers, support tickets and invoices, in its customer portal.'],
            'whm' => ['name' => 'WHM / cPanel', 'scope' => 'company', 'url' => '',
                'what' => 'The server control panel. Server Guardian, the backups watch and Integration Audit read it (accounts, services, SSL, disk) with the WHM API token set on this page (Set up… → Connection).'],
            // BT125: an outside monitoring service, read beside the app's own Uptime checks (connection on its set-up page)
            'mon360' => ['name' => '360 Monitoring', 'scope' => 'company', 'url' => '',
                'what' => 'Outside monitoring of servers (its agent) and websites. Its servers and monitors are shown under IT Operations → Uptime → 360 Monitoring, and its alerts can open tickets.'],
            'codetwo' => ['name' => 'CodeTwo Email Signatures', 'scope' => 'company', 'url' => 'https://login.codetwo.com/',
                'what' => 'Email signatures for Microsoft 365. CodeTwo reads each account\'s fields from Microsoft Entra ID about every 20 minutes, so a change made on Microsoft 365 → Accounts reaches the signatures by itself.'],
        ];
    }


    function itops_sys_setting(PDO $db): array {
        try { $s = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $s->execute(['access_systems']); $j = json_decode((string)$s->fetchColumn(), true); }
        catch (Throwable $e) { $j = null; }
        if (!is_array($j)) $j = [];
        return ['systems' => is_array($j['systems'] ?? null) ? $j['systems'] : [], 'order' => is_array($j['order'] ?? null) ? array_values(array_filter($j['order'], 'is_string')) : []];
    }



    /** One system, as the screens and the steps use it. $st = what is stored for it (may be empty). */
    function itops_sys_shape(string $key, array $st, ?array $cat): array {
        $custom = $cat === null;
        $connector = !$custom && function_exists('itops_conn_defs') && isset(itops_conn_defs()[$key]);
        $mode = in_array($st['mode'] ?? 'off', ['manual', 'connected'], true) ? $st['mode'] : 'off';
        if ($mode === 'connected' && !$connector) $mode = 'manual';   // a connection this build does not have: by hand
        $company = !$custom && ($cat['scope'] ?? '') === 'company';   // BT57: a company tool, no account per person
        $sh = [
            'key' => $key, 'custom' => $custom, 'company' => $company, 'what' => (string)($cat['what'] ?? ''),   // BT126: every system says what it is
            'name' => $custom ? (string)($st['name'] ?? $key) : $cat['name'],
            'mode' => $mode,
            'url' => array_key_exists('url', $st) ? (string)$st['url'] : ($cat['url'] ?? ''),
            'onboard' => array_key_exists('onboard', $st) ? !empty($st['onboard']) : true,
            'offboard' => array_key_exists('offboard', $st) ? !empty($st['offboard']) : true,
            'always_ask' => array_key_exists('always_ask', $st) ? !empty($st['always_ask']) : true,
            'account_hint' => array_key_exists('account_hint', $st) ? (string)$st['account_hint'] : ($cat['account_hint'] ?? 'the account name'),
            'on_text' => array_key_exists('on_text', $st) ? (string)$st['on_text'] : ($cat['on_text'] ?? ''),
            'off_text' => array_key_exists('off_text', $st) ? (string)$st['off_text'] : ($cat['off_text'] ?? ''),
            'connector' => $connector,              // BT45: this build can connect to it
            'planned' => !$custom && !$connector,   // a direct connection is planned for the other systems ITOps knows by name
        ];
        if ($company) { $sh['onboard'] = false; $sh['offboard'] = false; $sh['always_ask'] = false; $sh['account_hint'] = ''; $sh['on_text'] = ''; $sh['off_text'] = ''; }
        return $sh;
    }

    /** Every system: the catalog first, then the company's own in the order they were added. */
    function itops_sys_all(PDO $db): array {
        $set = itops_sys_setting($db); $out = [];
        foreach (itops_sys_catalog() as $k => $c) $out[] = itops_sys_shape($k, is_array($set['systems'][$k] ?? null) ? $set['systems'][$k] : [], $c);
        $seen = [];
        foreach (array_merge($set['order'], array_keys($set['systems'])) as $k) {
            $k = (string)$k;
            if (isset($seen[$k]) || !preg_match('/^c_[a-z0-9]{1,28}$/', $k) || !is_array($set['systems'][$k] ?? null)) continue;
            $seen[$k] = 1; $out[] = itops_sys_shape($k, $set['systems'][$k], null);
        }
        return $out;
    }

    function itops_sys_get(PDO $db, string $key): ?array {
        foreach (itops_sys_all($db) as $s) if ($s['key'] === $key) return $s;
        return null;
    }






    // ───────────────────────────── people links ─────────────────────────────

    /** A work email or sign-in name, lower-case, or '' when it is not one. */
    function itops_sys_email($v): string {
        if (!is_string($v)) return '';
        $v = mb_strtolower(trim($v));
        if ($v === '' || strlen($v) > 190 || !preg_match('/^[^@\s,;<>"\'\\\\]+@[a-z0-9.-]+\.[a-z0-9-]{2,}$/u', $v)) return '';
        return $v;
    }

    function itops_sys_link_row(array $r): array {
        return ['id' => (int)$r['id'], 'system' => (string)$r['system_key'], 'email' => (string)$r['email'], 'person_name' => (string)($r['person_name'] ?? ''),
                'account' => (string)$r['account'], 'note' => (string)($r['note'] ?? ''), 'status' => (string)$r['status'], 'source' => (string)$r['source'],
                'created_at' => $r['created_at'] ?? null, 'created_by' => (string)($r['created_by_name'] ?? ''),
                'removed_at' => $r['removed_at'] ?? null, 'removed_by' => (string)($r['removed_by_name'] ?? ''), 'removed_run' => $r['removed_run'] !== null ? (int)$r['removed_run'] : null,
                // BT45: set when the account is read from a connected system
                'ext_id' => isset($r['ext_id']) && $r['ext_id'] !== null ? (string)$r['ext_id'] : null, 'ext_email' => (string)($r['ext_email'] ?? ''), 'pinned' => !empty($r['pinned']),
                'detail' => isset($r['detail']) && is_string($r['detail']) && $r['detail'] !== '' ? (json_decode($r['detail'], true) ?: null) : null, 'seen_at' => $r['seen_at'] ?? null];
    }




    function itops_sys_audit(string $action, int $id, string $name, array $old, array $new): void {
        if (!function_exists('audit_log')) return;
        try { audit_log($action, 'access', $id ?: null, mb_substr($name, 0, 250), $old ?: ['(nothing)' => ''], $new ?: ['(nothing)' => '']); }
        catch (Throwable $e) { if (function_exists('itops_log')) { try { itops_log('error', 'access', 'AUDIT LOG WRITE FAILED for ' . $action . ' ' . $name . ': ' . $e->getMessage(), ['old' => $old, 'new' => $new]); } catch (Throwable $x) {} } }
    }




    // ───────────────────────────── steps in onboarding and offboarding ─────────────────────────────









    // ───────────────────────────── overview ─────────────────────────────




}

require_once __DIR__ . '/SystemConnect.php';   // BT45: the connections (it needs the functions above)
