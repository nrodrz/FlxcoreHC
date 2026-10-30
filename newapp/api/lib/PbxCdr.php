<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT152 — the call records of the office phone system (Grandstream UCM63xx), kept here for reports, and its missed
 * calls as tickets (PhoneTickets.php, the same rules as CallTrackingMetrics and JustCall).
 *
 *   Reading  every five minutes the app asks the PBX for the calls since the last reading (3 hours of overlap, as a
 *            record is written when the call ends; each call is kept once by its record id). The first reading goes
 *            back 90 days, in windows of 3 days and at most 12 requests a run, and goes on at the next runs. Through the main API ("cdrapi" with the session); on a firmware
 *            that does not give call records there, through the older CDR service (port 8443, its own user) when its user
 *            is entered on the connection. Nothing is pushed by the PBX: no address has to be opened towards the app.
 *   Kept     itops_pbx_cdr: when, how long, answered or not, direction (inbound = through a trunk to the office,
 *            outbound = out through a trunk, internal), the extension involved, the other number, the trunk, the name of
 *            any recording (the recording itself stays on the PBX). Removed after the days set (default 400).
 *   Times    the PBX's local time, read as the installation's time zone (Settings → General). Keep both the same.
 */
require_once __DIR__ . '/SystemConnect.php';
require_once __DIR__ . '/Ucm.php';
require_once __DIR__ . '/PhoneTickets.php';

