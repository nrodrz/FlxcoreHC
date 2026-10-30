<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT125: 360 Monitoring (an outside monitoring service), read only, beside the app's own Uptime checks.
 *
 *   API      https://api.monitoring360.io/v1/ (Business or Enterprise plan). The key goes in the Authorization header
 *            ("Bearer …", as the service's own command-line client sends it), never in the address, so it is never in a
 *            log. 1,500 requests an hour per key: lists are cached for two minutes and the alert check spends at most
 *            OPSH_M360_BUDGET requests a run.
 *   Lists    GET servers → {servers: [...]}, GET monitors → {monitors: [...]}, page/perpage.
 *   Alerts   GET server/{id}/notifications and monitor/{id}/notifications?start&end (Unix seconds) → {data: [{start, end,
 *            status, summary}]}. Their status words are not published, so an alert counts as over once it has an end
 *            time in the past.
 *   Tickets  (when switched on) one ticket per problem; the same problem again while its ticket is open adds a note; an
 *            alert that ended adds a note once. Short alerts (over within the set minutes) are only recorded.
 * Settings: "m360" (JSON; the key wrapped as key_enc, never returned to a browser).
 */
if (!function_exists('opsh_m360_cfg')) {
    if (!defined('OPSH_M360_BUDGET')) define('OPSH_M360_BUDGET', 60);

    function opsh_m360_base(): string { return (defined('OPSH_M360_BASE') && OPSH_M360_BASE !== '') ? rtrim(OPSH_M360_BASE, '/') . '/' : 'https://api.monitoring360.io/v1/'; }

    function opsh_m360_cfg(PDO $db): array {
        $v = $db->query("SELECT sval FROM opsh_settings WHERE skey='m360'")->fetchColumn();
        $j = json_decode((string)$v, true); if (!is_array($j)) $j = [];
        return $j + ['key_enc' => '', 'tested_ok' => false, 'alerts' => false, 'category_id' => 0, 'priority' => 'p2_high', 'min_minutes' => 5, 'by' => 0,
                     'last_poll' => 0, 'last_result' => null, 'rl_remaining' => null, 'cursor' => 0];
    }
    function opsh_m360_save(PDO $db, array $c): void {
        $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('m360',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($c)]);
    }
    /** What a screen may see (never the key). */
    function opsh_m360_public(PDO $db): array {
        $c = opsh_m360_cfg($db);
        return ['connected' => $c['key_enc'] !== '', 'tested_ok' => (bool)$c['tested_ok'], 'alerts' => (bool)$c['alerts'], 'category_id' => (int)$c['category_id'],
                'priority' => (string)$c['priority'], 'min_minutes' => (int)$c['min_minutes'], 'last_poll' => $c['last_poll'] ? date('Y-m-d H:i:s', (int)$c['last_poll']) : '',
                'last_result' => $c['last_result'], 'rl_remaining' => $c['rl_remaining']];
    }
    function opsh_m360_key(PDO $db): string {
        $c = opsh_m360_cfg($db); if ($c['key_enc'] === '') throw new RuntimeException('360 Monitoring is not connected: enter its API key in Settings → Company systems → 360 Monitoring.');
        $k = (string)vault_decrypt($c['key_enc']); if ($k === '') throw new RuntimeException('The saved 360 Monitoring key cannot be read (was the vault key changed?). Enter it again.');
        return $k;
    }

    /** One GET. Returns the decoded JSON. Throws RuntimeException with a sentence (never the key). */
    function opsh_m360_get(PDO $db, string $path, array $q = [], ?string $key = null): array {
        if (!preg_match('~^[a-z]+(/[A-Za-z0-9_-]{1,64}(/[a-z]+)?)?$~', $path)) throw new RuntimeException('Not a 360 Monitoring address.');
        $key = $key ?? opsh_m360_key($db);
        $url = opsh_m360_base() . $path . ($q ? '?' . http_build_query($q) : '');
        for ($try = 0; $try < 2; $try++) {
            $hdr = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (defined('OPSH_M360_BASE') ? CURLPROTO_HTTP : 0),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'User-Agent: ' . (function_exists('opsh_app_name') ? preg_replace('/[^A-Za-z0-9 ._-]/', '', opsh_app_name()) : 'app') . ' monitoring reader'],
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { $p = strpos($h, ':'); if ($p !== false) $hdr[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1)); return strlen($h); }]);
            $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
            if (isset($hdr['ratelimit-remaining'])) { try { $c = opsh_m360_cfg($db); $c['rl_remaining'] = (int)$hdr['ratelimit-remaining']; opsh_m360_save($db, $c); } catch (Throwable $e) {} }
            if ($raw === false) throw new RuntimeException('360 Monitoring did not answer (' . $err . ').');
            if ($code === 429 && $try === 0) { $w = (int)($hdr['retry-after'] ?? $hdr['ratelimit-reset'] ?? 2); sleep(max(1, min(5, $w))); continue; }
            $j = json_decode($raw, true);
            if ($code === 401 || $code === 403) throw new RuntimeException('360 Monitoring refused the key (HTTP ' . $code . '). Check the key, and that the account has API access (Business or Enterprise plan).');
            if ($code === 429) throw new RuntimeException('360 Monitoring says the hourly request limit is used up; it is read again later.');
            if ($code >= 400) throw new RuntimeException('360 Monitoring answered HTTP ' . $code . (is_array($j) && isset($j['error']) ? ': ' . mb_substr(is_string($j['error']) ? $j['error'] : json_encode($j['error']), 0, 200) : '') . '.');
            if (!is_array($j)) throw new RuntimeException('360 Monitoring answered something that is not JSON.');
            return $j;
        }
        throw new RuntimeException('360 Monitoring did not answer.');
    }

    /** Every item of a list (servers | monitors), at most $pages pages of 100. Returns [items, truncated]. */
    function opsh_m360_list(PDO $db, string $what, int $pages = 10): array {
        $items = []; $trunc = false;
        for ($p = 1; $p <= $pages; $p++) {
            $j = opsh_m360_get($db, $what, ['page' => $p, 'perpage' => 100]);
            $rows = $j[$what] ?? ($j['data'] ?? []); if (!is_array($rows)) $rows = [];
            foreach ($rows as $r) if (is_array($r)) $items[] = $r;
            if (count($rows) < 100) return [$items, false];
            if ($p === $pages) $trunc = true;
        }
        return [$items, $trunc];
    }

    function opsh_m360_pct($v): ?float { return is_numeric($v) ? round((float)$v, 1) : null; }
    /** A server as the screens show it. */
    function opsh_m360_server_row(array $s): array {
        $ld = is_array($s['last_data'] ?? null) ? $s['last_data'] : []; $sm = is_array($s['summary'] ?? null) ? $s['summary'] : [];
        $w = is_array($s['ip_whois'] ?? null) ? $s['ip_whois'] : [];
        return ['id' => (string)($s['id'] ?? ''), 'name' => (string)($s['name'] ?? ''), 'status' => (string)($s['status'] ?? ''), 'os' => (string)($s['os'] ?? ''),
                'ip' => (string)($w['ip'] ?? ($s['connecting_ip'] ?? '')), 'agent' => (string)($s['agent_version'] ?? ''),
                'cpu' => opsh_m360_pct($sm['cpu_usage_percent'] ?? null), 'mem' => opsh_m360_pct($sm['mem_usage_percent'] ?? null), 'disk' => opsh_m360_pct($sm['disk_usage_percent'] ?? null),
                'cores' => (int)($ld['cores'] ?? 0), 'uptime_s' => (int)($ld['uptime']['seconds'] ?? 0), 'tags' => array_values(array_filter((array)($s['tags'] ?? []), 'is_string'))];
    }
    /** A monitor (website, port, ping) as the screens show it. */
    function opsh_m360_monitor_row(array $m): array {
        return ['id' => (string)($m['id'] ?? ''), 'url' => (string)($m['url'] ?? ''), 'name' => (string)($m['name'] ?? ''), 'status' => (string)($m['status'] ?? ''),
                'status_message' => (string)($m['status_message'] ?? ''), 'code' => (string)($m['code'] ?? ''), 'uptime' => is_numeric($m['uptime_percentage'] ?? null) ? round((float)$m['uptime_percentage'], 3) : null,   // 99.95 must not show as 100
                'ttfb' => is_numeric($m['last_check']['ttfb'] ?? null) ? round((float)$m['last_check']['ttfb'], 3) : null, 'location' => (string)($m['monitor']['name'] ?? '')];
    }

    /** servers | monitors, kept two minutes (the hourly request limit is shared with the alert check). */
    function opsh_m360_cached(PDO $db, string $what, bool $fresh = false): array {
        require_once __DIR__ . '/SafeCache.php';
        $f = opsh_cache_dir('m360') . '/' . $what . '.json';
        if (!$fresh && ($c = opsh_cache_read($f)) !== null) { $j = json_decode($c, true); if (is_array($j) && time() - (int)($j['at'] ?? 0) < 120) return $j; }
        [$items, $trunc] = opsh_m360_list($db, $what);
        $rows = array_map($what === 'servers' ? 'opsh_m360_server_row' : 'opsh_m360_monitor_row', $items);
        $j = ['at' => time(), 'rows' => $rows, 'truncated' => $trunc];
        opsh_cache_write($f, json_encode($j));
        return $j;
    }
    function opsh_m360_forget_cache(): void { require_once __DIR__ . '/SafeCache.php'; foreach (['servers', 'monitors'] as $w) @unlink(opsh_cache_dir('m360') . '/' . $w . '.json'); }

    function opsh_m360_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS opsh_m360_alerts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, akey CHAR(40) NOT NULL UNIQUE, kind VARCHAR(8) NOT NULL,
            obj_id VARCHAR(64) NOT NULL, obj_name VARCHAR(255) NOT NULL DEFAULT '', summary VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(40) NOT NULL DEFAULT '',
            start_at DATETIME NULL, end_at DATETIME NULL, ticket_id INT UNSIGNED NULL, noted_end TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
            INDEX idx_m360_obj (kind, obj_id), INDEX idx_m360_ticket (ticket_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Who a ticket from an alert is "opened by": the person who switched the alerts on, else the first active Super Admin. */
    function opsh_m360_requester(PDO $db, int $by): int {
        if ($by) { $q = $db->prepare("SELECT id FROM opsh_users WHERE id=? AND status='active'"); $q->execute([$by]); if ($id = (int)$q->fetchColumn()) return $id; }
        return (int)$db->query("SELECT u.id FROM opsh_users u JOIN opsh_roles r ON r.id=u.role_id WHERE r.slug='super_admin' AND u.status='active' ORDER BY u.id LIMIT 1")->fetchColumn();
    }
    function opsh_m360_note(PDO $db, int $tid, int $by, string $text): void {   // an internal note, written as the person the tickets are opened by
        if (!$by) return;
        $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([$tid, $by, $text]);
        $db->prepare('UPDATE opsh_tickets SET updated_at=NOW() WHERE id=?')->execute([$tid]);
    }
    function opsh_m360_when(int $ts): string { return date('m/d/Y g:i A', $ts); }

    /**
     * The alert check (scheduler, or "Check alerts now"). Reads the alerts of every server and monitor since the last
     * check, records each once, and makes or updates tickets when switched on. Returns a summary.
     */
    function opsh_m360_poll(PDO $db, ?int $now = null): array {
        $now = $now ?? time(); $c = opsh_m360_cfg($db);
        if ($c['key_enc'] === '') return ['skipped' => 'not connected'];
        opsh_m360_table($db);
        $from = $c['last_poll'] ? max((int)$c['last_poll'] - 600, $now - 86400) : $now - 3600;
        $res = ['checked' => 0, 'alerts' => 0, 'new' => 0, 'tickets' => 0, 'notes' => 0, 'ended' => 0, 'errors' => [], 'left' => 0];
        $objs = [];
        try { foreach (opsh_m360_cached($db, 'servers')['rows'] as $s) $objs[] = ['server', $s['id'], $s['name'] ?: $s['id']];
              foreach (opsh_m360_cached($db, 'monitors')['rows'] as $m) $objs[] = ['monitor', $m['id'], $m['name'] ?: $m['url']]; }
        catch (Throwable $e) { $res['errors'][] = $e->getMessage(); $c['last_result'] = $res + ['at' => date('Y-m-d H:i:s', $now)]; opsh_m360_save($db, $c); return $res; }
        $n = count($objs); $budget = min($n, OPSH_M360_BUDGET); $start = $n ? ((int)$c['cursor'] % $n) : 0;
        $req = opsh_m360_requester($db, (int)$c['by']);
        $ins = $db->prepare('INSERT IGNORE INTO opsh_m360_alerts (akey, kind, obj_id, obj_name, summary, status, start_at, end_at, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
        for ($i = 0; $i < $budget; $i++) {
            [$kind, $oid, $oname] = $objs[($start + $i) % $n];
            if ($oid === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $oid)) continue;
            try { $j = opsh_m360_get($db, $kind . '/' . $oid . '/notifications', ['start' => $from, 'end' => $now]); }
            catch (Throwable $e) { $res['errors'][] = $oname . ': ' . $e->getMessage(); if (stripos($e->getMessage(), 'limit') !== false) break; continue; }
            $res['checked']++;
            foreach ((array)($j['data'] ?? []) as $a) {
                if (!is_array($a)) continue;
                $res['alerts']++;
                $st = (int)($a['start'] ?? 0); $en = (int)($a['end'] ?? 0); $sum = mb_substr(trim((string)($a['summary'] ?? 'Alert')), 0, 500); $status = mb_substr((string)($a['status'] ?? ''), 0, 40);
                if ($st <= 0) continue;
                $over = $en > 0 && $en >= $st && $en <= $now;
                $key = sha1($kind . '|' . $oid . '|' . $st . '|' . $sum);
                $ins->execute([$key, $kind, $oid, mb_substr($oname, 0, 255), $sum, $status, date('Y-m-d H:i:s', $st), $over ? date('Y-m-d H:i:s', $en) : null]);
                $isNew = $ins->rowCount() > 0;
                $q = $db->prepare('SELECT * FROM opsh_m360_alerts WHERE akey=?'); $q->execute([$key]); $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($isNew) $res['new']++;
                if (!$isNew && $over && !$row['end_at']) $db->prepare('UPDATE opsh_m360_alerts SET end_at=?, status=? WHERE id=?')->execute([date('Y-m-d H:i:s', $en), $status, (int)$row['id']]);
                // an alert with a ticket that is now over: a note, once
                if ($over && (int)$row['ticket_id'] > 0 && !(int)$row['noted_end']) {
                    opsh_m360_note($db, (int)$row['ticket_id'], $req, '360 Monitoring: "' . $sum . '" on ' . $oname . ' ended at ' . opsh_m360_when($en) . ' (it started ' . opsh_m360_when($st) . ').');
                    $db->prepare('UPDATE opsh_m360_alerts SET noted_end=1 WHERE id=?')->execute([(int)$row['id']]); $res['ended']++; $res['notes']++;
                }
                if (!$isNew || empty($c['alerts']) || !(int)$c['category_id'] || !$req) continue;
                if ($over && ($en - $st) < 60 * max(0, (int)$c['min_minutes'])) continue;   // a short blip: recorded only
                // the same problem with an open ticket: a note on it
                $o = $db->prepare("SELECT a.ticket_id FROM opsh_m360_alerts a JOIN opsh_tickets t ON t.id=a.ticket_id WHERE a.kind=? AND a.obj_id=? AND a.summary=? AND a.id<>? AND t.status NOT IN ('resolved','closed','cancelled','merged') ORDER BY a.id DESC LIMIT 1");
                $o->execute([$kind, $oid, $sum, (int)$row['id']]); $open = (int)$o->fetchColumn();
                if ($open) {
                    opsh_m360_note($db, $open, $req, '360 Monitoring: "' . $sum . '" on ' . $oname . ' again at ' . opsh_m360_when($st) . ($over ? ' (ended ' . opsh_m360_when($en) . ')' : '') . '.');
                    $db->prepare('UPDATE opsh_m360_alerts SET ticket_id=?, noted_end=? WHERE id=?')->execute([$open, $over ? 1 : 0, (int)$row['id']]); $res['notes']++; continue;
                }
                require_once __DIR__ . '/TicketNumbers.php';
                $subj = mb_substr('Monitoring: ' . $oname . ' — ' . $sum, 0, 250);
                $desc = '360 Monitoring alert on the ' . $kind . ' ' . $oname . ".\nAlert: " . $sum . ($status !== '' ? "\nStatus: " . $status : '') . "\nStarted: " . opsh_m360_when($st)
                      . ($over ? "\nEnded: " . opsh_m360_when($en) : "\nStill going when it was read (" . opsh_m360_when($now) . ').') . "\n\nThis ticket was made by the alert check; the next alerts of the same problem are added here as notes.";
                $pri = in_array($c['priority'], ['p1_critical', 'p2_high', 'p3_medium', 'p4_low'], true) ? $c['priority'] : 'p2_high';
                [$tn, $tid] = opsh_ticket_create_numbered($db, function (string $tn) use ($db, $subj, $desc, $c, $pri, $req) {
                    $db->prepare("INSERT INTO opsh_tickets (ticket_number,subject,description,category_id,priority,status,ticket_type,requester_id) VALUES (?,?,?,?,?,'open','incident',?)")
                       ->execute([$tn, $subj, $desc, (int)$c['category_id'], $pri, $req]);
                    return [$tn, (int)$db->lastInsertId()];
                });
                if (!function_exists('opsh_ticket_sla_fill')) require_once __DIR__ . '/BusinessHours.php';
                try { opsh_ticket_sla_fill($db, $tid); } catch (Throwable $e) {}
                try { require_once __DIR__ . '/TicketRouting.php'; opsh_tr_on_created($db, $tid, null, 'monitoring'); } catch (Throwable $e) {}
                $db->prepare('UPDATE opsh_m360_alerts SET ticket_id=?, noted_end=? WHERE id=?')->execute([$tid, $over ? 1 : 0, (int)$row['id']]);
                if (function_exists('audit_log')) audit_log('ticket_created', 'ticket', $tid, $tn, null, ['From' => '360 Monitoring alert', 'On' => $oname, 'Alert' => $sum]);
                $res['tickets']++;
            }
        }
        $res['left'] = max(0, $n - $budget);
        $c = opsh_m360_cfg($db);   // read again: the request counter was saved meanwhile
        $c['cursor'] = $n ? (($start + $budget) % $n) : 0;
        if ($res['checked'] > 0 || !$res['errors']) $c['last_poll'] = $now;
        $c['last_result'] = $res + ['at' => date('Y-m-d H:i:s', $now)];
        opsh_m360_save($db, $c);
        return $res;
    }

    /** Recent alerts kept here (newest first), with their tickets. */
    function opsh_m360_recent(PDO $db, int $limit = 50): array {
        try { opsh_m360_table($db); } catch (Throwable $e) { return []; }
        $q = $db->prepare('SELECT a.kind, a.obj_name, a.summary, a.status, a.start_at, a.end_at, a.ticket_id, t.ticket_number FROM opsh_m360_alerts a LEFT JOIN opsh_tickets t ON t.id=a.ticket_id ORDER BY a.start_at DESC, a.id DESC LIMIT ' . max(1, min(200, $limit)));
        $q->execute(); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
