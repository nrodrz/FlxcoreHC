<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT45 — connections to company systems (JustCall first; BT126 HubSpot, Jotform, eFax; BT148 CallTrackingMetrics).
 *
 * A connected system is read, never written: its users are read into opsh_people_links (ext_id = the system's id for
 * the account), so the account panel, "Needs attention", the steps of onboarding / offboarding and "Verify offboarding" use what the
 * system says instead of what a person typed.
 *
 *   settings  opsh_settings.access_conn = {key: {fields: {name: "enc:v1:…"}, saved_at, saved_by, tested_at, tested_ok,
 *             test_note, last_sync_at, last_sync_ok, last_error, last_error_at, counts: {...}, held: [...]}}
 *
 * Rules to keep:
 *   - "Could not read" is never "nothing there". A sync that fails changes nothing; a list that is not complete is
 *     refused by the client; a list that suddenly lacks most of the accounts is HELD until a person accepts it.
 *   - A person is matched to an account by the exact email the system reports. Anything else is assigned by a person
 *     (pinned) and is never changed by a sync.
 *   - Secrets are stored wrapped (Secrets.php), never returned, never written to the Audit Log or the Event Log.
 *   - Adding a connector: an entry in opsh_conn_defs(), a case in opsh_conn_fetch() and opsh_conn_test_call().
 */
require_once __DIR__ . '/Systems.php';
require_once __DIR__ . '/Secrets.php';

if (!defined('OPSH_CONN_FRESH')) define('OPSH_CONN_FRESH', 600);        // a reading this young is used as it is (seconds)
if (!defined('OPSH_CONN_CRON_EVERY')) define('OPSH_CONN_CRON_EVERY', 4 * 3600);

