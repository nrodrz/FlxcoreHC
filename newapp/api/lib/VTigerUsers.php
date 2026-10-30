<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — vTiger users: edit a vTiger user's profile, set their password, and edit their row of the site's
 * `employees` table (BT67). Everything goes through OpsHub's own bridge inside vTiger (opsh-bridge.php, build o6), so
 * vTiger's own code saves the user (its cached user files are rebuilt, its save handlers run) and the read-only
 * database account OpsHub uses for lookups stays read-only.
 *
 * The employees table is filled from vtiger_users by the site's own cron. Its columns are of three kinds (set on the
 * settings page; OpsHub suggests them from the column names):
 *   cron  copied from a vTiger user field by the cron — read-only here, shown as "In sync" / "Waiting for the cron"
 *   edit  only in the employees table — edited here
 *   hide  not shown (the key, the link to the user, timestamps)
 * So the cron and OpsHub never write the same column.
 *
 * BT69: the employee record can also be a vTiger module (for example VTE Employees: vtiger_vteemployee and its custom
 * fields in vtiger_vteemployeecf). Give any table of the module: the bridge (build o7) then lists the module's fields —
 * labels, picklists, read-only ones — finds the record by the link field and saves it with vTiger's own record save.
 */
require_once __DIR__ . '/VTigerProvision.php';

