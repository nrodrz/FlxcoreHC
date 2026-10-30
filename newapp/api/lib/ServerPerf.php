<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT155 — Server performance: what the server this app runs on is doing, minute by minute, and why.
 *
 *   Sample   every run of the scheduled jobs (cron-uptime.php every minute when it is set up, else cron-sla.php every
 *            five minutes): CPU use over the interval and over one second now, iowait and steal, load, memory, swap,
 *            processes; the busiest processes and accounts in the interval (CPU time per process from /proc); who was
 *            asking the web server (Apache's own status page, read from inside the server: visitor IP, site, page);
 *            MySQL threads and queries. Nothing is installed on the server and nothing needs root or SSH.
 *   Kept     itops_server_perf, one row per sample; the "who" part as a small JSON. Removed after the days kept (30).
 *   Shown    IT Operations → Servers → Server performance: a time graph with the threshold, the spikes as periods,
 *            and for each sample or spike the accounts, processes, visitors, sites and pages behind it.
 *   Limits   a server that hides other accounts' processes (hidepid / CageFS) shows only this account's; a server
 *            without Apache's status page (LiteSpeed, nginx) shows no visitors. Each limit is said on the page.
 *   Private  command lines are cut and any password, key or token in them is masked before they are kept.
 */
if (!function_exists('itops_sp_table')) {

    function itops_sp_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_server_perf (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, interval_s INT NOT NULL DEFAULT 0,
            cpu_pct DECIMAL(5,1) NULL, cpu_now DECIMAL(5,1) NULL, iowait_pct DECIMAL(5,1) NULL, steal_pct DECIMAL(5,1) NULL,
            load1 DECIMAL(7,2) NULL, load5 DECIMAL(7,2) NULL, load15 DECIMAL(7,2) NULL, cores SMALLINT NOT NULL DEFAULT 1,
            mem_total BIGINT NULL, mem_avail BIGINT NULL, swap_total BIGINT NULL, swap_free BIGINT NULL, procs_total INT NULL, procs_running INT NULL,
            web_busy INT NULL, web_idle INT NULL, web_rps DECIMAL(9,2) NULL, db_running INT NULL, db_connected INT NULL, db_qps DECIMAL(9,2) NULL,
            detail MEDIUMTEXT NULL, INDEX idx_sp_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        itops_sp_ensure_cols($db);   // BT158
    }
    function itops_sp_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_server_perf LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    function itops_sp_cfg(PDO $db): array {
        try { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute(['server_perf']); $j = json_decode((string)$q->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        return (is_array($j) ? $j : []) + ['cpu_alert' => 75, 'mem_alert' => 90, 'alert' => false, 'alert_after' => 3, 'keep_days' => 30, 'db_user' => '', 'db_pass' => '', 'geo' => false, 'geo_token' => '', 'daily_keep' => 1095, 'state' => [], 'last_at' => 0, 'last_purge' => 0, 'over' => 0, 'alerted' => false, 'notes' => []];
    }
    function itops_sp_save(PDO $db, array $c): void {
        $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['server_perf', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** A file of /proc, or null when this server does not let PHP read it (open_basedir, a jail). */
    function itops_sp_read(string $f): ?string {
        $base = defined('ITOPS_SP_PROC') ? ITOPS_SP_PROC : '/proc';   // tests point this at a copy
        $p = $base . substr($f, 5);
        $s = @file_get_contents($p);
        return $s === false ? null : $s;
    }

    /** CPU counters: [total, idle (with iowait), iowait, steal] in clock ticks. */
    function itops_sp_cpu(): ?array {
        $s = itops_sp_read('/proc/stat'); if ($s === null || !preg_match('/^cpu\s+([\d\s]+)$/m', $s, $m)) return null;
        $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
        $v = array_pad($v, 8, 0);
        return [array_sum(array_slice($v, 0, 8)), $v[3] + $v[4], $v[4], $v[7]];
    }
    function itops_sp_pct(?array $a, ?array $b): ?array {
        if (!$a || !$b) return null; $t = $b[0] - $a[0]; if ($t <= 0) return null;
        return ['cpu' => round(max(0, min(100, 100 * (1 - ($b[1] - $a[1]) / $t))), 1), 'iowait' => round(max(0, 100 * ($b[2] - $a[2]) / $t), 1), 'steal' => round(max(0, 100 * ($b[3] - $a[3]) / $t), 1)];
    }
    function itops_sp_cores(): int { $s = itops_sp_read('/proc/cpuinfo'); $n = $s === null ? 0 : preg_match_all('/^processor\s*:/m', $s); return max(1, (int)$n); }

    /** Mask what must not be kept from a command line: passwords, keys, tokens. */
    function itops_sp_mask(string $c): string {
        $c = preg_replace('/(\s-p)(\S+)/', '$1****', $c);
        $c = preg_replace('/((?:pass(?:word)?|pwd|secret|token|key|auth|apikey|api_key)[=:])\S+/i', '$1****', $c);
        $c = preg_replace('/(--(?:pass(?:word)?|secret|token|key)\s+)\S+/i', '$1****', $c);
        return mb_substr(trim(preg_replace('/\s+/', ' ', $c)), 0, 140);
    }

    /** Every process this account can see: pid → [uid, ticks (user + system), rss bytes, name, command]. */
    function itops_sp_procs(): array {
        $base = defined('ITOPS_SP_PROC') ? ITOPS_SP_PROC : '/proc'; $out = [];
        $dirs = @scandir($base); if (!$dirs) return [];
        $page = 4096;
        foreach ($dirs as $d) {
            if (!ctype_digit($d)) continue;
            $st = @file_get_contents("$base/$d/stat"); if ($st === false) continue;
            $r = strrpos($st, ')'); if ($r === false) continue;
            $name = substr($st, strpos($st, '(') + 1, $r - strpos($st, '(') - 1);
            $f = preg_split('/\s+/', trim(substr($st, $r + 2)));   // fields from 3 (state) on
            $ticks = (int)($f[11] ?? 0) + (int)($f[12] ?? 0);      // utime (14) + stime (15)
            $rss = (int)($f[21] ?? 0) * $page;                      // rss (24) in pages
            $uid = null; $sts = @file_get_contents("$base/$d/status"); if ($sts !== false && preg_match('/^Uid:\s+(\d+)/m', $sts, $m)) $uid = (int)$m[1];
            $cmd = @file_get_contents("$base/$d/cmdline"); $cmd = $cmd === false ? '' : str_replace("\0", ' ', $cmd);
            $out[(int)$d] = [$uid, $ticks, $rss, mb_substr($name, 0, 40), itops_sp_mask($cmd !== '' ? $cmd : '[' . $name . ']')];
        }
        return $out;
    }
    function itops_sp_user(?int $uid): string {
        static $c = []; if ($uid === null) return '?';
        if (!isset($c[$uid])) { $n = function_exists('posix_getpwuid') ? (@posix_getpwuid($uid)['name'] ?? '') : ''; $c[$uid] = $n !== '' ? $n : 'uid ' . $uid; }
        return $c[$uid];
    }

    /** Apache's status page from inside the server: totals and the requests of each worker. Null when there is none. */
    /** BT156: the addresses tried for Apache's status page, in order; the one that answers is remembered. */
    function itops_sp_web_urls(): array {
        if (defined('ITOPS_SP_STATUS_URL')) return [ITOPS_SP_STATUS_URL];
        // BT160: when NGINX sits in front of Apache (cPanel's ea-nginx), Apache itself listens on 81 (and 444 for https); 8080 for other proxies
        return ['http://127.0.0.1/whm-server-status', 'http://localhost/whm-server-status', 'https://127.0.0.1/whm-server-status', 'http://127.0.0.1/server-status',
                'http://127.0.0.1:81/whm-server-status', 'https://127.0.0.1:444/whm-server-status', 'http://127.0.0.1:8080/whm-server-status'];
    }
    /** GET a local address: [HTTP code (0 = no answer), body]. Only this server is asked; a certificate is not checked (it is the server itself). */
    function itops_sp_get(string $u): array {
        if (!function_exists('curl_init')) return [0, null]; $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
        $r = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$st, is_string($r) ? $r : null];
    }
    /**
     * Apache's status page from inside the server: totals and the requests of each worker, or ['error' => what each
     * address answered]. $known: the address that answered last time (tried first, alone, while it keeps answering).
     */
    function itops_sp_web(?string $known = null): array {
        $urls = itops_sp_web_urls(); if ($known && in_array($known, $urls, true)) $urls = array_values(array_unique(array_merge([$known], $urls)));
        $tried = []; $url = null; $auto = null;
        foreach ($urls as $u) {
            [$st, $b] = itops_sp_get($u . '?auto');
            if ($st === 200 && $b !== null && stripos($b, 'BusyWorkers') !== false) { $url = $u; $auto = $b; break; }
            $tried[] = preg_replace('~^https?://~', '', $u) . ': ' . ($st === 0 ? 'no answer' : 'HTTP ' . $st . ($st === 403 ? ' (refused)' : ($st === 404 ? ' (no such page)' : ($st >= 300 && $st < 400 ? ' (redirected)' : ($st === 200 ? ' (not a status page)' : '')))));
        }
        if ($url === null) return ['error' => implode('; ', $tried)];
        $get = fn(string $u) => (function ($r) { return $r[0] === 200 ? $r[1] : null; })(itops_sp_get($u));
        $t = []; foreach (preg_split('/\r?\n/', $auto) as $l) if (preg_match('/^([A-Za-z ]+):\s*(.+)$/', $l, $m)) $t[trim($m[1])] = trim($m[2]);
        $rows = [];
        $html = $get($url);
        if ($html !== null && preg_match('~<table[^>]*>\s*<tr>\s*<th>Srv</th>(.*?)</table>~is', $html, $tm)) {
            preg_match_all('~<tr[^>]*>(.*?)</tr>~is', '<tr><th>Srv</th>' . $tm[1], $trs);
            $head = null;
            foreach ($trs[1] as $tr) {
                preg_match_all('~<t[hd][^>]*>(.*?)</t[hd]>~is', $tr, $cells); $cells = array_map(fn($x) => html_entity_decode(trim(strip_tags($x)), ENT_QUOTES), $cells[1]);
                if ($head === null) { $head = array_flip($cells); continue; }
                $g = fn($k) => isset($head[$k]) ? (string)($cells[$head[$k]] ?? '') : '';
                $client = $g('Client'); $req = $g('Request'); $mode = $g('M');
                if ($client === '' && $req === '') continue;
                $rows[] = ['mode' => $mode, 'client' => mb_substr($client, 0, 64), 'vhost' => mb_substr(preg_replace('/:\d+$/', '', $g('VHost')), 0, 120), 'request' => mb_substr($req, 0, 300), 'ss' => (int)$g('SS'), 'cpu' => (float)$g('CPU')];
            }
        }
        return ['url' => $url, 'kbytes' => isset($t['Total kBytes']) ? (int)$t['Total kBytes'] : null, 'uptime' => isset($t['ServerUptimeSeconds']) ? (int)$t['ServerUptimeSeconds'] : (isset($t['Uptime']) ? (int)$t['Uptime'] : null),
                'busy' => isset($t['BusyWorkers']) ? (int)$t['BusyWorkers'] : null, 'idle' => isset($t['IdleWorkers']) ? (int)$t['IdleWorkers'] : null,
                'accesses' => isset($t['Total Accesses']) ? (int)$t['Total Accesses'] : null, 'rows' => $rows];
    }

    /** The page of a request line, without the query string ("GET /wp-login.php HTTP/1.1" → "GET /wp-login.php"). */
    function itops_sp_page(string $r): string { if (!preg_match('~^([A-Z]+)\s+(\S+)~', $r, $m)) return mb_substr($r, 0, 80); return $m[1] . ' ' . mb_substr(strtok($m[2], '?'), 0, 120); }

    /** The top $n of a count map as [[key, count], …]. */
    function itops_sp_top(array $m, int $n = 8): array { arsort($m); $o = []; foreach (array_slice($m, 0, $n, true) as $k => $v) $o[] = [(string)$k, is_float($v) ? round($v, 1) : $v]; return $o; }

    // ───────────────────────────── BT158: disks, network, mounts, uptime, accounts' domains, the database's queries ─────────────────────────────

    /** The numeric columns added by BT158 (kept per sample for the small charts). */
    function itops_sp_cols158(): array {
        return ['mem_free' => 'BIGINT NULL', 'mem_cached' => 'BIGINT NULL', 'mem_buffers' => 'BIGINT NULL', 'net_rx_kbps' => 'DECIMAL(12,1) NULL', 'net_tx_kbps' => 'DECIMAL(12,1) NULL',
                'disk_read_kbps' => 'DECIMAL(12,1) NULL', 'disk_write_kbps' => 'DECIMAL(12,1) NULL', 'disk_util' => 'DECIMAL(5,1) NULL', 'disk_space_pct' => 'DECIMAL(5,1) NULL',
                'web_kbps' => 'DECIMAL(12,1) NULL', 'db_slow' => 'INT NULL', 'uptime_s' => 'INT NULL',
                'swap_in' => 'DECIMAL(10,1) NULL', 'swap_out' => 'DECIMAL(10,1) NULL',
                // BT163: the database's kind of work, a second (and the memory hit rate, %)
                'db_sel' => 'DECIMAL(10,1) NULL', 'db_wr' => 'DECIMAL(10,1) NULL', 'db_scan' => 'DECIMAL(10,1) NULL', 'db_tmpdisk' => 'DECIMAL(10,2) NULL', 'db_hit' => 'DECIMAL(6,2) NULL',
                'db_conn' => 'DECIMAL(10,2) NULL', 'db_aborted' => 'DECIMAL(10,2) NULL'];   // BT162: pages a second moved from / to swap
    }
    function itops_sp_ensure_cols(PDO $db): void {
        $have = []; try { foreach ($db->query('SHOW COLUMNS FROM itops_server_perf')->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Field']] = 1; } catch (Throwable $e) { return; }
        foreach (itops_sp_cols158() as $k => $ddl) if (!isset($have[$k])) $db->exec("ALTER TABLE itops_server_perf ADD COLUMN $k $ddl");
    }

    /** Whole disks from /proc/diskstats: name → [sectors read, sectors written, reads + writes, ms doing I/O]. */
    function itops_sp_disks(): array {
        $s = itops_sp_read('/proc/diskstats'); $o = []; if ($s === null) return $o;
        foreach (preg_split('/\r?\n/', trim($s)) as $l) { $f = preg_split('/\s+/', trim($l)); if (count($f) < 13) continue;
            if (!preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+|nvme\d+n\d+)$/', $f[2])) continue;
            $o[$f[2]] = [(int)$f[5], (int)$f[9], (int)$f[3] + (int)$f[7], (int)$f[12]]; }
        return $o;
    }
    /** Network interfaces from /proc/net/dev (not lo): name → [bytes received, bytes sent]. */
    function itops_sp_nets(): array {
        $s = itops_sp_read('/proc/net/dev'); $o = []; if ($s === null) return $o;
        foreach (preg_split('/\r?\n/', $s) as $l) { if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $l, $m) || $m[1] === 'lo') continue; $f = preg_split('/\s+/', trim($m[2])); if (count($f) < 9) continue;
            $o[$m[1]] = [(int)$f[0], (int)$f[8]]; }
        return $o;
    }
    /** The disks' mount points (real devices only), with size and use. */
    function itops_sp_mounts(): array {
        $s = itops_sp_read('/proc/mounts'); $o = []; $seen = []; if ($s === null) return $o;
        foreach (preg_split('/\r?\n/', trim($s)) as $l) { $f = preg_split('/\s+/', $l); if (count($f) < 3) continue;
            if (strpos($f[0], '/dev/') !== 0 || strpos($f[0], '/dev/loop') === 0 || isset($seen[$f[0]]) || in_array($f[2], ['squashfs', 'iso9660'], true)) continue;
            $mp = str_replace('\040', ' ', $f[1]); $tot = @disk_total_space($mp); $free = @disk_free_space($mp); if (!$tot) continue;
            $seen[$f[0]] = 1; $o[] = ['mount' => $mp, 'dev' => $f[0], 'size' => (int)$tot, 'used' => (int)($tot - $free), 'pct' => round(100 * ($tot - $free) / $tot, 1)]; }
        usort($o, fn($a, $b) => strcmp($a['mount'], $b['mount']));
        return array_slice($o, 0, 12);
    }

    /** cPanel account → main domain, read from WHM (Server Guardian's connection) every 6 hours. */
    function itops_sp_domains(PDO $db, array &$st, int $now): array {
        if (isset($st['domains']) && is_array($st['domains']) && $now - (int)($st['domains_at'] ?? 0) < 21600) return $st['domains'];
        $st['domains_at'] = $now; $map = is_array($st['domains'] ?? null) ? $st['domains'] : [];
        try { require_once __DIR__ . '/Guardian.php'; $c = itops_whm_conn(itops_guardian_settings($db));
              if ($c) { $r = itops_whm_call($c, 'listaccts', [], 10); if ($r['ok']) { $map = []; foreach ((array)($r['data']['acct'] ?? []) as $a) if (!empty($a['user'])) $map[(string)$a['user']] = (string)($a['domain'] ?? ''); } } } catch (Throwable $e) {}
        $st['domains'] = $map; return $map;
    }

    /** A query with its values masked (strings and numbers): what the query does stays, no personal data. */
    function itops_sp_qmask(string $q): string {
        $q = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "'?'", $q); $q = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '"?"', $q);
        $q = preg_replace('/\b\d+(\.\d+)?\b/', '?', $q);
        return mb_substr(trim(preg_replace('/\s+/', ' ', $q)), 0, 300);
    }
    /**
     * The database's connections and running queries (SHOW FULL PROCESSLIST): by database and by user, and the queries
     * running longest. With the monitoring user saved on the Settings tab every database is seen; else only the app's own.
     */
    function itops_sp_dbproc(PDO $db, array $c): array {
        $all = false; $pdo = $db;
        $u = trim((string)($c['db_user'] ?? '')); $p = (string)($c['db_pass'] ?? '');
        if ($u !== '' && $p !== '') { try { require_once __DIR__ . '/Secrets.php'; $pw = itops_secret_unwrap($p);
                $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT), $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]); $all = true; }
              catch (Throwable $e) { return ['ok' => false, 'error' => 'The monitoring user could not sign in to the database: ' . preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 160))]; } }
        // BT163: five quick looks a fifth of a second apart (short queries are caught more often); the first also lists every connection
        $byDb = []; $byUser = []; $long = []; $active = 0; $conns = []; $total = 0;
        for ($pk = 0; $pk < 5; $pk++) {
            if ($pk) usleep(defined('ITOPS_SP_TEST') ? 1000 : 200000);
            try { $rows = $pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { if ($pk) break; return ['ok' => false, 'error' => 'The process list could not be read: ' . mb_substr($e->getMessage(), 0, 160)]; }
            foreach ($rows as $r) { $cmd = (string)($r['Command'] ?? ''); $info = (string)($r['Info'] ?? '');
                if ($cmd === 'Daemon' || $cmd === 'Binlog Dump' || stripos($info, 'SHOW FULL PROCESSLIST') !== false) continue;
                $d = (string)($r['db'] ?? '') ?: '(none)'; $usr = (string)($r['User'] ?? ''); $run = $cmd !== 'Sleep';
                if ($pk === 0) { $total++;
                    if (!isset($byDb[$d])) $byDb[$d] = [0, 0]; $byDb[$d][1]++; if ($run) { $byDb[$d][0]++; $active++; }
                    if (!isset($byUser[$usr])) $byUser[$usr] = [0, 0]; $byUser[$usr][1]++; if ($run) $byUser[$usr][0]++;
                    $conns[] = ['id' => (int)($r['Id'] ?? 0), 'user' => $usr, 'db' => $d, 'host' => preg_replace('/:\d+$/', '', (string)($r['Host'] ?? '')), 'cmd' => $cmd, 'time' => (int)($r['Time'] ?? 0),
                                'state' => mb_substr((string)($r['State'] ?? ''), 0, 40), 'q' => $info !== '' ? mb_substr(itops_sp_qmask($info), 0, 160) : ''];
                }
                if ($run && $info !== '') { $qm = itops_sp_qmask($info); $k = (int)($r['Id'] ?? 0) . '|' . md5($qm);
                    if (!isset($long[$k])) $long[$k] = ['user' => $usr, 'db' => $d, 'time' => 0, 'state' => '', 'q' => $qm, 'seen' => 0];
                    $long[$k]['time'] = max($long[$k]['time'], (int)($r['Time'] ?? 0)); $long[$k]['state'] = mb_substr((string)($r['State'] ?? ''), 0, 40); $long[$k]['seen']++; } }
        }
        $long = array_values($long); usort($long, fn($a, $b) => [$b['time'], $b['seen']] <=> [$a['time'], $a['seen']]);
        usort($conns, fn($a, $b) => [($b['cmd'] !== 'Sleep'), $b['time']] <=> [($a['cmd'] !== 'Sleep'), $a['time']]);
        $fmt = function ($m) { uasort($m, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]); $o = []; foreach (array_slice($m, 0, 10, true) as $k => $v) $o[] = [(string)$k, $v[0], $v[1]]; return $o; };
        return ['ok' => true, 'all' => $all, 'total' => $total, 'active' => $active, 'by_db' => $fmt($byDb), 'by_user' => $fmt($byUser), 'long' => array_slice($long, 0, 10), 'conns' => array_slice($conns, 0, 60), 'peeks' => 5];
    }

    /**
     * Take one sample, keep it, and alert when the CPU or memory stays above the levels set. Returns the row kept, or
     * ['skipped' => why]. $force ignores the minimum interval (the "Sample now" button).
     */
    function itops_sp_sample(PDO $db, bool $force = false, ?int $now = null): array {
        $now = $now ?? time(); $c = itops_sp_cfg($db);
        if (!$force && $now - (int)$c['last_at'] < 50) return ['skipped' => 'sampled less than a minute ago'];
        itops_sp_table($db);
        $st = is_array($c['state']) ? $c['state'] : []; $notes = [];
        $cpuA = itops_sp_cpu();
        if ($cpuA === null) $notes['proc'] = 'This server does not let the app read /proc (open_basedir or a jail): CPU, memory and processes cannot be read.';
        $procA = itops_sp_procs();
        // one second now, for the "right now" figure (a short spike is not averaged away)
        $diskA = itops_sp_disks(); $netA = itops_sp_nets(); $tA = microtime(true);
        $cpuNow = null; if ($cpuA !== null) { usleep(defined('ITOPS_SP_TEST') ? 10000 : 1000000);
            if (defined('ITOPS_SP_TEST') && defined('ITOPS_SP_PROC') && is_file(ITOPS_SP_PROC . '/stat.after')) { @copy(ITOPS_SP_PROC . '/stat.after', ITOPS_SP_PROC . '/stat'); foreach (glob(ITOPS_SP_PROC . '/*/stat.after') ?: [] as $f) @copy($f, dirname($f) . '/stat'); }   // tests: the second reading
            $cpuNow = itops_sp_pct($cpuA, itops_sp_cpu()); }
        // BT158: the processes again one second later — what ran during that second, short-lived ones (php-cgi) included
        $procB = $cpuA !== null ? itops_sp_procs() : []; $el = max(0.2, microtime(true) - $tA);
        $prevCpu = isset($st['cpu']) && is_array($st['cpu']) ? $st['cpu'] : null; $prevAt = (int)($st['at'] ?? 0);
        $interval = $prevAt ? max(1, $now - $prevAt) : 0;
        $avg = $prevCpu && $interval <= 3600 ? itops_sp_pct($prevCpu, $cpuA) : null;
        $la = null; $s = itops_sp_read('/proc/loadavg'); if ($s !== null && preg_match('~^([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+(\d+)/(\d+)~', $s, $m)) $la = $m;
        $mem = []; $s = itops_sp_read('/proc/meminfo'); if ($s !== null) { preg_match_all('/^(\w+):\s+(\d+)\s*kB/m', $s, $mm); foreach ($mm[1] as $i => $k) $mem[$k] = (int)$mm[2][$i] * 1024; }
        $cores = itops_sp_cores();
        // the busiest processes and accounts since the last sample (CPU time used in the interval)
        $prevP = isset($st['procs']) && is_array($st['procs']) ? $st['procs'] : [];
        $hz = 100; $users = []; $usersMem = []; $plist = []; $mine = function_exists('posix_getuid') ? posix_getuid() : null; $others = 0;
        foreach ($procA as $pid => [$uid, $ticks, $rss, $name, $cmd]) {
            if ($uid !== null && $uid !== $mine) $others++;
            $u = itops_sp_user($uid); $usersMem[$u] = ($usersMem[$u] ?? 0) + $rss;
            if (!$interval || !isset($prevP[$pid])) continue;
            $d = $ticks - (int)$prevP[$pid]; if ($d <= 0) continue;
            $pct = 100 * $d / $hz / $interval;   // % of one core
            $users[$u] = ($users[$u] ?? 0) + $pct;
            $plist[] = ['pid' => $pid, 'user' => $u, 'cpu' => round($pct, 1), 'mem' => $rss, 'name' => $name, 'cmd' => $cmd];
        }
        $usersNow = []; $pnow = [];
        foreach ($procB as $pid => [$uid, $ticks, $rss, $name, $cmd]) {
            $d = $ticks - (isset($procA[$pid]) ? (int)$procA[$pid][1] : 0); if ($d <= 0) continue;
            $pct = min(100 * $cores, 100 * $d / $hz / $el); $u = itops_sp_user($uid);
            $usersNow[$u] = ($usersNow[$u] ?? 0) + $pct; $pnow[] = ['pid' => $pid, 'user' => $u, 'cpu' => round($pct, 1), 'mem' => $rss, 'name' => $name, 'cmd' => $cmd];
        }
        usort($pnow, fn($a, $b) => $b['cpu'] <=> $a['cpu']);
        if ($procA && $others === 0) $notes['hidepid'] = 'Only this account\'s processes can be seen on this server (hidepid or CageFS): other accounts are not listed.';
        usort($plist, fn($a, $b) => $b['cpu'] <=> $a['cpu']);
        // who was asking the web server
        // the status page: the address that answered before; when none did, it is tried again once an hour (not every minute)
        $who = null; $rps = null; $web = null; $webKbps = null;
        if (!empty($st['web_url']) || $force || $now - (int)($st['web_try'] ?? 0) >= 3600) {
            $w = itops_sp_web($st['web_url'] ?? null); $st['web_try'] = $now;
            if (isset($w['error'])) { $st['web_url'] = ''; $st['web_err'] = $w['error']; } else { $web = $w; $st['web_url'] = $w['url']; $st['web_err'] = ''; }
        }
        if ($web === null) $notes['web'] = 'Visitors, sites and pages come from the web server\'s own status page (the one WHM shows under Server Status → Apache Status), read from inside the server. On this server that page does not answer the app'
            . (!empty($st['web_err']) ? ' (' . $st['web_err'] . ')' : '') . '. WHM reaches it its own way; the app can only ask the server at its local address, and "404" means the server answered without that page there (often because NGINX or another proxy sits in front of Apache). CPU, memory, accounts, processes and the database are not affected. To show visitors, ask the hosting provider to allow the Apache status page for 127.0.0.1 (mod_status, "Require ip 127.0.0.1").';
        else {
            $cl = []; $vh = []; $pg = []; $act = 0;
            $byIp = [];   // BT159: per address — requests seen, running, POSTs, login pages, longest, sites and pages
            foreach ($web['rows'] as $r) {
                if ($r['client'] !== '') { $ip = $r['client']; $run = !in_array($r['mode'], ['_', '.', ''], true); $rq = (string)$r['request'];
                    if (!isset($byIp[$ip])) $byIp[$ip] = ['n' => 0, 'act' => 0, 'post' => 0, 'login' => 0, 'secs' => 0, 'sites' => [], 'pages' => []];
                    $x = &$byIp[$ip]; $x['n']++; if ($run) { $x['act']++; $x['secs'] = max($x['secs'], (int)$r['ss']); }
                    if (strncmp($rq, 'POST ', 5) === 0) $x['post']++;
                    if (preg_match('~/(wp-login\.php|xmlrpc\.php|login|signin|user/login|administrator)\b~i', $rq)) $x['login']++;
                    if ($r['vhost'] !== '') $x['sites'][$r['vhost']] = ($x['sites'][$r['vhost']] ?? 0) + 1;
                    if ($rq !== '' && $rq !== 'NULL') { $pk = itops_sp_page($rq); $x['pages'][$pk] = ($x['pages'][$pk] ?? 0) + 1; }
                    unset($x); }
                if ($r['client'] !== '') $cl[$r['client']] = ($cl[$r['client']] ?? 0) + 1;
                if ($r['vhost'] !== '') $vh[$r['vhost']] = ($vh[$r['vhost']] ?? 0) + 1;
                if ($r['request'] !== '' && $r['request'] !== 'NULL') { $k = ($r['vhost'] !== '' ? $r['vhost'] . ' ' : '') . itops_sp_page($r['request']); $pg[$k] = ($pg[$k] ?? 0) + 1; }
                if (!in_array($r['mode'], ['_', '.', ''], true)) $act++;
            }
            uasort($byIp, fn($a, $b) => $b['n'] <=> $a['n']); $byIp = array_slice($byIp, 0, 25, true);
            foreach ($byIp as &$x) { $x['sites'] = itops_sp_top($x['sites'], 2); $x['pages'] = itops_sp_top($x['pages'], 2); } unset($x);
            require_once __DIR__ . '/NetWatch.php'; itops_nw_seen($db, $byIp, $now);   // BT164: first and last time of each address (for the map's "new" places)
            $who = ['clients' => itops_sp_top($cl, 15), 'by_ip' => $byIp, 'sites' => itops_sp_top($vh), 'pages' => itops_sp_top($pg), 'active' => $act, 'seen' => count($web['rows'])];
            if ($web['accesses'] !== null && isset($st['web_acc']) && $interval) $rps = max(0, ($web['accesses'] - (int)$st['web_acc']) / $interval);
            if ($web['accesses'] !== null) $st['web_acc'] = $web['accesses'];
            if ($web['kbytes'] !== null && isset($st['web_kb']) && $interval) $webKbps = max(0, ($web['kbytes'] - (int)$st['web_kb']) * 8 / $interval);
            if ($web['kbytes'] !== null) $st['web_kb'] = $web['kbytes'];
            $who['requests'] = array_slice(array_values(array_map(fn($r) => ['client' => $r['client'], 'site' => $r['vhost'], 'page' => itops_sp_page($r['request']), 'secs' => $r['ss']],
                array_filter($web['rows'], fn($r) => !in_array($r['mode'], ['_', '.', ''], true) && $r['request'] !== '' && $r['request'] !== 'NULL'))), 0, 15);
            $who['uptime'] = $web['uptime']; $who['busy'] = $web['busy']; $who['idle'] = $web['idle'];
        }
        // the database
        $dbr = null; $dbc = null; $qps = null; $slow = null;
        // BT163: the kind of work too — reads and writes, full table scans, temporary tables on disk, memory hits, new and refused connections
        $mixK = ['Com_select', 'Com_insert', 'Com_update', 'Com_delete', 'Com_replace', 'Select_scan', 'Select_full_join', 'Created_tmp_disk_tables', 'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads', 'Connections', 'Aborted_connects'];
        $mixNow = []; $mix = null;
        try { foreach ($db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('" . implode("','", $mixK) . "')")->fetchAll(PDO::FETCH_NUM) as [$k, $v]) $mixNow[$k] = (int)$v; } catch (Throwable $e) {}
        $prevMix = is_array($st['db_mix'] ?? null) ? $st['db_mix'] : null;
        if ($mixNow && $prevMix && $interval) {
            $dl = fn($k) => isset($mixNow[$k], $prevMix[$k]) ? max(0, $mixNow[$k] - $prevMix[$k]) : null; $ps = fn($k) => ($x = $dl($k)) === null ? null : round($x / $interval, 1);
            $req = $dl('Innodb_buffer_pool_read_requests'); $miss = $dl('Innodb_buffer_pool_reads');
            $mix = ['sel' => $ps('Com_select'), 'wr' => round(((int)$dl('Com_insert') + (int)$dl('Com_update') + (int)$dl('Com_delete') + (int)$dl('Com_replace')) / $interval, 1),
                    'ins' => $ps('Com_insert'), 'upd' => $ps('Com_update'), 'del' => $ps('Com_delete'), 'scan' => round(((int)$dl('Select_scan') + (int)$dl('Select_full_join')) / $interval, 1),
                    'tmpdisk' => $ps('Created_tmp_disk_tables'), 'hit' => $req ? round(100 * (1 - $miss / $req), 2) : null, 'conn' => $ps('Connections'), 'aborted' => $ps('Aborted_connects')];
        }
        if ($mixNow) $st['db_mix'] = $mixNow;
        try { foreach ($db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_running','Threads_connected','Questions','Slow_queries')")->fetchAll(PDO::FETCH_NUM) as [$k, $v]) {
                  if ($k === 'Threads_running') $dbr = (int)$v; elseif ($k === 'Threads_connected') $dbc = (int)$v;
                  elseif ($k === 'Slow_queries') { if (isset($st['db_slow']) && $interval) $slow = max(0, (int)$v - (int)$st['db_slow']); $st['db_slow'] = (int)$v; }
                  elseif ($k === 'Questions') { if (isset($st['db_q']) && $interval) $qps = max(0, ((int)$v - (int)$st['db_q']) / $interval); $st['db_q'] = (int)$v; } } } catch (Throwable $e) {}
        // BT158: disks and network since the last sample, the mounts, the uptime, the database's queries
        $dk = []; $rk = 0; $wk = 0; $util = null; $prevD = is_array($st['disks'] ?? null) ? $st['disks'] : [];
        if ($interval) foreach ($diskA as $n => $v) { if (!isset($prevD[$n])) continue; $p = $prevD[$n];
            $r1 = max(0, ($v[0] - $p[0]) * 512 / 1024 / $interval); $w1 = max(0, ($v[1] - $p[1]) * 512 / 1024 / $interval); $u1 = min(100, max(0, ($v[3] - $p[3]) / ($interval * 10)));
            $dk[] = ['dev' => $n, 'read_kbps' => round($r1, 1), 'write_kbps' => round($w1, 1), 'iops' => round(max(0, $v[2] - $p[2]) / $interval, 1), 'util' => round($u1, 1)];
            $rk += $r1; $wk += $w1; $util = max((float)$util, $u1); }
        $nt = []; $rx = 0; $tx = 0; $prevN = is_array($st['nets'] ?? null) ? $st['nets'] : [];
        if ($interval) foreach ($netA as $n => $v) { if (!isset($prevN[$n])) continue; $a = max(0, ($v[0] - $prevN[$n][0]) * 8 / 1000 / $interval); $b2 = max(0, ($v[1] - $prevN[$n][1]) * 8 / 1000 / $interval);
            if ($a + $b2 > 0 || count($netA) <= 3) $nt[] = ['iface' => $n, 'rx_kbps' => round($a, 1), 'tx_kbps' => round($b2, 1)]; $rx += $a; $tx += $b2; }
        $st['disks'] = $diskA; $st['nets'] = $netA;
        $mounts = itops_sp_mounts(); $worst = $mounts ? max(array_map(fn($m) => $m['pct'], $mounts)) : null;
        $up = itops_sp_read('/proc/uptime'); $upS = $up !== null ? (int)(float)$up : null;
        // BT162: swap activity — pages moved in and out of swap since the last sample (what actually slows the server)
        $swIn = null; $swOut = null; $vm = itops_sp_read('/proc/vmstat');
        if ($vm !== null && preg_match('/^pswpin (\d+)/m', $vm, $a1) && preg_match('/^pswpout (\d+)/m', $vm, $a2)) {
            if ($interval && isset($st['pswpin'])) { $swIn = max(0, ((int)$a1[1] - (int)$st['pswpin']) / $interval); $swOut = max(0, ((int)$a2[1] - (int)$st['pswpout']) / $interval); }
            $st['pswpin'] = (int)$a1[1]; $st['pswpout'] = (int)$a2[1]; }
        $dbp = itops_sp_dbproc($db, $c);
        // BT163: the full list of connections is kept for the latest sample only (it would grow the samples by ~200 MB a month); each sample keeps the busy ones
        if (!empty($dbp['ok'])) { $st['db_conns'] = ['at' => date('Y-m-d H:i:s', $now), 'list' => $dbp['conns']]; $dbp['conns'] = array_values(array_filter($dbp['conns'], fn($x) => $x['cmd'] !== 'Sleep')); $dbp['conns'] = array_slice($dbp['conns'], 0, 10); }
        if (!$dbp['ok']) $notes['dbproc'] = $dbp['error']; elseif (!$dbp['all']) $notes['dbproc'] = 'The database\'s queries: only the app\'s own connections are seen. To see every database, save a read-only MySQL user with the PROCESS privilege on the Settings tab.';
        $dom = itops_sp_domains($db, $st, $now);
        $detail = ['users' => itops_sp_top($users, 8), 'users_mem' => itops_sp_top($usersMem, 8), 'procs' => array_slice($plist, 0, 10), 'web' => $who, 'notes' => array_values($notes),
                   'users_now' => itops_sp_top($usersNow, 10), 'procs_now' => array_slice($pnow, 0, 15), 'disks' => $dk, 'nets' => array_slice($nt, 0, 6), 'mounts' => $mounts, 'db' => $dbp['ok'] ? $dbp : null,
                   'db_mix' => $mix,
                   'domains' => array_intersect_key($dom, array_flip(array_merge(array_keys($users), array_keys($usersNow), array_keys($usersMem))))];
        $row = ['at' => date('Y-m-d H:i:s', $now), 'interval_s' => $interval, 'cpu_pct' => $avg['cpu'] ?? null, 'cpu_now' => $cpuNow['cpu'] ?? null, 'iowait_pct' => $avg['iowait'] ?? ($cpuNow['iowait'] ?? null),
                'steal_pct' => $avg['steal'] ?? ($cpuNow['steal'] ?? null), 'load1' => $la ? (float)$la[1] : null, 'load5' => $la ? (float)$la[2] : null, 'load15' => $la ? (float)$la[3] : null, 'cores' => $cores,
                'mem_total' => $mem['MemTotal'] ?? null, 'mem_avail' => $mem['MemAvailable'] ?? (isset($mem['MemFree']) ? $mem['MemFree'] + ($mem['Cached'] ?? 0) + ($mem['Buffers'] ?? 0) : null),
                'swap_total' => $mem['SwapTotal'] ?? null, 'swap_free' => $mem['SwapFree'] ?? null, 'procs_total' => $la ? (int)$la[5] : ($procA ? count($procA) : null), 'procs_running' => $la ? (int)$la[4] : null,
                'web_busy' => $web['busy'] ?? null, 'web_idle' => $web['idle'] ?? null, 'web_rps' => $rps !== null ? round($rps, 2) : null, 'db_running' => $dbr, 'db_connected' => $dbc, 'db_qps' => $qps !== null ? round($qps, 2) : null,
                'mem_free' => $mem['MemFree'] ?? null, 'mem_cached' => isset($mem['Cached']) ? $mem['Cached'] + ($mem['SReclaimable'] ?? 0) : null, 'mem_buffers' => $mem['Buffers'] ?? null,
                'net_rx_kbps' => $interval && $netA ? round($rx, 1) : null, 'net_tx_kbps' => $interval && $netA ? round($tx, 1) : null, 'disk_read_kbps' => $interval && $diskA ? round($rk, 1) : null,
                'disk_write_kbps' => $interval && $diskA ? round($wk, 1) : null, 'disk_util' => $util !== null ? round($util, 1) : null, 'disk_space_pct' => $worst, 'web_kbps' => $webKbps !== null ? round($webKbps, 1) : null,
                'db_slow' => $slow, 'uptime_s' => $upS, 'swap_in' => $swIn !== null ? round($swIn, 1) : null, 'swap_out' => $swOut !== null ? round($swOut, 1) : null,
                'db_sel' => $mix['sel'] ?? null, 'db_wr' => $mix['wr'] ?? null, 'db_scan' => $mix['scan'] ?? null, 'db_tmpdisk' => $mix['tmpdisk'] ?? null, 'db_hit' => $mix['hit'] ?? null,
                'db_conn' => $mix['conn'] ?? null, 'db_aborted' => $mix['aborted'] ?? null];
        $cols = array_keys($row);
        $db->prepare('INSERT INTO itops_server_perf (' . implode(',', $cols) . ',detail) VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')
           ->execute(array_merge(array_values($row), [json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)]));
        $row['id'] = (int)$db->lastInsertId();
        $st['cpu'] = $cpuA; $st['at'] = $now; $st['procs'] = array_map(fn($p) => $p[1], $procA);
        $c = itops_sp_cfg($db); $c['state'] = $st; $c['last_at'] = $now; $c['notes'] = array_values($notes);
        // alert: above the CPU or memory level for N samples in a row, once per episode
        $memPct = $row['mem_total'] ? 100 * (1 - (float)$row['mem_avail'] / (float)$row['mem_total']) : null;
        $cpuSeen = $row['cpu_pct'] ?? $row['cpu_now'];
        $over = ($cpuSeen !== null && $cpuSeen >= (float)$c['cpu_alert']) || ($memPct !== null && $memPct >= (float)$c['mem_alert']);
        $c['over'] = $over ? (int)$c['over'] + 1 : 0;
        if (!$over && !empty($c['alerted'])) { $c['alerted'] = false; itops_sp_alert($db, $c, false, $row, $detail, $memPct); }
        elseif ($over && empty($c['alerted']) && $c['over'] >= max(1, (int)$c['alert_after'])) { $c['alerted'] = true; itops_sp_alert($db, $c, true, $row, $detail, $memPct); }
        // BT161: the day's summary for the daily history — today every hour, yesterday once more when the day turns,
        // and on the first run every day still in the samples (so the history starts with what is already kept)
        $today = date('Y-m-d', $now);
        if ($today !== (string)($c['daily_day'] ?? '') || $now - (int)($c['daily_at'] ?? 0) >= 3600) {
            try { itops_sp_daily_table($db);
                  if (empty($c['daily_day'])) { foreach ($db->query('SELECT DISTINCT DATE(at) FROM itops_server_perf ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN) as $dd) if ($dd !== $today) itops_sp_rollup($db, (string)$dd, $c); }
                  elseif ($c['daily_day'] !== $today) itops_sp_rollup($db, (string)$c['daily_day'], $c);
                  itops_sp_rollup($db, $today, $c);
                  if ($now - (int)($c['daily_purge'] ?? 0) > 86400) { $db->prepare('DELETE FROM itops_server_daily WHERE day < ?')->execute([date('Y-m-d', $now - max(90, (int)($c['daily_keep'] ?? 1095)) * 86400)]); $c['daily_purge'] = $now; }
                  $c['daily_err'] = '';
            } catch (Throwable $e) { $c['daily_err'] = mb_substr($e->getMessage(), 0, 300); }
            $c['daily_day'] = $today; $c['daily_at'] = $now;
        }
        // BT163: every hour, a snapshot of the queries that cost the database most (needs the monitoring user and performance_schema)
        if (trim((string)($c['db_user'] ?? '')) !== '' && $now - (int)($st['dig_at'] ?? 0) >= 3600) {
            try { require_once __DIR__ . '/DbInsight.php'; $ds = itops_dbi_snap($db, $c, $now); $c['state']['dig_state'] = $ds['state']; } catch (Throwable $e) { $c['state']['dig_state'] = 'error'; }
            $c['state']['dig_at'] = $now;
        }
        // BT164: every 5 minutes, the sign-ins from outside the allowed countries and this hour's traffic (the network watch)
        if ($now - (int)($c['state']['nw']['at'] ?? 0) >= 300) {
            try { require_once __DIR__ . '/NetWatch.php'; itops_nw_run($db, $c, $now); }
            catch (Throwable $e) { $c['state']['nw'] = (is_array($c['state']['nw'] ?? null) ? $c['state']['nw'] : []); $c['state']['nw']['at'] = $now; $c['state']['nw']['notes'] = ['The network watch could not run: ' . mb_substr($e->getMessage(), 0, 200)]; }
        }
        // keep the days set
        if ($now - (int)$c['last_purge'] > 86400) { try { $db->prepare('DELETE FROM itops_server_perf WHERE at < ?')->execute([date('Y-m-d H:i:s', $now - max(7, (int)$c['keep_days']) * 86400)]); } catch (Throwable $e) {} try { $db->prepare('DELETE FROM itops_ip_geo WHERE fetched_at < ?')->execute([date('Y-m-d H:i:s', $now - 90 * 86400)]); } catch (Throwable $e) {} $c['last_purge'] = $now; }
        itops_sp_save($db, $c);
        return $row;
    }

    /** The bell for the administrators (and the Event Log): high for a while, or back to normal. Only when switched on. */
    function itops_sp_alert(PDO $db, array $c, bool $high, array $row, array $detail, ?float $memPct): void {
        $why = itops_sp_why($detail, (int)$row['cores']);
        $txt = $high ? 'The server has been busy for ' . (int)$c['over'] . ' samples: CPU ' . ($row['cpu_pct'] ?? $row['cpu_now'] ?? '?') . '%, memory ' . ($memPct !== null ? round($memPct) . '%' : '?') . ', load ' . ($row['load1'] ?? '?') . ' on ' . $row['cores'] . ' core(s).' . ($why ? ' ' . $why : '')
                     : 'The server is back below the levels set: CPU ' . ($row['cpu_pct'] ?? $row['cpu_now'] ?? '?') . '%.';
        if (!function_exists('itops_log') && is_file(__DIR__ . '/EventLog.php')) @require_once __DIR__ . '/EventLog.php';
        if (function_exists('itops_log')) { try { itops_log($high ? 'warning' : 'info', 'server', $txt); } catch (Throwable $e) {} }
        if (empty($c['alert'])) return;
        try {
            $ins = $db->prepare('INSERT INTO itops_notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)');
            foreach ($db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id = u.role_id WHERE r.slug IN ('super_admin','admin') AND u.status='active'")->fetchAll(PDO::FETCH_COLUMN) as $uid)
                $ins->execute([(int)$uid, $high ? 'Server busy' : 'Server back to normal', mb_substr($txt, 0, 1000), $high ? 'error' : 'info', 'server_perf']);
        } catch (Throwable $e) {}
    }

    /** One sentence: the account, process, visitor and site that weighed most in a sample. */
    function itops_sp_why(array $d, int $cores = 1): string {
        $p = [];
        if (!empty($d['users'][0])) $p[] = 'busiest account ' . $d['users'][0][0] . ' (' . round((float)$d['users'][0][1] / max(1, $cores), 1) . '% of the server)';
        if (!empty($d['procs'][0])) $p[] = 'top process ' . $d['procs'][0]['name'] . ' of ' . $d['procs'][0]['user'];
        if (!empty($d['web']['clients'][0])) $p[] = 'most requests from ' . $d['web']['clients'][0][0] . ' (' . $d['web']['clients'][0][1] . ')';
        if (!empty($d['web']['sites'][0])) $p[] = 'busiest site ' . $d['web']['sites'][0][0];
        return $p ? ucfirst(implode('; ', $p)) . '.' : '';
    }

    /**
     * The graph of a period: at most ~400 points (each the average and the highest of its samples), the spikes as
     * periods above the CPU level, and for each spike what weighed most in it (accounts, processes, visitors, sites, pages).
     */
    function itops_sp_series(PDO $db, string $from, string $to): array {
        $c = itops_sp_cfg($db); itops_sp_ensure_cols($db);
        $q = $db->prepare('SELECT id, at, interval_s, cpu_pct, cpu_now, iowait_pct, steal_pct, load1, cores, mem_total, mem_avail, swap_total, swap_free, web_busy, web_rps, db_running, db_qps, mem_free, mem_cached, mem_buffers, net_rx_kbps, net_tx_kbps, disk_read_kbps, disk_write_kbps, disk_util, disk_space_pct, web_kbps, db_slow, uptime_s, swap_in, swap_out, db_sel, db_wr, db_scan, db_tmpdisk, db_hit, db_conn, db_aborted FROM itops_server_perf WHERE at >= ? AND at <= ? ORDER BY at');
        $q->execute([$from, $to]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $t0 = strtotime($from); $t1 = strtotime($to); $span = max(60, $t1 - $t0); $bucket = max(60, (int)ceil($span / 400 / 60) * 60);
        $b = []; $lvl = (float)$c['cpu_alert']; $heat = [];
        foreach ($rows as $r) {
            $hk = substr((string)$r['at'], 0, 13); $hv = $r['cpu_pct'] !== null ? (float)$r['cpu_pct'] : ($r['cpu_now'] !== null ? (float)$r['cpu_now'] : null);
            if ($hv !== null) { if (!isset($heat[$hk])) $heat[$hk] = [0, 0, 0]; $heat[$hk][0] += $hv; $heat[$hk][1]++; $heat[$hk][2] = max($heat[$hk][2], $hv); }
            $t = strtotime($r['at']); $k = (int)floor(($t - $t0) / $bucket);
            $cpu = $r['cpu_pct'] !== null ? (float)$r['cpu_pct'] : ($r['cpu_now'] !== null ? (float)$r['cpu_now'] : null);
            $peak = max((float)($r['cpu_pct'] ?? 0), (float)($r['cpu_now'] ?? 0));
            $mem = $r['mem_total'] ? round(100 * (1 - (float)$r['mem_avail'] / (float)$r['mem_total']), 1) : null;
            $load = $r['load1'] !== null ? round(100 * (float)$r['load1'] / max(1, (int)$r['cores']), 1) : null;
            if (!isset($b[$k])) $b[$k] = ['t' => date('c', $t0 + $k * $bucket), 'n' => 0, 'cpu' => 0, 'cpu_max' => 0, 'mem' => 0, 'mem_n' => 0, 'load' => 0, 'load_n' => 0, 'iowait' => 0, 'id' => (int)$r['id'], 'peak_id' => (int)$r['id'], 'hi' => 0];
            $x = &$b[$k]; $x['n']++;
            if ($cpu !== null) { $x['cpu'] += $cpu; $x['hi'] = max($x['hi'], $cpu); }
            if ($peak >= $x['cpu_max']) { $x['cpu_max'] = $peak; $x['peak_id'] = (int)$r['id']; }
            if ($mem !== null) { $x['mem'] += $mem; $x['mem_n']++; }
            if ($load !== null) { $x['load'] += $load; $x['load_n']++; }
            $x['iowait'] = max($x['iowait'], (float)($r['iowait_pct'] ?? 0));
            // BT158: the averages for the small charts (disk, network, web, database, swap, memory parts)
            $ex = ['net_rx' => $r['net_rx_kbps'], 'net_tx' => $r['net_tx_kbps'], 'disk_r' => $r['disk_read_kbps'], 'disk_w' => $r['disk_write_kbps'], 'disk_util' => $r['disk_util'],
                   'web_rps' => $r['web_rps'], 'web_kbps' => $r['web_kbps'], 'web_busy' => $r['web_busy'], 'db_run' => $r['db_running'], 'db_qps' => $r['db_qps'], 'db_slow' => $r['db_slow'],
                   'swap' => $r['swap_total'] ? 100 * (1 - (float)$r['swap_free'] / (float)$r['swap_total']) : null,
                   'db_sel' => $r['db_sel'], 'db_wr' => $r['db_wr'], 'db_scan' => $r['db_scan'], 'db_tmpdisk' => $r['db_tmpdisk'], 'db_hit' => $r['db_hit'], 'db_conn' => $r['db_conn'], 'db_aborted' => $r['db_aborted'],
                   'swap_io' => $r['swap_in'] !== null ? (float)$r['swap_in'] + (float)$r['swap_out'] : null,
                   'mem_cache' => $r['mem_total'] && $r['mem_cached'] !== null ? 100 * ((float)$r['mem_cached'] + (float)$r['mem_buffers']) / (float)$r['mem_total'] : null];
            foreach ($ex as $ek => $ev) if ($ev !== null) { $x['x'][$ek][0] = ($x['x'][$ek][0] ?? 0) + (float)$ev; $x['x'][$ek][1] = ($x['x'][$ek][1] ?? 0) + 1; }
            unset($x);
        }
        $pts = [];
        foreach ($b as $x) $pts[] = ['t' => $x['t'], 'cpu' => round($x['cpu'] / max(1, $x['n']), 1), 'cpu_max' => round($x['cpu_max'], 1), 'mem' => $x['mem_n'] ? round($x['mem'] / $x['mem_n'], 1) : null,
                                     'load' => $x['load_n'] ? round($x['load'] / $x['load_n'], 1) : null, 'iowait' => round($x['iowait'], 1), 'id' => $x['peak_id'], 'n' => $x['n'], 'cpu_hi' => round($x['hi'], 1)]
                                     + array_map(fn($v) => round($v[0] / max(1, $v[1]), 1), $x['x'] ?? []);
        // spikes: runs of samples at or above the level (a gap of up to 10 minutes below joins two runs)
        $spikes = []; $cur = null;
        foreach ($rows as $r) {
            $peak = $r['cpu_pct'] !== null ? (float)$r['cpu_pct'] : (float)($r['cpu_now'] ?? 0); $t = strtotime($r['at']);   // a spike is the minute's average above the level
            if ($peak >= $lvl) {
                if ($cur && $t - $cur['last'] <= 600) { $cur['last'] = $t; $cur['ids'][] = (int)$r['id']; if ($peak > $cur['peak']) { $cur['peak'] = $peak; $cur['peak_id'] = (int)$r['id']; } }
                else { if ($cur) $spikes[] = $cur; $cur = ['first' => $t, 'last' => $t, 'peak' => $peak, 'peak_id' => (int)$r['id'], 'ids' => [(int)$r['id']]]; }
            }
        }
        if ($cur) $spikes[] = $cur;
        $spikes = array_reverse($spikes);
        $out = []; $all = ['users' => [], 'clients' => [], 'sites' => [], 'pages' => []];
        foreach (array_slice($spikes, 0, 50) as $s) {
            $agg = itops_sp_agg($db, $s['ids']);
            foreach ($all as $k => $_) foreach ($agg[$k] as [$n, $v]) $all[$k][$n] = ($all[$k][$n] ?? 0) + $v;
            $out[] = ['from' => date('c', $s['first']), 'to' => date('c', $s['last']), 'minutes' => max(1, (int)round(($s['last'] - $s['first']) / 60) + 1), 'samples' => count($s['ids']), 'peak' => round($s['peak'], 1), 'peak_id' => $s['peak_id'],
                      'users' => array_slice($agg['users'], 0, 3), 'clients' => array_slice($agg['clients'], 0, 3), 'sites' => array_slice($agg['sites'], 0, 3), 'pages' => array_slice($agg['pages'], 0, 3), 'procs' => array_slice($agg['procs'], 0, 3)];
        }
        $sum = []; foreach ($all as $k => $m) $sum[$k] = itops_sp_top($m, 5);
        $lr = $rows ? end($rows) : null;
        $latest = $lr ? ['uptime_s' => $lr['uptime_s'] !== null ? (int)$lr['uptime_s'] : null, 'disk_space_pct' => $lr['disk_space_pct'] !== null ? (float)$lr['disk_space_pct'] : null] : null;
        $n = count($rows); $over = 0; foreach ($rows as $r) if (($r['cpu_pct'] !== null ? (float)$r['cpu_pct'] : (float)($r['cpu_now'] ?? 0)) >= $lvl) $over++;
        return ['from' => $from, 'to' => $to, 'bucket_s' => $bucket, 'points' => $pts, 'spikes' => $out, 'spike_count' => count($spikes), 'samples' => $n, 'over_pct' => $n ? round(100 * $over / $n, 1) : 0,
                'in_spikes' => $sum, 'level' => $lvl, 'mem_level' => (float)$c['mem_alert'], 'cores' => $rows ? (int)end($rows)['cores'] : 1, 'last_id' => $rows ? (int)end($rows)['id'] : null, 'latest' => $latest,
                'heat' => array_map(fn($k, $v) => ['d' => substr($k, 0, 10), 'h' => (int)substr($k, 11, 2), 'cpu' => round($v[0] / max(1, $v[1]), 1), 'max' => round($v[2], 1), 'n' => $v[1]], array_keys($heat), $heat)];
    }

    /** What weighed most across some samples: accounts by CPU, processes by name, visitors, sites and pages by requests seen. */
    function itops_sp_agg(PDO $db, array $ids): array {
        $u = []; $cl = []; $vh = []; $pg = []; $pr = [];
        foreach (array_chunk(array_slice($ids, 0, 500), 100) as $ch) {
            $q = $db->prepare('SELECT detail FROM itops_server_perf WHERE id IN (' . implode(',', array_fill(0, count($ch), '?')) . ')'); $q->execute($ch);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $j) { $d = json_decode((string)$j, true); if (!is_array($d)) continue;
                foreach ($d['users'] ?? [] as [$k, $v]) $u[$k] = ($u[$k] ?? 0) + (float)$v / max(1, count($ids));
                foreach ($d['procs'] ?? [] as $p) { $k = $p['name'] . ' (' . $p['user'] . ')'; $pr[$k] = ($pr[$k] ?? 0) + (float)$p['cpu'] / max(1, count($ids)); }
                foreach ($d['web']['clients'] ?? [] as [$k, $v]) $cl[$k] = ($cl[$k] ?? 0) + (int)$v;
                foreach ($d['web']['sites'] ?? [] as [$k, $v]) $vh[$k] = ($vh[$k] ?? 0) + (int)$v;
                foreach ($d['web']['pages'] ?? [] as [$k, $v]) $pg[$k] = ($pg[$k] ?? 0) + (int)$v; }
        }
        return ['users' => itops_sp_top($u, 5), 'procs' => itops_sp_top($pr, 5), 'clients' => itops_sp_top($cl, 5), 'sites' => itops_sp_top($vh, 5), 'pages' => itops_sp_top($pg, 5)];
    }

    /**
     * BT158: the accounts over a period — each account's average and peak CPU, its average memory, how many samples it
     * was the busiest in, and its main domain. Both scans count (the one-second "now" scan catches the short php-cgi runs).
     */
    function itops_sp_accounts(PDO $db, string $from, string $to): array {
        $q = $db->prepare('SELECT detail FROM itops_server_perf WHERE at >= ? AND at <= ? ORDER BY at DESC LIMIT 3000'); $q->execute([$from, $to]);
        $a = []; $n = 0; $dom = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $j) { $d = json_decode((string)$j, true); if (!is_array($d)) continue; $n++;
            $cpu = []; foreach ($d['users'] ?? [] as [$k, $v]) $cpu[$k] = (float)$v; foreach ($d['users_now'] ?? [] as [$k, $v]) $cpu[$k] = max($cpu[$k] ?? 0, (float)$v);
            arsort($cpu); $first = key($cpu);
            foreach ($cpu as $k => $v) { $x = &$a[$k]; $x['sum'] = ($x['sum'] ?? 0) + $v; $x['max'] = max($x['max'] ?? 0, $v); if ($k === $first && $v > 0) $x['top'] = ($x['top'] ?? 0) + 1; unset($x); }
            foreach ($d['users_mem'] ?? [] as [$k, $v]) { $a[$k]['mem'] = ($a[$k]['mem'] ?? 0) + (int)$v; $a[$k]['mem_n'] = ($a[$k]['mem_n'] ?? 0) + 1; }
            foreach ((array)($d['domains'] ?? []) as $k => $v) if (!isset($dom[$k]) && $v !== '') $dom[$k] = $v; }
        $o = []; foreach ($a as $k => $x) $o[] = ['user' => (string)$k, 'domain' => $dom[$k] ?? '', 'cpu' => round(($x['sum'] ?? 0) / max(1, $n), 1), 'cpu_max' => round($x['max'] ?? 0, 1),
            'mem' => !empty($x['mem_n']) ? (int)round($x['mem'] / $x['mem_n']) : null, 'top' => (int)($x['top'] ?? 0), 'top_pct' => $n ? round(100 * ($x['top'] ?? 0) / $n, 1) : 0];
        usort($o, fn($p, $r) => $r['cpu'] <=> $p['cpu']);
        return ['from' => $from, 'to' => $to, 'samples' => $n, 'accounts' => array_slice($o, 0, 40)];
    }

    // ───────────────────────────── BT159: the addresses that sent load, with their location and network ─────────────────────────────

    /**
     * Every address seen by the web server over the period, most load first: requests seen (running or just served at
     * each sample), running, in samples above the CPU level, POSTs, login pages, the longest request, sites and pages.
     * A sample of the status page, not a full log: heavy users show up most.
     */
    /** BT165: a site's name as the visitors' lists group it (lower case, no "www.", no port). */
    function itops_sp_site(string $v): string { $v = strtolower(trim($v)); $v = preg_replace('/:\d+$/', '', $v); return preg_replace('/^www\./', '', $v); }
    function itops_sp_ips(PDO $db, string $from, string $to, float $lvl, int $limit = 60, string $site = ''): array {
        $q = $db->prepare('SELECT at, cpu_pct, cpu_now, detail FROM itops_server_perf WHERE at >= ? AND at <= ? ORDER BY at DESC LIMIT 6001'); $q->execute([$from, $to]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC); $capped = count($rows) > 6000; if ($capped) array_pop($rows);
        $a = []; $tot = 0; $withWeb = 0; $n = 0; $doms = []; $site = $site !== '' ? itops_sp_site($site) : '';
        foreach ($rows as $r) { $n++; $d = json_decode((string)$r['detail'], true); $w = is_array($d) ? ($d['web'] ?? null) : null; if (!is_array($w)) continue; $withWeb++;
            $cpu = $r['cpu_pct'] !== null ? (float)$r['cpu_pct'] : (float)($r['cpu_now'] ?? 0); $hot = $cpu >= $lvl; $at = (string)$r['at'];
            $by = is_array($w['by_ip'] ?? null) ? $w['by_ip'] : [];
            $list = $by; if (!$list) foreach ($w['clients'] ?? [] as $cc) if (is_array($cc) && count($cc) >= 2) $list[(string)$cc[0]] = ['n' => (int)$cc[1]];   // samples before BT159: counts only
            foreach ($list as $ip => $x) { $ip = (string)$ip; if ($ip === '') continue; $k = (int)($x['n'] ?? 0);
                // BT165: the sites each address asked for, per sample (its two busiest): the total per site, and one site alone when chosen
                $mine = 0; foreach ($x['sites'] ?? [] as [$sn, $sv]) { $sk = itops_sp_site((string)$sn); if ($sk === '') continue; $doms[$sk] = ($doms[$sk] ?? 0) + (int)$sv; if ($site !== '' && $sk === $site) $mine += (int)$sv; }
                if ($site !== '') { if (!$mine) continue; $k = $mine; }
                $tot += $k;
                if (!isset($a[$ip])) $a[$ip] = ['ip' => $ip, 'seen' => 0, 'running' => 0, 'samples' => 0, 'hot' => 0, 'post' => 0, 'login' => 0, 'secs' => 0, 'first' => $at, 'last' => $at, 'sites' => [], 'pages' => []];
                $y = &$a[$ip]; $y['seen'] += $k; $y['samples']++; if ($hot) $y['hot'] += $k;
                $y['running'] += (int)($x['act'] ?? 0); $y['post'] += (int)($x['post'] ?? 0); $y['login'] += (int)($x['login'] ?? 0); $y['secs'] = max($y['secs'], (int)($x['secs'] ?? 0));
                if ($at < $y['first']) $y['first'] = $at; if ($at > $y['last']) $y['last'] = $at;
                foreach ($x['sites'] ?? [] as [$sn, $sv]) $y['sites'][$sn] = ($y['sites'][$sn] ?? 0) + (int)$sv;
                foreach ($x['pages'] ?? [] as [$pn, $pv]) $y['pages'][$pn] = ($y['pages'][$pn] ?? 0) + (int)$pv;
                unset($y); } }
        usort($a, fn($p, $r) => [$r['seen'], $r['running']] <=> [$p['seen'], $p['running']]);
        $out = [];
        foreach (array_slice($a, 0, $limit > 0 ? $limit : null) as $y) { $y['share'] = $tot ? round(100 * $y['seen'] / $tot, 1) : 0; $y['sites'] = itops_sp_top($y['sites'], 3); $y['pages'] = itops_sp_top($y['pages'], 3); $out[] = $y; }
        arsort($doms); $dl = []; foreach (array_slice($doms, 0, 40, true) as $k => $v) $dl[] = [(string)$k, $v];
        return ['from' => $from, 'to' => $to, 'samples' => $n, 'with_web' => $withWeb, 'capped' => $capped, 'addresses' => count($a), 'total_seen' => $tot, 'ips' => $out, 'site' => $site, 'sites' => $dl];
    }

    function itops_ip_geo_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_ip_geo (ip VARCHAR(45) NOT NULL PRIMARY KEY, kind VARCHAR(12) NOT NULL DEFAULT 'public', country CHAR(2) NULL, region VARCHAR(80) NULL, city VARCHAR(80) NULL,
            org VARCHAR(160) NULL, hostname VARCHAR(190) NULL, fetched_at DATETIME NOT NULL, lat DECIMAL(8,4) NULL, lng DECIMAL(8,4) NULL, geo_v TINYINT NOT NULL DEFAULT 0, INDEX idx_geo_at (fetched_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // BT164: the map needs each address's coordinates (geo_v 1 = looked up with them; older rows are looked up again when seen)
        static $cols = false; if ($cols) return; $cols = true;
        try { $have = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'itops_ip_geo'")->fetchAll(PDO::FETCH_COLUMN);
              if (!in_array('lat', $have, true)) $db->exec('ALTER TABLE itops_ip_geo ADD COLUMN lat DECIMAL(8,4) NULL, ADD COLUMN lng DECIMAL(8,4) NULL');
              if (!in_array('geo_v', $have, true)) $db->exec('ALTER TABLE itops_ip_geo ADD COLUMN geo_v TINYINT NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }
    /** BT164: the lookups left today (the free service answers about 1,000 a day without a token, 50,000 a month with one). */
    function itops_ip_geo_left(PDO $db, bool $token): array {
        $cap = $token ? 1600 : 900; $day = date('Y-m-d'); $n = 0;
        try { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute(['ip_geo_day']); $v = (string)$q->fetchColumn(); if (strpos($v, $day . '|') === 0) $n = (int)substr($v, 11); } catch (Throwable $e) {}
        return ['cap' => $cap, 'used' => $n, 'left' => max(0, $cap - $n)];
    }
    /** An address that is not on the public internet (this network, the server itself, reserved). */
    function itops_ip_private(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    /**
     * Location and network of addresses, from the cache (30 days) and else from the lookup service (ipinfo.io; the
     * optional token is the one saved on the Settings tab, wrapped). At most $budget new lookups per call, 3 s each.
     * Returns [ip => row] and the number still waiting.
     */
    function itops_ip_geo(PDO $db, array $ips, array $c, int $budget = 15): array {
        itops_ip_geo_table($db); $ips = array_values(array_unique(array_filter($ips, fn($x) => is_string($x) && $x !== '')));
        $have = []; $old = [];
        foreach (array_chunk($ips, 200) as $ch) { $q = $db->prepare('SELECT * FROM itops_ip_geo WHERE ip IN (' . implode(',', array_fill(0, count($ch), '?')) . ') AND fetched_at > ?');
            $q->execute(array_merge($ch, [date('Y-m-d H:i:s', time() - 30 * 86400)])); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (($r['kind'] !== 'unknown' || strtotime($r['fetched_at']) > time() - 86400) && !($r['kind'] === 'public' && empty($r['geo_v']) && $budget > 0)) $have[$r['ip']] = $r;
            // a failed lookup is tried again after a day; BT164: a row from before the map (no coordinates) is looked up again, when there is budget
            if (count($have) < count($ch)) { $q2 = $db->prepare('SELECT * FROM itops_ip_geo WHERE ip IN (' . implode(',', array_fill(0, count($ch), '?')) . ')'); $q2->execute($ch); foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($have[$r['ip']]) && $r['kind'] === 'public' && empty($r['geo_v'])) $old[$r['ip']] = $r; } }
        // a "too many lookups" answer pauses every lookup for an hour (kept in itops_settings ip_geo_pause)
        $pause = 0; try { $pq = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $pq->execute(['ip_geo_pause']); $pause = (int)$pq->fetchColumn(); } catch (Throwable $e) {}
        $tok = ''; if (!empty($c['geo_token'])) { try { require_once __DIR__ . '/Secrets.php'; $tok = itops_secret_unwrap($c['geo_token']); } catch (Throwable $e) { $tok = ''; } }
        $base = defined('ITOPS_GEO_URL') ? ITOPS_GEO_URL : 'https://ipinfo.io/';
        $lim = itops_ip_geo_left($db, $tok !== ''); $budget = min($budget, $lim['left']); $spent = 0;
        $ins = $db->prepare('INSERT INTO itops_ip_geo (ip, kind, country, region, city, org, hostname, fetched_at, lat, lng, geo_v) VALUES (?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE kind=VALUES(kind), country=VALUES(country), region=VALUES(region), city=VALUES(city), org=VALUES(org), hostname=VALUES(hostname), fetched_at=VALUES(fetched_at), lat=VALUES(lat), lng=VALUES(lng), geo_v=1');
        $waiting = 0; $t0 = microtime(true); $err = !$lim['left'] && $ips ? 'Today\'s location lookups are used up (' . number_format($lim['cap']) . ' a day' . ($tok === '' ? ', to stay inside the free limit without a token' : '') . '); the rest follow tomorrow' . ($tok === '' ? ' — a free token on the Settings tab raises the limit' : '') . '.' : null;
        $err = $err ?? ($pause > time() ? 'The lookup service said too many lookups; the app waits until ' . date('g:i A', $pause) . ' before asking again (a free token on the Settings tab raises the limit).' : null); $fails = 0;
        foreach ($ips as $ip) { if (isset($have[$ip])) continue;
            if (itops_ip_private($ip)) { $ins->execute([$ip, 'private', null, null, null, null, null, date('Y-m-d H:i:s'), null, null]); $have[$ip] = ['ip' => $ip, 'kind' => 'private']; continue; }
            if ($budget <= 0 || microtime(true) - $t0 > 12 || $err) { if (isset($old[$ip])) $have[$ip] = $old[$ip]; else $waiting++; continue; }
            $budget--; $spent++;
            $ch = curl_init($base . rawurlencode($ip) . '/json' . ($tok !== '' ? '?token=' . rawurlencode($tok) : ''));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $j = is_string($b) ? json_decode($b, true) : null;
            if ($code === 429) { $until = time() + 3600; $err = 'The lookup service said too many lookups; the app waits until ' . date('g:i A', $until) . ' before asking again (a free token on the Settings tab raises the limit).'; $waiting++;
                try { $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['ip_geo_pause', (string)$until]); } catch (Throwable $e) {} continue; }
            if ($code !== 200 || !is_array($j)) { $fails++; if (isset($old[$ip])) { $have[$ip] = $old[$ip]; if ($fails >= 3) $err = 'The lookup service did not answer (HTTP ' . $code . '); the rest follow on the next refresh.'; continue; }
                $ins->execute([$ip, 'unknown', null, null, null, null, null, date('Y-m-d H:i:s'), null, null]); $have[$ip] = ['ip' => $ip, 'kind' => 'unknown'];
                if ($fails >= 3) $err = 'The lookup service did not answer (HTTP ' . $code . '); the rest follow on the next refresh.'; continue; }
            $fails = 0;
            $row = ['ip' => $ip, 'kind' => !empty($j['bogon']) ? 'private' : 'public', 'country' => isset($j['country']) ? mb_substr((string)$j['country'], 0, 2) : null, 'region' => isset($j['region']) ? mb_substr((string)$j['region'], 0, 80) : null,
                    'city' => isset($j['city']) ? mb_substr((string)$j['city'], 0, 80) : null, 'org' => isset($j['org']) ? mb_substr((string)$j['org'], 0, 160) : (isset($j['asn']['name']) ? mb_substr((string)$j['asn']['name'], 0, 160) : null),
                    'hostname' => isset($j['hostname']) ? mb_substr((string)$j['hostname'], 0, 190) : null, 'lat' => null, 'lng' => null];
            if (isset($j['loc']) && preg_match('/^(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/', (string)$j['loc'], $lm) && abs((float)$lm[1]) <= 90 && abs((float)$lm[2]) <= 180) { $row['lat'] = round((float)$lm[1], 4); $row['lng'] = round((float)$lm[2], 4); }
            $ins->execute([$ip, $row['kind'], $row['country'], $row['region'], $row['city'], $row['org'], $row['hostname'], date('Y-m-d H:i:s'), $row['lat'], $row['lng']]); $have[$ip] = $row;
        }
        if ($spent) { try { $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['ip_geo_day', date('Y-m-d') . '|' . ($lim['used'] + $spent)]); } catch (Throwable $e) {} }
        return ['geo' => $have, 'waiting' => $waiting, 'error' => $err];
    }
    /** What an address looks like: a search engine's crawler, a data center (a server, not a person), login attempts, slow requests. */
    function itops_ip_tags(array $y, ?array $g): array {
        $t = []; $h = strtolower((string)($g['hostname'] ?? '') . ' ' . (string)($g['org'] ?? ''));
        if (($g['kind'] ?? '') === 'private') $t[] = 'private';
        if (preg_match('/googlebot|google-proxy|search\.msn|bingbot|yandex|baidu|applebot|duckduck|petalbot|ahrefs|semrush|mj12|dotbot|amazonbot|facebookexternalhit|crawl/', $h)) $t[] = 'crawler';
        elseif (preg_match('/amazon|aws|google llc|google cloud|microsoft|azure|digitalocean|ovh|hetzner|linode|akamai|vultr|choopa|contabo|oracle|alibaba|tencent|leaseweb|m247|hostinger|godaddy|hostdime|cloudflare|scaleway|ionos|datacamp|colocrossing/', $h)) $t[] = 'datacenter';
        if (($y['login'] ?? 0) >= 3) $t[] = 'login';
        if (($y['secs'] ?? 0) >= 10) $t[] = 'slow';
        if (($y['hot'] ?? 0) >= 3 && $y['hot'] / max(1, $y['seen']) >= 0.8) $t[] = 'spikes';
        return $t;
    }

    // ───────────────────────────── BT161: the daily history (one row a day, kept 3 years) ─────────────────────────────

    function itops_sp_daily_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_server_daily (day DATE NOT NULL PRIMARY KEY, samples INT NOT NULL DEFAULT 0, cores SMALLINT NOT NULL DEFAULT 1,
            cpu_avg DECIMAL(5,1) NULL, cpu_max DECIMAL(5,1) NULL, cpu_max_at DATETIME NULL, peak_hour TINYINT NULL, load_avg DECIMAL(6,1) NULL, load_max DECIMAL(6,1) NULL,
            mem_avg DECIMAL(5,1) NULL, mem_max DECIMAL(5,1) NULL, swap_max DECIMAL(5,1) NULL, iowait_max DECIMAL(5,1) NULL,
            db_qps_avg DECIMAL(9,1) NULL, db_qps_max DECIMAL(9,1) NULL, db_slow INT NULL, web_rps_avg DECIMAL(9,2) NULL, web_rps_max DECIMAL(9,2) NULL,
            net_in_gb DECIMAL(10,2) NULL, net_out_gb DECIMAL(10,2) NULL, disk_read_gb DECIMAL(10,2) NULL, disk_write_gb DECIMAL(10,2) NULL, disk_space_pct DECIMAL(5,1) NULL,
            spikes INT NOT NULL DEFAULT 0, minutes_over INT NOT NULL DEFAULT 0, cpu_level DECIMAL(5,1) NULL, restarts INT NOT NULL DEFAULT 0,
            hours TEXT NULL, top_accounts TEXT NULL, top_ips TEXT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    /**
     * Sum up one day from its samples: average and highest CPU (and when), each hour's average and highest CPU, load,
     * memory, swap, disk wait, database, web, network and disk traffic, disk space at the end of the day, spikes and
     * minutes above the CPU level, restarts, the busiest accounts and addresses. Replaces the day's row.
     */
    function itops_sp_rollup(PDO $db, string $day, array $c): ?array {
        $q = $db->prepare('SELECT * FROM itops_server_perf WHERE at >= ? AND at <= ? ORDER BY at'); $q->execute([$day . ' 00:00:00', $day . ' 23:59:59']);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC); if (!$rows) return null;
        $lvl = (float)$c['cpu_alert']; $cores = max(1, (int)end($rows)['cores']);
        $h = array_fill(0, 24, [0.0, 0, 0.0, 0.0, 0, 0.0, 0]);   // cpu sum, n, cpu max, load sum, load n, qps sum, qps n
        $acc = ['cpu' => [0, 0], 'load' => [0, 0], 'mem' => [0, 0], 'qps' => [0, 0], 'rps' => [0, 0]]; $mx = ['cpu' => null, 'load' => null, 'mem' => null, 'swap' => null, 'io' => null, 'qps' => null, 'rps' => null];
        $cpuAt = null; $slow = null; $net = [0.0, 0.0]; $dsk = [0.0, 0.0]; $netSeen = false; $dskSeen = false; $space = null; $over = 0; $restarts = 0; $prevUp = null;
        $spikes = 0; $lastHot = null;
        $add = function (string $k, $v) use (&$acc, &$mx) { if ($v === null) return; $v = (float)$v; if (isset($acc[$k])) { $acc[$k][0] += $v; $acc[$k][1]++; } if ($mx[$k] === null || $v > $mx[$k]) $mx[$k] = $v; };
        foreach ($rows as $r) {
            $t = strtotime($r['at']); $hr = (int)date('G', $t); $iv = max(1, min(600, (int)$r['interval_s'] ?: 60));
            $cpu = $r['cpu_pct'] !== null ? (float)$r['cpu_pct'] : ($r['cpu_now'] !== null ? (float)$r['cpu_now'] : null);
            $load = $r['load1'] !== null ? 100 * (float)$r['load1'] / max(1, (int)$r['cores']) : null;
            $mem = $r['mem_total'] ? 100 * (1 - (float)$r['mem_avail'] / (float)$r['mem_total']) : null;
            $swap = $r['swap_total'] ? 100 * (1 - (float)$r['swap_free'] / (float)$r['swap_total']) : null;
            if ($cpu !== null) { if ($mx['cpu'] === null || $cpu > $mx['cpu']) $cpuAt = $r['at']; $h[$hr][0] += $cpu; $h[$hr][1]++; $h[$hr][2] = max($h[$hr][2], $cpu);
                if ($cpu >= $lvl) { $over += $iv; if ($lastHot === null || $t - $lastHot > 600) $spikes++; $lastHot = $t; } }
            if ($load !== null) { $h[$hr][3] += $load; $h[$hr][4]++; }
            if ($r['db_qps'] !== null) { $h[$hr][5] += (float)$r['db_qps']; $h[$hr][6]++; }
            $add('cpu', $cpu); $add('load', $load); $add('mem', $mem); $add('swap', $swap); $add('io', $r['iowait_pct']); $add('qps', $r['db_qps']); $add('rps', $r['web_rps']);
            if ($r['db_slow'] !== null) $slow = (int)$slow + (int)$r['db_slow'];
            if ($r['net_rx_kbps'] !== null) { $netSeen = true; $net[0] += (float)$r['net_rx_kbps'] * $iv / 8 / 1e6; $net[1] += (float)$r['net_tx_kbps'] * $iv / 8 / 1e6; }
            if ($r['disk_read_kbps'] !== null) { $dskSeen = true; $dsk[0] += (float)$r['disk_read_kbps'] * $iv / 1048576; $dsk[1] += (float)$r['disk_write_kbps'] * $iv / 1048576; }
            if ($r['disk_space_pct'] !== null) $space = (float)$r['disk_space_pct'];
            if ($r['uptime_s'] !== null) { $u = (int)$r['uptime_s']; if ($prevUp !== null && $u + 120 < $prevUp) $restarts++; $prevUp = $u; }
        }
        $avg = fn($k) => $acc[$k][1] ? round($acc[$k][0] / $acc[$k][1], 1) : null; $r1 = fn($v) => $v === null ? null : round($v, 1);
        $hours = []; $peakH = null; $best = -1;
        foreach ($h as $i => $x) { $a = $x[1] ? round($x[0] / $x[1], 1) : null; $hours[] = [$a, $x[1] ? round($x[2], 1) : null, $x[4] ? round($x[3] / $x[4], 1) : null, $x[6] ? round($x[5] / $x[6], 1) : null, $x[1]];
            if ($a !== null && $a > $best) { $best = $a; $peakH = $i; } }
        $ta = []; foreach (array_slice(itops_sp_accounts($db, $day . ' 00:00:00', $day . ' 23:59:59')['accounts'], 0, 5) as $a) $ta[] = [$a['user'], round($a['cpu'] / $cores, 1), round($a['cpu_max'] / $cores, 1), $a['domain']];
        $ti = []; foreach (array_slice(itops_sp_ips($db, $day . ' 00:00:00', $day . ' 23:59:59', $lvl)['ips'], 0, 5) as $y) $ti[] = [$y['ip'], $y['share'], $y['seen']];
        $row = ['day' => $day, 'samples' => count($rows), 'cores' => $cores, 'cpu_avg' => $avg('cpu'), 'cpu_max' => $r1($mx['cpu']), 'cpu_max_at' => $cpuAt, 'peak_hour' => $peakH,
                'load_avg' => $avg('load'), 'load_max' => $r1($mx['load']), 'mem_avg' => $avg('mem'), 'mem_max' => $r1($mx['mem']), 'swap_max' => $r1($mx['swap']), 'iowait_max' => $r1($mx['io']),
                'db_qps_avg' => $avg('qps'), 'db_qps_max' => $r1($mx['qps']), 'db_slow' => $slow, 'web_rps_avg' => $acc['rps'][1] ? round($acc['rps'][0] / $acc['rps'][1], 2) : null, 'web_rps_max' => $mx['rps'] === null ? null : round($mx['rps'], 2),
                'net_in_gb' => $netSeen ? round($net[0], 2) : null, 'net_out_gb' => $netSeen ? round($net[1], 2) : null, 'disk_read_gb' => $dskSeen ? round($dsk[0], 2) : null, 'disk_write_gb' => $dskSeen ? round($dsk[1], 2) : null,
                'disk_space_pct' => $space, 'spikes' => $spikes, 'minutes_over' => (int)round($over / 60), 'cpu_level' => $lvl, 'restarts' => $restarts,
                'hours' => json_encode($hours), 'top_accounts' => json_encode($ta, JSON_UNESCAPED_UNICODE), 'top_ips' => json_encode($ti), 'updated_at' => date('Y-m-d H:i:s')];
        $cols = array_keys($row);
        $db->prepare('REPLACE INTO itops_server_daily (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return $row;
    }
    /** The daily history between two days, newest first, with the JSON parts decoded. */
    function itops_sp_daily(PDO $db, string $from, string $to): array {
        itops_sp_daily_table($db);
        $q = $db->prepare('SELECT * FROM itops_server_daily WHERE day >= ? AND day <= ? ORDER BY day DESC'); $q->execute([$from, $to]); $o = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (['hours', 'top_accounts', 'top_ips'] as $k) $r[$k] = json_decode((string)$r[$k], true) ?: [];
            foreach ($r as $k => $v) if ($v !== null && !in_array($k, ['day', 'cpu_max_at', 'updated_at', 'hours', 'top_accounts', 'top_ips'], true)) $r[$k] = is_numeric($v) ? $v + 0 : $v;
            $o[] = $r;
        }
        return $o;
    }

    /** One sample in full. */
    function itops_sp_one(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT * FROM itops_server_perf WHERE id=?'); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC); if (!$r) return null;
        $r['detail'] = json_decode((string)$r['detail'], true) ?: [];
        foreach (['cpu_pct', 'cpu_now', 'iowait_pct', 'steal_pct', 'load1', 'load5', 'load15', 'web_rps', 'db_qps', 'net_rx_kbps', 'net_tx_kbps', 'disk_read_kbps', 'disk_write_kbps', 'disk_util', 'disk_space_pct', 'web_kbps', 'swap_in', 'swap_out', 'db_sel', 'db_wr', 'db_scan', 'db_tmpdisk', 'db_hit', 'db_conn', 'db_aborted'] as $k) $r[$k] = isset($r[$k]) ? (float)$r[$k] : null;
        foreach (['id', 'interval_s', 'cores', 'mem_total', 'mem_avail', 'swap_total', 'swap_free', 'procs_total', 'procs_running', 'web_busy', 'web_idle', 'db_running', 'db_connected', 'mem_free', 'mem_cached', 'mem_buffers', 'db_slow', 'uptime_s'] as $k) $r[$k] = isset($r[$k]) ? (int)$r[$k] : null;
        $r['mem_pct'] = $r['mem_total'] ? round(100 * (1 - $r['mem_avail'] / $r['mem_total']), 1) : null;
        $r['why'] = itops_sp_why($r['detail'], (int)$r['cores']);
        return $r;
    }
}
