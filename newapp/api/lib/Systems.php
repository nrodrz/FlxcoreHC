<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT44 — company systems and people links ("one person, every account").
 *
 * A SYSTEM is a place where a person has an account, a seat or a number: HubSpot, JustCall, eFax, Jotform, the phone
 * system, and any the company adds. Microsoft 365 and vTiger are not in this list: they are connected and have their
 * own steps. In this build a system is either not used, or "tracked by hand": OpsHub puts a step for it in every
 * onboarding and offboarding, a person does the work in that system and records what was done, and OpsHub keeps the
 * record. A direct connection per system comes in later builds and replaces "by hand" where the system's API allows.
 *
 * A PEOPLE LINK says "this person (work email) has this account in this system". Links are the memory: the offboarding
 * step shows them, completing the step closes them, and "Verify offboarding" fails while one is still open.
 *
 *   settings  opsh_settings.access_systems = {"systems": {key: {...}}, "order": [custom keys]}
 *   table     opsh_people_links (migration 2026_10_07_01_people_links)
 *
 * Rules to keep:
 *   - Nothing here calls another system. Every change is to OpsHub's own records. A system set to "Connected"
 *     (BT45, SystemConnect.php) is READ: its accounts become links with an ext_id, and they are not changed by hand.
 *   - Every change writes the Audit Log: who, when, the person, the system, old and new values. A refusal that follows
 *     a decision of the app (not a typing mistake) is written too.
 *   - A link is matched to a person by the exact work email or sign-in name. No guessing by name.
 */
