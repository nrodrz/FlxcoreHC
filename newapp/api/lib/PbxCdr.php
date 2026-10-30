<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT152 — the call records of the office phone system (Grandstream UCM63xx), kept here for reports, and its missed
 * calls as tickets (PhoneTickets.php, the same rules as CallTrackingMetrics and JustCall).
 *
 *   Reading  every five minutes the app asks the PBX for the calls since the last reading (3 hours of overlap, as a
 *            record is written when the call ends; each call is kept once by its record id). The first reading goes
 *            back 90 days, in windows of 3 days and at most 12 requests a run, and goes on at the next runs. Through the main API ("cdrapi" with the session); on a firmware
 *            that does not give call records there, through the older CDR service (port 8443, its own user) when its user
 *            is entered on the connection. Nothing is pushed by the PBX: no address has to be opened towards the app.
 *   Kept     itops_pbx_cdr: when, how long, answered or not, direction (inbound = through a trunk to the office,
 *            outbound = out through a trunk, internal), the extension involved, the other number, the trunk, the name of
 *            any recording (the recording itself stays on the PBX). Removed after the days set (default 400).
 *   Times    the PBX's local time, read as the installation's time zone (Settings → General). Keep both the same.
 */
require_once __DIR__ . '/SystemConnect.php';
require_once __DIR__ . '/Ucm.php';
require_once __DIR__ . '/PhoneTickets.php';

if (!function_exists('itops_pbx_cdr_table')) {
    if (!defined('ITOPS_PBX_CDR_BACKFILL_DAYS')) define('ITOPS_PBX_CDR_BACKFILL_DAYS', 90);

    function itops_pbx_cdr_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_pbx_cdr (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rec_key VARCHAR(80) NOT NULL, started_at DATETIME NULL, answered_at DATETIME NULL,
            ended_at DATETIME NULL, duration INT NOT NULL DEFAULT 0, billsec INT NOT NULL DEFAULT 0, disposition VARCHAR(20) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT '',
            direction VARCHAR(10) NOT NULL DEFAULT '', src VARCHAR(64) NOT NULL DEFAULT '', dst VARCHAR(64) NOT NULL DEFAULT '', caller_name VARCHAR(160) NOT NULL DEFAULT '',
            ext VARCHAR(20) NOT NULL DEFAULT '', other_number VARCHAR(64) NOT NULL DEFAULT '', trunk VARCHAR(80) NOT NULL DEFAULT '', action_type VARCHAR(40) NOT NULL DEFAULT '',
            recording VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
            UNIQUE KEY uq_pbx_cdr (rec_key), INDEX idx_pbx_cdr_start (started_at), INDEX idx_pbx_cdr_ext (ext), INDEX idx_pbx_cdr_other (other_number))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }








}
