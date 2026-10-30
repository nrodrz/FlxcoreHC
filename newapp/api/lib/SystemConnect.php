<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT45 — connections to company systems (JustCall first; BT126 HubSpot, Jotform, eFax; BT148 CallTrackingMetrics).
 *
 * A connected system is read, never written: its users are read into itops_people_links (ext_id = the system's id for
 * the account), so the account panel, "Needs attention", the steps of onboarding / offboarding and "Verify offboarding" use what the
 * system says instead of what a person typed.
 *
 *   settings  itops_settings.access_conn = {key: {fields: {name: "enc:v1:…"}, saved_at, saved_by, tested_at, tested_ok,
 *             test_note, last_sync_at, last_sync_ok, last_error, last_error_at, counts: {...}, held: [...]}}
 *
 * Rules to keep:
 *   - "Could not read" is never "nothing there". A sync that fails changes nothing; a list that is not complete is
 *     refused by the client; a list that suddenly lacks most of the accounts is HELD until a person accepts it.
 *   - A person is matched to an account by the exact email the system reports. Anything else is assigned by a person
 *     (pinned) and is never changed by a sync.
 *   - Secrets are stored wrapped (Secrets.php), never returned, never written to the Audit Log or the Event Log.
 *   - Adding a connector: an entry in itops_conn_defs(), a case in itops_conn_fetch() and itops_conn_test_call().
 */
require_once __DIR__ . '/Systems.php';
require_once __DIR__ . '/Secrets.php';

if (!defined('ITOPS_CONN_FRESH')) define('ITOPS_CONN_FRESH', 600);        // a reading this young is used as it is (seconds)
if (!defined('ITOPS_CONN_CRON_EVERY')) define('ITOPS_CONN_CRON_EVERY', 4 * 3600);

