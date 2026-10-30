<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — the error logs of every cPanel account on this server (BT162)
 *
 * Read through Server Guardian's WHM connection (WHM API 1 uapi_cpanel: Fileman::list_files, Fileman::get_file_content,
 * Stats::get_site_errors), every 10 minutes by the scheduled jobs, at most 10 accounts a run (all of them in turn).
 * Read-only. Only new lines are counted (by their time); the first reading of a file takes its last 7 days. Lines are
 * grouped by kind (level + the message with its numbers and quoted values replaced) per account, file and hour, kept
 * 30 days. Passwords, keys and tokens in a line are masked before it is kept; a file over 8 MB is not read (noted).
 */
if (!function_exists('opsh_errlog_table')) {

    function opsh_errlog_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS opsh_server_errlog (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account VARCHAR(64) NOT NULL, source VARCHAR(190) NOT NULL,
            sig CHAR(40) NOT NULL, level VARCHAR(12) NOT NULL, message TEXT NULL, place VARCHAR(255) NULL, hour DATETIME NOT NULL, n INT NOT NULL DEFAULT 0,
            last_at DATETIME NOT NULL, samples TEXT NULL, UNIQUE KEY uq_errlog (account, source, sig, hour), INDEX idx_errlog_hour (hour), INDEX idx_errlog_last (last_at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    function opsh_errlog_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM opsh_server_errlog LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
    function opsh_errlog_cfg(PDO $db): array {
        try { $q = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $q->execute(['server_errlog']); $j = json_decode((string)$q->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        return (is_array($j) ? $j : []) + ['enabled' => true, 'last_run' => 0, 'read_at' => 0, 'accounts' => [], 'list' => [], 'list_at' => 0, 'cursor' => 0, 'note' => '', 'purge' => 0];
    }
    function opsh_errlog_save(PDO $db, array $c): void {
        $db->prepare('INSERT INTO opsh_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['server_errlog', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** A WHM API 1 call (tests point OPSH_WHM_STUB at a stand-in). */
    function opsh_errlog_whm(array $conn, string $fn, array $params, int $timeout = 10): array {
        if (defined('OPSH_WHM_STUB')) {
            $ch = curl_init(OPSH_WHM_STUB . '/json-api/' . $fn . '?' . http_build_query(array_merge(['api.version' => 1], $params)));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout]); $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $j = is_string($b) ? json_decode($b, true) : null; if ($code >= 400 || !is_array($j)) return ['ok' => false, 'data' => [], 'error' => 'HTTP ' . $code];
            $meta = $j['metadata'] ?? []; $ok = isset($meta['result']) ? (int)$meta['result'] === 1 : true;
            return ['ok' => $ok, 'data' => $j['data'] ?? [], 'error' => $ok ? null : ($meta['reason'] ?? 'API error')];
        }
        require_once __DIR__ . '/Guardian.php';
        return opsh_whm_call($conn, $fn, $params, $timeout);
    }
    /** A cPanel UAPI function run as one account, through WHM. ['ok', 'data', 'error']. */
    function opsh_errlog_uapi(array $conn, string $user, string $module, string $func, array $args = []): array {
        $r = opsh_errlog_whm($conn, 'uapi_cpanel', array_merge(['cpanel.user' => $user, 'cpanel.module' => $module, 'cpanel.function' => $func], $args), 15);
        if (!$r['ok']) return ['ok' => false, 'data' => null, 'error' => (string)$r['error']];
        $u = $r['data']['uapi'] ?? $r['data'];
        if (is_array($u) && array_key_exists('status', $u) && (int)$u['status'] !== 1) { $e = $u['errors'] ?? null; return ['ok' => false, 'data' => null, 'error' => is_array($e) ? implode(' ', $e) : (string)($e ?: 'refused')]; }
        return ['ok' => true, 'data' => is_array($u) && array_key_exists('data', $u) ? $u['data'] : $u, 'error' => null];
    }

    /** Mask what must not be kept: passwords, keys, tokens, long numbers (cards, accounts). */
    function opsh_errlog_mask(string $s): string {
        $s = preg_replace('/((?:pass(?:word|wd)?|pwd|secret|token|api_?key|access_?key|auth)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1****', $s);
        $s = preg_replace('/\b\d{12,19}\b/', '****', $s);
        return mb_substr($s, 0, 1000);
    }
    /** The kind of a message: its numbers, hex ids and quoted values replaced, so repeats group together. */
    function opsh_errlog_norm(string $m): string {
        $m = preg_replace("/'[^']{0,200}'|\"[^\"]{0,200}\"/", '…', $m);
        $m = preg_replace('/\b0x[0-9a-f]+\b|\b[0-9a-f]{16,}\b/i', '#', $m);
        $m = preg_replace('/\d+/', '#', $m);
        return mb_substr(trim(preg_replace('/\s+/', ' ', $m)), 0, 300);
    }
    function opsh_errlog_level(string $l): string {
        $l = strtolower($l);
        if (preg_match('/fatal|parse|uncaught|emerg|alert|crit|recoverable/', $l)) return 'fatal';
        if (preg_match('/warn/', $l)) return 'warning';
        if (preg_match('/notice|deprecated|strict|info|debug/', $l)) return 'notice';
        return 'error';
    }
    /** "message in /path/file.php on line 12" / "file.php:12" → [message without the place, place]. */
    function opsh_errlog_place(string $m): array {
        if (preg_match('~^(.*?)\s+in\s+(/\S+?)(?:\s+on line\s+|:)(\d+)\s*$~s', $m, $x)) return [trim($x[1]), $x[2] . ':' . $x[3]];
        if (preg_match('~^(.*?)\s+in\s+(/\S+?):(\d+)\b~s', $m, $x)) return [trim($x[1]), $x[2] . ':' . $x[3]];
        return [$m, ''];
    }
    /**
     * The PHP error log format: "[29-Oct-2026 14:05:12 America/New_York] PHP Fatal error:  message in /file on line 3".
     * Lines after a line with a time (a stack trace) belong to it. Returns events after $since: [ts, level, message, place, raw].
     */
    function opsh_errlog_parse_php(string $text, int $since): array {
        $out = []; $cur = null;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (preg_match('~^\[(\d{1,2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z_/+\-0-9]+))?\]\s*(.*)$~', $line, $m)) {
                if ($cur) $out[] = $cur; $cur = null;
                try { $dt = new DateTime($m[1], new DateTimeZone($m[2] ?? '' ?: date_default_timezone_get())); $ts = $dt->getTimestamp(); } catch (Throwable $e) { $ts = strtotime($m[1]) ?: 0; }
                if ($ts <= $since) continue;
                $body = $m[3]; $lvl = 'error';
                if (preg_match('/^(?:PHP\s+)?([A-Za-z][A-Za-z ]{1,40}?):\s+(.*)$/s', $body, $b)) { $lvl = opsh_errlog_level($b[1]); $body = $b[2]; }
                [$msg, $place] = opsh_errlog_place($body);
                $cur = [$ts, $lvl, $msg, $place, $line];
            } elseif ($cur && trim($line) !== '' && strlen($cur[4]) < 1500) {
                $cur[4] .= "\n" . $line;
            }
        }
        if ($cur) $out[] = $cur;
        return $out;
    }
    /** The web server's error log lines of the account (Stats::get_site_errors): "[Thu Oct 29 14:05:12.12 2026] [module:level] … message". */
    function opsh_errlog_parse_apache(array $entries, int $since): array {
        $out = [];
        foreach ($entries as $e) {
            $line = is_array($e) ? (string)($e['entry'] ?? $e['line'] ?? $e['message'] ?? '') : (string)$e; if ($line === '') continue;
            $ts = is_array($e) && isset($e['date']) && is_numeric($e['date']) ? (int)$e['date'] : 0;
            if (!$ts && preg_match('~^\[(?:[A-Za-z]{3} )?([A-Za-z]{3} \d{1,2} \d{2}:\d{2}:\d{2})(?:\.\d+)? (\d{4})\]~', $line, $m)) $ts = strtotime($m[1] . ' ' . $m[2]) ?: 0;
            if ($ts <= $since) continue;
            $lvl = 'error'; if (preg_match('~\]\s*\[[a-z_0-9]*:([a-z0-9]+)\]~i', $line, $m)) $lvl = opsh_errlog_level($m[1]);
            $msg = preg_replace('~^(\s*\[[^\]]*\])+\s*~', '', $line);
            if (preg_match("~PHP message: PHP ([A-Za-z ]+?):\s+(.*?)(?:'|\\\\n|$)~", $msg, $p)) { $lvl = opsh_errlog_level($p[1] . ' error'); $msg = $p[2]; }
            [$msg, $place] = opsh_errlog_place($msg);
            $out[] = [$ts, $lvl, $msg, $place, $line];
        }
        return $out;
    }
    /** Add events to the hourly groups. */
    function opsh_errlog_store(PDO $db, string $acct, string $source, array $events): int {
        $agg = [];
        foreach ($events as [$ts, $lvl, $msg, $place, $raw]) {
            $msg = opsh_errlog_mask((string)$msg); $sig = sha1($lvl . '|' . opsh_errlog_norm($msg) . '|' . $place);
            $h = date('Y-m-d H:00:00', $ts); $k = $sig . '|' . $h;
            if (!isset($agg[$k])) $agg[$k] = ['sig' => $sig, 'hour' => $h, 'lvl' => $lvl, 'msg' => $msg, 'place' => mb_substr($place, 0, 255), 'n' => 0, 'last' => 0, 'raw' => []];
            $agg[$k]['n']++; $agg[$k]['last'] = max($agg[$k]['last'], $ts); $agg[$k]['raw'][] = opsh_errlog_mask(mb_substr((string)$raw, 0, 1500)); $agg[$k]['raw'] = array_slice($agg[$k]['raw'], -3);
        }
        $q = $db->prepare('INSERT INTO opsh_server_errlog (account, source, sig, level, message, place, hour, n, last_at, samples) VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE n = n + VALUES(n), last_at = GREATEST(last_at, VALUES(last_at)), message = VALUES(message), samples = VALUES(samples)');
        foreach ($agg as $a) $q->execute([$acct, mb_substr($source, 0, 190), $a['sig'], $a['lvl'], mb_substr($a['msg'], 0, 2000), $a['place'], $a['hour'], $a['n'], date('Y-m-d H:i:s', $a['last']), json_encode($a['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return count($events);
    }

    /**
     * Read the error logs of the accounts (10 a run, in turn). $force ignores the 10-minute pace ("Read now").
     * Returns ['read' => accounts read, 'events' => new lines, 'note' => …] or ['skipped' => why].
     */
    function opsh_errlog_collect(PDO $db, bool $force = false, ?int $now = null): array {
        $now = $now ?? time(); $c = opsh_errlog_cfg($db);
        if (!$force && $now - (int)$c['last_run'] < 600) return ['skipped' => 'read less than 10 minutes ago'];
        try { $lk = $db->query("SELECT GET_LOCK('opsh_errlog', 0)")->fetchColumn(); } catch (Throwable $e) { $lk = 1; }
        if (!$lk) return ['skipped' => 'another reading is running'];
        try {
            opsh_errlog_table($db); $c['last_run'] = $now; opsh_errlog_save($db, $c);
            require_once __DIR__ . '/Guardian.php';
            $conn = defined('OPSH_WHM_STUB') ? ['host' => 'stub', 'port' => 0, 'user' => 'root', 'token' => 'x'] : opsh_whm_conn(opsh_guardian_settings($db));
            if (!$conn) { $c['note'] = 'The error logs are read through Server Guardian\'s WHM connection, which is not set up (Server Guardian → Configure server checks).'; opsh_errlog_save($db, $c); return ['read' => 0, 'events' => 0, 'note' => $c['note']]; }
            // the accounts (every 6 hours): user, main domain, home folder
            if (!$c['list'] || $now - (int)$c['list_at'] > 21600 || $force) {
                $r = opsh_errlog_whm($conn, 'listaccts', [], 15);
                if (!$r['ok']) { $c['note'] = 'WHM did not list the accounts: ' . $r['error']; opsh_errlog_save($db, $c); return ['read' => 0, 'events' => 0, 'note' => $c['note']]; }
                $list = []; foreach ((array)($r['data']['acct'] ?? []) as $a) if (!empty($a['user'])) $list[] = ['user' => (string)$a['user'], 'domain' => (string)($a['domain'] ?? ''), 'home' => '/' . trim((string)($a['partition'] ?? 'home'), '/') . '/' . $a['user'], 'suspended' => !empty($a['suspended'])];
                usort($list, fn($x, $y) => strcmp($x['user'], $y['user'])); $c['list'] = $list; $c['list_at'] = $now;
            }
            $list = array_values(array_filter($c['list'], fn($a) => !$a['suspended']));
            $n = count($list); $take = $force ? $n : min($n, 10); $start = $n ? ((int)$c['cursor'] % $n) : 0; $read = 0; $events = 0; $t0 = microtime(true); $fails = [];
            for ($i = 0; $i < $take; $i++) {
                if (!$force && microtime(true) - $t0 > 25) break;
                $a = $list[($start + $i) % $n]; $u = $a['user']; $st = $c['accounts'][$u] ?? ['files' => [], 'apache_ts' => 0];
                $st['domain'] = $a['domain']; $st['note'] = ''; $firstSince = $now - 7 * 86400;
                // 1. the PHP error logs: ~/logs/*error.log (one per PHP-FPM site) and the document root's error_log
                $files = [];
                foreach (['logs' => '/\\.?error\\.log$|error_log$/', 'public_html' => '/^error_log$/'] as $dir => $re) {
                    $lr = opsh_errlog_uapi($conn, $u, 'Fileman', 'list_files', ['dir' => $a['home'] . '/' . $dir, 'types' => 'file']);
                    if (!$lr['ok']) { if (!preg_match('/not exist|no such|does not/i', (string)$lr['error'])) $fails[$u] = $lr['error']; continue; }
                    foreach ((array)$lr['data'] as $f) { $fn = (string)($f['file'] ?? $f['name'] ?? ''); if ($fn === '' || !preg_match($re, $fn)) continue;
                        $files[$dir . '/' . $fn] = ['dir' => $a['home'] . '/' . $dir, 'file' => $fn, 'size' => (int)($f['size'] ?? 0), 'mtime' => (int)($f['mtime'] ?? 0)]; }
                }
                $keep = [];
                foreach ($files as $rel => $f) {
                    $p = $st['files'][$rel] ?? null; $since = $p ? (int)$p['last_ts'] : $firstSince;
                    $keep[$rel] = ['size' => $f['size'], 'mtime' => $f['mtime'], 'last_ts' => $since, 'note' => ''];
                    if ($p && (int)$p['size'] === $f['size'] && (int)$p['mtime'] === $f['mtime']) { $keep[$rel]['note'] = $p['note'] ?? ''; continue; }   // unchanged
                    if ($f['size'] > 8 * 1048576) { $keep[$rel]['note'] = 'Too big to read through WHM (' . round($f['size'] / 1048576, 1) . ' MB): empty or rotate it, then it is read again.'; continue; }
                    $gr = opsh_errlog_uapi($conn, $u, 'Fileman', 'get_file_content', ['dir' => $f['dir'], 'file' => $f['file']]);
                    if (!$gr['ok']) { $keep[$rel]['note'] = 'Could not be read: ' . mb_substr((string)$gr['error'], 0, 160); continue; }
                    $text = is_array($gr['data']) ? (string)($gr['data']['content'] ?? '') : (string)$gr['data'];
                    $ev = opsh_errlog_parse_php($text, $since);
                    if ($ev) { $events += opsh_errlog_store($db, $u, $rel, $ev); $keep[$rel]['last_ts'] = max(array_column($ev, 0)); }
                }
                $st['files'] = $keep;
                // 2. the web server's error log lines of the account's sites
                $ar = opsh_errlog_uapi($conn, $u, 'Stats', 'get_site_errors', ['log' => 'error', 'maxlines' => 300]);
                if ($ar['ok']) { $ev = opsh_errlog_parse_apache((array)$ar['data'], max((int)$st['apache_ts'], $firstSince)); if ($ev) { $events += opsh_errlog_store($db, $u, 'web server', $ev); $st['apache_ts'] = max(array_column($ev, 0)); } }
                elseif (!isset($fails[$u])) $st['note'] = 'The web server\'s error lines could not be read: ' . mb_substr((string)$ar['error'], 0, 160);
                if (isset($fails[$u])) $st['note'] = 'WHM refused to read this account\'s files: ' . mb_substr((string)$fails[$u], 0, 200) . ' (the WHM token needs the right to act as the accounts).';
                $st['read_at'] = $now; $c['accounts'][$u] = $st; $read++;
            }
            $c['cursor'] = $start + $read; $c['read_at'] = $now;
            $c['note'] = $n && count($fails) === $read && $read ? 'WHM refused to read the accounts\' files (' . mb_substr((string)reset($fails), 0, 160) . '). The WHM token of Server Guardian needs the right to act as the accounts (a root token, or "all" privileges).' : '';
            $c['accounts'] = array_intersect_key($c['accounts'], array_flip(array_column($c['list'], 'user')));
            if ($now - (int)$c['purge'] > 86400) { $db->prepare('DELETE FROM opsh_server_errlog WHERE hour < ?')->execute([date('Y-m-d H:00:00', $now - 30 * 86400)]); $c['purge'] = $now; }
            opsh_errlog_save($db, $c);
            return ['read' => $read, 'events' => $events, 'note' => $c['note']];
        } finally { try { $db->query("SELECT RELEASE_LOCK('opsh_errlog')"); } catch (Throwable $e) {} }
    }

    /** The groups of a period (hours back from now) and the accounts' state, for the page. */
    function opsh_errlog_summary(PDO $db, int $hours, ?int $now = null): array {
        $now = $now ?? time(); $c = opsh_errlog_cfg($db); $from = date('Y-m-d H:00:00', $now - $hours * 3600);
        $g = []; $lv = ['fatal' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0]; $byAcc = [];
        if (opsh_errlog_ready($db)) {
            $q = $db->prepare('SELECT account, source, sig, level, MAX(message) message, MAX(place) place, SUM(n) n, MAX(last_at) last_at, MIN(hour) first_hour FROM opsh_server_errlog WHERE hour >= ? GROUP BY account, source, sig, level ORDER BY MAX(last_at) DESC LIMIT 500');
            $q->execute([$from]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $s = $db->prepare('SELECT samples FROM opsh_server_errlog WHERE account=? AND source=? AND sig=? ORDER BY hour DESC LIMIT 1'); $s->execute([$r['account'], $r['source'], $r['sig']]);
                $r['samples'] = json_decode((string)$s->fetchColumn(), true) ?: []; $r['n'] = (int)$r['n'];
                $g[] = $r; $lv[$r['level']] = ($lv[$r['level']] ?? 0) + $r['n'];
                $b = &$byAcc[$r['account']]; $b[$r['level']] = ($b[$r['level']] ?? 0) + $r['n']; $b['last'] = max($b['last'] ?? '', $r['last_at']); unset($b);
            }
        }
        $accts = [];
        foreach ($c['list'] as $a) { $st = $c['accounts'][$a['user']] ?? null;
            $accts[] = ['user' => $a['user'], 'domain' => $a['domain'], 'suspended' => $a['suspended'], 'read_at' => $st['read_at'] ?? null, 'note' => $st['note'] ?? '',
                        'files' => array_map(fn($k, $v) => ['file' => $k, 'size' => $v['size'], 'mtime' => $v['mtime'], 'note' => $v['note'] ?? ''], array_keys($st['files'] ?? []), array_values($st['files'] ?? [])),
                        'counts' => $byAcc[$a['user']] ?? new stdClass()]; }
        return ['hours' => $hours, 'groups' => $g, 'levels' => $lv, 'accounts' => $accts, 'read_at' => $c['read_at'] ?: null, 'note' => $c['note']];
    }
    /** The short count for the Status tab: serious errors (fatal + error) in the last hour and the last 24 hours. */
    function opsh_errlog_brief(PDO $db, ?int $now = null): array {
        $now = $now ?? time(); $c = opsh_errlog_cfg($db); $o = ['hour' => null, 'day' => null, 'accounts' => 0, 'read_at' => $c['read_at'] ?: null, 'note' => $c['note']];
        if (!opsh_errlog_ready($db) || !$c['read_at']) return $o;
        $q = $db->prepare("SELECT COALESCE(SUM(CASE WHEN last_at >= ? THEN n ELSE 0 END),0), COALESCE(SUM(n),0), COUNT(DISTINCT account) FROM opsh_server_errlog WHERE hour >= ? AND level IN ('fatal','error')");
        $q->execute([date('Y-m-d H:i:s', $now - 3600), date('Y-m-d H:00:00', $now - 86400)]); [$h, $d, $a] = $q->fetch(PDO::FETCH_NUM);
        return ['hour' => (int)$h, 'day' => (int)$d, 'accounts' => (int)$a] + $o;
    }
}
