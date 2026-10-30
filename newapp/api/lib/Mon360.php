<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT125: 360 Monitoring (an outside monitoring service), read only, beside the app's own Uptime checks.
 *
 *   API      https://api.monitoring360.io/v1/ (Business or Enterprise plan). The key goes in the Authorization header
 *            ("Bearer …", as the service's own command-line client sends it), never in the address, so it is never in a
 *            log. 1,500 requests an hour per key: lists are cached for two minutes and the alert check spends at most
 *            ITOPS_M360_BUDGET requests a run.
 *   Lists    GET servers → {servers: [...]}, GET monitors → {monitors: [...]}, page/perpage.
 *   Alerts   GET server/{id}/notifications and monitor/{id}/notifications?start&end (Unix seconds) → {data: [{start, end,
 *            status, summary}]}. Their status words are not published, so an alert counts as over once it has an end
 *            time in the past.
 *   Tickets  (when switched on) one ticket per problem; the same problem again while its ticket is open adds a note; an
 *            alert that ended adds a note once. Short alerts (over within the set minutes) are only recorded.
 * Settings: "m360" (JSON; the key wrapped as key_enc, never returned to a browser).
 */
if (!function_exists('itops_m360_cfg')) {
    if (!defined('ITOPS_M360_BUDGET')) define('ITOPS_M360_BUDGET', 60);







    function itops_m360_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_m360_alerts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, akey CHAR(40) NOT NULL UNIQUE, kind VARCHAR(8) NOT NULL,
            obj_id VARCHAR(64) NOT NULL, obj_name VARCHAR(255) NOT NULL DEFAULT '', summary VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(40) NOT NULL DEFAULT '',
            start_at DATETIME NULL, end_at DATETIME NULL, ticket_id INT UNSIGNED NULL, noted_end TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
            INDEX idx_m360_obj (kind, obj_id), INDEX idx_m360_ticket (ticket_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }



}
