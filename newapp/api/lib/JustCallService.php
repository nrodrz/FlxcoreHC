<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT150 — JustCall beyond its users (those go through SystemConnect.php since BT45): the company's numbers, the
 * call report of a period (activity per person), and missed calls / voicemails as tickets (PhoneTickets.php, the engine
 * CallTrackingMetrics uses since BT148). Read only: nothing is ever written to JustCall.
 *
 *   Calls    GET /v2.1/calls (sort=id, order=desc, per_page ≤ 100; from_datetime / to_datetime in the account's time
 *            zone; call_direction Incoming|Outgoing). Only the last three months are kept by JustCall.
 *   Types    call_info.type: answered, missed, voicemail, abandoned, busy, failed… (INCOMING_/OUTGOING_ prefixes dropped).
 *   Tickets  "after switching on" is decided by the call id (the newest id when switched on is the baseline; JustCall
 *            ids grow), so the account's time zone never decides which calls count.
 * The calls are asked every five minutes while tickets are on: one or two requests, far inside JustCall's hourly limit.
 */
require_once __DIR__ . '/SystemConnect.php';
require_once __DIR__ . '/JustCall.php';
require_once __DIR__ . '/PhoneTickets.php';

if (!function_exists('itops_jc_client')) {
    function itops_jc_client(PDO $db): ItopsJustCall {
        $cn = itops_conn_public($db, 'justcall');
        if (!$cn || !$cn['configured']) throw new RuntimeException('JustCall is not connected: a Super Admin enters its key under Settings → Company systems → JustCall → Set up.');
        $c = itops_conn_creds($db, 'justcall');
        return new ItopsJustCall($c['api_key'] ?? '', $c['api_secret'] ?? '');
    }
    function itops_jc_is_missed(string $st): bool { return in_array($st, ['missed', 'voicemail', 'abandoned', 'busy', 'failed'], true); }

    function itops_jc_numbers(PDO $db, bool $fresh = false): array {
        require_once __DIR__ . '/SafeCache.php';
        $f = itops_cache_dir('justcall') . '/numbers.json'; $j = null;
        if (!$fresh && ($raw = itops_cache_read($f)) !== null) { $x = json_decode($raw, true); if (is_array($x) && time() - (int)($x['at'] ?? 0) < 300) $j = $x; }
        if ($j === null) { $j = ['at' => time(), 'rows' => itops_jc_client($db)->numbers()]; itops_cache_write($f, json_encode($j)); }
        $cfg = itops_ph_cfg($db, 'justcall'); $lines = array_flip((array)$cfg['lines']);
        foreach ($j['rows'] as &$r) { $d = itops_ph_digits($r['number']); $r['digits'] = $d; $r['tickets'] = $cfg['on'] && $d !== '' && (!$lines || isset($lines[$d])); $r['show'] = itops_ph_show($r['number']); }
        unset($r);
        return ['rows' => $j['rows'], 'account' => null, 'read_at' => date('Y-m-d H:i:s', (int)$j['at'])];
    }

    /** The activity of a period (dates YYYY-MM-DD): by line, by agent (the person), by day. At most 5,000 calls. */
    function itops_jc_report(PDO $db, string $from, string $to, bool $fresh = false): array {
        require_once __DIR__ . '/SafeCache.php';
        $f = itops_cache_dir('justcall') . '/calls_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to) . '.json';
        if (!$fresh && ($raw = itops_cache_read($f)) !== null) { $x = json_decode($raw, true); if (is_array($x) && time() - (int)($x['at'] ?? 0) < 300) return $x['report'] + ['read_at' => date('Y-m-d H:i:s', (int)$x['at'])]; }
        [$calls, $trunc] = itops_jc_client($db)->calls(['from_datetime' => $from . ' 00:00:00', 'to_datetime' => $to . ' 23:59:59'], '', 50);
        $report = itops_ph_report($calls, 'itops_jc_is_missed') + ['from' => $from, 'to' => $to, 'account' => null, 'truncated' => $trunc];
        itops_cache_write($f, json_encode(['at' => time(), 'report' => $report]));
        return $report + ['read_at' => date('Y-m-d H:i:s')];
    }

    /** The newest call id now, recorded as the baseline when tickets are switched on. */
    function itops_jc_baseline(PDO $db): string { return itops_jc_client($db)->newestCallId(); }

    /** The missed-call check: the calls above the baseline, newest first until the baseline, handed to PhoneTickets. */
    function itops_jc_poll(PDO $db, ?int $now = null): array {
        $now = $now ?? time(); $c = itops_ph_cfg($db, 'justcall');
        if (!$c['on']) return ['skipped' => 'tickets are switched off'];
        $sys = itops_sys_get($db, 'justcall');
        if (!$sys || $sys['mode'] !== 'connected') return ['skipped' => 'JustCall is not set to Connected'];
        $res = ['at' => date('Y-m-d H:i:s', $now), 'read' => 0, 'errors' => []];
        try {
            $base = (string)($c['baseline'] ?? '');
            [$calls, $trunc] = itops_jc_client($db)->calls(['call_direction' => 'Incoming', 'from_datetime' => date('Y-m-d', $now - 3 * 86400)], $base, 10);
            $res['read'] = count($calls);
            foreach ($calls as &$k) { $k['after_baseline'] = true;   // above the baseline (none = there was no call yet when switched on)
                 if ($k['direction'] === '') $k['direction'] = 'inbound'; }
            unset($k);
            $max = $base; foreach ($calls as $k) if ($max === '' || ItopsJustCall::cmpId($k['id'], $max) > 0) $max = $k['id'];
            $res += itops_ph_take($db, 'justcall', 'JustCall', $calls, $now);
            $c = itops_ph_cfg($db, 'justcall'); if ($max !== '') $c['baseline'] = $max; itops_ph_save($db, 'justcall', $c);
            if ($trunc) $res['errors'][] = 'more than 1,000 new calls since the last check: the oldest of them were not looked at';
        } catch (Throwable $e) { $res['errors'][] = $e->getMessage(); }
        $c = itops_ph_cfg($db, 'justcall');
        if (!$res['errors'] || $res['read'] > 0) $c['last_poll'] = $now;
        $c['last_result'] = $res; itops_ph_save($db, 'justcall', $c);
        return $res;
    }
}