if (!function_exists('opsh_vtu_call')) {
    /** A bridge call. Throws RuntimeException with a sentence for the person. */
    function opsh_vtu_call(PDO $db, string $action, array $payload = []): array {
        $prov = opsh_vtiger_cfg($db)['prov'];
        if (!opsh_vtiger_bridge_ready($prov)) throw new RuntimeException('The vTiger bridge is not set up (Settings → Company systems → vTiger: bridge address and secret).');
        $r = opsh_vtiger_bridge_call($prov, $action, $payload);
        if (empty($r['ok'])) {
            $e = (string)($r['error'] ?? 'unknown error');
            if (stripos($e, 'Unknown action') !== false) throw new RuntimeException('The vTiger bridge is an older build: upload the new opsh-bridge.php (build o11, in the vtiger-bridge folder of this release) to the vTiger web root.');
            throw new RuntimeException('vTiger: ' . $e);
        }
        return $r;
    }
    /** BT89 (M-39): vTiger's own tables, never an employee table (the same rule as bridge o8). */
    function opsh_vtu_system_table(string $t): bool {
        $l = strtolower($t);
        return (bool)preg_match('/^(vtiger_(users|user2|user_|role|profile|def_org|org_share|tmp_|datashare|group|users2group|crmentity|tab|field|blocks|entityname|ws_|asterisk|systems|mail_accounts|portalinfo|customerportal|loginhistory|audit_trial|settings|currency|organizationdetails|shareduser|sharedcalendar|homestuff|home_layout|seq|modentity|modtracker|crmsetup|relatedlists|links|app2tab|parenttab|picklist|role2picklist|mailscanner|eventhandler|cron_task|emailtemplates|notificationscheduler|inventorynotification|mail_|outgoingserver|systems|config|version|language|module_dashboard|import_|scheduled_reports|reportfilters|cvadvfilter|customview)|com_vtiger_|information_schema|mysql|performance_schema|sys$)/', $l)
            || preg_match('/_seq$/', $l) || preg_match('/(passw|session|token|secret)/', $l);
    }
    /** Passwords travel only over HTTPS (or to the same machine). */
    function opsh_vtu_bridge_secure(PDO $db): bool {
        return opsh_vtiger_url_secure((string)(opsh_vtiger_cfg($db)['prov']['bridge_url'] ?? ''));
    }
    /** The user fields (vTiger's own list and sections) with the choices of the list fields. */
    function opsh_vtu_meta(PDO $db): array {
        static $m = null; if ($m !== null) return $m;
        $r = opsh_vtu_call($db, 'user_fields');
        return $m = ['fields' => (array)($r['fields'] ?? []), 'picklists' => (array)($r['picklists'] ?? []), 'admin_id' => (string)($r['admin_id'] ?? '')];
    }
    /** What kind of input a vTiger uitype is. */
    function opsh_vtu_kind(array $f): string {
        $u = (int)$f['uitype'];
        if ($f['name'] === 'is_admin' || $u === 156) return 'admin';
        if ($u === 56) return 'check';
        if (in_array($u, [15, 16, 32, 33, 98, 101, 115, 117], true) || $f['name'] === 'roleid' || $f['name'] === 'status') return 'pick';
        if (in_array($u, [13, 104], true)) return 'email';
        if ($u === 5) return 'date';
        if (in_array($u, [7, 9, 71, 72], true)) return 'number';
        if (in_array($u, [19, 20, 21, 24], true)) return 'long';
        if ($u === 106 || $f['name'] === 'user_name') return 'fixed';
        return 'text';
    }
    /** Check one value for one user field. Returns the clean value or throws InvalidArgumentException. */
    function opsh_vtu_clean_field(array $f, $v, array $pick): string {
        $bad = fn($m) => throw new InvalidArgumentException('"' . $f['label'] . '": ' . $m);
        if (!is_scalar($v) && $v !== null) $bad('not a value.');
        $v = trim(str_replace(["\r\n", "\r"], "\n", (string)$v)); $k = opsh_vtu_kind($f);
        if ($k === 'fixed' || !empty($f['readonly'])) $bad('cannot be changed here.');
        if ($k !== 'long') $v = preg_replace('/\s+/u', ' ', $v);
        if ($f['mandatory'] && $v === '') $bad('is required.');
        if ($k === 'admin') { if (!in_array($v, ['on', 'off'], true)) $bad('on or off.'); return $v; }
        if ($k === 'check') { if (!in_array($v, ['0', '1'], true)) $bad('yes or no.'); return $v; }
        if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $bad('not a valid email address.');
        if ($k === 'date' && $v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)))) $bad('not a valid date.');
        if ($k === 'number' && $v !== '' && !is_numeric($v)) $bad('must be a number.');
        if ($k === 'pick' && isset($pick[$f['name']])) { $ok = array_map(fn($o) => (string)$o[0], $pick[$f['name']]); if (!in_array($v, $ok, true)) $bad('choose from the list.'); }
        $max = (int)($f['max'] ?? 0); if ($max > 0 && $k !== 'pick' && mb_strlen($v) > max($max, $k === 'long' ? 5000 : 0)) $bad('at most ' . $max . ' characters.');
        if (mb_strlen($v) > 20000) $bad('too long.');
        return $v;
    }
    /** "Jane Doe" for a value as the person sees it (list labels instead of ids). */
    function opsh_vtu_show(array $f, string $v, array $pick): string {
        $k = opsh_vtu_kind($f);
        if ($k === 'admin') return $v === 'on' ? 'yes' : 'no';
        if ($k === 'check') return $v === '1' ? 'yes' : 'no';
        if ($k === 'pick' && isset($pick[$f['name']])) foreach ($pick[$f['name']] as $o) if ((string)$o[0] === $v) return (string)$o[1] !== '' ? (string)$o[1] : $v;
        return mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '…' : $v;
    }

    // ── the employees table ──
    function opsh_vtu_alias(): array {
        return ['email' => 'email1', 'email1' => 'email1', 'work_email' => 'email1', 'primary_email' => 'email1', 'first_name' => 'first_name', 'firstname' => 'first_name', 'fname' => 'first_name',
                'last_name' => 'last_name', 'lastname' => 'last_name', 'lname' => 'last_name', 'title' => 'title', 'job_title' => 'title', 'department' => 'department', 'dept' => 'department',
                'phone' => 'phone_work', 'office_phone' => 'phone_work', 'work_phone' => 'phone_work', 'phone_work' => 'phone_work', 'mobile' => 'phone_mobile', 'mobile_phone' => 'phone_mobile',
                'cell' => 'phone_mobile', 'cell_phone' => 'phone_mobile', 'phone_mobile' => 'phone_mobile', 'home_phone' => 'phone_home', 'phone_home' => 'phone_home', 'fax' => 'phone_fax',
                'user_name' => 'user_name', 'username' => 'user_name', 'status' => 'status', 'reports_to' => 'reports_to_id', 'reports_to_id' => 'reports_to_id', 'manager_id' => 'reports_to_id',
                'street' => 'address_street', 'address' => 'address_street', 'address_street' => 'address_street', 'city' => 'address_city', 'address_city' => 'address_city', 'state' => 'address_state',
                'address_state' => 'address_state', 'zip' => 'address_postalcode', 'postal_code' => 'address_postalcode', 'postalcode' => 'address_postalcode', 'address_postalcode' => 'address_postalcode',
                'country' => 'address_country', 'address_country' => 'address_country'];
    }
    /** OpsHub's suggestion for a table: how a row links to its user, and what each column is. */
    function opsh_vtu_emp_suggest(array $cols, array $userFields): array {
        $names = array_column($cols, 'name'); $lc = ''; $src = 'id';
        foreach (['vtiger_user_id', 'vtiger_userid', 'crm_user_id', 'user_id', 'userid', 'vt_user_id'] as $c) if (in_array($c, $names, true)) { $lc = $c; $src = 'id'; break; }
        if ($lc === '') foreach ($cols as $c) if (($c['ref'] ?? '') === 'user' && !in_array($c['name'], ['assigned_user_id', 'created_user_id', 'modifiedby'], true)) { $lc = $c['name']; $src = 'id'; break; }   // a vTiger module: its own "user" field (not "Assigned To")
        if ($lc === '') foreach (['user_name', 'username', 'vtiger_user_name'] as $c) if (in_array($c, $names, true)) { $lc = $c; $src = 'user_name'; break; }
        if ($lc === '') foreach (['email', 'email1', 'work_email'] as $c) if (in_array($c, $names, true)) { $lc = $c; $src = 'email1'; break; }
        if ($lc === '') foreach ($cols as $c) if (stripos((string)($c['extra'] ?? ''), 'email') !== false) { $lc = $c['name']; $src = 'email1'; break; }
        $al = opsh_vtu_alias(); $uf = array_flip($userFields); $out = [];
        foreach ($cols as $c) {
            $n = $c['name']; $ln = strtolower($n); $ll = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($c['label'] ?? ''))), '_');
            if ($n === $lc || $c['key'] === 'PRI' || stripos((string)$c['extra'], 'auto_increment') !== false || stripos((string)$c['extra'], 'on update') !== false
                || preg_match('/^(created|updated|modified)(_at|_on|_time|time|date)?$/', $ln)) { $out[$n] = ['role' => 'hide', 'from' => '']; continue; }
            $from = isset($uf[$n]) ? $n : ($al[$ln] ?? (($ll !== 'status' && isset($al[$ll])) ? $al[$ll] : ''));   // by label too, but an employee "Status" is not the user's Active / Inactive
            if (isset($c['options']) && $from === 'status') $from = '';
            if (in_array($n, ['assigned_user_id', 'createdtime', 'modifiedtime', 'created_user_id', 'modifiedby', 'source', 'starred', 'tags'], true)) { $out[$n] = ['role' => 'hide', 'from' => '']; continue; }
            $cron = $from !== '' && isset($uf[$from]);
            $out[$n] = ['role' => $cron ? 'cron' : (opsh_vtu_ro($c) ? 'hide' : 'edit'), 'from' => $cron ? $from : ''];
        }
        return ['link_column' => $lc, 'link_source' => $src, 'cols' => $out];
    }
    /** The table (or vTiger module) as the bridge sees it. Build o7 says which it is; an older bridge is asked to be replaced. */
    function opsh_vtu_schema(PDO $db, string $table): array {
        $sch = opsh_vtu_call($db, 'employees_schema', ['table' => $table]);
        if (!isset($sch['kind'])) throw new RuntimeException('The vTiger bridge is an older build: upload the new opsh-bridge.php (build o11, in the vtiger-bridge folder of this release) to the vTiger web root. Build o7 reads employee records kept in a vTiger module (such as VTE Employees).');
        return $sch;
    }
    /** BT70: the suggestions worth showing — never vTiger's shared tables (vtiger_crmentity*), settings tables or custom-field lists. */
    function opsh_vtu_candidates(array $c): array {
        $c = array_values(array_filter($c, fn($x) => !preg_match('/^vtiger_crmentity|_settings$|^vtiger_cf_|_seq$|cf$/i', (string)($x['table'] ?? ''))));
        $best = opsh_vtu_pick_module($c);   // the likeliest first
        if ($best) { $c = array_values(array_filter($c, fn($x) => $x['table'] !== $best['table'])); array_unshift($c, $best); }
        return $c;
    }
    /** The employee module to use when nothing is set: a module called "Employees" (or VTE Employees) whose main table is vtiger_<module>. */
    function opsh_vtu_pick_module(array $cand): ?array {
        $best = null; $score = 0;
        foreach ($cand as $x) {
            $m = (string)($x['module'] ?? ''); if ($m === '' || strtolower((string)$x['table']) !== 'vtiger_' . strtolower($m)) continue;
            $sc = strcasecmp(trim((string)($x['label'] ?? '')), 'Employees') === 0 ? 3 : (stripos($m, 'vteemployee') !== false ? 2 : (preg_match('/^employees?$/i', $m) ? 2 : 0));
            if ($sc > $score) { $best = $x; $score = $sc; }
        }
        return $best;
    }
    function opsh_vtu_ro(array $c): bool { return stripos((string)($c['extra'] ?? ''), 'readonly') !== false; }
    function opsh_vtu_emp_cfg(PDO $db): array {
        $j = json_decode((string)($db->query("SELECT sval FROM opsh_settings WHERE skey='vtiger_employees'")->fetchColumn() ?: ''), true);
        return is_array($j) ? $j + ['table' => 'employees', 'link_column' => '', 'link_source' => 'id', 'cols' => [], 'saved' => true] : ['table' => 'employees', 'link_column' => '', 'link_source' => 'id', 'cols' => [], 'saved' => false];
    }
    /** The table as it is now, with the settings (saved, or OpsHub's suggestion when nothing is saved yet). */
    function opsh_vtu_emp_state(PDO $db, ?array $userFields = null, ?string $table = null): array {
        $cfg = opsh_vtu_emp_cfg($db);
        if ($table !== null && $table !== $cfg['table']) $cfg = ['table' => $table, 'link_column' => '', 'link_source' => 'id', 'cols' => [], 'saved' => false];   // a preview of another table
        $sch = opsh_vtu_schema($db, $cfg['table']);
        if (empty($sch['exists'])) {
            $cand = opsh_vtu_candidates((array)($sch['candidates'] ?? []));
            // BT70: nothing saved yet and no "employees" table — use the site's employee module (shown as a suggestion until saved)
            $pick = (!$cfg['saved'] && $table === null) ? opsh_vtu_pick_module($cand) : null;
            if ($pick === null) return ['exists' => false, 'table' => $cfg['table'], 'cfg' => $cfg, 'candidates' => $cand];
            $cfg = ['table' => $pick['table'], 'link_column' => '', 'link_source' => 'id', 'cols' => [], 'saved' => false];
            $sch = opsh_vtu_schema($db, $pick['table']);
            if (empty($sch['exists'])) return ['exists' => false, 'table' => $pick['table'], 'cfg' => $cfg, 'candidates' => $cand];
            $auto = true;
        }
        $cols = (array)$sch['columns'];
        $userFields = $userFields ?? array_column(opsh_vtu_meta($db)['fields'], 'name');
        $sug = opsh_vtu_emp_suggest($cols, $userFields);
        if (!$cfg['saved']) { $cfg = $sug + ['table' => $cfg['table'], 'saved' => false]; }
        else foreach ($cols as $c) if (!isset($cfg['cols'][$c['name']])) $cfg['cols'][$c['name']] = $sug['cols'][$c['name']] + ['new' => true];   // a column added since: OpsHub's suggestion until saved
        return ['exists' => true, 'table' => $cfg['table'], 'columns' => $cols, 'rows' => (int)($sch['rows'] ?? 0), 'cfg' => $cfg, 'suggested' => $sug,
                'kind' => (string)$sch['kind'], 'module' => (string)($sch['module'] ?? ''), 'module_label' => (string)($sch['module_label'] ?? ''), 'auto' => !empty($auto)];
    }
    function opsh_vtu_link_value(array $cfg, string $id, array $vals): string {
        return $cfg['link_source'] === 'user_name' ? (string)($vals['user_name'] ?? '') : ($cfg['link_source'] === 'email1' ? (string)($vals['email1'] ?? '') : $id);
    }
    /** A column's type, in words the form understands: text | long | int | decimal | date | datetime | yesno | list. */
    function opsh_vtu_col_kind(array $c): array {
        $t = strtolower($c['type']);
        if (isset($c['options']) && is_array($c['options'])) return ['kind' => 'list', 'options' => array_map(fn($o) => is_array($o) ? [(string)$o[0], (string)($o[1] ?? $o[0])] : [(string)$o, (string)$o], $c['options'])];   // a vTiger picklist or user field
        if (preg_match('/^enum\((.*)\)$/is', (string)$c['type'], $m)) return ['kind' => 'list', 'options' => array_map(fn($o) => [$o, $o], str_getcsv($m[1], ',', "'", ''))];   // MySQL doubles a quote inside a value
        if (stripos((string)($c['extra'] ?? ''), 'email') !== false) return ['kind' => 'email', 'max' => 100];
        if (preg_match('/^tinyint\(1\)/', $t)) return ['kind' => 'yesno'];
        if (preg_match('/^(tiny|small|medium|big)?int/', $t)) return ['kind' => 'int'];
        if (preg_match('/^(decimal|numeric|float|double)(\((\d+),(\d+)\))?/', $t, $m)) return ['kind' => 'decimal', 'scale' => isset($m[4]) ? (int)$m[4] : 4];
        if ($t === 'date') return ['kind' => 'date'];
        if (preg_match('/^(datetime|timestamp)/', $t)) return ['kind' => 'datetime'];
        if (preg_match('/(text|blob)$/', $t)) return ['kind' => 'long'];
        if (preg_match('/^(var)?char\((\d+)\)/', $t, $m)) return ['kind' => 'text', 'max' => (int)$m[2]];
        return ['kind' => 'text', 'max' => 255];
    }
    function opsh_vtu_clean_col(array $c, $v): ?string {
        $bad = fn($m) => throw new InvalidArgumentException('"' . ($c['label'] ?? $c['name']) . '": ' . $m);
        if (opsh_vtu_ro($c)) $bad('is read-only in vTiger.');
        if (!is_scalar($v) && $v !== null) $bad('not a value.');
        $v = $v === null ? '' : trim(str_replace(["\r\n", "\r"], "\n", (string)$v)); $k = opsh_vtu_col_kind($c);
        if ($v === '') { if (!$c['null'] && !in_array($k['kind'], ['text', 'long'], true)) $bad('is required.'); return $c['null'] ? null : ''; }
        switch ($k['kind']) {
            case 'list': if (!in_array($v, array_map(fn($o) => (string)$o[0], $k['options']), true)) $bad('choose from the list.'); return $v;
            case 'email': if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $bad('not a valid email address.'); return $v;
            case 'yesno': if (!in_array($v, ['0', '1'], true)) $bad('yes or no.'); return $v;
            case 'int': if (!preg_match('/^-?\d{1,18}$/', $v)) $bad('must be a whole number.'); return $v;
            case 'decimal': if (!is_numeric($v)) $bad('must be a number.'); return $v;
            case 'date': if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) $bad('not a valid date.'); return $v;
            case 'datetime': $ts = strtotime($v); if ($ts === false) $bad('not a valid date and time.'); return date('Y-m-d H:i:s', $ts);
            case 'text': $v = preg_replace('/\s+/u', ' ', $v); if (mb_strlen($v) > $k['max']) $bad('at most ' . $k['max'] . ' characters.'); return $v;
            default: if (mb_strlen($v) > 60000) $bad('too long.'); return $v;
        }
    }
    /** Is a copied column the same as its vTiger field (the cron has run since the last change)? */
    function opsh_vtu_same($a, $b): bool { $n = fn($x) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$x))); return $n($a) === $n($b); }

    /** A strong password: 16 characters, upper and lower case, digits and symbols, no look-alikes. */
    function opsh_vtu_password(): string {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!#%+=?@*'];
        $p = []; foreach ($sets as $s) $p[] = $s[random_int(0, strlen($s) - 1)];
        $all = implode('', $sets); while (count($p) < 16) $p[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($p) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$p[$i], $p[$j]] = [$p[$j], $p[$i]]; }
        return implode('', $p);
    }
    function opsh_vtu_password_problem(string $p): ?string {
        if (mb_strlen($p) < 10) return 'Use at least 10 characters.';
        if (mb_strlen($p) > 64) return 'Use at most 64 characters.';
        $n = (int)preg_match('/[a-z]/', $p) + (int)preg_match('/[A-Z]/', $p) + (int)preg_match('/\d/', $p) + (int)preg_match('/[^A-Za-z0-9]/', $p);
        if ($n < 3) return 'Mix at least three of: lower case, upper case, digits, symbols.';
        if (preg_match('/\s/', $p)) return 'No spaces.';
        return null;
    }
}
