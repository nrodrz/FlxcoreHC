<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the error logs of every cPanel account on this server (BT162)
 *
 * Read through Server Guardian's WHM connection (WHM API 1 uapi_cpanel: Fileman::list_files, Fileman::get_file_content,
 * Stats::get_site_errors), every 10 minutes by the scheduled jobs, at most 10 accounts a run (all of them in turn).
 * Read-only. Only new lines are counted (by their time); the first reading of a file takes its last 7 days. Lines are
 * grouped by kind (level + the message with its numbers and quoted values replaced) per account, file and hour, kept
 * 30 days. Passwords, keys and tokens in a line are masked before it is kept; a file over 8 MB is not read (noted).
 */
if (!function_exists('itops_errlog_table')) {

    function itops_errlog_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_server_errlog (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account VARCHAR(64) NOT NULL, source VARCHAR(190) NOT NULL,
            sig CHAR(40) NOT NULL, level VARCHAR(12) NOT NULL, message TEXT NULL, place VARCHAR(255) NULL, hour DATETIME NOT NULL, n INT NOT NULL DEFAULT 0,
            last_at DATETIME NOT NULL, samples TEXT NULL, UNIQUE KEY uq_errlog (account, source, sig, hour), INDEX idx_errlog_hour (hour), INDEX idx_errlog_last (last_at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }




}
