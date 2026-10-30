<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT148 — missed calls become tickets. Shared by every phone system that can be read (CallTrackingMetrics BT148,
 * JustCall BT150; the office PBX later uses the same table, settings and rules).
 *
 *   settings  itops_settings.phone_tickets = {system key: {on, lines: [10 digits], statuses: [...], category_id,
 *             priority, window_min, by, last_poll, last_result}}
 *   table     itops_phone_calls: every missed call looked at, once (UNIQUE system + call id), with what was done:
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
if (!function_exists('itops_ph_cfg')) {



    function itops_ph_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_phone_calls (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, system_key VARCHAR(20) NOT NULL, call_id VARCHAR(64) NOT NULL,
            called_at DATETIME NULL, caller_number VARCHAR(40) NOT NULL DEFAULT '', caller_digits VARCHAR(15) NOT NULL DEFAULT '', caller_name VARCHAR(160) NOT NULL DEFAULT '',
            line_number VARCHAR(40) NOT NULL DEFAULT '', line_label VARCHAR(160) NOT NULL DEFAULT '', status VARCHAR(30) NOT NULL DEFAULT '', action VARCHAR(10) NOT NULL DEFAULT '',
            reason VARCHAR(160) NOT NULL DEFAULT '', ticket_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_phone_call (system_key, call_id), INDEX idx_phone_caller (system_key, caller_digits), INDEX idx_phone_ticket (ticket_id), INDEX idx_phone_at (called_at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }







}
