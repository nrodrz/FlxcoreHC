<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT94 — Scorecard: rows for each priority target, and "the data behind the number".
 *
 *   opsh_scp_*   the priority rows: for each priority (Critical P1 … Planning P5), the share of tickets that got their
 *                first response on time and the share resolved on time — the targets set under Settings → SLA & Hours.
 *                Settings → Scorecard → "Priority targets" chooses which of them are on the Scorecard.
 *   opsh_scd_*   what a Scorecard number means: its definition, the rule, the count behind it and the records counted
 *                (Scorecard → click a value). Ticket records are listed only for people who see every ticket.
 *
 * Ticket times are the ones stored on each ticket (sla_response_due, due_date), so business hours, paused time and a
 * category's own hours are already in them; the numbers here never recalculate a target.
 */

if (!function_exists('opsh_scp_prios')) {

    /** The five priorities: key => [short code, name]. */
    function opsh_scp_prios(): array {
        return ['p1_critical' => ['P1', 'Critical'], 'p2_high' => ['P2', 'High'], 'p3_medium' => ['P3', 'Medium'], 'p4_low' => ['P4', 'Low'], 'p5_planned' => ['P5', 'Planning']];
    }
    /** The two measures of a priority target. */
    function opsh_scp_kinds(): array { return ['resp' => 'first response on time', 'res' => 'resolved on time']; }

    /** Scorecard sources: pri_resp_p1_critical => "Critical (P1): first response on time (%)", … */
    function opsh_scp_sources(): array {
        $o = [];
        foreach (opsh_scp_prios() as $k => [$p, $n]) foreach (opsh_scp_kinds() as $kk => $l) $o['pri_' . $kk . '_' . $k] = $n . ' (' . $p . '): ' . $l . ' (%)';
        return $o;
    }
    /** 'pri_resp_p2_high' → ['resp', 'p2_high'], anything else → null. */
    function opsh_scp_parse(string $src): ?array {
        if (!preg_match('/^pri_(resp|res)_(p[1-5]_[a-z]+)$/', $src, $m) || !isset(opsh_scp_prios()[$m[2]])) return null;
        return [$m[1], $m[2]];
    }
    /** The name a new priority row gets: "Critical (P1) — first response on time". */
    function opsh_scp_row_name(string $kind, string $prio): string {
        [$p, $n] = opsh_scp_prios()[$prio]; return $n . ' (' . $p . ') — ' . opsh_scp_kinds()[$kind];
    }

    /**
     * Met / missed for one priority over [$s, $e).
     *  resp: tickets created in the period with a response due time; met = first response by the due time; missed = a late
     *        first response, or none yet and the due time has passed. A ticket still inside its response time is not
     *        counted yet ("waiting").
     *  res:  tickets resolved in the period; met = resolved by the due time (no due time counts as met) — the same rule as
     *        "Tickets closed within SLA", for one priority.
     * Returns ['met'=>n, 'missed'=>n, 'waiting'=>n].
     */
    function opsh_scp_counts(PDO $db, string $kind, string $prio, string $s, string $e): array {
        if ($kind === 'resp') {
            $st = $db->prepare("SELECT SUM(first_response_at IS NOT NULL AND first_response_at <= sla_response_due) met,
                                       SUM((first_response_at IS NOT NULL AND first_response_at > sla_response_due) OR (first_response_at IS NULL AND sla_response_due < NOW())) missed,
                                       SUM(first_response_at IS NULL AND sla_response_due >= NOW()) waiting
                                FROM opsh_tickets WHERE priority=? AND status<>'cancelled' AND merged_into_id IS NULL AND sla_response_due IS NOT NULL AND created_at >= ? AND created_at < ?");
        } else {
            $st = $db->prepare("SELECT SUM(due_date IS NULL OR resolved_at <= due_date) met, SUM(due_date IS NOT NULL AND resolved_at > due_date) missed, 0 waiting
                                FROM opsh_tickets WHERE priority=? AND resolved_at IS NOT NULL AND resolved_at >= ? AND resolved_at < ?");
        }
        $st->execute([$prio, $s, $e]); $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['met' => (int)($r['met'] ?? 0), 'missed' => (int)($r['missed'] ?? 0), 'waiting' => (int)($r['waiting'] ?? 0)];
    }
    /** The Scorecard value of a priority source over [$s, $e): the % met, or null when nothing was counted. */
    function opsh_scp_value(PDO $db, string $src, string $s, string $e): ?float {
        $k = opsh_scp_parse($src); if (!$k) return null;
        try { $c = opsh_scp_counts($db, $k[0], $k[1], $s, $e); } catch (Throwable $ex) { return null; }
        $tot = $c['met'] + $c['missed'];
        return $tot ? round(100.0 * $c['met'] / $tot, 1) : null;
    }
    /** The priority sources with their row on the Scorecard (if any) and whether it is shown: for the chooser. */
    function opsh_scp_matrix(PDO $db): array {
        $rows = [];
        try { foreach ($db->query("SELECT id, name, source, active, goal_op, goal_value, unit FROM opsh_scorecard_measurables WHERE source LIKE 'pri\\_%'")->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['source']] = $r; } catch (Throwable $e) {}
        $tg = function_exists('opsh_sla_priority_targets') ? opsh_sla_priority_targets($db) : [];
        $out = [];
        foreach (opsh_scp_prios() as $k => [$p, $n]) {
            $line = ['priority' => $k, 'code' => $p, 'name' => $n, 'response_h' => $tg[$k][0] ?? null, 'resolution_h' => $tg[$k][1] ?? null, 'measures' => []];
            foreach (opsh_scp_kinds() as $kk => $l) {
                $src = 'pri_' . $kk . '_' . $k; $r = $rows[$src] ?? null;
                $line['measures'][$kk] = ['source' => $src, 'label' => $l, 'on' => $r && (int)$r['active'] === 1, 'row_id' => $r ? (int)$r['id'] : null,
                                          'row_name' => $r ? (string)$r['name'] : null, 'goal' => $r ? (float)$r['goal_value'] : null];
            }
            $out[] = $line;
        }
        return $out;
    }

    // ───────────────────────────── Priority definitions ─────────────────────────────

    /**
     * What each priority means, with examples (priority = impact × urgency). Editable under Settings → SLA & Hours
     * (setting "sla_priority_defs"); what is not set keeps these words.
     */
    function opsh_prio_defs_default(): array {
        return [
            'p1_critical' => ['meaning' => 'A whole service is down or a security incident is under way: many people or a critical part of the work are stopped and there is no workaround. High impact, high urgency.',
                              'examples' => "Email or the internet is down for everyone at a site\nA phone system outage at the main office\nRansomware, or an administrator account that may be compromised\nThe payroll or client records system is unavailable when it is needed"],
            'p2_high'     => ['meaning' => 'A key service is badly degraded, or a whole team (or a person with an urgent deadline) cannot work, and there is no reasonable workaround. High impact with medium urgency, or medium impact with high urgency.',
                              'examples' => "The VPN fails for all remote staff\nA department cannot print anything before a deadline\nA director's laptop does not start an hour before a board meeting\nSeveral people clicked the same phishing email"],
            'p3_medium'   => ['meaning' => 'One person or a small group is affected, or a service is slower than it should be, and work can carry on with a workaround. The usual priority for a fault.',
                              'examples' => "One person cannot open a shared drive but can work from OneDrive\nOutlook keeps closing on one computer\nThe Wi-Fi is slow in one meeting room\nA new starter is missing a shared mailbox on their second day"],
            'p4_low'      => ['meaning' => 'A small problem, a question or a request with little effect on the work: it can wait without harm.',
                              'examples' => "A request for a second monitor\nA how-to question (setting up an email signature)\nA cosmetic mistake on a form\nSoftware that is not needed this week"],
            'p5_planned'  => ['meaning' => 'Planned work with a date agreed in advance: nothing is broken. The clock is long because the work is scheduled.',
                              'examples' => "Setting up a computer for a start date two weeks away\nAn office or desk move\nA scheduled software upgrade\nReplacing laptops in a refresh cycle"],
        ];
    }
    /** The definitions in use: the saved ones over the defaults. */
    function opsh_prio_defs(PDO $db): array {
        $d = opsh_prio_defs_default();
        try {
            $st = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='sla_priority_defs'"); $st->execute();
            $j = json_decode((string)$st->fetchColumn(), true);
            if (is_array($j)) foreach ($d as $k => $v) foreach (['meaning', 'examples'] as $f) {
                if (isset($j[$k][$f]) && is_string($j[$k][$f]) && trim($j[$k][$f]) !== '') $d[$k][$f] = trim($j[$k][$f]);
            }
        } catch (Throwable $e) {}
        return $d;
    }
    /** Clean what Settings sends: meaning up to 600 characters, examples up to 8 lines of 160. Throws on a bad shape. */
    function opsh_prio_defs_clean($v): array {
        if (is_string($v)) $v = json_decode($v, true);
        if (!is_array($v)) throw new InvalidArgumentException('Priority definitions: a meaning and examples per priority are expected.');
        $out = [];
        foreach (array_keys(opsh_scp_prios()) as $k) {
            $x = $v[$k] ?? null; if ($x === null) continue;
            if (!is_array($x)) throw new InvalidArgumentException('Priority definitions: a meaning and examples per priority are expected.');
            $m = trim((string)($x['meaning'] ?? '')); $ex = trim(str_replace("\r", '', (string)($x['examples'] ?? '')));
            if (mb_strlen($m) > 600) throw new InvalidArgumentException('Priority definitions: the meaning of ' . opsh_scp_prios()[$k][1] . ' is longer than 600 characters.');
            $lines = array_values(array_filter(array_map('trim', explode("\n", $ex)), fn($l) => $l !== ''));
            if (count($lines) > 8) throw new InvalidArgumentException('Priority definitions: up to 8 examples for ' . opsh_scp_prios()[$k][1] . '.');
            foreach ($lines as $l) if (mb_strlen($l) > 160) throw new InvalidArgumentException('Priority definitions: one example of ' . opsh_scp_prios()[$k][1] . ' is longer than 160 characters.');
            $out[$k] = ['meaning' => $m, 'examples' => implode("\n", $lines)];
        }
        return $out;
    }

    // ───────────────────────────── The data behind a number ─────────────────────────────

    /** Plain words for what a source measures and how. [what, rule, area page or ''] */
    function opsh_scd_definition(string $src, string $label): array {
        $pk = opsh_scp_parse($src);
        if ($pk) {
            [$p, $n] = opsh_scp_prios()[$pk[1]];
            return $pk[0] === 'resp'
                ? ['The share of ' . $n . ' (' . $p . ') tickets that got their first response within the response target.',
                   'Counted: tickets created in the period with priority ' . $n . ' (not cancelled or merged). On time = the first response (a public reply, Pending, or Resolved by someone other than the requester) came by the response due time on the ticket. Late = it came after, or there is none yet and the due time has passed. Tickets still inside their response time are not counted yet. Value = on time ÷ counted × 100.', 'tickets']
                : ['The share of ' . $n . ' (' . $p . ') tickets resolved within the resolution target.',
                   'Counted: tickets with priority ' . $n . ' resolved in the period. On time = resolved by the due time on the ticket (a ticket with no due time counts as on time). Tickets still open past their due time are counted when they are resolved. Value = on time ÷ resolved × 100.', 'tickets'];
        }
        $D = [
            'manual'            => ['A number typed in by hand each week.', 'Typed by the row\'s owner or anyone with Scorecard · Edit. Months and years combine the weekly values (average, total or latest week, as set on the row).', ''],
            'p1_incidents'      => ['Critical (P1) tickets opened in the period.', 'Count of tickets created in the period whose priority is Critical.', 'tickets'],
            'ticket_volume'     => ['Tickets opened in the period.', 'Count of all tickets created in the period, whatever their status now.', 'tickets'],
            'frt_minutes'       => ['How long people waited for the first response, on average.', 'For tickets created in the period that have a first response: the minutes from creation to the first response (clock time), averaged. Tickets without a response yet are left out.', 'tickets'],
            'sla_closure_pct'   => ['The share of tickets resolved within their due time.', 'Counted: tickets resolved in the period, all priorities. On time = resolved by the due time on the ticket (no due time counts as on time). Value = on time ÷ resolved × 100.', 'tickets'],
            'onboarding_ontime' => ['The share of onboardings finished with every step on time.', 'Counted: onboarding processes completed in the period. On time = no step with a due date was finished after it. Value = on time ÷ completed × 100.', 'onboarding'],
            'open_alerts'       => ['Server alerts still open when the week was saved.', 'A reading taken when the Scorecard was refreshed: the number of open alerts at that moment. Months and years use the weekly readings.', 'alerts'],
            'secure_score_pct'  => ['Microsoft Secure Score, as a % of the maximum.', 'A reading from Microsoft 365 when the Scorecard was refreshed: current score ÷ maximum score × 100.', 'm365'],
            'intune_compliance' => ['The share of managed devices reporting compliant in Intune.', 'A reading from Microsoft 365 when the Scorecard was refreshed: compliant devices ÷ managed devices × 100 (up to 200 devices are read).', 'm365'],
            'mfa_pct'           => ['The share of people registered for multi-factor sign-in.', 'A reading from Microsoft 365 when the Scorecard was refreshed: people registered for MFA ÷ people in the registration report × 100.', 'm365'],
            'ssl_days_min'      => ['The fewest days left before a watched certificate expires.', 'A reading from Server Guardian when the Scorecard was refreshed (an expired certificate counts as 0).', 'guardian'],
            'uptime_pct'        => ['Server availability from Server Guardian alerts.', 'The share of the period with no open error alert from Server Guardian.', 'guardian'],
            'backup_success'    => ['Days with a successful backup check.', 'The share of days in the period with a backup check logged without an error.', 'backups'],
        ];
        if (isset($D[$src])) return $D[$src];
        if (strpos($src, 'uptime_') === 0) return [$label . '.', 'Worked out from the Uptime monitors for the period. Open IT Operations → Uptime for the outages behind it.', 'uptime'];
        if (strpos($src, 'bkp_') === 0) return [$label . '.', 'Worked out from the backups watched in IT Operations → Backups: each expected backup counts once, on time when it arrived in its slot.', 'backups'];
        if ($src === 'sa_score') return ['The security assessment score of the Microsoft 365 tenant.', 'The score of the last scan before the end of the period: rules passed ÷ rules checked in the chosen baseline × 100 (rules the tenant has no license for are left out).', 'security_assessment'];   // BT123
        if ($src === 'sa_failed') return ['How many rules of the chosen baseline failed.', 'The failed rules in the last scan before the end of the period (warnings are not counted).', 'security_assessment'];
        if ($src === 'sa_drift') return ['Rules that passed and then failed or warned again.', 'Counted: drift found by the scans in the period (a rule that passed in one scan and fails or warns in the next). Microsoft 365 → Posture → Drift lists each one, with what may have changed it.', 'security_assessment'];
        if (strpos($src, 'ins_') === 0) return [$label . '.', 'Worked out from tickets, cases and the improvement register for the period. Reports shows the same measure with its details.', 'reports'];
        return [$label !== '' ? $label . '.' : 'An automatic measure.', 'Worked out automatically for the period.', ''];
    }

    /** True when the source is about tickets (its records are tickets). */
    function opsh_scd_ticket_source(string $src): bool {
        return opsh_scp_parse($src) !== null || in_array($src, ['p1_incidents', 'ticket_volume', 'frt_minutes', 'sla_closure_pct'], true);
    }

    /** Minutes between two date-times, as "2 h 5 min" / "3 d 4 h". */
    function opsh_scd_span(int $sec): string {
        $m = (int)round(abs($sec) / 60);
        if ($m < 60) return $m . ' min';
        if ($m < 1440) return intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
        return intdiv($m, 1440) . ' d' . (intdiv($m % 1440, 60) ? ' ' . intdiv($m % 1440, 60) . ' h' : '');
    }

    /**
     * The records behind an automatic value over [$s, $e).
     * Returns ['columns'=>[…], 'rows'=>[…], 'total'=>n, 'numerator'=>…, 'denominator'=>…, 'num_label'=>…, 'den_label'=>…,
     *          'extra'=>[…]] or null when the source has no record list. $limit caps the rows (the counts are always whole).
     * Each row: ['id'=>, 'link'=>'#tickets/12' or '', 'cells'=>[…], 'result'=>'met'|'missed'|'waiting'|'counted'].
     */
    function opsh_scd_records(PDO $db, string $src, string $s, string $e, int $limit = 500): ?array {
        $fmt = fn($d) => $d ? date('m/d/Y g:i A', strtotime((string)$d)) : '—';
        $pl = function ($p) { $x = opsh_scp_prios()[$p] ?? null; return $x ? $x[1] : (string)$p; };
        $tkCols = ['Ticket', 'Subject', 'Priority'];
        $pk = opsh_scp_parse($src);
        if ($pk && $pk[0] === 'resp') { $kind = 'resp'; $prio = $pk[1]; }
        elseif ($pk) { $kind = 'res'; $prio = $pk[1]; }
        elseif ($src === 'sla_closure_pct') { $kind = 'res'; $prio = null; }
        elseif ($src === 'p1_incidents' || $src === 'ticket_volume') { $kind = 'count'; $prio = $src === 'p1_incidents' ? 'p1_critical' : null; }
        elseif ($src === 'frt_minutes') { $kind = 'frt'; $prio = null; }
        elseif ($src === 'onboarding_ontime') { $kind = 'ob'; $prio = null; }
        else return null;

        $rows = []; $met = 0; $missed = 0; $waiting = 0; $sum = 0; $n = 0; $now = time();
        if ($kind === 'resp') {
            $st = $db->prepare("SELECT id, ticket_number, subject, priority, created_at, sla_response_due, first_response_at FROM opsh_tickets
                                WHERE priority=? AND status<>'cancelled' AND merged_into_id IS NULL AND sla_response_due IS NOT NULL AND created_at >= ? AND created_at < ? ORDER BY created_at");
            $st->execute([$prio, $s, $e]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $due = strtotime((string)$t['sla_response_due']); $fr = $t['first_response_at'] ? strtotime((string)$t['first_response_at']) : null;
                if ($fr !== null) { $res = $fr <= $due ? 'met' : 'missed'; $how = $fr <= $due ? 'On time' : 'Late by ' . opsh_scd_span($fr - $due); }
                elseif ($due < $now) { $res = 'missed'; $how = 'No response yet — overdue by ' . opsh_scd_span($now - $due); }
                else { $res = 'waiting'; $how = 'Not counted yet — due in ' . opsh_scd_span($due - $now); }
                if ($res === 'met') $met++; elseif ($res === 'missed') $missed++; else $waiting++;
                $rows[] = ['id' => (int)$t['id'], 'link' => '#tickets/' . (int)$t['id'], 'result' => $res,
                           'cells' => [$t['ticket_number'], $t['subject'], $pl($t['priority']), $fmt($t['created_at']), $fmt($t['sla_response_due']), $fmt($t['first_response_at']), $how]];
            }
            return ['columns' => array_merge($tkCols, ['Created', 'Response due', 'First response', 'Result']), 'rows' => array_slice($rows, 0, $limit), 'total' => count($rows),
                    'numerator' => $met, 'denominator' => $met + $missed, 'num_label' => 'on time', 'den_label' => 'counted', 'extra' => $waiting ? [$waiting . ' ticket' . ($waiting === 1 ? ' is' : 's are') . ' still inside the response time and not counted yet.'] : []];
        }
        if ($kind === 'res') {
            $st = $db->prepare("SELECT id, ticket_number, subject, priority, created_at, due_date, resolved_at FROM opsh_tickets
                                WHERE resolved_at IS NOT NULL AND resolved_at >= ? AND resolved_at < ?" . ($prio ? ' AND priority=?' : '') . " ORDER BY resolved_at");
            $st->execute($prio ? [$s, $e, $prio] : [$s, $e]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $ok = !$t['due_date'] || strtotime((string)$t['resolved_at']) <= strtotime((string)$t['due_date']);
                $ok ? $met++ : $missed++;
                $how = !$t['due_date'] ? 'On time (no due time)' : ($ok ? 'On time' : 'Late by ' . opsh_scd_span(strtotime((string)$t['resolved_at']) - strtotime((string)$t['due_date'])));
                $rows[] = ['id' => (int)$t['id'], 'link' => '#tickets/' . (int)$t['id'], 'result' => $ok ? 'met' : 'missed',
                           'cells' => [$t['ticket_number'], $t['subject'], $pl($t['priority']), $fmt($t['created_at']), $fmt($t['due_date']), $fmt($t['resolved_at']), $how]];
            }
            $extra = [];
            try {   // tickets of the same scope still open past their due time: not in the number yet, worth knowing
                $q = $db->prepare("SELECT COUNT(*) FROM opsh_tickets WHERE status IN ('open','in_progress','pending') AND due_date IS NOT NULL AND due_date < NOW()" . ($prio ? ' AND priority=?' : ''));
                $q->execute($prio ? [$prio] : []); $od = (int)$q->fetchColumn();
                if ($od) $extra[] = $od . ' open ticket' . ($od === 1 ? ' is' : 's are') . ' past the due time right now' . ($prio ? ' at this priority' : '') . '; ' . ($od === 1 ? 'it counts' : 'they count') . ' when resolved.';
            } catch (Throwable $ex) {}
            return ['columns' => array_merge($tkCols, ['Created', 'Due', 'Resolved', 'Result']), 'rows' => array_slice($rows, 0, $limit), 'total' => count($rows),
                    'numerator' => $met, 'denominator' => $met + $missed, 'num_label' => 'on time', 'den_label' => 'resolved', 'extra' => $extra];
        }
        if ($kind === 'count') {
            $st = $db->prepare("SELECT t.id, t.ticket_number, t.subject, t.priority, t.status, t.created_at, u.display_name rq FROM opsh_tickets t LEFT JOIN opsh_users u ON u.id=t.requester_id
                                WHERE t.created_at >= ? AND t.created_at < ?" . ($prio ? ' AND t.priority=?' : '') . " ORDER BY t.created_at");
            $st->execute($prio ? [$s, $e, $prio] : [$s, $e]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $rows[] = ['id' => (int)$t['id'], 'link' => '#tickets/' . (int)$t['id'], 'result' => 'counted',
                'cells' => [$t['ticket_number'], $t['subject'], $pl($t['priority']), $fmt($t['created_at']), str_replace('_', ' ', (string)$t['status']), (string)($t['rq'] ?? '')]];
            return ['columns' => array_merge($tkCols, ['Created', 'Status now', 'Requester']), 'rows' => array_slice($rows, 0, $limit), 'total' => count($rows),
                    'numerator' => count($rows), 'denominator' => null, 'num_label' => 'tickets', 'den_label' => '', 'extra' => []];
        }
        if ($kind === 'frt') {
            $st = $db->prepare("SELECT id, ticket_number, subject, priority, created_at, first_response_at, TIMESTAMPDIFF(MINUTE, created_at, first_response_at) mins FROM opsh_tickets
                                WHERE first_response_at IS NOT NULL AND created_at >= ? AND created_at < ? ORDER BY mins DESC");
            $st->execute([$s, $e]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) { $sum += (int)$t['mins']; $n++;
                $rows[] = ['id' => (int)$t['id'], 'link' => '#tickets/' . (int)$t['id'], 'result' => 'counted',
                           'cells' => [$t['ticket_number'], $t['subject'], $pl($t['priority']), $fmt($t['created_at']), $fmt($t['first_response_at']), (string)(int)$t['mins']]]; }
            return ['columns' => array_merge($tkCols, ['Created', 'First response', 'Minutes']), 'rows' => array_slice($rows, 0, $limit), 'total' => count($rows),
                    'numerator' => $sum, 'denominator' => $n, 'num_label' => 'minutes in all', 'den_label' => 'tickets', 'extra' => ['Longest waits first.']];
        }
        // onboarding
        $st = $db->prepare("SELECT r.id, r.subject_name, r.completed_at, (SELECT COUNT(*) FROM opsh_ob_run_tasks t WHERE t.run_id=r.id AND t.due_date IS NOT NULL AND t.status='done' AND t.due_date < DATE(COALESCE(t.completed_at, r.completed_at))) AS late
                            FROM opsh_ob_runs r WHERE r.kind='onboard' AND r.status='completed' AND r.completed_at >= ? AND r.completed_at < ? ORDER BY r.completed_at");
        $st->execute([$s, $e]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $ok = (int)$r['late'] === 0; $ok ? $met++ : $missed++;
            $rows[] = ['id' => (int)$r['id'], 'link' => '', 'result' => $ok ? 'met' : 'missed',
                       'cells' => [(string)$r['subject_name'], $fmt($r['completed_at']), (string)(int)$r['late'], $ok ? 'On time' : 'Late steps']]; }
        return ['columns' => ['Onboarding', 'Completed', 'Steps finished late', 'Result'], 'rows' => array_slice($rows, 0, $limit), 'total' => count($rows),
                'numerator' => $met, 'denominator' => $met + $missed, 'num_label' => 'on time', 'den_label' => 'completed', 'extra' => []];
    }
}
