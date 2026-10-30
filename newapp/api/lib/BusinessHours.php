<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Business-hours SLA calculator.
 * Setting key 'business_hours' holds JSON:
 *   { enabled, tz, days:[1..7 ISO], start:"HH:MM", end:"HH:MM", holidays:["YYYY-MM-DD", ...] }
 * opsh_bh_config() returns a normalized config array when enabled, else null.
 * opsh_bh_add() adds N working hours to a start timestamp, skipping
 * non-working days, hours outside the window, and holidays.
 */
if (!function_exists('opsh_bh_config')) {
    function opsh_bh_config(PDO $db): ?array {
        $s = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='business_hours'");
        $s->execute();
        $raw = $s->fetchColumn();
        if ($raw === false) return null;
        $c = json_decode((string)$raw, true);
        if (!is_array($c) || empty($c['enabled'])) return null;
        $days = (isset($c['days']) && is_array($c['days']) && $c['days']) ? array_values(array_unique(array_filter(array_map('intval', $c['days']), fn($d) => $d >= 1 && $d <= 7))) : [1, 2, 3, 4, 5];   // review DESK-25: 1..7 only
        if (!$days) $days = [1, 2, 3, 4, 5];
        $start = (isset($c['start']) && preg_match('/^\d{1,2}:\d{2}$/', $c['start'])) ? $c['start'] : '09:00';
        $end   = (isset($c['end'])   && preg_match('/^\d{1,2}:\d{2}$/', $c['end']))   ? $c['end']   : '17:00';
        $tzDef = date_default_timezone_get() ?: 'America/New_York';   // review DESK-25: the app's zone, not UTC (BT131: the installation's, applied at start)
        $tz = (isset($c['tz']) && $c['tz']) ? $c['tz'] : $tzDef;
        try { new DateTimeZone($tz); } catch (\Throwable $e) { $tz = $tzDef; }
        $holidays = (isset($c['holidays']) && is_array($c['holidays'])) ? array_flip($c['holidays']) : [];
        // a zero-length or inverted window would loop forever — guard it
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        if (($eh * 60 + $em) <= ($sh * 60 + $sm)) { $start = '09:00'; $end = '17:00'; }
        return ['days' => $days, 'start' => $start, 'end' => $end, 'tz' => $tz, 'holidays' => $holidays];
    }

    function opsh_bh_add(int $startTs, float $hours, array $cfg): int {
        if ($hours <= 0) return $startTs;
        $tz = new DateTimeZone($cfg['tz']);
        $cur = (new DateTime('@' . $startTs))->setTimezone($tz);
        [$sh, $sm] = array_map('intval', explode(':', $cfg['start']));
        [$eh, $em] = array_map('intval', explode(':', $cfg['end']));
        $remaining = (int) round($hours * 3600);
        $guard = 0;
        while ($remaining > 0 && $guard < 5000) {
            $guard++;
            $dow = (int) $cur->format('N');           // 1=Mon .. 7=Sun
            $dkey = $cur->format('Y-m-d');
            $isWork = in_array($dow, $cfg['days'], true) && !isset($cfg['holidays'][$dkey]);
            if (!$isWork) { $cur->modify('+1 day')->setTime(0, 0, 0); continue; }
            $winStart = (clone $cur)->setTime($sh, $sm, 0);
            $winEnd   = (clone $cur)->setTime($eh, $em, 0);
            if ($cur < $winStart) $cur = clone $winStart;
            if ($cur >= $winEnd) { $cur->modify('+1 day')->setTime(0, 0, 0); continue; }
            $avail = $winEnd->getTimestamp() - $cur->getTimestamp();
            if ($remaining <= $avail) { $cur->modify('+' . $remaining . ' seconds'); $remaining = 0; break; }
            $remaining -= $avail;
            $cur->modify('+1 day')->setTime(0, 0, 0);
        }
        return $cur->getTimestamp();
    }
}

