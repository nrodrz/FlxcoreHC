<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT76 (Phase 2 of "Tickets, Cases & Projects — Process"): cases.
 *
 * A case coordinates work that needs several people. It starts from a ticket (the main ticket) and can hold more
 * tickets (one parent per ticket). The work is split into tasks, each with one assignee, a due date, a status, an
 * optional predecessor (dependency); a task can be an approval (approved / rejected) or a task for the requester.
 * The owner runs it; watchers are informed. The requester sees a plain view: status, milestones (tasks shown to the
 * requester under a public name), the tasks for them and the updates shared with them.
 *
 * Who may do what:
 *  - create a case / own one: role permission cases:create, or named under Settings → General → "Cases & Projects";
 *  - see every case: cases:all or named; with create too, manage every case (a lead);
 *  - the owner manages their case; watchers and task assignees see it; an assignee works their own task;
 *  - the requester of a linked ticket sees the requester view.
 * Every change is in the Audit Log (entity "case"); refusals are logged too.
 */
require_once __DIR__ . '/TicketLifecycle.php';
require_once __DIR__ . '/EmailTemplates.php';   // BT77: case emails and the digest
require_once __DIR__ . '/Perms.php';

if (!function_exists('itops_cs_has')) {
    function itops_cs_has(PDO $db): bool {
        static $h = null;
        if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_cases'")->fetchColumn() === 1; }
        catch (Throwable $e) { $h = false; }
        return $h;
    }

    /** People named under Settings → General → Cases & Projects: {create:[ids], all:[ids]}. */
    function itops_cs_people(PDO $db): array {
        $raw = null;
        try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='cases_people'"); $s->execute(); $raw = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) {}
        $out = ['create' => [], 'all' => [], 'templates' => [], 'cab' => []];   // BT78: who manages the case templates; BT82: the Change Advisory Board
        foreach (['create', 'all', 'templates', 'cab'] as $k) $out[$k] = array_values(array_unique(array_filter(array_map('intval', is_array($raw[$k] ?? null) ? $raw[$k] : []))));
        return $out;
    }
    function itops_cs_can(PDO $db, array $user, string $what): bool {
        if (itops_can($user, 'cases', $what)) return true;
        return in_array((int)($user['id'] ?? 0), itops_cs_people($db)[$what] ?? [], true);
    }

    function itops_cs_get(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT c.*, o.display_name AS owner_name, o.status AS owner_status FROM itops_cases c LEFT JOIN itops_users o ON o.id=c.owner_id WHERE c.id=?');
        $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * This person's place in the case: owner | lead (manages every case) | member (watcher, task assignee, or sees
     * every case) | requester (asked through a linked ticket) | null.
     */
    function itops_cs_role(PDO $db, array $case, array $user): ?string {
        $uid = (int)($user['id'] ?? 0);
        if ((int)$case['owner_id'] === $uid) return 'owner';
        $sponsorMember = !empty($case['sponsor_id']) && (int)$case['sponsor_id'] === $uid;   // BT80: a project's sponsor sees it
        $all = itops_cs_can($db, $user, 'all');
        if ($all && itops_cs_can($db, $user, 'create')) return 'lead';
        if ($all || $sponsorMember) return 'member';
        $w = $db->prepare('SELECT 1 FROM itops_case_watchers WHERE case_id=? AND user_id=?'); $w->execute([(int)$case['id'], $uid]);
        if ($w->fetchColumn()) return 'member';
        $t = $db->prepare("SELECT 1 FROM itops_case_tasks WHERE case_id=? AND assignee_id=? AND kind<>'requester'"); $t->execute([(int)$case['id'], $uid]);
        if ($t->fetchColumn()) return 'member';
        $r = $db->prepare("SELECT 1 FROM itops_tickets WHERE case_id=? AND requester_id=? AND (contact_email IS NULL OR contact_email='') AND contact_name IS NULL"); $r->execute([(int)$case['id'], $uid]);
        if ($r->fetchColumn()) return 'requester';
        if (itops_cs_has3($db)) {   // BT77: invited to one of its meetings
            $a = $db->prepare('SELECT 1 FROM itops_case_tasks t JOIN itops_calendar_attendees a ON a.event_id=t.event_id WHERE t.case_id=? AND a.user_id=?'); $a->execute([(int)$case['id'], $uid]);
            if ($a->fetchColumn()) return 'member';
        }
        return null;
    }
    function itops_cs_manages(?string $role): bool { return $role === 'owner' || $role === 'lead'; }

    function itops_cs_audit(string $action, array $case, $old = null, $new = null): void {
        if (function_exists('audit_log')) { try { audit_log($action, 'case', (int)$case['id'], mb_substr($case['case_number'] . ' · ' . $case['title'], 0, 250), $old, $new); } catch (Throwable $e) {} }
    }
    /** A bell. A requester's bell opens their tickets (they follow the case there), everyone else's opens Cases. */
    function itops_cs_bell(PDO $db, $uid, string $title, string $body, int $caseId, string $link = 'cases'): void {
        if (!$uid) return;
        try { $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([(int)$uid, $title, $body, 'case', $link]); } catch (Throwable $e) {}
    }

    /** CS-YYYY-NNNN, never given twice (the highest issued is remembered). */
    function itops_cs_create_numbered(PDO $db, callable $insert, string $prefix = 'CS') {   // BT80: PR- for projects
        $prefix = $prefix === 'PR' ? 'PR' : 'CS';
        $year = date('Y'); $key = ($prefix === 'PR' ? 'project_seq_' : 'case_seq_') . $year;
        $issued = 0; try { $st = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $st->execute([$key]); $issued = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        $last = 0; try { $last = (int)$db->query("SELECT MAX(CAST(SUBSTRING(case_number,9) AS UNSIGNED)) FROM itops_cases WHERE case_number LIKE '{$prefix}-{$year}-%'")->fetchColumn(); } catch (Throwable $e) {}
        $n = max($issued, $last) + 1;
        for ($try = 0; $try < 12; $try++, $n++) {
            $cn = sprintf('%s-%s-%04d', $prefix, $year, $n);
            try { $out = $insert($cn); }
            catch (Throwable $e) {
                if (!($e instanceof PDOException && (string)$e->getCode() === '23000' && stripos($e->getMessage(), 'case_number') !== false)) throw $e;
                continue;
            }
            try { $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=IF(CAST(sval AS UNSIGNED) < CAST(VALUES(sval) AS UNSIGNED), VALUES(sval), sval)")->execute([$key, (string)$n]); } catch (Throwable $e) {}
            return $out;
        }
        throw new RuntimeException('Could not give the case a number. Try again.');
    }

    function itops_cs_tasks(PDO $db, int $caseId): array {
        $ev = itops_cs_has3($db) ? ', e.online_url AS meeting_url, e.status AS meeting_status' : '';   // BT77: a meeting task's event
        $q = $db->prepare('SELECT t.*, u.display_name AS assignee_name, u.status AS assignee_status' . $ev . ' FROM itops_case_tasks t LEFT JOIN itops_users u ON u.id=t.assignee_id' . ($ev ? ' LEFT JOIN itops_calendar_events e ON e.id=t.event_id' : '') . ' WHERE t.case_id=? ORDER BY t.seq, t.id');
        $q->execute([$caseId]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $by = []; foreach ($rows as $r) $by[(int)$r['id']] = $r;
        $ck = function_exists('itops_cs_has7') && itops_cs_has7($db) && $rows ? itops_cs_checks($db, array_map('intval', array_column($rows, 'id'))) : null;   // BT85: checklists
        foreach ($rows as &$r) { $r['blocked'] = itops_cs_blocked($r, $by); if ($ck !== null) $r['checks'] = $ck[(int)$r['id']] ?? []; }
        return $rows;
    }
    /** A task waits for its predecessor: done (and, for an approval, approved) or cancelled frees it. */
    function itops_cs_blocked(array $t, array $by): bool {
        $d = (int)($t['depends_on'] ?? 0);
        if (!$d || !isset($by[$d])) return false;
        $p = $by[$d];
        if ($p['status'] === 'cancelled') return false;
        if ($p['status'] !== 'done') return true;
        return $p['kind'] === 'approval' && ($p['decision'] ?? '') !== 'approved';
    }
    function itops_cs_tickets(PDO $db, int $caseId): array {
        $q = $db->prepare('SELECT t.id, t.ticket_number, t.subject, t.status, t.requester_id, t.contact_email, t.contact_name, t.case_main, t.case_close_mode, t.assignee_id,
                                  r.display_name AS requester_name FROM itops_tickets t LEFT JOIN itops_users r ON r.id=t.requester_id WHERE t.case_id=? ORDER BY t.case_main DESC, t.id');
        $q->execute([$caseId]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** on_track | at_risk (an open task past its due date, or the target date passed) | done */
    function itops_cs_health(array $case, array $tasks, array $phases = []): string {
        if (in_array($case['status'], ['resolved', 'closed', 'cancelled'], true)) return 'done';
        $today = date('Y-m-d');
        if (!empty($case['target_date']) && $case['target_date'] < $today) return 'at_risk';
        foreach ($tasks as $t) if (in_array($t['status'], ['open', 'in_progress', 'waiting'], true) && !empty($t['due_date']) && $t['due_date'] < $today) return 'at_risk';
        foreach ($phases as $p) if (($p['state'] ?? '') === 'late') return 'at_risk';   // BT80: a phase that missed its milestone
        return 'on_track';
    }

    /** The requester's view: no internal tasks, names, notes or other tickets. */
    function itops_cs_public_view(PDO $db, array $case, array $user): array {
        $tasks = itops_cs_tasks($db, (int)$case['id']);
        $today = date('Y-m-d');
        $miles = []; $mine = []; $meet = []; $late = false;
        foreach ($tasks as $t) {
            if ($t['status'] === 'cancelled') continue;
            if ((int)$t['show_requester'] === 1 && $t['kind'] !== 'requester') {   // their own tasks are listed apart
                $miles[] = ['name' => (string)($t['public_title'] !== null && $t['public_title'] !== '' ? $t['public_title'] : $t['title']), 'done' => $t['status'] === 'done', 'due' => $t['due_date']];
                if ($t['status'] !== 'done' && !empty($t['due_date']) && $t['due_date'] < $today) $late = true;
            }
            if (($t['kind'] ?? '') === 'appointment' && !empty($t['invite_requester']) && !in_array($t['status'], ['done', 'cancelled'], true) && !empty($t['start_at']))
                $meet[] = ['name' => (string)($t['public_title'] !== null && $t['public_title'] !== '' ? $t['public_title'] : $t['title']), 'start' => $t['start_at'], 'minutes' => (int)$t['minutes'], 'join' => (string)($t['meeting_url'] ?? '')];
            if ($t['kind'] === 'requester' && (int)$t['assignee_id'] === (int)$user['id']) $mine[] = ['id' => (int)$t['id'], 'title' => (string)$t['title'], 'done' => $t['status'] === 'done', 'blocked' => (bool)$t['blocked'], 'due' => $t['due_date']];
        }
        $done = in_array($case['status'], ['resolved', 'closed'], true);
        $waitMe = false; foreach ($mine as $m) if (!$m['done'] && !$m['blocked']) $waitMe = true;
        $label = $done ? 'Done' : ($case['status'] === 'cancelled' ? 'Cancelled' : ($waitMe ? 'Waiting on you' : (($late || (!empty($case['target_date']) && $case['target_date'] < $today)) ? 'Delayed' : 'On track')));
        $up = $db->prepare('SELECT u.body, u.created_at, w.display_name AS author FROM itops_case_updates u LEFT JOIN itops_users w ON w.id=u.user_id WHERE u.case_id=? AND u.is_public=1 ORDER BY u.id DESC LIMIT 20');
        $up->execute([(int)$case['id']]);
        return ['view' => 'requester', 'id' => (int)$case['id'], 'case_number' => $case['case_number'], 'title' => $case['title'], 'owner_name' => $case['owner_name'],
                'target_date' => $case['target_date'], 'status' => $case['status'], 'status_label' => $label,
                'progress' => ['done' => count(array_filter($miles, fn($m) => $m['done'])), 'total' => count($miles)],
                'milestones' => $miles, 'my_tasks' => $mine, 'meetings' => $meet, 'updates' => $up->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** The resolution due time of tickets that close with the case follows its target date (end of the day). */
    function itops_cs_follow_target(PDO $db, array $case): void {
        if (empty($case['target_date'])) return;
        try { $db->prepare("UPDATE itops_tickets SET due_date=? WHERE case_id=? AND case_close_mode='with' AND status IN ('open','in_progress','pending')")->execute([$case['target_date'] . ' 17:00:00', (int)$case['id']]);
              $db->prepare("DELETE a FROM itops_sla_alerts a JOIN itops_tickets t ON t.id=a.ticket_id WHERE t.case_id=? AND t.case_close_mode='with'")->execute([(int)$case['id']]); } catch (Throwable $e) {}
    }

    /** Free the tasks that waited for $taskId, and tell their assignees. */
    function itops_cs_unblock(PDO $db, array $case, int $taskId): void {
        $tasks = itops_cs_tasks($db, (int)$case['id']);
        foreach ($tasks as $t) {
            if ((int)$t['depends_on'] !== $taskId || $t['blocked'] || !in_array($t['status'], ['open', 'waiting'], true) || !$t['assignee_id']) continue;
            itops_cs_bell($db, $t['assignee_id'], $t['kind'] === 'approval' ? 'Approval needed' : 'Your task can start', $case['case_number'] . ': ' . $t['title'], (int)$case['id'], $t['kind'] === 'requester' ? 'tickets' : 'cases');
            // BT77: and by email (an approval: "approval waiting for you")
            if (function_exists('itops_cs_mail')) {
                if (!function_exists('itops_email')) require_once __DIR__ . '/EmailTemplates.php';
                $done = ''; foreach ($tasks as $x) if ((int)$x['id'] === $taskId) $done = $x['title'];
                $v = ['case_number' => $case['case_number'], 'case_title' => $case['title'], 'task' => $t['title'], 'due' => itops_cs_when($t), 'done_task' => $done,
                      'owner_name' => (string)($case['owner_name'] ?? ''), 'link' => itops_cs_url('cases/' . $case['id'])];
                itops_cs_mail($db, $t['assignee_id'], $t['kind'] === 'approval' ? 'case_approval_needed' : 'case_task_unblocked', $v, isset($_SERVER['ITOPS_USER_ID']) ? (int)$_SERVER['ITOPS_USER_ID'] : null);
            }
        }
    }

    /** A resolved case whose tickets are all closed is closed. Returns how many closed. */
    function itops_cs_close_run(PDO $db): int {
        if (!itops_cs_has($db)) return 0;
        $n = 0;
        foreach ($db->query("SELECT * FROM itops_cases WHERE status='resolved'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $open = $db->prepare("SELECT COUNT(*) FROM itops_tickets WHERE case_id=? AND status NOT IN ('closed','cancelled')"); $open->execute([(int)$c['id']]);
            if ((int)$open->fetchColumn() > 0) continue;
            $u = $db->prepare("UPDATE itops_cases SET status='closed', closed_at=NOW() WHERE id=? AND status='resolved'"); $u->execute([(int)$c['id']]);
            if ($u->rowCount() !== 1) continue;
            $n++;
            $pn = $_SERVER['ITOPS_USER_NAME'] ?? null; $_SERVER['ITOPS_USER_NAME'] = 'System (auto-close)';
            itops_cs_audit('case_closed', $c, ['status' => 'resolved'], ['status' => 'closed', 'rule' => 'Every ticket of the case is closed']);
            if ($pn === null) unset($_SERVER['ITOPS_USER_NAME']); else $_SERVER['ITOPS_USER_NAME'] = $pn;
        }
        return $n;
    }

    /** A ticket of a resolved case was reopened by its requester: the case reopens too. */
    function itops_cs_on_ticket_reopened(PDO $db, int $ticketId): void {
        if (!itops_cs_has($db)) return;
        $q = $db->prepare('SELECT c.* FROM itops_cases c JOIN itops_tickets t ON t.case_id=c.id WHERE t.id=?'); $q->execute([$ticketId]); $c = $q->fetch(PDO::FETCH_ASSOC);
        if (!$c || $c['status'] !== 'resolved') return;
        $db->prepare("UPDATE itops_cases SET status='in_progress', resolved_at=NULL WHERE id=?")->execute([(int)$c['id']]);
        $tn = $db->prepare('SELECT ticket_number FROM itops_tickets WHERE id=?'); $tn->execute([$ticketId]);
        itops_cs_audit('case_reopened', $c, ['status' => 'resolved'], ['status' => 'in_progress', 'reason' => 'The requester replied on ' . $tn->fetchColumn()]);
        itops_cs_bell($db, $c['owner_id'], 'Case reopened', $c['case_number'] . ': a requester replied after it was resolved', (int)$c['id']);
    }
}

/* ── BT77 · Phase 3: the calendar and messages ─────────────────────────────────────────────────────────────────────────
 * Task dates show on the ITOps calendar of the task's person, the case owner and the watchers (they stay in ITOps; only
 * meetings go to Outlook). A meeting task (kind "appointment") is an ITOps calendar event organized by the task's person,
 * with the people chosen and, when asked, the requester: the Outlook sync (BT66) makes it an Outlook meeting with a Teams
 * link; when the meeting is moved (in Outlook or in the calendar) the task follows; cancelled or removed, the task waits.
 * Emails at once only when a task is given to someone, when a task can start, and when an approval waits; everything else
 * is in the daily case digest (business mornings, nothing sent to someone with nothing to report). */
if (!function_exists('itops_cs_appt_sync')) {
    function itops_cs_has3(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_tasks' AND COLUMN_NAME='start_at'")->fetchColumn() === 1; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    function itops_cs_url(string $hash): string { return defined('APP_URL') && APP_URL !== '' && strpos((string)APP_URL, '%%') === false ? rtrim((string)APP_URL, '/') . '/#' . $hash : ''; }
    function itops_cs_when(array $t): array {   // the task's date in both languages
        if (($t['kind'] ?? '') === 'appointment' && !empty($t['start_at'])) return itops_tpl_d2_time((string)$t['start_at']);
        if (!empty($t['due_date'])) return itops_tpl_d2((string)$t['due_date']);
        return ['en' => 'no date set', 'es' => 'sin fecha'];
    }
    if (!function_exists('itops_tpl_d2_time')) {
        function itops_tpl_d2_time(string $dt): array { $ts = strtotime($dt); return ['en' => date('D, m/d/Y g:i A', $ts), 'es' => itops_tpl_date(substr($dt, 0, 10), 'es') . ' ' . date('g:i A', $ts)]; }
    }

    /** An email to a person of the app (active, with an address). Never to the person who made the change. */
    function itops_cs_mail(PDO $db, $uid, string $key, array $vars, ?int $byId = null): bool {
        $uid = (int)$uid; if (!$uid || ($byId !== null && $uid === $byId)) return false;
        if (!function_exists('itops_email')) require_once __DIR__ . '/EmailTemplates.php';
        $q = $db->prepare("SELECT email, display_name FROM itops_users WHERE id=? AND status='active'"); $q->execute([$uid]); $u = $q->fetch(PDO::FETCH_ASSOC);
        if (!$u || !filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) return false;
        try { $r = itops_email($key, (string)$u['email'], $vars + ['recipient_name' => (string)$u['display_name']], ['user_id' => $uid]); return !empty($r['ok']); } catch (Throwable $e) { return false; }
    }
    /** A task was given to someone (added, or a new person): the right email. */
    function itops_cs_notify_task(PDO $db, array $case, int $taskId, ?int $byId, string $byName): void {
        $tasks = itops_cs_tasks($db, (int)$case['id']); $t = null; $by = [];
        foreach ($tasks as $x) { $by[(int)$x['id']] = $x; if ((int)$x['id'] === $taskId) $t = $x; }
        if (!$t || !$t['assignee_id'] || in_array($t['status'], ['done', 'cancelled'], true)) return;
        $v = ['case_number' => $case['case_number'], 'case_title' => $case['title'], 'task' => $t['title'], 'due' => itops_cs_when($t), 'assigned_by' => $byName,
              'owner_name' => (string)($case['owner_name'] ?? ''), 'link' => itops_cs_url('cases/' . $case['id'])];
        if ($t['kind'] === 'requester') {   // their step opens on their own ticket
            $mt = $db->prepare('SELECT id FROM itops_tickets WHERE case_id=? AND case_main=1'); $mt->execute([(int)$case['id']]); $tid = (int)$mt->fetchColumn();
            if ($tid) $v['link'] = itops_cs_url('tickets/' . $tid);
        }
        if ($t['kind'] === 'approval' && !$t['blocked']) { itops_cs_mail($db, $t['assignee_id'], 'case_approval_needed', $v, $byId); return; }
        $after = ['en' => '', 'es' => ''];
        if ($t['blocked'] && isset($by[(int)$t['depends_on']])) { $p = $by[(int)$t['depends_on']]['title'];
            $after = ['en' => 'It starts after "' . $p . '"; you will be told when it can start.', 'es' => 'Empieza después de "' . $p . '"; se le avisará cuando pueda empezar.']; }
        if ($t['kind'] === 'appointment') $after = ['en' => trim($after['en'] . ' It is a meeting: the invitation comes from Outlook when the organizer\'s Outlook sync is on.'), 'es' => trim($after['es'] . ' Es una reunión: la invitación llega por Outlook cuando la sincronización de Outlook del organizador está activa.')];
        itops_cs_mail($db, $t['assignee_id'], 'case_task_assigned', $v + ['after' => $after], $byId);
    }

    // ── meetings ──
    /** The people of a meeting task (the organizer is the task's person): the chosen people and, when asked, the requester. */
    function itops_cs_appt_people(PDO $db, array $case, array $t): array {
        $out = []; $seen = [(int)$t['assignee_id'] => 1];
        foreach ((array)json_decode((string)($t['attendees'] ?? ''), true) as $u) { $u = (int)$u; if (!$u || isset($seen[$u])) continue; $seen[$u] = 1; $out[] = ['user_id' => $u, 'email' => '', 'name' => '']; }
        if (!empty($t['invite_requester'])) {
            $mt = $db->prepare('SELECT requester_id, contact_email, contact_name FROM itops_tickets WHERE case_id=? AND case_main=1'); $mt->execute([(int)$case['id']]); $m = $mt->fetch(PDO::FETCH_ASSOC);
            if ($m) {
                $ext = !empty($m['contact_email']) || $m['contact_name'] !== null;
                if (!$ext && !isset($seen[(int)$m['requester_id']])) $out[] = ['user_id' => (int)$m['requester_id'], 'email' => '', 'name' => ''];
                elseif ($ext && filter_var((string)$m['contact_email'], FILTER_VALIDATE_EMAIL)) $out[] = ['user_id' => null, 'email' => mb_strtolower((string)$m['contact_email']), 'name' => (string)$m['contact_name']];
            }
        }
        return $out;
    }
    /** Make or update the calendar event of a meeting task (cancelled with the task or the case). Returns the event id. */
    function itops_cs_appt_sync(PDO $db, array $case, int $taskId, ?int $byId = null): ?int {
        if (!itops_cs_has3($db)) return null;
        require_once __DIR__ . '/Calendar.php';
        $q = $db->prepare('SELECT * FROM itops_case_tasks WHERE id=?'); $q->execute([$taskId]); $t = $q->fetch(PDO::FETCH_ASSOC);
        if (!$t || $t['kind'] !== 'appointment' || empty($t['start_at']) || !$t['assignee_id']) return null;
        $mins = max(15, min(480, (int)($t['minutes'] ?: 60)));
        $cancel = $t['status'] === 'cancelled' || $case['status'] === 'cancelled';
        $types = itops_cal_types($db); $mt = itops_cal_type($types, 'meeting'); $rem = $mt ? (array)($mt['reminders'] ?? []) : [15];
        $desc = 'Case ' . $case['case_number'] . ': ' . $case['title'] . "\n" . ($t['public_title'] ? 'Shown to the requester as: ' . $t['public_title'] . "\n" : '') . itops_cs_url('cases/' . $case['id']);
        $want = ['title' => mb_substr($t['title'] . ' · ' . $case['case_number'], 0, 255), 'description' => $desc, 'event_type' => 'meeting', 'start_date' => $t['start_at'],
                 'end_date' => date('Y-m-d H:i:s', strtotime($t['start_at']) + $mins * 60), 'all_day' => 0, 'owner_id' => (int)$t['assignee_id'], 'status' => $cancel ? 'cancelled' : 'scheduled',
                 'entity_type' => 'case', 'entity_id' => (int)$case['id']];
        $people = itops_cs_appt_people($db, $case, $t);
        $eid = (int)($t['event_id'] ?? 0); $cur = $eid ? itops_cal_get($db, $eid) : null;
        $label = fn(array $e) => ['Title' => $e['title'], 'Starts' => substr((string)$e['start_date'], 0, 16), 'Ends' => substr((string)$e['end_date'], 0, 16), 'Status' => $e['status'],
                                  'Organizer' => (string)$db->query('SELECT display_name FROM itops_users WHERE id=' . (int)$e['owner_id'])->fetchColumn()];
        if (!$cur) {
            if ($cancel) return null;
            $db->prepare('INSERT INTO itops_calendar_events (title, description, event_type, start_date, end_date, all_day, location, online_url, visibility, status, reminders, owner_id, entity_type, entity_id, created_by, updated_by)
                           VALUES (?,?,?,?,?,0,\'\',\'\',\'team\',?,?,?,?,?,?,?)')
               ->execute([$want['title'], $want['description'], 'meeting', $want['start_date'], $want['end_date'], $want['status'], $rem ? json_encode(array_values($rem)) : null, $want['owner_id'], 'case', (int)$case['id'], $byId, $byId]);
            $eid = (int)$db->lastInsertId();
            $db->prepare('UPDATE itops_case_tasks SET event_id=? WHERE id=?')->execute([$eid, $taskId]);
            $ins = $db->prepare('INSERT INTO itops_calendar_attendees (event_id, user_id, email, name, response) VALUES (?,?,?,?,\'none\')');
            foreach ($people as $a) $ins->execute([$eid, $a['user_id'], $a['email'], $a['name']]);
            if (function_exists('audit_log')) { try { audit_log('calendar_event_add', 'calendar_event', $eid, $want['title'], null, $label($want) + ['Made by' => 'case ' . $case['case_number'] . ' (meeting task)', 'Attendees' => (string)count($people)]); } catch (Throwable $e) {} }
            foreach ($people as $a) if ($a['user_id'] && $a['user_id'] !== $byId) itops_cs_bell($db, $a['user_id'], 'Invited: ' . $t['title'], $case['case_number'] . ' · ' . date('m/d/Y g:i A', strtotime($t['start_at'])), (int)$case['id'], 'calendar');
            return $eid;
        }
        $old = ['title' => $cur['title'], 'description' => $cur['description'], 'start_date' => $cur['start'], 'end_date' => (string)$cur['end'], 'owner_id' => (int)$cur['owner_id'], 'status' => $cur['status']];
        $chg = []; foreach (['title', 'description', 'start_date', 'end_date', 'owner_id', 'status'] as $k) if ((string)$old[$k] !== (string)$want[$k]) $chg[$k] = $want[$k];
        if ($cur['status'] === 'done') unset($chg['status']);
        if ($chg) {
            $db->prepare('UPDATE itops_calendar_events SET ' . implode(', ', array_map(fn($k) => "$k=?", array_keys($chg))) . ', updated_by=? WHERE id=?')->execute(array_merge(array_values($chg), [$byId, $eid]));
            if (function_exists('audit_log')) { try { $o = $label($old + ['start_date' => $cur['start']]); $n = $label(array_merge($old, $chg));
                audit_log('calendar_event_save', 'calendar_event', $eid, $want['title'], array_diff_assoc($o, $n), array_diff_assoc($n, $o) + ['Changed by' => 'case ' . $case['case_number'] . ' (meeting task)']); } catch (Throwable $e) {} }
        }
        // the people: add the new ones (their answers kept), remove the ones taken off
        $have = []; foreach ($cur['attendees'] as $a) $have[$a['user_id'] ? 'u' . $a['user_id'] : 'e' . strtolower($a['email'])] = 1;
        $wantK = []; foreach ($people as $a) $wantK[$a['user_id'] ? 'u' . $a['user_id'] : 'e' . $a['email']] = $a;
        foreach ($wantK as $k => $a) if (!isset($have[$k])) {
            $db->prepare('INSERT INTO itops_calendar_attendees (event_id, user_id, email, name, response) VALUES (?,?,?,?,\'none\')')->execute([$eid, $a['user_id'], $a['email'], $a['name']]);
            if ($a['user_id'] && $a['user_id'] !== $byId && !$cancel) itops_cs_bell($db, $a['user_id'], 'Invited: ' . $t['title'], $case['case_number'] . ' · ' . date('m/d/Y g:i A', strtotime($t['start_at'])), (int)$case['id'], 'calendar');
        }
        foreach ($cur['attendees'] as $a) { $k = $a['user_id'] ? 'u' . $a['user_id'] : 'e' . strtolower($a['email']);
            if (!isset($wantK[$k])) { if ($a['user_id']) $db->prepare('DELETE FROM itops_calendar_attendees WHERE event_id=? AND user_id=?')->execute([$eid, $a['user_id']]); else $db->prepare('DELETE FROM itops_calendar_attendees WHERE event_id=? AND user_id IS NULL AND LOWER(email)=?')->execute([$eid, strtolower($a['email'])]); } }
        return $eid;
    }
    /**
     * The calendar event of a meeting task changed outside the case (moved in Outlook or in the calendar, cancelled,
     * removed): the task follows. $where = "Outlook" or "the calendar".
     */
    function itops_cs_event_changed(PDO $db, int $eid, string $where, ?int $byId = null, string $byName = ''): void {
        if (!itops_cs_has($db) || !itops_cs_has3($db)) return;
        require_once __DIR__ . '/Calendar.php';
        $q = $db->prepare("SELECT * FROM itops_case_tasks WHERE event_id=? AND kind='appointment'"); $q->execute([$eid]); $t = $q->fetch(PDO::FETCH_ASSOC); if (!$t) return;
        $case = itops_cs_get($db, (int)$t['case_id']); if (!$case) return;
        $e = itops_cal_get($db, $eid);
        $live = !in_array($t['status'], ['done', 'cancelled'], true) && in_array($case['status'], ['open', 'in_progress'], true);
        $who = $byName !== '' ? $byName . ' (in ' . $where . ')' : 'Calendar (' . $where . ')';
        $as = function (callable $f) use ($who, $byId) { $pn = $_SERVER['ITOPS_USER_NAME'] ?? null; $pi = $_SERVER['ITOPS_USER_ID'] ?? null; $_SERVER['ITOPS_USER_NAME'] = $who; if ($byId) $_SERVER['ITOPS_USER_ID'] = $byId;
            try { $f(); } finally { if ($pn === null) unset($_SERVER['ITOPS_USER_NAME']); else $_SERVER['ITOPS_USER_NAME'] = $pn; if ($pi === null) unset($_SERVER['ITOPS_USER_ID']); else $_SERVER['ITOPS_USER_ID'] = $pi; } };
        $tell = function (string $title) use ($db, $case, $t, $byId) { foreach (array_unique([(int)$case['owner_id'], (int)$t['assignee_id']]) as $u) if ($u && $u !== (int)$byId) itops_cs_bell($db, $u, $title, $case['case_number'] . ': ' . $t['title'], (int)$case['id']); };
        if (!$e || $e['status'] === 'cancelled') {
            if (!$e) $db->prepare('UPDATE itops_case_tasks SET event_id=NULL WHERE id=?')->execute([(int)$t['id']]);
            if (!$live || $t['status'] === 'waiting') return;
            $why = !$e ? 'The meeting was removed from the calendar: set a new time' : 'The meeting was cancelled in ' . $where . ': set a new time';
            $db->prepare("UPDATE itops_case_tasks SET status='waiting', reason=? WHERE id=?")->execute([mb_substr($why, 0, 255), (int)$t['id']]);
            $as(fn() => itops_cs_audit('case_task_updated', $case, ['task' => $t['title'], 'status' => $t['status']], ['task' => $t['title'], 'status' => 'waiting', 'reason' => $why]));
            $tell('Meeting cancelled');
            return;
        }
        $mins = $e['end'] ? max(15, (int)round((strtotime($e['end']) - strtotime($e['start'])) / 60)) : (int)$t['minutes'];
        if ($e['start'] === (string)$t['start_at'] && $mins === (int)$t['minutes']) return;
        $db->prepare('UPDATE itops_case_tasks SET start_at=?, minutes=?, due_date=? WHERE id=?')->execute([$e['start'], min(480, $mins), substr($e['start'], 0, 10), (int)$t['id']]);
        $as(fn() => itops_cs_audit('case_task_updated', $case, ['task' => $t['title'], 'start' => substr((string)$t['start_at'], 0, 16), 'minutes' => (int)$t['minutes']],
                                                        ['task' => $t['title'], 'start' => substr($e['start'], 0, 16), 'minutes' => $mins, 'moved_in' => $where]));
        if ($live) $tell('Meeting moved');
    }
    /** The scheduled run: any meeting task whose event moved, was cancelled or removed without the hooks seeing it. */
    function itops_cs_events_reconcile(PDO $db): int {
        if (!itops_cs_has($db) || !itops_cs_has3($db)) return 0;
        $n = 0;
        $rows = $db->query("SELECT t.event_id FROM itops_case_tasks t JOIN itops_cases c ON c.id=t.case_id LEFT JOIN itops_calendar_events e ON e.id=t.event_id
                            WHERE t.kind='appointment' AND t.event_id IS NOT NULL AND t.status NOT IN ('done','cancelled') AND c.status IN ('open','in_progress')
                              AND (e.id IS NULL OR e.status='cancelled' OR e.start_date<>t.start_at OR TIMESTAMPDIFF(MINUTE, e.start_date, e.end_date)<>t.minutes)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $eid) { itops_cs_event_changed($db, (int)$eid, 'the calendar'); $n++; }
        return $n;
    }

    // ── the ITOps calendar ──
    /** Task dates and target dates of the cases this person works on (all cases when they see every case and asked for everything). */
    function itops_cs_agenda(PDO $db, array $user, string $from, string $to, bool $mine): array {
        if (!itops_cs_has($db)) return [];
        $uid = (int)$user['id']; $all = !$mine && itops_cs_can($db, $user, 'all'); $out = []; $today = date('Y-m-d');
        $inv = $all ? '1=1' : "(t.assignee_id=$uid OR c.owner_id=$uid OR EXISTS (SELECT 1 FROM itops_case_watchers w WHERE w.case_id=c.id AND w.user_id=$uid))";
        $kind = itops_cs_has3($db) ? " AND t.kind<>'appointment'" : '';
        $s = $db->prepare("SELECT t.id, t.title, t.kind, t.due_date, t.status, t.assignee_id, u.display_name AS who, c.id AS case_id, c.case_number, c.title AS case_title, c.owner_id
                           FROM itops_case_tasks t JOIN itops_cases c ON c.id=t.case_id LEFT JOIN itops_users u ON u.id=t.assignee_id
                           WHERE t.due_date BETWEEN ? AND ? AND t.status IN ('open','in_progress','waiting') AND c.status IN ('open','in_progress')$kind AND $inv");
        $s->execute([$from, $to]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lbl = $r['kind'] === 'approval' ? 'approval' : ($r['kind'] === 'requester' ? 'requester step' : 'task');
            $out[] = ['date' => $r['due_date'], 'datetime' => $r['due_date'] . ' 00:00:00', 'type' => 'case_task', 'title' => $r['case_number'] . ' · ' . $lbl . ' due', 'subtitle' => $r['title'] . ($r['who'] ? ' · ' . ($r['kind'] === 'requester' ? 'the requester' : $r['who']) : ''),
                      'entity' => 'case', 'entity_id' => (int)$r['case_id'], 'page' => 'cases', 'color' => '#B5654A', 'overdue' => $r['due_date'] < $today, 'can_edit' => false, 'all_day' => true, 'mine' => (int)$r['assignee_id'] === $uid];
        }
        $inv2 = $all ? '1=1' : "(c.owner_id=$uid" . (function_exists('itops_cs_has6') && itops_cs_has6($db) ? " OR c.sponsor_id=$uid" : '') . " OR EXISTS (SELECT 1 FROM itops_case_watchers w WHERE w.case_id=c.id AND w.user_id=$uid))";
        $s = $db->prepare("SELECT c.id, c.case_number, c.title, c.target_date FROM itops_cases c WHERE c.target_date BETWEEN ? AND ? AND c.status IN ('open','in_progress') AND $inv2");
        $s->execute([$from, $to]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
            $out[] = ['date' => $r['target_date'], 'datetime' => $r['target_date'] . ' 00:00:00', 'type' => 'case_target', 'title' => $r['case_number'] . ' · target date', 'subtitle' => $r['title'],
                      'entity' => 'case', 'entity_id' => (int)$r['id'], 'page' => 'cases', 'color' => '#7D4E9B', 'overdue' => $r['target_date'] < $today, 'can_edit' => false, 'all_day' => true];
        if (function_exists('itops_cs_has6') && itops_cs_has6($db)) {   // BT80: project milestones (the end of each phase), for the team and the sponsor
            $inv3 = $all ? '1=1' : "(c.owner_id=$uid OR c.sponsor_id=$uid OR EXISTS (SELECT 1 FROM itops_case_watchers w WHERE w.case_id=c.id AND w.user_id=$uid) OR EXISTS (SELECT 1 FROM itops_case_tasks t WHERE t.case_id=c.id AND t.phase_id=p.id AND t.assignee_id=$uid AND t.kind<>'requester'))";   // BT92: not for a requester's step
            $s = $db->prepare("SELECT p.name, p.milestone_date, c.id, c.case_number, c.title FROM itops_case_phases p JOIN itops_cases c ON c.id=p.case_id WHERE p.milestone_date BETWEEN ? AND ? AND c.status IN ('open','in_progress') AND $inv3");
            $s->execute([$from, $to]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
                $out[] = ['date' => $r['milestone_date'], 'datetime' => $r['milestone_date'] . ' 00:00:00', 'type' => 'case_milestone', 'title' => $r['case_number'] . ' · milestone: ' . $r['name'], 'subtitle' => $r['title'],
                          'entity' => 'case', 'entity_id' => (int)$r['id'], 'page' => 'cases', 'color' => '#7D4E9B', 'overdue' => $r['milestone_date'] < $today, 'can_edit' => false, 'all_day' => true];
        }
        if (!function_exists('itops_svc_has2') && is_file(__DIR__ . '/ServiceMgmt.php')) require_once __DIR__ . '/ServiceMgmt.php';
        if (function_exists('itops_svc_has2') && itops_svc_has2($db)) {   // BT82: change windows (the team, the approvers) and change freezes
            $inv4 = $all ? '1=1' : "(c.owner_id=$uid OR EXISTS (SELECT 1 FROM itops_case_watchers w WHERE w.case_id=c.id AND w.user_id=$uid) OR EXISTS (SELECT 1 FROM itops_case_tasks t WHERE t.case_id=c.id AND t.assignee_id=$uid AND t.kind<>'requester'))";   // BT92 (code audit Low): requesters do not see change windows
            $s = $db->prepare("SELECT c.id, c.case_number, c.title, i.window_start, i.window_end, i.change_type, i.risk FROM itops_case_svc i JOIN itops_cases c ON c.id=i.case_id
                               WHERE c.origin='change' AND i.window_start IS NOT NULL AND DATE(i.window_start) BETWEEN ? AND ? AND c.status IN ('open','in_progress') AND $inv4");
            $s->execute([$from, $to]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
                $out[] = ['date' => substr($r['window_start'], 0, 10), 'datetime' => $r['window_start'], 'type' => 'case_change', 'title' => $r['case_number'] . ' · change window', 'subtitle' => $r['title'] . ($r['window_end'] ? ' · until ' . date('M j g:i A', strtotime($r['window_end'])) : ''),
                          'entity' => 'case', 'entity_id' => (int)$r['id'], 'page' => 'cases', 'color' => '#C08A30', 'overdue' => false, 'can_edit' => false, 'all_day' => false];
            if (!function_exists('itops_chg_freezes')) require_once __DIR__ . '/ServiceMgmt.php';
            foreach (itops_chg_freezes($db) as $f) {
                if ($f['to'] < $from || $f['from'] > $to) continue;
                for ($d = max($f['from'], $from), $n = 0; $d <= min($f['to'], $to) && $n < 62; $d = date('Y-m-d', strtotime($d . ' +1 day')), $n++)
                    $out[] = ['date' => $d, 'datetime' => $d . ' 00:00:00', 'type' => 'change_freeze', 'title' => 'Change freeze: ' . $f['name'], 'subtitle' => date('m/d/Y', strtotime($f['from'])) . ' to ' . date('m/d/Y', strtotime($f['to'])) . ' · no normal or standard changes',
                              'entity' => 'case', 'entity_id' => 0, 'page' => 'cases', 'color' => '#8A97A6', 'overdue' => false, 'can_edit' => false, 'all_day' => true];
            }
        }
        return $out;
    }

    // ── the daily digest ──
    function itops_cs_digest_cfg(PDO $db): array {
        $d = ['enabled' => true, 'hour' => 7, 'weekdays' => true];
        try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='cases_digest'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        if (is_array($j)) { if (isset($j['enabled'])) $d['enabled'] = (bool)$j['enabled']; if (isset($j['hour']) && (int)$j['hour'] >= 0 && (int)$j['hour'] <= 23) $d['hour'] = (int)$j['hour']; if (isset($j['weekdays'])) $d['weekdays'] = (bool)$j['weekdays']; }
        return $d;
    }
    /** One person's digest: ['count' => tasks waiting on them, 'report' => [en, es]], or null when there is nothing to tell. */
    function itops_cs_digest_for(PDO $db, array $user, ?string $since): ?array {
        $uid = (int)$user['id']; $today = date('Y-m-d'); $week = date('Y-m-d', strtotime('+7 days')); $since = $since ?: date('Y-m-d H:i:s', time() - 86400);
        $L = ['en' => [], 'es' => []]; $add = function (string $en, string $es) use (&$L) { $L['en'][] = $en; $L['es'][] = $es; };
        $d = fn(?string $x, string $l) => $x ? itops_tpl_date($x, $l) : '';
        // 1) waiting on you
        $s = $db->prepare("SELECT t.*, c.case_number, c.title AS case_title FROM itops_case_tasks t JOIN itops_cases c ON c.id=t.case_id
                           WHERE t.assignee_id=? AND t.status IN ('open','in_progress','waiting') AND c.status IN ('open','in_progress')" . (itops_cs_has3($db) ? " AND t.kind<>'appointment'" : '') . " ORDER BY t.due_date IS NULL, t.due_date, t.id");
        $s->execute([$uid]); $mineT = []; $blocked = [];
        $byCase = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (!isset($byCase[$t['case_id']])) { $byCase[$t['case_id']] = []; foreach (itops_cs_tasks($db, (int)$t['case_id']) as $x) $byCase[$t['case_id']][(int)$x['id']] = $x; }
            if (!empty($byCase[$t['case_id']][(int)$t['id']]['blocked'])) { $blocked[] = $t; continue; }
            $mineT[] = $t;
        }
        $line = function (array $t, string $l) use ($d) {
            $what = $t['kind'] === 'approval' ? ($l === 'es' ? 'Aprobar: ' : 'Approve: ') : ($t['kind'] === 'requester' ? ($l === 'es' ? 'Para su solicitud: ' : 'For your request: ') : '');
            $due = $t['due_date'] ? ($l === 'es' ? ', fecha ' : ', due ') . $d($t['due_date'], $l) : '';
            $w = $t['status'] === 'waiting' && $t['reason'] ? ($l === 'es' ? ' — en espera: ' : ' — waiting: ') . $t['reason'] : '';
            return '- ' . $what . $t['title'] . ' (' . $t['case_number'] . $due . ')' . $w;
        };
        $groups = [['late', 'Late', 'Atrasadas', fn($t) => $t['due_date'] && $t['due_date'] < $today], ['today', 'Due today', 'Para hoy', fn($t) => $t['due_date'] === $today],
                   ['soon', 'Due in the next 7 days', 'En los próximos 7 días', fn($t) => $t['due_date'] && $t['due_date'] > $today && $t['due_date'] <= $week],
                   ['later', 'Later or no date', 'Más adelante o sin fecha', fn($t) => !$t['due_date'] || $t['due_date'] > $week]];
        if ($mineT) {
            $add('Waiting on you (' . count($mineT) . '):', 'Pendientes suyas (' . count($mineT) . '):');
            foreach ($groups as [$k, $en, $es, $f]) { $g = array_values(array_filter($mineT, $f)); if (!$g) continue;
                $L['en'][] = $en . ':'; $L['es'][] = $es . ':';
                $L['en'][] = implode("\n", array_map(fn($t) => $line($t, 'en'), $g)); $L['es'][] = implode("\n", array_map(fn($t) => $line($t, 'es'), $g)); }
        }
        if ($blocked) $add(count($blocked) . ' more of your tasks wait for someone else first; you will be told when they can start.', 'Otras ' . count($blocked) . ' tareas suyas esperan primero a otra persona; se le avisará cuando puedan empezar.');
        // 2) today's meetings
        if (itops_cs_has3($db)) {
            $s = $db->prepare("SELECT t.*, c.case_number, e.online_url FROM itops_case_tasks t JOIN itops_cases c ON c.id=t.case_id LEFT JOIN itops_calendar_events e ON e.id=t.event_id
                               WHERE t.kind='appointment' AND t.status IN ('open','in_progress') AND c.status IN ('open','in_progress') AND DATE(t.start_at)=?
                                 AND (t.assignee_id=? OR EXISTS (SELECT 1 FROM itops_calendar_attendees a WHERE a.event_id=t.event_id AND a.user_id=?)) ORDER BY t.start_at");
            $s->execute([$today, $uid, $uid]); $m = $s->fetchAll(PDO::FETCH_ASSOC);
            if ($m) { $add('Meetings today:', 'Reuniones de hoy:');
                $L['en'][] = implode("\n", array_map(fn($t) => '- ' . date('g:i A', strtotime($t['start_at'])) . ' ' . $t['title'] . ' (' . $t['case_number'] . ')' . ($t['online_url'] ? ' — Teams: ' . $t['online_url'] : ''), $m));
                $L['es'][] = implode("\n", array_map(fn($t) => '- ' . date('g:i A', strtotime($t['start_at'])) . ' ' . $t['title'] . ' (' . $t['case_number'] . ')' . ($t['online_url'] ? ' — Teams: ' . $t['online_url'] : ''), $m)); }
        }
        // 3) the cases they own or watch, and the projects they sponsor (BT80)
        $sp6 = function_exists('itops_cs_has6') && itops_cs_has6($db);
        $s = $db->prepare("SELECT c.*, o.display_name AS owner_name FROM itops_cases c LEFT JOIN itops_users o ON o.id=c.owner_id WHERE c.status IN ('open','in_progress')
                           AND (c.owner_id=? OR EXISTS (SELECT 1 FROM itops_case_watchers w WHERE w.case_id=c.id AND w.user_id=?)" . ($sp6 ? ' OR c.sponsor_id=?' : '') . ") ORDER BY c.id");
        $s->execute($sp6 ? [$uid, $uid, $uid] : [$uid, $uid]); $cs = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($cs) {
            $en = []; $es = [];
            foreach ($cs as $c) {
                $tasks = itops_cs_tasks($db, (int)$c['id']); $h = itops_cs_health($c, $tasks, $sp6 && ($c['kind'] ?? '') === 'project' ? itops_cs_phases($db, (int)$c['id'], $tasks) : []);
                $tot = count(array_filter($tasks, fn($t) => $t['status'] !== 'cancelled')); $done = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));
                $late = count(array_filter($tasks, fn($t) => in_array($t['status'], ['open', 'in_progress', 'waiting'], true) && $t['due_date'] && $t['due_date'] < $today));
                $news = count(array_filter($tasks, fn($t) => $t['done_at'] && $t['done_at'] >= $since));
                $u = $db->prepare('SELECT COUNT(*) FROM itops_case_updates WHERE case_id=? AND created_at>=?'); $u->execute([(int)$c['id'], $since]); $nu = (int)$u->fetchColumn();
                $en[] = '- ' . $c['case_number'] . ' ' . $c['title'] . ' — ' . ($h === 'at_risk' ? 'AT RISK' : 'on track') . ', ' . $done . ' of ' . $tot . ' done' . ($late ? ', ' . $late . ' late' : '') . ($news ? ', ' . $news . ' finished since the last digest' : '') . ($nu ? ', ' . $nu . ' new update' . ($nu === 1 ? '' : 's') : '') . ((int)$c['owner_id'] !== $uid ? ' (you watch it)' : '');
                $es[] = '- ' . $c['case_number'] . ' ' . $c['title'] . ' — ' . ($h === 'at_risk' ? 'EN RIESGO' : 'al día') . ', ' . $done . ' de ' . $tot . ' terminadas' . ($late ? ', ' . $late . ' atrasadas' : '') . ($news ? ', ' . $news . ' terminadas desde el último resumen' : '') . ($nu ? ', ' . $nu . ' actualizaciones nuevas' : '') . ((int)$c['owner_id'] !== $uid ? ' (usted lo sigue)' : '');
            }
            $add($sp6 ? 'Your cases and projects:' : 'Your cases:', $sp6 ? 'Sus casos y proyectos:' : 'Sus casos:'); $L['en'][] = implode("\n", $en); $L['es'][] = implode("\n", $es);
        }
        if (!$L['en']) return null;
        return ['count' => count($mineT), 'report' => ['en' => implode("\n\n", $L['en']), 'es' => implode("\n\n", $L['es'])]];
    }
    /**
     * Send the digests that are due (each business morning from the set hour; once a day per person). $force sends now
     * (Settings: "Send me mine now"); $only limits it to one person. Returns [sent, nothing, reason].
     */
    function itops_cs_digest_run(PDO $db, bool $force = false, ?int $only = null): array {
        if (!itops_cs_has($db) || !itops_cs_has3($db)) return ['sent' => 0, 'nothing' => 0, 'reason' => 'Run the database update first.'];
        $cfg = itops_cs_digest_cfg($db);
        if (!$force) {
            if (!$cfg['enabled']) return ['sent' => 0, 'nothing' => 0, 'reason' => 'The daily case digest is off.'];
            if ((int)date('G') < $cfg['hour']) return ['sent' => 0, 'nothing' => 0, 'reason' => 'Before ' . date('g A', mktime($cfg['hour'], 0)) . '.'];
            if ($cfg['weekdays'] && (int)date('N') >= 6) return ['sent' => 0, 'nothing' => 0, 'reason' => 'Not on weekends.'];
        }
        if (!function_exists('itops_email')) require_once __DIR__ . '/EmailTemplates.php';
        $today = date('Y-m-d');
        $ids = $only ? [$only] : $db->query("SELECT DISTINCT u FROM (
              SELECT t.assignee_id AS u FROM itops_case_tasks t JOIN itops_cases c ON c.id=t.case_id WHERE c.status IN ('open','in_progress') AND t.status IN ('open','in_progress','waiting') AND t.assignee_id IS NOT NULL
              UNION SELECT c.owner_id FROM itops_cases c WHERE c.status IN ('open','in_progress') AND c.owner_id IS NOT NULL
              UNION SELECT w.user_id FROM itops_case_watchers w JOIN itops_cases c ON c.id=w.case_id WHERE c.status IN ('open','in_progress')
              " . (function_exists('itops_cs_has6') && itops_cs_has6($db) ? "UNION SELECT c.sponsor_id FROM itops_cases c WHERE c.status IN ('open','in_progress') AND c.sponsor_id IS NOT NULL" : '') . "
              UNION SELECT a.user_id FROM itops_calendar_attendees a JOIN itops_case_tasks t ON t.event_id=a.event_id JOIN itops_cases c ON c.id=t.case_id WHERE c.status IN ('open','in_progress') AND a.user_id IS NOT NULL) x")->fetchAll(PDO::FETCH_COLUMN);
        $sent = 0; $none = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $q = $db->prepare("SELECT id, email, display_name, status FROM itops_users WHERE id=?"); $q->execute([$id]); $u = $q->fetch(PDO::FETCH_ASSOC);
            if (!$u || $u['status'] !== 'active') continue;
            $l = $db->prepare('SELECT last_date, last_at FROM itops_case_digest WHERE user_id=?'); $l->execute([$id]); $last = $l->fetch(PDO::FETCH_ASSOC) ?: ['last_date' => null, 'last_at' => null];
            if (!$force && $last['last_date'] === $today) continue;
            $dg = itops_cs_digest_for($db, $u, $last['last_at']);
            $db->prepare('INSERT INTO itops_case_digest (user_id, last_date, last_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE last_date=VALUES(last_date), last_at=VALUES(last_at)')->execute([$id, $today]);
            if (!$dg) { $none++; continue; }
            if (!filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) continue;
            $r = itops_email('case_digest', (string)$u['email'], ['date' => itops_tpl_d2($today), 'count' => (string)$dg['count'], 'report' => $dg['report'], 'link' => itops_cs_url('cases'), 'recipient_name' => (string)$u['display_name']], ['user_id' => $id]);
            if (!empty($r['ok'])) $sent++;
        }
        return ['sent' => $sent, 'nothing' => $none, 'reason' => ''];
    }
}


/* ── BT78 · Phase 4: templates ─────────────────────────────────────────────────────────────────────────────────────────
 * A template is a ready-made list of tasks for work that comes back (a new hire, a leaver, a move). Applying it asks for
 * one date (the start day, the last day, the move day…) and makes every task with its person, its date counted in
 * business days from that date (the business-hours days and holidays when business hours are on, else Monday to Friday;
 * negative = before it), what it waits for, its meeting time, and what the requester sees. A task's person is the case
 * owner, a named person, or chosen when the template is applied; a requester step goes to the requester. Templates are
 * kept by a Super Admin and the people named for it; a case can be saved as a template. */
if (!function_exists('itops_cs_tpl_apply')) {
    function itops_cs_has4(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_templates'")->fetchColumn() === 1; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    function itops_cs_tpl_can_manage(PDO $db, array $user): bool {
        return ($user['role_slug'] ?? '') === 'super_admin' || in_array((int)($user['id'] ?? 0), itops_cs_people($db)['templates'] ?? [], true);
    }
    /** Working days: the business-hours days and holidays when business hours are on, else Monday to Friday. */
    function itops_cs_workday_cfg(PDO $db): array {
        if (!function_exists('itops_bh_config')) require_once __DIR__ . '/BusinessHours.php';
        $bh = itops_bh_config($db);
        return ['days' => $bh['days'] ?? [1, 2, 3, 4, 5], 'holidays' => $bh['holidays'] ?? []];
    }
    function itops_cs_is_workday(array $w, string $ymd): bool { return in_array((int)date('N', strtotime($ymd)), $w['days'], true) && !isset($w['holidays'][$ymd]); }
    /** The date $n business days after (or before, when negative) $anchor. 0 = the anchor itself. */
    function itops_cs_bizday(array $w, string $anchor, int $n): string {
        $d = $anchor; $step = $n < 0 ? '-1 day' : '+1 day'; $left = abs($n); $guard = 0;
        while ($left > 0 && $guard++ < 2000) { $d = date('Y-m-d', strtotime($d . ' ' . $step)); if (itops_cs_is_workday($w, $d)) $left--; }
        return $d;
    }
    /** Business days from $anchor to $date (negative when before). */
    function itops_cs_bizdiff(array $w, string $anchor, string $date): int {
        if ($date === $anchor) return 0;
        $sign = $date > $anchor ? 1 : -1; $n = 0; $d = $anchor; $guard = 0;
        while ($d !== $date && $guard++ < 2000) { $d = date('Y-m-d', strtotime($d . ($sign > 0 ? ' +1 day' : ' -1 day'))); if (itops_cs_is_workday($w, $d)) $n++; }
        return $sign * $n;
    }
    function itops_cs_tpl_get(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT * FROM itops_case_templates WHERE id=?'); $q->execute([$id]); $t = $q->fetch(PDO::FETCH_ASSOC); if (!$t) return null;
        $k = $db->prepare('SELECT k.*, u.display_name AS assignee_name FROM itops_case_template_tasks k LEFT JOIN itops_users u ON u.id=k.assignee_id WHERE k.template_id=? ORDER BY k.seq'); $k->execute([$id]);
        $t['tasks'] = $k->fetchAll(PDO::FETCH_ASSOC);
        return $t;
    }
    function itops_cs_tpl_list(PDO $db, bool $withInactive): array {
        $rows = $db->query('SELECT t.*, (SELECT COUNT(*) FROM itops_case_template_tasks k WHERE k.template_id=t.id) AS task_count FROM itops_case_templates t' . ($withInactive ? '' : ' WHERE t.active=1') . ' ORDER BY t.name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $g = itops_cs_tpl_get($db, (int)$r['id']); $r['tasks'] = $g['tasks']; }
        return $rows;
    }
    /** Check a template sent by the browser: [template fields, tasks]. Throws InvalidArgumentException with a plain message. */
    function itops_cs_tpl_clean(PDO $db, array $in): array {
        $bad = function (string $m) { throw new InvalidArgumentException($m); };
        $name = trim((string)($in['name'] ?? '')); if ($name === '') $bad('A template needs a name.'); if (mb_strlen($name) > 150) $bad('The name is too long (150 characters at most).');
        $t = ['name' => $name, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 4000), 'case_title' => mb_substr(trim((string)($in['case_title'] ?? '')), 0, 255),
              'anchor_label' => mb_substr(trim((string)($in['anchor_label'] ?? '')) ?: 'Start date', 0, 60), 'active' => !array_key_exists('active', $in) || !empty($in['active']) ? 1 : 0, 'target_offset' => null,
              'std_change' => !empty($in['std_change']) ? 1 : 0];   // BT82: a standard (pre-approved) change
        if (isset($in['target_offset']) && $in['target_offset'] !== '' && $in['target_offset'] !== null) { if (!is_numeric($in['target_offset']) || abs((int)$in['target_offset']) > 250) $bad('The target date is up to 250 business days from the date asked.'); $t['target_offset'] = (int)$in['target_offset']; }
        $tasks = []; $list = (array)($in['tasks'] ?? []);
        if (!$list) $bad('A template needs at least one task.'); if (count($list) > 60) $bad('A template holds up to 60 tasks.');
        $n = count($list);
        foreach (array_values($list) as $i => $k) {
            if (!is_array($k)) $bad('A task is not in the expected form.');
            $seq = $i + 1; $title = trim((string)($k['title'] ?? '')); if ($title === '') $bad('Task ' . $seq . ' needs a title.');
            $kind = (string)($k['kind'] ?? 'task'); if (!in_array($kind, ['task', 'approval', 'requester', 'appointment'], true)) $bad('Task ' . $seq . ': the kind is task, approval, requester or appointment.');
            $who = $kind === 'requester' ? 'requester' : (string)($k['who'] ?? 'owner');
            if (!in_array($who, ['owner', 'ask', 'person', 'requester'], true) || ($who === 'requester' && $kind !== 'requester')) $bad('Task ' . $seq . ': choose who does it (the case owner, a person, or chosen when the template is used).');
            $aid = null;
            if ($who === 'person') { $aid = (int)($k['assignee_id'] ?? 0); $q = $db->prepare("SELECT 1 FROM itops_users WHERE id=? AND status='active'"); $q->execute([$aid]); if (!$aid || !$q->fetchColumn()) $bad('Task ' . $seq . ': choose an active person.'); }
            $off = $k['offset_days'] ?? 0; if (!is_numeric($off) || abs((int)$off) > 250) $bad('Task ' . $seq . ': the day is up to 250 business days before or after.');
            $dep = (int)($k['depends_seq'] ?? 0); if ($dep && ($dep < 1 || $dep > $n || $dep === $seq)) $bad('Task ' . $seq . ': it can wait for another task of the template, not itself.');
            $time = null; $mins = null; $att = null; $inv = 0;
            if ($kind === 'appointment') {
                $time = trim((string)($k['at_time'] ?? '09:00')); if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $bad('Task ' . $seq . ': the meeting time is HH:MM.');
                $mins = (int)($k['minutes'] ?? 60); if ($mins < 15 || $mins > 480) $bad('Task ' . $seq . ': a meeting lasts 15 minutes to 8 hours.');
                $ids = []; foreach ((array)($k['attendees'] ?? []) as $u) { $q = $db->prepare("SELECT 1 FROM itops_users WHERE id=? AND status='active'"); $q->execute([(int)$u]); if (!$q->fetchColumn()) $bad('Task ' . $seq . ': everyone invited must be an active account.'); $ids[] = (int)$u; }
                $att = $ids ? json_encode(array_values(array_unique($ids))) : null; $inv = !empty($k['invite_requester']) ? 1 : 0;
            }
            $pub = trim((string)($k['public_title'] ?? ''));
            $dur = $kind === 'appointment' ? 1 : (int)($k['duration_days'] ?? 1); if ($dur < 1 || $dur > 250) $bad('Task ' . $seq . ': it lasts 1 to 250 business days.');   // BT79
            $phn = mb_substr(trim((string)($k['phase_name'] ?? '')), 0, 100);   // BT80: the project phase it belongs to (projects only)
            $tasks[] = ['phase_name' => $phn === '' ? null : $phn, 'duration_days' => $dur, 'seq' => $seq, 'title' => mb_substr($title, 0, 255), 'kind' => $kind, 'who' => $who, 'assignee_id' => $aid, 'offset_days' => (int)$off, 'at_time' => $time, 'minutes' => $mins,
                        'depends_seq' => $dep ?: null, 'show_requester' => $kind === 'requester' ? 1 : (!empty($k['show_requester']) ? 1 : 0), 'public_title' => $pub === '' ? null : mb_substr($pub, 0, 255), 'invite_requester' => $inv, 'attendees' => $att];
        }
        // no loops through "waits for"
        foreach ($tasks as $k) { $seen = []; for ($x = $k['seq']; $x; $x = $tasks[$x - 1]['depends_seq'] ?? null) { if (isset($seen[$x])) $bad('Task ' . $k['seq'] . ': the tasks would wait for each other.'); $seen[$x] = 1; } }
        return [$t, $tasks];
    }
    function itops_cs_tpl_save(PDO $db, array $t, array $tasks, ?int $id, int $uid): int {
        $db->beginTransaction();
        try {
            if ($id) $db->prepare('UPDATE itops_case_templates SET name=?, description=?, case_title=?, anchor_label=?, target_offset=?, active=?, updated_by=?, updated_at=NOW() WHERE id=?')
                        ->execute([$t['name'], $t['description'], $t['case_title'], $t['anchor_label'], $t['target_offset'], $t['active'], $uid, $id]);
            else { $db->prepare('INSERT INTO itops_case_templates (name, description, case_title, anchor_label, target_offset, active, created_by, updated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())')
                        ->execute([$t['name'], $t['description'], $t['case_title'], $t['anchor_label'], $t['target_offset'], $t['active'], $uid, $uid]); $id = (int)$db->lastInsertId(); }
            try { $db->prepare('UPDATE itops_case_templates SET std_change=? WHERE id=?')->execute([(int)($t['std_change'] ?? 0), $id]); } catch (Throwable $e) {}   // BT82 (column after the database update)
            $db->prepare('DELETE FROM itops_case_template_tasks WHERE template_id=?')->execute([$id]);
            $h5 = itops_cs_has5($db); $h6 = function_exists('itops_cs_has6') && itops_cs_has6($db);
            $ins = $db->prepare('INSERT INTO itops_case_template_tasks (template_id, seq, title, kind, who, assignee_id, offset_days, at_time, minutes, depends_seq, show_requester, public_title, invite_requester, attendees' . ($h5 ? ', duration_days' : '') . ($h6 ? ', phase_name' : '') . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?' . ($h5 ? ',?' : '') . ($h6 ? ',?' : '') . ')');
            foreach ($tasks as $k) $ins->execute(array_merge([$id, $k['seq'], $k['title'], $k['kind'], $k['who'], $k['assignee_id'], $k['offset_days'], $k['at_time'], $k['minutes'], $k['depends_seq'], $k['show_requester'], $k['public_title'], $k['invite_requester'], $k['attendees']], $h5 ? [(int)($k['duration_days'] ?? 1)] : [], $h6 ? [$k['phase_name'] ?? null] : []));
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); if ($e instanceof PDOException && (string)$e->getCode() === '23000') throw new InvalidArgumentException('A template with that name already exists.'); throw $e; }
        return $id;
    }
    /** What applying a template from $anchor would make: each task with its date (and, for a meeting, its time). */
    function itops_cs_tpl_plan(PDO $db, array $tpl, string $anchor): array {
        $w = itops_cs_workday_cfg($db); $out = [];
        foreach ($tpl['tasks'] as $k) {
            $day = itops_cs_bizday($w, $anchor, (int)$k['offset_days']);
            $dur = max(1, (int)($k['duration_days'] ?? 1)); $sday = $dur > 1 && $k['kind'] !== 'appointment' ? itops_cs_bizday($w, $day, -($dur - 1)) : $day;   // BT79: the offset is the due day
            $out[] = ['phase_name' => $k['phase_name'] ?? null, 'start' => $sday, 'duration_days' => $dur, 'seq' => (int)$k['seq'], 'title' => $k['title'], 'kind' => $k['kind'], 'who' => $k['who'], 'assignee_id' => $k['assignee_id'] ? (int)$k['assignee_id'] : null, 'assignee_name' => $k['assignee_name'] ?? null,
                      'date' => $day, 'start_at' => $k['kind'] === 'appointment' ? $day . ' ' . $k['at_time'] . ':00' : null, 'depends_seq' => $k['depends_seq'] ? (int)$k['depends_seq'] : null,
                      'show_requester' => (int)$k['show_requester'], 'public_title' => $k['public_title'], 'offset_days' => (int)$k['offset_days'], 'past' => $day < date('Y-m-d')];
        }
        return ['anchor' => $anchor, 'target_date' => $tpl['target_offset'] !== null ? itops_cs_bizday($w, $anchor, (int)$tpl['target_offset']) : null, 'tasks' => $out,
                'ask' => array_values(array_map(fn($k) => ['seq' => (int)$k['seq'], 'title' => $k['title'], 'kind' => $k['kind']], array_filter($tpl['tasks'], fn($k) => $k['who'] === 'ask')))];
    }
    /**
     * Make the template's tasks in a case. $ask = [seq => user id] for the tasks whose person is chosen now (missing →
     * the case owner). Returns the new task ids. The emails, bells and meetings follow (as when a task is added by hand).
     */
    function itops_cs_tpl_apply(PDO $db, array $case, array $tpl, string $anchor, array $ask, int $byId, string $byName): array {
        $plan = itops_cs_tpl_plan($db, $tpl, $anchor);
        $mainReq = null; foreach (itops_cs_tickets($db, (int)$case['id']) as $tk) if ((int)$tk['case_main'] === 1) $mainReq = $tk;
        $reqUser = ($mainReq && empty($mainReq['contact_email']) && $mainReq['contact_name'] === null) ? (int)$mainReq['requester_id'] : 0;
        $byTpl = []; foreach ($tpl['tasks'] as $k) $byTpl[(int)$k['seq']] = $k;
        $seq0 = (int)$db->query('SELECT COALESCE(MAX(seq),0) FROM itops_case_tasks WHERE case_id=' . (int)$case['id'])->fetchColumn();
        $map = []; $made = []; $skipped = [];
        $ins = $db->prepare('INSERT INTO itops_case_tasks (case_id, seq, title, public_title, show_requester, kind, assignee_id, due_date, status, start_at, minutes, attendees, invite_requester, created_at) VALUES (?,?,?,?,?,?,?,?,\'open\',?,?,?,?,NOW())');
        $h5 = itops_cs_has5($db);
        foreach ($plan['tasks'] as $p) {
            $k = $byTpl[$p['seq']];
            if ($p['kind'] === 'requester') { if (!$reqUser) { $skipped[] = $p['title']; continue; } $who = $reqUser; }
            elseif ($k['who'] === 'person') $who = (int)$k['assignee_id'];
            elseif ($k['who'] === 'ask' && !empty($ask[$p['seq']])) $who = (int)$ask[$p['seq']];
            else $who = (int)$case['owner_id'];
            $inv = (int)$k['invite_requester']; if ($inv && $mainReq && !$reqUser && !filter_var((string)$mainReq['contact_email'], FILTER_VALIDATE_EMAIL)) $inv = 0;
            $ins->execute([(int)$case['id'], $seq0 + $p['seq'], $p['title'], $p['public_title'], (int)$p['show_requester'], $p['kind'], $who, $p['date'], $p['start_at'], $k['minutes'] ? (int)$k['minutes'] : null, $k['attendees'], $inv]);
            $map[$p['seq']] = (int)$db->lastInsertId(); $made[] = $map[$p['seq']];
            if ($h5 && $p['start'] !== $p['date']) $db->prepare('UPDATE itops_case_tasks SET start_date=? WHERE id=?')->execute([$p['start'], $map[$p['seq']]]);
            if (!empty($p['phase_name']) && ($case['kind'] ?? 'case') === 'project' && function_exists('itops_cs_has6') && itops_cs_has6($db)) {   // BT80: a project gets the template's phases
                $pid = itops_cs_phase_by_name($db, (int)$case['id'], (string)$p['phase_name']); $phasesMade[$pid] = max($phasesMade[$pid] ?? '', (string)$p['date']);
                $db->prepare('UPDATE itops_case_tasks SET phase_id=? WHERE id=?')->execute([$pid, $map[$p['seq']]]);
            }
        }
        foreach ($phasesMade ?? [] as $pid => $last) $db->prepare('UPDATE itops_case_phases SET milestone_date=? WHERE id=? AND milestone_date IS NULL')->execute([$last, $pid]);   // the milestone: its last task
        foreach ($plan['tasks'] as $p) if ($p['depends_seq'] && isset($map[$p['seq']], $map[$p['depends_seq']])) $db->prepare('UPDATE itops_case_tasks SET depends_on=? WHERE id=?')->execute([$map[$p['depends_seq']], $map[$p['seq']]]);
        if ((int)($tpl['std_change'] ?? 0) === 1 && ($case['origin'] ?? '') === 'change' && function_exists('itops_svc_has2') && itops_svc_has2($db)) {   // BT82: a standard change is pre-approved
            $itS = itops_case_svc_get($db, (int)$case['id']);
            $stS = itops_chg_approval_state($db, $itS);
            if (!itops_chg_std_block($db, $itS)) {   // BT86: not once approval was asked for, nor in a change freeze (the route says why)
                $fS = ['change_type' => 'standard', 'approval_state' => 'approved']; if (itops_chg_has_std($db)) $fS['std_template_id'] = (int)$tpl['id']; itops_case_svc_set($db, (int)$case['id'], $fS); }
        }
        if ($plan['target_date'] && empty($case['target_date'])) { $db->prepare('UPDATE itops_cases SET target_date=? WHERE id=?')->execute([$plan['target_date'], (int)$case['id']]); }
        $db->prepare("UPDATE itops_cases SET updated_at=NOW(), status=IF(status='open','in_progress',status) WHERE id=?")->execute([(int)$case['id']]);
        try { $db->prepare('UPDATE itops_cases SET template_id=? WHERE id=? AND template_id IS NULL')->execute([(int)$tpl['id'], (int)$case['id']]); } catch (Throwable $e) {}   // BT83: time per template (before the database update: no column yet)
        $case = itops_cs_get($db, (int)$case['id']);
        itops_cs_follow_target($db, $case);
        itops_cs_audit('case_template_applied', $case, null, ['template' => $tpl['name'], $tpl['anchor_label'] => $anchor, 'tasks' => count($made), 'target_date' => $case['target_date']] + ($skipped ? ['left out (the requester has no account)' => $skipped] : []));
        foreach ($made as $tid) {
            $q = $db->prepare('SELECT kind, assignee_id, title FROM itops_case_tasks WHERE id=?'); $q->execute([$tid]); $t = $q->fetch(PDO::FETCH_ASSOC);
            if ((int)$t['assignee_id'] !== $byId) itops_cs_bell($db, $t['assignee_id'], $t['kind'] === 'approval' ? 'Approval needed' : ($t['kind'] === 'requester' ? 'Something to do on your request' : ($t['kind'] === 'appointment' ? 'Meeting to organize' : 'Task assigned to you')), $case['case_number'] . ': ' . $t['title'], (int)$case['id'], $t['kind'] === 'requester' ? 'tickets' : 'cases');
            if (itops_cs_has3($db)) { itops_cs_notify_task($db, $case, $tid, $byId, $byName); if ($t['kind'] === 'appointment') itops_cs_appt_sync($db, $case, $tid, $byId); }
        }
        return ['ids' => $made, 'skipped' => $skipped, 'target_date' => $case['target_date']];
    }
    /** A case as a template: dates counted from $anchor; the owner's tasks go to "the case owner"; cancelled tasks are left out. */
    function itops_cs_tpl_from_case(PDO $db, array $case, string $anchor): array {
        $w = itops_cs_workday_cfg($db); $tasks = []; $seq = []; $i = 0;
        $phn = []; if (function_exists('itops_cs_has6') && itops_cs_has6($db)) foreach (itops_cs_phases($db, (int)$case['id']) as $p) $phn[(int)$p['id']] = $p['name'];   // BT80
        $all = array_values(array_filter(itops_cs_tasks($db, (int)$case['id']), fn($t) => $t['status'] !== 'cancelled'));
        foreach ($all as $t) $seq[(int)$t['id']] = ++$i;
        foreach ($all as $t) {
            $date = $t['kind'] === 'appointment' && $t['start_at'] ? substr($t['start_at'], 0, 10) : ($t['due_date'] ?: $anchor);
            $who = $t['kind'] === 'requester' ? 'requester' : ((int)$t['assignee_id'] === (int)$case['owner_id'] ? 'owner' : 'person');
            $dur = (!empty($t['start_date']) && $t['kind'] !== 'appointment' && $t['due_date']) ? itops_cs_span($w, $t['start_date'], $t['due_date']) + 1 : 1;   // BT79
            $tasks[] = ['phase_name' => $phn[(int)($t['phase_id'] ?? 0)] ?? null, 'duration_days' => $dur, 'title' => $t['title'], 'kind' => $t['kind'], 'who' => $who, 'assignee_id' => $who === 'person' ? (int)$t['assignee_id'] : null, 'offset_days' => itops_cs_bizdiff($w, $anchor, $date),
                        'at_time' => $t['kind'] === 'appointment' && $t['start_at'] ? substr($t['start_at'], 11, 5) : null, 'minutes' => $t['minutes'] ?? null, 'depends_seq' => $t['depends_on'] && isset($seq[(int)$t['depends_on']]) ? $seq[(int)$t['depends_on']] : null,
                        'show_requester' => (int)$t['show_requester'], 'public_title' => $t['public_title'], 'invite_requester' => (int)($t['invite_requester'] ?? 0), 'attendees' => json_decode((string)($t['attendees'] ?? ''), true) ?: []];
        }
        return ['description' => 'Made from case ' . $case['case_number'] . ': ' . $case['title'], 'case_title' => '', 'anchor_label' => 'Start date',
                'target_offset' => $case['target_date'] ? itops_cs_bizdiff($w, $anchor, $case['target_date']) : null, 'tasks' => $tasks];
    }
}

/* ── BT79 · the case timeline (Gantt) ──────────────────────────────────────────────────────────────────────────────────
 * Each task is a bar from its start date to its due date (no start date yet = it starts on its due date; a meeting is
 * its own day). Moving or stretching a bar changes the dates. A task that waits for another must start after it ends:
 * when a bar moves later, the tasks that wait for it would move too, keeping their length in business days — the app
 * shows them first and moves them only when confirmed. Done and cancelled tasks do not move. */
if (!function_exists('itops_cs_schedule')) {
    function itops_cs_has5(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_tasks' AND COLUMN_NAME='start_date'")->fetchColumn() === 1; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    /** The next working day after $ymd. */
    function itops_cs_next_workday(array $w, string $ymd): string { return itops_cs_bizday($w, $ymd, 1); }
    /** Business days in [start, due] minus one (0 = a one-day task). */
    function itops_cs_span(array $w, string $start, string $due): int { return max(0, itops_cs_bizdiff($w, $start, $due)); }
    /**
     * $moves = [task id => ['start' => Y-m-d, 'due' => Y-m-d]]. Returns ['moved' => [...], 'knock' => [...]] where each
     * row is [id, title, assignee, from_start, from_due, to_start, to_due]. With $apply the dates are saved (meetings keep
     * their time and their Outlook meeting follows), each change is in the Audit Log, and people whose task moved because
     * of someone else's change get a bell. Throws InvalidArgumentException with a plain message.
     */
    function itops_cs_schedule(PDO $db, array $case, array $moves, bool $apply, ?int $byId, string $byName): array {
        $w = itops_cs_workday_cfg($db); $tasks = itops_cs_tasks($db, (int)$case['id']); $by = [];
        foreach ($tasks as $t) { $t['_s'] = $t['start_date'] ?: $t['due_date']; $t['_d'] = $t['due_date']; $by[(int)$t['id']] = $t; }
        $orig = $by; $moved = []; $knock = [];
        foreach ($moves as $id => $m) {
            $id = (int)$id; if (!isset($by[$id])) throw new InvalidArgumentException('A task is not in this case.');
            $t = $by[$id];
            if (in_array($t['status'], ['done', 'cancelled'], true)) throw new InvalidArgumentException('"' . $t['title'] . '" is ' . $t['status'] . ' and keeps its dates.');
            $s = (string)($m['start'] ?? ''); $d = (string)($m['due'] ?? '');
            foreach ([$s, $d] as $x) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) || !strtotime($x)) throw new InvalidArgumentException('Dates are YYYY-MM-DD.');
            if ($t['kind'] === 'appointment') $s = $d;   // a meeting is one day
            if ($s > $d) throw new InvalidArgumentException('"' . $t['title'] . '" cannot start after it is due.');
            $by[$id]['_s'] = $s; $by[$id]['_d'] = $d; $moved[$id] = 1;
        }
        // tasks that wait follow, keeping their length (repeat until nothing changes; the chain has no loops)
        for ($round = 0, $chg = true; $chg && $round < 100; $round++) {
            $chg = false;
            foreach ($by as $id => $t) {
                $p = (int)($t['depends_on'] ?? 0); if (!$p || !isset($by[$p]) || in_array($t['status'], ['done', 'cancelled'], true) || isset($moved[$id])) continue;
                if (!$by[$p]['_d'] || !$t['_d']) continue;
                $need = itops_cs_next_workday($w, $by[$p]['_d']);
                if ($t['_s'] >= $need) continue;
                $len = itops_cs_span($w, $orig[$id]['_s'] ?: $orig[$id]['_d'], $orig[$id]['_d']);
                $by[$id]['_s'] = $need; $by[$id]['_d'] = $t['kind'] === 'appointment' ? $need : itops_cs_bizday($w, $need, $len);
                $knock[$id] = 1; $chg = true;
            }
        }
        // BT92 (code audit Low): a task moved by hand does not start before the task it waits for is due
        foreach (array_keys($moved) as $id) {
            $p = (int)($by[$id]['depends_on'] ?? 0);
            if ($p && isset($by[$p]) && !in_array($by[$p]['status'], ['done', 'cancelled'], true) && $by[$p]['_d'] && $by[$id]['_s'] < $by[$p]['_d'])
                throw new InvalidArgumentException('"' . $by[$id]['title'] . '" waits for "' . $by[$p]['title'] . '", due ' . date('m/d/Y', strtotime($by[$p]['_d'])) . ': it cannot start before that. Move "' . $by[$p]['title'] . '" first.');
        }
        $row = fn($id) => ['id' => $id, 'title' => $by[$id]['title'], 'assignee' => $by[$id]['assignee_name'], 'kind' => $by[$id]['kind'], 'from_start' => $orig[$id]['_s'], 'from_due' => $orig[$id]['_d'], 'to_start' => $by[$id]['_s'], 'to_due' => $by[$id]['_d']];
        $out = ['moved' => array_map($row, array_keys($moved)), 'knock' => array_map($row, array_keys($knock))];
        if (!$apply) return $out;
        foreach (array_merge(array_keys($moved), array_keys($knock)) as $id) {
            $t = $by[$id];
            if ($t['_s'] === $orig[$id]['_s'] && $t['_d'] === $orig[$id]['_d'] && $orig[$id]['start_date']) continue;
            $start = $t['kind'] === 'appointment' ? null : $t['_s'];
            $sa = $t['kind'] === 'appointment' && $t['start_at'] ? $t['_d'] . ' ' . substr($t['start_at'], 11) : ($t['start_at'] ?? null);
            $db->prepare('UPDATE itops_case_tasks SET start_date=?, due_date=?, start_at=? WHERE id=?')->execute([$start, $t['_d'], $sa, $id]);
            itops_cs_audit('case_task_updated', $case, ['task' => $t['title'], 'start' => $orig[$id]['_s'], 'due' => $orig[$id]['_d']],
                          ['task' => $t['title'], 'start' => $t['_s'], 'due' => $t['_d'], 'changed_in' => isset($knock[$id]) ? 'the timeline (it waits for a task that moved)' : 'the timeline']);
            if ($t['kind'] === 'appointment' && !empty($t['event_id']) && function_exists('itops_cs_appt_sync')) itops_cs_appt_sync($db, $case, $id, $byId);
            if (isset($knock[$id]) && $t['assignee_id'] && (int)$t['assignee_id'] !== (int)$byId)
                itops_cs_bell($db, $t['assignee_id'], 'Task dates changed', $case['case_number'] . ': ' . $t['title'] . ' now ' . date('m/d/Y', strtotime($t['_s'])) . ($t['_s'] !== $t['_d'] ? '–' . date('m/d/Y', strtotime($t['_d'])) : ''), (int)$case['id'], $t['kind'] === 'requester' ? 'tickets' : 'cases');
        }
        $db->prepare('UPDATE itops_cases SET updated_at=NOW() WHERE id=?')->execute([(int)$case['id']]);
        return $out;
    }
}

/* ── BT80 · Phase 5: projects, cases without a ticket, phases, budget lines ────────────────────────────────────────────
 * a ticket is a service request or an incident (someone asked); a case started from it keeps that requester.
 * Proactive work that IT starts itself — a planned change, a problem investigation, an internal task — is a case
 * without a ticket ("origin" says which); larger planned work is a project (PR-…) with phases (Plan → Build → Roll out
 * → Close…), each with a milestone date, a sponsor instead of a requester, and team members from anywhere in the
 * organisation, who see their projects and their own tasks only. A case that grows is promoted to a project. */
if (!function_exists('itops_cs_phases')) {
    function itops_cs_has6(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_phases'")->fetchColumn() === 1; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    function itops_cs_origins(): array { return ['request' => 'A request or incident (ticket)', 'change' => 'Planned change', 'problem' => 'Problem investigation', 'internal' => 'Internal task']; }
    /** The phases of a project with their progress: tasks, done, first start, last due, and a state
     *  (done | late = milestone passed and not done | on_track | empty). */
    function itops_cs_phases(PDO $db, int $caseId, ?array $tasks = null): array {
        if (!itops_cs_has6($db)) return [];
        $q = $db->prepare('SELECT * FROM itops_case_phases WHERE case_id=? ORDER BY seq, id'); $q->execute([$caseId]); $ph = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$ph) return [];
        $tasks = $tasks ?? itops_cs_tasks($db, $caseId); $today = date('Y-m-d');
        foreach ($ph as &$p) {
            $mine = array_values(array_filter($tasks, fn($t) => (int)($t['phase_id'] ?? 0) === (int)$p['id'] && $t['status'] !== 'cancelled'));
            $p['tasks'] = count($mine); $p['done'] = count(array_filter($mine, fn($t) => $t['status'] === 'done'));
            $starts = array_filter(array_map(fn($t) => $t['start_date'] ?? ($t['due_date'] ?? null) ?: ($t['due_date'] ?? null), $mine)); $dues = array_filter(array_map(fn($t) => $t['due_date'] ?? null, $mine));
            $p['first'] = $starts ? min($starts) : null; $p['last'] = $dues ? max($dues) : null;
            $finished = $p['tasks'] > 0 && $p['done'] === $p['tasks'];
            $p['state'] = $finished ? 'done' : (!$p['tasks'] ? 'empty' : (!empty($p['milestone_date']) && $p['milestone_date'] < $today ? 'late' : 'on_track'));
        }
        return $ph;
    }
    /** Find a phase of the case by name, or make it (at the end). */
    function itops_cs_phase_by_name(PDO $db, int $caseId, string $name): int {
        $q = $db->prepare('SELECT id FROM itops_case_phases WHERE case_id=? AND name=?'); $q->execute([$caseId, $name]); $id = (int)$q->fetchColumn();
        if ($id) return $id;
        $seq = (int)$db->query('SELECT COALESCE(MAX(seq),0)+1 FROM itops_case_phases WHERE case_id=' . $caseId)->fetchColumn();
        $db->prepare('INSERT INTO itops_case_phases (case_id, seq, name, created_at) VALUES (?,?,?,NOW())')->execute([$caseId, $seq, mb_substr($name, 0, 100)]);
        return (int)$db->lastInsertId();
    }
    /** A case becomes a project: a PR- number (the CS- number is kept as its former number), kind project. */
    function itops_cs_promote(PDO $db, array $case): array {
        $old = $case['case_number'];
        $new = itops_cs_create_numbered($db, function (string $cn) use ($db, $case) {
            $db->prepare("UPDATE itops_cases SET case_number=?, kind='project', former_number=?, updated_at=NOW() WHERE id=?")->execute([$cn, $case['case_number'], (int)$case['id']]);
            return $cn;
        }, 'PR');
        $c = itops_cs_get($db, (int)$case['id']);
        foreach (itops_cs_tickets($db, (int)$case['id']) as $k)
            $db->prepare('INSERT INTO itops_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([(int)$k['id'], $_SERVER['ITOPS_USER_ID'] ?? null, 'Case ' . $old . ' became project ' . $new . '.']);
        itops_cs_audit('case_promoted', $c, ['number' => $old, 'kind' => 'case'], ['number' => $new, 'kind' => 'project']);
        return $c;
    }
    /** IT Budget lines linked to a project, with their planned amount for the year. */
    function itops_cs_budget_lines(PDO $db, int $caseId): array {
        if (!itops_cs_has6($db)) return [];
        try {
            if (!function_exists('itops_budget_amount')) require_once __DIR__ . '/Budget.php';
            $q = $db->prepare('SELECT l.* FROM itops_case_budget b JOIN itops_budget_lines l ON l.id=b.line_id WHERE b.case_id=? ORDER BY l.fy, l.name'); $q->execute([$caseId]);
            return array_map(fn($l) => ['id' => (int)$l['id'], 'fy' => (int)$l['fy'], 'name' => $l['name'], 'tower' => $l['tower'], 'amount' => itops_budget_amount($l), 'status' => $l['status']], $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) { return []; }
    }
}

/* ── BT85: a short checklist inside a task ──────────────────────────────────────────────────── */
if (!function_exists('itops_cs_has7')) {
    function itops_cs_has7(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_task_checks'")->fetchColumn() === 1; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    /** task id => [ {id, text, done, done_by_name, done_at}, … ] in order. */
    function itops_cs_checks(PDO $db, array $taskIds): array {
        $out = []; $taskIds = array_values(array_filter(array_map('intval', $taskIds))); if (!$taskIds) return $out;
        $q = $db->query('SELECT k.id, k.task_id, k.text, k.done, k.done_at, u.display_name AS done_by_name FROM itops_case_task_checks k LEFT JOIN itops_users u ON u.id=k.done_by WHERE k.task_id IN (' . implode(',', $taskIds) . ') ORDER BY k.task_id, k.seq, k.id');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['task_id']][] = ['id' => (int)$r['id'], 'text' => $r['text'], 'done' => (int)$r['done'] === 1, 'done_at' => $r['done_at'], 'done_by_name' => $r['done_by_name']];
        return $out;
    }
}

