<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT52: ticket numbers (TK-YYYY-NNNN), one helper for every channel (in-app, Help Request forms, the public
 * request page, email-to-ticket).
 *
 * Before BT52 each channel took MAX(number)+1 and inserted. Two requests at the same moment took the same number; the
 * UNIQUE key refused the second, and that person saw "Something went wrong" — the request was lost. And when the
 * newest ticket was deleted, its number was given again to the next ticket, so old notifications and emails named
 * another ticket.
 *
 * Now: the next number is the higher of the newest number in the table and the last number ever issued (setting
 * "ticket_seq_YYYY"), plus one; a number taken meanwhile is detected by the UNIQUE key and the next one is tried.
 */
if (!function_exists('itops_ticket_create_numbered')) {

    function itops_ticket_number_dup(Throwable $e): bool {
        return $e instanceof PDOException && (string)$e->getCode() === '23000' && stripos($e->getMessage(), 'ticket_number') !== false;
    }

    /**
     * Calls $insert($ticketNumber) until it succeeds with a number nobody else holds (up to 12 tries). $insert must do
     * the INSERT and return what the caller needs (it is returned as is). Any other error is thrown as before.
     */
    function itops_ticket_create_numbered(PDO $db, callable $insert) {
        $year = date('Y'); $key = 'ticket_seq_' . $year;
        $issued = 0;
        try { $st = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $st->execute([$key]); $issued = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        $last = 0;
        try { $last = (int)$db->query("SELECT MAX(CAST(SUBSTRING(ticket_number,9) AS UNSIGNED)) FROM itops_tickets WHERE ticket_number LIKE 'TK-{$year}-%'")->fetchColumn(); } catch (Throwable $e) {}
        $n = max($issued, $last) + 1;
        for ($try = 0; $try < 12; $try++, $n++) {
            $tn = sprintf('TK-%s-%04d', $year, $n);
            try { $out = $insert($tn); }
            catch (Throwable $e) {
                if (!itops_ticket_number_dup($e)) throw $e;
                if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $e2) {} }
                continue;   // somebody took that number a moment ago: the next one
            }
            try {   // remember the highest number issued, so a deleted ticket's number is never given again
                $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=IF(CAST(sval AS UNSIGNED) < CAST(VALUES(sval) AS UNSIGNED), VALUES(sval), sval)")
                   ->execute([$key, (string)$n]);
            } catch (Throwable $e) {}
            return $out;
        }
        throw new RuntimeException('Could not give the ticket a number: too many tickets were created at the same moment. Please try again.');
    }
}
