<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT164 — Network watch (Server performance): where the load comes from (the map), unusual traffic, and sign-ins from
 * outside the allowed countries.
 *   itops_ip_seen       every address the web server's status page showed: first and last seen (a place is "new" when all
 *                      its addresses were first seen in the last 7 days).
 *   itops_net_hour      one row an hour: addresses, new ones, countries, login-page askers, the network with most addresses;
 *                      the level (good / busy / high / crit) and why, against the same hour of the previous 7 days.
 *   itops_signin_flags  sign-ins to vTiger and to this app from outside the allowed countries, worked or failed.
 * Runs with the samples every 5 minutes (itops_sp_sample). Only reads the server, vTiger and the sites; it never blocks.
 * Locations come from the location lookup of Server performance (ipinfo.io), within its daily limit.
 */
require_once __DIR__ . '/ServerPerf.php';

if (!function_exists('itops_nw_tables')) {

    function itops_nw_tables(PDO $db): void {
        itops_ip_geo_table($db);
        $db->exec("CREATE TABLE IF NOT EXISTS itops_ip_seen (ip VARCHAR(45) NOT NULL PRIMARY KEY, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, hits INT NOT NULL DEFAULT 0,
            INDEX idx_seen_first (first_seen), INDEX idx_seen_last (last_seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_net_hour (hour DATETIME NOT NULL PRIMARY KEY, samples INT NOT NULL DEFAULT 0, seen INT NOT NULL DEFAULT 0, addrs INT NOT NULL DEFAULT 0,
            new_addrs INT NOT NULL DEFAULT 0, located INT NOT NULL DEFAULT 0, countries TEXT NULL, new_countries TEXT NULL, login_hits INT NOT NULL DEFAULT 0, login_addrs INT NOT NULL DEFAULT 0,
            top_org VARCHAR(160) NULL, top_org_addrs INT NOT NULL DEFAULT 0, baseline DECIMAL(9,1) NULL, level VARCHAR(8) NOT NULL DEFAULT 'good', reasons TEXT NULL, top_ips TEXT NULL,
            updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_signin_flags (id INT AUTO_INCREMENT PRIMARY KEY, source VARCHAR(12) NOT NULL, user_name VARCHAR(190) NOT NULL, display VARCHAR(190) NULL,
            ip VARCHAR(45) NOT NULL, country CHAR(2) NULL, region VARCHAR(80) NULL, city VARCHAR(80) NULL, org VARCHAR(160) NULL, lat DECIMAL(8,4) NULL, lng DECIMAL(8,4) NULL,
            at DATETIME NOT NULL, ok TINYINT NOT NULL DEFAULT 1, status VARCHAR(40) NULL, excused TINYINT NOT NULL DEFAULT 0, excuse VARCHAR(255) NULL, alerted TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, UNIQUE KEY uq_flag (source, user_name, ip, at), INDEX idx_flag_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }











}