if (!function_exists('opsh_conn_defs')) {

    /** The systems this build can connect to. */
    function opsh_conn_defs(): array {
        return [
            'justcall' => ['name' => 'JustCall', 'fields' => [['api_key', 'API key'], ['api_secret', 'API secret']],
                'where' => 'In JustCall: your profile → “APIs and Webhooks”. The key another app of yours already uses can be used here too.',
                'reads' => 'Users: name, email, role, extension, the numbers they own and share, last sign-in. Also the calls of a period (activity per person) and, when you switch it on, the missed calls and voicemails that become tickets.',
                'cannot' => 'JustCall’s API cannot add or remove a user. Those stay by hand in JustCall, and this app checks afterwards.'],
            // BT151: the office PBX (Grandstream UCM63xx), through api/lib/Ucm.php; it only takes extensions out of service and back
            'pbx' => ['name' => 'Phone system (PBX)', 'fields' => [['address', 'Address of the PBX API (https://…:8089, or its RemoteConnect address)'], ['api_user', 'API user'], ['api_secret', 'API password'],
                ['cdr_user', 'CDR user (only when the main API gives no call records: the older CDR API, port 8443)', true], ['cdr_secret', 'CDR password (with the CDR user)', true], ['cdr_port', 'CDR port (8443 when empty)', true]],
                'where' => 'On the PBX\'s web page: Integrations → API Configuration → HTTPS API Settings (New): Enable, and an API user and password of its own.',
                'reads' => 'Every extension: number, name, email (when the PBX gives one), type and status; the model, firmware and uptime. An extension out of service counts as no account. Every five minutes, the call records (for the reports, and missed calls as tickets when you switch that on).',
                'cannot' => 'It changes only what you ask on its tab or step: create an extension, its name and email, a new voicemail PIN, out of service (forwarding cleared) or back. It deletes an extension only after the waiting period out of service, with its number typed. Trunks, routes and departments are never changed.'],
            // BT148: read only, through api/lib/Ctm.php (the numbers, calls and missed-call tickets: CtmService.php)
            'ctm' => ['name' => 'CallTrackingMetrics', 'fields' => [['api_key', 'Access key'], ['api_secret', 'Secret key'], ['account_id', 'Account ID (empty when the key reaches one account)', true]],
                'where' => 'In CallTrackingMetrics: Settings → Account Settings → API Integration (an account administrator). Copy the access key and the secret key.',
                'reads' => 'Users: name, email, role and groups. Also the tracking numbers, the calls of a period (report), and the missed calls that become tickets when you switch that on.',
                'cannot' => 'This connection only reads. Adding and removing users stays by hand in CallTrackingMetrics, and this app checks afterwards.'],
            // BT126: read only, through api/lib/SaasUsers.php
            'hubspot' => ['name' => 'HubSpot', 'fields' => [['api_key', 'Service key (starts with pat-)']],
                'where' => 'In HubSpot: Development → Keys → Service keys (Settings → Integrations → Service Keys), with the scopes settings.users.read, settings.users.teams.read and settings.users.write (to change and remove users from a person\'s page). A private app token with those scopes works too.',
                'reads' => 'Users: name, email, Super Admin or role, team and seats.',
                'cannot' => 'This connection only reads. Creating and removing HubSpot users stays by hand in HubSpot, and this app checks afterwards.'],
            'jotform' => ['name' => 'Jotform', 'fields' => [['api_key', 'API key (Read Access)'], ['region', 'Server (empty = US; type eu or hipaa for those servers)', true]],
                'where' => 'In Jotform: your account settings → API → Create New Key, with "Read Access". Use the account that owns the company\'s forms.',
                'reads' => 'The account owner and the team members (sub-users) Jotform lists; the forms with who owns each; and, for the forms you switch on, the new submissions (they become tickets). Jotform does not publish which fields a member carries: "Save the key and test" shows them.',
                'cannot' => 'This connection only reads. Adding and removing members stays by hand in Jotform.'],
            'efax' => ['name' => 'eFax (Documo)', 'fields' => [['api_key', 'API key (access level admin)']],
                'where' => 'In Documo: Toolbox → API Keys → Create (admin access level), made under a service user, not a person.',
                'reads' => 'The users and their fax caller numbers, when Documo\'s API lists them for the key. Documo documents only "who owns this key"; the test says whether the user list is there.',
                'cannot' => 'This connection only reads. If Documo does not list users for the key, eFax stays tracked by hand.'],
        ];
    }

    function opsh_conn_all(PDO $db): array {
        try { $s = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $s->execute(['access_conn']); $j = json_decode((string)$s->fetchColumn(), true); }
        catch (Throwable $e) { $j = null; }
        return is_array($j) ? $j : [];
    }

    function opsh_conn_put(PDO $db, string $key, array $state): void {
        $all = opsh_conn_all($db); $all[$key] = $state;
        $db->prepare('INSERT INTO opsh_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['access_conn', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    function opsh_conn_state(PDO $db, string $key): array {
        $all = opsh_conn_all($db);
        return is_array($all[$key] ?? null) ? $all[$key] : [];
    }

    /** The stored credentials in clear, for a call. Never send this anywhere but to the system's own API. */
    function opsh_conn_creds(PDO $db, string $key): array {
        $st = opsh_conn_state($db, $key); $out = [];
        foreach ((opsh_conn_defs()[$key]['fields'] ?? []) as [$f]) {
            $v = (string)($st['fields'][$f] ?? '');
            try { $out[$f] = $v === '' ? '' : opsh_secret_unwrap($v); } catch (Throwable $e) { throw new RuntimeException('The stored key could not be decrypted (the vault key of this installation changed?). Enter the key again.'); }
        }
        return $out;
    }

    /** What a screen may know about a connection: no secret, not even its length. */
    function opsh_conn_public(PDO $db, string $key): ?array {
        $def = opsh_conn_defs()[$key] ?? null; if (!$def) return null;
        $st = opsh_conn_state($db, $key);
        $fields = []; $all = true;
        foreach ($def['fields'] as $fd) { [$f, $label] = $fd; $has = ($st['fields'][$f] ?? '') !== ''; $all = $all && ($has || !empty($fd[2])); $fields[] = ['key' => $f, 'label' => $label, 'saved' => $has, 'optional' => !empty($fd[2])]; }
        return ['key' => $key, 'name' => $def['name'], 'where' => $def['where'], 'reads' => $def['reads'], 'cannot' => $def['cannot'], 'fields' => $fields, 'configured' => $all,
                'saved_at' => $st['saved_at'] ?? null, 'saved_by' => $st['saved_by'] ?? null,
                'tested_at' => $st['tested_at'] ?? null, 'tested_ok' => !empty($st['tested_ok']), 'test_note' => (string)($st['test_note'] ?? ''),
                'last_sync_at' => $st['last_sync_at'] ?? null, 'last_sync_ok' => array_key_exists('last_sync_ok', $st) ? (bool)$st['last_sync_ok'] : null,
                'last_error' => (string)($st['last_error'] ?? ''), 'last_error_at' => $st['last_error_at'] ?? null,
                'counts' => is_array($st['counts'] ?? null) ? $st['counts'] : null, 'held' => is_array($st['held'] ?? null) ? $st['held'] : []];
    }

    /** Are the columns of this build there (db-update.php has run)? Without them nothing can be read into the table. */
    function opsh_conn_ready(PDO $db): bool {
        try { $db->query('SELECT ext_id, ext_email, pinned, detail, seen_at FROM opsh_people_links LIMIT 1'); return true; } catch (Throwable $e) { return false; }
    }

    function opsh_conn_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /** "Oct 8, 2026 10:05 AM EDT" from a stored reading time (BT61: 12-hour; BT131: the installation's zone). */
    function opsh_conn_when(?string $iso): string { $t = $iso ? strtotime($iso) : false; return $t ? date('m/d/Y g:i A T', $t) : 'an unknown time'; }

    /** A message that may be stored or shown: the key and the secret are taken out of it, whoever put them there. */
    function opsh_conn_scrub(string $msg, array $creds): string {
        foreach ($creds as $v) if (is_string($v) && strlen($v) >= 4) $msg = str_replace($v, '(hidden)', $msg);
        return mb_substr($msg, 0, 400);
    }

    /** The person behind the current request, for the Audit Log line of a reading (nobody = the scheduled job). */
    function opsh_conn_actor(): array {
        $id = (int)($_SERVER['OPSH_USER_ID'] ?? 0);
        return $id ? ['id' => $id, 'display_name' => (string)($_SERVER['OPSH_USER_NAME'] ?? 'a person')] : [];
    }

    /** The stand-in "email" of an account that has none in the system: it matches nobody until a person assigns it. */
    function opsh_conn_placeholder(string $key, string $extId): string {
        return 'no-email-' . (preg_replace('/[^a-z0-9]/', '', strtolower($extId)) ?: 'x') . '@' . $key . '.invalid';
    }
    function opsh_conn_is_placeholder(string $email): bool { return (bool)preg_match('/^no-email-[a-z0-9]+@[a-z0-9_]+\.invalid$/', $email); }

    /** One call that proves the stored key works. Returns ['ok' => bool, 'note' => sentence]. Never throws. */
    function opsh_conn_test_call(string $key, array $creds): array {
        try {
            if ($key === 'justcall') {
                require_once __DIR__ . '/JustCall.php';
                $t = (new OpshJustCall($creds['api_key'] ?? '', $creds['api_secret'] ?? ''))->test();
                $miss = array_values(array_diff(['id', 'email', 'name'], $t['fields']));
                if ($t['users_seen'] > 0 && $miss) return ['ok' => false, 'note' => 'JustCall answered, but a user has no ' . implode(', ', $miss) . ' in the answer, so people cannot be matched. Fields seen: ' . implode(', ', $t['fields']) . '.'];
                return ['ok' => true, 'note' => 'JustCall answered' . ($t['total'] !== null ? ': ' . $t['total'] . ' user(s) in the account' : '') . '.' . ($t['users_seen'] ? ' A user carries: ' . implode(', ', $t['fields']) . '.' : ' It listed no user.')];
            }
            if ($key === 'pbx') {   // BT151
                require_once __DIR__ . '/Ucm.php';
                $db = getDB(); $st = opsh_conn_state($db, 'pbx');
                $u = new OpshUcm($creds['address'] ?? '', $creds['api_user'] ?? '', $creds['api_secret'] ?? '', (string)($st['pin'] ?? ''));
                try { $u->login(); $s = $u->status(); $x = $u->extensions(); }
                catch (OpshUcmException $e) { if ($e->loginRefused) { $st = opsh_conn_state($db, 'pbx'); $st['login_refused_at'] = opsh_conn_now(); opsh_conn_put($db, 'pbx', $st); } throw $e; }
                finally { $u->logout(); }
                if ($u->seenPin !== '' && empty($st['pin'])) { $st = opsh_conn_state($db, 'pbx'); $st['pin'] = $u->seenPin; opsh_conn_put($db, 'pbx', $st); }
                $mail = count(array_filter($x, fn($e) => $e['email'] !== ''));
                return ['ok' => true, 'note' => 'The phone system answered' . ($s['model'] !== '' ? ': ' . $s['model'] : '') . ($s['firmware'] !== '' ? ', firmware ' . $s['firmware'] : '') . '. ' . count($x) . ' extension(s)'
                        . ', ' . count(array_filter($x, fn($e) => $e['out_of_service'])) . ' out of service. ' . ($mail ? $mail . ' carry an email.' : 'None carries an email: assign each to its person on the PBX tab.')
                        . ($u->seenPin !== '' ? ' Its certificate is remembered.' : '')];
            }
            if ($key === 'ctm') {   // BT148
                require_once __DIR__ . '/Ctm.php';
                $t = (new OpshCtm($creds['api_key'] ?? '', $creds['api_secret'] ?? '', $creds['account_id'] ?? ''))->test();
                $acc = ($t['account']['name'] !== '' ? $t['account']['name'] . ' ' : '') . '#' . $t['account']['id'];
                if ($t['users_seen'] > 0 && !in_array('email', $t['fields'], true)) return ['ok' => false, 'note' => 'CallTrackingMetrics answered (account ' . $acc . '), but a user has no email in the answer, so people cannot be matched. Fields seen: ' . implode(', ', $t['fields']) . '.'];
                return ['ok' => true, 'note' => 'CallTrackingMetrics answered: account ' . $acc . ($t['total'] !== null ? ', ' . $t['total'] . ' user(s)' : '') . '.' . ($t['users_seen'] ? ' A user carries: ' . implode(', ', $t['fields']) . '.' : ' It listed no user.')];
            }
            if (in_array($key, ['hubspot', 'jotform', 'efax'], true)) {   // BT126
                require_once __DIR__ . '/SaasUsers.php';
                $t = (new OpshSaasUsers($key, $creds))->test(); $n = OpshSaasUsers::name($key);
                if (!$t['lists']) return ['ok' => false, 'note' => $t['note']];
                $miss = array_values(array_diff(['email'], $t['fields']));
                if ($t['users_seen'] > 0 && $miss) return ['ok' => false, 'note' => $n . ' answered, but a user has no email in the answer, so people cannot be matched. Fields seen: ' . implode(', ', $t['fields']) . '.'];
                return ['ok' => true, 'note' => $n . ' answered' . ($t['total'] !== null ? ': ' . $t['total'] . ' user(s)' : '') . '.' . ($t['users_seen'] ? ' A user carries: ' . implode(', ', $t['fields']) . '.' : ' No user in the first page.')];
            }
            return ['ok' => false, 'note' => 'This build has no connection for that system.'];
        } catch (Throwable $e) { return ['ok' => false, 'note' => opsh_conn_scrub($e->getMessage(), $creds)]; }
    }

    /** Every account of the system, in one shape: id, name, email, role, extension, owned_numbers, shared_numbers, groups, last_login. Throws. */
    function opsh_conn_fetch(string $key, array $creds): array {
        if ($key === 'justcall') {
            require_once __DIR__ . '/JustCall.php';
            return (new OpshJustCall($creds['api_key'] ?? '', $creds['api_secret'] ?? ''))->users();
        }
        if (in_array($key, ['hubspot', 'jotform', 'efax'], true)) { require_once __DIR__ . '/SaasUsers.php'; return (new OpshSaasUsers($key, $creds))->users(); }   // BT126
        if ($key === 'pbx') {   // BT151: an extension out of service is not an account any more
            require_once __DIR__ . '/Ucm.php'; $db = getDB(); $st = opsh_conn_state($db, 'pbx');
            if (!empty($st['login_refused_at'])) throw new RuntimeException('the phone system refused the last sign-in, so nothing signs in again by itself (the PBX bans an address after a few failures). Test the connection on its set-up page');
            $u = new OpshUcm($creds['address'] ?? '', $creds['api_user'] ?? '', $creds['api_secret'] ?? '', (string)($st['pin'] ?? ''));
            try { $u->login(); $x = $u->extensions(); }
            catch (OpshUcmException $e) { if ($e->loginRefused) { $st = opsh_conn_state($db, 'pbx'); $st['login_refused_at'] = opsh_conn_now(); opsh_conn_put($db, 'pbx', $st); } throw $e; }
            finally { $u->logout(); }
            try { require_once __DIR__ . '/PbxService.php'; opsh_pbx_note_out($db, $x); } catch (Throwable $e) {}   // BT153: when each was first seen out of service
            $out = [];
            foreach ($x as $e) if (!$e['out_of_service']) $out[] = ['id' => $e['extension'], 'name' => $e['name'], 'email' => $e['email'], 'role' => $e['type'], 'extension' => $e['extension'], 'owned_numbers' => [], 'shared_numbers' => [],
                                                              'groups' => [], 'created_at' => '', 'last_login' => ''];
            return $out;
        }
        if ($key === 'ctm') { require_once __DIR__ . '/Ctm.php'; return (new OpshCtm($creds['api_key'] ?? '', $creds['api_secret'] ?? '', $creds['account_id'] ?? ''))->users(); }   // BT148
        throw new RuntimeException('This build has no connection for that system.');
    }

    /** The label an account gets in the lists: the name, with the first number or the extension. */
    function opsh_conn_label(array $u): string {
        $n = $u['name'] !== '' ? $u['name'] : ($u['email'] !== '' ? $u['email'] : 'user ' . $u['id']);
        $x = $u['owned_numbers'][0] ?? ($u['extension'] !== '' ? 'ext ' . $u['extension'] : '');
        return mb_substr($n . ($x !== '' ? ' (' . $x . ')' : ''), 0, 180);
    }

    /** The part of an account kept next to the link (shown on the pages; nothing secret). */
    function opsh_conn_detail(array $u): string {
        return (string)json_encode(['name' => $u['name'], 'role' => $u['role'], 'extension' => $u['extension'], 'owned_numbers' => $u['owned_numbers'], 'shared_numbers' => $u['shared_numbers'],
                                    'groups' => $u['groups'], 'last_login' => $u['last_login'], 'created_at' => $u['created_at']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Read the system and bring opsh_people_links in step with it.
     *   $by    = who asked (a user row), or [] for the scheduled job
     *   $force = accept a list that lacks most of the accounts known so far (a person looked and confirmed)
     * Returns ['ok', 'error', 'users', 'added', 'reopened', 'closed', 'replaced', 'moved', 'held' => [labels], 'at'].
     * On any failure nothing is changed.
     */
    function opsh_conn_sync(PDO $db, string $key, array $by = [], bool $force = false): array {
        $sys = opsh_sys_get($db, $key);
        $res = ['ok' => false, 'error' => null, 'users' => 0, 'added' => 0, 'reopened' => 0, 'closed' => 0, 'replaced' => 0, 'moved' => 0, 'held' => [], 'at' => opsh_conn_now()];
        if (!$sys || !$sys['connector']) { $res['error'] = 'This build has no connection for that system.'; return $res; }
        if (!opsh_conn_ready($db)) { $res['error'] = 'Run db-update.php once: this update added what a connected system needs.'; return $res; }
        $uid = (int)($by['id'] ?? 0) ?: null;
        $who = $uid ? 'synchronized by ' . (string)($by['display_name'] ?? 'a person') : 'scheduled synchronization';
        $lock = 'opsh_conn_' . $key;
        try { $got = (int)$db->query('SELECT GET_LOCK(' . $db->quote($lock) . ', 0)')->fetchColumn(); } catch (Throwable $e) { $got = 1; }
        if (!$got) { $res['error'] = 'A synchronization of ' . $sys['name'] . ' is already running. Try again in a moment.'; $res['busy'] = true; return $res; }
        try {
            $st = opsh_conn_state($db, $key);
            $creds = [];
            try { $creds = opsh_conn_creds($db, $key); if (!$creds || ($creds['api_key'] ?? null) === '' || (array_key_exists('api_secret', $creds) && $creds['api_secret'] === '')) throw new RuntimeException('No key is saved for ' . $sys['name'] . '.'); $users = opsh_conn_fetch($key, $creds); }
            catch (Throwable $e) {
                $msg = opsh_conn_scrub($e->getMessage(), $creds);
                $st['last_sync_ok'] = false; $st['last_error'] = $msg; $st['last_error_at'] = opsh_conn_now(); opsh_conn_put($db, $key, $st);
                if (function_exists('opsh_log')) { try { opsh_log('warning', 'access', $sys['name'] . ' could not be read: ' . $msg); } catch (Throwable $x) {} }
                $res['error'] = $msg; return $res;
            }
            $res['users'] = count($users);
            $q = $db->prepare('SELECT * FROM opsh_people_links WHERE system_key = ?'); $q->execute([$key]);
            $byExt = []; $manual = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['ext_id'] !== null && $r['ext_id'] !== '') $byExt[(string)$r['ext_id']] = $r; elseif ($r['status'] === 'active') $manual[] = $r; }
            $ids = []; foreach ($users as $u) $ids[$u['id']] = 1;
            $gone = array_filter($byExt, fn($r) => $r['status'] === 'active' && !isset($ids[(string)$r['ext_id']]));
            $activeBefore = count(array_filter($byExt, fn($r) => $r['status'] === 'active'));
            // The list lacks most of what was there (or has nobody at all): more likely a fault than a mass departure. Hold it.
            $hold = !$force && $gone && ((count($users) === 0) || ($activeBefore >= 4 && count($gone) * 2 > $activeBefore));

            $db->beginTransaction();
            $taken = [];   // email|label already used in this run (the table allows one row per system + person + label)
            $find = $db->prepare('SELECT id FROM opsh_people_links WHERE system_key=? AND email=? AND account=? AND id<>?');
            foreach ($users as $u) {
                $old = $byExt[$u['id']] ?? null;
                $extEmail = opsh_sys_email($u['email']);
                $person = $old && !empty($old['pinned']) ? (string)$old['email'] : ($extEmail !== '' ? $extEmail : opsh_conn_placeholder($key, $u['id']));
                $label = opsh_conn_label($u);
                $find->execute([$key, $person, $label, $old ? (int)$old['id'] : 0]);
                // The table allows one row per system + person + label. Two accounts that would share one get the id in the label.
                $withId = mb_substr($label, 0, max(20, 186 - mb_strlen($u['id']))) . ' #' . $u['id'];
                if ($find->fetchColumn() || isset($taken[$person . '|' . mb_strtolower($label)])) $label = $withId;
                $taken[$person . '|' . mb_strtolower($label)] = 1;
                $detail = opsh_conn_detail($u);
                // …and when the database still finds them equal (it compares without case and accents), the same way.
                $write = function (string $sql, callable $args) use ($db, &$label, $withId) {
                    try { $db->prepare($sql)->execute($args($label)); }
                    catch (PDOException $e) { if ((string)$e->getCode() !== '23000' || $label === $withId) throw $e; $label = $withId; $db->prepare($sql)->execute($args($label)); }
                };
                if ($old) {
                    $was = opsh_sys_link_row($old + ['created_by_name' => '', 'removed_by_name' => '']);
                    $write("UPDATE opsh_people_links SET email=?, ext_email=?, account=?, person_name=?, detail=?, seen_at=NOW(), status='active', removed_at=NULL, removed_by=NULL, removed_run=NULL, updated_at=NOW() WHERE id=?",
                        fn($lb) => [$person, $extEmail !== '' ? $extEmail : null, $lb, $u['name'] !== '' ? $u['name'] : null, $detail, (int)$old['id']]);
                    if ($old['status'] !== 'active') { $res['reopened']++; opsh_sys_audit('access_link_restore', (int)$old['id'], $person . ' - ' . $sys['name'], ['Account' => $was['account'], 'Status' => 'removed'], ['Account' => $label, 'Status' => 'active', 'Why' => $sys['name'] . ' lists this user again (' . $who . ')']); }
                    elseif ((string)$old['email'] !== $person) { $res['moved']++; opsh_sys_audit('access_link_move', (int)$old['id'], (string)$old['email'] . ' - ' . $sys['name'], ['Person' => (string)$old['email'], 'Account' => $was['account']], ['Person' => $person, 'Account' => $label, 'Why' => 'the email of this user changed in ' . $sys['name'] . ' (' . $who . ')']); }
                } else {
                    $write("INSERT INTO opsh_people_links (system_key, email, person_name, account, note, status, source, created_by, created_at, updated_by, updated_at, ext_id, ext_email, pinned, detail, seen_at) VALUES (?,?,?,?,NULL,'active','api',?,NOW(),?,NOW(),?,?,0,?,NOW())",
                        fn($lb) => [$key, $person, $u['name'] !== '' ? $u['name'] : null, $lb, $uid, $uid, $u['id'], $extEmail !== '' ? $extEmail : null, $detail]);
                    $res['added']++;
                    opsh_sys_audit('access_link_add', (int)$db->lastInsertId(), $person . ' - ' . $sys['name'], [], ['System' => $sys['name'], 'Person' => $person, 'Account' => $label, 'Status' => 'active', 'Recorded through' => 'read from ' . $sys['name'] . ' (' . $who . ')']);
                }
            }
            // What was typed by hand before the connection: the system is the record now.
            foreach ($manual as $r) {
                $db->prepare("UPDATE opsh_people_links SET status='removed', removed_at=NOW(), removed_by=?, updated_at=NOW() WHERE id=? AND status='active'")->execute([$uid, (int)$r['id']]);
                $res['replaced']++;
                opsh_sys_audit('access_link_remove', (int)$r['id'], (string)$r['email'] . ' - ' . $sys['name'], ['Account' => (string)$r['account'] !== '' ? (string)$r['account'] : '(name not recorded)', 'Status' => 'active'],
                    ['Status' => 'removed', 'Why' => 'typed by hand before ' . $sys['name'] . ' was connected: replaced by what ' . $sys['name'] . ' reports (' . $who . ')']);
            }
            if (!$hold) {
                foreach ($gone as $r) {
                    $db->prepare("UPDATE opsh_people_links SET status='removed', removed_at=NOW(), removed_by=?, updated_at=NOW() WHERE id=? AND status='active'")->execute([$uid, (int)$r['id']]);
                    $res['closed']++;
                    opsh_sys_audit('access_link_remove', (int)$r['id'], (string)$r['email'] . ' - ' . $sys['name'], ['Account' => (string)$r['account'], 'Status' => 'active'],
                        ['Status' => 'removed', 'Why' => $sys['name'] . ' no longer lists this user (' . $who . ')']);
                }
            } else $res['held'] = array_values(array_map(fn($r) => (string)$r['account'], $gone));
            $db->commit();

            $st = opsh_conn_state($db, $key);
            $st['last_sync_at'] = $res['at']; $st['last_sync_ok'] = true; $st['last_error'] = ''; $st['last_error_at'] = null; $st['held'] = $res['held'];
            $st['counts'] = ['users' => count($users), 'at' => $res['at']];
            opsh_conn_put($db, $key, $st);
            if ($res['added'] || $res['reopened'] || $res['closed'] || $res['replaced'] || $res['moved'] || $res['held'])
                opsh_sys_audit('access_sync', 0, $sys['name'] . ' - ' . ($uid ? 'synchronized' : 'scheduled synchronization'), ['Users in ' . $sys['name'] => (string)count($users)],
                    ['New' => (string)$res['added'], 'Listed again' => (string)$res['reopened'], 'No longer listed' => (string)$res['closed'], 'Typed by hand, replaced' => (string)$res['replaced'],
                     'Email changed' => (string)$res['moved']] + ($res['held'] ? ['Held back' => count($res['held']) . ' account(s) missing from the list were NOT closed: ' . mb_substr(implode(', ', $res['held']), 0, 300)] : []));
            $res['ok'] = true;
            return $res;
        } catch (Throwable $e) {
            try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable $x) {}
            $res['error'] = 'The synchronization stopped and nothing was changed: ' . $e->getMessage();
            if (function_exists('opsh_log')) { try { opsh_log('error', 'access', $sys['name'] . ' synchronization failed: ' . $e->getMessage()); } catch (Throwable $x) {} }
            return $res;
        } finally {
            try { $db->query('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')'); } catch (Throwable $e) {}
        }
    }

    /**
     * Make sure what is in the table is a recent reading of the system. Returns [ok, error, at]. A reading younger than
     * $maxAge seconds is used as it is; 0 = always read now. When accounts are held back the reading is NOT good enough to say "gone".
     */
    function opsh_conn_fresh(PDO $db, string $key, array $by = [], int $maxAge = OPSH_CONN_FRESH): array {
        $st = opsh_conn_state($db, $key);
        $at = isset($st['last_sync_at']) ? strtotime((string)$st['last_sync_at']) : false;
        if ($maxAge > 0 && !empty($st['last_sync_ok']) && $at && (time() - $at) <= $maxAge && empty($st['held'])) return ['ok' => true, 'error' => null, 'at' => (string)$st['last_sync_at']];
        $r = opsh_conn_sync($db, $key, $by);
        if ($r['ok'] && $r['held']) return ['ok' => false, 'error' => 'the list from this system lacks ' . count($r['held']) . ' account(s) it had before; a person has to look at it on the system’s tab first', 'at' => $r['at']];
        return ['ok' => $r['ok'], 'error' => $r['error'], 'at' => $r['at']];
    }

    /** The scheduled job: read each connected system when its last reading is older than OPSH_CONN_CRON_EVERY. */
    function opsh_conn_cron(PDO $db): array {
        $out = [];
        foreach (opsh_sys_all($db) as $s) {
            if ($s['mode'] !== 'connected') continue;
            $st = opsh_conn_state($db, $s['key']);
            $at = isset($st['last_sync_at']) ? strtotime((string)$st['last_sync_at']) : false;
            $lastTry = isset($st['last_error_at']) ? strtotime((string)$st['last_error_at']) : false;
            if (($at && (time() - $at) < OPSH_CONN_CRON_EVERY) || ($lastTry && (time() - $lastTry) < 1800)) continue;   // a failed reading is tried again after half an hour, not every run
            $out[$s['key']] = opsh_conn_sync($db, $s['key']);
        }
        return $out;
    }

    /**
     * Accounts of a connected system that belong to nobody OpsHub knows: no Microsoft 365 account has their email.
     * Returns labels (at most 20), or null when the Microsoft 365 directory could not be read. An offboarding that
     * finds "no user" must say so when such accounts exist: one of them may be the person, under another email.
     */
    function opsh_conn_unmatched(PDO $db, string $key): ?array {
        $d = opsh_sys_directory($db);
        if ($d['users'] === null) return null;
        $known = [];
        foreach ($d['users'] as $u) foreach ([opsh_sys_email($u['upn'] ?? ''), opsh_sys_email($u['mail'] ?? '')] as $e) if ($e !== '') $known[$e] = 1;
        try { $q = $db->prepare("SELECT email, account FROM opsh_people_links WHERE system_key = ? AND status = 'active' AND ext_id IS NOT NULL ORDER BY account"); $q->execute([$key]); }
        catch (Throwable $e) { return null; }
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($known[(string)$r['email']])) $out[] = (string)$r['account'];
        return array_slice($out, 0, 20);
    }

    /**
     * The accounts read from a connected system, each with the person it is matched to and what Microsoft 365 says
     * about that person. $dir = directory rows, or null when Microsoft 365 could not be read.
     */
    function opsh_conn_accounts(PDO $db, string $key, ?array $dir): array {
        $by = [];
        foreach ($dir ?? [] as $u) {
            $row = ['name' => (string)($u['display_name'] ?? ''), 'enabled' => !empty($u['enabled']), 'upn' => opsh_sys_email($u['upn'] ?? '')];
            foreach ([opsh_sys_email($u['upn'] ?? ''), opsh_sys_email($u['mail'] ?? '')] as $e) if ($e !== '' && !isset($by[$e])) $by[$e] = $row;
        }
        $q = $db->prepare("SELECT l.*, cu.display_name AS created_by_name, ru.display_name AS removed_by_name FROM opsh_people_links l
                           LEFT JOIN opsh_users cu ON cu.id = l.created_by LEFT JOIN opsh_users ru ON ru.id = l.removed_by
                           WHERE l.system_key = ? AND l.status = 'active' AND l.ext_id IS NOT NULL ORDER BY l.account, l.id");
        $q->execute([$key]);
        $rows = []; $sum = ['users' => 0, 'numbers' => 0, 'attention' => 0, 'left' => 0, 'unmatched' => 0, 'no_email' => 0, 'idle' => 0, 'pinned' => 0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $l = opsh_sys_link_row($r); $d = is_array($l['detail']) ? $l['detail'] : [];
            $ph = opsh_conn_is_placeholder($l['email']);
            $m = $ph ? null : ($by[$l['email']] ?? null);
            $m365 = $dir === null ? 'unknown' : ($m ? ($m['enabled'] ? 'enabled' : 'disabled') : 'none');
            $flag = $ph ? 'no_email' : ($m365 === 'none' ? 'unmatched' : ($m365 === 'disabled' ? 'left' : ''));
            // BT150: a seat nobody uses — no sign-in for 90 days (only when the system says when the last sign-in was)
            $ll = (string)($d['last_login'] ?? ''); $llT = $ll === '' ? false : (ctype_digit($ll) ? (int)(strlen($ll) > 11 ? (int)substr($ll, 0, 10) : $ll) : strtotime($ll));
            if ($flag === '' && $llT && $llT > 946684800 && $llT < time() - 90 * 86400) $flag = 'idle';
            $owned = array_values(array_filter((array)($d['owned_numbers'] ?? []), 'is_string')); $shared = array_values(array_filter((array)($d['shared_numbers'] ?? []), 'is_string'));
            $rows[] = ['id' => $l['id'], 'ext_id' => $l['ext_id'], 'name' => (string)($d['name'] ?? $l['person_name']), 'email' => $l['ext_email'], 'account' => $l['account'],
                       'person' => $ph ? '' : $l['email'], 'person_name' => $m ? $m['name'] : '', 'pinned' => $l['pinned'], 'm365' => $m365, 'flag' => $flag,
                       'role' => (string)($d['role'] ?? ''), 'extension' => (string)($d['extension'] ?? ''), 'owned_numbers' => $owned, 'shared_numbers' => $shared,
                       'groups' => array_values(array_filter((array)($d['groups'] ?? []), 'is_string')), 'last_login' => (string)($d['last_login'] ?? ''), 'seen_at' => $l['seen_at'], 'note' => $l['note']];
            $sum['users']++; $sum['numbers'] += count($owned);
            if ($flag !== '') { $sum['attention']++; $sum[$flag]++; }
            if ($l['pinned']) $sum['pinned']++;
        }
        usort($rows, fn($a, $b) => [$a['flag'] === '' ? 1 : 0, mb_strtolower($a['name'])] <=> [$b['flag'] === '' ? 1 : 0, mb_strtolower($b['name'])]);
        return ['rows' => $rows, 'summary' => $sum];
    }

    /**
     * A step of an onboarding or offboarding for a CONNECTED system. The person does the work in the system; the step
     * is done when the system itself shows it. Nobody's word replaces a reading.
     *   grant:  granted (= "check the system") | not_needed        remove: removed | none (= "check the system")
     */
    function opsh_conn_step_run(PDO $db, array $sys, array $run, string $op, string $outcome, string $note, array $user): array {
        $key = $sys['key']; $name = $sys['name']; $emails = opsh_sys_run_emails($run);
        $tail = $note !== '' ? ' · ' . $note : '';
        $labels = fn(array $ls) => implode(', ', array_map(fn($l) => $l['account'], $ls));
        if ($op === 'grant') {
            if (!in_array($outcome, ['granted', 'not_needed'], true)) opsh_ob_fail('Say what applies in ' . $name . ': check it for the account, or it is not needed for this person.', 400);
            if ($outcome === 'not_needed') return ['result' => $name . ': not needed for this person' . $tail, 'detail' => ['system' => $name, 'outcome' => 'not needed']];
        } else {
            if ($outcome === 'moved') opsh_ob_fail($name . ' is read directly, so a hand-over is not recorded here. Give the numbers to the colleague in ' . $name . ' and remove the user; then check again.', 400);
            if (!in_array($outcome, ['removed', 'none', 'remove_now'], true) || ($outcome === 'remove_now' && !in_array($key, ['hubspot', 'pbx'], true))) opsh_ob_fail('Remove the user in ' . $name . ', then use "Check ' . $name . ' again" on the step.', 400);
        }
        if (!$emails) opsh_ob_fail('This record has no work email for the person, so ' . $name . ' cannot be asked about them. Look in ' . $name . ' yourself and skip the step with a note.', 409);
        // BT133: HubSpot users are removed from the step itself (the user ids read from HubSpot; a Super Admin never)
        $removedNow = [];
        // BT151: the PBX extension is taken out of service from the step itself (call forwarding cleared; nothing deleted)
        if ($op !== 'grant' && $outcome === 'remove_now' && $key === 'pbx') {
            require_once __DIR__ . '/PbxService.php';
            $mine = array_values(array_filter(opsh_sys_links($db, $emails, 'active', 'pbx'), fn($l) => $l['ext_id'] !== null && preg_match('/^[0-9]{2,18}$/', (string)$l['ext_id'])));
            if (!$mine) opsh_ob_fail('No extension is recorded for this person, so there is nothing to take out of service. Use "Check the phone system again".', 409);
            foreach ($mine as $l) {
                try { $chg = opsh_pbx_service($db, (string)$l['ext_id'], true, 'offboarding record #' . (int)$run['id']); }
                catch (Throwable $e) { opsh_ob_fail('The phone system did not take extension ' . $l['ext_id'] . ' out of service: ' . $e->getMessage(), 502); }
                $removedNow[] = 'ext ' . $l['ext_id'] . ' out of service' . (count($chg) > 1 ? ', forwarding cleared' : '');
            }
        }
        if ($op !== 'grant' && $outcome === 'remove_now' && $key === 'hubspot') {
            require_once __DIR__ . '/HubspotPeople.php';
            $mine = array_values(array_filter(opsh_sys_links($db, $emails, 'active', $key), fn($l) => $l['ext_id'] !== null && preg_match('/^[0-9]{1,20}$/', (string)$l['ext_id'])));
            if (!$mine) opsh_ob_fail('No HubSpot user is recorded for this person, so there is nothing to remove. Use "Check HubSpot again".', 409);
            try { $c = opsh_hsp_client($db); } catch (Throwable $e) { opsh_ob_fail($e->getMessage(), 409); }
            foreach ($mine as $l) {
                try { $raw = opsh_hsp_get($c, (string)$l['ext_id']); } catch (Throwable $e) { opsh_ob_fail('HubSpot could not be asked about ' . $l['account'] . ': ' . $e->getMessage(), 502); }
                if (!$raw) continue;   // already gone
                $u = opsh_hsp_shape($raw);
                if (!in_array($u['email'], $emails, true)) opsh_ob_fail('The HubSpot user ' . $l['account'] . ' now has another email (' . $u['email'] . '). Look at it in HubSpot. Nothing was removed.', 409);
                if ($u['super_admin']) { audit_log('hubspot_user_refused', 'access', null, $u['email'] . ' - HubSpot', null, ['Why' => 'offboarding step: a Super Admin is not removed from here', 'Offboarding record' => '#' . (int)$run['id']]);
                    opsh_ob_fail($u['email'] . ' is a Super Admin in HubSpot. Another Super Admin removes them in HubSpot; then use "Check HubSpot again".', 409); }
                [$st, $d] = $c->call('DELETE', '/settings/v3/users/' . rawurlencode($u['id']), ['idProperty' => 'USER_ID']);
                if ($st !== 204 && ($st < 200 || $st >= 300) && $st !== 404) { $why = opsh_hsp_err($c, $st, $d, 'remove the user');
                    audit_log('hubspot_user_refused', 'access', null, $u['email'] . ' - HubSpot', null, ['Why' => $why, 'Offboarding record' => '#' . (int)$run['id']]); opsh_ob_fail($why, 502); }
                audit_log('hubspot_user_remove', 'access', null, $u['email'] . ' - HubSpot', opsh_hsp_audit_view($u) + ['HubSpot user' => 'exists'], ['HubSpot user' => 'removed', 'Offboarding record' => '#' . (int)$run['id']]);
                $removedNow[] = $l['account'];
            }
        }
        // An answer is a person saying "I did it in the system just now": always a new reading, never one from before.
        $f = opsh_conn_fresh($db, $key, $user, 0);
        if (!$f['ok']) opsh_ob_fail($name . ' could not be read just now, so the step stays open: ' . ($f['error'] ?: 'no answer') . '.', 409);
        $when = opsh_conn_when($f['at']);
        $links = opsh_sys_links($db, $emails, 'active', $key);
        if ($op === 'grant') {
            if (!$links) opsh_ob_fail($name . ' lists no user with the email ' . $emails[0] . ' (read ' . $when . '). Add the person in ' . $name . ' and check again. If their user there has another email, assign it to them on the ' . $name . ' tab of Company systems.', 409);
            return ['result' => $name . ': the user is there (' . $labels($links) . '), read from ' . $name . ' ' . $when . $tail,
                    'detail' => ['system' => $name, 'outcome' => 'set up, read from ' . $name, 'account' => $labels($links), 'read_at' => $f['at']]];
        }
        if ($links) opsh_ob_fail($name . ' still lists ' . $labels($links) . ' for this person (read ' . $when . '). Reassign the numbers and remove the user in ' . $name . ', then check again.', 409);
        $closed = array_values(array_filter(opsh_sys_links($db, $emails, 'removed', $key), fn($l) => $l['ext_id'] !== null));
        $un = opsh_conn_unmatched($db, $key);
        $caveat = $un ? '; ' . count($un) . ' user(s) in ' . $name . ' are matched to nobody and were not counted' : '';
        return ['result' => $name . ': ' . ($removedNow ? 'removed from here (' . implode(', ', $removedNow) . '); ' : '') . 'no user for this person, read from ' . $name . ' ' . $when . ($closed ? ' (was: ' . $labels(array_slice($closed, 0, 3)) . ')' : '') . $caveat . $tail,
                'detail' => ['system' => $name, 'outcome' => 'no user, read from ' . $name, 'accounts' => $labels($closed), 'read_at' => $f['at'], 'unmatched' => $un === null ? 'unknown' : count($un)]];
    }

    /** The line of "Verify offboarding" for a connected system: the system is asked, and "could not ask" is a failure. */
    function opsh_conn_verify_check(PDO $db, array $sys, array $emails, ?array $step): array {
        $name = $sys['name']; $label = 'Account removed (read from ' . $name . ')';
        if (!$emails) return ['system' => $name, 'label' => $label, 'status' => 'skip', 'detail' => 'No work email on this process, so ' . $name . ' cannot be asked'];
        $f = opsh_conn_fresh($db, $sys['key'], opsh_conn_actor());
        if (!$f['ok']) return ['system' => $name, 'label' => $label, 'status' => 'fail', 'detail' => 'Could not read ' . $name . ': ' . ($f['error'] ?: 'no answer')];
        $open = opsh_sys_links($db, $emails, 'active', $sys['key']);
        $when = opsh_conn_when($f['at']);
        if ($open) return ['system' => $name, 'label' => $label, 'status' => 'fail', 'detail' => $name . ' still lists ' . implode(', ', array_map(fn($l) => $l['account'], $open)) . ' (read ' . $when . ')'];
        $un = opsh_conn_unmatched($db, $sys['key']);
        return ['system' => $name, 'label' => $label, 'status' => 'pass',
                'detail' => 'No user with this person’s email (read ' . $when . ')' . ($un ? '. ' . count($un) . ' user(s) there are matched to nobody: look at the ' . $name . ' tab' : '')
                          . ($step && in_array($step['status'], ['pending', 'failed'], true) ? '. The step of this offboarding is still open' : '')];
    }
}