if (!function_exists('opsh_bh_between')) {
    /** BT52: working seconds between two moments (business hours when they are on, else plain seconds). */
    function opsh_bh_between(int $fromTs, int $toTs, ?array $cfg): int {
        if ($toTs <= $fromTs) return 0;
        if (!$cfg) return $toTs - $fromTs;
        $tz = new DateTimeZone($cfg['tz']);
        $cur = (new DateTime('@' . $fromTs))->setTimezone($tz);
        [$sh, $sm] = array_map('intval', explode(':', $cfg['start']));
        [$eh, $em] = array_map('intval', explode(':', $cfg['end']));
        $sum = 0; $guard = 0;
        while ($cur->getTimestamp() < $toTs && $guard++ < 5000) {
            $dow = (int)$cur->format('N'); $dkey = $cur->format('Y-m-d');
            $winStart = (clone $cur)->setTime($sh, $sm, 0); $winEnd = (clone $cur)->setTime($eh, $em, 0);
            if (in_array($dow, $cfg['days'], true) && !isset($cfg['holidays'][$dkey])) {
                $a = max($cur->getTimestamp(), $winStart->getTimestamp()); $b = min($toTs, $winEnd->getTimestamp());
                if ($b > $a) $sum += $b - $a;
            }
            $cur->modify('+1 day')->setTime(0, 0, 0);
        }
        return $sum;
    }

    /** BT52: a due time moved later by $seconds of working time (used when a paused ticket resumes). */
    function opsh_bh_shift(string $due, int $seconds, ?array $cfg): string {
        $ts = strtotime($due); if ($ts === false || $seconds <= 0) return $due;
        return date('Y-m-d H:i:s', $cfg ? opsh_bh_add($ts, $seconds / 3600, $cfg) : $ts + $seconds);
    }
}