if (!defined('OPSH_SYS_MAX_CUSTOM')) define('OPSH_SYS_MAX_CUSTOM', 20);
if (!function_exists('opsh_sys_catalog')) {

    /** The systems OpsHub knows by name. A company switches on the ones it uses. */
    function opsh_sys_catalog(): array {
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

    /** Names a custom system may not take (they are built in, or would be confused with the catalog). */
    function opsh_sys_reserved_names(): array {
        $n = ['microsoft 365' => 1, 'vtiger' => 1, 'm365' => 1, 'entra' => 1];
        foreach (opsh_sys_catalog() as $c) $n[mb_strtolower($c['name'])] = 1;
        return $n;
    }

    function opsh_sys_setting(PDO $db): array {
        try { $s = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $s->execute(['access_systems']); $j = json_decode((string)$s->fetchColumn(), true); }
        catch (Throwable $e) { $j = null; }
        if (!is_array($j)) $j = [];
        return ['systems' => is_array($j['systems'] ?? null) ? $j['systems'] : [], 'order' => is_array($j['order'] ?? null) ? array_values(array_filter($j['order'], 'is_string')) : []];
    }

    function opsh_sys_setting_put(PDO $db, array $val): void {
        $json = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 60000) throw new InvalidArgumentException('The systems and their instructions are too long to store. Shorten the instructions.');
        $db->prepare('INSERT INTO opsh_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['access_systems', $json]);
    }

    /** One line of plain text: control and invisible characters out, spaces collapsed. $multi keeps line breaks. */
    function opsh_sys_text($v, int $max, bool $multi = false): string {
        if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
        $v = (string)$v;
        if (strlen($v) > 20000 || ($v !== '' && !mb_check_encoding($v, 'UTF-8'))) return '';
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $o = preg_replace('/[\x{00AD}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', '', $v); if (is_string($o)) $v = $o;
        $o = preg_replace($multi ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', $multi ? '' : ' ', $v); if (is_string($o)) $v = $o;
        $o = preg_replace($multi ? '/[^\S\n]+/u' : '/\s+/u', ' ', $v); if (is_string($o)) $v = $o;
        if ($multi) { $o = preg_replace('/\n{3,}/', "\n\n", $v); if (is_string($o)) $v = $o; }
        return mb_substr(trim($v), 0, $max);
    }

    /** One system, as the screens and the steps use it. $st = what is stored for it (may be empty). */
    function opsh_sys_shape(string $key, array $st, ?array $cat): array {
        $custom = $cat === null;
        $connector = !$custom && function_exists('opsh_conn_defs') && isset(opsh_conn_defs()[$key]);
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
            'planned' => !$custom && !$connector,   // a direct connection is planned for the other systems OpsHub knows by name
        ];
        if ($company) { $sh['onboard'] = false; $sh['offboard'] = false; $sh['always_ask'] = false; $sh['account_hint'] = ''; $sh['on_text'] = ''; $sh['off_text'] = ''; }
        return $sh;
    }

    /** Every system: the catalog first, then the company's own in the order they were added. */
    function opsh_sys_all(PDO $db): array {
        $set = opsh_sys_setting($db); $out = [];
        foreach (opsh_sys_catalog() as $k => $c) $out[] = opsh_sys_shape($k, is_array($set['systems'][$k] ?? null) ? $set['systems'][$k] : [], $c);
        $seen = [];
        foreach (array_merge($set['order'], array_keys($set['systems'])) as $k) {
            $k = (string)$k;
            if (isset($seen[$k]) || !preg_match('/^c_[a-z0-9]{1,28}$/', $k) || !is_array($set['systems'][$k] ?? null)) continue;
            $seen[$k] = 1; $out[] = opsh_sys_shape($k, $set['systems'][$k], null);
        }
        return $out;
    }

    function opsh_sys_get(PDO $db, string $key): ?array {
        foreach (opsh_sys_all($db) as $s) if ($s['key'] === $key) return $s;
        return null;
    }

    /** The systems in use (mode other than "not used"). $for = 'onboard' | 'offboard' | '' (all in use). */
    function opsh_sys_in_use(PDO $db, string $for = ''): array {
        // BT57: company tools (hosting provider, WHM / cPanel, CodeTwo) have no account per person, so they are never "in use" for people
        return array_values(array_filter(opsh_sys_all($db), fn($s) => $s['mode'] !== 'off' && empty($s['company']) && ($for === '' || !empty($s[$for]))));
    }

    /**
     * Check what a screen sent for one system. Returns the values to store. Throws InvalidArgumentException with a
     * sentence for the screen. $names = lower-case names already taken by other systems.
     */
    function opsh_sys_clean($in, bool $custom, array $names = []): array {
        if (!is_array($in) || ($in && array_is_list($in))) throw new InvalidArgumentException('The system is not in the expected form. Nothing was changed.');
        $mode = $in['mode'] ?? 'off';
        if (!in_array($mode, ['off', 'manual', 'connected'], true)) throw new InvalidArgumentException('Choose "Not used", "Tracked by hand" or "Connected".');
        if ($mode === 'connected' && $custom) throw new InvalidArgumentException('A system of your own is tracked by hand: there is nothing to connect to.');
        foreach (['onboard', 'offboard', 'always_ask'] as $b) if (array_key_exists($b, $in) && !is_bool($in[$b])) throw new InvalidArgumentException('A switch was sent as something other than on or off. Nothing was changed.');
        $url = opsh_sys_text($in['url'] ?? '', 300);
        if ($url !== '') {
            $p = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['https', 'http'], true) || empty($p['host']))
                throw new InvalidArgumentException('The link must be a full web address that starts with https:// (or http:// for a device in the office).');
        }
        $out = ['mode' => $mode, 'url' => $url,
                'onboard' => !array_key_exists('onboard', $in) || $in['onboard'], 'offboard' => !array_key_exists('offboard', $in) || $in['offboard'],
                'always_ask' => !array_key_exists('always_ask', $in) || $in['always_ask'],
                'account_hint' => opsh_sys_text($in['account_hint'] ?? '', 60),
                'on_text' => opsh_sys_text($in['on_text'] ?? '', 1000, true), 'off_text' => opsh_sys_text($in['off_text'] ?? '', 1000, true)];
        if ($out['account_hint'] === '') $out['account_hint'] = 'the account name';
        if ($custom) {
            $name = opsh_sys_text($in['name'] ?? '', 60);
            if (mb_strlen($name) < 2) throw new InvalidArgumentException('Give the system a name (at least two characters).');
            $lk = mb_strtolower($name);
            if (isset(opsh_sys_reserved_names()[$lk])) throw new InvalidArgumentException('"' . $name . '" is already in the list. Switch that one on instead of adding it again.');
            if (isset($names[$lk])) throw new InvalidArgumentException('There is already a system called "' . $name . '". Give this one another name.');
            $out['name'] = $name;
        }
        return $out;
    }

    /** A system as a screen would send it: what a change request is laid over, so a key that is not sent keeps its value. */
    function opsh_sys_as_input(array $s): array {
        return ['mode' => $s['mode'], 'url' => $s['url'], 'onboard' => (bool)$s['onboard'], 'offboard' => (bool)$s['offboard'], 'always_ask' => (bool)$s['always_ask'],
                'account_hint' => $s['account_hint'], 'on_text' => $s['on_text'], 'off_text' => $s['off_text']] + ($s['custom'] ? ['name' => $s['name']] : []);
    }

    /** The same values with the labels the Audit Log shows. */
    function opsh_sys_audit_view(array $s): array {
        return ['Name' => (string)$s['name'], 'Use' => $s['mode'] === 'connected' ? 'Connected' : ($s['mode'] === 'manual' ? (!empty($s['company']) ? 'In use' : 'Tracked by hand') : 'Not used'), 'Link' => (string)$s['url'],
                'In onboarding' => $s['onboard'] ? 'yes' : 'no', 'In offboarding' => $s['offboard'] ? 'yes' : 'no',
                'Ask at every offboarding' => $s['always_ask'] ? 'yes' : 'no', 'An account is' => (string)$s['account_hint'],
                'Onboarding instructions' => (string)$s['on_text'], 'Offboarding instructions' => (string)$s['off_text']];
    }

    /** old → new with only the lines that differ (the Audit Log shows "field: old → new"). */
    function opsh_sys_diff(array $old, array $new): array {
        $o = []; $n = [];
        foreach ($new as $k => $v) if ((string)($old[$k] ?? '') !== (string)$v) { $o[$k] = (string)($old[$k] ?? ''); $n[$k] = (string)$v; }
        return [$o, $n];
    }

    // ───────────────────────────── people links ─────────────────────────────

    /** A work email or sign-in name, lower-case, or '' when it is not one. */
    function opsh_sys_email($v): string {
        if (!is_string($v)) return '';
        $v = mb_strtolower(trim($v));
        if ($v === '' || strlen($v) > 190 || !preg_match('/^[^@\s,;<>"\'\\\\]+@[a-z0-9.-]+\.[a-z0-9-]{2,}$/u', $v)) return '';
        return $v;
    }

    function opsh_sys_link_row(array $r): array {
        return ['id' => (int)$r['id'], 'system' => (string)$r['system_key'], 'email' => (string)$r['email'], 'person_name' => (string)($r['person_name'] ?? ''),
                'account' => (string)$r['account'], 'note' => (string)($r['note'] ?? ''), 'status' => (string)$r['status'], 'source' => (string)$r['source'],
                'created_at' => $r['created_at'] ?? null, 'created_by' => (string)($r['created_by_name'] ?? ''),
                'removed_at' => $r['removed_at'] ?? null, 'removed_by' => (string)($r['removed_by_name'] ?? ''), 'removed_run' => $r['removed_run'] !== null ? (int)$r['removed_run'] : null,
                // BT45: set when the account is read from a connected system
                'ext_id' => isset($r['ext_id']) && $r['ext_id'] !== null ? (string)$r['ext_id'] : null, 'ext_email' => (string)($r['ext_email'] ?? ''), 'pinned' => !empty($r['pinned']),
                'detail' => isset($r['detail']) && is_string($r['detail']) && $r['detail'] !== '' ? (json_decode($r['detail'], true) ?: null) : null, 'seen_at' => $r['seen_at'] ?? null];
    }

    /** Links of one person (any of the emails given). $status = 'active' | 'removed' | '' (both). $system = '' for all. */
    function opsh_sys_links(PDO $db, array $emails, string $status = 'active', string $system = ''): array {
        $emails = array_values(array_unique(array_filter(array_map('opsh_sys_email', $emails))));
        if (!$emails) return [];
        $sql = "SELECT l.*, cu.display_name AS created_by_name, ru.display_name AS removed_by_name FROM opsh_people_links l
                LEFT JOIN opsh_users cu ON cu.id = l.created_by LEFT JOIN opsh_users ru ON ru.id = l.removed_by
                WHERE l.email IN (" . implode(',', array_fill(0, count($emails), '?')) . ")";
        $args = $emails;
        if ($status !== '') { $sql .= ' AND l.status = ?'; $args[] = $status; }
        if ($system !== '') { $sql .= ' AND l.system_key = ?'; $args[] = $system; }
        try { $q = $db->prepare($sql . ' ORDER BY l.system_key, l.status, l.account, l.id'); $q->execute($args); return array_map('opsh_sys_link_row', $q->fetchAll(PDO::FETCH_ASSOC)); }
        catch (Throwable $e) { return []; }   // the table is not there yet (db-update.php not run)
    }

    function opsh_sys_link_by_id(PDO $db, int $id): ?array {
        $q = $db->prepare("SELECT l.*, cu.display_name AS created_by_name, ru.display_name AS removed_by_name FROM opsh_people_links l
                           LEFT JOIN opsh_users cu ON cu.id = l.created_by LEFT JOIN opsh_users ru ON ru.id = l.removed_by WHERE l.id = ?");
        $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? opsh_sys_link_row($r) : null;
    }

    function opsh_sys_link_audit_view(array $l, string $sysName): array {
        return ['System' => $sysName, 'Person' => (string)$l['email'], 'Account' => (string)$l['account'] !== '' ? (string)$l['account'] : '(name not recorded)',
                'Note' => (string)($l['note'] ?? ''), 'Status' => ($l['status'] ?? 'active') === 'removed' ? 'removed' : 'active'];
    }

    function opsh_sys_audit(string $action, int $id, string $name, array $old, array $new): void {
        if (!function_exists('audit_log')) return;
        try { audit_log($action, 'access', $id ?: null, mb_substr($name, 0, 250), $old ?: ['(nothing)' => ''], $new ?: ['(nothing)' => '']); }
        catch (Throwable $e) { if (function_exists('opsh_log')) { try { opsh_log('error', 'access', 'AUDIT LOG WRITE FAILED for ' . $action . ' ' . $name . ': ' . $e->getMessage(), ['old' => $old, 'new' => $new]); } catch (Throwable $x) {} } }
    }

    /**
     * Record that a person has an account in a system. An identical link that was closed is opened again; one that is
     * open is returned as it is ('existing' => true). Returns ['link' => row, 'existing' => bool, 'restored' => bool].
     */
    function opsh_sys_link_add(PDO $db, array $sys, string $email, string $account, string $note, string $personName, string $source, int $uid): array {
        $email = opsh_sys_email($email);
        if ($email === '') throw new InvalidArgumentException('Enter the work email (or sign-in name) of the person.');
        $account = opsh_sys_text($account, 190); $note = opsh_sys_text($note, 500); $personName = opsh_sys_text($personName, 190);
        if (!in_array($source, ['manual', 'import', 'onboard', 'offboard'], true)) $source = 'manual';
        $q = $db->prepare('SELECT id, status FROM opsh_people_links WHERE system_key = ? AND email = ? AND account = ?'); $q->execute([$sys['key'], $email, $account]); $ex = $q->fetch(PDO::FETCH_ASSOC);
        if ($ex && $ex['status'] === 'active') return ['link' => opsh_sys_link_by_id($db, (int)$ex['id']), 'existing' => true, 'restored' => false];
        if ($ex) {
            $before = opsh_sys_link_by_id($db, (int)$ex['id']);
            $db->prepare("UPDATE opsh_people_links SET status='active', removed_at=NULL, removed_by=NULL, removed_run=NULL, note=?, person_name=COALESCE(NULLIF(?, ''), person_name), updated_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$note !== '' ? $note : ($before['note'] ?? ''), $personName, $uid, (int)$ex['id']]);
            $after = opsh_sys_link_by_id($db, (int)$ex['id']);
            [$o, $n] = opsh_sys_diff(opsh_sys_link_audit_view($before, $sys['name']), opsh_sys_link_audit_view($after, $sys['name']));
            opsh_sys_audit('access_link_restore', (int)$ex['id'], $email . ' - ' . $sys['name'], $o + ['Account' => opsh_sys_link_audit_view($before, $sys['name'])['Account']], $n + ['Account' => opsh_sys_link_audit_view($after, $sys['name'])['Account']]);
            return ['link' => $after, 'existing' => false, 'restored' => true];
        }
        $db->prepare("INSERT INTO opsh_people_links (system_key, email, person_name, account, note, status, source, created_by, created_at, updated_by, updated_at) VALUES (?,?,?,?,?,'active',?,?,NOW(),?,NOW())")
           ->execute([$sys['key'], $email, $personName !== '' ? $personName : null, $account, $note !== '' ? $note : null, $source, $uid, $uid]);
        $id = (int)$db->lastInsertId();
        $after = opsh_sys_link_by_id($db, $id);
        opsh_sys_audit('access_link_add', $id, $email . ' - ' . $sys['name'], [], opsh_sys_link_audit_view($after, $sys['name']) + ['Recorded through' => $source]);
        return ['link' => $after, 'existing' => false, 'restored' => false];
    }

    /** Close a link: the account no longer exists (or no longer belongs to the person). $why goes to the Audit Log. */
    function opsh_sys_link_remove(PDO $db, array $link, string $sysName, int $uid, ?int $runId = null, string $why = ''): array {
        if ($link['status'] === 'removed') return $link;
        $db->prepare("UPDATE opsh_people_links SET status='removed', removed_at=NOW(), removed_by=?, removed_run=?, updated_by=?, updated_at=NOW() WHERE id=? AND status='active'")->execute([$uid, $runId, $uid, $link['id']]);
        $after = opsh_sys_link_by_id($db, $link['id']);
        $old = opsh_sys_link_audit_view($link, $sysName); $new = opsh_sys_link_audit_view($after, $sysName);
        opsh_sys_audit('access_link_remove', $link['id'], $link['email'] . ' - ' . $sysName, ['Account' => $old['Account'], 'Status' => 'active'],
            ['Account' => $new['Account'], 'Status' => 'removed'] + ($why !== '' ? ['Why' => $why] : []) + ($runId ? ['Offboarding record' => '#' . $runId] : []));
        return $after;
    }

    /**
     * Hand an account over to another person (a number or an extension that stays in use). The link keeps its account
     * and gets the new person. If that person already has the same account recorded, this one is closed instead.
     */
    function opsh_sys_link_move(PDO $db, array $link, string $sysName, string $toEmail, int $uid, ?int $runId = null): array {
        $q = $db->prepare("SELECT id, status FROM opsh_people_links WHERE system_key = ? AND email = ? AND account = ?"); $q->execute([$link['system'], $toEmail, $link['account']]); $ex = $q->fetch(PDO::FETCH_ASSOC);
        if ($ex) {   // the other person already has this account in the records: make sure theirs is open, close ours
            if ($ex['status'] !== 'active') $db->prepare("UPDATE opsh_people_links SET status='active', removed_at=NULL, removed_by=NULL, removed_run=NULL, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$uid, (int)$ex['id']]);
            $db->prepare("UPDATE opsh_people_links SET status='removed', removed_at=NOW(), removed_by=?, removed_run=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$uid, $runId, $uid, $link['id']]);
        } else {
            $db->prepare("UPDATE opsh_people_links SET email=?, person_name=NULL, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$toEmail, $uid, $link['id']]);
        }
        $acc = $link['account'] !== '' ? $link['account'] : '(name not recorded)';
        opsh_sys_audit('access_link_move', $link['id'], $link['email'] . ' - ' . $sysName, ['Person' => $link['email'], 'Account' => $acc], ['Person' => $toEmail, 'Account' => $acc] + ($runId ? ['Offboarding record' => '#' . $runId] : []));
        return opsh_sys_link_by_id($db, $link['id']) ?? $link;
    }

    // ───────────────────────────── steps in onboarding and offboarding ─────────────────────────────

    /** The emails a run's person is known by. */
    function opsh_sys_run_emails(array $run): array {
        return array_values(array_unique(array_filter([opsh_sys_email($run['subject_email'] ?? ''), opsh_sys_email($run['subject_upn'] ?? '')])));
    }

    function opsh_sys_step_title(array $sys, string $op): string {
        return mb_substr($sys['name'] . ' · ' . ($op === 'grant' ? 'set up the account' : 'remove the account'), 0, 190);
    }

    /** What a step shows: the system, what to do, and the accounts recorded for the person. */
    function opsh_sys_step_info(PDO $db, array $run, array $task, array $by = [], bool $refresh = false): array {
        $cfg = json_decode((string)($task['config'] ?? '{}'), true) ?: [];
        $key = (string)($cfg['system'] ?? ''); $op = ($cfg['op'] ?? '') === 'grant' ? 'grant' : 'remove';
        $sys = opsh_sys_get($db, $key);
        $name = $sys ? $sys['name'] : ($key !== '' ? $key : 'a system that was removed from the list');
        // BT45: a connected system is read before the step is shown, so the step says what the system says now
        $connected = $sys && $sys['mode'] === 'connected' && function_exists('opsh_conn_fresh');
        $read = null; $unmatched = null;
        if ($connected && in_array((string)$task['status'], ['pending', 'failed'], true)) {
            $f = opsh_conn_fresh($db, $key, $by, $refresh ? 0 : OPSH_CONN_FRESH);
            $read = ['ok' => (bool)$f['ok'], 'error' => $f['error'], 'at' => $f['at']];
            $unmatched = opsh_conn_unmatched($db, $key);
        }
        return ['task_id' => (int)$task['id'], 'run_id' => (int)$run['id'], 'op' => $op, 'status' => (string)$task['status'],
                'person' => (string)$run['subject_name'], 'emails' => opsh_sys_run_emails($run),
                'system' => ['key' => $key, 'name' => $name, 'url' => $sys['url'] ?? '', 'account_hint' => $sys['account_hint'] ?? 'the account name',
                             'text' => $sys ? ($op === 'grant' ? $sys['on_text'] : $sys['off_text']) : '', 'known' => (bool)$sys, 'connected' => (bool)$connected],
                'read' => $read, 'unmatched' => $unmatched,
                'links' => $links = ($key !== '' ? opsh_sys_links($db, opsh_sys_run_emails($run), 'active', $key) : []),
                // BT149: the Jotform forms a leaver owns, so they are moved before the member is removed (null = could not be read)
                'forms' => $connected && $key === 'jotform' && $op === 'remove' && in_array((string)$task['status'], ['pending', 'failed'], true)
                    ? (function () use ($db, $links) { require_once __DIR__ . '/JotformForms.php'; $u = array_values(array_filter(array_map(fn($l) => (string)($l['ext_id'] ?? ''), $links))); return $u ? opsh_jf_forms_of($db, $u) : []; })() : [],
                // BT153: a new person without an extension: the number to suggest and what it may call (null = the PBX could not be read)
                'pbx_new' => $connected && $key === 'pbx' && $op === 'grant' && !$links && ($read['ok'] ?? false) && in_array((string)$task['status'], ['pending', 'failed'], true)
                    ? (function () use ($db) { require_once __DIR__ . '/PbxService.php';
                        try { $rows = opsh_pbx_with($db, fn(OpshUcm $u) => $u->extensions()); } catch (Throwable $e) { return null; }
                        return ['next' => opsh_pbx_next_ext($rows), 'permission' => opsh_pbx_settings($db)['permission'], 'permissions' => opsh_pbx_permissions()]; })() : null];
    }

    /**
     * Complete a "system_access" step. Called by opsh_ob_task_action(); throws OpshObError for anything the person
     * must correct (the step stays open). Returns ['result' => text for the record, 'detail' => for the audit entry].
     *   remove: outcome = removed | none | moved (+ to_email)      grant: outcome = granted (+ account) | not_needed
     */
    function opsh_sys_step_run(PDO $db, array $run, array $task, array $cfg, array $body, array $user): array {
        $key = (string)($cfg['system'] ?? ''); $op = ($cfg['op'] ?? '') === 'grant' ? 'grant' : 'remove';
        $sys = opsh_sys_get($db, $key);
        if (!$sys) opsh_ob_fail('This system is no longer in the list of company systems. Skip the step, or add the system again.', 409);
        $uid = (int)($user['id'] ?? 0); $runId = (int)$run['id'];
        $outcome = is_string($body['outcome'] ?? null) ? $body['outcome'] : '';
        $note = opsh_sys_text($body['note'] ?? '', 300);
        $emails = opsh_sys_run_emails($run);
        $tail = $note !== '' ? ' · ' . $note : '';
        $list = fn(array $ls) => implode(', ', array_map(fn($l) => $l['account'] !== '' ? $l['account'] : 'account without a recorded name', $ls));

        // BT45: a connected system answers for itself. A person's word is not taken for what the system can be asked.
        // BT153: the phone system's extension is created from the onboarding step itself; then the step reads the PBX as usual
        if ($sys['mode'] === 'connected' && $key === 'pbx' && $op === 'grant' && $outcome === 'create_now') {
            require_once __DIR__ . '/PbxService.php';
            if (!$emails) opsh_ob_fail('This record has no work email for the person, so the extension could not be matched to them. Create it on the phone system tab and assign it.', 409);
            if (opsh_sys_links($db, $emails, 'active', 'pbx')) opsh_ob_fail('The phone system already lists an extension for this person. Use "Confirm: the user is there".', 409);
            $perm = is_string($body['permission'] ?? null) && $body['permission'] !== '' ? $body['permission'] : (string)opsh_pbx_settings($db)['permission'];
            try { opsh_pbx_create($db, trim((string)($body['account'] ?? '')), (string)$run['subject_name'], $emails[0], $perm, 'onboarding record #' . $runId); }
            catch (InvalidArgumentException $e) { opsh_ob_fail($e->getMessage() . ' Nothing was created.', 400); }
            catch (Throwable $e) { opsh_ob_fail('The phone system did not create the extension: ' . $e->getMessage(), 502); }
            $outcome = 'granted';
        }
        if ($sys['mode'] === 'connected' && function_exists('opsh_conn_step_run')) return opsh_conn_step_run($db, $sys, $run, $op, $outcome, $note, $user);

        if ($op === 'grant') {
            if (!in_array($outcome, ['granted', 'not_needed'], true)) opsh_ob_fail('Say what was done in ' . $sys['name'] . ': the account was set up, or it is not needed.', 400);
            if ($outcome === 'not_needed') return ['result' => $sys['name'] . ': not needed for this person' . $tail, 'detail' => ['system' => $sys['name'], 'outcome' => 'not needed']];
            if (!$emails) opsh_ob_fail('This record has no work email for the person, so the account cannot be recorded. Record it on the person, under Microsoft 365 → Accounts.', 400);
            $account = opsh_sys_text($body['account'] ?? '', 190);
            try { $r = opsh_sys_link_add($db, $sys, $emails[0], $account, $note, (string)$run['subject_name'], 'onboard', $uid); }
            catch (InvalidArgumentException $e) { opsh_ob_fail($e->getMessage(), 400); }
            return ['result' => $sys['name'] . ': set up' . ($account !== '' ? ' (' . $account . ')' : '') . ($r['existing'] ? ' — it was already recorded' : '') . $tail,
                    'detail' => ['system' => $sys['name'], 'outcome' => 'set up', 'account' => $account]];
        }

        if (!in_array($outcome, ['removed', 'none', 'moved'], true)) opsh_ob_fail('Say what was done in ' . $sys['name'] . ': the account was removed, it was handed over to somebody, or the person has no account there.', 400);
        $links = $emails ? opsh_sys_links($db, $emails, 'active', $key) : [];
        if ($outcome === 'moved') {
            $to = opsh_sys_email($body['to_email'] ?? '');
            if ($to === '') opsh_ob_fail('Enter the work email of the person who takes the account over.', 400);
            if (in_array($to, $emails, true)) opsh_ob_fail('That is the person who is leaving. Enter the colleague who takes the account over.', 400);
            if (!$links) opsh_ob_fail('No account is recorded for this person in ' . $sys['name'] . ', so there is nothing to hand over in the records. Choose "Removed" or "No account there".', 400);
            foreach ($links as $l) opsh_sys_link_move($db, $l, $sys['name'], $to, $uid, $runId);
            return ['result' => $sys['name'] . ': handed over to ' . $to . ' (' . $list($links) . ')' . $tail, 'detail' => ['system' => $sys['name'], 'outcome' => 'handed over', 'to' => $to, 'accounts' => $list($links)]];
        }
        $why = $outcome === 'none' ? 'checked: the person has no account there' : 'removed in the system';
        foreach ($links as $l) opsh_sys_link_remove($db, $l, $sys['name'], $uid, $runId, $why);
        if ($outcome === 'none')
            return ['result' => $sys['name'] . ': checked, no account there' . ($links ? ' (the recorded ' . $list($links) . ' was closed in the records)' : '') . $tail,
                    'detail' => ['system' => $sys['name'], 'outcome' => 'no account', 'accounts' => $list($links)]];
        return ['result' => $sys['name'] . ': removed' . ($links ? ' (' . $list($links) . ')' : ' (no account had been recorded)') . $tail,
                'detail' => ['system' => $sys['name'], 'outcome' => 'removed', 'accounts' => $list($links)]];
    }

    /** The systems a wizard offers for a person, with the accounts recorded. $kind = 'onboard' | 'offboard'. */
    function opsh_sys_for_person(PDO $db, string $kind, array $emails): array {
        $out = [];
        foreach (opsh_sys_in_use($db, $kind) as $s) {
            $links = $kind === 'offboard' ? opsh_sys_links($db, $emails, 'active', $s['key']) : [];
            $out[] = ['key' => $s['key'], 'name' => $s['name'], 'url' => $s['url'], 'account_hint' => $s['account_hint'], 'always_ask' => $s['always_ask'], 'connected' => $s['mode'] === 'connected',
                      'text' => $kind === 'onboard' ? $s['on_text'] : $s['off_text'],
                      'links' => array_map(fn($l) => ['id' => $l['id'], 'account' => $l['account'], 'note' => $l['note']], $links),
                      // offboarding: ticked when an account is recorded, or when the system asks every time
                      'suggest' => $kind === 'offboard' ? ($links || $s['always_ask']) : false];
        }
        return $out;
    }

    /** Insert the pending steps for the chosen systems. Returns the number added. Unknown or unused keys are refused. */
    function opsh_sys_add_steps(PDO $db, int $runId, string $kind, array $keys, int &$sort): int {
        $byKey = []; foreach (opsh_sys_in_use($db, $kind) as $s) $byKey[$s['key']] = $s;
        $ins = $db->prepare("INSERT INTO opsh_ob_run_tasks (run_id,sort_order,title,task_type,config,assignee_role,required,due_date,status) VALUES (?,?,?,'system_access',?,NULL,1,NULL,'pending')");
        $n = 0; $done = [];
        foreach ($keys as $k) {
            if (!is_string($k) || isset($done[$k]) || !isset($byKey[$k])) continue;
            $done[$k] = 1; $op = $kind === 'onboard' ? 'grant' : 'remove';
            $ins->execute([$runId, ($sort += 10), opsh_sys_step_title($byKey[$k], $op), json_encode(['system' => $k, 'op' => $op])]); $n++;
        }
        return $n;
    }

    /** Keys from a screen that are systems in use for this kind. Throws when one is not (nothing is created then). */
    function opsh_sys_check_keys(PDO $db, string $kind, $keys): array {
        if (!is_array($keys)) throw new InvalidArgumentException('The list of company systems is not a list. Nothing was started.');
        $ok = []; foreach (opsh_sys_in_use($db, $kind) as $s) $ok[$s['key']] = 1;
        $out = [];
        foreach ($keys as $k) {
            if (!is_string($k) || !isset($ok[$k])) throw new InvalidArgumentException('One of the company systems on the screen is not in use for ' . ($kind === 'onboard' ? 'onboarding' : 'offboarding') . ' any more. Reload the page and start again. Nothing was started.');
            $out[$k] = 1;
        }
        return array_keys($out);
    }

    /**
     * Lines for "Verify offboarding": for every system in use at offboarding, is an account still recorded as open?
     * By hand means OpsHub reports what the records say, and says so.
     */
    function opsh_sys_verify_checks(PDO $db, array $run): array {
        $emails = opsh_sys_run_emails($run); $checks = [];
        $doneBy = [];
        try {
            $tq = $db->prepare("SELECT t.config, t.status, t.result, t.completed_at FROM opsh_ob_run_tasks t WHERE t.run_id = ? AND t.task_type = 'system_access'"); $tq->execute([(int)$run['id']]);
            foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) { $c = json_decode((string)$t['config'], true) ?: []; if (!empty($c['system'])) $doneBy[(string)$c['system']] = $t; }
        } catch (Throwable $e) {}
        foreach (opsh_sys_in_use($db, 'offboard') as $s) {
            $step = $doneBy[$s['key']] ?? null;
            if ($s['mode'] === 'connected' && function_exists('opsh_conn_verify_check')) { $checks[] = opsh_conn_verify_check($db, $s, $emails, $step); continue; }   // BT45: asked, not remembered
            $open = $emails ? opsh_sys_links($db, $emails, 'active', $s['key']) : [];
            $label = 'Account removed (recorded by hand)';
            if ($open) {
                $checks[] = ['system' => $s['name'], 'label' => $label, 'status' => 'fail',
                    'detail' => count($open) . ' account(s) still recorded as open: ' . implode(', ', array_map(fn($l) => $l['account'] !== '' ? $l['account'] : 'name not recorded', $open))];
            } elseif ($step && $step['status'] === 'done') {
                $checks[] = ['system' => $s['name'], 'label' => $label, 'status' => 'pass', 'detail' => (string)$step['result'] . ($step['completed_at'] ? ' — recorded ' . substr((string)$step['completed_at'], 0, 10) : '')];
            } elseif ($step && in_array($step['status'], ['pending', 'failed'], true)) {
                $checks[] = ['system' => $s['name'], 'label' => $label, 'status' => 'fail', 'detail' => 'The step "' . opsh_sys_step_title($s, 'remove') . '" is still open: nobody has recorded what was done'];
            } else {
                $closed = $emails ? opsh_sys_links($db, $emails, 'removed', $s['key']) : [];
                if ($closed) $checks[] = ['system' => $s['name'], 'label' => $label, 'status' => 'pass', 'detail' => 'The recorded account was closed' . ($closed[0]['removed_at'] ? ' on ' . substr((string)$closed[0]['removed_at'], 0, 10) : '')];
                else $checks[] = ['system' => $s['name'], 'label' => $label, 'status' => 'skip', 'detail' => 'No account is recorded for this person and nobody was asked to check'];
            }
        }
        return $checks;
    }

    // ───────────────────────────── overview ─────────────────────────────

    /**
     * Every person against every system in use. $dir = the Microsoft 365 directory (rows with id, display_name, upn,
     * mail, enabled) or null when it could not be read; $vt = vTiger users (rows with email1/email, status, user_name)
     * or null. A person who is in the records but not in Microsoft 365 is listed too.
     */
    function opsh_sys_overview(PDO $db, ?array $dir, ?array $vt, array $unsure = []): array {   // $unsure (BT49): addresses Microsoft 365 could not be asked about
        $systems = opsh_sys_in_use($db);
        $rows = []; $byEmail = [];
        foreach ($dir ?? [] as $u) {
            $upn = opsh_sys_email($u['upn'] ?? ''); $mail = opsh_sys_email($u['mail'] ?? '');
            if ($upn === '' && $mail === '') continue;
            if (isset($byEmail[$upn !== '' ? $upn : $mail])) continue;   // one row per sign-in name (the screens key rows by it)
            $i = count($rows);
            $rows[] = ['email' => $upn !== '' ? $upn : $mail, 'name' => (string)($u['display_name'] ?? ''), 'm365_id' => (string)($u['id'] ?? ''),
                       'm365' => !empty($u['enabled']) ? 'enabled' : 'disabled', 'vtiger' => null, 'cells' => [], 'open' => 0, 'flag' => ''];
            $also = array_map('opsh_sys_email', is_array($u['also'] ?? null) ? $u['also'] : []);   // BT49: an alias of the account that another system uses
            foreach (array_unique(array_filter(array_merge([$upn, $mail], $also))) as $e) if (!isset($byEmail[$e])) $byEmail[$e] = $i;
        }
        $vtBy = [];
        foreach ($vt ?? [] as $v) { $e = opsh_sys_email($v['email1'] ?? ($v['email'] ?? '')); if ($e !== '' && (!isset($vtBy[$e]) || ($v['status'] ?? '') === 'Active')) $vtBy[$e] = ['user' => (string)($v['user_name'] ?? ''), 'status' => (string)($v['status'] ?? ''), 'name' => trim((string)($v['first_name'] ?? '') . ' ' . (string)($v['last_name'] ?? ''))]; }
        foreach ($byEmail as $e => $i) if (isset($vtBy[$e]) && $rows[$i]['vtiger'] === null) $rows[$i]['vtiger'] = $vtBy[$e];
        // BT46: an ACTIVE vTiger user whose email no Microsoft 365 account has is listed as a person of its own, so the page
        // shows everybody who can sign in to one of the two connected systems. (Inactive ones are history, not access.)
        foreach ($vtBy as $e => $v) {
            if (isset($byEmail[$e]) || strcasecmp($v['status'], 'Active') !== 0) continue;
            $byEmail[$e] = count($rows);
            $rows[] = ['email' => $e, 'name' => $v['name'] !== '' ? $v['name'] : $v['user'], 'm365_id' => '', 'm365' => ($dir === null || isset($unsure[$e])) ? 'unknown' : 'none',
                       'vtiger' => $v, 'cells' => [], 'open' => 0, 'flag' => '', 'only' => 'vtiger'];
        }
        $keys = array_column($systems, 'key');
        $links = [];
        if ($keys) {
            // ext_id came with BT45: until db-update.php has run the accounts are listed without it, not hidden
            foreach (['id, system_key, email, person_name, account, ext_id', 'id, system_key, email, person_name, account'] as $cols) {
                try {
                    $q = $db->prepare("SELECT $cols FROM opsh_people_links WHERE status = 'active' AND system_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ") ORDER BY email, system_key, account");
                    $q->execute($keys); $links = $q->fetchAll(PDO::FETCH_ASSOC); break;
                } catch (Throwable $e) { $links = []; }
            }
        }
        foreach ($links as $l) {
            $e = (string)$l['email'];
            if (!isset($byEmail[$e])) {
                $byEmail[$e] = count($rows);
                $rows[] = ['email' => $e, 'name' => (string)($l['person_name'] ?? ''), 'm365_id' => '', 'm365' => ($dir === null || isset($unsure[$e])) ? 'unknown' : 'none',
                           'vtiger' => $vtBy[$e] ?? null, 'cells' => [], 'open' => 0, 'flag' => ''];
            }
            $i = $byEmail[$e]; $k = (string)$l['system_key'];
            $rows[$i]['cells'][$k][] = ['id' => (int)$l['id'], 'account' => (string)$l['account'], 'read' => isset($l['ext_id']) && $l['ext_id'] !== null && $l['ext_id'] !== ''];
            $rows[$i]['open']++;
        }
        $sum = []; foreach ($systems as $s) $sum[$s['key']] = ['key' => $s['key'], 'name' => $s['name'], 'open' => 0, 'attention' => 0, 'connected' => $s['mode'] === 'connected'];
        $attention = 0;
        foreach ($rows as &$r) {
            // left: the Microsoft 365 account is blocked and something is still open (an account in a company system, or the
            // vTiger user is still active); unknown: no Microsoft 365 account has this email
            $vtOn = is_array($r['vtiger']) && strcasecmp((string)$r['vtiger']['status'], 'Active') === 0;
            if ($r['m365'] === 'disabled' && ($r['open'] > 0 || $vtOn)) $r['flag'] = 'left';
            elseif ($r['open'] > 0 && $r['m365'] === 'none') $r['flag'] = 'unknown';
            if ($r['flag'] !== '') $attention++;
            foreach ($r['cells'] as $k => $ls) { if (!isset($sum[$k])) continue; $sum[$k]['open'] += count($ls); if ($r['flag'] !== '') $sum[$k]['attention'] += count($ls); }
        }
        unset($r);
        usort($rows, fn($a, $b) => [$a['flag'] === '' ? 1 : 0, mb_strtolower($a['name'] !== '' ? $a['name'] : $a['email'])] <=> [$b['flag'] === '' ? 1 : 0, mb_strtolower($b['name'] !== '' ? $b['name'] : $b['email'])]);
        foreach ($rows as &$r) $r['cells'] = (object)$r['cells'];   // {} for a person with nothing recorded, not []
        unset($r);
        $c = ['m365_active' => 0, 'm365_blocked' => 0, 'vtiger' => 0, 'vtiger_active' => 0];
        foreach ($rows as $r) {
            if ($r['m365'] === 'enabled') $c['m365_active']++; elseif ($r['m365'] === 'disabled') $c['m365_blocked']++;
            if (is_array($r['vtiger'])) { $c['vtiger']++; if (strcasecmp((string)$r['vtiger']['status'], 'Active') === 0) $c['vtiger_active']++; }
        }
        return ['systems' => array_map(fn($s) => ['key' => $s['key'], 'name' => $s['name'], 'connected' => $s['mode'] === 'connected'], $systems), 'rows' => $rows, 'summary' => array_values($sum),
                'people' => count($rows), 'attention' => $attention, 'counts' => $c];
    }

    /**
     * The Microsoft 365 directory, from the same 10-minute cache file the Microsoft 365 reports use (an account change
     * made in OpsHub drops it). Returns ['users' => rows | null, 'error' => text | null, 'at' => unix time | null].
     * $load = false: only what the cache has (no call to Microsoft).
     */
    function opsh_sys_directory(PDO $db, bool $fresh = false, bool $load = true): array {
        $cacheDir = (function () { require_once __DIR__ . '/M365Background.php'; return opsh_m365_cache_dir(); })();
        $cacheFile = $cacheDir . '/m365-directory.json';
        $dir = null; $err = null; $at = null;
        if (!$fresh && is_file($cacheFile)) { $j = json_decode((string)@file_get_contents($cacheFile), true); if (is_array($j) && (time() - (int)($j['ts'] ?? 0)) <= 600 && is_array($j['payload'] ?? null)) { $dir = $j['payload']; $at = (int)$j['ts']; } }
        if ($dir === null && $load) {
            try {
                require_once __DIR__ . '/Graph.php';
                if (!function_exists('opsh_graph_configured') || !opsh_graph_configured()) throw new RuntimeException('Microsoft 365 is not connected');
                require_once __DIR__ . '/M365Hub.php';
                try { $db->exec('SET SESSION wait_timeout=1800'); } catch (Throwable $e) {}
                $dir = OpshM365::fromConfig()->directoryForRecon(); $at = time();
                if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
                @file_put_contents($cacheFile, json_encode(['ts' => $at, 'payload' => $dir]), LOCK_EX); @chmod($cacheFile, 0600);
            } catch (Throwable $e) { $dir = null; $err = $e->getMessage(); }
        }
        // directoryForRecon() answers {users: [...], has_sign_in: …, roster: …}; callers need the list
        $roster = is_array($dir) && is_array($dir['roster'] ?? null) ? $dir['roster'] : null;   // BT49: how complete the read was
        if (is_array($dir) && isset($dir['users']) && is_array($dir['users'])) $dir = $dir['users'];
        return ['users' => is_array($dir) ? $dir : null, 'error' => $err, 'at' => $at, 'roster' => $roster];
    }

    /**
     * BT49: before the page says "no Microsoft 365 account" for an address, Microsoft 365 is asked for that address.
     * The addresses are the ones another system uses (an active vTiger user, an open account in a company system) and
     * that the account listing does not have. What comes back is folded into the directory for this page:
     *   - an account the listing left out is added;
     *   - an account that has the address as an alias gets it as `also`, so the person is matched;
     *   - a guest account is added and marked (the listing leaves guests out on purpose).
     * The answers are kept for ten minutes next to the directory; "Refresh" asks again. Never throws.
     * Returns ['dir' => directory (unchanged when nothing was found), 'check' => counts for the page, or null,
     *          'unsure' => [address => true] for the ones Microsoft 365 could not be asked about: they are "not read", not "no account"].
     */
    function opsh_sys_verify_absent(PDO $db, ?array $dir, ?array $vt, bool $fresh = false): array {
        if ($dir === null) return ['dir' => $dir, 'check' => null];
        $have = []; $byId = [];
        foreach ($dir as $i => $u) { foreach ([opsh_sys_email($u['upn'] ?? ''), opsh_sys_email($u['mail'] ?? '')] as $e) if ($e !== '') $have[$e] = true; if (($u['id'] ?? '') !== '') $byId[(string)$u['id']] = $i; }
        $ask = [];
        foreach ($vt ?? [] as $v) { $e = opsh_sys_email($v['email1'] ?? ($v['email'] ?? '')); if ($e !== '' && !isset($have[$e]) && strcasecmp((string)($v['status'] ?? ''), 'Active') === 0) $ask[$e] = true; }
        try { foreach ($db->query("SELECT DISTINCT email FROM opsh_people_links WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $e) { $e = opsh_sys_email($e); if ($e !== '' && !isset($have[$e])) $ask[$e] = true; } } catch (Throwable $e) {}
        if (!$ask) return ['dir' => $dir, 'check' => ['asked' => 0, 'found' => 0, 'listing' => 0, 'alias' => 0, 'guest' => 0, 'none' => 0, 'unchecked' => 0, 'errors' => 0]];
        $cacheDir = (function () { require_once __DIR__ . '/M365Background.php'; return opsh_m365_cache_dir(); })();
        $cacheFile = $cacheDir . '/m365-absent.json';
        $known = [];
        if (!$fresh && is_file($cacheFile)) { $j = json_decode((string)@file_get_contents($cacheFile), true); if (is_array($j) && (time() - (int)($j['ts'] ?? 0)) <= 600 && is_array($j['payload'] ?? null)) $known = $j['payload']; }
        $todo = array_values(array_filter(array_keys($ask), fn($e) => !isset($known[$e]) || in_array($known[$e]['state'] ?? '', ['unchecked', 'error'], true)));
        if ($todo) {
            try {
                require_once __DIR__ . '/Graph.php'; require_once __DIR__ . '/M365Hub.php';
                $got = OpshM365::fromConfig()->accountsByAddress($todo);
                foreach ($got as $e => $r) $known[$e] = $r;
                if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
                @file_put_contents($cacheFile, json_encode(['ts' => time(), 'payload' => $known]), LOCK_EX); @chmod($cacheFile, 0600);
                // The Event Log says what the check found, so the reason is on record: counts only, no addresses
                $c = ['listing' => 0, 'alias' => 0, 'guest' => 0];
                foreach ($got as $r) if (($r['state'] ?? '') === 'found') { $k = !empty($r['user']['guest']) ? 'guest' : (($r['how'] ?? '') === 'alias' ? 'alias' : 'listing'); $c[$k]++; }
                if (array_sum($c) > 0 && function_exists('opsh_log')) {
                    try { opsh_log('warning', 'm365', 'Company systems: ' . array_sum($c) . ' of ' . count($got) . ' addresses that the Microsoft 365 account listing did not have do have an account: '
                        . $c['listing'] . ' left out of the listing, ' . $c['alias'] . ' under an alias, ' . $c['guest'] . ' guest account(s). They are shown with their account.', ['asked' => count($got)] + $c); } catch (Throwable $e) {}
                }
            } catch (Throwable $e) { foreach ($todo as $e2) if (!isset($known[$e2])) $known[$e2] = ['state' => 'error', 'error' => $e->getMessage()]; }
        }
        $check = ['asked' => count($ask), 'found' => 0, 'listing' => 0, 'alias' => 0, 'guest' => 0, 'none' => 0, 'unchecked' => 0, 'errors' => 0]; $unsure = [];
        foreach (array_keys($ask) as $e) {
            $r = $known[$e] ?? ['state' => 'unchecked']; $st = (string)($r['state'] ?? 'unchecked');
            if ($st === 'none') { $check['none']++; continue; }
            if ($st === 'error') { $check['errors']++; $unsure[$e] = true; continue; }
            if ($st !== 'found' || !is_array($r['user'] ?? null) || ($r['user']['id'] ?? '') === '') { $check['unchecked']++; $unsure[$e] = true; continue; }
            $u = $r['user']; $check['found']++;
            $check[!empty($u['guest']) ? 'guest' : (($r['how'] ?? '') === 'alias' ? 'alias' : 'listing')]++;
            if (isset($byId[$u['id']])) { $i = $byId[$u['id']]; $dir[$i]['also'] = array_values(array_unique(array_merge($dir[$i]['also'] ?? [], [$e]))); }
            else { if (!empty($u['guest']) && opsh_sys_email($u['mail'] ?? '') !== '') $u['upn'] = $u['mail'];   // a guest signs in with another organization's address: its mail is the one people know
                   $u['also'] = [$e]; $u['found_by'] = !empty($u['guest']) ? 'guest' : (($r['how'] ?? '') === 'alias' ? 'alias' : 'listing'); $byId[$u['id']] = count($dir); $dir[] = $u; }
        }
        return ['dir' => $dir, 'check' => $check, 'unsure' => $unsure];
    }

    /** Rows of a pasted list: "email, account, note" per line (comma, semicolon or tab). */
    function opsh_sys_parse_list(string $text): array {
        $out = [];
        foreach (preg_split('/\n/', str_replace(["\r\n", "\r"], "\n", $text)) ?: [] as $n => $line) {
            if (trim($line) === '') continue;
            $p = preg_split('/[\t;,]/', $line, 3) ?: [];
            $out[] = ['line' => $n + 1, 'email' => trim((string)($p[0] ?? '')), 'account' => trim((string)($p[1] ?? '')), 'note' => trim((string)($p[2] ?? ''))];
        }
        return $out;
    }
}

require_once __DIR__ . '/SystemConnect.php';   // BT45: the connections (it needs the functions above)