if (!function_exists('itops_conn_defs')) {

    /** The systems this build can connect to. */
    function itops_conn_defs(): array {
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

    function itops_conn_all(PDO $db): array {
        try { $s = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $s->execute(['access_conn']); $j = json_decode((string)$s->fetchColumn(), true); }
        catch (Throwable $e) { $j = null; }
        return is_array($j) ? $j : [];
    }

    function itops_conn_put(PDO $db, string $key, array $state): void {
        $all = itops_conn_all($db); $all[$key] = $state;
        $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['access_conn', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    function itops_conn_state(PDO $db, string $key): array {
        $all = itops_conn_all($db);
        return is_array($all[$key] ?? null) ? $all[$key] : [];
    }

    /** The stored credentials in clear, for a call. Never send this anywhere but to the system's own API. */
    function itops_conn_creds(PDO $db, string $key): array {
        $st = itops_conn_state($db, $key); $out = [];
        foreach ((itops_conn_defs()[$key]['fields'] ?? []) as [$f]) {
            $v = (string)($st['fields'][$f] ?? '');
            try { $out[$f] = $v === '' ? '' : itops_secret_unwrap($v); } catch (Throwable $e) { throw new RuntimeException('The stored key could not be decrypted (the vault key of this installation changed?). Enter the key again.'); }
        }
        return $out;
    }


    /** Are the columns of this build there (db-update.php has run)? Without them nothing can be read into the table. */
    function itops_conn_ready(PDO $db): bool {
        try { $db->query('SELECT ext_id, ext_email, pinned, detail, seen_at FROM itops_people_links LIMIT 1'); return true; } catch (Throwable $e) { return false; }
    }

    function itops_conn_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }


    /** A message that may be stored or shown: the key and the secret are taken out of it, whoever put them there. */
    function itops_conn_scrub(string $msg, array $creds): string {
        foreach ($creds as $v) if (is_string($v) && strlen($v) >= 4) $msg = str_replace($v, '(hidden)', $msg);
        return mb_substr($msg, 0, 400);
    }


    /** The stand-in "email" of an account that has none in the system: it matches nobody until a person assigns it. */
    function itops_conn_placeholder(string $key, string $extId): string {
        return 'no-email-' . (preg_replace('/[^a-z0-9]/', '', strtolower($extId)) ?: 'x') . '@' . $key . '.invalid';
    }


    /** Every account of the system, in one shape: id, name, email, role, extension, owned_numbers, shared_numbers, groups, last_login. Throws. */
    function itops_conn_fetch(string $key, array $creds): array {
        if ($key === 'justcall') {
            require_once __DIR__ . '/JustCall.php';
            return (new ItopsJustCall($creds['api_key'] ?? '', $creds['api_secret'] ?? ''))->users();
        }
        if (in_array($key, ['hubspot', 'jotform', 'efax'], true)) { require_once __DIR__ . '/SaasUsers.php'; return (new ItopsSaasUsers($key, $creds))->users(); }   // BT126
        if ($key === 'pbx') {   // BT151: an extension out of service is not an account any more
            require_once __DIR__ . '/Ucm.php'; $db = getDB(); $st = itops_conn_state($db, 'pbx');
            if (!empty($st['login_refused_at'])) throw new RuntimeException('the phone system refused the last sign-in, so nothing signs in again by itself (the PBX bans an address after a few failures). Test the connection on its set-up page');
            $u = new ItopsUcm($creds['address'] ?? '', $creds['api_user'] ?? '', $creds['api_secret'] ?? '', (string)($st['pin'] ?? ''));
            try { $u->login(); $x = $u->extensions(); }
            catch (ItopsUcmException $e) { if ($e->loginRefused) { $st = itops_conn_state($db, 'pbx'); $st['login_refused_at'] = itops_conn_now(); itops_conn_put($db, 'pbx', $st); } throw $e; }
            finally { $u->logout(); }
            try { require_once __DIR__ . '/PbxService.php'; itops_pbx_note_out($db, $x); } catch (Throwable $e) {}   // BT153: when each was first seen out of service
            $out = [];
            foreach ($x as $e) if (!$e['out_of_service']) $out[] = ['id' => $e['extension'], 'name' => $e['name'], 'email' => $e['email'], 'role' => $e['type'], 'extension' => $e['extension'], 'owned_numbers' => [], 'shared_numbers' => [],
                                                              'groups' => [], 'created_at' => '', 'last_login' => ''];
            return $out;
        }
        if ($key === 'ctm') { require_once __DIR__ . '/Ctm.php'; return (new ItopsCtm($creds['api_key'] ?? '', $creds['api_secret'] ?? '', $creds['account_id'] ?? ''))->users(); }   // BT148
        throw new RuntimeException('This build has no connection for that system.');
    }

    /** The label an account gets in the lists: the name, with the first number or the extension. */
    function itops_conn_label(array $u): string {
        $n = $u['name'] !== '' ? $u['name'] : ($u['email'] !== '' ? $u['email'] : 'user ' . $u['id']);
        $x = $u['owned_numbers'][0] ?? ($u['extension'] !== '' ? 'ext ' . $u['extension'] : '');
        return mb_substr($n . ($x !== '' ? ' (' . $x . ')' : ''), 0, 180);
    }

    /** The part of an account kept next to the link (shown on the pages; nothing secret). */
    function itops_conn_detail(array $u): string {
        return (string)json_encode(['name' => $u['name'], 'role' => $u['role'], 'extension' => $u['extension'], 'owned_numbers' => $u['owned_numbers'], 'shared_numbers' => $u['shared_numbers'],
                                    'groups' => $u['groups'], 'last_login' => $u['last_login'], 'created_at' => $u['created_at']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Read the system and bring itops_people_links in step with it.
     *   $by    = who asked (a user row), or [] for the scheduled job
     *   $force = accept a list that lacks most of the accounts known so far (a person looked and confirmed)
     * Returns ['ok', 'error', 'users', 'added', 'reopened', 'closed', 'replaced', 'moved', 'held' => [labels], 'at'].
     * On any failure nothing is changed.
     */
    function itops_conn_sync(PDO $db, string $key, array $by = [], bool $force = false): array {
        $sys = itops_sys_get($db, $key);
        $res = ['ok' => false, 'error' => null, 'users' => 0, 'added' => 0, 'reopened' => 0, 'closed' => 0, 'replaced' => 0, 'moved' => 0, 'held' => [], 'at' => itops_conn_now()];
        if (!$sys || !$sys['connector']) { $res['error'] = 'This build has no connection for that system.'; return $res; }
        if (!itops_conn_ready($db)) { $res['error'] = 'Run db-update.php once: this update added what a connected system needs.'; return $res; }
        $uid = (int)($by['id'] ?? 0) ?: null;
        $who = $uid ? 'synchronized by ' . (string)($by['display_name'] ?? 'a person') : 'scheduled synchronization';
        $lock = 'itops_conn_' . $key;
        try { $got = (int)$db->query('SELECT GET_LOCK(' . $db->quote($lock) . ', 0)')->fetchColumn(); } catch (Throwable $e) { $got = 1; }
        if (!$got) { $res['error'] = 'A synchronization of ' . $sys['name'] . ' is already running. Try again in a moment.'; $res['busy'] = true; return $res; }
        try {
            $st = itops_conn_state($db, $key);
            $creds = [];
            try { $creds = itops_conn_creds($db, $key); if (!$creds || ($creds['api_key'] ?? null) === '' || (array_key_exists('api_secret', $creds) && $creds['api_secret'] === '')) throw new RuntimeException('No key is saved for ' . $sys['name'] . '.'); $users = itops_conn_fetch($key, $creds); }
            catch (Throwable $e) {
                $msg = itops_conn_scrub($e->getMessage(), $creds);
                $st['last_sync_ok'] = false; $st['last_error'] = $msg; $st['last_error_at'] = itops_conn_now(); itops_conn_put($db, $key, $st);
                if (function_exists('itops_log')) { try { itops_log('warning', 'access', $sys['name'] . ' could not be read: ' . $msg); } catch (Throwable $x) {} }
                $res['error'] = $msg; return $res;
            }
            $res['users'] = count($users);
            $q = $db->prepare('SELECT * FROM itops_people_links WHERE system_key = ?'); $q->execute([$key]);
            $byExt = []; $manual = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['ext_id'] !== null && $r['ext_id'] !== '') $byExt[(string)$r['ext_id']] = $r; elseif ($r['status'] === 'active') $manual[] = $r; }
            $ids = []; foreach ($users as $u) $ids[$u['id']] = 1;
            $gone = array_filter($byExt, fn($r) => $r['status'] === 'active' && !isset($ids[(string)$r['ext_id']]));
            $activeBefore = count(array_filter($byExt, fn($r) => $r['status'] === 'active'));
            // The list lacks most of what was there (or has nobody at all): more likely a fault than a mass departure. Hold it.
            $hold = !$force && $gone && ((count($users) === 0) || ($activeBefore >= 4 && count($gone) * 2 > $activeBefore));

            $db->beginTransaction();
            $taken = [];   // email|label already used in this run (the table allows one row per system + person + label)
            $find = $db->prepare('SELECT id FROM itops_people_links WHERE system_key=? AND email=? AND account=? AND id<>?');
            foreach ($users as $u) {
                $old = $byExt[$u['id']] ?? null;
                $extEmail = itops_sys_email($u['email']);
                $person = $old && !empty($old['pinned']) ? (string)$old['email'] : ($extEmail !== '' ? $extEmail : itops_conn_placeholder($key, $u['id']));
                $label = itops_conn_label($u);
                $find->execute([$key, $person, $label, $old ? (int)$old['id'] : 0]);
                // The table allows one row per system + person + label. Two accounts that would share one get the id in the label.
                $withId = mb_substr($label, 0, max(20, 186 - mb_strlen($u['id']))) . ' #' . $u['id'];
                if ($find->fetchColumn() || isset($taken[$person . '|' . mb_strtolower($label)])) $label = $withId;
                $taken[$person . '|' . mb_strtolower($label)] = 1;
                $detail = itops_conn_detail($u);
                // …and when the database still finds them equal (it compares without case and accents), the same way.
                $write = function (string $sql, callable $args) use ($db, &$label, $withId) {
                    try { $db->prepare($sql)->execute($args($label)); }
                    catch (PDOException $e) { if ((string)$e->getCode() !== '23000' || $label === $withId) throw $e; $label = $withId; $db->prepare($sql)->execute($args($label)); }
                };
                if ($old) {
                    $was = itops_sys_link_row($old + ['created_by_name' => '', 'removed_by_name' => '']);
                    $write("UPDATE itops_people_links SET email=?, ext_email=?, account=?, person_name=?, detail=?, seen_at=NOW(), status='active', removed_at=NULL, removed_by=NULL, removed_run=NULL, updated_at=NOW() WHERE id=?",
                        fn($lb) => [$person, $extEmail !== '' ? $extEmail : null, $lb, $u['name'] !== '' ? $u['name'] : null, $detail, (int)$old['id']]);
                    if ($old['status'] !== 'active') { $res['reopened']++; itops_sys_audit('access_link_restore', (int)$old['id'], $person . ' - ' . $sys['name'], ['Account' => $was['account'], 'Status' => 'removed'], ['Account' => $label, 'Status' => 'active', 'Why' => $sys['name'] . ' lists this user again (' . $who . ')']); }
                    elseif ((string)$old['email'] !== $person) { $res['moved']++; itops_sys_audit('access_link_move', (int)$old['id'], (string)$old['email'] . ' - ' . $sys['name'], ['Person' => (string)$old['email'], 'Account' => $was['account']], ['Person' => $person, 'Account' => $label, 'Why' => 'the email of this user changed in ' . $sys['name'] . ' (' . $who . ')']); }
                } else {
                    $write("INSERT INTO itops_people_links (system_key, email, person_name, account, note, status, source, created_by, created_at, updated_by, updated_at, ext_id, ext_email, pinned, detail, seen_at) VALUES (?,?,?,?,NULL,'active','api',?,NOW(),?,NOW(),?,?,0,?,NOW())",
                        fn($lb) => [$key, $person, $u['name'] !== '' ? $u['name'] : null, $lb, $uid, $uid, $u['id'], $extEmail !== '' ? $extEmail : null, $detail]);
                    $res['added']++;
                    itops_sys_audit('access_link_add', (int)$db->lastInsertId(), $person . ' - ' . $sys['name'], [], ['System' => $sys['name'], 'Person' => $person, 'Account' => $label, 'Status' => 'active', 'Recorded through' => 'read from ' . $sys['name'] . ' (' . $who . ')']);
                }
            }
            // What was typed by hand before the connection: the system is the record now.
            foreach ($manual as $r) {
                $db->prepare("UPDATE itops_people_links SET status='removed', removed_at=NOW(), removed_by=?, updated_at=NOW() WHERE id=? AND status='active'")->execute([$uid, (int)$r['id']]);
                $res['replaced']++;
                itops_sys_audit('access_link_remove', (int)$r['id'], (string)$r['email'] . ' - ' . $sys['name'], ['Account' => (string)$r['account'] !== '' ? (string)$r['account'] : '(name not recorded)', 'Status' => 'active'],
                    ['Status' => 'removed', 'Why' => 'typed by hand before ' . $sys['name'] . ' was connected: replaced by what ' . $sys['name'] . ' reports (' . $who . ')']);
            }
            if (!$hold) {
                foreach ($gone as $r) {
                    $db->prepare("UPDATE itops_people_links SET status='removed', removed_at=NOW(), removed_by=?, updated_at=NOW() WHERE id=? AND status='active'")->execute([$uid, (int)$r['id']]);
                    $res['closed']++;
                    itops_sys_audit('access_link_remove', (int)$r['id'], (string)$r['email'] . ' - ' . $sys['name'], ['Account' => (string)$r['account'], 'Status' => 'active'],
                        ['Status' => 'removed', 'Why' => $sys['name'] . ' no longer lists this user (' . $who . ')']);
                }
            } else $res['held'] = array_values(array_map(fn($r) => (string)$r['account'], $gone));
            $db->commit();

            $st = itops_conn_state($db, $key);
            $st['last_sync_at'] = $res['at']; $st['last_sync_ok'] = true; $st['last_error'] = ''; $st['last_error_at'] = null; $st['held'] = $res['held'];
            $st['counts'] = ['users' => count($users), 'at' => $res['at']];
            itops_conn_put($db, $key, $st);
            if ($res['added'] || $res['reopened'] || $res['closed'] || $res['replaced'] || $res['moved'] || $res['held'])
                itops_sys_audit('access_sync', 0, $sys['name'] . ' - ' . ($uid ? 'synchronized' : 'scheduled synchronization'), ['Users in ' . $sys['name'] => (string)count($users)],
                    ['New' => (string)$res['added'], 'Listed again' => (string)$res['reopened'], 'No longer listed' => (string)$res['closed'], 'Typed by hand, replaced' => (string)$res['replaced'],
                     'Email changed' => (string)$res['moved']] + ($res['held'] ? ['Held back' => count($res['held']) . ' account(s) missing from the list were NOT closed: ' . mb_substr(implode(', ', $res['held']), 0, 300)] : []));
            $res['ok'] = true;
            return $res;
        } catch (Throwable $e) {
            try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable $x) {}
            $res['error'] = 'The synchronization stopped and nothing was changed: ' . $e->getMessage();
            if (function_exists('itops_log')) { try { itops_log('error', 'access', $sys['name'] . ' synchronization failed: ' . $e->getMessage()); } catch (Throwable $x) {} }
            return $res;
        } finally {
            try { $db->query('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')'); } catch (Throwable $e) {}
        }
    }

    /**
     * Make sure what is in the table is a recent reading of the system. Returns [ok, error, at]. A reading younger than
     * $maxAge seconds is used as it is; 0 = always read now. When accounts are held back the reading is NOT good enough to say "gone".
     */
    function itops_conn_fresh(PDO $db, string $key, array $by = [], int $maxAge = ITOPS_CONN_FRESH): array {
        $st = itops_conn_state($db, $key);
        $at = isset($st['last_sync_at']) ? strtotime((string)$st['last_sync_at']) : false;
        if ($maxAge > 0 && !empty($st['last_sync_ok']) && $at && (time() - $at) <= $maxAge && empty($st['held'])) return ['ok' => true, 'error' => null, 'at' => (string)$st['last_sync_at']];
        $r = itops_conn_sync($db, $key, $by);
        if ($r['ok'] && $r['held']) return ['ok' => false, 'error' => 'the list from this system lacks ' . count($r['held']) . ' account(s) it had before; a person has to look at it on the system’s tab first', 'at' => $r['at']];
        return ['ok' => $r['ok'], 'error' => $r['error'], 'at' => $r['at']];
    }





}
