<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT164 — Network watch (Server performance): where the load comes from (the map), unusual traffic, and sign-ins from
 * outside the allowed countries.
 *   itops_ip_seen       every address the web server's status page showed: first and last seen (a place is "new" when all
 *                      its addresses were first seen in the last 7 days).
 *   itops_net_hour      one row an hour: addresses, new ones, countries, login-page askers, the network with most addresses;
 *                      the level (good / busy / high / crit) and why, against the same hour of the previous 7 days.
 *   itops_signin_flags  sign-ins to vTiger and to this app from outside the allowed countries, worked or failed.
 * Runs with the samples every 5 minutes (itops_sp_sample). Only reads the server, vTiger and the sites; it never blocks.
 * Locations come from the location lookup of Server performance (ipinfo.io), within its daily limit.
 */
require_once __DIR__ . '/ServerPerf.php';
require_once __DIR__ . '/Countries.php';

if (!function_exists('itops_nw_tables')) {

    function itops_nw_tables(PDO $db): void {
        itops_ip_geo_table($db);
        $db->exec("CREATE TABLE IF NOT EXISTS itops_ip_seen (ip VARCHAR(45) NOT NULL PRIMARY KEY, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, hits INT NOT NULL DEFAULT 0,
            INDEX idx_seen_first (first_seen), INDEX idx_seen_last (last_seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_net_hour (hour DATETIME NOT NULL PRIMARY KEY, samples INT NOT NULL DEFAULT 0, seen INT NOT NULL DEFAULT 0, addrs INT NOT NULL DEFAULT 0,
            new_addrs INT NOT NULL DEFAULT 0, located INT NOT NULL DEFAULT 0, countries TEXT NULL, new_countries TEXT NULL, login_hits INT NOT NULL DEFAULT 0, login_addrs INT NOT NULL DEFAULT 0,
            top_org VARCHAR(160) NULL, top_org_addrs INT NOT NULL DEFAULT 0, baseline DECIMAL(9,1) NULL, level VARCHAR(8) NOT NULL DEFAULT 'good', reasons TEXT NULL, top_ips TEXT NULL,
            updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_signin_flags (id INT AUTO_INCREMENT PRIMARY KEY, source VARCHAR(12) NOT NULL, user_name VARCHAR(190) NOT NULL, display VARCHAR(190) NULL,
            ip VARCHAR(45) NOT NULL, country CHAR(2) NULL, region VARCHAR(80) NULL, city VARCHAR(80) NULL, org VARCHAR(160) NULL, lat DECIMAL(8,4) NULL, lng DECIMAL(8,4) NULL,
            at DATETIME NOT NULL, ok TINYINT NOT NULL DEFAULT 1, status VARCHAR(40) NULL, excused TINYINT NOT NULL DEFAULT 0, excuse VARCHAR(255) NULL, alerted TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, UNIQUE KEY uq_flag (source, user_name, ip, at), INDEX idx_flag_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    function itops_nw_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_signin_flags LIMIT 1'); $db->query('SELECT 1 FROM itops_net_hour LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    /** The settings: allowed countries, what is watched, how alerts go out, the exceptions for people travelling. */
    function itops_nw_cfg(array $c): array {
        $s = is_array($c['sec'] ?? null) ? $c['sec'] : [];
        $s += ['countries' => ['US', 'PR'], 'signins' => true, 'traffic' => true, 'notify' => true, 'email' => false, 'exceptions' => []];
        $s['countries'] = array_values(array_unique(array_filter(array_map(fn($x) => strtoupper((string)$x), (array)$s['countries']), fn($x) => preg_match('/^[A-Z]{2}$/', $x))));
        $s['exceptions'] = array_values(array_filter((array)$s['exceptions'], 'is_array'));
        return $s;
    }

    /** Each sample: the addresses seen, kept with their first and last time. */
    function itops_nw_seen(PDO $db, array $byIp, int $now): void {
        if (!$byIp) return;
        try { $q = $db->prepare('INSERT INTO itops_ip_seen (ip, first_seen, last_seen, hits) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_seen = GREATEST(last_seen, VALUES(last_seen)), hits = hits + VALUES(hits)');
              $at = date('Y-m-d H:i:s', $now); foreach ($byIp as $ip => $x) if ((string)$ip !== '') $q->execute([(string)$ip, $at, $at, max(1, (int)($x['n'] ?? 1))]); } catch (Throwable $e) {}
    }

    /** An exception that covers a sign-in: the same person (or anyone), the same country, until a day. */
    function itops_nw_excused(array $sec, string $source, string $user, string $cc, string $at): ?array {
        foreach ($sec['exceptions'] as $e) {
            $u = strtolower((string)($e['user'] ?? '')); $src = (string)($e['source'] ?? 'any');
            if ($u !== '*' && $u !== strtolower($user)) continue;
            if ($src !== 'any' && $src !== $source) continue;
            if (strtoupper((string)($e['country'] ?? '')) !== strtoupper($cc)) continue;
            if ((string)($e['until'] ?? '') !== '' && substr($at, 0, 10) > (string)$e['until']) continue;
            return $e;
        }
        return null;
    }

    /**
     * BT165: vTiger's sign-in history since a time — through vTiger's read-only database connection when it is set and
     * answers, else through the provisioning bridge (build o11 and later). Returns [rows, how, error, configured].
     */
    function itops_nw_vt_history(PDO $db, string $since, int $limit = 2000): array {
        require_once __DIR__ . '/VTigerProvision.php'; require_once __DIR__ . '/VTigerClient.php';
        $cfg = itops_vtiger_cfg($db); $dbc = $cfg['db'] ?? []; $prov = $cfg['prov'] ?? []; $err = null;
        if (!empty($dbc['db']) && !empty($dbc['user'])) {
            try { require_once __DIR__ . '/VTigerDb.php'; $vt = new ItopsVTigerDb($dbc); return [$vt->loginHistory($since, $limit), 'database', null, true]; }
            catch (Throwable $e) { $err = 'vTiger\'s read-only database connection was refused (' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160) . ')'; }
        }
        if (itops_vtiger_bridge_ready($prov)) {
            $r = itops_vtiger_bridge_call($prov, 'login_history', ['since' => $since, 'limit' => $limit]);
            if (!empty($r['ok']) && is_array($r['rows'] ?? null)) {
                $rows = array_map(fn($x) => ['user_name' => (string)($x['user_name'] ?? ''), 'first_name' => (string)($x['first_name'] ?? ''), 'last_name' => (string)($x['last_name'] ?? ''), 'ip' => (string)($x['ip'] ?? ''),
                                              'login_time' => (string)($x['login_time'] ?? ''), 'logout_time' => (string)($x['logout_time'] ?? ''), 'status' => (string)($x['status'] ?? '')], $r['rows']);
                return [$rows, 'bridge', null, true];
            }
            $be = (string)($r['error'] ?? 'no answer');
            $be = stripos($be, 'Unknown action') !== false ? 'the bridge on the vTiger server is an older build: upload vtiger-bridge/itops-bridge.php of this release (build o11) to the vTiger web root' : 'the bridge answered: ' . mb_substr($be, 0, 160);
            return [[], 'bridge', ($err ? $err . '; ' : '') . $be, true];
        }
        return [[], '', $err, $err !== null];
    }

    /**
     * BT167: who signed in from each address in a period — vTiger (bridge or read-only database) and this app (the Audit
     * Log): [ip => [[user name, display name, 'vtiger'|'app', last time], …]]. Kept 2 minutes (the page refreshes each minute).
     */
    function itops_nw_ip_users(PDO $db, string $from, string $to): array {
        $key = 'ip_users_cache'; $tag = substr($from, 0, 15) . '|' . substr($to, 0, 15);
        try { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute([$key]); $c = json_decode((string)$q->fetchColumn(), true);
              if (is_array($c) && ($c['tag'] ?? '') === $tag && (int)($c['at'] ?? 0) > time() - 120) return (array)$c['map']; } catch (Throwable $e) {}
        $m = []; $add = function ($ip, $u, $dn, $src, $at) use (&$m) { if ($ip === '' || $u === '') return; $k = $src . '|' . strtolower($u);
            if (!isset($m[$ip][$k]) || $m[$ip][$k][3] < $at) $m[$ip][$k] = [$u, $dn, $src, $at]; };
        try { $q = $db->prepare("SELECT user_name, entity_name, ip_address, created_at FROM itops_audit_log WHERE entity_type = 'auth' AND action IN ('login_success','login_first','o365_login','teams_login') AND created_at >= ? AND created_at <= ? ORDER BY id DESC LIMIT 5000");
              $q->execute([$from, $to]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $add(trim((string)$r['ip_address']), (string)$r['entity_name'], (string)$r['user_name'], 'app', (string)$r['created_at']); } catch (Throwable $e) {}
        [$vr] = itops_nw_vt_history($db, $from, 2000);
        foreach ($vr as $r) if ($r['login_time'] <= $to && stripos($r['status'], 'fail') === false) $add($r['ip'], $r['user_name'], trim($r['first_name'] . ' ' . $r['last_name']), 'vtiger', $r['login_time']);
        $out = []; foreach ($m as $ip => $l) $out[$ip] = array_values($l);
        try { $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute([$key, json_encode(['tag' => $tag, 'at' => time(), 'map' => $out], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) {}
        return $out;
    }

    /** The sign-ins of the last $hours: this app's own (the Audit Log) and vTiger's (its read-only database, when set). */
    function itops_nw_signins(PDO $db, int $since, array &$notes): array {
        $o = [];
        try { $q = $db->prepare("SELECT action, user_name, entity_name, ip_address, created_at FROM itops_audit_log WHERE entity_type = 'auth' AND action IN ('login_success','login_first','o365_login','teams_login','login_failed','login_lockout') AND created_at >= ? ORDER BY id DESC LIMIT 3000");
              $q->execute([date('Y-m-d H:i:s', $since)]);
              foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $ip = trim((string)$r['ip_address']); if ($ip === '') continue; $ok = !in_array($r['action'], ['login_failed', 'login_lockout'], true);
                  $o[] = ['source' => 'app', 'user_name' => mb_substr((string)$r['entity_name'], 0, 190), 'display' => $ok ? mb_substr((string)$r['user_name'], 0, 190) : '', 'ip' => $ip, 'at' => (string)$r['created_at'], 'ok' => $ok ? 1 : 0,
                          'status' => ['login_success' => 'Signed in', 'login_first' => 'Signed in (first time)', 'o365_login' => 'Signed in with Microsoft', 'teams_login' => 'Signed in from Teams', 'login_failed' => 'Wrong password', 'login_lockout' => 'Locked out'][$r['action']] ?? $r['action']]; } }
        catch (Throwable $e) { $notes[] = 'This app\'s sign-ins could not be read: ' . mb_substr($e->getMessage(), 0, 120); }
        [$vr, $how, $verr, $vconf] = itops_nw_vt_history($db, date('Y-m-d H:i:s', $since), 2000);
        foreach ($vr as $r) { if ($r['ip'] === '' || $r['login_time'] === '') continue; $ok = stripos($r['status'], 'fail') === false;
            $o[] = ['source' => 'vtiger', 'user_name' => mb_substr($r['user_name'], 0, 190), 'display' => mb_substr(trim($r['first_name'] . ' ' . $r['last_name']), 0, 190), 'ip' => $r['ip'], 'at' => $r['login_time'], 'ok' => $ok ? 1 : 0, 'status' => mb_substr($r['status'], 0, 40)]; }
        if ($verr !== null && !$vr) $notes[] = 'vTiger\'s sign-ins could not be read: ' . $verr . '.';
        elseif (!$vconf) $notes[] = 'vTiger\'s sign-ins are not checked: set the vTiger bridge or vTiger\'s read-only database connection (Integrations → vTiger).';
        return $o;
    }

    /** One hour of traffic: addresses, new ones, countries, login-page askers, the busiest network, and its level. */
    function itops_nw_hour(PDO $db, int $hs, array $c, array $sec, int $budget): array {
        $he = $hs + 3599; $q = $db->prepare('SELECT detail FROM itops_server_perf WHERE at >= ? AND at <= ? ORDER BY at'); $q->execute([date('Y-m-d H:i:s', $hs), date('Y-m-d H:i:s', $he)]);
        $a = []; $samples = 0; $seen = 0; $lh = 0;
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $samples++; $d = json_decode((string)$r['detail'], true); $w = is_array($d) ? ($d['web'] ?? null) : null; if (!is_array($w)) continue;
            $by = is_array($w['by_ip'] ?? null) ? $w['by_ip'] : [];
            foreach ($by as $ip => $x) { $ip = (string)$ip; if ($ip === '') continue; $k = (int)($x['n'] ?? 0); $l = (int)($x['login'] ?? 0); $seen += $k; $lh += $l;
                if (!isset($a[$ip])) $a[$ip] = [0, 0]; $a[$ip][0] += $k; $a[$ip][1] += $l; } }
        uasort($a, fn($x, $y) => $y[0] <=> $x[0]); $ips = array_keys($a);
        $new = 0; foreach (array_chunk($ips, 300) as $ch) { $s = $db->prepare('SELECT COUNT(*) FROM itops_ip_seen WHERE ip IN (' . implode(',', array_fill(0, count($ch), '?')) . ') AND first_seen >= ?'); $s->execute(array_merge($ch, [date('Y-m-d H:i:s', $hs)])); $new += (int)$s->fetchColumn(); }
        $g = $ips ? itops_ip_geo($db, array_slice($ips, 0, 400), $c, $budget) : ['geo' => []];
        $cc = []; $org = []; $located = 0;
        foreach ($ips as $ip) { $x = $g['geo'][$ip] ?? null; if (!$x || ($x['kind'] ?? '') !== 'public' || empty($x['country'])) continue; $located++; $cc[$x['country']] = ($cc[$x['country']] ?? 0) + 1;
            if (!empty($x['org'])) $org[$x['org']] = ($org[$x['org']] ?? 0) + 1; }
        arsort($cc); arsort($org);
        // countries outside the allowed ones and not seen in the 7 days before this hour
        $past = []; $p = $db->prepare('SELECT countries FROM itops_net_hour WHERE hour >= ? AND hour < ?'); $p->execute([date('Y-m-d H:i:s', $hs - 7 * 86400), date('Y-m-d H:i:s', $hs)]);
        foreach ($p->fetchAll(PDO::FETCH_COLUMN) as $j) foreach ((array)json_decode((string)$j, true) as $k => $v) $past[$k] = 1;
        $newCc = array_values(array_filter(array_keys($cc), fn($k) => !in_array($k, $sec['countries'], true) && !isset($past[$k])));
        $outCc = array_values(array_filter(array_keys($cc), fn($k) => !in_array($k, $sec['countries'], true)));
        // the usual number of addresses: the same hour of the previous 7 days (else every hour of them)
        $b = $db->prepare('SELECT addrs, HOUR(hour) h FROM itops_net_hour WHERE hour >= ? AND hour < ? AND samples >= 10'); $b->execute([date('Y-m-d H:i:s', $hs - 7 * 86400), date('Y-m-d H:i:s', $hs)]);
        $same = []; $all = []; $hh = (int)date('G', $hs); foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) { $all[] = (int)$r['addrs']; if ((int)$r['h'] === $hh) $same[] = (int)$r['addrs']; }
        $med = function (array $v) { sort($v); $n = count($v); return $n ? ($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2) : null; };
        $base = count($same) >= 3 ? $med($same) : (count($all) >= 12 ? $med($all) : null);
        $loginAddrs = count(array_filter($a, fn($x) => $x[1] > 0)); $n = count($a);
        $topOrg = $org ? array_key_first($org) : null; $topOrgN = $topOrg !== null ? $org[$topOrg] : 0;
        $lv = ['good' => 0, 'busy' => 1, 'high' => 2, 'crit' => 3]; $level = 'good'; $why = [];
        $up = function (string $l, string $t) use (&$level, &$why, $lv) { $why[] = [$l, $t]; if ($lv[$l] > $lv[$level]) $level = $l; };
        if ($base !== null) { $ratio = $n / max(5, $base);
            if ($ratio >= 10 && $n >= 100) $up('crit', $n . ' addresses, about ' . round($ratio) . ' times the usual ' . round($base) . ' for this hour');
            elseif ($ratio >= 5 && $n >= 50) $up('high', $n . ' addresses, about ' . round($ratio) . ' times the usual ' . round($base) . ' for this hour');
            elseif ($ratio >= 3 && $n >= 20) $up('busy', $n . ' addresses, about ' . round($ratio, 1) . ' times the usual ' . round($base) . ' for this hour'); }
        $nc = count($newCc);
        if ($nc >= 10) $up('crit', $nc . ' countries outside the allowed ones not seen in the previous 7 days'); elseif ($nc >= 6) $up('high', $nc . ' new countries outside the allowed ones'); elseif ($nc >= 3) $up('busy', $nc . ' new countries outside the allowed ones');
        if ($loginAddrs >= 40) $up('crit', $loginAddrs . ' addresses asked for sign-in pages (wp-login, xmlrpc, login)'); elseif ($loginAddrs >= 15) $up('high', $loginAddrs . ' addresses asked for sign-in pages'); elseif ($loginAddrs >= 5) $up('busy', $loginAddrs . ' addresses asked for sign-in pages');
        if ($topOrg !== null && $located >= 10 && $topOrgN >= 10 && $topOrgN / $located >= 0.5) $up($topOrgN >= 20 ? 'high' : 'busy', $topOrgN . ' of the ' . $located . ' located addresses come from one network: ' . $topOrg);
        $top = []; foreach (array_slice($ips, 0, 8) as $ip) { $x = $g['geo'][$ip] ?? []; $top[] = [$ip, $a[$ip][0], $x['country'] ?? null, $x['city'] ?? null, $x['org'] ?? null, $a[$ip][1]]; }
        $row = ['hour' => date('Y-m-d H:i:s', $hs), 'samples' => $samples, 'seen' => $seen, 'addrs' => $n, 'new_addrs' => $new, 'located' => $located, 'countries' => json_encode($cc), 'new_countries' => json_encode($newCc),
                'login_hits' => $lh, 'login_addrs' => $loginAddrs, 'top_org' => $topOrg !== null ? mb_substr($topOrg, 0, 160) : null, 'top_org_addrs' => $topOrgN, 'baseline' => $base, 'level' => $level,
                'reasons' => json_encode($why, JSON_UNESCAPED_UNICODE), 'top_ips' => json_encode($top, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')];
        $cols = array_keys($row);
        $db->prepare('REPLACE INTO itops_net_hour (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return $row + ['outside' => $outCc];
    }

    /** The bell of the administrators (and Teams, when its alerts are on), the Event Log, and the e-mail when switched on. */
    function itops_nw_alert(PDO $db, array $sec, string $title, string $body, string $level = 'warning'): void {
        if (!function_exists('itops_log') && is_file(__DIR__ . '/EventLog.php')) @require_once __DIR__ . '/EventLog.php';
        if (function_exists('itops_log')) { try { itops_log($level, 'server', $title . ' — ' . $body); } catch (Throwable $e) {} }
        if (empty($sec['notify'])) return;
        try {
            $admins = $db->query("SELECT u.id, u.email, u.display_name FROM itops_users u JOIN itops_roles r ON r.id = u.role_id WHERE r.slug IN ('super_admin','admin') AND u.status='active'")->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare('INSERT INTO itops_notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)');
            foreach ($admins as $a) {
                $ins->execute([(int)$a['id'], $title, mb_substr($body, 0, 1000), $level === 'error' ? 'error' : 'warning', 'server_perf']);
                if (!empty($sec['email']) && !empty($a['email'])) { if (!function_exists('itops_email') && is_file(__DIR__ . '/EmailTemplates.php')) @require_once __DIR__ . '/EmailTemplates.php';
                    if (function_exists('itops_email')) { try { itops_email('server_security_alert', (string)$a['email'], ['subject_line' => $title, 'detail' => $body, 'recipient_name' => (string)$a['display_name'],
                        'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#server_perf' : ''], ['user_id' => (int)$a['id']]); } catch (Throwable $e) {} } }
            }
        } catch (Throwable $e) {}
    }

    /**
     * Every 5 minutes: check the sign-ins of the last 2 days against the allowed countries, sum up this hour's traffic
     * (and the last hour once more), alert once per finding, and fill in the coordinates of addresses looked up before.
     */
    function itops_nw_run(PDO $db, array &$c, int $now): array {
        itops_nw_tables($db); $sec = itops_nw_cfg($c); $st = is_array($c['state']['nw'] ?? null) ? $c['state']['nw'] : []; $out = ['flags' => 0, 'pending' => 0, 'notes' => []];
        if (empty($st['since'])) $st['since'] = $now;
        $geoOn = !empty($c['geo']);
        if (!$geoOn) $out['notes'][] = 'The location lookup is off, so countries cannot be told (Settings → Visitors\' location and network).';
        // 1. sign-ins from outside the allowed countries
        if ($sec['signins']) {
            $rows = itops_nw_signins($db, $now - 48 * 3600, $out['notes']);
            $pub = array_values(array_unique(array_filter(array_column($rows, 'ip'), fn($ip) => !itops_ip_private($ip))));
            $g = $geoOn && $pub ? itops_ip_geo($db, $pub, $c, 5) : ['geo' => []];
            $ins = $db->prepare('INSERT IGNORE INTO itops_signin_flags (source, user_name, display, ip, country, region, city, org, lat, lng, at, ok, status, excused, excuse, alerted, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $r) { if (itops_ip_private($r['ip'])) continue; $x = $g['geo'][$r['ip']] ?? null;
                if (!$x || empty($x['country'])) { $out['pending']++; continue; }
                if (in_array(strtoupper($x['country']), $sec['countries'], true)) continue;
                $ex = itops_nw_excused($sec, $r['source'], $r['user_name'], $x['country'], $r['at']);
                $ins->execute([$r['source'], $r['user_name'], $r['display'], $r['ip'], $x['country'], $x['region'] ?? null, $x['city'] ?? null, $x['org'] ?? null, $x['lat'] ?? null, $x['lng'] ?? null, $r['at'], $r['ok'], $r['status'],
                               $ex ? 1 : 0, $ex ? mb_substr('Allowed: ' . ($ex['note'] ?? '') . ' (until ' . ($ex['until'] ?? '') . ')', 0, 255) : null, $ex ? 1 : 0, date('Y-m-d H:i:s', $now)]);
                $out['flags'] += $ins->rowCount(); }
            $new = $db->query('SELECT * FROM itops_signin_flags WHERE alerted = 0 AND excused = 0 ORDER BY at LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
            if ($new) {
                $lines = array_map(fn($f) => itops_nw_flag_text($f), $new); $ok = count(array_filter($new, fn($f) => (int)$f['ok'] === 1));
                $title = count($new) === 1 ? ((int)$new[0]['ok'] ? 'Sign-in from ' : 'Failed sign-in from ') . itops_country_name($new[0]['country']) . ': ' . ($new[0]['display'] ?: $new[0]['user_name'])
                                           : count($new) . ' sign-ins from outside the allowed countries' . ($ok ? ' (' . $ok . ' worked)' : ' (all failed)');
                itops_nw_alert($db, $sec, $title, implode("\n", $lines) . "\nIf the person is travelling, allow them on Server performance → Details → Security; if not, change their password and check what they did.", $ok ? 'error' : 'warning');
                $db->exec('UPDATE itops_signin_flags SET alerted = 1 WHERE id IN (' . implode(',', array_map('intval', array_column($new, 'id'))) . ')');
            }
        }
        // 2. this hour's traffic (and the previous hour once more, until it is complete)
        if ($sec['traffic']) {
            $hs = strtotime(date('Y-m-d H:00:00', $now));
            if ((int)($st['hour_done'] ?? 0) < $hs - 3600) { itops_nw_hour($db, $hs - 3600, $c, $sec, 0); $st['hour_done'] = $hs - 3600; }
            $h = itops_nw_hour($db, $hs, $c, $sec, $geoOn ? 3 : 0);
            $lvRank = ['good' => 0, 'busy' => 1, 'high' => 2, 'crit' => 3];
            if ($lvRank[$h['level']] >= 2 && (int)($st['alert_hour'] ?? 0) !== $hs) {
                $why = array_map(fn($w) => $w[1], json_decode($h['reasons'], true) ?: []);
                $top = array_map(fn($t) => $t[0] . ($t[2] ? ' (' . itops_country_name($t[2]) . ($t[4] ? ', ' . $t[4] : '') . ')' : '') . ' — ' . $t[1] . ' seen' . ($t[5] ? ', ' . $t[5] . ' on sign-in pages' : ''), array_slice(json_decode($h['top_ips'], true) ?: [], 0, 5));
                itops_nw_alert($db, $sec, 'Unusual traffic (' . ($h['level'] === 'crit' ? 'critical' : 'high') . ')', implode('; ', $why) . ".\nTop addresses: " . implode('; ', $top) . "\nTo block an address: WHM → cPHulk or the firewall (CSF / Imunify360).", $h['level'] === 'crit' ? 'error' : 'warning');
                $st['alert_hour'] = $hs;
            }
            $out['traffic'] = ['level' => $h['level'], 'addrs' => $h['addrs']];
        }
        // 3. the coordinates of addresses looked up before the map (a few each run)
        if ($geoOn) { try { $old = $db->query("SELECT g.ip FROM itops_ip_geo g JOIN itops_ip_seen s ON s.ip = g.ip WHERE g.kind = 'public' AND g.geo_v = 0 ORDER BY s.last_seen DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
            if ($old) itops_ip_geo($db, $old, $c, 2); } catch (Throwable $e) {} }
        // 4. keep: addresses 90 days, hours 400 days, flags a year
        if ($now - (int)($st['purge'] ?? 0) > 86400) { try { $db->prepare('DELETE FROM itops_ip_seen WHERE last_seen < ?')->execute([date('Y-m-d H:i:s', $now - 90 * 86400)]);
            $db->prepare('DELETE FROM itops_net_hour WHERE hour < ?')->execute([date('Y-m-d H:i:s', $now - 400 * 86400)]); $db->prepare('DELETE FROM itops_signin_flags WHERE at < ?')->execute([date('Y-m-d H:i:s', $now - 366 * 86400)]); } catch (Throwable $e) {} $st['purge'] = $now; }
        $st['at'] = $now; $st['notes'] = $out['notes']; $st['pending'] = $out['pending']; $c['state']['nw'] = $st;
        return $out;
    }
    function itops_nw_flag_text(array $f): string {
        $where = trim(implode(', ', array_filter([$f['city'] ?? '', itops_country_name($f['country'] ?? '')])), ', ');
        return ((int)$f['ok'] ? '' : 'FAILED — ') . ($f['display'] ? $f['display'] . ' (' . $f['user_name'] . ')' : $f['user_name']) . ' — ' . ($f['source'] === 'vtiger' ? 'vTiger' : 'this app') . ' — from ' . $where
             . ($f['org'] ? ', network ' . $f['org'] : '') . ' — ' . $f['ip'] . ' — ' . date('m/d/Y g:i A', strtotime((string)$f['at']));
    }

    /** Status: the sign-ins from outside in the last 24 hours and this hour's traffic level. */
    function itops_nw_brief(PDO $db, array $c): array {
        if (!itops_nw_ready($db)) return ['ready' => false];
        $sec = itops_nw_cfg($c); $st = $c['state']['nw'] ?? [];
        $f = $db->prepare('SELECT COUNT(*) n, SUM(ok) ok, MAX(at) last FROM itops_signin_flags WHERE excused = 0 AND at >= ?'); $f->execute([date('Y-m-d H:i:s', time() - 86400)]); $fr = $f->fetch(PDO::FETCH_ASSOC);
        $lastF = $db->query('SELECT * FROM itops_signin_flags WHERE excused = 0 ORDER BY at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        $h = $db->query('SELECT * FROM itops_net_hour ORDER BY hour DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        $learning = empty($st['since']) || time() - (int)$st['since'] < 3 * 86400;
        return ['ready' => true, 'geo' => !empty($c['geo']), 'signins_on' => (bool)$sec['signins'], 'traffic_on' => (bool)$sec['traffic'], 'countries' => $sec['countries'],
                'flags_24h' => (int)($fr['n'] ?? 0), 'flags_ok_24h' => (int)($fr['ok'] ?? 0), 'last_flag' => $lastF ? itops_nw_flag_pub($lastF) : null,
                'hour' => $h ? ['hour' => $h['hour'], 'level' => $h['level'], 'addrs' => (int)$h['addrs'], 'baseline' => $h['baseline'] !== null ? (float)$h['baseline'] : null, 'reasons' => json_decode((string)$h['reasons'], true) ?: [],
                                'login_addrs' => (int)$h['login_addrs'], 'new_countries' => json_decode((string)$h['new_countries'], true) ?: []] : null,
                'learning' => $learning, 'checked_at' => isset($st['at']) ? date('Y-m-d H:i:s', (int)$st['at']) : null, 'notes' => $st['notes'] ?? []];
    }
    function itops_nw_flag_pub(array $f): array {
        return ['id' => (int)$f['id'], 'source' => $f['source'], 'user_name' => $f['user_name'], 'display' => $f['display'], 'ip' => $f['ip'], 'country' => $f['country'], 'country_name' => itops_country_name($f['country']),
                'region' => $f['region'], 'city' => $f['city'], 'org' => $f['org'], 'lat' => $f['lat'] !== null ? (float)$f['lat'] : null, 'lng' => $f['lng'] !== null ? (float)$f['lng'] : null,
                'at' => $f['at'], 'ok' => (int)$f['ok'] === 1, 'status' => $f['status'], 'excused' => (int)$f['excused'] === 1, 'excuse' => $f['excuse']];
    }

    /**
     * The map of a period: every located place (city) with the load its addresses sent, how many addresses, sign-in-page
     * askers, networks; "new" when all its addresses were first seen in the last 7 days (after 7 days of watching);
     * and the sign-ins from outside the allowed countries in the period.
     */
    function itops_nw_map(PDO $db, string $from, string $to, array $c, string $site = ''): array {
        itops_nw_tables($db); $sec = itops_nw_cfg($c); $st = $c['state']['nw'] ?? [];
        $r = itops_sp_ips($db, $from, $to, (float)$c['cpu_alert'], 0, $site);
        $ips = array_column($r['ips'], null, 'ip'); $list = array_keys($ips);
        $geo = []; foreach (array_chunk($list, 300) as $ch) { $q = $db->prepare('SELECT * FROM itops_ip_geo WHERE ip IN (' . implode(',', array_fill(0, count($ch), '?')) . ')'); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $g) $geo[$g['ip']] = $g; }
        if (!empty($c['geo'])) { $miss = array_values(array_filter($list, fn($ip) => (!isset($geo[$ip]) || (($geo[$ip]['kind'] ?? '') === 'public' && empty($geo[$ip]['geo_v']))) && !itops_ip_private($ip))); if ($miss) { $gg = itops_ip_geo($db, array_slice($miss, 0, 60), $c, 8); foreach ($gg['geo'] as $k => $v) $geo[$k] = $v; } }
        $first = []; foreach (array_chunk($list, 300) as $ch) { $q = $db->prepare('SELECT ip, first_seen FROM itops_ip_seen WHERE ip IN (' . implode(',', array_fill(0, count($ch), '?')) . ')'); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $first[$k] = $v; }
        $watched = !empty($st['since']) && time() - (int)$st['since'] >= 7 * 86400; $cut = date('Y-m-d H:i:s', time() - 7 * 86400);
        $pl = []; $private = 0; $unknown = 0; $tot = 0; $ipUsers = itops_nw_ip_users($db, $from, $to);
        foreach ($ips as $ip => $y) { $tot += $y['seen']; $g = $geo[$ip] ?? null;
            if (itops_ip_private($ip) || ($g['kind'] ?? '') === 'private') { $private++; continue; }
            if (!$g || $g['lat'] === null || $g['lat'] === '' || empty($g['country'])) { $unknown++; continue; }
            $k = $g['country'] . '|' . ($g['region'] ?? '') . '|' . ($g['city'] ?? '');
            if (!isset($pl[$k])) $pl[$k] = ['key' => $k, 'country' => $g['country'], 'country_name' => itops_country_name($g['country']), 'region' => $g['region'], 'city' => $g['city'], 'lat' => (float)$g['lat'], 'lng' => (float)$g['lng'],
                                            'seen' => 0, 'addrs' => 0, 'login' => 0, 'hot' => 0, 'orgs' => [], 'ips' => [], 'users' => [], 'first' => null, 'allowed' => in_array($g['country'], $sec['countries'], true)];
            $p = &$pl[$k]; $p['seen'] += $y['seen']; $p['addrs']++; $p['login'] += (int)$y['login']; $p['hot'] += (int)$y['hot'];
            if (!empty($g['org'])) $p['orgs'][$g['org']] = ($p['orgs'][$g['org']] ?? 0) + 1;
            if (count($p['ips']) < 6) $p['ips'][] = [$ip, $y['seen'], $g['org'] ?? null];
            foreach ($ipUsers[$ip] ?? [] as $u) $p['users'][$u[2] . '|' . strtolower($u[0])] = [$u[0], $u[1], $u[2]];
            $fs = $first[$ip] ?? null; if ($fs !== null && ($p['first'] === null || $fs < $p['first'])) $p['first'] = $fs; unset($p); }
        $out = [];
        foreach ($pl as $p) { $p['users'] = array_slice(array_values($p['users']), 0, 12); arsort($p['orgs']); $p['orgs'] = array_slice(array_keys($p['orgs']), 0, 3); $p['share'] = $tot ? round(100 * $p['seen'] / $tot, 1) : 0; $p['new'] = $watched && $p['first'] !== null && $p['first'] >= $cut; $out[] = $p; }
        usort($out, fn($a, $b) => $b['seen'] <=> $a['seen']);
        $f = $db->prepare('SELECT * FROM itops_signin_flags WHERE at >= ? AND at <= ? ORDER BY at DESC LIMIT 300'); $f->execute([$from, $to]);
        return ['from' => $from, 'to' => $to, 'places' => $out, 'total_seen' => $tot, 'addresses' => count($ips), 'private' => $private, 'unknown' => $unknown, 'geo' => !empty($c['geo']),
                'allowed' => $sec['countries'], 'watched' => $watched, 'signins' => array_map('itops_nw_flag_pub', $f->fetchAll(PDO::FETCH_ASSOC)), 'with_web' => $r['with_web'], 'site' => $r['site'], 'sites' => $r['sites']];
    }
}
