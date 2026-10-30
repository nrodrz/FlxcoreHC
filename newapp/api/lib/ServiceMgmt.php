<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Service management practices on tickets (BT81, Phase 6 part 1 of the Tickets, Cases & Projects process).
 *
 *  - Ticket type (incident vs service request): each category says Incident or Service request; a new
 *    ticket takes its category's type, and people who edit tickets can change it. Incidents can have their own priority
 *    targets (Settings → SLA & Hours; none set = the same as requests).
 *  - Satisfaction (service level management): when a ticket is resolved, the requester is asked "How did we do?"
 *    (happy / not happy, with a comment) from the Resolved email or on their ticket. "Not happy" tells the ticket owner
 *    and the alert list, and leaves an internal note on the ticket.
 *  - Major incident (incident management): someone who sees every ticket declares a ticket a major incident —
 *    priority Critical, the alert list told, an optional banner for everyone; tickets about the same outage are linked
 *    to it; one update reaches every affected requester; resolving it resolves the linked tickets with the same note;
 *    a review afterwards (what happened, the cause, what we will do), which can open a Problem investigation case.
 */
require_once __DIR__ . '/EmailTemplates.php';

if (!function_exists('opsh_svc_has')) {
    function opsh_svc_has(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='opsh_tickets' AND COLUMN_NAME IN ('ticket_type','major')")->fetchColumn() === 2; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    function opsh_svc_types(): array { return ['incident' => 'Incident', 'request' => 'Service request']; }
    /** The type a new ticket in this category gets (no category, or before the database update: a service request). */
    function opsh_svc_type_for(PDO $db, $catId): string {
        if (!$catId) return 'request';
        try { $q = $db->prepare('SELECT ticket_type FROM opsh_ticket_categories WHERE id=?'); $q->execute([(int)$catId]); $t = (string)$q->fetchColumn(); } catch (Throwable $e) { $t = ''; }
        return $t === 'incident' ? 'incident' : 'request';
    }
    function opsh_svc_setting(PDO $db, string $k): ?string {
        try { $q = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? null : (string)$v; } catch (Throwable $e) { return null; }
    }
    function opsh_svc_set(PDO $db, string $k, ?string $v): void {
        if ($v === null) { $db->prepare('DELETE FROM opsh_settings WHERE skey=?')->execute([$k]); return; }
        $db->prepare('INSERT INTO opsh_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')->execute([$k, $v]);
    }
    function opsh_svc_app_url(): string { return defined('APP_URL') && APP_URL !== '' && strpos((string)APP_URL, '%%') === false ? rtrim((string)APP_URL, '/') : ''; }
    /** Who a ticket's emails go to: [email, name, user id (null for an outside requester), outside?] */
    function opsh_svc_requester(PDO $db, array $tk): array {
        $ext = !empty($tk['contact_email']) || ($tk['contact_name'] ?? null) !== null;
        if ($ext) return [(string)$tk['contact_email'], (string)($tk['contact_name'] ?? ''), null, true];
        $q = $db->prepare('SELECT email, display_name FROM opsh_users WHERE id=?'); $q->execute([(int)$tk['requester_id']]); $u = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return [(string)($u['email'] ?? ''), (string)($u['display_name'] ?? ''), (int)$tk['requester_id'], false];
    }
    function opsh_svc_bell(PDO $db, $uid, string $title, string $body, string $link = 'tickets'): void {
        if (!$uid) return;
        try { $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([(int)$uid, $title, $body, 'ticket', $link]); } catch (Throwable $e) {}
    }
    function opsh_svc_alert_ids(PDO $db): array {
        if (!function_exists('opsh_tr_alert_list')) { try { require_once __DIR__ . '/TicketRouting.php'; } catch (Throwable $e) {} }
        try { return array_values(array_unique(array_map('intval', (array)opsh_tr_alert_list($db)))); } catch (Throwable $e) { return []; }
    }

    // ── satisfaction ─────────────────────────────────────────────────────────────────────────
    function opsh_csat_on(PDO $db): bool { return opsh_svc_has($db) && opsh_svc_setting($db, 'sla_csat_enabled') !== '0'; }
    /** The rating link token of a ticket (made the first time it is asked for). */
    function opsh_csat_token(PDO $db, int $ticketId): string {
        $q = $db->prepare('SELECT token FROM opsh_ticket_csat WHERE ticket_id=?'); $q->execute([$ticketId]); $t = (string)$q->fetchColumn();
        if ($t !== '') return $t;
        $t = bin2hex(random_bytes(24));
        $db->prepare('INSERT IGNORE INTO opsh_ticket_csat (ticket_id, token, created_at) VALUES (?,?,NOW())')->execute([$ticketId, $t]);
        $q->execute([$ticketId]); return (string)$q->fetchColumn();
    }
    /** The "How did we do?" line of the Resolved email (empty when off or when the app has no address). */
    function opsh_csat_line(PDO $db, int $ticketId): array {
        if (!$ticketId || !opsh_csat_on($db) || opsh_svc_app_url() === '') return ['en' => '', 'es' => ''];
        try { $tok = opsh_csat_token($db, $ticketId); } catch (Throwable $e) { return ['en' => '', 'es' => '']; }
        $u = opsh_svc_app_url() . '/rate.html?t=' . $tok . '&r=';
        return ['en' => '**How did we do?** [Happy with how it was handled](' . $u . 'happy) · [Not happy](' . $u . 'unhappy)',
                'es' => '**¿Cómo lo hicimos?** [Satisfecho con la atención](' . $u . 'happy) · [No satisfecho](' . $u . 'unhappy)'];
    }
    function opsh_csat_get(PDO $db, int $ticketId): ?array {
        if (!opsh_svc_has($db)) return null;
        $q = $db->prepare('SELECT ticket_id, rating, comment, rated_at, rated_via FROM opsh_ticket_csat WHERE ticket_id=? AND rating IS NOT NULL'); $q->execute([$ticketId]);
        $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
    /**
     * Record a rating. $tk = the ticket row. Allowed while the ticket is resolved or closed, up to 30 days after it was
     * resolved; it can be changed in that time. Returns the stored row. Throws InvalidArgumentException with a plain message.
     */
    function opsh_csat_rate(PDO $db, array $tk, string $rating, string $comment, ?int $byUser, string $via): array {
        if (!opsh_csat_on($db)) throw new InvalidArgumentException('Ratings are switched off.');
        if (!in_array($rating, ['happy', 'unhappy'], true)) throw new InvalidArgumentException('Choose happy or not happy.');
        if (!in_array($tk['status'], ['resolved', 'closed'], true)) throw new InvalidArgumentException('A ticket can be rated once it is resolved.');
        $at = strtotime((string)($tk['resolved_at'] ?: $tk['closed_at'] ?: $tk['updated_at'] ?? ''));
        if ($at && $at < time() - 30 * 86400) throw new InvalidArgumentException('This ticket was resolved more than 30 days ago; it can no longer be rated.');
        $comment = mb_substr(trim($comment), 0, 2000);
        $prev = opsh_csat_get($db, (int)$tk['id']);
        // BT87 (public Lows): the same answer again changes nothing; an answer is changed at most 5 times (no flooding the
        // ticket with notes and the owner with bells)
        if ($prev && ($prev['rating'] ?? null) === $rating && (string)($prev['comment'] ?? '') === $comment) return $prev;
        if ($prev && !empty($prev['rating'])) { $nq = $db->prepare("SELECT COUNT(*) FROM opsh_audit_log WHERE action='ticket_rated' AND entity_type='ticket' AND entity_id=?"); $nq->execute([(int)$tk['id']]);
            if ((int)$nq->fetchColumn() >= 6) throw new InvalidArgumentException('This rating was already changed several times; it stays as it is. Reply to the email to tell the team more.'); }
        opsh_csat_token($db, (int)$tk['id']);
        $db->prepare('UPDATE opsh_ticket_csat SET rating=?, comment=?, rated_at=NOW(), rated_by=?, rated_via=? WHERE ticket_id=?')->execute([$rating, $comment === '' ? null : $comment, $byUser, $via, (int)$tk['id']]);
        if (function_exists('audit_log')) { try { audit_log('ticket_rated', 'ticket', (int)$tk['id'], (string)$tk['ticket_number'], $prev ? ['rating' => $prev['rating'], 'comment' => $prev['comment']] : null, ['rating' => $rating === 'happy' ? 'happy' : 'not happy', 'comment' => $comment, 'from' => $via]); } catch (Throwable $e) {} }
        $text = ($rating === 'happy' ? 'The requester rated this ticket: happy' : 'The requester rated this ticket: NOT happy') . ($comment !== '' ? ' — "' . $comment . '"' : '') . '.';
        $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([(int)$tk['id'], $byUser ?: (int)$tk['requester_id'], $text]);   // from the email link: written as the requester
        if ($rating === 'unhappy' && (!$prev || $prev['rating'] !== 'unhappy')) {   // the owner and the leads hear about it at once
            $who = array_values(array_unique(array_filter(array_merge([(int)($tk['assignee_id'] ?? 0)], opsh_svc_alert_ids($db)))));
            foreach ($who as $u) if ($u !== $byUser) opsh_svc_bell($db, $u, 'A requester is not happy', $tk['ticket_number'] . ': ' . ($comment !== '' ? mb_substr($comment, 0, 120) : $tk['subject']));
        }
        return opsh_csat_get($db, (int)$tk['id']) ?? [];
    }
    /** Satisfaction in a period: [rated, happy, unhappy, asked, recent unhappy comments]. $where filters opsh_tickets t. */
    function opsh_csat_stats(PDO $db, string $from, string $to): array {
        if (!opsh_svc_has($db)) return ['on' => false];
        $q = $db->prepare("SELECT c.rating, COUNT(*) n FROM opsh_ticket_csat c JOIN opsh_tickets t ON t.id=c.ticket_id WHERE c.rating IS NOT NULL AND c.rated_at BETWEEN ? AND ? GROUP BY c.rating");
        $q->execute([$from . ' 00:00:00', $to . ' 23:59:59']); $r = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        $asked = $db->prepare("SELECT COUNT(*) FROM opsh_ticket_csat c JOIN opsh_tickets t ON t.id=c.ticket_id WHERE c.created_at BETWEEN ? AND ?"); $asked->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $u = $db->prepare("SELECT t.id, t.ticket_number, t.subject, c.comment, c.rated_at, a.display_name AS assignee FROM opsh_ticket_csat c JOIN opsh_tickets t ON t.id=c.ticket_id LEFT JOIN opsh_users a ON a.id=t.assignee_id
                           WHERE c.rating='unhappy' AND c.rated_at BETWEEN ? AND ? ORDER BY c.rated_at DESC LIMIT 10"); $u->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $h = (int)($r['happy'] ?? 0); $n = (int)($r['unhappy'] ?? 0);
        return ['on' => opsh_csat_on($db), 'rated' => $h + $n, 'happy' => $h, 'unhappy' => $n, 'asked' => (int)$asked->fetchColumn(), 'pct' => ($h + $n) ? round(100 * $h / ($h + $n)) : null, 'recent_unhappy' => $u->fetchAll(PDO::FETCH_ASSOC)];
    }

    // ── major incidents ──────────────────────────────────────────────────────────────────────
    function opsh_major_get(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT t.*, u.display_name AS declared_by_name FROM opsh_tickets t LEFT JOIN opsh_users u ON u.id=t.major_by WHERE t.id=? AND t.major=1'); $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
    function opsh_major_open(PDO $db): array {
        if (!opsh_svc_has($db)) return [];
        return $db->query("SELECT id, ticket_number, subject, major_summary, major_at, status FROM opsh_tickets WHERE major=1 AND status IN ('open','in_progress','pending') ORDER BY major_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
    function opsh_major_affected(PDO $db, int $id): array {
        $q = $db->prepare('SELECT t.id, t.ticket_number, t.subject, t.status, t.requester_id, t.contact_name, t.contact_email, u.display_name AS requester_name FROM opsh_tickets t LEFT JOIN opsh_users u ON u.id=t.requester_id WHERE t.major_parent_id=? ORDER BY t.id');
        $q->execute([$id]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    function opsh_major_banner_text(array $t, string $summary): string { return 'Major incident: ' . $summary . ' — IT is working on it (' . $t['ticket_number'] . '). No need to report it again.'; }
    /** Declare: Critical priority (due times recalculated), the alert list told, an optional banner for everyone. */
    function opsh_major_declare(PDO $db, array $t, string $summary, bool $banner, array $by): array {
        $prev = $t;
        $b = ['priority' => 'p1_critical'];
        $sla = function_exists('opsh_ticket_sla_on_update') ? opsh_ticket_sla_on_update($db, $prev, $b) : ['extra' => [], 'notes' => []];
        $set = ['major=1', 'major_at=NOW()', 'major_by=?', 'major_summary=?', 'major_banner=?', "priority='p1_critical'"]; $args = [(int)$by['id'], $summary, $banner ? 1 : 0];
        foreach (['due_date', 'sla_response_due'] as $c) if (array_key_exists($c, $b)) { $set[] = "$c=?"; $args[] = $b[$c]; }
        $args[] = (int)$t['id'];
        $db->prepare('UPDATE opsh_tickets SET ' . implode(',', $set) . ' WHERE id=?')->execute($args);
        try { $db->prepare('DELETE FROM opsh_sla_alerts WHERE ticket_id=?')->execute([(int)$t['id']]); } catch (Throwable $e) {}
        if ($banner) {
            if (opsh_svc_setting($db, 'major_prev_announcement') === null) opsh_svc_set($db, 'major_prev_announcement', json_encode(['text' => (string)opsh_svc_setting($db, 'announcement_text'), 'level' => (string)opsh_svc_setting($db, 'announcement_level')]));
            opsh_svc_set($db, 'announcement_text', opsh_major_banner_text($t, $summary)); opsh_svc_set($db, 'announcement_level', 'critical');
        }
        $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([(int)$t['id'], (int)$by['id'], 'Declared a MAJOR INCIDENT by ' . $by['display_name'] . ': ' . $summary . ($banner ? ' (banner shown to everyone)' : '') . '.']);
        foreach (opsh_svc_alert_ids($db) as $u) if ($u !== (int)$by['id']) opsh_svc_bell($db, $u, 'Major incident declared', $t['ticket_number'] . ': ' . $summary);
        if (!empty($t['assignee_id']) && (int)$t['assignee_id'] !== (int)$by['id']) opsh_svc_bell($db, $t['assignee_id'], 'Major incident declared', $t['ticket_number'] . ': ' . $summary);
        if (function_exists('audit_log')) { try { audit_log('major_declared', 'ticket', (int)$t['id'], (string)$t['ticket_number'], ['priority' => $prev['priority']], ['summary' => $summary, 'priority' => 'p1_critical', 'banner' => $banner ? 'shown to everyone' : 'no']); } catch (Throwable $e) {} }
        return opsh_major_get($db, (int)$t['id']) ?? [];
    }
    /** Put the banner back as it was before the major incident (only if it still shows ours). */
    function opsh_major_banner_off(PDO $db, array $t): void {
        $cur = (string)opsh_svc_setting($db, 'announcement_text');
        if ($cur !== opsh_major_banner_text($t, (string)$t['major_summary'])) return;
        $p = json_decode((string)opsh_svc_setting($db, 'major_prev_announcement'), true);
        opsh_svc_set($db, 'announcement_text', is_array($p) ? (string)($p['text'] ?? '') : '');
        opsh_svc_set($db, 'announcement_level', is_array($p) && ($p['level'] ?? '') !== '' ? (string)$p['level'] : 'info');
        opsh_svc_set($db, 'major_prev_announcement', null);
    }
    /**
     * BT92 (code audit Low): a major incident that is cancelled, merged or deleted stands down — the banner goes back to what
     * it was, the tickets linked to it are unlinked (each told in an internal note) and it is a regular ticket again.
     */
    function opsh_major_stand_down(PDO $db, array $t, string $why, array $by): int {
        if ((int)($t['major_banner'] ?? 0) === 1) opsh_major_banner_off($db, $t);
        $kids = $db->prepare('SELECT id FROM opsh_tickets WHERE major_parent_id=?'); $kids->execute([(int)$t['id']]); $ids = array_map('intval', $kids->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $kid) {
            try { $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([$kid, (int)($by['id'] ?? 0) ?: null, 'Major incident ' . $t['ticket_number'] . ' was ' . $why . '; this ticket is no longer linked to it.']); } catch (Throwable $e) {}
        }
        $db->prepare('UPDATE opsh_tickets SET major_parent_id=NULL WHERE major_parent_id=?')->execute([(int)$t['id']]);
        try { $db->prepare('UPDATE opsh_tickets SET major=0, major_banner=0 WHERE id=?')->execute([(int)$t['id']]); } catch (Throwable $e) {}
        if (function_exists('audit_log')) { try { audit_log('major_stood_down', 'ticket', (int)$t['id'], (string)$t['ticket_number'], ['major' => 'yes', 'summary' => $t['major_summary'] ?? null], ['major' => 'no', 'reason' => $why, 'tickets unlinked' => count($ids)]); } catch (Throwable $e) {} }
        return count($ids);
    }
    /** One update for everyone affected: a public reply (and email) on the major ticket and on every open linked ticket. */
    function opsh_major_update(PDO $db, array $t, string $text, array $by): int {
        $sent = 0; $all = array_merge([$t], opsh_major_affected($db, (int)$t['id']));
        foreach ($all as $k) {
            if (!in_array($k['status'], ['open', 'in_progress', 'pending'], true)) continue;
            $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,0)')->execute([(int)$k['id'], (int)$by['id'], $text]);
            $db->prepare('UPDATE opsh_tickets SET first_response_at=COALESCE(first_response_at,NOW()) WHERE id=?')->execute([(int)$k['id']]);
            [$to, $nm, $ru, $ext] = opsh_svc_requester($db, $k + ['requester_id' => $k['requester_id'] ?? null]);
            if ($to !== '' && ($ext || (int)$ru !== (int)$by['id'])) {
                opsh_email('ticket_reply', $to, ['ticket_number' => $k['ticket_number'], 'subject' => $k['subject'], 'agent_name' => $by['display_name'] ?? '', 'comment' => $text, 'recipient_name' => $nm], ['user_id' => $ru, 'ticket_id' => (int)$k['id']]);
                $sent++;
            }
            if (!$ext && $ru && $ru !== (int)$by['id']) opsh_svc_bell($db, $ru, 'Update on the outage', $k['ticket_number'] . ': ' . mb_substr($text, 0, 80));
        }
        if (function_exists('audit_log')) { try { audit_log('major_update', 'ticket', (int)$t['id'], (string)$t['ticket_number'], null, ['text' => mb_substr($text, 0, 300), 'tickets' => count($all), 'emails' => $sent]); } catch (Throwable $e) {} }
        return $sent;
    }
    /** The major ticket was resolved: its linked open tickets are resolved with the same note (and the Resolved email); the banner goes. */
    function opsh_major_on_resolved(PDO $db, array $t, string $note, array $by): array {
        $done = [];
        foreach (opsh_major_affected($db, (int)$t['id']) as $k) {
            if (!in_array($k['status'], ['open', 'in_progress', 'pending'], true)) continue;
            $pq = $db->prepare('SELECT * FROM opsh_tickets WHERE id=?'); $pq->execute([(int)$k['id']]); $pr = $pq->fetch(PDO::FETCH_ASSOC);
            if (!empty($pr['case_id']) && ($pr['case_close_mode'] ?? '') === 'with') continue;   // it closes with its case
            if (function_exists('opsh_tk_end_pause')) { opsh_tk_end_pause($db, (int)$k['id']); $pq->execute([(int)$k['id']]); $pr = $pq->fetch(PDO::FETCH_ASSOC); }   // BT92: Pending time kept
            $db->prepare("UPDATE opsh_tickets SET status='resolved', resolved_at=NOW(), resolution_notes=?, pending_reason=NULL, first_response_at=COALESCE(first_response_at,NOW()), sla_paused_at=NULL WHERE id=?")->execute([mb_substr($note, 0, 4000), (int)$k['id']]);
            if (function_exists('audit_log')) { try { audit_log('updated', 'ticket', (int)$k['id'], $k['ticket_number'], ['status' => $k['status']], ['status' => 'resolved', 'with_major_incident' => $t['ticket_number']]); } catch (Throwable $e) {} }
            [$to, $nm, $ru, $ext] = opsh_svc_requester($db, $pr);
            if ($to !== '' && ($ext || (int)$ru !== (int)$by['id'])) {
                $vars = ['ticket_number' => $pr['ticket_number'], 'subject' => $pr['subject'], 'status' => opsh_tpl_l10n('status', 'resolved'), 'agent_name' => $by['display_name'] ?? '', 'recipient_name' => $nm];
                if (function_exists('opsh_tk_resolved_vars')) $vars += opsh_tk_resolved_vars($db, $note, 'resolved', (int)$pr['id']);
                opsh_email('ticket_resolved', $to, $vars, ['user_id' => $ru, 'ticket_id' => (int)$pr['id']]);
            }
            $done[] = $k['ticket_number'];
        }
        if ((int)($t['major_banner'] ?? 0) === 1) opsh_major_banner_off($db, $t);
        if (function_exists('audit_log')) { try { audit_log('major_resolved', 'ticket', (int)$t['id'], (string)$t['ticket_number'], null, ['linked tickets resolved' => $done]); } catch (Throwable $e) {} }
        foreach (opsh_svc_alert_ids($db) as $u) if ($u !== (int)$by['id']) opsh_svc_bell($db, $u, 'Major incident resolved — review due', $t['ticket_number'] . ': ' . ($t['major_summary'] ?: $t['subject']));
        return $done;
    }
    function opsh_major_review_get(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT r.*, u.display_name AS written_by_name, c.case_number AS problem_number FROM opsh_major_reviews r LEFT JOIN opsh_users u ON u.id=r.written_by LEFT JOIN opsh_cases c ON c.id=r.problem_case_id WHERE r.ticket_id=?');
        $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
}

/* ── BT82 · Phase 6 part 2: problems and known errors, change details, equipment links ─────────────────────────
 *  - Problem management: a "Problem investigation" case records the root cause and a workaround; the workaround can be
 *    published as a Known Error article in the Knowledge Base (category "Known errors"), updated when it changes and
 *    archived when the problem is resolved. Incident tickets linked to the problem show the workaround to the help desk.
 *  - Change enablement: a "Planned change" case records its type (standard = pre-approved, normal, emergency), risk
 *    (low / medium / high), impact, back-out plan and window. Approval by risk: low — the owner; medium — a Manager
 *    chosen; high — every member of the Change Advisory Board (Settings → General); emergency — one approver chosen,
 *    with a reason; standard — none. Change freezes (Settings → General) refuse normal and standard changes in their
 *    window. Resolving a change asks how it went (successful, with issues, failed and backed out).
 *  - Service configuration: tickets and cases are linked to the Equipment they affect; the equipment shows them.
 */
if (!function_exists('opsh_svc_has2')) {
    function opsh_svc_has2(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('opsh_case_svc','opsh_ci_links')")->fetchColumn() === 2; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    function opsh_chg_labels(): array {
        return ['type' => ['standard' => 'Standard (pre-approved)', 'normal' => 'Normal', 'emergency' => 'Emergency'],
                'risk' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
                'outcome' => ['successful' => 'Successful', 'issues' => 'Successful, with issues', 'failed' => 'Failed and backed out']];
    }
    function opsh_case_svc_get(PDO $db, int $caseId): array {
        $q = $db->prepare('SELECT * FROM opsh_case_svc WHERE case_id=?'); $q->execute([$caseId]); $r = $q->fetch(PDO::FETCH_ASSOC);
        $r = $r ?: ['case_id' => $caseId, 'root_cause' => null, 'workaround' => null, 'known_error' => 0, 'kb_article_id' => null, 'change_type' => null, 'risk' => null, 'impact' => null, 'backout' => null,
                    'window_start' => null, 'window_end' => null, 'emergency_reason' => null, 'approval_state' => 'none', 'approval_tasks' => null, 'outcome' => null, 'outcome_note' => null, 'std_template_id' => null];
        $r['approval_tasks'] = json_decode((string)($r['approval_tasks'] ?? ''), true) ?: [];
        return $r;
    }
    function opsh_case_svc_set(PDO $db, int $caseId, array $f): void {
        if (!$f) return;
        $db->prepare('INSERT IGNORE INTO opsh_case_svc (case_id) VALUES (?)')->execute([$caseId]);
        $set = []; $args = [];
        foreach ($f as $k => $v) { $set[] = "`$k`=?"; $args[] = is_array($v) ? json_encode(array_values($v)) : $v; }
        $args[] = $caseId;
        $db->prepare('UPDATE opsh_case_svc SET ' . implode(',', $set) . ', updated_at=NOW() WHERE case_id=?')->execute($args);
    }
    // ── change freezes ──
    function opsh_chg_freezes(PDO $db): array {
        $j = json_decode((string)opsh_svc_setting($db, 'change_freezes'), true);
        $out = [];
        foreach ((array)$j as $f) if (is_array($f) && !empty($f['from']) && !empty($f['to'])) $out[] = ['name' => (string)($f['name'] ?? 'Change freeze'), 'from' => (string)$f['from'], 'to' => (string)$f['to']];
        usort($out, fn($a, $b) => strcmp($a['from'], $b['from']));
        return $out;
    }
    /** The freeze a window falls in (dates inclusive), or null. */
    function opsh_chg_in_freeze(PDO $db, ?string $start, ?string $end): ?array {
        if (!$start) return null; $s = substr($start, 0, 10); $e = substr($end ?: $start, 0, 10);
        foreach (opsh_chg_freezes($db) as $f) if ($s <= $f['to'] && $e >= $f['from']) return $f;
        return null;
    }
    /** BT86: is the opsh_case_svc.std_template_id column there (after the database update)? */
    function opsh_chg_has_std(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='opsh_case_svc' AND COLUMN_NAME='std_template_id'")->fetchColumn() === 1; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    /** BT86: why a standard-change template cannot make this change standard now, or null. */
    function opsh_chg_std_block(PDO $db, array $svc): ?string {
        $st = opsh_chg_approval_state($db, $svc);
        if (($svc['change_type'] ?? '') !== 'standard' && in_array($st, ['requested', 'approved', 'rejected'], true)) return 'Approval was already asked for this change: withdraw the approval request before applying a standard-change template.';
        if ($fz = opsh_chg_in_freeze($db, $svc['window_start'] ?? null, $svc['window_end'] ?? null)) return 'The change window falls in the change freeze "' . $fz['name'] . '" (' . $fz['from'] . ' to ' . $fz['to'] . '): a standard change cannot be made in it.';
        return null;
    }
    /** BT86: the approval tasks of the change's current round (locked: only the approver answers them). */
    function opsh_chg_round(array $svc): array { return array_map('intval', (array)($svc['approval_tasks'] ?? [])); }
    /** Where the approval of a change stands, from its approval tasks: none | requested | approved | rejected. */
    function opsh_chg_approval_state(PDO $db, array $svc): string {
        if (($svc['change_type'] ?? '') === 'standard') return !empty($svc['std_template_id']) ? 'approved' : 'none';   // BT86: only a standard-change template pre-approves
        $ids = array_map('intval', (array)($svc['approval_tasks'] ?? []));
        if (!$ids) return 'none';
        $q = $db->query('SELECT status, decision FROM opsh_case_tasks WHERE id IN (' . implode(',', $ids) . ')'); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $live = array_filter($rows, fn($r) => $r['status'] !== 'cancelled');
        if (!$live) return 'none';
        foreach ($live as $r) if (($r['decision'] ?? '') === 'rejected') return 'rejected';
        foreach ($live as $r) if (($r['decision'] ?? '') !== 'approved') return 'requested';
        return 'approved';
    }
    /** Who must approve a change at its risk: [user ids] or an error message. */
    function opsh_chg_approvers(PDO $db, array $case, array $svc, int $chosen): array {
        $type = $svc['change_type'] ?: 'normal'; $risk = $svc['risk'] ?: '';
        if ($type === 'standard') return empty($svc['std_template_id']) ? ['error' => 'A standard change comes from a standard-change template. Make it a normal change, or apply the template.'] : ['ids' => [], 'why' => 'A standard change is pre-approved.'];
        if ($type === 'emergency' || $risk === 'medium') {
            if (!$chosen) return ['error' => $type === 'emergency' ? 'Choose who approves the emergency change.' : 'A medium-risk change is approved by a Manager: choose who.'];
            $q = $db->prepare("SELECT u.id, u.display_name, r.slug AS role_slug, r.permissions AS role_perms FROM opsh_users u LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active'"); $q->execute([$chosen]); $u = $q->fetch(PDO::FETCH_ASSOC);
            if (!$u) return ['error' => 'The approver must be an active account.'];
            if ((int)$u['id'] === (int)$case['owner_id']) return ['error' => 'The change owner cannot approve their own ' . ($type === 'emergency' ? 'emergency' : 'medium-risk') . ' change: choose someone else.'];   // BT86
            if (!function_exists('opsh_cs_can') || !opsh_cs_can($db, $u, 'create')) return ['error' => $u['display_name'] . ' cannot approve changes: choose a Manager (someone who can create cases).'];
            return ['ids' => [(int)$u['id']]];
        }
        if ($risk === 'high') {
            $cab = array_values(array_unique(array_map('intval', (array)(opsh_cs_people($db)['cab'] ?? []))));
            $act = $cab ? $db->query("SELECT id FROM opsh_users WHERE status='active' AND id IN (" . implode(',', $cab) . ")")->fetchAll(PDO::FETCH_COLUMN) : [];
            if (!$act) return ['error' => 'A high-risk change is approved by the Change Advisory Board: a Super Admin names its members in Settings → General → Cases & Projects.'];
            return ['ids' => array_map('intval', $act)];
        }
        if ($risk === 'low') return ['ids' => [(int)$case['owner_id']]];
        return ['error' => 'Set the change\'s risk first (low, medium or high).'];
    }

    // ── known errors (Knowledge Base) ──
    function opsh_prob_kb_html(array $case, array $svc, bool $fixed = false): string {
        $e = fn($s) => nl2br(htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'), false);
        return '<p><strong>Known error</strong> — investigated as ' . $e($case['case_number']) . '.' . ($fixed ? ' <strong>Fixed.</strong>' : ' A permanent fix is being worked on.') . '</p>'
             . '<h3>Symptoms</h3><p>' . $e($case['description'] ?: $case['title']) . '</p>'
             . '<h3>Workaround</h3><p>' . $e($svc['workaround']) . '</p>'
             . (!empty($svc['root_cause']) ? '<h3>Cause</h3><p>' . $e($svc['root_cause']) . '</p>' : '');
    }
    /** Publish (or update) the known-error article; returns its id. */
    function opsh_prob_publish(PDO $db, array $case, array $svc, int $byId): int {
        $cat = $db->query("SELECT id FROM opsh_categories WHERE kind='kb' AND slug='known-errors'")->fetchColumn() ?: null;
        $title = mb_substr('Known error: ' . $case['title'], 0, 255);
        $html = opsh_prob_kb_html($case, $svc);
        $aid = (int)($svc['kb_article_id'] ?? 0);
        if ($aid && $db->query('SELECT COUNT(*) FROM opsh_kb_articles WHERE id=' . $aid)->fetchColumn()) {
            $db->prepare("UPDATE opsh_kb_articles SET title=?, content=?, status='published', updated_at=NOW() WHERE id=?")->execute([$title, $html, $aid]);
        } else {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
            $db->prepare("INSERT INTO opsh_kb_articles (title,slug,content,category_id,author_id,status,created_at) VALUES (?,?,?,?,?,'published',NOW())")->execute([$title, $slug, $html, $cat, $byId]);
            $aid = (int)$db->lastInsertId();
        }
        opsh_case_svc_set($db, (int)$case['id'], ['known_error' => 1, 'kb_article_id' => $aid]);
        return $aid;
    }
    /** The problem is fixed (or the known error withdrawn): the article is archived, kept for the record. */
    function opsh_prob_retire(PDO $db, array $case, array $svc, bool $fixed): void {
        $aid = (int)($svc['kb_article_id'] ?? 0);
        if ($aid) $db->prepare("UPDATE opsh_kb_articles SET status='archived', content=?, updated_at=NOW() WHERE id=?")->execute([opsh_prob_kb_html($case, $svc, $fixed), $aid]);
        opsh_case_svc_set($db, (int)$case['id'], ['known_error' => 0]);
    }

    // ── equipment links (configuration items) ──
    function opsh_ci_list(PDO $db, string $type, int $id): array {
        if (!opsh_svc_has2($db)) return [];
        $q = $db->prepare('SELECT a.id, a.asset_tag, a.category, a.make, a.model, a.status, u.display_name AS holder FROM opsh_ci_links l JOIN opsh_assets a ON a.id=l.asset_id LEFT JOIN opsh_users u ON u.id=a.assigned_to WHERE l.entity_type=? AND l.entity_id=? ORDER BY a.asset_tag');
        $q->execute([$type, $id]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    function opsh_ci_search(PDO $db, string $q): array {
        $like = '%' . $q . '%';
        $s = $db->prepare("SELECT a.id, a.asset_tag, a.category, a.make, a.model, a.status, u.display_name AS holder FROM opsh_assets a LEFT JOIN opsh_users u ON u.id=a.assigned_to
                           WHERE a.status<>'retired' AND (?='' OR a.asset_tag LIKE ? OR a.make LIKE ? OR a.model LIKE ? OR a.serial_number LIKE ? OR u.display_name LIKE ?) ORDER BY a.asset_tag LIMIT 20");
        $s->execute([$q, $like, $like, $like, $like, $like]); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Link / unlink; returns the asset row (or throws InvalidArgumentException). $label names the ticket or case for the Audit Log. */
    function opsh_ci_link(PDO $db, string $type, int $id, int $assetId, bool $on, int $byId, string $label): array {
        $q = $db->prepare('SELECT * FROM opsh_assets WHERE id=?'); $q->execute([$assetId]); $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new InvalidArgumentException('Equipment not found.');
        if ($on) { $n = $db->prepare('INSERT IGNORE INTO opsh_ci_links (entity_type, entity_id, asset_id, linked_by, linked_at) VALUES (?,?,?,?,NOW())'); $n->execute([$type, $id, $assetId, $byId]); }
        else { $n = $db->prepare('DELETE FROM opsh_ci_links WHERE entity_type=? AND entity_id=? AND asset_id=?'); $n->execute([$type, $id, $assetId]); }
        if ($n->rowCount() && function_exists('audit_log')) { try {
            audit_log($on ? 'ci_linked' : 'ci_unlinked', $type, $id, $label, $on ? null : ['equipment' => $a['asset_tag']], $on ? ['equipment' => $a['asset_tag'] . ' · ' . trim($a['make'] . ' ' . $a['model'])] : null);
            audit_log($on ? 'ci_linked' : 'ci_unlinked', 'asset', $assetId, $a['asset_tag'], $on ? null : [$type => $label], $on ? [$type => $label] : null);
        } catch (Throwable $e) {} }
        return $a;
    }
    /** What an asset was involved in: tickets and cases (problems, changes) — only what this person may see. */
    function opsh_ci_for_asset(PDO $db, int $assetId, array $user): array {
        if (!opsh_svc_has2($db)) return ['tickets' => [], 'cases' => [], 'hidden' => 0];
        $tq = $db->prepare("SELECT t.id, t.ticket_number AS number, t.subject AS title, t.status, t.ticket_type, t.major, t.requester_id, t.assignee_id, t.backup_id, l.linked_at FROM opsh_ci_links l JOIN opsh_tickets t ON t.id=l.entity_id WHERE l.entity_type='ticket' AND l.asset_id=? ORDER BY l.linked_at DESC");
        $tq->execute([$assetId]); $tks = $tq->fetchAll(PDO::FETCH_ASSOC);
        $cq = $db->prepare("SELECT c.*, l.linked_at FROM opsh_ci_links l JOIN opsh_cases c ON c.id=l.entity_id WHERE l.entity_type='case' AND l.asset_id=? ORDER BY l.linked_at DESC");
        $cq->execute([$assetId]); $css = $cq->fetchAll(PDO::FETCH_ASSOC);
        $uid = (int)$user['id']; $allT = function_exists('opsh_can') && opsh_can($user, 'tickets', 'all'); $hidden = 0; $outT = []; $outC = [];
        foreach ($tks as $t) { if ($allT || in_array($uid, [(int)$t['requester_id'], (int)$t['assignee_id'], (int)$t['backup_id']], true)) $outT[] = ['id' => (int)$t['id'], 'number' => $t['number'], 'title' => $t['title'], 'status' => $t['status'], 'type' => $t['ticket_type'], 'major' => (int)$t['major'], 'linked_at' => $t['linked_at']]; else $hidden++; }
        foreach ($css as $c) { $role = function_exists('opsh_cs_role') ? opsh_cs_role($db, $c, $user) : null;
            if ($role && $role !== 'requester') $outC[] = ['id' => (int)$c['id'], 'number' => $c['case_number'], 'title' => $c['title'], 'status' => $c['status'], 'kind' => $c['kind'] ?? 'case', 'origin' => $c['origin'] ?? null, 'linked_at' => $c['linked_at']]; else $hidden++; }
        return ['tickets' => $outT, 'cases' => $outC, 'hidden' => $hidden];
    }
}
