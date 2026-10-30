<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Phase 7 (BT83): reporting and continual improvement.
 *
 * Service measures worked out from the tickets and the Audit Log, the Cases & Projects report (health, overdue tasks,
 * workload, tickets per case, time per template), and the automatic Scorecard measures built on them.
 *
 * First-contact resolution: a resolved ticket counts when its first owner resolved it without handing it to someone
 * else, without putting it in Pending and without it being reopened afterwards (agreed with the user, 10/15/2026).
 * Tickets merged into another one are left out.
 */
if (!function_exists('opsh_ins_ticket_flags')) {
    /** For each ticket id: ['reassigned' => bool, 'pending' => bool, 'reopened' => bool], from the Audit Log. */
    function opsh_ins_ticket_flags(PDO $db, array $ids): array {
        $out = [];
        foreach ($ids as $i) $out[(int)$i] = ['reassigned' => false, 'pending' => false, 'reopened' => false];
        $done = ['resolved', 'closed'];
        foreach (array_chunk(array_keys($out), 400) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $q = $db->query("SELECT entity_id, action, old_value, new_value FROM opsh_audit_log WHERE entity_type='ticket' AND entity_id IN ($in) AND action IN ('updated','ticket_reopened')");
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)$r['entity_id']; if (!isset($out[$id])) continue;
                if ($r['action'] === 'ticket_reopened') { $out[$id]['reopened'] = true; continue; }
                $old = json_decode((string)$r['old_value'], true); $new = json_decode((string)$r['new_value'], true);
                if (!is_array($new)) continue; if (!is_array($old)) $old = [];
                if (array_key_exists('assignee_id', $new) && !empty($old['assignee_id']) && (int)$new['assignee_id'] !== (int)$old['assignee_id']) $out[$id]['reassigned'] = true;
                if (($new['status'] ?? '') === 'pending') $out[$id]['pending'] = true;
                if (isset($new['status']) && in_array((string)($old['status'] ?? ''), $done, true) && in_array((string)$new['status'], ['open', 'in_progress', 'pending'], true)) $out[$id]['reopened'] = true;
            }
        }
        return $out;
    }

    /**
     * First-contact resolution and reopen rate over a set of tickets: $where / $params filter opsh_tickets (alias t).
     * Returns resolved, fcr, fcr_pct, reopened, reopen_pct, reassigned, pending.
     */
    function opsh_ins_fcr(PDO $db, string $where, array $params): array {
        $sql = "SELECT t.id, COALESCE(t.sla_paused_secs,0) AS paused FROM opsh_tickets t WHERE " . ($where !== '' ? "($where) AND " : '')
             . "t.status IN ('resolved','closed') AND COALESCE(t.resolved_at,t.closed_at) IS NOT NULL AND t.merged_into_id IS NULL";
        try { $st = $db->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) {   // installs without the merge / pause columns
            $st = $db->prepare("SELECT t.id, 0 AS paused FROM opsh_tickets t WHERE " . ($where !== '' ? "($where) AND " : '') . "t.status IN ('resolved','closed')"); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $n = count($rows); $res = ['resolved' => $n, 'fcr' => 0, 'fcr_pct' => null, 'reopened' => 0, 'reopen_pct' => null, 'reassigned' => 0, 'pending' => 0];
        if (!$n) return $res;
        $flags = opsh_ins_ticket_flags($db, array_column($rows, 'id'));
        foreach ($rows as $r) {
            $f = $flags[(int)$r['id']]; if ((int)$r['paused'] > 0) $f['pending'] = true;
            if ($f['reopened']) $res['reopened']++; if ($f['reassigned']) $res['reassigned']++; if ($f['pending']) $res['pending']++;
            if (!$f['reopened'] && !$f['reassigned'] && !$f['pending']) $res['fcr']++;
        }
        $res['fcr_pct'] = round(100 * $res['fcr'] / $n, 1); $res['reopen_pct'] = round(100 * $res['reopened'] / $n, 1);
        return $res;
    }

    /** Cases and projects finished in [$s, $e) with a target date, and how many by that date. */
    function opsh_ins_cases_ontime(PDO $db, string $s, string $e): array {
        $q = $db->prepare("SELECT COUNT(*) n, SUM(DATE(resolved_at) <= target_date) ok FROM opsh_cases WHERE status IN ('resolved','closed') AND resolved_at >= ? AND resolved_at < ? AND target_date IS NOT NULL");
        $q->execute([$s, $e]); $r = $q->fetch(PDO::FETCH_ASSOC);
        $n = (int)$r['n']; return ['finished' => $n, 'on_time' => (int)$r['ok'], 'pct' => $n ? round(100 * (int)$r['ok'] / $n, 1) : null];
    }
    /** Open case and project tasks past their due date, right now. */
    function opsh_ins_overdue_tasks(PDO $db): int {
        return (int)$db->query("SELECT COUNT(*) FROM opsh_case_tasks t JOIN opsh_cases c ON c.id=t.case_id WHERE c.status IN ('open','in_progress') AND t.status IN ('open','in_progress','waiting') AND t.due_date < CURDATE()")->fetchColumn();
    }
    function opsh_ins_change_success(PDO $db, string $s, string $e): ?float {
        try {
            $q = $db->prepare("SELECT COUNT(*) n, SUM(i.outcome IN ('successful','issues')) ok FROM opsh_cases c JOIN opsh_case_svc i ON i.case_id=c.id WHERE c.origin='change' AND c.kind='case' AND c.status IN ('resolved','closed') AND i.outcome IS NOT NULL AND c.resolved_at >= ? AND c.resolved_at < ?");
            $q->execute([$s, $e]); $r = $q->fetch(PDO::FETCH_ASSOC);
            return (int)$r['n'] ? round(100 * (int)$r['ok'] / (int)$r['n'], 1) : null;
        } catch (Throwable $ex) { return null; }
    }

    /* ── Scorecard ─────────────────────────────────────────────────────────────────────────── */
    function opsh_ins_scorecard_sources(): array {
        return [
            'ins_fcr_pct'            => 'First-contact resolution (%)',
            'ins_reopen_pct'         => 'Reopened tickets (%)',
            'ins_change_success_pct' => 'Changes that worked (%)',
            'ins_csat_pct'           => 'Requesters happy (%)',
            'ins_cases_ontime_pct'   => 'Cases and projects closed on time (%)',
            'ins_overdue_tasks'      => 'Overdue case and project tasks (count)',
            'ins_improvements_done'  => 'Improvements done (count)',   // BT84: the continual improvement register
        ];
    }
    /** The goal each measure starts with, and whether higher is better. */
    function opsh_ins_scorecard_goal(string $src): array {
        $g = ['ins_fcr_pct' => [70, 'gte', '%'], 'ins_reopen_pct' => [5, 'lte', '%'], 'ins_change_success_pct' => [90, 'gte', '%'], 'ins_csat_pct' => [90, 'gte', '%'],
              'ins_cases_ontime_pct' => [80, 'gte', '%'], 'ins_overdue_tasks' => [0, 'lte', ''], 'ins_improvements_done' => [1, 'gte', '']];
        return $g[$src] ?? [0, 'gte', ''];
    }
    function opsh_ins_scorecard_value(PDO $db, string $src, string $s, string $e): ?float {
        try {
            if ($src === 'ins_fcr_pct' || $src === 'ins_reopen_pct') {
                $r = opsh_ins_fcr($db, 'COALESCE(t.resolved_at,t.closed_at) >= ? AND COALESCE(t.resolved_at,t.closed_at) < ?', [$s, $e]);
                return $src === 'ins_fcr_pct' ? $r['fcr_pct'] : $r['reopen_pct'];
            }
            if ($src === 'ins_change_success_pct') return opsh_ins_change_success($db, $s, $e);
            if ($src === 'ins_csat_pct') {
                if (!function_exists('opsh_csat_stats')) require_once __DIR__ . '/ServiceMgmt.php';
                if (!opsh_svc_has($db)) return null;
                $c = opsh_csat_stats($db, substr($s, 0, 10), date('Y-m-d', strtotime($e) - 1));
                return !empty($c['rated']) ? (float)$c['pct'] : null;
            }
            if ($src === 'ins_cases_ontime_pct') return opsh_ins_cases_ontime($db, $s, $e)['pct'];
            if ($src === 'ins_improvements_done') { require_once __DIR__ . '/Improvements.php'; $st = opsh_imp_stats($db, $s, $e); return $st === null ? null : (float)$st['done']; }
            if ($src === 'ins_overdue_tasks') return strtotime($e) < time() - 3 * 86400 ? null : (float)opsh_ins_overdue_tasks($db);   // a count of now: weeks that ended days ago are not filled with today's number
        } catch (Throwable $ex) { return null; }
        return null;
    }

    /* ── Cases & Projects report ──────────────────────────────────────────────────────────── */
    /**
     * $all: the person sees every case (else only the cases they own, sponsor, watch or have a task in, and only their
     * own workload). $from / $to (Y-m-d) bound "finished" and the time per template.
     */
    function opsh_ins_cases_report(PDO $db, array $user, bool $all, string $from, string $to): array {
        require_once __DIR__ . '/Cases.php';
        $uid = (int)$user['id']; $today = date('Y-m-d');
        $vis = ''; $va = []; $has6 = opsh_cs_has6($db);
        if (!$all) {
            $vis = ' AND (c.owner_id=? OR ' . ($has6 ? 'c.sponsor_id=?' : '0=?') . ' OR EXISTS (SELECT 1 FROM opsh_case_watchers w WHERE w.case_id=c.id AND w.user_id=?) OR EXISTS (SELECT 1 FROM opsh_case_tasks t2 WHERE t2.case_id=c.id AND t2.assignee_id=? AND t2.kind<>\'requester\'))';
            $va = [$uid, $uid, $uid, $uid];
        }
        // open cases and projects, each with its health
        $q = $db->prepare("SELECT c.*, o.display_name AS owner_name FROM opsh_cases c LEFT JOIN opsh_users o ON o.id=c.owner_id WHERE c.status IN ('open','in_progress')$vis ORDER BY c.target_date IS NULL, c.target_date, c.id LIMIT 300");
        $q->execute($va); $open = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $tasks = opsh_cs_tasks($db, (int)$c['id']); $phases = $has6 ? opsh_cs_phases($db, (int)$c['id'], $tasks) : [];
            $live = array_filter($tasks, fn($t) => $t['status'] !== 'cancelled');
            $late = array_filter($tasks, fn($t) => in_array($t['status'], ['open', 'in_progress', 'waiting'], true) && !empty($t['due_date']) && $t['due_date'] < $today);
            $tk = (int)$db->query('SELECT COUNT(*) FROM opsh_tickets WHERE case_id=' . (int)$c['id'])->fetchColumn();
            $open[] = ['id' => (int)$c['id'], 'case_number' => $c['case_number'], 'title' => $c['title'], 'kind' => $c['kind'] ?? 'case', 'origin' => $c['origin'] ?? null,
                       'owner' => $c['owner_name'], 'target_date' => $c['target_date'], 'health' => opsh_cs_health($c, $tasks, $phases),
                       'tasks_total' => count($live), 'tasks_done' => count(array_filter($live, fn($t) => $t['status'] === 'done')), 'tasks_late' => count($late), 'tickets' => $tk];
        }
        $atRisk = count(array_filter($open, fn($c) => $c['health'] === 'at_risk'));
        // finished in the period
        $q = $db->prepare("SELECT COUNT(*) n, SUM(c.target_date IS NOT NULL) with_target, SUM(c.target_date IS NOT NULL AND DATE(c.resolved_at) <= c.target_date) ok FROM opsh_cases c WHERE c.status IN ('resolved','closed') AND c.resolved_at BETWEEN ? AND ?$vis");
        $q->execute(array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $va)); $fin = $q->fetch(PDO::FETCH_ASSOC);
        $finished = ['count' => (int)$fin['n'], 'with_target' => (int)$fin['with_target'], 'on_time' => (int)$fin['ok'], 'pct' => (int)$fin['with_target'] ? round(100 * (int)$fin['ok'] / (int)$fin['with_target']) : null];
        // overdue tasks by person
        $q = $db->prepare("SELECT t.assignee_id, u.display_name AS name, COUNT(*) n, MIN(t.due_date) oldest FROM opsh_case_tasks t JOIN opsh_cases c ON c.id=t.case_id LEFT JOIN opsh_users u ON u.id=t.assignee_id
                           WHERE c.status IN ('open','in_progress') AND t.status IN ('open','in_progress','waiting') AND t.due_date < CURDATE() AND t.kind<>'requester'$vis" . ($all ? '' : ' AND t.assignee_id=?') . " GROUP BY t.assignee_id, u.display_name ORDER BY n DESC, oldest LIMIT 30");
        $q->execute($all ? $va : array_merge($va, [$uid]));
        $overdue = array_map(fn($r) => ['user_id' => $r['assignee_id'] !== null ? (int)$r['assignee_id'] : null, 'name' => $r['name'] ?: 'Nobody', 'count' => (int)$r['n'], 'oldest' => $r['oldest']], $q->fetchAll(PDO::FETCH_ASSOC));
        // workload: open tasks and open tickets per person, and what is due in the next 7 days
        $wk = [];
        $add = function ($id, $name, $k, $n) use (&$wk) { if (!$id) return; $id = (int)$id; if (!isset($wk[$id])) $wk[$id] = ['user_id' => $id, 'name' => $name, 'tasks' => 0, 'tickets' => 0, 'due_week' => 0, 'late' => 0]; $wk[$id][$k] += (int)$n; };
        $wu = $all ? '' : ' AND t.assignee_id=' . $uid;
        foreach ($db->query("SELECT t.assignee_id, u.display_name AS name, COUNT(*) n, SUM(t.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) wk, SUM(t.due_date < CURDATE()) late FROM opsh_case_tasks t JOIN opsh_cases c ON c.id=t.case_id JOIN opsh_users u ON u.id=t.assignee_id
                             WHERE c.status IN ('open','in_progress') AND t.status IN ('open','in_progress','waiting') AND t.kind<>'requester'$wu GROUP BY t.assignee_id, u.display_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($r['assignee_id'], $r['name'], 'tasks', $r['n']); $add($r['assignee_id'], $r['name'], 'due_week', $r['wk']); $add($r['assignee_id'], $r['name'], 'late', $r['late']);
        }
        foreach ($db->query("SELECT t.assignee_id, u.display_name AS name, COUNT(*) n, SUM(t.due_date IS NOT NULL AND t.due_date BETWEEN NOW() AND NOW() + INTERVAL 7 DAY) wk, SUM(t.due_date IS NOT NULL AND t.due_date < NOW()) late FROM opsh_tickets t JOIN opsh_users u ON u.id=t.assignee_id
                             WHERE t.status IN ('open','in_progress','pending')$wu GROUP BY t.assignee_id, u.display_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($r['assignee_id'], $r['name'], 'tickets', $r['n']); $add($r['assignee_id'], $r['name'], 'due_week', $r['wk']); $add($r['assignee_id'], $r['name'], 'late', $r['late']);
        }
        $workload = array_values($wk); usort($workload, fn($a, $b) => ($b['tasks'] + $b['tickets']) <=> ($a['tasks'] + $a['tickets']) ?: strcmp($a['name'], $b['name']));
        // the cases holding the most tickets (the incidents and requests that touched the most people)
        $q = $db->prepare("SELECT c.id, c.case_number, c.title, c.kind, c.origin, c.status, COUNT(k.id) n, COUNT(DISTINCT k.requester_id) people FROM opsh_cases c JOIN opsh_tickets k ON k.case_id=c.id
                           WHERE (c.status IN ('open','in_progress') OR c.resolved_at BETWEEN ? AND ?)$vis GROUP BY c.id, c.case_number, c.title, c.kind, c.origin, c.status ORDER BY n DESC, c.id DESC LIMIT 10");
        $q->execute(array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $va));
        $perCase = array_map(fn($r) => ['id' => (int)$r['id'], 'case_number' => $r['case_number'], 'title' => $r['title'], 'kind' => $r['kind'], 'origin' => $r['origin'], 'status' => $r['status'], 'tickets' => (int)$r['n'], 'people' => (int)$r['people']], $q->fetchAll(PDO::FETCH_ASSOC));
        // time per template (cases made from a template and finished in the period)
        $byTpl = [];
        if (opsh_ins_has($db)) {
            $w = opsh_cs_workday_cfg($db);
            $q = $db->prepare("SELECT c.template_id, tp.name, c.created_at, c.resolved_at, c.target_date FROM opsh_cases c JOIN opsh_case_templates tp ON tp.id=c.template_id WHERE c.status IN ('resolved','closed') AND c.resolved_at BETWEEN ? AND ?$vis");
            $q->execute(array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $va));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (int)$r['template_id']; if (!isset($byTpl[$k])) $byTpl[$k] = ['template_id' => $k, 'name' => $r['name'], 'count' => 0, 'days' => 0, 'with_target' => 0, 'on_time' => 0];
                $byTpl[$k]['count']++; $byTpl[$k]['days'] += max(0, opsh_cs_bizdiff($w, substr($r['created_at'], 0, 10), substr($r['resolved_at'], 0, 10)));
                if ($r['target_date']) { $byTpl[$k]['with_target']++; if (substr($r['resolved_at'], 0, 10) <= $r['target_date']) $byTpl[$k]['on_time']++; }
            }
            foreach ($byTpl as &$t) { $t['avg_days'] = round($t['days'] / $t['count'], 1); $t['pct'] = $t['with_target'] ? round(100 * $t['on_time'] / $t['with_target']) : null; unset($t['days']); }
            unset($t); $byTpl = array_values($byTpl); usort($byTpl, fn($a, $b) => $b['count'] <=> $a['count']);
        }
        return ['all' => $all, 'from' => $from, 'to' => $to, 'open' => $open,
                'summary' => ['open' => count($open), 'projects' => count(array_filter($open, fn($c) => $c['kind'] === 'project')), 'at_risk' => $atRisk, 'overdue_tasks' => array_sum(array_column($overdue, 'count'))],
                'finished' => $finished, 'overdue_by_person' => $overdue, 'workload' => $workload, 'tickets_per_case' => $perCase, 'by_template' => $byTpl];
    }

    /** BT83 schema: the template a case was made from. */
    function opsh_ins_has(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $db->query('SELECT template_id FROM opsh_cases LIMIT 0'); $h = true; } catch (Throwable $e) { $h = false; }
        return $h;
    }
}
