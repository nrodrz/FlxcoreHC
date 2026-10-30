<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT148 — missed calls become tickets. Shared by every phone system that can be read (CallTrackingMetrics BT148,
 * JustCall BT150; the office PBX later uses the same table, settings and rules).
 *
 *   settings  opsh_settings.phone_tickets = {system key: {on, lines: [10 digits], statuses: [...], category_id,
 *             priority, window_min, by, last_poll, last_result}}
 *   table     opsh_phone_calls: every missed call looked at, once (UNIQUE system + call id), with what was done:
 *             'ticket' (a new ticket), 'note' (added to the open ticket of the same caller), 'skipped' (and why).
 *
 * Rules to keep:
 *   - A call is looked at once. The same call read again (the next check overlaps the last one on purpose) changes nothing.
 *   - Only inbound calls, only on the lines chosen (none chosen = every line), only with the statuses chosen.
 *   - The same caller again while their ticket is open and within the window: a note on that ticket, not a new ticket.
 *   - A call older than the moment the tickets were switched on never makes a ticket (switching on does not flood the desk).
 *   - A ticket is opened by the person who switched the tickets on (else the first active Super Admin); the caller is
 *     kept as the ticket's contact. Routing, service levels and alerts work as for any other ticket.
 *   - Every ticket made is written to the Audit Log; so is every change of these settings.
 */
