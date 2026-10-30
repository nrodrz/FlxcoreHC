<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the queries that cost the database most (BT163), from MariaDB/MySQL's own statistics
 * (performance_schema.events_statements_summary_by_digest), read through the read-only monitoring user.
 *
 * Needs performance_schema = ON (my.cnf, one restart) and SELECT on performance_schema.* for the monitoring user; the
 * page says which is missing and how to do it. The statistics count since the database started, so a snapshot of the
 * top 200 is kept every hour (30 days): a period's cost is the latest snapshot minus the one at its start. Every
 * value in a query is replaced by ? by the database itself, and masked again here.
 */
require_once __DIR__ . '/ServerPerf.php';
if (!function_exists('itops_dbi_table')) {

    function itops_dbi_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_server_digest (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, schema_name VARCHAR(64) NULL, digest VARCHAR(64) NOT NULL,
            qtext TEXT NULL, cnt BIGINT NOT NULL DEFAULT 0, sum_ms DOUBLE NOT NULL DEFAULT 0, max_ms DOUBLE NOT NULL DEFAULT 0, rows_exam BIGINT NOT NULL DEFAULT 0, rows_sent BIGINT NOT NULL DEFAULT 0,
            no_index BIGINT NOT NULL DEFAULT 0, tmp_disk BIGINT NOT NULL DEFAULT 0, last_seen DATETIME NULL, INDEX idx_dig_at (at), INDEX idx_dig_d (digest, at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

}
