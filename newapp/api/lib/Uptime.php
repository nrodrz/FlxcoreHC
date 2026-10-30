<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — uptime monitoring (BT61): websites and addresses you add, and every WHM/cPanel account's site.
 *
 * Monitors (opsh_uptime_monitors) are checked on their own interval by the uptime runner (cron-uptime.php every
 * minute, and the main scheduler / in-app scheduler as a fallback). A check (opsh_uptime_checks) records up/down, the
 * response time, the HTTP status, the error and the SSL certificate's expiry. A monitor is DOWN after N failed checks
 * in a row (2 to start, so one slow answer is not an outage); the outage is an incident (opsh_uptime_incidents) from
 * the first failed check to the first good one. Administrators are told once when a monitor goes down and once when it
 * is back (bell + email "uptime_alert"), and once when its certificate is about to expire.
 *
 * WHM/cPanel: with the WHM connection of Server Guardian, every account becomes a monitor of its main domain (kind
 * "cpanel"); a suspended account is shown as suspended (not an outage), a removed account stops being checked; the WHM
 * server itself is a monitor too. The accounts are read again every 6 hours, or on "Sync from WHM".
 *
 * Availability for a period = time not inside an incident / time the monitor existed in the period. The Scorecard reads
 * it per week (all monitors, websites, cPanel accounts, one monitor, incidents, downtime minutes, response time).
 *
 * Safety: only http:// and https:// are checked; cloud metadata and link-local addresses are refused; the page content
 * is never stored or shown (only whether the keyword was found).
 */