if (!function_exists('opsh_sla_hours')) {
    /**
     * [response hours, resolution hours] for a priority and a category (one rule for every channel — BT52).
     * The category's hours come first; without them, the priority's. For Critical and High, the shorter of the two is
     * used (setting "sla_urgent_shorter", on by default): a critical printer outage must not get the category's
     * relaxed 8-hour clock when the priority promises 4.
     */
    /**
     * BT52: the priority targets [response hours, resolution hours]. Before BT52 they were fixed in the code; now they
     * can be changed under Settings → SLA & Hours (setting "sla_priority_targets", JSON). Missing or invalid values keep
     * the defaults below. Hours may have decimals (0.5 = 30 minutes).
     */
    function opsh_sla_priority_targets(?PDO $db = null, ?string $type = null): array {
        $t = ['p1_critical' => [1, 4], 'p2_high' => [2, 8], 'p3_medium' => [4, 24], 'p4_low' => [8, 48], 'p5_planned' => [24, 120]];
        if ($db && $type === 'incident') {   // BT81: incidents can have their own targets; none set = the same as requests
            $base = opsh_sla_priority_targets($db);
            try { $st = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='sla_incident_targets'"); $st->execute(); $j = json_decode((string)$st->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
            if (!is_array($j) || !$j) return $base;
            foreach ($base as $k => $d) {
                $v = $j[$k] ?? null; if (!is_array($v)) continue;
                $r = isset($v[0]) && is_numeric($v[0]) && $v[0] > 0 && $v[0] <= 8760 ? (float)$v[0] : $d[0];
                $x = isset($v[1]) && is_numeric($v[1]) && $v[1] > 0 && $v[1] <= 8760 ? (float)$v[1] : $d[1];
                $base[$k] = [$r, max($x, $r)];
            }
            return $base;
        }
        if ($db) {
            try {
                $st = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='sla_priority_targets'"); $st->execute();
                $j = json_decode((string)$st->fetchColumn(), true);
                if (is_array($j)) foreach ($t as $k => $d) {
                    $v = $j[$k] ?? null; if (!is_array($v)) continue;
                    $r = isset($v[0]) && is_numeric($v[0]) && $v[0] > 0 && $v[0] <= 8760 ? (float)$v[0] : $d[0];
                    $x = isset($v[1]) && is_numeric($v[1]) && $v[1] > 0 && $v[1] <= 8760 ? (float)$v[1] : $d[1];
                    $t[$k] = [$r, max($x, $r)];
                }
            } catch (Throwable $e) {}
        }
        return $t;
    }

    function opsh_sla_hours($priority, $cat, ?PDO $db = null, ?string $type = null) {
        $tg = opsh_sla_priority_targets($db, $type);
        $dr = array_map(fn($v) => $v[0], $tg);
        $dx = array_map(fn($v) => $v[1], $tg);
        $p = isset($dr[$priority]) ? $priority : 'p3_medium';
        $r = ($cat && !empty($cat['sla_response_hours'])) ? (float)$cat['sla_response_hours'] : $dr[$p];
        $x = ($cat && !empty($cat['sla_hours']))          ? (float)$cat['sla_hours']          : $dx[$p];
        $urgentShorter = true;
        if ($db) { try { $st = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='sla_urgent_shorter'"); $st->execute(); $v = $st->fetchColumn(); if ($v !== false) $urgentShorter = (string)$v !== '0'; } catch (Throwable $e) {} }
        if ($urgentShorter && in_array($p, ['p1_critical', 'p2_high'], true)) { $r = min($r, $dr[$p]); $x = min($x, $dx[$p]); }
        return [$r, $x];
    }
}

if (!function_exists('opsh_db_clock_sync')) {
    /**
     * BT52: the database clock follows PHP's. Due times are written with PHP's clock (date()), while replies, created
     * times and the SLA checks use the database's (NOW(), TIMESTAMP columns). When the database server runs on another
     * zone (UTC on many hosts), a reply sent at once was recorded hours late and could breach the response SLA. This
     * sets the connection's zone to PHP's current offset. Never throws.
     */
    function opsh_db_clock_sync(PDO $db): void {
        try { $db->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
    }
}

if (!function_exists('opsh_ticket_sla_fill')) {
    /** Fill in the response / resolution due times of a ticket that was created outside the Tickets API
     *  (email intake, public request page, public forms) — same rules as a ticket created in the app:
     *  category hours first, else the priority defaults; counted in business hours when those are on.
     *  Only empty due times are set. Never throws. */
    function opsh_ticket_sla_fill(PDO $db, int $ticketId): void {
        try {
            $st = $db->prepare('SELECT t.*, c.sla_hours, c.sla_response_hours
                                  FROM opsh_tickets t LEFT JOIN opsh_ticket_categories c ON c.id=t.category_id WHERE t.id=?');
            $st->execute([$ticketId]); $t = $st->fetch(PDO::FETCH_ASSOC);
            if ($t && array_key_exists('ticket_type', $t) && empty($t['ticket_type'])) {   // BT81: intake tickets take their category's type
                $tt = 'request';
                if (!empty($t['category_id'])) { $cq = $db->prepare('SELECT ticket_type FROM opsh_ticket_categories WHERE id=?'); $cq->execute([(int)$t['category_id']]); $tt = (string)$cq->fetchColumn() === 'incident' ? 'incident' : 'request'; }
                $db->prepare('UPDATE opsh_tickets SET ticket_type=? WHERE id=?')->execute([$tt, $ticketId]); $t['ticket_type'] = $tt;
            }
            if (!$t || (!empty($t['sla_response_due']) && !empty($t['due_date']))) return;
            [$respH, $resoH] = opsh_sla_hours((string)($t['priority'] ?: 'p3_medium'), $t, $db, $t['ticket_type'] ?? null);   // BT52: one rule for every channel
            $start = time();   // called right after the insert (PHP clock, same as the Tickets API)
            $bh = opsh_bh_config($db);
            $resp = empty($t['sla_response_due']) ? date('Y-m-d H:i:s', $bh ? opsh_bh_add($start, $respH, $bh) : (int)($start + $respH * 3600)) : $t['sla_response_due'];
            $reso = empty($t['due_date'])         ? date('Y-m-d H:i:s', $bh ? opsh_bh_add($start, $resoH, $bh) : (int)($start + $resoH * 3600)) : $t['due_date'];
            $db->prepare('UPDATE opsh_tickets SET sla_response_due=?, due_date=? WHERE id=?')->execute([$resp, $reso, $ticketId]);
        } catch (Throwable $e) { /* due times are best-effort for intake tickets */ }
    }
}

if (!function_exists('opsh_ticket_sla_on_update')) {
    /**
     * BT52: what a ticket edit does to its SLA clocks. Called by PUT /tickets/{id} before the row is saved.
     * $prev is the row before the edit (SELECT *), $body the edit (changed in place). Returns
     *   ['extra' => columns to write after the save (pause columns, which may not exist before db-update),
     *    'clear_alerts' => true when the due times moved or the ticket reopened (so the SLA alerts can fire again),
     *    'notes' => what happened, for the audit log].
     * Rules:
     *  - Reopen (resolved / closed / cancelled → open, in progress or pending): resolved_at and closed_at are cleared,
     *    so the SLA is measured again instead of staying "met".
     *  - Pending pauses the clock: entering it records when; leaving it moves the due times on by the working time spent
     *    in Pending (the response due only while there has been no first response).
     *  - A new priority or category recalculates the due times from the creation time with the same rule as creation
     *    (opsh_sla_hours, business hours, earlier pauses added) — unless the person typed a due time in the same edit.
     */
    function opsh_ticket_sla_on_update(PDO $db, array $prev, array &$body): array {
        $out = ['extra' => [], 'clear_alerts' => false, 'notes' => []];
        if (!$prev) return $out;
        $done = ['resolved', 'closed', 'cancelled'];
        $old = (string)($prev['status'] ?? 'open');
        $new = isset($body['status']) && $body['status'] !== '' ? (string)$body['status'] : $old;
        $now = time();
        $bh = function_exists('opsh_bh_config') ? opsh_bh_config($db) : null;
        $hasPause = array_key_exists('sla_paused_at', $prev);   // the db-update has added the pause columns
        $same = function ($a, $b) { return substr(str_replace('T', ' ', (string)$a), 0, 16) === substr((string)$b, 0, 16); };
        $dueSent  = array_key_exists('due_date', $body) && $body['due_date'] !== null && $body['due_date'] !== '' && !$same($body['due_date'], $prev['due_date'] ?? '');
        $respSent = array_key_exists('sla_response_due', $body) && $body['sla_response_due'] !== null && $body['sla_response_due'] !== '' && !$same($body['sla_response_due'], $prev['sla_response_due'] ?? '');
        $due  = $prev['due_date'] ?? null;
        $resp = $prev['sla_response_due'] ?? null;
        $hasFirst = !empty($prev['first_response_at']) || !empty($body['first_response_at']);
        $pausedSecs = $hasPause ? (int)($prev['sla_paused_secs'] ?? 0) : 0;

        // Reopen — BT92 (decision 10/17/2026): the reopened ticket gets a new resolution due time, counted from now with the
        // same rule as a new ticket (its priority, category and type), unless a due time is typed in the same edit.
        $reopen = in_array($old, $done, true) && !in_array($new, $done, true);
        if ($reopen) {
            $body['resolved_at'] = null; $body['closed_at'] = null;
            $out['clear_alerts'] = true; $out['notes'][] = 'reopened: resolved and closed times cleared';
            if ((function () use ($db) { static $h = null; if ($h === null) { try { $db->query('SELECT sla_clock_start FROM opsh_tickets LIMIT 1'); $h = true; } catch (\Throwable $e) { $h = false; } } return $h; })()) { $out['extra']['sla_clock_start'] = date('Y-m-d H:i:s', $now); $out['extra']['sla_paused_secs'] = 0; }   // BT124 (review DESK-10): later recalculations count from here
            if (!$dueSent && function_exists('opsh_sla_hours')) {
                $catId = array_key_exists('category_id', $body) ? (int)$body['category_id'] : (int)($prev['category_id'] ?? 0);
                $cat = null;
                if ($catId) { try { $cs = $db->prepare('SELECT sla_hours, sla_response_hours FROM opsh_ticket_categories WHERE id=?'); $cs->execute([$catId]); $cat = $cs->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {} }
                $prio = (array_key_exists('priority', $body) && (string)$body['priority'] !== '') ? (string)$body['priority'] : (string)($prev['priority'] ?: 'p3_medium');
                $typ = (array_key_exists('ticket_type', $body) && (string)$body['ticket_type'] !== '') ? (string)$body['ticket_type'] : ($prev['ticket_type'] ?? null);
                [, $resoH] = opsh_sla_hours($prio, $cat, $db, $typ);
                $due = date('Y-m-d H:i:s', $bh ? opsh_bh_add($now, $resoH, $bh) : (int)($now + $resoH * 3600));
                $out['notes'][] = 'new resolution due time from the reopening';
            }
        }
        // Pause / resume
        if ($hasPause) {
            if ($new === 'pending' && $old !== 'pending') {
                $out['extra']['sla_paused_at'] = date('Y-m-d H:i:s', $now); $out['notes'][] = 'SLA paused (Pending)';
            } elseif ($old === 'pending' && $new !== 'pending' && !empty($prev['sla_paused_at'])) {
                $from = strtotime((string)$prev['sla_paused_at']);
                $secs = $from ? opsh_bh_between($from, $now, $bh) : 0;
                $out['extra']['sla_paused_at'] = null;
                if ($secs > 0) {
                    $pausedSecs += $secs; $out['extra']['sla_paused_secs'] = $pausedSecs;
                    if ($due && !$dueSent) $due = opsh_bh_shift((string)$due, $secs, $bh);
                    if ($resp && !$respSent && !$hasFirst) $resp = opsh_bh_shift((string)$resp, $secs, $bh);
                    $out['clear_alerts'] = true;
                    $out['notes'][] = 'SLA resumed: due times moved on by ' . round($secs / 60) . ' working minutes spent in Pending';
                }
            }
        }
        // Recalculate on a new priority or category
        $prioChanged = array_key_exists('priority', $body) && (string)$body['priority'] !== '' && (string)$body['priority'] !== (string)($prev['priority'] ?? '');
        $catChanged  = array_key_exists('category_id', $body) && (int)$body['category_id'] !== (int)($prev['category_id'] ?? 0);
        $typeChanged = array_key_exists('ticket_type', $body) && (string)$body['ticket_type'] !== '' && (string)$body['ticket_type'] !== (string)($prev['ticket_type'] ?? '');   // BT81
        $type = $typeChanged ? (string)$body['ticket_type'] : ($prev['ticket_type'] ?? null);
        if (($prioChanged || $catChanged || $typeChanged) && !$reopen && !in_array($new, $done, true) && (!$dueSent || (!$respSent && !$hasFirst))) {
            $catId = $catChanged ? (int)$body['category_id'] : (int)($prev['category_id'] ?? 0);
            $cat = null;
            if ($catId) { try { $cs = $db->prepare('SELECT sla_hours, sla_response_hours FROM opsh_ticket_categories WHERE id=?'); $cs->execute([$catId]); $cat = $cs->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {} }
            $prio = $prioChanged ? (string)$body['priority'] : (string)($prev['priority'] ?: 'p3_medium');
            [$respH, $resoH] = opsh_sla_hours($prio, $cat, $db, $type);
            $start = strtotime((string)(($prev['sla_clock_start'] ?? '') ?: ($prev['created_at'] ?? ''))) ?: $now;   // BT124: from the reopening when there was one
            $calc = function (float $h) use ($start, $bh, $pausedSecs) {
                $ts = $bh ? opsh_bh_add($start, $h, $bh) : (int)($start + $h * 3600);
                return opsh_bh_shift(date('Y-m-d H:i:s', $ts), $pausedSecs, $bh);
            };
            if (!$dueSent) $due = $calc($resoH);
            if (!$respSent && !$hasFirst) $resp = $calc($respH);
            $out['clear_alerts'] = true;
            $out['notes'][] = 'due times recalculated for ' . ($prioChanged ? 'the new priority' : '') . ($prioChanged && $catChanged ? ' and ' : '') . ($catChanged ? 'the new category' : '') . ($typeChanged ? (($prioChanged || $catChanged) ? ' and ' : '') . 'the new ticket type' : '');
        }
        if (!$dueSent && $due !== ($prev['due_date'] ?? null)) $body['due_date'] = $due;
        elseif (!$dueSent) unset($body['due_date']);
        if (!$respSent && $resp !== ($prev['sla_response_due'] ?? null)) $body['sla_response_due'] = $resp;
        elseif (!$respSent) unset($body['sla_response_due']);
        if ($dueSent || $respSent) $out['clear_alerts'] = true;
        return $out;
    }
}