if (!function_exists('itops_pbx_cdr_table')) {
    if (!defined('ITOPS_PBX_CDR_BACKFILL_DAYS')) define('ITOPS_PBX_CDR_BACKFILL_DAYS', 90);

    function itops_pbx_cdr_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_pbx_cdr (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rec_key VARCHAR(80) NOT NULL, started_at DATETIME NULL, answered_at DATETIME NULL,
            ended_at DATETIME NULL, duration INT NOT NULL DEFAULT 0, billsec INT NOT NULL DEFAULT 0, disposition VARCHAR(20) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT '',
            direction VARCHAR(10) NOT NULL DEFAULT '', src VARCHAR(64) NOT NULL DEFAULT '', dst VARCHAR(64) NOT NULL DEFAULT '', caller_name VARCHAR(160) NOT NULL DEFAULT '',
            ext VARCHAR(20) NOT NULL DEFAULT '', other_number VARCHAR(64) NOT NULL DEFAULT '', trunk VARCHAR(80) NOT NULL DEFAULT '', action_type VARCHAR(40) NOT NULL DEFAULT '',
            recording VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
            UNIQUE KEY uq_pbx_cdr (rec_key), INDEX idx_pbx_cdr_start (started_at), INDEX idx_pbx_cdr_ext (ext), INDEX idx_pbx_cdr_other (other_number))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    function itops_pbx_cdr_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_pbx_cdr LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    function itops_pbx_cdr_state(PDO $db): array {
        try { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute(['pbx_cdr']); $j = json_decode((string)$q->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        return (is_array($j) ? $j : []) + ['last_start' => '', 'cursor' => '', 'last_run' => 0, 'last_result' => null, 'keep_days' => 400, 'last_purge' => 0, 'source' => ''];
    }
    function itops_pbx_cdr_save(PDO $db, array $s): void {
        $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['pbx_cdr', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** A PBX time ("2026-10-27 14:05:00") as stored, or null. */
    function itops_pbx_dt(string $v): ?string { $t = $v !== '' ? strtotime($v) : false; return $t ? date('Y-m-d H:i:s', $t) : null; }

    /**
     * Read the new call records and keep them; then the missed-call tickets when they are on. Returns counts. Nothing
     * signs in after a refused sign-in (PbxService guard).
     */
    function itops_pbx_cdr_sync(PDO $db, ?int $now = null): array {
        require_once __DIR__ . '/PbxService.php';
        $now = $now ?? time(); $st = itops_pbx_cdr_state($db);
        $res = ['at' => date('Y-m-d H:i:s', $now), 'read' => 0, 'new' => 0, 'more' => false, 'errors' => []];
        $sys = itops_sys_get($db, 'pbx');
        if (!$sys || $sys['mode'] !== 'connected') { $res['errors'][] = 'the phone system is not set to Connected'; return $res; }
        itops_pbx_cdr_table($db);
        // from the cursor (3 hours back: a record is written when the call ends, so a long call appears late), else 90 days back;
        // in windows of 3 days, at most 12 requests a run, so the first reading goes on at the next runs
        $end = $now + 60; $cursor = $st['cursor'] !== '' ? strtotime($st['cursor']) - 3 * 3600 : $now - ITOPS_PBX_CDR_BACKFILL_DAYS * 86400;
        $rows = []; $more = false; $budget = 12;
        try {
            itops_pbx_guard($db);
            $c = itops_conn_creds($db, 'pbx'); $u = itops_pbx_client($db); $legacy = false;
            try {
                $u->login();
                while ($cursor < $end && $budget > 0) {
                    $wEnd = min($end, $cursor + 3 * 86400); $m = false;
                    $fromS = date('Y-m-d\TH:i:s', $cursor); $toS = date('Y-m-d\TH:i:s', $wEnd);
                    if (!$legacy) {
                        try { $got = $u->cdr($fromS, $toS, $budget, $m); $st['source'] = 'api'; }
                        catch (ItopsUcmException $e) {
                            if ($e->loginRefused || $e->pinMismatch || trim((string)($c['cdr_user'] ?? '')) === '') throw $e;
                            $legacy = true;
                        }
                    }
                    if ($legacy) { $got = $u->cdrLegacy((string)$c['cdr_user'], (string)($c['cdr_secret'] ?? ''), (int)(($c['cdr_port'] ?? '') !== '' ? $c['cdr_port'] : 8443), $fromS, $toS, $budget, $m); $st['source'] = 'legacy'; }
                    $rows = array_merge($rows, $got); $budget -= intdiv(count($got), 1000) + 1;
                    if ($m) {   // more than the requests allowed in this window: go on from the latest call read
                        $more = true; $mx = 0; foreach ($got as $r) { $t = strtotime($r['start']); if ($t && $t > $mx) $mx = $t; }
                        $cursor = max($cursor + 1, $mx); break;
                    }
                    $cursor = $wEnd;
                }
                if ($cursor < $end) $more = true;
            } catch (Throwable $e) { itops_pbx_note_refusal($db, $e); throw $e; }
            finally { $u->logout(); }
        } catch (Throwable $e) {
            $res['errors'][] = $e->getMessage(); $st = itops_pbx_cdr_state($db); $st['last_run'] = $now; $st['last_result'] = $res; itops_pbx_cdr_save($db, $st); return $res;
        }
        $src = $st['source'];
        $res['read'] = count($rows); $res['more'] = $more;
        $ins = $db->prepare("INSERT IGNORE INTO itops_pbx_cdr (rec_key, started_at, answered_at, ended_at, duration, billsec, disposition, status, direction, src, dst, caller_name, ext, other_number, trunk, action_type, recording, created_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $new = []; $max = $st['last_start'];
        foreach ($rows as $r) {
            $s = itops_pbx_dt($r['start']); if ($s === null) continue;
            $ins->execute([$r['key'], $s, itops_pbx_dt($r['answer']), itops_pbx_dt($r['end']), $r['duration'], $r['billsec'], $r['disposition'], $r['status'], $r['direction'], $r['src'], $r['dst'],
                           $r['caller_name'], $r['ext'], $r['other'], $r['trunk'], $r['action_type'], $r['recording']]);
            if ($ins->rowCount() > 0) { $res['new']++; $new[] = $r + ['start_db' => $s]; }
            if ($max === '' || $s > $max) $max = $s;
        }
        $st = itops_pbx_cdr_state($db);
        if ($max !== '') $st['last_start'] = $max;
        $st['cursor'] = date('Y-m-d H:i:s', min($cursor, $now)); $st['source'] = $src; $st['last_run'] = $now;
        // missed calls → tickets (only the calls kept for the first time in this run)
        $c = itops_ph_cfg($db, 'pbx');
        // (calls from before tickets were switched on are not looked at: the first reading of 90 days does not fill the list)
        $since = $c['on_since'] ? strtotime((string)$c['on_since']) - 60 : $now;
        $new = array_values(array_filter($new, fn($r) => strtotime($r['start_db']) >= $since));
        if ($c['on'] && $new) {
            $calls = array_map(fn($r) => ['id' => $r['key'], 'at' => date('c', strtotime($r['start_db'])), 'direction' => $r['direction'], 'status' => $r['status'], 'caller_number' => $r['src'],
                'caller_name' => $r['caller_name'], 'tracking_number' => strlen(preg_replace('/\D/', '', $r['dst'])) >= 7 ? $r['dst'] : '', 'tracking_label' => $r['trunk'] !== '' ? 'trunk ' . $r['trunk'] : '',
                'source' => '', 'agent_name' => $r['ext'] !== '' ? 'extension ' . $r['ext'] : '', 'ring_time' => max(0, $r['duration'] - $r['billsec']), 'voicemail' => $r['status'] === 'voicemail'], $new);
            $t = itops_ph_take($db, 'pbx', 'the phone system', $calls, $now);
            $res['missed'] = $t['missed']; $res['tickets'] = $t['tickets']; $res['notes'] = $t['notes'];
            $c = itops_ph_cfg($db, 'pbx'); $c['last_poll'] = $now; $c['last_result'] = $res; itops_ph_save($db, 'pbx', $c);
        }
        // records older than the days kept are removed once a day
        if ($now - (int)$st['last_purge'] > 86400) {
            try { $q = $db->prepare('DELETE FROM itops_pbx_cdr WHERE started_at < ?'); $q->execute([date('Y-m-d H:i:s', $now - max(30, (int)$st['keep_days']) * 86400)]); $res['purged'] = $q->rowCount(); } catch (Throwable $e) {}
            $st['last_purge'] = $now;
        }
        $st['last_result'] = $res; itops_pbx_cdr_save($db, $st);
        return $res;
    }

    /** The missed-call check for the PBX (the scheduled job or "Check now"): the same reading as the call records. */
    function itops_pbx_poll(PDO $db): array { $r = itops_pbx_cdr_sync($db); $r += ['tickets' => 0, 'notes' => 0]; return $r; }

    /** The numbers called from outside that the PBX recorded lately (the lines to choose for tickets). */
    function itops_pbx_numbers(PDO $db, bool $fresh = false): array {
        $rows = [];
        if (itops_pbx_cdr_ready($db)) {
            $q = $db->prepare("SELECT dst, MAX(trunk) AS trunk, COUNT(*) n FROM itops_pbx_cdr WHERE direction='inbound' AND started_at >= ? GROUP BY dst ORDER BY n DESC LIMIT 200");
            $q->execute([date('Y-m-d H:i:s', time() - 60 * 86400)]);
            $cfg = itops_ph_cfg($db, 'pbx'); $lines = array_flip((array)$cfg['lines']);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $d = itops_ph_digits((string)$r['dst']); if ($d === '') continue;
                $rows[] = ['id' => $d, 'number' => (string)$r['dst'], 'formatted' => '', 'show' => itops_ph_show((string)$r['dst']), 'digits' => $d, 'label' => (string)$r['trunk'] !== '' ? 'trunk ' . $r['trunk'] : '',
                           'source' => (int)$r['n'] . ' calls in 60 days', 'receiving' => [], 'routing' => '', 'status' => '', 'kind' => '', 'tickets' => $cfg['on'] && (!$lines || isset($lines[$d]))]; }
        }
        return ['rows' => $rows, 'account' => null, 'read_at' => date('Y-m-d H:i:s')];
    }

    /** "local", "long distance", "international", "toll free", "internal" or "other" for a number dialed out (North American plan). */
    function itops_pbx_dest(string $n): string {
        $raw = trim($n); $d = preg_replace('/\D/', '', $raw);
        if ($d === '') return 'other';
        if (strlen($d) <= 6) return 'internal';
        if (str_starts_with($d, '011') || (str_starts_with($raw, '+') && !str_starts_with($d, '1'))) return 'international';
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        if (strlen($d) === 10 && preg_match('/^8(00|33|44|55|66|77|88)/', $d)) return 'toll free';
        if (strlen($d) === 10) return 'long distance or local (10 digits)';
        if (strlen($d) === 7) return 'local';
        return 'other';
    }

    /**
     * The report of a period (dates YYYY-MM-DD, both included) from the records kept here: the same parts as the other
     * phone systems (total, lines = trunks, agents = extensions with their person, days), plus hours, directions,
     * destinations dialed and the numbers called or calling most.
     */
    function itops_pbx_report(PDO $db, string $from, string $to, bool $fresh = false): array {
        if (!itops_pbx_cdr_ready($db)) return ['from' => $from, 'to' => $to, 'total' => ['calls' => 0, 'inbound' => 0, 'answered' => 0, 'missed' => 0, 'talk' => 0, 'avg_talk' => 0], 'lines' => [], 'sources' => [], 'agents' => [], 'days' => [],
                                               'hours' => array_fill(0, 24, 0), 'directions' => [], 'destinations' => [], 'numbers' => [], 'read' => 0, 'truncated' => false, 'read_at' => date('Y-m-d H:i:s'), 'kept_from' => null];
        $q = $db->prepare('SELECT * FROM itops_pbx_cdr WHERE started_at >= ? AND started_at < ? ORDER BY started_at');
        $q->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
        $people = [];
        try { foreach ($db->query("SELECT ext_id, email, person_name FROM itops_people_links WHERE system_key='pbx' AND ext_id IS NOT NULL ORDER BY status='active' DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) as $r)
                  if (!isset($people[(string)$r['ext_id']])) $people[(string)$r['ext_id']] = ['email' => itops_conn_is_placeholder((string)$r['email']) ? '' : (string)$r['email'], 'name' => (string)($r['person_name'] ?? '')]; } catch (Throwable $e) {}
        $calls = []; $hours = array_fill(0, 24, 0); $dirs = ['inbound' => 0, 'outbound' => 0, 'internal' => 0]; $dest = []; $nums = []; $recd = 0;
        $missed = fn($s) => in_array($s, ['no answer', 'busy', 'failed', 'voicemail'], true);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = $people[(string)$r['ext']] ?? null;
            $calls[] = ['at' => date('c', strtotime((string)$r['started_at'])), 'direction' => $r['direction'] === 'internal' ? 'internal' : $r['direction'], 'status' => (string)$r['status'],
                        'talk_time' => (int)$r['billsec'], 'tracking_number' => (string)$r['trunk'], 'tracking_label' => (string)$r['trunk'] !== '' ? 'trunk ' . $r['trunk'] : '', 'source' => '',
                        'agent_name' => $r['ext'] !== '' ? 'ext ' . $r['ext'] . (($p['name'] ?? '') !== '' ? ' · ' . $p['name'] : '') : '', 'agent_email' => $p['email'] ?? ''];
            $hours[(int)date('G', strtotime((string)$r['started_at']))]++;
            if ((string)$r['recording'] !== '') $recd++;
            $dirs[$r['direction']] = ($dirs[$r['direction']] ?? 0) + 1;
            if ($r['direction'] === 'outbound') { $k = itops_pbx_dest((string)$r['other_number']); $dest[$k] = ($dest[$k] ?? 0) + 1; }
            if ($r['direction'] !== 'internal' && $r['other_number'] !== '') { $k = (string)$r['other_number'];
                if (!isset($nums[$k])) $nums[$k] = ['number' => itops_ph_show($k), 'name' => '', 'in' => 0, 'out' => 0, 'missed' => 0];
                if ($nums[$k]['name'] === '' && $r['caller_name'] !== '' && $r['direction'] === 'inbound') $nums[$k]['name'] = (string)$r['caller_name'];
                $nums[$k][$r['direction'] === 'inbound' ? 'in' : 'out']++; if ($r['direction'] === 'inbound' && $missed((string)$r['status'])) $nums[$k]['missed']++; }
        }
        // a trunk line has no digits: the shared report keys lines by digits, so trunks are grouped here
        $rep = itops_ph_report(array_map(fn($k) => $k + ['tracking_number' => ''], $calls), $missed);
        $tr = [];
        foreach ($calls as $k) { $t = $k['tracking_label'] !== '' ? $k['tracking_label'] : 'internal (no trunk)';
            if (!isset($tr[$t])) $tr[$t] = ['line' => $t, 'label' => '', 'calls' => 0, 'inbound' => 0, 'answered' => 0, 'missed' => 0, 'talk' => 0];
            $tr[$t]['calls']++; if ($k['direction'] === 'inbound') $tr[$t]['inbound']++; if ($k['status'] === 'answered') { $tr[$t]['answered']++; $tr[$t]['talk'] += $k['talk_time']; }
            if ($k['direction'] === 'inbound' && $missed($k['status'])) $tr[$t]['missed']++; }
        foreach ($tr as &$x) $x['avg_talk'] = $x['answered'] ? (int)round($x['talk'] / $x['answered']) : 0; unset($x);
        $tr = array_values($tr); usort($tr, fn($a, $b) => $b['calls'] <=> $a['calls']);
        $nums = array_values($nums); usort($nums, fn($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out'])); $nums = array_slice($nums, 0, 25);
        arsort($dest);
        $kept = null; try { $kept = $db->query('SELECT MIN(started_at) FROM itops_pbx_cdr')->fetchColumn() ?: null; } catch (Throwable $e) {}
        return array_merge($rep, ['lines' => $tr, 'from' => $from, 'to' => $to, 'hours' => $hours, 'directions' => $dirs, 'destinations' => array_map(fn($k, $v) => ['kind' => $k, 'calls' => $v], array_keys($dest), $dest),
                                  'numbers' => $nums, 'recorded' => $recd, 'truncated' => false, 'account' => null, 'read_at' => date('Y-m-d H:i:s'), 'kept_from' => $kept]);
    }

    /** This server's address as the internet sees it (for the PBX's whitelist and the firewall rule). */
    function itops_pbx_myip(): array {
        $urls = defined('ITOPS_MYIP_URL') && is_string(ITOPS_MYIP_URL) && ITOPS_MYIP_URL !== '' ? [ITOPS_MYIP_URL] : ['https://api.ipify.org?format=json', 'https://api64.ipify.org?format=json'];
        foreach ($urls as $u) {
            $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false]);
            $raw = curl_exec($ch); curl_close($ch);
            $d = json_decode((string)$raw, true); $ip = is_array($d) ? (string)($d['ip'] ?? '') : '';
            if (filter_var($ip, FILTER_VALIDATE_IP)) return ['ok' => true, 'ip' => $ip, 'from' => parse_url($u, PHP_URL_HOST)];
        }
        return ['ok' => false, 'ip' => '', 'note' => 'The address could not be found from here. Ask the hosting provider for the server\'s outgoing (public) IP address.'];
    }
}