if (!function_exists('opsh_uptime_ready')) {

    function opsh_uptime_ready(PDO $db): bool {
        static $ok = null; if ($ok !== null) return $ok;
        try { $db->query('SELECT 1 FROM opsh_uptime_monitors LIMIT 1'); return $ok = true; } catch (Throwable $e) { return $ok = false; }
    }
    /** BT70: who sees which monitors. Setting uptime_visibility = {roles: {role_id: {mode, ids}}, users: {user_id: {mode, ids}}};
     *  mode all | list (ids) | scorecard (the servers chosen for the Scorecard). A person's own entry beats their role's. */
    function opsh_uptime_vis_cfg(PDO $db): array {
        try { $j = json_decode((string)$db->query("SELECT sval FROM opsh_settings WHERE skey='uptime_visibility'")->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        $j = is_array($j) ? $j : []; return ['roles' => (array)($j['roles'] ?? []), 'users' => (array)($j['users'] ?? [])];
    }
    function opsh_uptime_vis_rule(array $cfg, array $user): array {
        $u = $cfg['users'][(string)(int)$user['id']] ?? null; if (is_array($u)) return $u + ['by' => 'person'];
        $r = $cfg['roles'][(string)(int)($user['role_id'] ?? 0)] ?? null; if (is_array($r)) return $r + ['by' => 'role'];
        return ['mode' => 'all', 'ids' => [], 'by' => 'default'];
    }
    /** The monitor ids this person may see, or null for all (Super Admin, Uptime · Edit, or "all"). */
    function opsh_uptime_visible(PDO $db, array $user): ?array {
        if (($user['role_slug'] ?? '') === 'super_admin' || (function_exists('opsh_can') && opsh_can($user, 'uptime', 'edit'))) return null;
        $r = opsh_uptime_vis_rule(opsh_uptime_vis_cfg($db), $user);
        if (($r['mode'] ?? 'all') === 'list') return array_values(array_map('intval', (array)($r['ids'] ?? [])));
        if (($r['mode'] ?? 'all') === 'scorecard') return array_map('intval', $db->query('SELECT id FROM opsh_uptime_monitors WHERE on_scorecard=1')->fetchAll(PDO::FETCH_COLUMN));
        return null;
    }
    function opsh_uptime_cfg(PDO $db): array {
        $d = ['whm_sync' => true, 'whm_interval' => 15, 'whm_https' => true, 'notify' => true, 'email' => true, 'keep_days' => 400];
        try { $j = json_decode((string)$db->query("SELECT sval FROM opsh_settings WHERE skey='uptime_cfg'")->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        if (is_array($j)) foreach ($d as $k => $v) if (array_key_exists($k, $j)) $d[$k] = is_bool($v) ? (bool)$j[$k] : (int)$j[$k];
        return $d;
    }

    // ── one monitor ──────────────────────────────────────────────────────────────────────────────────────────────

    function opsh_uptime_row(array $r): array {
        return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'kind' => (string)$r['kind'], 'url' => (string)$r['url'], 'method' => (string)$r['method'],
            'expect_status' => (string)$r['expect_status'], 'keyword' => (string)$r['keyword'], 'interval_min' => (int)$r['interval_min'], 'timeout_s' => (int)$r['timeout_s'],
            'verify_ssl' => (bool)$r['verify_ssl'], 'ssl_warn_days' => (int)$r['ssl_warn_days'], 'fail_threshold' => (int)$r['fail_threshold'], 'alert' => (bool)$r['alert'],
            'active' => (bool)$r['active'], 'cpanel_user' => $r['cpanel_user'] !== null ? (string)$r['cpanel_user'] : null, 'source' => (string)$r['source'], 'notes' => (string)($r['notes'] ?? ''),
            'state' => (string)$r['state'], 'state_since' => $r['state_since'], 'last_check' => $r['last_check'], 'last_ms' => $r['last_ms'] === null ? null : (int)$r['last_ms'],
            'last_code' => $r['last_code'] === null ? null : (int)$r['last_code'], 'last_error' => (string)($r['last_error'] ?? ''), 'ssl_expires' => $r['ssl_expires'],
            'consecutive_fail' => (int)$r['consecutive_fail'], 'suspended' => (bool)$r['suspended'], 'created_at' => $r['created_at'],
            'on_scorecard' => (bool)($r['on_scorecard'] ?? false)];
    }
    function opsh_uptime_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM opsh_uptime_monitors WHERE id=?'); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ? opsh_uptime_row($r) : null; }

    /** Is this address one the server may call? Returns an error sentence, or null. */
    function opsh_uptime_url_problem(string $url): ?string { $t = opsh_uptime_target($url); return empty($t['unresolved']) ? $t['error'] : null; }   // a name that does not resolve yet can be saved; its check says so
    /**
     * BT90→BT91 (code audit M-23): the address a monitor checks. Servers on your own network may be monitored, but never a
     * link-local, "this network" or broadcast address (cloud metadata lives there), also when written as IPv6
     * (::ffff:169.254.169.254, ::169.254.169.254, fe80::…). The name is looked up once (IPv4 and IPv6, every answer checked)
     * and the request is pinned to the address checked (CURLOPT_RESOLVE), so the name cannot point elsewhere in between.
     * Returns ['error' => sentence|null, 'resolve' => 'host:port:ip'|null].
     */
    function opsh_uptime_ip_bad(string $ip): bool {
        $ip = trim($ip, '[] ');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $b = @inet_pton($ip); if ($b === false) return true;
            if (substr($b, 0, 10) === str_repeat("\0", 10) && (substr($b, 10, 2) === "\xff\xff" || substr($b, 10, 2) === "\0\0")) {   // ::ffff:a.b.c.d and ::a.b.c.d
                $v4 = inet_ntop(substr($b, 12));
                if (substr($b, 10, 2) === "\0\0") { if ($v4 === '0.0.0.0') return true; if ($v4 === '0.0.0.1') return false; }   // :: refused; ::1 is this server, like 127.0.0.1
                return opsh_uptime_ip_bad($v4);
            }
            $b0 = ord($b[0]); $b1 = ord($b[1]);
            if (strtolower(bin2hex(substr($b, 0, 4))) === 'fd00' . '0ec2') return true;   // BT124 (review OPS-4): AWS IPv6 metadata (fd00:ec2::/32)
            return ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) || $b0 === 0xff;   // fe80::/10 link-local, multicast
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        // BT124 (review OPS-4): and the other cloud metadata addresses (100.100.100.200, 192.0.0.0/24)
        return (bool)preg_match('/^(169\.254\.|0\.|22[4-9]\.|23\d\.|192\.0\.0\.)/', $ip) || $ip === '255.255.255.255' || $ip === '100.100.100.200';
    }
    function opsh_uptime_target(string $url): array {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return ['error' => 'The address starts with http:// or https:// and has a host name.', 'resolve' => null];
        if (!empty($p['user']) || !empty($p['pass'])) return ['error' => 'Leave the user name and password out of the address.', 'resolve' => null];
        $h = strtolower(trim($p['host'], '[]'));
        if (in_array($h, ['metadata.google.internal', 'metadata', '169.254.169.254'], true)) return ['error' => 'That address cannot be monitored.', 'resolve' => null];
        $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        if (filter_var($h, FILTER_VALIDATE_IP)) return opsh_uptime_ip_bad($h) ? ['error' => 'That address cannot be monitored (a link-local or reserved address).', 'resolve' => null] : ['error' => null, 'resolve' => null];
        $ips = [];
        foreach ((array)@dns_get_record($h, DNS_A | DNS_AAAA) as $r) { if (!empty($r['ip'])) $ips[] = $r['ip']; if (!empty($r['ipv6'])) $ips[] = $r['ipv6']; }
        if (!$ips) $ips = (array)(@gethostbynamel($h) ?: []);
        foreach ($ips as $ip) if (opsh_uptime_ip_bad($ip)) return ['error' => 'That address cannot be monitored (a link-local or reserved address).', 'resolve' => null];
        if (!$ips) return ['error' => 'The name ' . $h . ' does not resolve to an address.', 'resolve' => null, 'unresolved' => true];   // BT124 (review OPS-4): never let curl look it up again (a name that answers differently the second time)
        $v4s = array_values(array_filter($ips, fn($x) => strpos($x, ':') === false)); $pin = $v4s[0] ?? $ips[0];   // IPv4 first, as curl would
        return ['error' => null, 'resolve' => $h . ':' . $port . ':' . (strpos($pin, ':') !== false ? '[' . $pin . ']' : $pin)];
    }
    function opsh_uptime_status_ok(int $code, string $expect): bool {
        if ($code <= 0) return false;
        foreach (array_filter(array_map('trim', explode(',', $expect ?: '200-399'))) as $part) {
            if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $part, $m)) { if ($code >= (int)$m[1] && $code <= (int)$m[2]) return true; }
            elseif (ctype_digit($part) && (int)$part === $code) return true;
        }
        return false;
    }
    /** Check a person's monitor settings. Throws InvalidArgumentException with a sentence. */
    function opsh_uptime_clean(array $in, ?array $cur): array {
        $bad = fn($m) => throw new InvalidArgumentException($m);
        $o = $cur ?? ['name' => '', 'kind' => 'http', 'url' => '', 'method' => 'GET', 'expect_status' => '200-399', 'keyword' => '', 'interval_min' => 5, 'timeout_s' => 10, 'verify_ssl' => true,
                      'ssl_warn_days' => 14, 'fail_threshold' => 2, 'alert' => true, 'active' => true, 'notes' => ''];
        foreach (['name' => 120, 'url' => 500, 'keyword' => 200, 'notes' => 1000, 'expect_status' => 40] as $k => $max) if (array_key_exists($k, $in)) {
            if (!is_string($in[$k])) $bad('The monitor is not in the expected form (text values).');
            $v = trim(preg_replace('/\s+/u', ' ', $in[$k]) ?? ''); if (mb_strlen($v) > $max) $bad('That text is too long (' . $max . ' characters at most).'); $o[$k] = $v;
        }
        if (($cur['source'] ?? 'manual') !== 'whm') {
            if ($o['url'] === '') $bad('Type the address to check (https://…).');
            if ($p = opsh_uptime_url_problem($o['url'])) $bad($p);
        }
        if ($o['name'] === '') $o['name'] = (string)(parse_url($o['url'], PHP_URL_HOST) ?: $o['url']);
        if (array_key_exists('method', $in)) { if (!in_array($in['method'], ['GET', 'HEAD'], true)) $bad('Check with GET or HEAD.'); $o['method'] = $in['method']; }
        if (!preg_match('/^\s*\d{3}(\s*-\s*\d{3})?(\s*,\s*\d{3}(\s*-\s*\d{3})?)*\s*$/', $o['expect_status'])) $bad('Expected answers are HTTP codes such as 200-399 or 200,301.');
        if ($o['keyword'] !== '' && $o['method'] === 'HEAD') $bad('A word to look for needs the GET method (HEAD has no page).');
        foreach (['interval_min' => [1, 1440, 'Check every 1 to 1,440 minutes.'], 'timeout_s' => [2, 60, 'Wait 2 to 60 seconds for an answer.'], 'ssl_warn_days' => [0, 90, 'Warn 0 to 90 days before the certificate expires.'], 'fail_threshold' => [1, 5, 'Count it as down after 1 to 5 failed checks in a row.']] as $k => [$lo, $hi, $m])
            if (array_key_exists($k, $in)) { if (!is_numeric($in[$k]) || (int)$in[$k] < $lo || (int)$in[$k] > $hi || (float)$in[$k] != (int)$in[$k]) $bad($m); $o[$k] = (int)$in[$k]; }
        foreach (['verify_ssl', 'alert', 'active'] as $k) if (array_key_exists($k, $in)) { if (!is_bool($in[$k])) $bad('"' . $k . '" is on or off.'); $o[$k] = $in[$k]; }
        return $o;
    }

    // ── checking ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Check several monitors at once (curl_multi). Each item: monitor row array. Returns [id => result] with
     * ok, ms, code, error, ssl_expires (Y-m-d|null), keyword_found (bool|null).
     */
    /** One round of requests in parallel, no redirects followed: [id => [monitor, url]] → [id => raw result]. */
    function opsh_uptime_round(array $items): array {
        $mh = curl_multi_init(); $hs = []; $bodies = []; $raw = [];
        foreach ($items as $id => $it) {
            [$m, $url] = $it; $pin = $it[2] ?? null;
            $ch = curl_init($url); $bodies[$id] = '';
            $opt = [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => max(2, (int)$m['timeout_s']), CURLOPT_CONNECTTIMEOUT => min(10, max(2, (int)$m['timeout_s'])),
                    CURLOPT_USERAGENT => 'Uptime-Check/1.0',   /* review OPS-18: no product name shown to the sites checked */ CURLOPT_SSL_VERIFYPEER => (bool)$m['verify_ssl'], CURLOPT_SSL_VERIFYHOST => $m['verify_ssl'] ? 2 : 0, CURLOPT_CERTINFO => true,
                    CURLOPT_NOSIGNAL => true, CURLOPT_HEADER => false, CURLOPT_ENCODING => '',
                    CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$bodies, $id) { if (strlen($bodies[$id]) < 1048576) $bodies[$id] .= $chunk; return strlen($chunk); }];
            if (defined('CURLOPT_PROTOCOLS')) { $opt[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS; }
            if ($m['method'] === 'HEAD') $opt[CURLOPT_NOBODY] = true;
            if ($pin) $opt[CURLOPT_RESOLVE] = [$pin];   // BT91 (M-23): the address checked, no second lookup
            curl_setopt_array($ch, $opt); curl_multi_add_handle($mh, $ch); $hs[$id] = [$ch, $m];
        }
        $running = null; $res = [];
        do { $st = curl_multi_exec($mh, $running); while ($info = curl_multi_info_read($mh)) $res[spl_object_id($info['handle'])] = (int)$info['result']; if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
        while ($info = curl_multi_info_read($mh)) $res[spl_object_id($info['handle'])] = (int)$info['result'];
        foreach ($hs as $id => [$ch, $m]) {
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $errno = $res[spl_object_id($ch)] ?? curl_errno($ch); $err = $errno ? (curl_error($ch) ?: (function_exists('curl_strerror') ? curl_strerror($errno) : 'Connection error ' . $errno)) : '';
            $exp = null; $ci = @curl_getinfo($ch, CURLINFO_CERTINFO);
            if (is_array($ci) && !empty($ci[0])) { $c0 = $ci[0]; $ed = $c0['Expire date'] ?? ($c0['Expire Date'] ?? null); if ($ed && ($t = strtotime($ed))) $exp = date('Y-m-d', $t); }
            $raw[$id] = ['code' => $code, 'ms' => $ms, 'errno' => $errno, 'err' => $err, 'exp' => $exp, 'body' => $bodies[$id], 'redirect' => (string)(curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '')];
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
        return $raw;
    }
    /**
     * Check several monitors at once. Redirects are followed one hop at a time (up to 5), and every address on the way
     * goes through opsh_uptime_url_problem() again, so a redirect cannot lead the checker to a link-local or metadata address.
     */
    function opsh_uptime_check_many(array $mons): array {
        $out = []; if (!$mons) return $out;
        if (!function_exists('curl_multi_init')) { foreach ($mons as $m) $out[$m['id']] = ['ok' => false, 'ms' => null, 'code' => 0, 'error' => 'PHP curl is not installed on this server', 'ssl_expires' => null, 'keyword_found' => null]; return $out; }
        $byId = []; $todo = []; $acc = [];
        foreach ($mons as $m) {
            $tg = opsh_uptime_target($m['url']);
            if ($tg['error']) { $out[$m['id']] = ['ok' => false, 'ms' => null, 'code' => 0, 'error' => $tg['error'], 'ssl_expires' => null, 'keyword_found' => null]; continue; }
            $byId[$m['id']] = $m; $todo[$m['id']] = [$m, $m['url'], $tg['resolve']]; $acc[$m['id']] = ['ms' => 0, 'exp' => null];
        }
        $final = [];
        for ($hop = 0; $todo && $hop <= 5; $hop++) {
            $raw = opsh_uptime_round($todo); $next = [];
            foreach ($raw as $id => $r) {
                $acc[$id]['ms'] += $r['ms']; if ($r['exp']) $acc[$id]['exp'] = $r['exp'];
                if (!$r['errno'] && $r['code'] >= 300 && $r['code'] < 400 && $r['redirect'] !== '' && $hop < 5) {
                    $tg = opsh_uptime_target($r['redirect']);
                    if ($tg['error']) { $final[$id] = ['code' => $r['code'], 'ms' => $acc[$id]['ms'], 'errno' => -1, 'err' => 'Redirected to an address that cannot be monitored', 'body' => '']; continue; }
                    $next[$id] = [$byId[$id], $r['redirect'], $tg['resolve']]; continue;
                }
                $final[$id] = $r; $final[$id]['ms'] = $acc[$id]['ms'];
            }
            $todo = $next;
        }
        foreach ($final as $id => $r) {
            $m = $byId[$id]; $code = $r['code']; $errno = $r['errno']; $err = $r['err']; $ms = $r['ms'];
            $plain = [6 => 'The host name does not resolve (DNS)', 7 => 'Could not connect: the server refused or cannot be reached', 28 => 'No answer within ' . (int)$m['timeout_s'] . ' seconds',
                      35 => 'Secure connection failed (SSL/TLS)', 51 => 'The certificate does not match the host name', 60 => 'The certificate is not trusted (expired or self-signed)', 52 => 'The server closed the connection without answering', 56 => 'The connection was cut while reading the answer'];
            if ($errno > 0 && isset($plain[$errno])) $err = $plain[$errno] . ($errno === 35 || $errno === 60 ? ' — ' . mb_substr($err, 0, 120) : '');
            $kw = null; $ok = !$errno && opsh_uptime_status_ok($code, $m['expect_status']);
            if ($ok && $m['keyword'] !== '' && $m['method'] !== 'HEAD') { $kw = mb_stripos($r['body'], $m['keyword']) !== false; if (!$kw) { $ok = false; $err = 'The word "' . mb_substr($m['keyword'], 0, 40) . '" is not on the page'; } }
            if (!$ok && $err === '') $err = $code ? 'Answered HTTP ' . $code . ' (expected ' . ($m['expect_status'] ?: '200-399') . ')' : 'No answer';
            $out[$id] = ['ok' => $ok, 'ms' => $errno ? null : $ms, 'code' => $code, 'error' => mb_substr($err, 0, 300), 'ssl_expires' => $acc[$id]['exp'], 'keyword_found' => $kw];
        }
        return $out;
    }

    /** Record one result and move the monitor's state; opens / closes incidents and sends the alerts. */
    function opsh_uptime_apply(PDO $db, array $m, array $r, array $cfg): string {
        $now = date('Y-m-d H:i:s');
        $db->prepare('INSERT INTO opsh_uptime_checks (monitor_id, checked_at, ok, ms, code, error) VALUES (?,?,?,?,?,?)')->execute([$m['id'], $now, $r['ok'] ? 1 : 0, $r['ms'], $r['code'] ?: null, $r['ok'] ? null : $r['error']]);
        $state = $m['state']; $since = $m['state_since']; $cf = (int)$m['consecutive_fail']; $first = $m['first_fail_at'] ?? null; $event = '';
        if ($r['ok']) {
            if ($state === 'down') {
                $inc = $db->prepare("SELECT id, started_at FROM opsh_uptime_incidents WHERE monitor_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1"); $inc->execute([$m['id']]); $i = $inc->fetch(PDO::FETCH_ASSOC);
                if ($i) $db->prepare('UPDATE opsh_uptime_incidents SET ended_at=?, duration_s=? WHERE id=?')->execute([$now, max(0, time() - strtotime($i['started_at'])), $i['id']]);
                $event = 'up'; if ($m['alert']) opsh_uptime_alert($db, $cfg, $m, 'up', $i ? (time() - strtotime($i['started_at'])) : null, $r);
            }
            if ($state !== 'up') { $state = 'up'; $since = $now; }
            $cf = 0; $first = null;
        } else {
            $cf++; if ($cf === 1) $first = $now;
            if ($cf >= max(1, (int)$m['fail_threshold']) && $state !== 'down') {
                $state = 'down'; $since = $first ?: $now;
                $db->prepare('INSERT INTO opsh_uptime_incidents (monitor_id, started_at, cause) VALUES (?,?,?)')->execute([$m['id'], $since, mb_substr($r['error'], 0, 300)]);
                $event = 'down'; if ($m['alert']) opsh_uptime_alert($db, $cfg, $m, 'down', null, $r + ['since' => $since]);
            }
        }
        // certificate about to expire: once per expiry date
        $sslAl = $m['ssl_alerted_for'] ?? null;
        if ($r['ssl_expires'] && (int)$m['ssl_warn_days'] > 0 && strtotime($r['ssl_expires']) - time() < (int)$m['ssl_warn_days'] * 86400 && $sslAl !== $r['ssl_expires']) {
            if ($m['alert']) opsh_uptime_alert($db, $cfg, $m, 'ssl', null, $r); $sslAl = $r['ssl_expires'];
        }
        $db->prepare('UPDATE opsh_uptime_monitors SET state=?, state_since=?, last_check=?, last_ms=?, last_code=?, last_error=?, ssl_expires=COALESCE(?, ssl_expires), consecutive_fail=?, first_fail_at=?, ssl_alerted_for=? WHERE id=?')
           ->execute([$state, $since, $now, $r['ms'], $r['code'] ?: null, $r['ok'] ? '' : $r['error'], $r['ssl_expires'], $cf, $first, $sslAl, $m['id']]);
        return $event;
    }
    function opsh_uptime_alert(PDO $db, array $cfg, array $m, string $what, ?int $downS, array $r): void {
        $len = $downS === null ? '' : opsh_uptime_dur($downS);
        $title = ['down' => 'DOWN: ' . $m['name'], 'up' => 'Back up: ' . $m['name'], 'ssl' => 'Certificate expiring: ' . $m['name']][$what];
        $state = ['down' => 'down', 'up' => 'back up after ' . $len, 'ssl' => 'certificate expires ' . date('m/d/Y', strtotime((string)$r['ssl_expires']))][$what];
        $detail = ['down' => 'Since ' . date('m/d/Y g:i A', strtotime((string)($r['since'] ?? date('Y-m-d H:i:s')))) . '. ' . rtrim($r['error'], '.') . '.',
                   'up' => 'It answered again at ' . date('g:i A') . ($r['ms'] !== null ? ' in ' . $r['ms'] . ' ms' : '') . '. The outage lasted ' . $len . '.',
                   'ssl' => 'Renew the certificate (AutoSSL in cPanel, or your certificate provider) before it expires.'][$what];
        if (function_exists('opsh_log')) opsh_log($what === 'down' ? 'error' : ($what === 'ssl' ? 'warning' : 'info'), 'uptime', $title . ' — ' . $detail, ['monitor' => $m['id']]);
        if (empty($cfg['notify'])) return;
        try {
            $admins = $db->query("SELECT u.id, u.email, u.display_name FROM opsh_users u JOIN opsh_roles r ON r.id = u.role_id WHERE r.slug IN ('super_admin','admin','dept_admin') AND u.status='active'")->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare('INSERT INTO opsh_notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)');
            foreach ($admins as $a) {
                $ins->execute([(int)$a['id'], $title, $m['url'] . ' — ' . $detail, $what === 'down' ? 'error' : ($what === 'ssl' ? 'warning' : 'info'), 'uptime']);
                if (!empty($cfg['email']) && !empty($a['email']) && function_exists('opsh_email')) {
                    $stEs = ['down' => 'caído', 'up' => 'disponible de nuevo', 'ssl' => 'el certificado vence el ' . date('d/m/Y', strtotime((string)($r['ssl_expires'] ?? 'now')))][$what];
                    try { opsh_email('uptime_alert', (string)$a['email'], ['monitor' => $m['name'], 'url' => $m['url'], 'state' => $state, 'state_es' => $stEs, 'detail' => $detail, 'recipient_name' => (string)$a['display_name'],
                        'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#uptime' : ''], ['user_id' => (int)$a['id']]); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
    }
    function opsh_uptime_dur(int $s): string {
        if ($s < 90) return $s . ' seconds'; if ($s < 5400) return round($s / 60) . ' minutes';
        if ($s < 172800) { $h = intdiv($s, 3600); $mm = round(($s % 3600) / 60); return $h . ' h' . ($mm ? ' ' . $mm . ' min' : ''); }
        return round($s / 86400, 1) . ' days';
    }

    /**
     * The runner: reads the WHM accounts when due, then checks every active monitor whose interval has passed.
     * One runner at a time (a database lock). Returns a summary.
     */
    function opsh_uptime_run(PDO $db, bool $all = false, ?array $only = null): array {
        $res = ['checked' => 0, 'down' => 0, 'up' => 0, 'events' => [], 'whm' => null, 'busy' => false];
        if (!opsh_uptime_ready($db)) return $res;
        try { $l = $db->query("SELECT GET_LOCK('opsh_uptime_run', 0)")->fetchColumn(); if ((int)$l !== 1) { $res['busy'] = true; return $res; } } catch (Throwable $e) {}
        try {
            $cfg = opsh_uptime_cfg($db);
            if ($only === null && $cfg['whm_sync']) {
                $last = (string)($db->query("SELECT sval FROM opsh_settings WHERE skey='uptime_whm_synced'")->fetchColumn() ?: '');
                if ($last === '' || time() - strtotime($last) > 6 * 3600) { try { $res['whm'] = opsh_uptime_whm_sync($db, $cfg); } catch (Throwable $e) { $res['whm'] = ['ok' => false, 'error' => $e->getMessage()]; } }
            }
            $sql = 'SELECT * FROM opsh_uptime_monitors WHERE active=1 AND suspended=0' . ($only ? ' AND id IN (' . implode(',', array_map('intval', $only)) . ')' : '') . ' ORDER BY last_check IS NOT NULL, last_check';
            $due = [];
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // A failed check that has not yet reached the outage threshold is confirmed on the next run (about a minute later),
                // so an outage is detected quickly even when the monitor checks every 5 or 15 minutes.
                $confirm = (int)$r['consecutive_fail'] > 0 && $r['state'] !== 'down';
                if (!$all && !$only && !$confirm && $r['last_check'] && time() - strtotime($r['last_check']) < (int)$r['interval_min'] * 60 - 20) continue;
                if (!$all && !$only && $confirm && $r['last_check'] && time() - strtotime($r['last_check']) < 40) continue;
                $due[] = $r;
            }
            foreach (array_chunk($due, 20) as $chunk) {
                $rows = array_map(fn($r) => $r + opsh_uptime_row($r), $chunk);
                $R = opsh_uptime_check_many(array_map(fn($r) => ['id' => (int)$r['id'], 'url' => $r['url'], 'method' => $r['method'], 'timeout_s' => (int)$r['timeout_s'], 'verify_ssl' => (bool)$r['verify_ssl'], 'expect_status' => $r['expect_status'], 'keyword' => (string)$r['keyword']], $chunk));
                foreach ($rows as $m) {
                    $m['id'] = (int)$m['id']; $m['alert'] = (bool)$m['alert']; $r = $R[$m['id']] ?? null; if (!$r) continue;
                    $ev = opsh_uptime_apply($db, $m, $r, $cfg); $res['checked']++;
                    if ($r['ok']) $res['up']++; else $res['down']++;
                    if ($ev) $res['events'][] = $m['name'] . ': ' . $ev;
                }
            }
            if (mt_rand(1, 50) === 1) opsh_uptime_purge($db, $cfg);
        } finally { try { $db->query("SELECT RELEASE_LOCK('opsh_uptime_run')"); } catch (Throwable $e) {} }
        return $res;
    }
    function opsh_uptime_purge(PDO $db, array $cfg): void {
        try { $db->prepare('DELETE FROM opsh_uptime_checks WHERE checked_at < ?')->execute([date('Y-m-d H:i:s', time() - 90 * 86400)]);
              $db->prepare('DELETE FROM opsh_uptime_incidents WHERE ended_at IS NOT NULL AND ended_at < ?')->execute([date('Y-m-d H:i:s', time() - max(90, (int)$cfg['keep_days']) * 86400)]); } catch (Throwable $e) {}
    }

    // ── WHM / cPanel accounts ────────────────────────────────────────────────────────────────────────────────────

    /** Read the WHM accounts: one monitor per account (its main domain), plus the WHM server itself. */
    function opsh_uptime_whm_sync(PDO $db, ?array $cfg = null): array {
        $cfg = $cfg ?? opsh_uptime_cfg($db);
        require_once __DIR__ . '/Guardian.php';
        $s = opsh_guardian_settings($db); $c = opsh_whm_conn($s);
        if (!$c) return ['ok' => false, 'error' => 'WHM is not connected (Server Guardian → Server actions → WHM host and API token).'];
        $r = opsh_whm_call($c, 'listaccts', [], 20);
        $db->prepare("INSERT INTO opsh_settings (skey, sval) VALUES ('uptime_whm_synced', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([date('Y-m-d H:i:s')]);
        // the WHM server itself
        $whmUrl = 'https://' . $c['host'] . ':' . $c['port'] . '/';
        $ex = $db->prepare("SELECT id FROM opsh_uptime_monitors WHERE source='whm' AND kind='whm' LIMIT 1"); $ex->execute(); $wid = $ex->fetchColumn();
        if (!$wid) $db->prepare("INSERT INTO opsh_uptime_monitors (name, kind, url, method, expect_status, interval_min, timeout_s, verify_ssl, source, state, active) VALUES (?, 'whm', ?, 'GET', '200-499', 5, 15, 0, 'whm', 'unknown', 1)")->execute(['WHM server (' . $c['host'] . ')', $whmUrl]);
        else $db->prepare('UPDATE opsh_uptime_monitors SET url=? WHERE id=?')->execute([$whmUrl, $wid]);
        if (!$r['ok']) return ['ok' => false, 'error' => 'WHM did not answer: ' . ($r['error'] ?: 'HTTP ' . $r['code'])];
        $seen = []; $added = 0; $susp = 0; $removed = 0;
        $find = $db->prepare("SELECT * FROM opsh_uptime_monitors WHERE source='whm' AND kind='cpanel' AND cpanel_user=?");
        foreach ((array)($r['data']['acct'] ?? []) as $a) {
            $user = (string)($a['user'] ?? ''); $dom = strtolower(trim((string)($a['domain'] ?? ''))); if ($user === '' || $dom === '' || !preg_match('/^[a-z0-9.-]+$/', $dom)) continue;
            $seen[] = $user; $isS = !empty($a['suspended']); if ($isS) $susp++;
            $url = ($cfg['whm_https'] ? 'https://' : 'http://') . $dom . '/';
            $find->execute([$user]); $m = $find->fetch(PDO::FETCH_ASSOC);
            if (!$m) {
                $db->prepare("INSERT INTO opsh_uptime_monitors (name, kind, url, method, expect_status, interval_min, timeout_s, verify_ssl, source, cpanel_user, suspended, state, active, notes) VALUES (?, 'cpanel', ?, 'GET', '200-499', ?, 15, 1, 'whm', ?, ?, ?, 1, ?)")
                   ->execute([$dom . ' (' . $user . ')', $url, (int)$cfg['whm_interval'], $user, $isS ? 1 : 0, $isS ? 'suspended' : 'unknown', 'cPanel account ' . $user . (isset($a['plan']) ? ' · plan ' . $a['plan'] : '')]);
                $added++;
            } else {
                $st = $m['state'] === 'removed' ? 'unknown' : $m['state'];
                if ($isS) $st = 'suspended'; elseif ($st === 'suspended') $st = 'unknown';
                $db->prepare('UPDATE opsh_uptime_monitors SET name=?, url=?, suspended=?, active=IF(state=\'removed\',1,active), state=? WHERE id=?')->execute([$dom . ' (' . $user . ')', $url, $isS ? 1 : 0, $st, $m['id']]);
                if ($isS) $db->prepare('UPDATE opsh_uptime_incidents SET ended_at=NOW(), duration_s=TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE monitor_id=? AND ended_at IS NULL')->execute([$m['id']]);   // suspended is not an outage
            }
        }
        // accounts no longer on the server: stop checking them (their history stays)
        $all = $db->query("SELECT id, cpanel_user FROM opsh_uptime_monitors WHERE source='whm' AND kind='cpanel' AND active=1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all as $m) if (!in_array($m['cpanel_user'], $seen, true)) {
            $db->prepare("UPDATE opsh_uptime_monitors SET active=0, state='removed', consecutive_fail=0, first_fail_at=NULL WHERE id=?")->execute([$m['id']]); $removed++;
            // an outage still open ends now: the account is gone, it is no longer down
            $db->prepare("UPDATE opsh_uptime_incidents SET ended_at=NOW(), duration_s=TIMESTAMPDIFF(SECOND, started_at, NOW()), note=LEFT(CONCAT(note, IF(note='', '', ' · '), 'Account removed from WHM'), 500) WHERE monitor_id=? AND ended_at IS NULL")->execute([$m['id']]);
        }
        return ['ok' => true, 'accounts' => count($seen), 'added' => $added, 'suspended' => $susp, 'removed' => $removed];
    }

    // ── availability, reports, Scorecard ─────────────────────────────────────────────────────────────────────────

    /** Downtime seconds of each monitor between two times (incidents overlapping the period). */
    function opsh_uptime_downtime(PDO $db, string $from, string $to, ?array $ids = null): array {
        $sql = 'SELECT monitor_id, started_at, COALESCE(ended_at, NOW()) AS ended_at FROM opsh_uptime_incidents WHERE started_at < ? AND COALESCE(ended_at, NOW()) > ?' . ($ids ? ' AND monitor_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '');
        $s = $db->prepare($sql); $s->execute([$to, $from]); $out = []; $f = strtotime($from); $t = strtotime($to);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $a = max($f, strtotime($r['started_at'])); $b = min($t, strtotime($r['ended_at'])); if ($b > $a) $out[(int)$r['monitor_id']] = ($out[(int)$r['monitor_id']] ?? 0) + ($b - $a); }
        return $out;
    }
    /** Availability (%) per monitor for a period; a monitor counts from when it was added (and only if checked). */
    function opsh_uptime_availability(PDO $db, string $from, string $to, array $monitors): array {
        $down = opsh_uptime_downtime($db, $from, $to, array_column($monitors, 'id')); $out = [];
        $f = strtotime($from); $t = min(time(), strtotime($to));
        foreach ($monitors as $m) {
            $start = max($f, strtotime((string)$m['created_at'])); $span = $t - $start;
            if ($span <= 0 || !$m['last_check']) { $out[$m['id']] = null; continue; }
            $out[$m['id']] = round(100 * max(0, $span - ($down[$m['id']] ?? 0)) / $span, 3);
        }
        return $out;
    }
    function opsh_uptime_monitors(PDO $db, bool $activeOnly = true, ?string $kind = null): array {
        $w = []; if ($activeOnly) $w[] = 'active=1'; if ($kind === 'web') $w[] = "kind='http'"; elseif ($kind === 'cpanel') $w[] = "kind IN ('cpanel','whm')";
        return array_map('opsh_uptime_row', $db->query('SELECT * FROM opsh_uptime_monitors' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY kind, name')->fetchAll(PDO::FETCH_ASSOC));
    }
    function opsh_uptime_scorecard_sources(PDO $db): array {
        $o = ['uptime_sc_pct' => 'Uptime — the servers chosen for the Scorecard (%)', 'uptime_sc_incidents' => 'Uptime — outages of the servers chosen for the Scorecard (count)',
              'uptime_sc_downtime_min' => 'Uptime — downtime of the servers chosen for the Scorecard (minutes)',
              'uptime_all_pct' => 'Uptime — all monitored sites (%)', 'uptime_web_pct' => 'Uptime — websites you added (%)', 'uptime_cpanel_pct' => 'Uptime — WHM / cPanel accounts (%)',
              'uptime_incidents' => 'Uptime — outages that started (count)', 'uptime_downtime_min' => 'Uptime — total downtime (minutes)', 'uptime_response_ms' => 'Uptime — average response time (ms)'];
        try { foreach ($db->query("SELECT id, name FROM opsh_uptime_monitors WHERE state<>'removed' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $m) $o['uptime_m_' . $m['id']] = 'Uptime — ' . mb_substr($m['name'], 0, 60) . ' (%)'; } catch (Throwable $e) {}
        return $o;
    }
    /** A Scorecard value for a week ($from inclusive, $to exclusive), or null when there is nothing to measure. */
    function opsh_uptime_scorecard_value(PDO $db, string $source, string $from, string $to): ?float {
        if (!opsh_uptime_ready($db) || strpos($source, 'uptime_') !== 0 || $source === 'uptime_pct') return null;
        if (preg_match('/^uptime_m_(\d+)$/', $source, $mm)) { $m = opsh_uptime_get($db, (int)$mm[1]); if (!$m) return null; $a = opsh_uptime_availability($db, $from, $to, [$m]); return $a[$m['id']] === null ? null : round($a[$m['id']], 2); }
        if (strpos($source, 'uptime_sc_') === 0) {   // BT62: only the servers ticked "On the Scorecard"
            $ms = array_values(array_filter(opsh_uptime_monitors($db, false), fn($m) => $m['on_scorecard'] && $m['state'] !== 'removed' && !$m['suspended']));
            if (!$ms) return null;
            if ($source === 'uptime_sc_pct') { $a = array_filter(opsh_uptime_availability($db, $from, $to, $ms), fn($v) => $v !== null); return $a ? round(array_sum($a) / count($a), 2) : null; }
            if ($source === 'uptime_sc_incidents') { $s = $db->prepare('SELECT COUNT(*) FROM opsh_uptime_incidents WHERE started_at >= ? AND started_at < ? AND monitor_id IN (' . implode(',', array_map(fn($m) => (int)$m['id'], $ms)) . ')'); $s->execute([$from, $to]); return (float)$s->fetchColumn(); }
            if ($source === 'uptime_sc_downtime_min') return round(array_sum(opsh_uptime_downtime($db, $from, $to, array_column($ms, 'id'))) / 60, 1);
            return null;
        }
        if (in_array($source, ['uptime_all_pct', 'uptime_web_pct', 'uptime_cpanel_pct'], true)) {
            $ms = array_values(array_filter(opsh_uptime_monitors($db, false, ['uptime_all_pct' => null, 'uptime_web_pct' => 'web', 'uptime_cpanel_pct' => 'cpanel'][$source]), fn($m) => $m['state'] !== 'removed' && !$m['suspended']));
            $a = array_filter(opsh_uptime_availability($db, $from, $to, $ms), fn($v) => $v !== null);
            return $a ? round(array_sum($a) / count($a), 2) : null;
        }
        if ($source === 'uptime_incidents') { $s = $db->prepare('SELECT COUNT(*) FROM opsh_uptime_incidents WHERE started_at >= ? AND started_at < ?'); $s->execute([$from, $to]); return (float)$s->fetchColumn(); }
        if ($source === 'uptime_downtime_min') return round(array_sum(opsh_uptime_downtime($db, $from, $to)) / 60, 1);
        if ($source === 'uptime_response_ms') { $s = $db->prepare('SELECT AVG(ms) FROM opsh_uptime_checks WHERE ok=1 AND checked_at >= ? AND checked_at < ?'); $s->execute([$from, $to]); $v = $s->fetchColumn(); return $v === null ? null : round((float)$v); }
        return null;
    }

    /** The uptime report for a period: one row per monitor, and the outages. */
    function opsh_uptime_report(PDO $db, string $from, string $to, ?string $kind = null, ?array $only = null): array {
        $f = $from . ' 00:00:00'; $t = date('Y-m-d H:i:s', strtotime($to . ' 23:59:59') + 1);
        $ms = array_values(array_filter(opsh_uptime_monitors($db, false, $kind), fn($m) => ($m['state'] !== 'removed' || $m['last_check'] >= $f) && ($only === null || in_array((int)$m['id'], $only, true))));   // BT70: $only = the monitors this person may see
        $av = opsh_uptime_availability($db, $f, $t, $ms); $down = opsh_uptime_downtime($db, $f, $t, array_column($ms, 'id'));
        $resp = []; $chk = [];
        if ($ms) { $s = $db->prepare('SELECT monitor_id, AVG(CASE WHEN ok=1 THEN ms END) a, COUNT(*) n, SUM(ok=0) bad FROM opsh_uptime_checks WHERE checked_at >= ? AND checked_at < ? GROUP BY monitor_id'); $s->execute([$f, $t]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $resp[(int)$r['monitor_id']] = $r['a'] === null ? null : (int)round((float)$r['a']); $chk[(int)$r['monitor_id']] = [(int)$r['n'], (int)$r['bad']]; } }
        $inc = []; $s = $db->prepare('SELECT i.*, m.name FROM opsh_uptime_incidents i JOIN opsh_uptime_monitors m ON m.id = i.monitor_id WHERE i.started_at < ? AND COALESCE(i.ended_at, NOW()) > ? ORDER BY i.started_at');
        $s->execute([$t, $f]); $ids = array_flip(array_column($ms, 'id'));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($ids[(int)$r['monitor_id']])) $inc[] = ['monitor' => $r['name'], 'monitor_id' => (int)$r['monitor_id'], 'started_at' => $r['started_at'], 'ended_at' => $r['ended_at'], 'duration_s' => $r['ended_at'] ? (int)$r['duration_s'] : time() - strtotime($r['started_at']), 'cause' => (string)$r['cause']];
        $rows = []; $vals = [];
        foreach ($ms as $m) { $a = $av[$m['id']]; if ($a !== null && !$m['suspended']) $vals[] = $a;
            $rows[] = ['id' => $m['id'], 'name' => $m['name'], 'url' => $m['url'], 'kind' => $m['kind'], 'state' => $m['suspended'] ? 'suspended' : $m['state'], 'availability' => $a, 'downtime_s' => (int)($down[$m['id']] ?? 0),
                       'incidents' => count(array_filter($inc, fn($i) => $i['monitor_id'] === (int)$m['id'])),   /* review OPS-24: by monitor, not by name */ 'avg_ms' => $resp[$m['id']] ?? null, 'checks' => $chk[$m['id']][0] ?? 0, 'failed_checks' => $chk[$m['id']][1] ?? 0, 'ssl_expires' => $m['ssl_expires']]; }
        return ['from' => $from, 'to' => $to, 'monitors' => $rows, 'incidents' => $inc, 'overall' => $vals ? round(array_sum($vals) / count($vals), 3) : null,
                'downtime_s' => array_sum(array_column($rows, 'downtime_s')), 'down_now' => count(array_filter($rows, fn($r) => $r['state'] === 'down')),
                // Review OPS-24: single checks are kept 90 days; say so when the period starts earlier
                'note' => strtotime($from) < time() - 90 * 86400 ? 'Checks, failed checks and response times count only the last 90 days (single checks are kept 90 days); availability and outages cover the whole period.' : ''];
    }
}
