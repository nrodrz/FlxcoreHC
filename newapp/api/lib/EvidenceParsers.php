<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT16 evidence parsers. Each parser turns an uploaded/pasted file into normalised observations
 * (or server-metric samples) WITHOUT touching production: it only reads the text it is given.
 *
 * Contract — every parser returns:
 *   ['observations' => [...], 'metrics' => [...], 'read' => n, 'rejected' => n, 'errors' => [first 20], 'notes' => [...],
 *    'period_start' => utc|null, 'period_end' => utc|null]
 * Hard limits: OPSH_EP_MAX_ROWS rows, OPSH_EP_MAX_SECONDS of parse time, 20 MB input (enforced by the route).
 * SQL text is reduced to a digest (literals removed); secrets in commands/URLs are redacted.
 *
 * IMPORTANT: formats were built from vendor documentation (phpMyAdmin CSV, MariaDB information_schema,
 * Apache combined log, crontab, sysstat sar, Power BI / Fabric REST). They must be re-validated against the
 * real production samples as they arrive; header detection is deliberately tolerant and every rejected row
 * is reported, never silently dropped.
 */

require_once __DIR__ . '/IntegrationAudit.php';

if (!function_exists('opsh_ep_catalog')) {

    define('OPSH_EP_MAX_ROWS', 50000);
    define('OPSH_EP_MAX_SECONDS', 20);
    define('OPSH_EP_MAX_BYTES', 20 * 1024 * 1024);

    /** Parser catalog (drives the import form). opts: [key, label, type, required, default, help] */
    function opsh_ep_catalog(): array {
        $tz = ['source_tz', 'Timezone of timestamps in the file', 'tz', false, '', 'Used only when a timestamp carries no offset. Default: server time.'];
        return [
            'db_processlist' => ['label' => 'MariaDB processlist (phpMyAdmin CSV / SHOW PROCESSLIST)', 'source_type' => 'db_process', 'kind' => 'obs',
                'help' => 'Columns: Id, User, Host, db, Command, Time, State, Info. One snapshot = one file. SQL text is reduced to a digest.',
                'opts' => [['captured_at', 'When the snapshot was taken', 'datetime', true, '', 'The processlist has no clock of its own — each row\'s start is estimated as captured_at − Time.'],
                           ['include_sleep', 'Include idle (Sleep) connections', 'bool', false, false, 'Idle connections still show who is connected, but add volume.'], $tz]],
            'db_ps_digest' => ['label' => 'Performance Schema digest summary (CSV)', 'source_type' => 'db_digest', 'kind' => 'obs',
                'help' => 'events_statements_summary_by_digest: SCHEMA_NAME, DIGEST, DIGEST_TEXT, COUNT_STAR, SUM_TIMER_WAIT, SUM_ROWS_EXAMINED, SUM_ROWS_SENT, FIRST_SEEN, LAST_SEEN. Only if Performance Schema is already on.',
                'opts' => [$tz]],
            'db_userstat' => ['label' => 'MariaDB userstat (USER_ / CLIENT_ / TABLE_STATISTICS CSV)', 'source_type' => 'db_userstat', 'kind' => 'obs',
                'help' => 'Cumulative counters since the last FLUSH. Import two snapshots to compare. Only if userstat is already enabled (approval item).',
                'opts' => [['captured_at', 'When the snapshot was taken', 'datetime', true, '', ''], $tz]],
            'http_access' => ['label' => 'Apache / vTiger access log (combined format)', 'source_type' => 'http', 'kind' => 'obs',
                'help' => 'cPanel domlog lines: IP - user [time] "METHOD path HTTP/x" status bytes "referer" "user-agent". Query-string values are dropped.',
                'opts' => [['exclude_static', 'Skip static files (images, css, js, fonts)', 'bool', false, true, ''],
                           ['path_filter', 'Only paths matching (comma-separated, * wildcards)', 'text', false, '', 'e.g. *webservice.php*, *.php, /modules/*'],
                           ['exclude_ips', 'Ignore these IPs (comma-separated)', 'text', false, '', 'e.g. office IPs of staff browsers'], $tz]],
            'cron_inventory' => ['label' => 'Cron inventory (crontab -l / cPanel Cron Jobs)', 'source_type' => 'cron', 'kind' => 'obs',
                'help' => 'One job per line: "m h dom mon dow command" or @daily etc. Tokens and passwords in commands are masked.',
                'opts' => [['captured_at', 'When the list was taken', 'datetime', false, '', 'Default: now'], ['host', 'Account / server this crontab belongs to', 'text', false, '', 'e.g. cPanel user of vTiger']]],
            'sar' => ['label' => 'sysstat sar output (sar -u / -q / -r)', 'source_type' => 'metric', 'kind' => 'metric',
                'help' => 'Plain-text sar for one day. CPU %user/%system/%iowait/%idle, load averages and run queue, memory %used.',
                'opts' => [['date', 'Day the file covers (if the header has no date)', 'date', false, '', ''], ['host', 'Server name', 'text', false, '', ''], $tz]],
            'fabric_history' => ['label' => 'Microsoft Fabric / Power BI run history (JSON or CSV)', 'source_type' => 'fabric', 'kind' => 'obs',
                'help' => 'Refresh history (REST: value[] with startTime/endTime/status) or job instances (startTimeUtc/endTimeUtc), or a CSV with start, end, status, item columns.',
                'opts' => [['integration_id', 'This history belongs to', 'integration', false, '', 'Rows are attributed to it by provenance (the file is its own history). Leave empty if unsure.'],
                           ['item_name', 'Dataset / item name (if the file does not name it)', 'text', false, '', 'Refresh history exported per dataset usually has no name column.'], $tz]],
            'generic_csv' => ['label' => 'Other CSV (map the columns yourself)', 'source_type' => 'other', 'kind' => 'obs',
                'help' => 'For WHM, backup logs or any other export. Map at least the timestamp column.',
                'opts' => [['mode', 'Import as', 'select:observations=Activity observations,metrics=Server metric samples', false, 'observations', ''],
                           ['source_type', 'Activity type', 'select:backup=Backup,http=HTTP / API,db_process=Database session,cron=Scheduled job,other=Other', false, 'other', ''],
                           ['col_time', 'Column: timestamp (start)', 'text', true, '', ''], ['col_end', 'Column: end time', 'text', false, '', ''],
                           ['col_account', 'Column: account / user', 'text', false, '', ''], ['col_host', 'Column: source host / IP', 'text', false, '', ''],
                           ['col_endpoint', 'Column: endpoint / job / path', 'text', false, '', ''], ['col_status', 'Column: status', 'text', false, '', ''],
                           ['col_duration', 'Column: duration (seconds)', 'text', false, '', ''], ['col_bytes', 'Column: bytes', 'text', false, '', ''],
                           ['col_metrics', 'Metrics mode: value columns (comma-separated)', 'text', false, '', 'e.g. cpu,load1,mem_used_pct'], $tz]],
        ];
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    function opsh_ep_text(string $raw): string {
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
        if (strncmp($raw, "\xFF\xFE", 2) === 0 || strncmp($raw, "\xFE\xFF", 2) === 0) $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        return str_replace(["\r\n", "\r"], "\n", $raw);
    }

    function opsh_ep_key(string $h): string { return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($h, " \t\"'`"))), '_'); }

    /** CSV / TSV / semicolon reader with header row; tolerant of quoted multi-line fields. Returns [headers, rows(assoc)]. */
    function opsh_ep_csv(string $text, int $max): array {
        $first = strtok($text, "\n") ?: '';
        $cands = ["\t" => substr_count($first, "\t"), ';' => substr_count($first, ';'), ',' => substr_count($first, ','), '|' => substr_count($first, '|')];
        arsort($cands); $delim = (string)array_key_first($cands); if ($cands[$delim] === 0) $delim = ',';
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $text); rewind($fh);
        $hdr = null; $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
            if ($delim === '|') $r = array_map('trim', $r);
            if ($hdr === null) { $hdr = array_map('opsh_ep_key', $r); continue; }
            if (count($rows) >= $max) break;
            $row = []; foreach ($hdr as $i => $k) if ($k !== '') $row[$k] = isset($r[$i]) ? trim((string)$r[$i]) : '';
            $rows[] = $row;
        }
        fclose($fh);
        return [$hdr ?? [], $rows];
    }

    function opsh_ep_pick(array $row, array $keys, $default = '') { foreach ($keys as $k) if (isset($row[$k]) && $row[$k] !== '' && strtoupper($row[$k]) !== 'NULL') return $row[$k]; return $default; }
    function opsh_ep_int($v): ?int { if ($v === '' || $v === null || !is_numeric(str_replace(',', '', (string)$v))) return null; return (int)round((float)str_replace(',', '', (string)$v)); }

    function opsh_ep_result(): array { return ['observations' => [], 'metrics' => [], 'read' => 0, 'rejected' => 0, 'errors' => [], 'notes' => [], 'period_start' => null, 'period_end' => null]; }
    function opsh_ep_err(array &$R, string $msg): void { $R['rejected']++; if (count($R['errors']) < 20) $R['errors'][] = $msg; }
    function opsh_ep_span(array &$R, ?string $a, ?string $b = null): void {
        foreach ([$a, $b] as $t) if ($t) { if (!$R['period_start'] || $t < $R['period_start']) $R['period_start'] = $t; if (!$R['period_end'] || $t > $R['period_end']) $R['period_end'] = $t; }
    }
    function opsh_ep_over_time(float $t0): bool { return (microtime(true) - $t0) > OPSH_EP_MAX_SECONDS; }

    function opsh_ep_obs(array $base): array {
        return $base + ['ended_at' => null, 'source_ts_raw' => '', 'source_ref' => '', 'account' => '', 'host' => '', 'db_name' => '', 'endpoint' => '',
                        'method' => '', 'status_code' => '', 'user_agent' => '', 'query_digest' => '', 'duration_ms' => null, 'rows_examined' => null,
                        'rows_sent' => null, 'rows_affected' => null, 'bytes' => null, 'meta' => null];
    }

    /* ─────────────────────────── dispatcher ─────────────────────────── */

    function opsh_ep_parse(string $parser, string $raw, array $opt): array {
        $cat = opsh_ep_catalog(); if (!isset($cat[$parser])) throw new RuntimeException('Unknown parser');
        foreach ($cat[$parser]['opts'] as $o) if ($o[3] && trim((string)($opt[$o[0]] ?? '')) === '') throw new RuntimeException('Missing: ' . $o[1]);
        $text = opsh_ep_text($raw);
        $fn = 'opsh_ep_' . $parser;
        $R = $fn($text, $opt + ['source_tz' => '']);
        if ($R['read'] >= OPSH_EP_MAX_ROWS) $R['notes'][] = 'Stopped at the ' . number_format(OPSH_EP_MAX_ROWS) . '-row limit — split the file.';
        return $R;
    }

    /** Captured-at option → UTC (interpreted in the source timezone). */
    function opsh_ep_captured(array $opt, bool $defaultNow = false): ?string {
        $v = trim((string)($opt['captured_at'] ?? ''));
        if ($v === '') return $defaultNow ? gmdate('Y-m-d H:i:s') . '.000' : null;
        return opsh_ia_to_utc(str_replace('T', ' ', $v), (string)$opt['source_tz']);
    }

    /* ─────────────────────────── MariaDB processlist ─────────────────────────── */

    /** Shared by the import parser and the (approval-gated) live sampler. */
    function opsh_ep_processlist_row(array $row, string $capUtc, string $tz): ?array {
        $user = opsh_ep_pick($row, ['user']); $cmd = opsh_ep_pick($row, ['command']);
        if ($user === '' && $cmd === '') return null;
        $time = opsh_ep_int(opsh_ep_pick($row, ['time'], '0')) ?? 0;
        $info = (string)opsh_ep_pick($row, ['info']);
        $digest = opsh_ia_sql_digest($info);
        $host = opsh_ia_host_only((string)opsh_ep_pick($row, ['host']));
        $db = (string)opsh_ep_pick($row, ['db']);
        $start = gmdate('Y-m-d H:i:s', strtotime($capUtc . ' UTC') - max(0, $time)) . '.000';
        $o = opsh_ep_obs(['observed_at' => $start, 'ended_at' => $capUtc, 'source_tz' => $tz, 'source_type' => 'db_process',
            'source_ts_raw' => 'captured ' . substr($capUtc, 0, 19) . 'Z − ' . $time . 's', 'source_ref' => 'processlist id ' . opsh_ep_pick($row, ['id']),
            'account' => $user, 'host' => $host, 'db_name' => $db, 'query_digest' => $digest, 'duration_ms' => $time * 1000,
            'rows_examined' => opsh_ep_int(opsh_ep_pick($row, ['rows_examined'])), 'rows_sent' => opsh_ep_int(opsh_ep_pick($row, ['rows_sent'])),
            'status_code' => mb_substr($cmd, 0, 40),
            'meta' => array_filter(['state' => mb_substr((string)opsh_ep_pick($row, ['state']), 0, 120), 'command' => $cmd, 'time_sec' => $time,
                                    'tables' => $digest ? opsh_ia_sql_tables($digest) : []])]);
        $o['fingerprint'] = opsh_ia_fp(['db_process', opsh_ia_account_only($user), $host, $db, $digest !== '' ? $digest : $cmd]);
        return $o;
    }

    function opsh_ep_db_processlist(string $text, array $opt): array {
        $R = opsh_ep_result(); $t0 = microtime(true);
        $cap = opsh_ep_captured($opt); if (!$cap) throw new RuntimeException('The snapshot time is not a valid date/time');
        $tz = opsh_ia_valid_tz((string)$opt['source_tz']);
        [$hdr, $rows] = opsh_ep_csv($text, OPSH_EP_MAX_ROWS);
        if (!in_array('user', $hdr, true) || !in_array('command', $hdr, true)) throw new RuntimeException('This does not look like a processlist export: columns User and Command are required (found: ' . implode(', ', array_slice($hdr, 0, 10)) . ')');
        $sleep = !empty($opt['include_sleep']) && $opt['include_sleep'] !== 'false';
        foreach ($rows as $i => $row) {
            $R['read']++; if (opsh_ep_over_time($t0)) { $R['notes'][] = 'Stopped: time limit.'; break; }
            if (!$sleep && strcasecmp((string)opsh_ep_pick($row, ['command']), 'Sleep') === 0) { $R['rejected']++; continue; }
            $o = opsh_ep_processlist_row($row, $cap, $tz);
            if (!$o) { opsh_ep_err($R, 'Row ' . ($i + 2) . ': no User/Command'); continue; }
            $R['observations'][] = $o; opsh_ep_span($R, $o['observed_at'], $o['ended_at']);
        }
        if (!$sleep) $R['notes'][] = 'Idle (Sleep) connections were skipped and counted as rejected.';
        return $R;
    }

    /* ─────────────────────────── Performance Schema digests ─────────────────────────── */

    function opsh_ep_db_ps_digest(string $text, array $opt): array {
        $R = opsh_ep_result(); $t0 = microtime(true); $tz = opsh_ia_valid_tz((string)$opt['source_tz']);
        [$hdr, $rows] = opsh_ep_csv($text, OPSH_EP_MAX_ROWS);
        if (!array_intersect(['digest_text', 'digest'], $hdr) || !in_array('count_star', $hdr, true)) throw new RuntimeException('Expected DIGEST_TEXT (or DIGEST) and COUNT_STAR columns');
        foreach ($rows as $i => $row) {
            $R['read']++; if (opsh_ep_over_time($t0)) { $R['notes'][] = 'Stopped: time limit.'; break; }
            $first = opsh_ia_to_utc((string)opsh_ep_pick($row, ['first_seen']), $tz); $last = opsh_ia_to_utc((string)opsh_ep_pick($row, ['last_seen']), $tz);
            if (!$first && !$last) { opsh_ep_err($R, 'Row ' . ($i + 2) . ': FIRST_SEEN / LAST_SEEN missing or invalid'); continue; }
            $digest = opsh_ia_sql_digest((string)opsh_ep_pick($row, ['digest_text']));
            $count = opsh_ep_int(opsh_ep_pick($row, ['count_star'])) ?? 0;
            $wait = (float)str_replace(',', '', (string)opsh_ep_pick($row, ['sum_timer_wait'], '0'));   // picoseconds
            $user = (string)opsh_ep_pick($row, ['user']); $host = (string)opsh_ep_pick($row, ['host']);
            $o = opsh_ep_obs(['observed_at' => $first ?: $last, 'ended_at' => $last ?: $first, 'source_tz' => $tz, 'source_type' => 'db_digest',
                'source_ts_raw' => opsh_ep_pick($row, ['first_seen']) . ' … ' . opsh_ep_pick($row, ['last_seen']),
                'source_ref' => 'digest ' . mb_substr((string)opsh_ep_pick($row, ['digest']), 0, 64), 'account' => $user, 'host' => opsh_ia_host_only($host),
                'db_name' => (string)opsh_ep_pick($row, ['schema_name']), 'query_digest' => $digest,
                'duration_ms' => $wait > 0 ? (int)round($wait / 1e9) : null,
                'rows_examined' => opsh_ep_int(opsh_ep_pick($row, ['sum_rows_examined'])), 'rows_sent' => opsh_ep_int(opsh_ep_pick($row, ['sum_rows_sent'])),
                'rows_affected' => opsh_ep_int(opsh_ep_pick($row, ['sum_rows_affected'])),
                'meta' => ['executions' => $count, 'aggregate' => 'totals between FIRST_SEEN and LAST_SEEN', 'tables' => opsh_ia_sql_tables($digest)]]);
            $o['fingerprint'] = opsh_ia_fp(['db_digest', opsh_ia_account_only($user), $o['db_name'], $digest ?: $o['source_ref']]);
            $o['_no_queue'] = $user === '' && $host === '';     // digest rows without an actor are evidence, not "who" — no triage item
            $R['observations'][] = $o; opsh_ep_span($R, $o['observed_at'], $o['ended_at']);
        }
        return $R;
    }

    /* ─────────────────────────── MariaDB userstat ─────────────────────────── */

    function opsh_ep_userstat_row(array $row, string $cap, string $tz): ?array {
        $kind = isset($row['client']) ? 'client' : (isset($row['table_name']) ? 'table' : (isset($row['user']) ? 'user' : ''));
        if ($kind === '') return null;
        $num = []; foreach ($row as $k => $v) if (is_numeric(str_replace(',', '', (string)$v))) $num[$k] = (float)str_replace(',', '', (string)$v);
        $base = ['observed_at' => $cap, 'source_tz' => $tz, 'source_ts_raw' => 'snapshot ' . substr($cap, 0, 19) . 'Z',
                 'rows_examined' => isset($num['rows_read']) ? (int)$num['rows_read'] : null, 'rows_sent' => isset($num['rows_sent']) ? (int)$num['rows_sent'] : null,
                 'rows_affected' => isset($num['rows_changed']) ? (int)$num['rows_changed'] : (int)(($num['rows_inserted'] ?? 0) + ($num['rows_updated'] ?? 0) + ($num['rows_deleted'] ?? 0)),
                 'bytes' => isset($num['bytes_sent']) ? (int)$num['bytes_sent'] : null, 'meta' => ['cumulative' => true] + array_slice($num, 0, 30)];
        if ($kind === 'table') {
            $o = opsh_ep_obs($base + ['source_type' => 'db_table_stats', 'db_name' => (string)$row['table_schema'], 'endpoint' => $row['table_schema'] . '.' . $row['table_name'], 'source_ref' => 'TABLE_STATISTICS']);
            $o['fingerprint'] = opsh_ia_fp(['db_table_stats', $o['endpoint']]); $o['_no_queue'] = true;
        } elseif ($kind === 'client') {
            $o = opsh_ep_obs($base + ['source_type' => 'db_userstat', 'host' => opsh_ia_host_only((string)$row['client']), 'source_ref' => 'CLIENT_STATISTICS']);
            $o['fingerprint'] = opsh_ia_fp(['db_userstat', 'client', $o['host']]);
        } else {
            $o = opsh_ep_obs($base + ['source_type' => 'db_userstat', 'account' => (string)$row['user'], 'source_ref' => 'USER_STATISTICS']);
            $o['fingerprint'] = opsh_ia_fp(['db_userstat', 'user', opsh_ia_account_only((string)$row['user'])]);
        }
        return $o;
    }

    function opsh_ep_db_userstat(string $text, array $opt): array {
        $R = opsh_ep_result(); $t0 = microtime(true);
        $cap = opsh_ep_captured($opt); if (!$cap) throw new RuntimeException('The snapshot time is not a valid date/time');
        $tz = opsh_ia_valid_tz((string)$opt['source_tz']);
        [$hdr, $rows] = opsh_ep_csv($text, OPSH_EP_MAX_ROWS);
        if (!array_intersect(['user', 'client', 'table_name'], $hdr)) throw new RuntimeException('Expected a USER, CLIENT or TABLE_NAME column (USER_ / CLIENT_ / TABLE_STATISTICS)');
        foreach ($rows as $i => $row) {
            $R['read']++; if (opsh_ep_over_time($t0)) break;
            $o = opsh_ep_userstat_row($row, $cap, $tz);
            if (!$o) { opsh_ep_err($R, 'Row ' . ($i + 2) . ': unrecognised'); continue; }
            $R['observations'][] = $o; opsh_ep_span($R, $cap);
        }
        $R['notes'][] = 'Counters are cumulative since the last FLUSH / restart — compare two snapshots for activity in between.';
        return $R;
    }

    /* ─────────────────────────── Apache access log ─────────────────────────── */

    function opsh_ep_http_access(string $text, array $opt): array {
        $R = opsh_ep_result(); $t0 = microtime(true); $tz = opsh_ia_valid_tz((string)$opt['source_tz']);
        $static = !isset($opt['exclude_static']) || ($opt['exclude_static'] !== false && $opt['exclude_static'] !== 'false' && $opt['exclude_static'] !== '0');
        $filters = array_values(array_filter(array_map('trim', explode(',', (string)($opt['path_filter'] ?? '')))));
        $skipIps = array_values(array_filter(array_map('trim', explode(',', (string)($opt['exclude_ips'] ?? '')))));
        $re = '~^(\S+) \S+ (\S+) \[([^\]]+)\] "(?:(\S+) (\S+)(?: [^"]*)?|[^"]*)" (\d{3}|-) (\d+|-)(?: "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)")?(?: (\d+))?~';
        $n = 0;
        foreach (explode("\n", $text) as $i => $line) {
            if (trim($line) === '') continue;
            if (++$n > OPSH_EP_MAX_ROWS) break;
            $R['read']++; if (($n % 2000) === 0 && opsh_ep_over_time($t0)) { $R['notes'][] = 'Stopped: time limit.'; break; }
            // vhost_combined: "host:port IP - ..." → drop the vhost
            if (preg_match('~^\S+:\d+ (\d{1,3}(?:\.\d{1,3}){3}|[0-9a-f:]+) ~i', $line)) $line = substr($line, strpos($line, ' ') + 1);
            if (!preg_match($re, $line, $m)) { opsh_ep_err($R, 'Line ' . ($i + 1) . ': not in combined/common log format'); continue; }
            $ip = $m[1]; $path = $m[5] ?? '';
            if ($skipIps && in_array($ip, $skipIps, true)) { $R['rejected']++; continue; }
            if ($static && preg_match('~\.(?:png|jpe?g|gif|svg|ico|css|js|mjs|map|woff2?|ttf|eot|webp)(?:\?|$)~i', $path)) { $R['rejected']++; continue; }
            $tpl = opsh_ia_path_template($path);
            if ($filters) { $ok = false; foreach ($filters as $f) if (opsh_ia_glob($f, $tpl) || opsh_ia_glob($f, $path)) { $ok = true; break; } if (!$ok) { $R['rejected']++; continue; } }
            $utc = opsh_ia_to_utc($m[3], $tz, 'd/M/Y:H:i:s O') ?? opsh_ia_to_utc(str_replace(':', ' ', substr($m[3], 0, 11)) . substr($m[3], 11), $tz);
            if (!$utc) { opsh_ep_err($R, 'Line ' . ($i + 1) . ': bad timestamp'); continue; }
            $ua = isset($m[9]) ? stripcslashes($m[9]) : '';
            $o = opsh_ep_obs(['observed_at' => $utc, 'source_tz' => $tz, 'source_type' => 'http', 'source_ts_raw' => $m[3],
                'account' => $m[2] !== '-' ? $m[2] : '', 'host' => $ip, 'method' => strtoupper((string)($m[4] ?? '')), 'endpoint' => opsh_ia_redact($tpl),
                'status_code' => $m[6], 'bytes' => $m[7] === '-' ? null : (int)$m[7], 'user_agent' => mb_substr($ua, 0, 255),
                'meta' => array_filter(['referer_host' => isset($m[8]) ? (string)parse_url($m[8], PHP_URL_HOST) : '', 'trailing' => $m[10] ?? null])]);
            $o['fingerprint'] = opsh_ia_fp(['http', $ip, $o['method'], $tpl]);
            $R['observations'][] = $o; opsh_ep_span($R, $utc);
        }
        return $R;
    }

    /* ─────────────────────────── cron inventory ─────────────────────────── */

    function opsh_ep_cron_inventory(string $text, array $opt): array {
        $R = opsh_ep_result();
        $cap = opsh_ep_captured($opt, true);
        $host = mb_substr(trim((string)($opt['host'] ?? '')), 0, 190);
        foreach (explode("\n", $text) as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || preg_match('/^(SHELL|MAILTO|PATH|HOME|CRON_TZ)\s*=/i', $line)) continue;
            if (preg_match('/^minute\s+hour/i', $line)) continue;            // cPanel table header
            $R['read']++; if ($R['read'] > 2000) break;
            if (preg_match('/^(@\w+)\s+(.+)$/', $line, $m)) { $expr = $m[1]; $cmd = $m[2]; }
            else {
                $p = preg_split('/\s+/', $line, 6);
                if (count($p) < 6) { opsh_ep_err($R, 'Line ' . ($i + 1) . ': not "m h dom mon dow command"'); continue; }
                $expr = implode(' ', array_slice($p, 0, 5)); $cmd = $p[5];
            }
            if (!opsh_ia_cron_parse($expr)) { opsh_ep_err($R, 'Line ' . ($i + 1) . ': schedule "' . $expr . '" not understood'); continue; }
            $red = opsh_ia_redact($cmd);
            $o = opsh_ep_obs(['observed_at' => $cap, 'source_type' => 'cron', 'source_tz' => opsh_ia_valid_tz((string)$opt['source_tz']),
                'source_ts_raw' => 'inventory', 'source_ref' => 'cron ' . $expr, 'host' => $host, 'endpoint' => mb_substr($red, 0, 500),
                'meta' => ['cron_expr' => $expr, 'command' => $red, 'inventory' => true]]);
            $o['_job_text'] = $red;
            $o['fingerprint'] = opsh_ia_fp(['cron', $host, $expr, $red]);
            $R['observations'][] = $o; opsh_ep_span($R, $cap);
        }
        $R['notes'][] = 'A cron inventory records what is SCHEDULED, not what ran. Link each job to an integration, then add it as that integration\'s cron schedule.';
        return $R;
    }

    /* ─────────────────────────── sar ─────────────────────────── */

    function opsh_ep_sar(string $text, array $opt): array {
        $R = opsh_ep_result(); $tz = opsh_ia_valid_tz((string)$opt['source_tz']);
        $host = mb_substr(trim((string)($opt['host'] ?? '')), 0, 120); $date = trim((string)($opt['date'] ?? ''));
        $cols = null; $prevT = null; $dayShift = 0; $cpus = null;
        foreach (explode("\n", $text) as $i => $line) {
            if (count($R['metrics']) >= OPSH_EP_MAX_ROWS) break;
            if (preg_match('~^Linux\s+\S+\s+\(([^)]+)\)\s+(\d{1,4}[/-]\d{1,2}[/-]\d{1,4})~', $line, $m)) {
                if ($host === '') $host = $m[1];
                $d = $m[2];
                if (preg_match('~^(\d{2})/(\d{2})/(\d{2,4})$~', $d, $x)) $d = (strlen($x[3]) === 2 ? '20' . $x[3] : $x[3]) . '-' . $x[1] . '-' . $x[2];
                if ($date === '') $date = $d;
                if (preg_match('~\((\d+) CPU\)~', $line, $c)) $cpus = (int)$c[1];
                continue;
            }
            if (!preg_match('~^(\d{1,2}:\d{2}:\d{2})(?:\s+(AM|PM))?\s+(.*)$~', trim($line), $m)) continue;
            $rest = preg_split('/\s+/', trim($m[3]));
            // header line: contains a % column name or a known header word
            if (array_filter($rest, fn($x) => str_starts_with($x, '%') || in_array(strtolower($x), ['runq-sz', 'ldavg-1', 'kbmemfree', 'cpu'], true))) { $cols = array_map('strtolower', $rest); continue; }
            if (!$cols || count($rest) !== count($cols)) continue;
            if ($date === '') throw new RuntimeException('The sar file has no date header — enter the day it covers.');
            $hms = $m[1]; if (!empty($m[2])) { [$h, $mi, $se] = array_map('intval', explode(':', $hms)); if ($m[2] === 'PM' && $h < 12) $h += 12; if ($m[2] === 'AM' && $h === 12) $h = 0; $hms = sprintf('%02d:%02d:%02d', $h, $mi, $se); }
            if ($prevT !== null && $hms < $prevT) $dayShift++;              // crossed midnight (sar keeps one file per day, but be safe)
            $prevT = $hms;
            $local = date('Y-m-d', strtotime($date . ' +' . $dayShift . ' day')) . ' ' . $hms;
            $utc = opsh_ia_to_utc($local, $tz); if (!$utc) { opsh_ep_err($R, 'Line ' . ($i + 1) . ': bad time'); continue; }
            $utc = substr($utc, 0, 19);
            $row = array_combine($cols, $rest);
            if (isset($row['cpu']) && strtolower($row['cpu']) !== 'all') continue;   // per-core rows: keep only "all"
            $map = ['%user' => ['cpu_user', '%'], '%system' => ['cpu_system', '%'], '%iowait' => ['cpu_iowait', '%'], '%idle' => ['cpu_idle', '%'], '%steal' => ['cpu_steal', '%'],
                    'ldavg-1' => ['load1', ''], 'ldavg-5' => ['load5', ''], 'ldavg-15' => ['load15', ''], 'runq-sz' => ['runq', ''], 'blocked' => ['blocked', ''], '%memused' => ['mem_used_pct', '%']];
            $R['read']++;
            foreach ($map as $k => [$metric, $unit]) if (isset($row[$k]) && is_numeric(str_replace(',', '.', $row[$k])))
                $R['metrics'][] = ['ts' => $utc, 'metric' => $metric, 'value' => (float)str_replace(',', '.', $row[$k]), 'unit' => $unit, 'source' => 'sar', 'host' => $host];
            if (isset($row['%idle'])) $R['metrics'][] = ['ts' => $utc, 'metric' => 'cpu_busy', 'value' => round(100 - (float)str_replace(',', '.', $row['%idle']), 2), 'unit' => '%', 'source' => 'sar', 'host' => $host];
            opsh_ep_span($R, $utc);
        }
        if (!$R['metrics']) throw new RuntimeException('No sar samples found — expected sar -u, -q or -r text output.');
        if ($cpus) $R['notes'][] = 'Server reports ' . $cpus . ' CPUs (load above that number means queuing).';
        return $R;
    }

    /* ─────────────────────────── Fabric / Power BI history ─────────────────────────── */

    function opsh_ep_fabric_history(string $text, array $opt): array {
        $R = opsh_ep_result(); $tz = opsh_ia_valid_tz((string)$opt['source_tz']);
        $rows = [];
        $j = json_decode(trim($text), true);
        if (is_array($j)) { $rows = isset($j['value']) && is_array($j['value']) ? $j['value'] : (array_is_list($j) ? $j : [$j]);
            $rows = array_map(function ($r) { $o = []; foreach ((array)$r as $k => $v) $o[opsh_ep_key((string)$k)] = is_scalar($v) ? (string)$v : json_encode($v); return $o; }, $rows); }
        else { [, $rows] = opsh_ep_csv($text, OPSH_EP_MAX_ROWS); }
        foreach (array_slice($rows, 0, OPSH_EP_MAX_ROWS) as $i => $row) {
            $R['read']++;
            $sRaw = (string)opsh_ep_pick($row, ['starttimeutc', 'start_time_utc', 'starttime', 'start_time', 'start', 'started', 'startdatetime', 'refresh_start']);
            $eRaw = (string)opsh_ep_pick($row, ['endtimeutc', 'end_time_utc', 'endtime', 'end_time', 'end', 'completed', 'finished', 'enddatetime', 'refresh_end']);
            $s = opsh_ia_to_utc($sRaw, $tz); $e = $eRaw !== '' ? opsh_ia_to_utc($eRaw, $tz) : null;
            if (!$s) { opsh_ep_err($R, 'Row ' . ($i + 1) . ': no valid start time'); continue; }
            $item = (string)opsh_ep_pick($row, ['item_name', 'itemname', 'dataset', 'dataset_name', 'semantic_model', 'name', 'artifact_name', 'item', 'itemid', 'datasetid', 'artifact'], trim((string)($opt['item_name'] ?? '')));
            $status = (string)opsh_ep_pick($row, ['status', 'state', 'result']);
            $id = (string)opsh_ep_pick($row, ['requestid', 'request_id', 'id', 'run_id', 'job_instance_id', 'rootactivityid']);
            $type = (string)opsh_ep_pick($row, ['refreshtype', 'refresh_type', 'jobtype', 'job_type', 'invoketype', 'invoke_type', 'type']);
            $o = opsh_ep_obs(['observed_at' => $s, 'ended_at' => $e, 'source_tz' => $tz, 'source_type' => 'fabric', 'source_ts_raw' => $sRaw,
                'source_ref' => mb_substr($id !== '' ? 'run ' . $id : 'run', 0, 190), 'endpoint' => mb_substr($item, 0, 500), 'status_code' => mb_substr($status, 0, 40),
                'account' => (string)opsh_ep_pick($row, ['owner', 'executed_by', 'user', 'invoked_by']),
                'duration_ms' => $e ? max(0, (int)round((strtotime(substr($e, 0, 19) . ' UTC') - strtotime(substr($s, 0, 19) . ' UTC')) * 1000)) : null,
                'meta' => array_filter(['type' => $type, 'gateway' => (string)opsh_ep_pick($row, ['gateway', 'gateway_name']), 'workspace' => (string)opsh_ep_pick($row, ['workspace', 'workspace_name', 'groupid']),
                    'failure' => mb_substr((string)opsh_ep_pick($row, ['failurereason', 'failure_reason', 'serviceexceptionjson', 'error']), 0, 300)])]);
            $o['fingerprint'] = opsh_ia_fp(['fabric', $item, $type]);
            $o['_job_text'] = $item;
            $R['observations'][] = $o; opsh_ep_span($R, $s, $e);
        }
        $R['notes'][] = 'Fabric run records show when Fabric ran — not which database queries it made. DB activity is attributed only by identifiers.';
        return $R;
    }

    /* ─────────────────────────── generic CSV ─────────────────────────── */

    function opsh_ep_generic_csv(string $text, array $opt): array {
        $R = opsh_ep_result(); $tz = opsh_ia_valid_tz((string)$opt['source_tz']); $t0 = microtime(true);
        [$hdr, $rows] = opsh_ep_csv($text, OPSH_EP_MAX_ROWS);
        $col = fn($k) => opsh_ep_key((string)($opt[$k] ?? ''));
        $ct = $col('col_time'); if (!in_array($ct, $hdr, true)) throw new RuntimeException('Timestamp column "' . ($opt['col_time'] ?? '') . '" not found. Columns: ' . implode(', ', array_slice($hdr, 0, 20)));
        $mode = ($opt['mode'] ?? 'observations') === 'metrics' ? 'metrics' : 'observations';
        $stype = in_array($opt['source_type'] ?? '', OPSH_IA_SOURCE_TYPES, true) ? $opt['source_type'] : 'other';
        $mcols = array_values(array_filter(array_map('opsh_ep_key', explode(',', (string)($opt['col_metrics'] ?? '')))));
        if ($mode === 'metrics' && !$mcols) throw new RuntimeException('List the metric value columns');
        foreach ($rows as $i => $row) {
            $R['read']++; if (opsh_ep_over_time($t0)) break;
            $utc = opsh_ia_to_utc((string)($row[$ct] ?? ''), $tz);
            if (!$utc) { opsh_ep_err($R, 'Row ' . ($i + 2) . ': timestamp "' . mb_substr((string)($row[$ct] ?? ''), 0, 40) . '" not understood'); continue; }
            if ($mode === 'metrics') {
                foreach ($mcols as $mc) if (isset($row[$mc]) && is_numeric($row[$mc])) $R['metrics'][] = ['ts' => substr($utc, 0, 19), 'metric' => mb_substr($mc, 0, 40), 'value' => (float)$row[$mc], 'unit' => '', 'source' => 'import', 'host' => ''];
                opsh_ep_span($R, $utc); continue;
            }
            $end = $col('col_end') !== '' ? opsh_ia_to_utc((string)($row[$col('col_end')] ?? ''), $tz) : null;
            $dur = $col('col_duration') !== '' ? opsh_ep_int($row[$col('col_duration')] ?? '') : null;
            $o = opsh_ep_obs(['observed_at' => $utc, 'ended_at' => $end, 'source_tz' => $tz, 'source_type' => $stype, 'source_ts_raw' => (string)$row[$ct],
                'account' => $col('col_account') !== '' ? (string)($row[$col('col_account')] ?? '') : '',
                'host' => $col('col_host') !== '' ? opsh_ia_host_only((string)($row[$col('col_host')] ?? '')) : '',
                'endpoint' => $col('col_endpoint') !== '' ? opsh_ia_redact((string)($row[$col('col_endpoint')] ?? '')) : '',
                'status_code' => $col('col_status') !== '' ? (string)($row[$col('col_status')] ?? '') : '',
                'duration_ms' => $dur !== null ? $dur * 1000 : null,
                'bytes' => $col('col_bytes') !== '' ? opsh_ep_int($row[$col('col_bytes')] ?? '') : null]);
            $o['fingerprint'] = opsh_ia_fp([$stype, opsh_ia_account_only($o['account']), $o['host'], $o['endpoint']]);
            $R['observations'][] = $o; opsh_ep_span($R, $utc, $end);
        }
        return $R;
    }
}
