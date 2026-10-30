<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT148 — what the app does with CallTrackingMetrics beyond its users (those go through SystemConnect.php like
 * every connected system): the tracking numbers, the call report of a period, and the missed-call check that makes
 * tickets (PhoneTickets.php). Read only: nothing is ever written to CallTrackingMetrics.
 *
 * Lists are kept five minutes (SafeCache) so opening the tabs again does not spend requests; "Read now" reads again.
 */
require_once __DIR__ . '/SystemConnect.php';
require_once __DIR__ . '/Ctm.php';
require_once __DIR__ . '/PhoneTickets.php';

if (!function_exists('opsh_ctm_client')) {

    /** The client made from the saved key. Throws a sentence when CTM is not connected. */
    function opsh_ctm_client(PDO $db): OpshCtm {
        $cn = opsh_conn_public($db, 'ctm');
        if (!$cn || !$cn['configured']) throw new RuntimeException('CallTrackingMetrics is not connected: a Super Admin enters its keys under Settings → Company systems → CallTrackingMetrics → Set up.');
        $c = opsh_conn_creds($db, 'ctm');
        return new OpshCtm($c['api_key'] ?? '', $c['api_secret'] ?? '', $c['account_id'] ?? '');
    }

    function opsh_ctm_cache(string $name): string { require_once __DIR__ . '/SafeCache.php'; return opsh_cache_dir('ctm') . '/' . preg_replace('/[^a-z0-9_-]/', '', $name) . '.json'; }
    function opsh_ctm_forget(): void { require_once __DIR__ . '/SafeCache.php'; foreach (glob(opsh_cache_dir('ctm') . '/*.json') ?: [] as $f) @unlink($f); }

    /** The tracking numbers, with the lines that make tickets marked. */
    function opsh_ctm_numbers(PDO $db, bool $fresh = false): array {
        require_once __DIR__ . '/SafeCache.php';
        $f = opsh_ctm_cache('numbers'); $j = null;
        if (!$fresh && ($raw = opsh_cache_read($f)) !== null) { $x = json_decode($raw, true); if (is_array($x) && time() - (int)($x['at'] ?? 0) < 300) $j = $x; }
        if ($j === null) {
            $cl = opsh_ctm_client($db); $rows = $cl->numbers();
            $j = ['at' => time(), 'account' => $cl->acct, 'rows' => $rows];
            opsh_cache_write($f, json_encode($j));
        }
        $cfg = opsh_ph_cfg($db, 'ctm'); $lines = array_flip((array)$cfg['lines']);
        foreach ($j['rows'] as &$r) { $d = opsh_ph_digits($r['number'] !== '' ? $r['number'] : $r['formatted']); $r['digits'] = $d; $r['tickets'] = $cfg['on'] && $d !== '' && (!$lines || isset($lines[$d])); $r['show'] = $r['formatted'] !== '' ? $r['formatted'] : opsh_ph_show($r['number']); }
        unset($r);
        usort($j['rows'], fn($a, $b) => [mb_strtolower($a['label'] ?: 'zzz'), $a['number']] <=> [mb_strtolower($b['label'] ?: 'zzz'), $b['number']]);
        return ['rows' => $j['rows'], 'account' => $j['account'], 'read_at' => date('Y-m-d H:i:s', (int)$j['at'])];
    }

    /** True for a call status that counts as missed by default in the report (the missed-call check uses its own list). */
    function opsh_ctm_is_missed(string $st): bool { return in_array($st, ['no answer', 'busy', 'canceled', 'hangup', 'failed'], true); }

    /**
     * The call report of a period: totals, by line, by source, by agent and by day. At most 50 pages (5,000 calls) are
     * read; "truncated" says the period had more, and the figures then cover the first 5,000 only.
     */
    function opsh_ctm_report(PDO $db, string $from, string $to, bool $fresh = false): array {
        require_once __DIR__ . '/SafeCache.php';
        $f = opsh_ctm_cache('calls_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to));
        if (!$fresh && ($raw = opsh_cache_read($f)) !== null) { $x = json_decode($raw, true); if (is_array($x) && time() - (int)($x['at'] ?? 0) < 300) return $x['report'] + ['read_at' => date('Y-m-d H:i:s', (int)$x['at'])]; }
        $cl = opsh_ctm_client($db);
        [$calls, $trunc] = $cl->calls($from, $to, 50);
        $report = opsh_ph_report($calls, 'opsh_ctm_is_missed') + ['from' => $from, 'to' => $to, 'account' => $cl->acct, 'truncated' => $trunc];
        opsh_cache_write($f, json_encode(['at' => time(), 'report' => $report]));
        return $report + ['read_at' => date('Y-m-d H:i:s')];
    }

    /**
     * The missed-call check (the scheduled job every five minutes, or "Check now"). Reads the calls since the last check
     * (with 15 minutes of overlap: a call is looked at once anyway) and hands them to PhoneTickets.
     */
    function opsh_ctm_poll(PDO $db, ?int $now = null): array {
        $now = $now ?? time(); $c = opsh_ph_cfg($db, 'ctm');
        if (!$c['on']) return ['skipped' => 'tickets are switched off'];
        $sys = opsh_sys_get($db, 'ctm');
        if (!$sys || $sys['mode'] !== 'connected') return ['skipped' => 'CallTrackingMetrics is not set to Connected'];
        $since = (int)$c['last_poll'] ? max((int)$c['last_poll'] - 900, $now - 86400) : $now - 3600;
        $res = ['at' => date('Y-m-d H:i:s', $now), 'read' => 0, 'errors' => []];
        try {
            $cl = opsh_ctm_client($db);
            // dates in the account's time zone: one day either side keeps every call of the window in range
            [$calls, $trunc] = $cl->calls(date('Y-m-d', $since - 86400), date('Y-m-d', $now + 86400), 10);
            $res['read'] = count($calls);
            $calls = array_values(array_filter($calls, fn($k) => $k['at'] === '' || strtotime($k['at']) >= $since));
            $res += opsh_ph_take($db, 'ctm', 'CallTrackingMetrics', $calls, $now);
            if ($trunc) $res['errors'][] = 'more calls than one check reads (1,000): the oldest of the window were not looked at';
        } catch (Throwable $e) { $res['errors'][] = $e->getMessage(); }
        $c = opsh_ph_cfg($db, 'ctm');
        if (!$res['errors'] || $res['read'] > 0) $c['last_poll'] = $now;
        $c['last_result'] = $res; opsh_ph_save($db, 'ctm', $c);
        return $res;
    }
}