if (!function_exists('opsh_ph_cfg')) {

    function opsh_ph_defaults(): array {
        return ['on' => false, 'lines' => [], 'statuses' => ['no answer', 'busy', 'canceled', 'hangup'], 'category_id' => 0, 'priority' => 'p3_medium', 'window_min' => 120,
                'by' => 0, 'on_since' => null, 'last_poll' => 0, 'last_result' => null];
    }

    function opsh_ph_all(PDO $db): array {
        try { $s = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $s->execute(['phone_tickets']); $j = json_decode((string)$s->fetchColumn(), true); }
        catch (Throwable $e) { $j = null; }
        return is_array($j) ? $j : [];
    }
    function opsh_ph_cfg(PDO $db, string $sys): array {
        $a = opsh_ph_all($db); $c = (is_array($a[$sys] ?? null) ? $a[$sys] : []) + opsh_ph_defaults();
        $c['lines'] = array_values(array_map('strval', array_filter((array)$c['lines'], fn($x) => is_string($x) || is_int($x))));
        $c['statuses'] = array_values(array_filter((array)$c['statuses'], 'is_string'));
        return $c;
    }
    function opsh_ph_save(PDO $db, string $sys, array $c): void {
        $a = opsh_ph_all($db); $a[$sys] = $c;
        $db->prepare('INSERT INTO opsh_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['phone_tickets', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    function opsh_ph_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS opsh_phone_calls (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, system_key VARCHAR(20) NOT NULL, call_id VARCHAR(64) NOT NULL,
            called_at DATETIME NULL, caller_number VARCHAR(40) NOT NULL DEFAULT '', caller_digits VARCHAR(15) NOT NULL DEFAULT '', caller_name VARCHAR(160) NOT NULL DEFAULT '',
            line_number VARCHAR(40) NOT NULL DEFAULT '', line_label VARCHAR(160) NOT NULL DEFAULT '', status VARCHAR(30) NOT NULL DEFAULT '', action VARCHAR(10) NOT NULL DEFAULT '',
            reason VARCHAR(160) NOT NULL DEFAULT '', ticket_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_phone_call (system_key, call_id), INDEX idx_phone_caller (system_key, caller_digits), INDEX idx_phone_ticket (ticket_id), INDEX idx_phone_at (called_at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    function opsh_ph_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM opsh_phone_calls LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    /** The last 10 digits of a phone number (US and Canada numbers with or without +1 compare equal). '' when too short. */
    function opsh_ph_digits(string $n): string { $d = preg_replace('/\D/', '', $n) ?? ''; return strlen($d) >= 7 ? substr($d, -10) : ''; }

    /** "(407) 555-0100" for a 10-digit number; anything else as it came. */
    function opsh_ph_show(string $n): string { $d = preg_replace('/\D/', '', $n) ?? ''; if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1); return strlen($d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : $n; }

    /** Who tickets are opened by: the person who switched them on when still active, else the first active Super Admin. */
    function opsh_ph_requester(PDO $db, int $by): int {
        if ($by) { $q = $db->prepare("SELECT id FROM opsh_users WHERE id=? AND status='active'"); $q->execute([$by]); if ($id = (int)$q->fetchColumn()) return $id; }
        try { return (int)$db->query("SELECT u.id FROM opsh_users u JOIN opsh_roles r ON r.id=u.role_id WHERE r.slug='super_admin' AND u.status='active' ORDER BY u.id LIMIT 1")->fetchColumn(); } catch (Throwable $e) { return 0; }
    }

    /** What a screen may see of the settings, and the calls looked at lately. */
    function opsh_ph_public(PDO $db, string $sys, int $recent = 40): array {
        $c = opsh_ph_cfg($db, $sys); $rows = [];
        if (opsh_ph_ready($db)) {
            $q = $db->prepare('SELECT p.*, t.ticket_number, t.status AS ticket_status FROM opsh_phone_calls p LEFT JOIN opsh_tickets t ON t.id=p.ticket_id WHERE p.system_key=? ORDER BY p.called_at DESC, p.id DESC LIMIT ' . max(1, min(200, $recent)));
            $q->execute([$sys]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = ['at' => $r['called_at'], 'caller' => opsh_ph_show((string)$r['caller_number']), 'caller_name' => (string)$r['caller_name'], 'line' => opsh_ph_show((string)$r['line_number']),
                'line_label' => (string)$r['line_label'], 'status' => (string)$r['status'], 'action' => (string)$r['action'], 'reason' => (string)$r['reason'],
                'ticket_id' => $r['ticket_id'] !== null ? (int)$r['ticket_id'] : null, 'ticket_number' => (string)($r['ticket_number'] ?? ''), 'ticket_status' => (string)($r['ticket_status'] ?? '')];
        }
        $by = ''; if ((int)$c['by']) { try { $q = $db->prepare('SELECT display_name FROM opsh_users WHERE id=?'); $q->execute([(int)$c['by']]); $by = (string)$q->fetchColumn(); } catch (Throwable $e) {} }
        return ['on' => (bool)$c['on'], 'lines' => array_values((array)$c['lines']), 'statuses' => array_values((array)$c['statuses']), 'category_id' => (int)$c['category_id'], 'priority' => (string)$c['priority'],
                'window_min' => (int)$c['window_min'], 'by_name' => $by, 'on_since' => $c['on_since'], 'last_poll' => $c['last_poll'] ? date('Y-m-d H:i:s', (int)$c['last_poll']) : '',
                'last_result' => $c['last_result'], 'recent' => $rows, 'ready' => opsh_ph_ready($db)];
    }

    /**
     * Check the settings a person sent. Returns the clean settings, or throws InvalidArgumentException with a sentence.
     * $known = the line numbers the system has now (10 digits), so a typing mistake cannot select nothing at all.
     */
    function opsh_ph_clean(PDO $db, array $in, array $was, array $allowedStatuses, ?array $known): array {
        $c = $was;
        if (array_key_exists('on', $in)) { if (!is_bool($in['on'])) throw new InvalidArgumentException('"Make tickets" is on or off.'); $c['on'] = $in['on']; }
        if (array_key_exists('lines', $in)) {
            if (!is_array($in['lines']) || count($in['lines']) > 200) throw new InvalidArgumentException('The lines are a list (at most 200).');
            $l = [];   // a list, not array keys: PHP turns a key of digits into a number
            foreach ($in['lines'] as $x) { if (!is_string($x)) throw new InvalidArgumentException('A line is a phone number.'); $d = opsh_ph_digits($x); if ($d === '') throw new InvalidArgumentException('"' . mb_substr($x, 0, 30) . '" is not a phone number.');
                if ($known !== null && !in_array($d, $known, true)) throw new InvalidArgumentException(opsh_ph_show($d) . ' is not one of the numbers read from the system. Read the numbers again.'); if (!in_array($d, $l, true)) $l[] = $d; }
            $c['lines'] = $l;
        }
        if (array_key_exists('statuses', $in)) {
            if (!is_array($in['statuses']) || !$in['statuses']) throw new InvalidArgumentException('Choose at least one kind of call that counts as missed.');
            $s = [];
            foreach ($in['statuses'] as $x) { if (!is_string($x) || !in_array($x, $allowedStatuses, true)) throw new InvalidArgumentException('Unknown kind of call: ' . mb_substr((string)(is_string($x) ? $x : ''), 0, 30) . '.'); $s[$x] = 1; }
            $c['statuses'] = array_keys($s);
        }
        if (array_key_exists('category_id', $in)) {
            if (!is_int($in['category_id']) || $in['category_id'] < 0) throw new InvalidArgumentException('Choose a ticket category.');
            if ($in['category_id']) { $q = $db->prepare('SELECT COUNT(*) FROM opsh_ticket_categories WHERE id=?'); $q->execute([$in['category_id']]); if (!(int)$q->fetchColumn()) throw new InvalidArgumentException('That ticket category no longer exists. Reload the page.'); }
            $c['category_id'] = $in['category_id'];
        }
        if (array_key_exists('priority', $in)) { if (!in_array($in['priority'], ['p1_critical', 'p2_high', 'p3_medium', 'p4_low'], true)) throw new InvalidArgumentException('Choose a priority.'); $c['priority'] = $in['priority']; }
        if (array_key_exists('window_min', $in)) { if (!is_int($in['window_min']) || $in['window_min'] < 0 || $in['window_min'] > 10080) throw new InvalidArgumentException('The window is a number of minutes, 0 to 10080 (a week).'); $c['window_min'] = $in['window_min']; }
        if ($c['on'] && !(int)$c['category_id']) throw new InvalidArgumentException('Choose the ticket category before switching tickets on.');
        return $c;
    }

    /**
     * Look at calls read from a phone system: record each once, and make tickets or notes. $calls rows carry
     * id, at (ISO UTC), direction, status, caller_number, caller_name, tracking_number (the line), tracking_label,
     * source, agent_name, ring_time, voicemail. Returns counts.
     */
    function opsh_ph_take(PDO $db, string $sys, string $sysName, array $calls, ?int $now = null): array {
        $now = $now ?? time(); $c = opsh_ph_cfg($db, $sys);
        $res = ['seen' => 0, 'missed' => 0, 'tickets' => 0, 'notes' => 0, 'skipped' => 0, 'old' => 0];
        if (!$c['on']) return $res + ['off' => true];
        opsh_ph_table($db);
        $req = opsh_ph_requester($db, (int)$c['by']);
        $since = $c['on_since'] ? strtotime((string)$c['on_since']) : $now;
        $lines = array_flip((array)$c['lines']); $st = array_flip((array)$c['statuses']);
        $ins = $db->prepare("INSERT IGNORE INTO opsh_phone_calls (system_key, call_id, called_at, caller_number, caller_digits, caller_name, line_number, line_label, status, action, reason, created_at) VALUES (?,?,?,?,?,?,?,?,?,'','',NOW())");
        usort($calls, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));   // oldest first: the first missed call makes the ticket, the next ones add to it
        foreach ($calls as $k) {
            $res['seen']++;
            if (($k['direction'] ?? 'inbound') !== 'inbound' || !isset($st[(string)($k['status'] ?? '')])) continue;
            $line = opsh_ph_digits((string)($k['tracking_number'] ?? ''));
            if ($lines && !isset($lines[$line])) continue;
            $t = !empty($k['at']) ? strtotime((string)$k['at']) : false;
            $res['missed']++;
            $ins->execute([$sys, (string)$k['id'], $t ? date('Y-m-d H:i:s', $t) : null, mb_substr((string)($k['caller_number'] ?? ''), 0, 40), opsh_ph_digits((string)($k['caller_number'] ?? '')),
                           mb_substr((string)($k['caller_name'] ?? ''), 0, 160), mb_substr((string)($k['tracking_number'] ?? ''), 0, 40), mb_substr((string)($k['tracking_label'] ?? ''), 0, 160), mb_substr((string)$k['status'], 0, 30)]);
            if ($ins->rowCount() === 0) continue;   // looked at before
            $rowId = (int)$db->lastInsertId();
            $mark = function (string $action, string $reason, ?int $tid) use ($db, $rowId) { $db->prepare('UPDATE opsh_phone_calls SET action=?, reason=?, ticket_id=? WHERE id=?')->execute([$action, mb_substr($reason, 0, 160), $tid, $rowId]); };
            // BT150: a system that checks "after switching on" by call id (JustCall) marks its calls after_baseline: no clock is needed
            if (!empty($k['after_baseline'])) { if (!$t) $t = $now; }
            elseif (!$t || $t < $since - 60) { $mark('skipped', $t ? 'before tickets were switched on' : 'no time given by ' . $sysName, null); $res['skipped']++; $res['old']++; continue; }
            if (!$req) { $mark('skipped', 'no active Super Admin to open the ticket', null); $res['skipped']++; continue; }
            $digits = opsh_ph_digits((string)($k['caller_number'] ?? ''));
            $who = trim((string)($k['caller_name'] ?? '')); $num = opsh_ph_show((string)($k['caller_number'] ?? ''));
            $callerTxt = $who !== '' && $num !== '' ? $who . ' (' . $num . ')' : ($who !== '' ? $who : ($num !== '' ? $num : 'an unknown caller'));
            $lineTxt = trim((string)($k['tracking_label'] ?? '')) !== '' ? $k['tracking_label'] . ' (' . opsh_ph_show((string)$k['tracking_number']) . ')' : opsh_ph_show((string)($k['tracking_number'] ?? '')) ;
            $when = date('m/d/Y g:i A', $t);
            // the same caller with an open ticket from a missed call within the window: a note on it
            if ($digits !== '' && (int)$c['window_min'] > 0) {
                $o = $db->prepare("SELECT p.ticket_id FROM opsh_phone_calls p JOIN opsh_tickets t ON t.id=p.ticket_id WHERE p.system_key=? AND p.caller_digits=? AND p.id<>? AND p.action='ticket'
                                   AND p.called_at >= ? AND t.status NOT IN ('resolved','closed','cancelled') AND t.merged_into_id IS NULL ORDER BY p.id DESC LIMIT 1");
                $o->execute([$sys, $digits, $rowId, date('Y-m-d H:i:s', $t - 60 * (int)$c['window_min'])]);
                if ($open = (int)$o->fetchColumn()) {
                    $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([$open, $req, 'Another missed call from ' . $callerTxt . ' to ' . ($lineTxt ?: 'the company line') . ' at ' . $when . ' (' . $sysName . ': ' . $k['status'] . ').']);
                    $db->prepare('UPDATE opsh_tickets SET updated_at=NOW() WHERE id=?')->execute([$open]);
                    $mark('note', 'the same caller already has an open ticket', $open); $res['notes']++; continue;
                }
            }
            require_once __DIR__ . '/TicketNumbers.php';
            $subj = mb_substr('Missed call: ' . $callerTxt . ($lineTxt !== '' ? ' → ' . $lineTxt : ''), 0, 250);
            $desc = 'A call to the company was not answered.' . "\n\nCaller: " . $callerTxt . "\nCalled: " . ($lineTxt !== '' ? $lineTxt : 'not given')
                  . (!empty($k['source']) ? "\nSource: " . $k['source'] : '') . "\nWhen: " . $when . "\nWhat happened: " . $k['status'] . (!empty($k['ring_time']) ? ' after ' . (int)$k['ring_time'] . ' seconds' : '')
                  . (!empty($k['agent_name']) ? "\nRang: " . $k['agent_name'] : '') . (!empty($k['voicemail']) ? "\nThe caller left a voicemail (listen to it in " . $sysName . ')' : '')
                  . (!empty($k['transcript']) ? "\nVoicemail, as " . $sysName . " transcribed it: " . $k['transcript'] : '')
                  . "\n\nCall back: " . ($num !== '' ? $num : 'no number given') . "\n\nThis ticket was made by the missed-call check of " . $sysName . '. More missed calls from the same number while it is open'
                  . ((int)$c['window_min'] > 0 ? ' (within ' . (int)$c['window_min'] . ' minutes)' : '') . ' are added to it as notes.';
            $cat = (int)$c['category_id']; $pri = (string)$c['priority'];
            $type = 'request'; try { require_once __DIR__ . '/ServiceMgmt.php'; $type = opsh_svc_type_for($db, $cat ?: null); } catch (Throwable $e) {}
            try {
                [$tn, $tid] = opsh_ticket_create_numbered($db, function (string $tn) use ($db, $subj, $desc, $cat, $pri, $req, $who, $num, $type) {
                    $hasContact = true;
                    try { $db->prepare("INSERT INTO opsh_tickets (ticket_number,subject,description,category_id,priority,status,ticket_type,requester_id,contact_name) VALUES (?,?,?,?,?,'open',?,?,?)")
                              ->execute([$tn, $subj, $desc, $cat ?: null, $pri, $type, $req, mb_substr($who !== '' ? $who . ($num !== '' ? ' ' . $num : '') : $num, 0, 160) ?: null]); }
                    catch (PDOException $e) { if (stripos($e->getMessage(), 'contact_name') === false) throw $e; $hasContact = false; }
                    if (!$hasContact) $db->prepare("INSERT INTO opsh_tickets (ticket_number,subject,description,category_id,priority,status,ticket_type,requester_id) VALUES (?,?,?,?,?,'open',?,?)")->execute([$tn, $subj, $desc, $cat ?: null, $pri, $type, $req]);
                    return [$tn, (int)$db->lastInsertId()];
                });
            } catch (Throwable $e) { $mark('skipped', 'the ticket could not be made: ' . $e->getMessage(), null); $res['skipped']++; continue; }
            if (!function_exists('opsh_ticket_sla_fill')) require_once __DIR__ . '/BusinessHours.php';
            try { opsh_ticket_sla_fill($db, $tid); } catch (Throwable $e) {}
            try { require_once __DIR__ . '/TicketRouting.php'; opsh_tr_on_created($db, $tid, null, 'phone'); } catch (Throwable $e) {}
            $mark('ticket', '', $tid); $res['tickets']++;
            if (function_exists('audit_log')) audit_log('ticket_created', 'ticket', $tid, $tn, null, ['From' => $sysName . ' missed call', 'Caller' => $callerTxt, 'Called' => $lineTxt ?: 'not given', 'When' => $when]);
        }
        return $res;
    }

    /** BT150 (from BT148): the call report of a list of calls: totals, by line, by source, by agent and by day. */
    function opsh_ph_report(array $calls, callable $isMissed): array {
        $blank = ['calls' => 0, 'inbound' => 0, 'answered' => 0, 'missed' => 0, 'talk' => 0];
        $tot = $blank; $line = []; $src = []; $agent = []; $day = [];
        $add = function (array &$b, array $k) use ($isMissed) {
            $b['calls']++; if ($k['direction'] === 'inbound') $b['inbound']++;
            if ($k['status'] === 'answered') { $b['answered']++; $b['talk'] += $k['talk_time']; }
            if ($k['direction'] === 'inbound' && $isMissed($k['status'])) $b['missed']++;
        };
        foreach ($calls as $k) {
            $add($tot, $k);
            $lk = opsh_ph_digits($k['tracking_number']) ?: '(none)';
            if (!isset($line[$lk])) $line[$lk] = $blank + ['line' => $k['tracking_number'] !== '' ? opsh_ph_show($k['tracking_number']) : 'no tracking number', 'label' => ''];
            if ($line[$lk]['label'] === '' && $k['tracking_label'] !== '') $line[$lk]['label'] = $k['tracking_label'];
            $add($line[$lk], $k);
            $sk = $k['source'] !== '' ? $k['source'] : '(no source)'; if (!isset($src[$sk])) $src[$sk] = $blank + ['source' => $sk]; $add($src[$sk], $k);
            if ($k['agent_name'] !== '' || $k['agent_email'] !== '') { $ak = mb_strtolower($k['agent_email'] ?: $k['agent_name']); if (!isset($agent[$ak])) $agent[$ak] = $blank + ['agent' => $k['agent_name'] ?: $k['agent_email'], 'email' => $k['agent_email']]; $add($agent[$ak], $k); }
            $dk = $k['at'] !== '' ? date('Y-m-d', strtotime($k['at'])) : ''; if ($dk !== '') { if (!isset($day[$dk])) $day[$dk] = $blank + ['day' => $dk]; $add($day[$dk], $k); }
        }
        $fin = function (array $rows, string $sortKey = 'calls') { foreach ($rows as &$r) $r['avg_talk'] = $r['answered'] ? (int)round($r['talk'] / $r['answered']) : 0; unset($r); $rows = array_values($rows); usort($rows, fn($a, $b) => $b[$sortKey] <=> $a[$sortKey]); return $rows; };
        $tot['avg_talk'] = $tot['answered'] ? (int)round($tot['talk'] / $tot['answered']) : 0;
        $days = array_values($day); usort($days, fn($a, $b) => strcmp($a['day'], $b['day']));
        return ['total' => $tot, 'lines' => $fin($line), 'sources' => $fin($src), 'agents' => $fin($agent), 'days' => $days, 'read' => count($calls)];
    }
}
