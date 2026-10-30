<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT75 (Phase 1 of "Tickets, Cases & Projects — Process"): the regular ticket from start to finish.
 *
 *  - Pending asks why: waiting on the requester, a vendor, an approval or other (itops_tickets.pending_reason).
 *  - Resolved needs a resolution note (itops_tickets.resolution_notes); it goes to the requester in the Resolved email.
 *  - A public reply from the requester on a Resolved ticket reopens it (In progress), from the app or by email.
 *  - A Resolved ticket closes on its own after N business days with no reply (Settings → SLA & Hours,
 *    sla_autoclose_days, default 3, 0 = never). Business days follow the business-hours days and holidays when
 *    business hours are on, else Monday to Friday.
 *  - Merge: an active ticket is merged into another active ticket of the same requester; its comments and files move
 *    across, it is cancelled with merged_into_id set, and the requester is told once.
 * Every change is written to the Audit Log.
 */
require_once __DIR__ . '/BusinessHours.php';

if (!function_exists('itops_tk_pending_reasons')) {
    function itops_tk_pending_reasons(): array {
        return ['requester' => 'The requester', 'vendor' => 'A vendor', 'approval' => 'An approval', 'other' => 'Something else'];
    }

    /** Does the database have the BT75 columns yet (the update may not have been run)? */
    function itops_tk_has_cols(PDO $db): bool {
        static $has = null;
        if ($has !== null) return $has;
        try { $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_tickets' AND COLUMN_NAME IN ('pending_reason','merged_into_id')")->fetchColumn() === 2; }
        catch (Throwable $e) { $has = false; }
        return $has;
    }

    /** Business days before a resolved ticket closes on its own (0 = never). */
    function itops_tk_close_days(PDO $db): int {
        $v = null;
        try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='sla_autoclose_days'"); $s->execute(); $v = $s->fetchColumn(); } catch (Throwable $e) {}
        if ($v === false || $v === null || $v === '') return 3;
        return max(0, min(30, (int)$v));
    }

    /**
     * The moment a ticket resolved at $fromTs has waited $n business days: the same time of day, $n working days later.
     * Working days = the business-hours days without holidays (business hours on), else Monday to Friday.
     */
    function itops_tk_business_days_after(int $fromTs, int $n, ?array $bh): int {
        $tz = new DateTimeZone($bh['tz'] ?? date_default_timezone_get());
        $days = $bh['days'] ?? [1, 2, 3, 4, 5];
        $hol = $bh['holidays'] ?? [];
        $d = (new DateTime('@' . $fromTs))->setTimezone($tz);
        $left = $n; $guard = 0;
        while ($left > 0 && $guard++ < 400) {
            $d->modify('+1 day');
            if (in_array((int)$d->format('N'), $days, true) && !isset($hol[$d->format('Y-m-d')])) $left--;
        }
        return $d->getTimestamp();
    }

    /** Close resolved tickets whose wait is over. Returns how many closed. */
    function itops_tk_autoclose_run(PDO $db): int {
        $n = itops_tk_close_days($db);
        if ($n <= 0) return 0;
        $bh = function_exists('itops_bh_config') ? itops_bh_config($db) : null;
        $rows = $db->query("SELECT id, ticket_number, resolved_at FROM itops_tickets WHERE status='resolved' AND resolved_at IS NOT NULL ORDER BY resolved_at LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $closed = 0; $now = time();
        foreach ($rows as $r) {
            $from = strtotime((string)$r['resolved_at']); if (!$from) continue;
            if ($now < itops_tk_business_days_after($from, $n, $bh)) continue;
            $u = $db->prepare("UPDATE itops_tickets SET status='closed', closed_at=NOW() WHERE id=? AND status='resolved'");
            $u->execute([(int)$r['id']]);
            if ($u->rowCount() !== 1) continue;
            $closed++;
            if (function_exists('audit_log')) {
                $prevName = $_SERVER['ITOPS_USER_NAME'] ?? null; $prevId = $_SERVER['ITOPS_USER_ID'] ?? null;
                $_SERVER['ITOPS_USER_NAME'] = 'System (auto-close)'; unset($_SERVER['ITOPS_USER_ID']);
                try { audit_log('ticket_auto_closed', 'ticket', (int)$r['id'], (string)$r['ticket_number'], ['status' => 'resolved', 'resolved_at' => $r['resolved_at']],
                    ['status' => 'closed', 'rule' => 'No reply for ' . $n . ' business day' . ($n === 1 ? '' : 's') . ' after Resolved']); } catch (Throwable $e) {}
                if ($prevName === null) unset($_SERVER['ITOPS_USER_NAME']); else $_SERVER['ITOPS_USER_NAME'] = $prevName;
                if ($prevId !== null) $_SERVER['ITOPS_USER_ID'] = $prevId;
            }
        }
        return $closed;
    }

    /**
     * A public reply on a Resolved ticket from its requester (the ITOps requester, or the outside contact's address)
     * reopens it to In progress: resolved time and note cleared, the SLA alerts may fire again. Closed tickets stay
     * closed (the reply is kept and the owner is told, as before). Returns true when it reopened.
     */
    /** Is this author the ticket's requester (the ITOps requester, or the outside contact's address)? */
    function itops_tk_is_requester(array $t, ?int $authorId, string $authorEmail = ''): bool {
        $contact = strtolower(trim((string)($t['contact_email'] ?? '')));
        if ($contact !== '') return $authorEmail !== '' && strtolower(trim($authorEmail)) === $contact;
        if (($t['contact_name'] ?? null) !== null) return false;   // an outside requester without an address
        return $authorId && (int)$authorId === (int)($t['requester_id'] ?? 0);
    }

    /**
     * BT92 (decision 10/17/2026): a public reply from the requester on a Pending ticket ends Pending — the ticket goes back
     * to In progress and the SLA clock runs again (due times moved on by the working time spent in Pending). Returns true
     * when it resumed.
     */
    function itops_tk_resume_on_reply(PDO $db, int $tid, ?int $authorId, string $authorEmail = ''): bool {
        $q = $db->prepare('SELECT * FROM itops_tickets WHERE id=?'); $q->execute([$tid]); $t = $q->fetch(PDO::FETCH_ASSOC);
        if (!$t || ($t['status'] ?? '') !== 'pending' || !itops_tk_is_requester($t, $authorId, $authorEmail)) return false;
        $body = ['status' => 'in_progress'];
        $sla = itops_ticket_sla_on_update($db, $t, $body);
        $u = $db->prepare("UPDATE itops_tickets SET status='in_progress'" . (itops_tk_has_cols($db) ? ', pending_reason=NULL' : '') . " WHERE id=? AND status='pending'");
        $u->execute([$tid]);
        if ($u->rowCount() !== 1) return false;
        foreach (['due_date', 'sla_response_due'] as $c) if (array_key_exists($c, $body)) { try { $db->prepare("UPDATE itops_tickets SET `$c`=? WHERE id=?")->execute([$body[$c], $tid]); } catch (Throwable $e) {} }
        foreach ($sla['extra'] as $c => $v) { try { $db->prepare("UPDATE itops_tickets SET `$c`=? WHERE id=?")->execute([$v, $tid]); } catch (Throwable $e) {} }
        if ($sla['clear_alerts']) { try { $db->prepare('DELETE FROM itops_sla_alerts WHERE ticket_id=?')->execute([$tid]); } catch (Throwable $e) {} }
        if (function_exists('audit_log')) {
            try { audit_log('ticket_resumed', 'ticket', $tid, (string)$t['ticket_number'], ['status' => 'pending', 'pending_reason' => $t['pending_reason'] ?? null, 'due_date' => $t['due_date']],
                ['status' => 'in_progress', 'reason' => 'The requester replied', 'due_date' => $body['due_date'] ?? $t['due_date']]); } catch (Throwable $e) {}
        }
        foreach (array_unique(array_filter([(int)($t['assignee_id'] ?? 0), (int)($t['backup_id'] ?? 0)])) as $uid) {
            try { $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')
                     ->execute([$uid, 'Ticket back in progress', $t['ticket_number'] . ': the requester replied while it was Pending', 'ticket', 'tickets']); } catch (Throwable $e) {}
        }
        return true;
    }

    /** A public reply from someone: reopens a Resolved ticket, or ends Pending, when it comes from the requester. */
    function itops_tk_on_requester_reply(PDO $db, int $tid, ?int $authorId, string $authorEmail = ''): void {
        if (!itops_tk_reopen_on_reply($db, $tid, $authorId, $authorEmail)) itops_tk_resume_on_reply($db, $tid, $authorId, $authorEmail);
    }

    /**
     * BT92 (code audit Low): a Pending ticket closed by something else (its major incident or its case) keeps its paused
     * time: the pause is ended first, so the due times move on by the working time spent in Pending.
     */
    function itops_tk_end_pause(PDO $db, int $tid): void {
        try {
            $q = $db->prepare('SELECT * FROM itops_tickets WHERE id=?'); $q->execute([$tid]); $t = $q->fetch(PDO::FETCH_ASSOC);
            if (!$t || ($t['status'] ?? '') !== 'pending' || empty($t['sla_paused_at'])) return;
            $body = ['status' => 'in_progress'];
            $sla = itops_ticket_sla_on_update($db, $t, $body);
            foreach (['due_date', 'sla_response_due'] as $c) if (array_key_exists($c, $body)) $db->prepare("UPDATE itops_tickets SET `$c`=? WHERE id=?")->execute([$body[$c], $tid]);
            foreach ($sla['extra'] as $c => $v) $db->prepare("UPDATE itops_tickets SET `$c`=? WHERE id=?")->execute([$v, $tid]);
        } catch (Throwable $e) {}
    }

    function itops_tk_reopen_on_reply(PDO $db, int $tid, ?int $authorId, string $authorEmail = ''): bool {
        $q = $db->prepare('SELECT * FROM itops_tickets WHERE id=?'); $q->execute([$tid]); $t = $q->fetch(PDO::FETCH_ASSOC);
        if (!$t || ($t['status'] ?? '') !== 'resolved') return false;
        if (!itops_tk_is_requester($t, $authorId, $authorEmail)) return false;
        $body = ['status' => 'in_progress'];
        $sla = itops_ticket_sla_on_update($db, $t, $body);
        $u = $db->prepare("UPDATE itops_tickets SET status='in_progress', resolved_at=NULL, closed_at=NULL, resolution_notes=NULL WHERE id=? AND status='resolved'");
        $u->execute([$tid]);
        if ($u->rowCount() !== 1) return false;
        foreach (['due_date', 'sla_response_due'] as $c) if (array_key_exists($c, $body)) { try { $db->prepare("UPDATE itops_tickets SET `$c`=? WHERE id=?")->execute([$body[$c], $tid]); } catch (Throwable $e) {} }
        try { $db->prepare('DELETE FROM itops_sla_alerts WHERE ticket_id=?')->execute([$tid]); } catch (Throwable $e) {}
        if (function_exists('audit_log')) {
            try { audit_log('ticket_reopened', 'ticket', $tid, (string)$t['ticket_number'], ['status' => 'resolved', 'resolution_note' => (string)($t['resolution_notes'] ?? '')],
                ['status' => 'in_progress', 'reason' => 'The requester replied']); } catch (Throwable $e) {}
        }
        foreach (array_unique(array_filter([(int)($t['assignee_id'] ?? 0), (int)($t['backup_id'] ?? 0)])) as $uid) {
            try { $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')
                     ->execute([$uid, 'Ticket reopened', $t['ticket_number'] . ': the requester replied after it was resolved', 'ticket', 'tickets']); } catch (Throwable $e) {}
        }
        if (!empty($t['case_id'])) { require_once __DIR__ . '/Cases.php'; itops_cs_on_ticket_reopened($db, $tid); }   // BT76: its case reopens too
        return true;
    }

    /**
     * Merge ticket $srcId into ticket $dstId, done by $user. Throws InvalidArgumentException with a sentence for the
     * person when it is not allowed. Returns ['source' => number, 'target' => number, 'comments' => n, 'files' => n].
     */
    function itops_tk_merge(PDO $db, int $srcId, int $dstId, array $user): array {
        if ($srcId === $dstId) throw new InvalidArgumentException('A ticket cannot be merged into itself.');
        $get = $db->prepare('SELECT * FROM itops_tickets WHERE id=?');
        $get->execute([$srcId]); $s = $get->fetch(PDO::FETCH_ASSOC);
        $get->execute([$dstId]); $d = $get->fetch(PDO::FETCH_ASSOC);
        if (!$s || !$d) throw new OutOfBoundsException('Ticket not found');
        $active = ['open', 'in_progress', 'pending'];
        if (!in_array($s['status'], $active, true)) throw new InvalidArgumentException($s['ticket_number'] . ' is ' . str_replace('_', ' ', $s['status']) . ': only an open ticket can be merged.');
        if (!in_array($d['status'], $active, true)) throw new InvalidArgumentException($d['ticket_number'] . ' is ' . str_replace('_', ' ', $d['status']) . ': merge into a ticket that is still open.');
        $who = function (array $t): string {
            $c = strtolower(trim((string)($t['contact_email'] ?? '')));
            if ($c !== '' || ($t['contact_name'] ?? null) !== null) return 'x:' . $c;
            return 'u:' . (int)$t['requester_id'];
        };
        if (!empty($s['case_id'])) throw new InvalidArgumentException($s['ticket_number'] . ' belongs to a case: unlink it from the case first, or merge the other ticket into it.');   // BT76
        if ($who($s) !== $who($d)) throw new InvalidArgumentException('Only tickets of the same requester can be merged. Link them to a case instead when different people asked.');
        $db->beginTransaction();
        try {
            // BT92 (code audit Low): atomic — both tickets are locked and checked again inside the transaction, so two merges
            // (or a merge and a status change) at the same moment cannot both pass.
            $lk = $db->prepare('SELECT id, status, case_id, merged_into_id FROM itops_tickets WHERE id IN (?,?) ORDER BY id FOR UPDATE'); $lk->execute([$srcId, $dstId]);
            $now = []; foreach ($lk->fetchAll(PDO::FETCH_ASSOC) as $r) $now[(int)$r['id']] = $r;
            if (count($now) !== 2) throw new OutOfBoundsException('Ticket not found');
            foreach ([$srcId, $dstId] as $x) if (!in_array($now[$x]['status'], $active, true) || !empty($now[$x]['merged_into_id']))
                throw new InvalidArgumentException(($x === $srcId ? $s : $d)['ticket_number'] . ' changed a moment ago (it is ' . str_replace('_', ' ', $now[$x]['status']) . ' now). Reload and try again.');
            if (!empty($now[$srcId]['case_id'])) throw new InvalidArgumentException($s['ticket_number'] . ' belongs to a case now.');
            $mc = $db->prepare('UPDATE itops_ticket_comments SET ticket_id=? WHERE ticket_id=?'); $mc->execute([$dstId, $srcId]); $nc = $mc->rowCount();
            $ma = $db->prepare('UPDATE itops_ticket_attachments SET ticket_id=? WHERE ticket_id=?'); $ma->execute([$dstId, $srcId]); $na = $ma->rowCount();
            $note = 'Merged from ' . $s['ticket_number'] . ' (' . $s['subject'] . ') by ' . ($user['display_name'] ?? '') . '.'
                  . (trim((string)$s['description']) !== '' ? "\n\nIts request:\n" . $s['description'] : '')
                  . ($nc || $na ? "\n\nIts " . ($nc ? $nc . ' comment' . ($nc === 1 ? '' : 's') : '') . ($nc && $na ? ' and ' : '') . ($na ? $na . ' file' . ($na === 1 ? '' : 's') : '') . ' now appear on this ticket.' : '');
            $db->prepare('INSERT INTO itops_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([$dstId, (int)$user['id'], $note]);
            $db->prepare('INSERT INTO itops_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,0)')
               ->execute([$srcId, (int)$user['id'], 'This request was merged into ' . $d['ticket_number'] . ', where it continues. Its messages and files are there now.']);
            $up = $db->prepare("UPDATE itops_tickets SET status='cancelled', merged_into_id=?, closed_at=NOW(), pending_reason=NULL WHERE id=? AND status IN ('open','in_progress','pending') AND merged_into_id IS NULL");
            $up->execute([$dstId, $srcId]);
            if ($up->rowCount() !== 1) throw new InvalidArgumentException($s['ticket_number'] . ' changed a moment ago. Reload and try again.');
            try { $db->prepare('DELETE FROM itops_sla_alerts WHERE ticket_id=?')->execute([$srcId]); } catch (Throwable $e) {}
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        if ((int)($s['major'] ?? 0) === 1 || !empty($s['major_parent_id'])) {   // BT92: a merged major incident stands down; a merged linked ticket leaves its major
            require_once __DIR__ . '/ServiceMgmt.php';
            if ((int)($s['major'] ?? 0) === 1 && function_exists('itops_major_stand_down')) itops_major_stand_down($db, $s, 'merged into ' . $d['ticket_number'], $user);
            if (!empty($s['major_parent_id'])) $db->prepare('UPDATE itops_tickets SET major_parent_id=NULL WHERE id=?')->execute([$srcId]);
        }
        if (function_exists('audit_log')) {
            audit_log('ticket_merged', 'ticket', $srcId, (string)$s['ticket_number'], ['status' => $s['status']],
                ['status' => 'cancelled', 'merged_into' => $d['ticket_number'], 'comments_moved' => $nc, 'files_moved' => $na]);
            audit_log('ticket_merged_in', 'ticket', $dstId, (string)$d['ticket_number'], null,   // BT92: the target's own history says what came in
                ['merged_from' => $s['ticket_number'], 'subject' => (string)$s['subject'], 'comments_moved' => $nc, 'files_moved' => $na]);
        }
        // The requester is told once (not when they merged it themselves).
        try {
            require_once __DIR__ . '/EmailTemplates.php';
            $ext = !empty($s['contact_email']) || ($s['contact_name'] ?? null) !== null;
            $to = ''; $name = ''; $uid = null;
            if ($ext) { $to = (string)($s['contact_email'] ?? ''); $name = (string)($s['contact_name'] ?? ''); }
            else { $uq = $db->prepare('SELECT email, display_name FROM itops_users WHERE id=?'); $uq->execute([(int)$s['requester_id']]); $ur = $uq->fetch(PDO::FETCH_ASSOC) ?: [];
                   $to = (string)($ur['email'] ?? ''); $name = (string)($ur['display_name'] ?? ''); $uid = (int)$s['requester_id']; }
            $self = $ext ? strcasecmp($to, (string)($user['email'] ?? '')) === 0 : (int)$s['requester_id'] === (int)$user['id'];
            if ($to !== '' && !$self && function_exists('itops_email')) {
                itops_email('ticket_status', $to, ['ticket_number' => $s['ticket_number'], 'subject' => $s['subject'] ?? '',
                    'status' => ['en' => 'merged into ' . $d['ticket_number'], 'es' => 'unida a ' . $d['ticket_number']],
                    'agent_name' => $user['display_name'] ?? '', 'recipient_name' => $name], ['user_id' => $uid, 'ticket_id' => $srcId]);
            }
        } catch (Throwable $e) { /* the merge stands; the email is best effort */ }
        return ['source' => $s['ticket_number'], 'target' => $d['ticket_number'], 'comments' => $nc, 'files' => $na];
    }

    /** The "What we did" line and the reply line of the Resolved / closed email, per language. */
    function itops_tk_resolved_vars(PDO $db, string $note, string $status, int $ticketId = 0): array {
        $note = trim($note);
        $n = itops_tk_close_days($db);
        $out = ['resolution_note' => $note === '' ? '' : ['en' => 'What we did: ' . $note, 'es' => 'Lo que hicimos: ' . $note]];
        if ($status === 'resolved') {
            $out['reopen_line'] = $n > 0
                ? ['en' => 'Not fixed? Reply to this email within ' . $n . ' business day' . ($n === 1 ? '' : 's') . ' and the ticket reopens. After that it closes on its own.',
                   'es' => '¿No quedó resuelto? Responda a este correo dentro de ' . $n . ' día' . ($n === 1 ? '' : 's') . ' hábil' . ($n === 1 ? '' : 'es') . ' y el ticket se reabre. Después se cierra solo.']
                : ['en' => 'Not fixed? Reply to this email and the ticket reopens.', 'es' => '¿No quedó resuelto? Responda a este correo y el ticket se reabre.'];
        } else {
            $out['reopen_line'] = ['en' => 'Need more help? Reply to this email or open a new request.', 'es' => '¿Necesita más ayuda? Responda a este correo o abra una nueva solicitud.'];
        }
        // BT81: "How did we do?" — two links to rate the ticket (empty when ratings are off)
        $out['csat_line'] = ['en' => '', 'es' => ''];
        if ($ticketId) { try { require_once __DIR__ . '/ServiceMgmt.php'; $out['csat_line'] = itops_csat_line($db, $ticketId); } catch (Throwable $e) {} }
        return $out;
    }
}
