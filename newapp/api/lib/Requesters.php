<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — link tickets from outside (email, public page, forms) to the person's OpsHub account.
 *
 * Tickets from someone without an account are filed under the fallback account (requester_id = 1) with the
 * sender in contact_email / contact_name (shown as "EXT"). As soon as that address belongs to an OpsHub user —
 * e.g. they sign in with Microsoft 365 for the first time, or an admin creates / imports the account — the
 * tickets are handed over: requester_id = the user, contact fields cleared. The user then sees them under
 * Tickets ("Opened by me") and gets updates like any other requester.
 */
if (!function_exists('opsh_claim_tickets')) {
    function opsh_claim_tickets(PDO $db, int $userId, string $email): int {
        $email = strtolower(trim($email));
        if ($userId <= 1 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
        try {
            $st = $db->prepare('UPDATE opsh_tickets SET requester_id=?, contact_email=NULL, contact_name=NULL
                                 WHERE contact_email=? AND (requester_id=1 OR requester_id IS NULL)');
            $st->execute([$userId, $email]);
            $n = $st->rowCount();
            if ($n > 0 && function_exists('audit_log')) audit_log('tickets_claimed', 'user', $userId, $email . ' · ' . $n . ' ticket(s) linked to the account');
            return $n;
        } catch (Throwable $e) { return 0; }   // older DB without contact_email: nothing to claim
    }
}

if (!function_exists('opsh_ticket_notify_assignee')) {
    /** A public reply was added by someone other than the assignee (the requester in the app or by email, or a
     *  colleague): ring the assignee's bell and send the "Requester replied" email (template ticket_requester_reply). */
    function opsh_ticket_notify_assignee(PDO $db, int $ticketId, int $authorId, string $authorName, string $comment): void {
        // BT17: the owner AND the backup are told (the backup receives everything the owner receives).
        try {
            require_once __DIR__ . '/TicketRouting.php';
            $st = $db->prepare('SELECT t.ticket_number, t.subject FROM opsh_tickets t WHERE t.id=?');
            $st->execute([$ticketId]); $t = $st->fetch(PDO::FETCH_ASSOC); if (!$t) return;
            foreach (opsh_tr_workers($db, $ticketId) as $uid) {
                if ($uid === $authorId) continue;
                $u = opsh_tr_user($db, $uid); if (!$u) continue;
                $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')
                   ->execute([$uid, 'New reply on ' . $t['ticket_number'], mb_substr(($authorName !== '' ? $authorName . ': ' : '') . $comment, 0, 160), 'comment', 'tickets']);
                if (!empty($u['email'])) {
                    if (!function_exists('opsh_email')) require_once __DIR__ . '/EmailTemplates.php';
                    opsh_email('ticket_requester_reply', $u['email'], ['ticket_number' => $t['ticket_number'], 'subject' => (string)$t['subject'],
                        'author_name' => $authorName !== '' ? $authorName : 'The requester', 'comment' => mb_substr($comment, 0, 4000),
                        'recipient_name' => (string)$u['display_name']], ['user_id' => $uid, 'ticket_id' => $ticketId]);
                }
            }
        } catch (Throwable $e) { /* notifications are best-effort */ }
    }
}
