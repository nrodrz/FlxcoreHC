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






    /** Apache's status page from inside the server: totals and the requests of each worker. Null when there is none. */



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










    // ───────────────────────────── BT159: the addresses that sent load, with their location and network ─────────────────────────────

    /**
     * Every address seen by the web server over the period, most load first: requests seen (running or just served at
     * each sample), running, in samples above the CPU level, POSTs, login pages, the longest request, sites and pages.
     * A sample of the status page, not a full log: heavy users show up most.
     */

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

}
