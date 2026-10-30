<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT151 — what the app does with the office PBX (Grandstream UCM63xx, lib/Ucm.php) beyond reading its extensions as
 * accounts (SystemConnect.php): the full list of extensions with the person each belongs to, the PBX's status, and taking
 * an extension out of service or back. Every change is written to the Audit Log, with what was before; a refusal too.
 */
require_once __DIR__ . '/SystemConnect.php';
require_once __DIR__ . '/Ucm.php';

if (!function_exists('opsh_pbx_client')) {

    function opsh_pbx_client(PDO $db): OpshUcm {
        $cn = opsh_conn_public($db, 'pbx');
        if (!$cn || !$cn['configured']) throw new RuntimeException('The phone system is not connected: a Super Admin enters its address and API user under Settings → Company systems → Phone system (PBX) → Set up.');
        $c = opsh_conn_creds($db, 'pbx'); $st = opsh_conn_state($db, 'pbx');
        return new OpshUcm($c['address'] ?? '', $c['api_user'] ?? '', $c['api_secret'] ?? '', (string)($st['pin'] ?? ''));
    }

    /** A failed sign-in is remembered: nothing signs in again by itself until the connection is saved or tested again. */
    function opsh_pbx_guard(PDO $db): void {
        $st = opsh_conn_state($db, 'pbx');
        if (!empty($st['login_refused_at'])) throw new RuntimeException('The phone system refused the last sign-in (' . opsh_conn_when((string)$st['login_refused_at']) . '), so nothing signs in again by itself: the PBX bans an address after a few failures. Check the API user and password, then "Test the connection" on its set-up page.');
    }
    function opsh_pbx_note_refusal(PDO $db, Throwable $e): void {
        if ($e instanceof OpshUcmException && $e->loginRefused) { $st = opsh_conn_state($db, 'pbx'); $st['login_refused_at'] = opsh_conn_now(); opsh_conn_put($db, 'pbx', $st); }
    }

    /** Every extension (out of service too), each with the person it is linked to. */
    function opsh_pbx_extensions(PDO $db): array {
        opsh_pbx_guard($db);
        $u = opsh_pbx_client($db);
        try { $u->login(); $x = $u->extensions(); $s = $u->status(); } catch (Throwable $e) { opsh_pbx_note_refusal($db, $e); throw $e; } finally { $u->logout(); }
        $seen = opsh_pbx_note_out($db, $x); $wait = (int)opsh_pbx_settings($db)['delete_wait_days'];   // BT153
        $who = [];
        try { $q = $db->prepare("SELECT ext_id, email, person_name, pinned FROM opsh_people_links WHERE system_key='pbx' AND ext_id IS NOT NULL ORDER BY status='active' DESC, id DESC"); $q->execute();
              foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($who[(string)$r['ext_id']])) $who[(string)$r['ext_id']] = ['email' => opsh_conn_is_placeholder((string)$r['email']) ? '' : (string)$r['email'], 'name' => (string)($r['person_name'] ?? '')]; } catch (Throwable $e) {}
        foreach ($x as &$e) { $w = $who[$e['extension']] ?? null; $e['person'] = $w['email'] ?? ($e['email'] !== '' ? $e['email'] : '');
            $e['out_since'] = $e['out_of_service'] && isset($seen[$e['extension']]) ? (string)$seen[$e['extension']] : null;
            $e['can_delete_from'] = $e['out_since'] ? date('Y-m-d', strtotime($e['out_since']) + $wait * 86400) : null; }
        unset($e);
        return ['rows' => $x, 'status' => $s, 'read_at' => date('Y-m-d H:i:s'), 'delete_wait_days' => $wait];
    }

    /** Out of service ($out) or back in service. Returns what changed. Writes the Audit Log (refusals too). Throws a sentence. */
    function opsh_pbx_service(PDO $db, string $ext, bool $out, string $why): array {
        $label = 'Extension ' . $ext . ' - phone system';
        try {
            opsh_pbx_guard($db);
            $u = opsh_pbx_client($db);
            try { $u->login(); $chg = $u->setService($ext, $out); } finally { $u->logout(); }
        } catch (Throwable $e) {
            if (isset($u)) opsh_pbx_note_refusal($db, $e);
            if (function_exists('audit_log')) audit_log('pbx_extension_refused', 'access', null, $label, null, ['Wanted' => $out ? 'out of service' : 'back in service', 'Why it failed' => mb_substr($e->getMessage(), 0, 300), 'For' => $why]);
            throw $e;
        }
        if ($out) { $st = opsh_conn_state($db, 'pbx'); $m = is_array($st['out_seen'] ?? null) ? $st['out_seen'] : []; if (!isset($m[$ext])) { $m[$ext] = date('Y-m-d H:i:s'); $st['out_seen'] = $m; opsh_conn_put($db, 'pbx', $st); } }   // BT153
        if ($chg && function_exists('audit_log')) {
            $o = []; $n = []; foreach ($chg as $k => [$a, $b]) { $o[$k] = $a; $n[$k] = $b; }
            audit_log($out ? 'pbx_extension_out' : 'pbx_extension_back', 'access', null, $label, $o, $n + ['For' => $why]);
        }
        // the accounts read from the PBX follow at once (an extension out of service is no account)
        try { opsh_conn_sync($db, 'pbx', function_exists('opsh_conn_actor') ? opsh_conn_actor() : []); } catch (Throwable $e) {}
        return $chg;
    }

    // ───────────────────────────── BT153: managing extensions ─────────────────────────────

    /** Sign in, do $fn with the client, sign out; a refused sign-in is remembered (nothing retries by itself). */
    function opsh_pbx_with(PDO $db, callable $fn) {
        opsh_pbx_guard($db);
        $u = opsh_pbx_client($db);
        try { $u->login(); return $fn($u); } catch (Throwable $e) { opsh_pbx_note_refusal($db, $e); throw $e; } finally { $u->logout(); }
    }

    function opsh_pbx_settings(PDO $db): array {
        $st = opsh_conn_state($db, 'pbx'); $m = is_array($st['manage'] ?? null) ? $st['manage'] : [];
        return $m + ['delete_wait_days' => 30, 'permission' => 'internal-local-national'];
    }
    function opsh_pbx_permissions(): array {
        return ['internal' => 'Internal calls only', 'internal-local' => 'Internal and local', 'internal-local-national' => 'Internal, local and national (long distance)',
                'internal-local-national-international' => 'Everything, international included'];
    }

    /**
     * When each extension out of service was first seen out of service by this app (it decides when it may be deleted).
     * Kept in the connection state; an extension back in service is forgotten. Returns the map.
     */
    function opsh_pbx_note_out(PDO $db, array $rows): array {
        $st = opsh_conn_state($db, 'pbx'); $m = is_array($st['out_seen'] ?? null) ? $st['out_seen'] : []; $now = date('Y-m-d H:i:s'); $chg = false; $seen = [];
        foreach ($rows as $r) { $e = (string)$r['extension']; $seen[$e] = 1;
            if ($r['out_of_service'] && !isset($m[$e])) { $m[$e] = $now; $chg = true; }
            if (!$r['out_of_service'] && isset($m[$e])) { unset($m[$e]); $chg = true; } }
        foreach (array_keys($m) as $e) if (!isset($seen[(string)$e])) { unset($m[$e]); $chg = true; }
        if ($chg) { $st = opsh_conn_state($db, 'pbx'); $st['out_seen'] = $m; opsh_conn_put($db, 'pbx', $st); }
        return $m;
    }

    /** The extension to suggest for a new person: the next free number after the highest one of the usual length. */
    function opsh_pbx_next_ext(array $rows): string {
        $nums = array_values(array_filter(array_map(fn($r) => (string)$r['extension'], $rows), fn($e) => preg_match('/^[1-9][0-9]{1,5}$/', $e)));
        if (!$nums) return '1000';
        $len = array_count_values(array_map('strlen', $nums)); arsort($len); $L = (int)array_key_first($len);
        $same = array_map('intval', array_filter($nums, fn($e) => strlen($e) === $L)); $taken = array_flip($nums);
        for ($n = max($same) + 1; strlen((string)$n) === $L; $n++) if (!isset($taken[(string)$n])) return (string)$n;
        for ($n = (int)('1' . str_repeat('0', $L - 1)); strlen((string)$n) === $L; $n++) if (!isset($taken[(string)$n])) return (string)$n;
        return '';
    }

    /** A secret the PBX accepts: letters and digits, at least one of each (the UCM refuses weak SIP passwords). */
    function opsh_pbx_secret(int $len = 16): string {
        $a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ'; $d = '23456789'; $all = $a . $d;
        $s = $a[random_int(0, strlen($a) - 1)] . $d[random_int(0, 7)] . strtoupper($a[random_int(0, 23)]);
        while (strlen($s) < $len) $s .= $all[random_int(0, strlen($all) - 1)];
        return str_shuffle($s);
    }
    function opsh_pbx_pin(): string { do { $p = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); } while (preg_match('/^(\d)\1+$/', $p) || strpos('0123456789876543210', $p) !== false); return $p; }

    function opsh_pbx_check_ext(string $ext): void { if (!preg_match('/^[1-9][0-9]{1,5}$/', $ext)) throw new InvalidArgumentException('An extension is a number of 2 to 6 digits that does not start with 0.'); }

    /**
     * Create an extension for a person (onboarding, or "Add an extension…"). The SIP password, voicemail PIN and user
     * portal password are made here, given to the PBX, and never kept or shown: the person signs in to Wave with the QR
     * code, and a voicemail PIN is given with "New voicemail PIN…". Audit Log: the extension, name, email, permission.
     */
    function opsh_pbx_create(PDO $db, string $ext, string $name, string $email, string $perm, string $why): array {
        opsh_pbx_check_ext($ext);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $name)); $email = mb_strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 64) throw new InvalidArgumentException('Give the name shown on the extension (at most 64 characters).');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('The email is not valid.');
        if (!isset(opsh_pbx_permissions()[$perm])) throw new InvalidArgumentException('Choose what the extension may call.');
        $label = 'Extension ' . $ext . ' - phone system';
        try {
            opsh_pbx_with($db, function (OpshUcm $u) use ($ext, $name, $email, $perm) {
                foreach ($u->extensions() as $r) {
                    if ($r['extension'] === $ext) throw new InvalidArgumentException('Extension ' . $ext . ' already exists on the phone system' . ($r['name'] !== '' ? ' (' . $r['name'] . ')' : '') . '. Choose another number.');
                    if ($email !== '' && $r['email'] === $email) throw new InvalidArgumentException($email . ' already has extension ' . $r['extension'] . ' on the phone system. Nothing was created.');
                }
                $u->addExtension(['extension' => $ext, 'fullname' => $name, 'email' => $email, 'secret' => opsh_pbx_secret(), 'vmsecret' => opsh_pbx_pin(), 'user_password' => opsh_pbx_secret(14), 'permission' => $perm]);
                if (!$u->account($ext)) throw new OpshUcmException('The phone system accepted the request but does not list extension ' . $ext . ' afterwards. Look at it on the PBX.');
            });
        } catch (Throwable $e) {
            if (function_exists('audit_log')) audit_log('pbx_extension_refused', 'access', null, $label, null, ['Wanted' => 'create it for ' . $name, 'Why it failed' => mb_substr($e->getMessage(), 0, 300), 'For' => $why]);
            throw $e;
        }
        if (function_exists('audit_log')) audit_log('pbx_extension_create', 'access', null, $label, null, ['Name' => $name, 'Email' => $email ?: 'none', 'May call' => opsh_pbx_permissions()[$perm], 'Passwords' => 'made by the app, given to the PBX, not kept', 'For' => $why]);
        try { opsh_conn_sync($db, 'pbx', function_exists('opsh_conn_actor') ? opsh_conn_actor() : []); } catch (Throwable $e) {}
        return ['extension' => $ext, 'name' => $name, 'email' => $email];
    }

    /** Change the name and/or email of an extension. Read back: a field the firmware did not take is said, not hidden. */
    function opsh_pbx_edit(PDO $db, string $ext, ?string $name, ?string $email, string $why): array {
        opsh_pbx_check_ext($ext);
        $p = [];
        if ($name !== null) { $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $name)); if ($name === '' || mb_strlen($name) > 64) throw new InvalidArgumentException('Give the name shown on the extension (at most 64 characters).'); }
        if ($email !== null) { $email = mb_strtolower(trim($email)); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('The email is not valid.'); }
        $label = 'Extension ' . $ext . ' - phone system';
        try {
            [$before, $after] = opsh_pbx_with($db, function (OpshUcm $u) use ($ext, $name, $email, &$p) {
                $b = $u->account($ext); if (!$b) throw new InvalidArgumentException('The phone system has no extension ' . $ext . '.');
                $x = OpshUcm::ext(['extension' => $ext] + $b);
                if ($name !== null && $name !== $x['name']) { $f = trim(strtok($name, ' ') ?: ''); $p += ['fullname' => $name, 'first_name' => $f, 'last_name' => trim(substr($name, strlen($f)))]; }
                if ($email !== null && $email !== $x['email']) $p['email'] = $email;
                if (!$p) return [$x, $x];
                $u->updateExtension($ext, $p);
                return [$x, OpshUcm::ext(['extension' => $ext] + ($u->account($ext) ?: []))];
            });
        } catch (Throwable $e) {
            if (function_exists('audit_log')) audit_log('pbx_extension_refused', 'access', null, $label, null, ['Wanted' => 'change name or email', 'Why it failed' => mb_substr($e->getMessage(), 0, 300), 'For' => $why]);
            throw $e;
        }
        if (!$p) return ['changed' => [], 'not_taken' => []];
        $chg = []; $not = [];
        if (isset($p['fullname'])) { if ($after['name'] === $name) $chg['Name'] = [$before['name'], $name]; else $not[] = 'the name'; }
        if (isset($p['email'])) { if ($after['email'] === $email) $chg['Email'] = [$before['email'] ?: 'none', $email ?: 'none']; else $not[] = 'the email'; }
        if (function_exists('audit_log')) { $o = []; $n = []; foreach ($chg as $k => [$a, $b]) { $o[$k] = $a; $n[$k] = $b; } if ($not) $n['Not taken by the PBX'] = implode(', ', $not);
            audit_log('pbx_extension_edit', 'access', null, $label, $o, $n + ['For' => $why]); }
        try { opsh_conn_sync($db, 'pbx', function_exists('opsh_conn_actor') ? opsh_conn_actor() : []); } catch (Throwable $e) {}
        return ['changed' => $chg, 'not_taken' => $not];
    }

    /** A new voicemail PIN, set on the PBX and returned once (never kept, never logged). */
    function opsh_pbx_new_pin(PDO $db, string $ext, string $why): string {
        opsh_pbx_check_ext($ext); $pin = opsh_pbx_pin(); $label = 'Extension ' . $ext . ' - phone system';
        try { opsh_pbx_with($db, function (OpshUcm $u) use ($ext, $pin) { if (!$u->account($ext)) throw new InvalidArgumentException('The phone system has no extension ' . $ext . '.'); $u->updateExtension($ext, ['vmsecret' => $pin]); }); }
        catch (Throwable $e) { if (function_exists('audit_log')) audit_log('pbx_extension_refused', 'access', null, $label, null, ['Wanted' => 'a new voicemail PIN', 'Why it failed' => mb_substr($e->getMessage(), 0, 300), 'For' => $why]); throw $e; }
        if (function_exists('audit_log')) audit_log('pbx_voicemail_pin', 'access', null, $label, null, ['Voicemail PIN' => 'replaced (shown once, not kept)', 'For' => $why]);
        return $pin;
    }

    /** The Wave QR code of an extension (it signs Wave in as the extension, so it is shown only on request and logged). */
    function opsh_pbx_qr(PDO $db, string $ext, string $why): string {
        opsh_pbx_check_ext($ext);
        $img = opsh_pbx_with($db, fn(OpshUcm $u) => $u->waveQr($ext));
        if ($img === '') throw new OpshUcmException('The phone system gave no QR code for extension ' . $ext . ' (this firmware may not offer it through the API). Use the QR code in the PBX\'s user portal.');
        if (function_exists('audit_log')) audit_log('pbx_wave_qr', 'access', null, 'Extension ' . $ext . ' - phone system', null, ['Wave QR code' => 'shown', 'For' => $why]);
        return $img;
    }

    /**
     * Delete an extension. Only one that has been out of service for the waiting period (seen by this app), with its
     * number typed. Its voicemail, recordings and settings go with it on the PBX. Audit Log with what it was.
     */
    function opsh_pbx_delete(PDO $db, string $ext, string $why): array {
        opsh_pbx_check_ext($ext); $label = 'Extension ' . $ext . ' - phone system'; $wait = (int)opsh_pbx_settings($db)['delete_wait_days'];
        try {
            $was = opsh_pbx_with($db, function (OpshUcm $u) use ($db, $ext, $wait) {
                $rows = $u->extensions(); $seen = opsh_pbx_note_out($db, $rows); $x = null;
                foreach ($rows as $r) if ($r['extension'] === $ext) $x = $r;
                if (!$x) throw new InvalidArgumentException('The phone system has no extension ' . $ext . '.');
                if (!$x['out_of_service']) throw new InvalidArgumentException('Extension ' . $ext . ' is in service. Only an extension out of service for ' . $wait . ' days can be deleted.');
                $since = isset($seen[$ext]) ? strtotime((string)$seen[$ext]) : time();
                $days = (int)floor((time() - $since) / 86400);
                if ($days < $wait) throw new InvalidArgumentException('Extension ' . $ext . ' has been out of service for ' . $days . ' day(s) (since ' . date('m/d/Y', $since) . '). It can be deleted after ' . $wait . ' days, from ' . date('m/d/Y', $since + $wait * 86400) . '.');
                $u->deleteExtension($ext);
                if ($u->account($ext)) throw new OpshUcmException('The phone system still lists extension ' . $ext . ' after deleting it. Look at it on the PBX.');
                return $x + ['out_since' => date('Y-m-d', $since)];
            });
        } catch (Throwable $e) {
            if (function_exists('audit_log')) audit_log('pbx_extension_refused', 'access', null, $label, null, ['Wanted' => 'delete it', 'Why it failed' => mb_substr($e->getMessage(), 0, 300), 'For' => $why]);
            throw $e;
        }
        if (function_exists('audit_log')) audit_log('pbx_extension_delete', 'access', null, $label, ['Extension' => $ext, 'Name' => $was['name'] ?: 'none', 'Email' => $was['email'] ?: 'none', 'Out of service since' => $was['out_since']], ['Extension' => 'deleted, with its voicemail and settings', 'For' => $why]);
        $st = opsh_conn_state($db, 'pbx'); unset($st['out_seen'][$ext]); opsh_conn_put($db, 'pbx', $st);
        try { opsh_conn_sync($db, 'pbx', function_exists('opsh_conn_actor') ? opsh_conn_actor() : []); } catch (Throwable $e) {}
        return $was;
    }

    /** Days from a PBX uptime text ("12 days 4:05:11", "0 day(s) 03:12:00", "3d 04h"), or null. */
    function opsh_pbx_uptime_hours(string $u): ?float {
        if ($u === '') return null;
        $d = preg_match('/(\d+)\s*d/i', $u, $m) ? (int)$m[1] : 0; $h = 0;
        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?/', $u, $m)) $h = (int)$m[1] + (int)$m[2] / 60; elseif (preg_match('/(\d+)\s*h/i', $u, $m)) $h = (int)$m[1];
        if (!preg_match('/\d/', $u)) return null;
        return $d * 24 + $h;
    }

    /** The read-only picture of the PBX's set-up: status, trunks, departments, the extensions in numbers, and warnings. */
    function opsh_pbx_overview(PDO $db): array {
        [$s, $t, $dep, $rows] = opsh_pbx_with($db, function (OpshUcm $u) { $tr = null; try { $tr = $u->trunks(); } catch (OpshUcmException $e) {} return [$u->status(), $tr, $u->departments(), $u->extensions()]; });
        opsh_pbx_note_out($db, $rows);
        $warn = [];
        $h = opsh_pbx_uptime_hours((string)$s['uptime']);
        if ($h !== null && $h < 24) $warn[] = ['kind' => 'restarted', 'text' => 'The phone system started ' . ($h < 1 ? 'less than an hour' : (int)$h . ' hour(s)') . ' ago (up ' . $s['uptime'] . '). If nobody restarted it, look for a power cut or a crash.'];
        if ($t === null) $warn[] = ['kind' => 'trunks', 'text' => 'The trunks could not be read (the API user may not have the permission).'];
        foreach ($t ?? [] as $x) {
            if ($x['out_of_service']) $warn[] = ['kind' => 'trunk_off', 'text' => 'Trunk ' . $x['name'] . ' is out of service: calls cannot go out or come in through it.'];
            elseif ($x['status'] !== '' && preg_match('/unreach|fail|unregist|timeout|reject/i', $x['status'])) $warn[] = ['kind' => 'trunk_down', 'text' => 'Trunk ' . $x['name'] . ' reports "' . $x['status'] . '".'];
        }
        $in = array_values(array_filter($rows, fn($r) => !$r['out_of_service']));
        $members = []; foreach ($dep as $d) foreach ($d['members'] as $m) $members[$m][] = $d['name'];
        return ['status' => $s, 'trunks' => $t ?? [], 'departments' => $dep, 'warnings' => $warn, 'read_at' => date('Y-m-d H:i:s'),
                'counts' => ['extensions' => count($rows), 'in_service' => count($in), 'out_of_service' => count($rows) - count($in), 'without_email' => count(array_filter($in, fn($r) => $r['email'] === ''))],
                'next' => opsh_pbx_next_ext($rows), 'settings' => opsh_pbx_settings($db)];
    }
}
