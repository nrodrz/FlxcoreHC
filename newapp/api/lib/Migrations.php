<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Database migration runner.
 * Idempotent, ordered migrations tracked in itops_migrations. Safe to run repeatedly.
 * To add a future update: append a new entry to itops_migrations_list() with a new key.
 */

function itops_migrations_list(): array {
    return [
        // key => callable(PDO $db): string (human-readable result)
        '2027_06_12_01_bt164_net_watch' => function (PDO $db): string {
            // BT164: coordinates of the looked-up addresses (the map), addresses first/last seen, the hourly traffic and the sign-ins from outside the allowed countries
            require_once __DIR__ . '/NetWatch.php'; itops_nw_tables($db);
            return 'Network watch tables and map coordinates ready.';
        },
        '2027_06_05_01_bt163_db_insight' => function (PDO $db): string {
            // BT163: the database's kind of work per sample, and the hourly snapshots of the costliest queries
            require_once __DIR__ . '/DbInsight.php'; itops_dbi_table($db); itops_sp_table($db);
            return 'Database insight table and columns ready.';
        },
        '2027_05_29_01_bt162_errlog' => function (PDO $db): string {
            // BT162: the error logs of the accounts (grouped by kind and hour) and the swap activity per sample
            require_once __DIR__ . '/ErrLogs.php'; itops_errlog_table($db);
            require_once __DIR__ . '/ServerPerf.php'; itops_sp_table($db);
            return 'Error logs table and swap activity columns ready.';
        },
        '2027_05_22_01_bt161_server_daily' => function (PDO $db): string {
            // BT161: one row a day of the server's performance (busiest hours, peaks, traffic, top accounts), kept 3 years
            require_once __DIR__ . '/ServerPerf.php'; itops_sp_daily_table($db);
            return 'Server performance daily history table ready.';
        },
        '2027_05_15_01_bt159_ip_geo' => function (PDO $db): string {
            // BT159: the cached location and network of the addresses seen by the web server
            require_once __DIR__ . '/ServerPerf.php'; itops_ip_geo_table($db);
            return 'Address location cache ready.';
        },
        '2027_05_08_01_bt158_server_perf_more' => function (PDO $db): string {
            // BT158: memory breakdown, disks, network, web traffic, slow queries and uptime per sample, for the management dashboard
            require_once __DIR__ . '/ServerPerf.php'; itops_sp_table($db);
            return 'Server performance: disk, network and memory columns ready.';
        },
        '2027_05_01_01_bt155_server_perf' => function (PDO $db): string {
            // BT155: the performance samples of this server (CPU, memory, load, who was asking), for the graph and the spikes
            require_once __DIR__ . '/ServerPerf.php'; itops_sp_table($db);
            return 'Server performance table ready.';
        },
        '2027_04_24_01_bt152_pbx_cdr' => function (PDO $db): string {
            // BT152: the call records of the office phone system, kept for its reports
            require_once __DIR__ . '/PbxCdr.php'; itops_pbx_cdr_table($db);
            return 'Phone system call records table ready.';
        },
        '2027_04_17_01_bt149_jotform' => function (PDO $db): string {
            // BT149: Jotform submissions looked at by the forms check, each once, with the ticket made
            require_once __DIR__ . '/JotformForms.php'; itops_jf_table($db);
            return 'Jotform submissions list ready.';
        },
        '2027_04_10_01_bt148_phone_calls' => function (PDO $db): string {
            // BT148: missed calls looked at by the missed-call check (CallTrackingMetrics first), each once, with the ticket made
            require_once __DIR__ . '/PhoneTickets.php'; itops_ph_table($db);
            return 'Missed-call list ready.';
        },
        '2027_04_03_01_bt141_bin' => function (PDO $db): string {
            // BT141: Deleted items (itops_recycle). Records deleted before were only marked "archived": those whose last
            // Audit Log entry is a delete move in (see lib/RecycleBin.php itops_bin_adopt_legacy).
            require_once __DIR__ . '/RecycleBin.php';
            itops_bin_table($db);
            return itops_bin_adopt_legacy($db);
        },
        '2027_04_02_01_bt138_merge' => function (PDO $db): string {
            // BT138: the four simple category lists become itops_categories (kind kb/doc/sop/cred), the two access logs
            // become itops_access_log (kind cred/doc), and the unused itops_notification_prefs is removed. Runs on new and
            // old installations alike; safe to run again after an interruption (see lib/Categories.php).
            require_once __DIR__ . '/Categories.php';
            return itops_bt138_merge($db);
        },
        '2027_03_26_01_bt135_tagline' => function (PDO $db): string {
            // BT135: the product line under the name is now "IT Master Operations Hub". Only the untouched default changes;
            // a line set in Settings → Branding stays as it is.
            $q = $db->prepare("UPDATE itops_settings SET sval = ? WHERE skey = 'app_tagline' AND sval = ?");
            $q->execute(['IT Master Operations Hub', 'Operations & Knowledge Platform']);
            if ($q->rowCount() > 0 && function_exists('audit_log')) { try { audit_log('branding_tagline_default', 'settings', null, 'Line under the name (database update)', ['Line' => 'Operations & Knowledge Platform'], ['Line' => 'IT Master Operations Hub']); } catch (Throwable $e) {} }
            return $q->rowCount() > 0 ? 'Line under the name set to the new default.' : 'Line under the name left as it was (customized or already new).';
        },
        '2027_03_19_01_bt134_personal_pcs' => function (PDO $db): string {
            // BT134: personal computers protected with Microsoft Defender, by choice (Devices → Personal computers)
            require_once __DIR__ . '/PersonalPcs.php'; itops_pc_table($db);
            return 'Personal computers list ready.';
        },
        '2027_03_12_01_bt130_billing' => function (PDO $db): string {
            // BT130: billing on the License Hub (price list, billing of licenses, charges, payments)
            require_once __DIR__ . '/Billing.php'; itops_bill_table($db);
            return 'Billing ready.';
        },
        '2027_03_05_01_bt129_channels' => function (PDO $db): string {
            // BT129: release channels (Pilot / Stable) on the hub
            require_once __DIR__ . '/HubCheckin.php'; itops_hub_table($db);
            return 'Release channels ready.';
        },
        '2027_02_26_01_bt128_hub_links' => function (PDO $db): string {
            // BT128: link codes, and what each installation reports about its own license
            require_once __DIR__ . '/HubCheckin.php'; itops_hub_table($db);
            return 'License Hub link codes ready.';
        },
        '2027_02_19_01_bt127_hub' => function (PDO $db): string {
            // BT127: the installations that check in with this License Hub (used only where the License Manager is on)
            require_once __DIR__ . '/HubCheckin.php'; itops_hub_table($db);
            return 'License Hub check-in register ready.';
        },
        '2027_02_12_01_bt125_updates' => function (PDO $db): string {
            // BT125: the hub's register of update bundles (License Manager → Releases)
            $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_releases (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, build VARCHAR(40) NOT NULL, notes TEXT NULL,
                install_id VARCHAR(19) NOT NULL DEFAULT '', key_id VARCHAR(16) NOT NULL, size INT UNSIGNED NOT NULL DEFAULT 0, file_name VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL, INDEX idx_lmr_build (build)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // BT125: 360 Monitoring alerts seen (one row per alert, with the ticket it opened or was added to)
            require_once __DIR__ . '/Mon360.php'; itops_m360_table($db);
            return 'Release register and 360 Monitoring alerts ready.';
        },
        '2027_02_05_01_bt124_review' => function (PDO $db): string {
            // BT124 (code review): one list of emergency access accounts. The older Conditional Access panel kept its own
            // (m365_breakglass); it is folded into the Posture list (sa_emergency) when that one is empty.
            $g = fn($k) => $db->query("SELECT sval FROM itops_settings WHERE skey=" . $db->quote($k))->fetchColumn();
            $old = json_decode((string)$g('m365_breakglass'), true); $cur = json_decode((string)$g('sa_emergency'), true);
            $msg = 'nothing to fold';
            if (is_array($old) && $old && !(is_array($cur) && $cur)) {
                $e = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $old), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)))), 0, 4);
                $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('sa_emergency',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($e)]);
                $msg = 'emergency accounts folded into one list (' . count($e) . ')';
            }
            $db->exec("DELETE FROM itops_settings WHERE skey='m365_breakglass'");
            // review DESK-10: when a reopened ticket's SLA clock starts again
            try { $db->query('SELECT sla_clock_start FROM itops_tickets LIMIT 1'); } catch (Throwable $e) { $db->exec('ALTER TABLE itops_tickets ADD COLUMN sla_clock_start DATETIME NULL'); $msg .= '; ticket SLA restart column added'; }
            // review SEC-19: the copy of what a plan sign-off covered, and its fingerprint
            try { $db->query('SELECT signed_hash FROM itops_sa_plans LIMIT 1'); } catch (Throwable $e) { $db->exec('ALTER TABLE itops_sa_plans ADD COLUMN signed_snapshot MEDIUMTEXT NULL, ADD COLUMN signed_hash CHAR(64) NULL'); $msg .= '; plan sign-off copy columns added'; }
            return $msg;
        },
        '2027_01_29_01_bt124_ms_billing' => function (PDO $db): string {
            // BT124: Microsoft's own status of an invoice imported from its billing API (kept apart from the person's status note)
            try { $db->query('SELECT ms_note FROM itops_invoices LIMIT 1'); return 'already there'; } catch (Throwable $e) {}
            $db->exec('ALTER TABLE itops_invoices ADD COLUMN ms_note VARCHAR(500) NULL');
            return 'Microsoft invoice status column ready';
        },
        '2027_01_22_01_bt123_sa_drift' => function (PDO $db): string {
            // BT123 (4e): a rule that passed in one scan and fails or warns in the next, with what may have changed it
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_drift (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, run_id INT UNSIGNED NOT NULL, prev_run_id INT UNSIGNED NOT NULL,
                rule_id VARCHAR(60) NOT NULL, from_status VARCHAR(10) NOT NULL, to_status VARCHAR(10) NOT NULL, detected_at DATETIME NOT NULL, evidence MEDIUMTEXT NULL,
                plan_decision VARCHAR(12) NULL, ack_by INT UNSIGNED NULL, ack_at DATETIME NULL, ack_note VARCHAR(500) NULL,
                UNIQUE KEY uq_sd_run_rule (run_id, rule_id), KEY idx_sd_at (detected_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Security drift table ready';
        },
        '2027_01_15_01_bt121_sa_ai' => function (PDO $db): string {
            // BT121 (4d): the AI planning assistant's suggestions for a draft plan (shown only; a person decides)
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_ai_suggestions (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, plan_id INT UNSIGNED NOT NULL,
                rule_id VARCHAR(60) NOT NULL, decision VARCHAR(10) NOT NULL, why VARCHAR(300) NOT NULL DEFAULT '', created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
                UNIQUE KEY uq_sx_plan_rule (plan_id, rule_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Planning assistant table ready';
        },
        '2027_01_08_01_bt120_invoices' => function (PDO $db): string {
            // BT120: invoices from any vendor (lines tied to contracts), and the Microsoft subscriptions linked to contracts
            $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS itops_invoices (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL,
                invoice_number VARCHAR(80) NOT NULL, invoice_date DATE NOT NULL, period_start DATE NULL, period_end DATE NULL, due_date DATE NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD', subtotal DECIMAL(14,2) NOT NULL DEFAULT 0, tax DECIMAL(14,2) NOT NULL DEFAULT 0, total DECIMAL(14,2) NOT NULL DEFAULT 0,
                status VARCHAR(12) NOT NULL DEFAULT 'received', source VARCHAR(12) NOT NULL DEFAULT 'manual', paid_date DATE NULL, actual_id INT UNSIGNED NULL,
                notes TEXT NULL, status_note VARCHAR(500) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inv_vendor_num (vendor_id, invoice_number), KEY idx_inv_date (invoice_date), KEY idx_inv_status (status)) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_invoice_lines (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL, pos SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                contract_id INT UNSIGNED NULL, description VARCHAR(300) NOT NULL DEFAULT '', quantity DECIMAL(12,2) NOT NULL DEFAULT 1, unit_price DECIMAL(14,4) NOT NULL DEFAULT 0,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0, billing VARCHAR(10) NOT NULL DEFAULT 'monthly', sku_part VARCHAR(60) NULL, KEY idx_il_inv (invoice_id), KEY idx_il_contract (contract_id)) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_license_subs (sub_id VARCHAR(80) NOT NULL PRIMARY KEY, commerce_id VARCHAR(100) NOT NULL DEFAULT '', sku_id VARCHAR(40) NOT NULL DEFAULT '',
                sku_part VARCHAR(80) NOT NULL DEFAULT '', name VARCHAR(200) NOT NULL DEFAULT '', contract_id INT UNSIGNED NULL, seats INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT '',
                trial TINYINT(1) NOT NULL DEFAULT 0, renews DATE NULL, created DATE NULL, assigned INT NULL, last_seen DATETIME NULL, last_change VARCHAR(500) NULL, KEY idx_ls_contract (contract_id)) $o");
            return 'Invoice and license subscription tables ready';
        },
        '2027_01_01_01_bt119_secops' => function (PDO $db): string {
            // BT119 (4c part 2): admin role changes made from the app (kept so they can be put back), and the ticket made
            // for a Defender incident (one per incident)
            $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS itops_m365_role_changes (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, action VARCHAR(20) NOT NULL,
                principal_id VARCHAR(40) NOT NULL, principal_name VARCHAR(255) NOT NULL DEFAULT '', role_id VARCHAR(40) NOT NULL, role_name VARCHAR(255) NOT NULL DEFAULT '',
                scope VARCHAR(255) NOT NULL DEFAULT '/', assignment_id VARCHAR(200) NULL, eligibility VARCHAR(200) NULL, by_user INT UNSIGNED NOT NULL, at DATETIME NOT NULL,
                request_id INT UNSIGNED NULL, undone_at DATETIME NULL, undone_by INT UNSIGNED NULL, KEY idx_mrc_at (at)) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_m365_incident_tickets (incident_id VARCHAR(40) NOT NULL PRIMARY KEY, ticket_id INT UNSIGNED NOT NULL,
                created_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL) $o");
            return 'Admin role change and incident ticket tables ready';
        },
        '2026_12_25_01_bt118_ca_changes' => function (PDO $db): string {
            // BT118 (4c): every Conditional Access change made from ITOps (with the policy as it was, to put it back), and
            // the requests waiting for a second person when Settings → Approvals asks for one
            $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_ca_changes (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, action VARCHAR(20) NOT NULL,
                policy_id VARCHAR(400) NOT NULL DEFAULT '', policy_name VARCHAR(255) NOT NULL DEFAULT '', before_json MEDIUMTEXT NULL, after_json MEDIUMTEXT NULL,
                by_user INT UNSIGNED NOT NULL, at DATETIME NOT NULL, request_id INT UNSIGNED NULL, undone_at DATETIME NULL, undone_by INT UNSIGNED NULL, KEY idx_cac_at (at)) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_change_requests (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(20) NOT NULL,
                params TEXT NOT NULL, label VARCHAR(400) NOT NULL, reason VARCHAR(1000) NOT NULL, requested_by INT UNSIGNED NOT NULL, requested_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending', decided_by INT UNSIGNED NULL, decided_at DATETIME NULL,
                note VARCHAR(1000) NULL, result VARCHAR(1000) NULL, KEY idx_scr_status (status, id)) $o");
            return 'Conditional Access change tables ready';
        },
        '2026_12_18_01_bt117_sa_plans' => function (PDO $db): string {
            // BT117 (4b): the security plan built from an assessment run, and the client's decision on each item
            $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_plans (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, version INT UNSIGNED NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'draft', baseline VARCHAR(20) NOT NULL DEFAULT 'standard', run_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                signed_name VARCHAR(150) NULL, signed_title VARCHAR(150) NULL, signed_note VARCHAR(1000) NULL, signed_at DATETIME NULL, signed_by INT UNSIGNED NULL,
                KEY idx_sp_status (status, id)) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_plan_items (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, plan_id INT UNSIGNED NOT NULL,
                rule_id VARCHAR(60) NOT NULL, stage TINYINT UNSIGNED NOT NULL, seq INT UNSIGNED NOT NULL, found_status VARCHAR(10) NOT NULL,
                found_detail VARCHAR(1000) NULL, affected_count INT UNSIGNED NOT NULL DEFAULT 0,
                decision VARCHAR(12) NOT NULL DEFAULT 'undecided', note VARCHAR(1000) NULL, owner_id INT UNSIGNED NULL, due_date DATE NULL, expires_on DATE NULL,
                ticket_id INT UNSIGNED NULL, decided_by INT UNSIGNED NULL, decided_at DATETIME NULL,
                KEY idx_spi_plan (plan_id, stage, seq), KEY idx_spi_rule (rule_id)) $o");
            return 'security plan tables ready';
        },
        '2026_12_11_01_bt116_sa_extra' => function (PDO $db): string {
            // BT116 (4a): each assessment run keeps the baseline chosen then, the licenses owned and Microsoft's recommended actions
            try { $db->query('SELECT extra FROM itops_sa_runs LIMIT 1'); return 'already there'; } catch (Throwable $e) {}
            $db->exec('ALTER TABLE itops_sa_runs ADD COLUMN extra MEDIUMTEXT NULL');
            return 'itops_sa_runs.extra added';
        },
        '2026_12_04_01_bt114_device_requests' => function (PDO $db): string {
            // BT114: retire, wipe and fresh start of a device run only after a second person approves. One row per request.
            $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS itops_m365_dev_requests (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(80) NOT NULL, device_name VARCHAR(255) NOT NULL DEFAULT '', device_user VARCHAR(255) NOT NULL DEFAULT '',
                device_os VARCHAR(80) NOT NULL DEFAULT '', device_owner VARCHAR(20) NOT NULL DEFAULT '', device_serial VARCHAR(120) NOT NULL DEFAULT '',
                action VARCHAR(20) NOT NULL, options VARCHAR(255) NOT NULL DEFAULT '{}', reason VARCHAR(1000) NOT NULL,
                requested_by INT UNSIGNED NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending', decided_by INT UNSIGNED NULL, decided_at DATETIME NULL, decision_note VARCHAR(1000) NULL,
                sent_at DATETIME NULL, result VARCHAR(1000) NULL,
                KEY idx_mdr_status (status, requested_at), KEY idx_mdr_device (device_id, status)) $o");
            return 'device approval requests table ready';
        },
        '2026_11_27_01_bt113_m365_mail' => function (PDO $db): string {
            // BT113: the permission "Microsoft 365 · Mailboxes" for every role that has Microsoft 365 · Tenant configuration
            // (view → view, edit → view and edit). Roles saved before BT21 follow it by themselves (Perms.php).
            $n = 0;
            foreach ($db->query("SELECT id, permissions FROM itops_roles WHERE slug<>'super_admin'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = json_decode((string)$r['permissions'], true);
                if (!is_array($p) || isset($p['m365_mail'])) continue;
                $granular = false; foreach ($p as $v) { if (is_array($v)) { $granular = true; break; } }
                if (!$granular) continue;
                $m = (array)($p['m365_admin'] ?? []); if (!$m) continue;
                $p['m365_mail'] = in_array('edit', $m, true) ? ['view', 'edit'] : ['view'];
                $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$r['id']]); $n++;
            }
            return 'Microsoft 365 · Mailboxes given to ' . $n . ' role(s) with Microsoft 365 · Tenant configuration.';
        },
        '2026_11_20_01_bt108_ui_prefs' => function (PDO $db): string {
            // BT108: small screen preferences per person (for now: how Ticket Categories opens)
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_users' AND COLUMN_NAME='ui_prefs'")->fetchColumn();
            if (!$has) $db->exec('ALTER TABLE itops_users ADD COLUMN ui_prefs TEXT DEFAULT NULL');
            return $has ? 'ui_prefs already there' : 'ui_prefs added';
        },
        '2026_11_13_01_bt107_group_category_label' => function (PDO $db): string {
            // BT107: the two-step ticket picker on forms asks for a group, then a category. Forms still using the
            // original label "Category" get "Group and category"; a label someone changed is kept.
            // Entries created without a status were stored with none; the ticket screens only list "active" ones.
            $fixed = 0; try { $fixed = (int)$db->exec("UPDATE itops_ticket_categories SET status='active' WHERE status IS NULL OR status=''"); } catch (Throwable $e) {}
            $n = 0;
            try { $rows = $db->query('SELECT id, schema_json FROM itops_forms')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return 'no forms table'; }
            $up = $db->prepare('UPDATE itops_forms SET schema_json=? WHERE id=?');
            foreach ($rows as $r) {
                $sc = json_decode((string)$r['schema_json'], true); if (!is_array($sc) || !is_array($sc['fields'] ?? null)) continue;
                $ch = false;
                foreach ($sc['fields'] as &$f) { if (($f['type'] ?? '') === 'group_category' && trim((string)($f['label'] ?? '')) === 'Category') { $f['label'] = 'Group and category'; $ch = true; } }
                unset($f);
                if ($ch) { $up->execute([json_encode($sc), (int)$r['id']]); $n++; }
            }
            return $n . ' form(s) relabelled; ' . $fixed . ' categor' . ($fixed === 1 ? 'y' : 'ies') . ' without a status set to active';
        },
        '2026_11_06_01_bt106_risky_people' => function (PDO $db): string {
            // BT106: the risky people of each assessment run, and their follow-up (messages, deadline, escalation, proposals)
            $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS itops_risk_runs (run_id INT UNSIGNED NOT NULL PRIMARY KEY, sources VARCHAR(255) NOT NULL DEFAULT '{}', people INT UNSIGNED NOT NULL DEFAULT 0,
                high INT UNSIGNED NOT NULL DEFAULT 0, medium INT UNSIGNED NOT NULL DEFAULT 0, low INT UNSIGNED NOT NULL DEFAULT 0) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_risk_people (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, run_id INT UNSIGNED NOT NULL, user_oid VARCHAR(64) NOT NULL,
                upn VARCHAR(255) NOT NULL DEFAULT '', name VARCHAR(255) NOT NULL DEFAULT '', mail VARCHAR(255) NOT NULL DEFAULT '', is_admin TINYINT(1) NOT NULL DEFAULT 0,
                score TINYINT UNSIGNED NOT NULL DEFAULT 0, level VARCHAR(8) NOT NULL DEFAULT 'low', factors VARCHAR(500) NOT NULL DEFAULT '[]', details TEXT NULL,
                KEY idx_rp_run (run_id, score), KEY idx_rp_oid (user_oid, run_id)) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_risk_followups (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_oid VARCHAR(64) NOT NULL, upn VARCHAR(255) NOT NULL DEFAULT '',
                name VARCHAR(255) NOT NULL DEFAULT '', mail VARCHAR(255) NOT NULL DEFAULT '', level VARCHAR(8) NOT NULL DEFAULT '', first_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_score TINYINT UNSIGNED NOT NULL DEFAULT 0, factors VARCHAR(500) NOT NULL DEFAULT '[]', status VARCHAR(10) NOT NULL DEFAULT 'open', deadline DATE NOT NULL,
                started_at DATETIME NOT NULL, reminded_at DATETIME NULL, escalated_at DATETIME NULL, closed_at DATETIME NULL, closed_by INT UNSIGNED NULL, close_note VARCHAR(500) NULL,
                ticket_id INT UNSIGNED NULL, manager_mail VARCHAR(255) NULL, first_run_id INT UNSIGNED NULL, last_run_id INT UNSIGNED NULL,
                KEY idx_rf_oid (user_oid, status), KEY idx_rf_status (status)) $o");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_risk_actions (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, followup_id INT UNSIGNED NOT NULL, user_oid VARCHAR(64) NOT NULL,
                upn VARCHAR(255) NOT NULL DEFAULT '', name VARCHAR(255) NOT NULL DEFAULT '', action VARCHAR(20) NOT NULL, reason VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(10) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, decided_by INT UNSIGNED NULL, decided_at DATETIME NULL, result VARCHAR(500) NULL,
                KEY idx_ra_status (status), KEY idx_ra_fu (followup_id)) $o");
            return 'Risky people and follow-up tables.';
        },
        '2026_11_05_01_bt105_license_manager' => function (PDO $db): string {
            // BT105: the register of licenses issued by the License Manager (used only in the owner's own installation,
            // where config.php switches it on; created everywhere so the schema is the same)
            $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_licenses (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, serial VARCHAR(20) NOT NULL, licensee VARCHAR(200) NOT NULL, contact VARCHAR(200) NOT NULL DEFAULT '',
                install_id VARCHAR(19) NOT NULL, agreement VARCHAR(60) NOT NULL DEFAULT '', kind VARCHAR(40) NOT NULL DEFAULT '', plan VARCHAR(40) NOT NULL DEFAULT '',
                modules TEXT NULL, users INT UNSIGNED NOT NULL DEFAULT 0, term_start DATE NOT NULL, term_end DATE NULL, notes VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(10) NOT NULL DEFAULT 'issued', replaces_id INT UNSIGNED NULL, issued_at DATETIME NOT NULL, issued_by INT UNSIGNED NULL,
                key_id VARCHAR(16) NOT NULL, signature VARCHAR(120) NOT NULL, revoked_at DATETIME NULL, revoked_reason VARCHAR(300) NULL,
                UNIQUE KEY uq_lm_serial (serial), KEY idx_lm_install (install_id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'License Manager register table.';
        },
        '2026_11_04_01_bt102_security_assessment' => function (PDO $db): string {
            // BT102: security assessment runs and their results; the permission "Security assessment" for every role that
            // has Microsoft 365 · Tenant configuration (view → view, edit → view and run). Roles saved before BT21 follow it
            // by themselves (Perms.php). Super Admin always has it.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_runs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, started_at DATETIME NOT NULL, finished_at DATETIME NULL,
                via VARCHAR(10) NOT NULL DEFAULT 'manual', started_by INT UNSIGNED NULL, score TINYINT UNSIGNED NULL, counts VARCHAR(255) NOT NULL DEFAULT '{}',
                KEY idx_sa_started (started_at)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sa_results (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, run_id INT UNSIGNED NOT NULL, check_id VARCHAR(60) NOT NULL,
                status VARCHAR(10) NOT NULL, why VARCHAR(12) NOT NULL DEFAULT '', affected_count INT UNSIGNED NOT NULL DEFAULT 0,
                affected MEDIUMTEXT NULL, detail TEXT NULL, KEY idx_sa_run (run_id), KEY idx_sa_check (check_id, run_id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $n = 0;
            foreach ($db->query("SELECT id, permissions FROM itops_roles WHERE slug<>'super_admin'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = json_decode((string)$r['permissions'], true);
                if (!is_array($p) || isset($p['security_assessment'])) continue;
                $granular = false; foreach ($p as $v) { if (is_array($v)) { $granular = true; break; } }
                if (!$granular) continue;
                $m = (array)($p['m365_admin'] ?? []); if (!$m) continue;
                $p['security_assessment'] = in_array('edit', $m, true) ? ['view', 'edit'] : ['view'];
                $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$r['id']]); $n++;
            }
            return 'Security assessment tables; the permission given to ' . $n . ' role(s) with Microsoft 365 · Tenant configuration.';
        },
        '2026_11_03_01_bt98_admin_role' => function (PDO $db): string {
            // BT98: a system role "Admin" — every module with every action, below Super Admin. Not included, as for every
            // role: the permissions marked "not granted to any role automatically" (Entra devices, recovery keys, directory
            // roles, vTiger passwords, IT Budget, Company systems) and "Terms of Use · accept for the organization" (Super
            // Admin only for now). Super Admin-only actions stay Super Admin-only. An existing role with the slug "admin" is
            // left as it is.
            $q = $db->prepare("SELECT id, is_system FROM itops_roles WHERE slug='admin'"); $q->execute(); $ex = $q->fetch(PDO::FETCH_ASSOC);
            if ($ex) return 'Admin role: a role with the key "admin" already exists — left as it is.';
            $p = ['tickets' => ['view','create','edit','delete','all'], 'cases' => ['view','create','all'], 'calendar' => ['view','create','edit','delete'], 'reports' => ['view'], 'scorecard' => ['view','create','edit','delete'], 'standards' => ['view','create','edit','delete'], 'kb' => ['view','create','edit','delete'], 'kb_gaps' => ['view','edit'], 'kb_assist' => ['view'], 'sops' => ['view','create','edit','delete'], 'job_descriptions' => ['view','create','edit','delete'], 'my_signatures' => ['view'], 'assets' => ['view','create','edit','delete'], 'creds' => ['view','create','edit','delete','all'], 'contracts' => ['view','create','edit','delete'], 'vendors' => ['view','create','edit','delete'], 'my_equipment' => ['view'], 'm365' => ['view'], 'm365_users' => ['edit'], 'm365_credentials' => ['edit'], 'm365_licenses' => ['edit'], 'm365_groups' => ['edit'], 'provision_onboard' => ['edit'], 'provision_offboard' => ['edit'], 'vtiger_users' => ['view','edit'], 'onboarding' => ['view','create','edit','delete','all'], 'm365_admin' => ['view','edit'], 'guardian' => ['view'], 'uptime' => ['view','edit'], 'backups' => ['view','edit'], 'announcements' => ['view','create','edit','delete'], 'users' => ['view','create','edit','delete','all'], 'roles' => ['view'], 'categories' => ['view','create','edit','delete'], 'forms' => ['view','create','edit','delete'], 'import' => ['view'], 'settings' => ['view','edit'], 'license' => ['view'], 'branding' => ['view','edit'], 'email_cfg' => ['view','edit'], 'sla_cfg' => ['view','edit'], 'changelog' => ['view'], 'audit' => ['view'], 'event_log' => ['view'], 'jobs' => ['view','edit'], 'integrations' => ['view','edit'], 'integration_audit' => ['view','edit','admin']];
            $db->prepare("INSERT INTO itops_roles (name, slug, description, permissions, is_system) VALUES (?,?,?,?,1)")
               ->execute(['Admin', 'admin', 'Administers the whole system below Super Admin: every module and action, except what stays with Super Admin (roles, the Super Admin role, maintenance, server actions, accepting the Terms of Use for the organization) and the sensitive permissions given only by hand.', json_encode($p, JSON_UNESCAPED_SLASHES)]);
            return 'Admin role added (' . count($p) . ' modules).';
        },
        '2026_11_02_01_bt97_terms_license' => function (PDO $db): string {
            // BT97: Terms of Use acceptances (one row per person and version; one 'org' row when the first administrator
            // accepts for the organization), and the permission "License details" (About & ownership in full) for every
            // role that may change Settings · General. Super Admin always has it.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_terms_acceptances (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, version VARCHAR(20) NOT NULL,
                scope VARCHAR(4) NOT NULL DEFAULT 'user', org_name VARCHAR(120) NULL, ip_address VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '', accepted_at DATETIME NOT NULL,
                KEY idx_tou_user (user_id, version), KEY idx_tou_scope (version, scope)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $n = 0;
            foreach ($db->query("SELECT id, permissions FROM itops_roles WHERE slug<>'super_admin'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = json_decode((string)$r['permissions'], true);
                if (!is_array($p) || isset($p['license'])) continue;
                $granular = false; foreach ($p as $v) { if (is_array($v)) { $granular = true; break; } }
                if (!$granular || !in_array('edit', (array)($p['settings'] ?? []), true)) continue;   // a role saved before BT18 follows Settings by itself
                $p['license'] = ['view'];
                $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$r['id']]);
                $n++;
            }
            return 'Terms of Use: acceptance table ready; License details given to ' . $n . ' role(s) that manage Settings (Super Admin always has it).';
        },
        '2026_11_01_01_bt96_white_label' => function (PDO $db): string {
            // BT96: the product names no company or organization. The hosting-provider tool of Company systems has the key
            // 'hosting' (its old key, a provider's name, is assembled here only); this installation gets its own identifier
            // (Administration → About & ownership). The organization's own data is not changed.
            $p = [];
            $old = 'host' . 'dime';
            try {
                $j = json_decode((string)$db->query("SELECT sval FROM itops_settings WHERE skey='access_systems'")->fetchColumn(), true);
                if (is_array($j) && isset($j['systems'][$old])) {
                    if (!isset($j['systems']['hosting'])) $j['systems']['hosting'] = $j['systems'][$old];
                    unset($j['systems'][$old]);
                    if (isset($j['order']) && is_array($j['order'])) $j['order'] = array_values(array_unique(array_map(fn($k) => $k === $old ? 'hosting' : $k, $j['order'])));
                    $db->prepare("UPDATE itops_settings SET sval=? WHERE skey='access_systems'")->execute([json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    $p[] = 'the hosting-provider company tool moved to its neutral key';
                }
            } catch (Throwable $e) {}
            require_once __DIR__ . '/Brand.php';
            $id = itops_install_id($db);
            $p[] = $id !== '' ? 'installation ID ready' : 'installation ID not created (settings table missing)';
            return 'White label: ' . implode('; ', $p) . '.';
        },
        '2026_10_31_01_bt95_neutral' => function (PDO $db): string {
            // BT95: the app names no regulator, standards body or framework. The module is "Standards & Audits"; the legacy
            // names were moved by itops_legacy_rename() before this step (tables, columns, keys, the reviewer role, files).
            $d = $GLOBALS['ITOPS_LEGACY_DONE'] ?? [];
            if (!empty($d['error'])) throw new RuntimeException('Renaming stopped: ' . $d['error']);
            $p = [];
            if (!empty($d['tables'])) $p[] = count($d['tables']) . ' table(s) renamed';
            if (!empty($d['columns'])) $p[] = 'onboarding columns renamed';
            if (!empty($d['roles'])) $p[] = 'role permissions updated (the read-only reviewer role is "External reviewer")';
            if (!empty($d['links'])) $p[] = $d['links'] . ' evidence link(s) updated';
            if (!empty($d['audit_kinds'])) $p[] = $d['audit_kinds'] . ' Audit Log entr' . ($d['audit_kinds'] === 1 ? 'y' : 'ies') . ' relabelled';
            if (!empty($d['files'])) $p[] = 'evidence files moved to uploads/standards';
            if (!empty($d['old_files'])) $p[] = $d['old_files'] . ' program file(s) of the old names removed';
            return 'Standards & Audits: ' . ($p ? implode('; ', $p) : 'nothing to rename (already neutral)') . '.';
        },
        '2026_10_30_01_bt94_features' => function (PDO $db): string {
            // BT94: related tickets (one reply / one resolution for a group of tickets); announcements to a Microsoft 365 group
            // or to typed email addresses (people without an account confirm with a personal link).
            $has = function (string $t, string $c) use ($db): bool { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $q->execute([$t, $c]); return (int)$q->fetchColumn() > 0; };
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ticket_related_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL DEFAULT '', created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (!$has('itops_tickets', 'related_group_id')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN related_group_id INT UNSIGNED NULL, ADD INDEX idx_tk_related (related_group_id)');
            $db->exec("CREATE TABLE IF NOT EXISTS itops_announcement_audience_ext (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, announcement_id INT UNSIGNED NOT NULL, kind VARCHAR(10) NOT NULL, ref VARCHAR(320) NOT NULL, label VARCHAR(255) NOT NULL DEFAULT '',
                UNIQUE KEY uq_ann_ext (announcement_id, kind, ref), KEY idx_ann_ext (announcement_id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_announcement_external (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, announcement_id INT UNSIGNED NOT NULL, email VARCHAR(320) NOT NULL, name VARCHAR(255) NOT NULL DEFAULT '',
                via VARCHAR(255) NOT NULL DEFAULT '', token_hash CHAR(64) NOT NULL, email_state VARCHAR(10) NOT NULL DEFAULT 'pending', email_at DATETIME NULL,
                seen_at DATETIME NULL, ack_at DATETIME NULL, reminded_at DATETIME NULL,
                UNIQUE KEY uq_ann_ext_mail (announcement_id, email), UNIQUE KEY uq_ann_ext_tok (token_hash), KEY idx_ann_ext_state (email_state)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Related tickets can be linked; announcements can go to a Microsoft 365 group or to email addresses.';
        },
        '2026_10_29_01_bt92_tickets' => function (PDO $db): string {
            // BT92 (code audit M-21): a ticket's file can be internal (for the support team: Tickets · All, its owner and backup).
            $q = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_ticket_attachments' AND COLUMN_NAME='is_internal'");
            if (!(int)$q->fetchColumn()) $db->exec("ALTER TABLE itops_ticket_attachments ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0");
            $n = (int)$db->query('SELECT COUNT(*) FROM itops_ticket_attachments')->fetchColumn();
            return 'Ticket files can be marked internal (the ' . $n . ' file' . ($n === 1 ? '' : 's') . ' already there stay visible to the requester, as before).';
        },
        '2026_10_28_01_bt91_ops' => function (PDO $db): string {
            // BT91 (code audit M-23 to M-29 and the operations Lows): the Standards & Audits tables made here instead of on a
            // page request; signature requests remember the document's version and text; links for outside signers expire;
            // the stored HTML of Knowledge Base articles, SOPs and job descriptions cleaned once (new saves are cleaned as made).
            require_once __DIR__ . '/StandardsSetup.php';
            require_once __DIR__ . '/HtmlClean.php';
            require_once __DIR__ . '/Signatures.php';
            $has = function (string $t, string $c) use ($db): bool { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $q->execute([$t, $c]); return (int)$q->fetchColumn() > 0; };
            $tbl = function (string $t) use ($db): bool { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $q->execute([$t]); return (int)$q->fetchColumn() > 0; };
            itops_std_ensure($db);
            $out = ['Compliance tables in place'];
            $c = 0;
            foreach (['itops_kb_articles', 'itops_sops', 'itops_job_descriptions'] as $t) {
                if (!$tbl($t)) continue;
                foreach ($db->query("SELECT id, content FROM {$t} WHERE content LIKE '%<%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $clean = itops_html_clean((string)$r['content']);
                    if ($clean === itops_html_clean((string)$r['content'], false)) continue;   // only where the cleaning removed something
                    $db->prepare("UPDATE {$t} SET content=?, updated_at=updated_at WHERE id=?")->execute([$clean, (int)$r['id']]); $c++;
                }
            }
            $out[] = $c ? $c . ' document' . ($c === 1 ? '' : 's') . ' had unsafe HTML removed' : 'no stored document had unsafe HTML';
            if ($tbl('itops_signature_requests')) {
                if (!$has('itops_signature_requests', 'doc_fp')) $db->exec("ALTER TABLE itops_signature_requests ADD COLUMN doc_version VARCHAR(20) NULL, ADD COLUMN doc_fp CHAR(32) NULL");
                if (!$has('itops_signature_signers', 'token_expires')) $db->exec("ALTER TABLE itops_signature_signers ADD COLUMN token_expires DATETIME NULL");
                // links already sent keep working for the usual time from today
                $l = $db->exec("UPDATE itops_signature_signers SET token_expires = NOW() + INTERVAL " . (int)ITOPS_SIGN_LINK_DAYS . " DAY WHERE sign_token IS NOT NULL AND token_expires IS NULL AND status='pending'");
                // open requests remember the document as it is today
                $n = 0;
                foreach ($db->query("SELECT id, doc_type, doc_id FROM itops_signature_requests WHERE status='open' AND doc_fp IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $d = itops_sig_doc($db, (string)$r['doc_type'], (int)$r['doc_id']); if (!$d) continue;
                    $db->prepare('UPDATE itops_signature_requests SET doc_fp=?, doc_version=? WHERE id=?')->execute([$d['fp'], $d['version'] !== '' ? $d['version'] : null, (int)$r['id']]); $n++;
                }
                $out[] = 'signature requests tied to the document version (' . $n . ' open request' . ($n === 1 ? '' : 's') . '); outside signing links expire after ' . (int)ITOPS_SIGN_LINK_DAYS . ' days (' . (int)$l . ' link' . ((int)$l === 1 ? '' : 's') . ' already sent run ' . (int)ITOPS_SIGN_LINK_DAYS . ' days from today)';
            }
            return implode('; ', $out) . '.';
        },
        '2026_10_27_01_bt90_signin' => function (PDO $db): string {
            // BT90 (code audit M-8, M-9, M-12 and the sign-in Lows): sign-in failures in their own table (cleaned as they age),
            // a 2FA code used once, the last activity of a session, a link to Microsoft allowed by a Super Admin,
            // and "All records" for the roles that already managed every department's users.
            $has = function (string $t, string $c) use ($db): bool { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $q->execute([$t, $c]); return (int)$q->fetchColumn() > 0; };
            $db->exec("CREATE TABLE IF NOT EXISTS itops_auth_throttle (id BIGINT AUTO_INCREMENT PRIMARY KEY, k VARCHAR(120) NOT NULL, at DATETIME NOT NULL,
                INDEX idx_at_k (k, at), INDEX idx_at_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (!$has('itops_users', 'mfa_last_step')) $db->exec("ALTER TABLE itops_users ADD COLUMN mfa_last_step BIGINT NULL");
            if (!$has('itops_users', 'o365_link_ok')) $db->exec("ALTER TABLE itops_users ADD COLUMN o365_link_ok TINYINT(1) NOT NULL DEFAULT 0");
            if (!$has('itops_sessions', 'last_seen_at')) $db->exec("ALTER TABLE itops_sessions ADD COLUMN last_seen_at DATETIME NULL");
            $old = $db->exec("DELETE FROM itops_settings WHERE skey LIKE 'll\\_%' OR skey LIKE 'ml\\_%'");   // the old lockout rows (never cleaned before)
            // Users · All records: kept by every role that already managed users, except Department Admin (limited to its department now).
            $n = 0;
            foreach ($db->query("SELECT id, slug, permissions FROM itops_roles WHERE slug<>'super_admin'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = json_decode((string)$r['permissions'], true); if (!is_array($p) || !isset($p['users']) || !is_array($p['users'])) continue;
                if ($r['slug'] === 'dept_admin' || in_array('all', $p['users'], true)) continue;
                if (!array_intersect(['create', 'edit', 'delete'], $p['users'])) continue;
                $p['users'][] = 'all'; $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p), (int)$r['id']]); $n++;
            }
            return 'Sign-in: failures counted per account in their own table (' . (int)$old . ' old lockout row' . ((int)$old === 1 ? '' : 's') . ' removed); 2FA codes accepted once; idle sign-out checked on the server; Microsoft linking allowed by a Super Admin. Users · All records given to ' . $n . ' role' . ($n === 1 ? '' : 's') . ' that already managed users (not Department Admin).';
        },
        '2026_10_26_01_bt87_public' => function (PDO $db): string {
            // BT87 (code audit): Knowledge Base articles shown on the public help portal only when marked public; one vote
            // per visitor; public requests sent with an address of the app stay unconfirmed until that person confirms.
            $has = function (string $t, string $c) use ($db): bool { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $q->execute([$t, $c]); return (int)$q->fetchColumn() === 1; };
            if (!$has('itops_kb_articles', 'is_public')) $db->exec("ALTER TABLE itops_kb_articles ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_kb_votes (article_id INT NOT NULL, voter CHAR(32) NOT NULL, kind VARCHAR(8) NOT NULL, created_at DATETIME NULL,
                PRIMARY KEY (article_id, voter, kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (!$has('itops_tickets', 'verify_user_id')) $db->exec("ALTER TABLE itops_tickets ADD COLUMN verify_user_id INT NULL, ADD COLUMN verify_token CHAR(64) NULL, ADD INDEX idx_tk_verify (verify_token)");
            $n = (int)$db->query("SELECT COUNT(*) FROM itops_kb_articles WHERE status='published'")->fetchColumn();
            return 'Knowledge Base: a "public" flag per article (off — ' . $n . ' published article' . ($n === 1 ? '' : 's') . ' are no longer on the public help portal until marked public); public requests confirmed by email.';
        },
        '2026_10_25_01_bt86_fixes' => function (PDO $db): string {
            // BT86 (code audit): a standard change remembers its standard-change template; deleted accounts are turned off
            $c = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_svc' AND COLUMN_NAME='std_template_id'")->fetchColumn();
            if (!$c) $db->exec("ALTER TABLE itops_case_svc ADD COLUMN std_template_id INT NULL");
            try {   // standard changes made from a standard-change template before BT86 keep their pre-approval
                $db->exec("UPDATE itops_case_svc i JOIN itops_cases c ON c.id=i.case_id JOIN itops_case_templates t ON t.id=c.template_id AND t.std_change=1 SET i.std_template_id=t.id WHERE i.change_type='standard' AND i.std_template_id IS NULL");
            } catch (Throwable $e) {}
            $n = (int)$db->query("SELECT COUNT(*) FROM itops_case_svc WHERE change_type='standard' AND std_template_id IS NULL")->fetchColumn();
            return 'Planned changes: a standard change comes from a standard-change template' . ($n ? ' (' . $n . ' standard change' . ($n === 1 ? '' : 's') . ' made by hand now need approval)' : '') . '.';
        },
        '2026_10_24_01_task_checklists' => function (PDO $db): string {
            // BT85: a short checklist inside a case task
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_task_checks (
                id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, seq INT NOT NULL DEFAULT 0, text VARCHAR(255) NOT NULL, done TINYINT(1) NOT NULL DEFAULT 0,
                done_by INT NULL, done_at DATETIME NULL, created_at DATETIME NULL, INDEX idx_check_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Cases & Projects: checklists inside tasks.';
        },
        '2026_10_23_01_improvements' => function (PDO $db): string {
            // BT84 (Phase 7 part 2 — continual improvement): the improvement register
            $db->exec("CREATE TABLE IF NOT EXISTS itops_improvements (
                id INT AUTO_INCREMENT PRIMARY KEY, number VARCHAR(20) NOT NULL, title VARCHAR(200) NOT NULL, description TEXT NULL, benefit TEXT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'new', priority VARCHAR(8) NULL, effort VARCHAR(8) NULL, owner_id INT NULL, target_date DATE NULL, measure TEXT NULL, progress TEXT NULL,
                source_type VARCHAR(16) NOT NULL DEFAULT 'suggestion', source_id INT NULL, source_label VARCHAR(120) NULL, suggested_by INT NULL,
                decided_by INT NULL, decided_at DATETIME NULL, decision_note TEXT NULL, result VARCHAR(8) NULL, result_note TEXT NULL, done_at DATETIME NULL, case_id INT NULL,
                created_at DATETIME NULL, updated_at DATETIME NULL,
                UNIQUE KEY uq_improvement_number (number), INDEX idx_imp_status (status), INDEX idx_imp_owner (owner_id), INDEX idx_imp_by (suggested_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'The continual improvement register (Service Desk → Improvements).';
        },
        '2026_10_22_01_insights' => function (PDO $db): string {
            // BT83 (Phase 7 part 1 — reporting): the template a case was made from (time per template in Reports)
            $col = fn($t, $c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();
            if (!(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_cases'")->fetchColumn()) return 'Cases are not installed yet; nothing to do.';
            if (!$col('itops_cases', 'template_id')) $db->exec('ALTER TABLE itops_cases ADD COLUMN template_id INT NULL, ADD INDEX idx_case_template (template_id)');
            $n = 0;   // cases made from a template before this update: the Audit Log names the template
            if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_templates'")->fetchColumn()) {
                $tpl = []; foreach ($db->query('SELECT id, name FROM itops_case_templates')->fetchAll(PDO::FETCH_ASSOC) as $t) $tpl[mb_strtolower(trim($t['name']))] = (int)$t['id'];
                $up = $db->prepare('UPDATE itops_cases SET template_id=? WHERE id=? AND template_id IS NULL');
                foreach ($db->query("SELECT entity_id, new_value FROM itops_audit_log WHERE action='case_template_applied' AND entity_type='case' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $v = json_decode((string)$r['new_value'], true); $k = mb_strtolower(trim((string)($v['template'] ?? '')));
                    if ($k !== '' && isset($tpl[$k])) { $up->execute([$tpl[$k], (int)$r['entity_id']]); $n += $up->rowCount(); }
                }
            }
            return 'Reports: cases remember the template they were made from (' . $n . ' earlier case' . ($n === 1 ? '' : 's') . ' found in the Audit Log).';
        },
        '2026_10_21_01_svc_changes' => function (PDO $db): string {
            // BT82 (Phase 6 part 2 ): problem and change records on cases, equipment links, standard-change templates
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_svc (
                case_id INT NOT NULL PRIMARY KEY, root_cause TEXT NULL, workaround TEXT NULL, known_error TINYINT(1) NOT NULL DEFAULT 0, kb_article_id INT NULL,
                change_type VARCHAR(10) NULL, risk VARCHAR(8) NULL, impact TEXT NULL, backout TEXT NULL, window_start DATETIME NULL, window_end DATETIME NULL, emergency_reason TEXT NULL,
                approval_state VARCHAR(12) NOT NULL DEFAULT 'none', approval_tasks TEXT NULL, outcome VARCHAR(12) NULL, outcome_note TEXT NULL, updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ci_links (
                entity_type VARCHAR(8) NOT NULL, entity_id INT NOT NULL, asset_id INT NOT NULL, linked_by INT NULL, linked_at DATETIME NULL,
                PRIMARY KEY (entity_type, entity_id, asset_id), INDEX idx_ci_asset (asset_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $col = fn($t, $c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();
            if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_templates'")->fetchColumn() && !$col('itops_case_templates', 'std_change'))
                $db->exec('ALTER TABLE itops_case_templates ADD COLUMN std_change TINYINT(1) NOT NULL DEFAULT 0');
            $kb = '';
            if (!(int)$db->query("SELECT COUNT(*) FROM itops_kb_categories WHERE slug='known-errors'")->fetchColumn()) {
                $db->exec("INSERT INTO itops_kb_categories (name, slug, sort_order) VALUES ('Known errors', 'known-errors', 90)"); $kb = '; Knowledge Base category "Known errors"';
            }
            return 'problem records (root cause, workaround, known errors in the Knowledge Base), change records (type, risk, impact, back-out plan, window, approval by risk, outcome), equipment linked to tickets and cases, standard-change templates' . $kb . '.';
        },
        '2026_10_20_01_svc_basics' => function (PDO $db): string {
            // BT81 (Phase 6, part 1): ticket type per category and per ticket, satisfaction ratings, major incidents
            $col = fn($t, $c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();
            $newCat = !$col('itops_ticket_categories', 'ticket_type');
            if ($newCat) $db->exec("ALTER TABLE itops_ticket_categories ADD COLUMN ticket_type VARCHAR(10) NOT NULL DEFAULT 'request'");
            if (!$col('itops_tickets', 'ticket_type')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN ticket_type VARCHAR(10) NULL');
            if (!$col('itops_tickets', 'major')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN major TINYINT(1) NOT NULL DEFAULT 0');
            if (!$col('itops_tickets', 'major_parent_id')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN major_parent_id INT NULL, ADD INDEX idx_major_parent (major_parent_id)');
            if (!$col('itops_tickets', 'major_at')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN major_at DATETIME NULL, ADD COLUMN major_by INT NULL, ADD COLUMN major_summary VARCHAR(255) NULL, ADD COLUMN major_banner TINYINT(1) NOT NULL DEFAULT 0');
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ticket_csat (
                ticket_id INT NOT NULL PRIMARY KEY, token CHAR(48) NOT NULL, rating VARCHAR(10) NULL, comment TEXT NULL, rated_at DATETIME NULL, rated_by INT NULL, rated_via VARCHAR(10) NULL, created_at DATETIME NULL,
                UNIQUE KEY uq_csat_token (token), INDEX idx_csat_rated (rated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_major_reviews (
                ticket_id INT NOT NULL PRIMARY KEY, what_happened TEXT NULL, cause TEXT NULL, actions TEXT NULL, problem_case_id INT NULL, written_by INT NULL, written_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $inc = 0;
            $newCat = $newCat || !(int)$db->query("SELECT COUNT(*) FROM itops_ticket_categories WHERE ticket_type='incident'")->fetchColumn();
            if ($newCat) {   // a first guess from the category names; Ticket Categories shows the type and it can be changed
                $inc = $db->exec("UPDATE itops_ticket_categories SET ticket_type='incident' WHERE name REGEXP '(issue|problem|error|outage|down|not working|broken|fail|crash|virus|malware|phish|security|repair|slow|cannot|can''t|unable|lost|stolen)'");
            }
            $db->exec("UPDATE itops_tickets t LEFT JOIN itops_ticket_categories c ON c.id=t.category_id SET t.ticket_type=COALESCE(c.ticket_type,'request') WHERE t.ticket_type IS NULL");
            return 'Service management basics: Incident or Service request on categories and tickets' . ($newCat ? ' (' . (int)$inc . ' categor' . ($inc === 1 ? 'y' : 'ies') . ' set to Incident from their names; check them under Ticket Categories)' : '') . ', satisfaction ratings, major incidents and their reviews.';
        },
        '2026_10_19_01_projects' => function (PDO $db): string {
            // BT80 (Phase 5): projects (PR-…) with phases and milestones, cases without a ticket (their origin), budget lines
            $col = fn($t, $c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();
            if (!$col('itops_cases', 'kind')) $db->exec("ALTER TABLE itops_cases ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'case'");
            if (!$col('itops_cases', 'origin')) $db->exec('ALTER TABLE itops_cases ADD COLUMN origin VARCHAR(12) NULL');
            if (!$col('itops_cases', 'sponsor_id')) $db->exec('ALTER TABLE itops_cases ADD COLUMN sponsor_id INT NULL');
            if (!$col('itops_cases', 'former_number')) $db->exec('ALTER TABLE itops_cases ADD COLUMN former_number VARCHAR(20) NULL');
            if (!$col('itops_case_tasks', 'phase_id')) $db->exec('ALTER TABLE itops_case_tasks ADD COLUMN phase_id INT NULL');
            if (!$col('itops_case_template_tasks', 'phase_name')) $db->exec('ALTER TABLE itops_case_template_tasks ADD COLUMN phase_name VARCHAR(100) NULL');
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_phases (
                id INT AUTO_INCREMENT PRIMARY KEY, case_id INT NOT NULL, seq INT NOT NULL DEFAULT 0, name VARCHAR(100) NOT NULL, milestone_date DATE NULL, created_at DATETIME NULL,
                INDEX idx_cph_case (case_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_budget (
                case_id INT NOT NULL, line_id INT NOT NULL, PRIMARY KEY (case_id, line_id), INDEX idx_cb_line (line_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $n = $db->exec("UPDATE itops_cases SET origin='request' WHERE origin IS NULL");
            $seeded = '';
            if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_case_templates'")->fetchColumn() === 1
                && !(int)$db->query("SELECT COUNT(*) FROM itops_case_templates WHERE name='IT project (Plan, Build, Roll out, Close)'")->fetchColumn()) {
                // a first project template: the four phases, an approved scope, a change approval before roll-out, lessons learned
                $db->prepare("INSERT INTO itops_case_templates (name, description, case_title, anchor_label, target_offset, active, created_at, updated_at) VALUES (?,?,?,?,?,1,NOW(),NOW())")
                   ->execute(['IT project (Plan, Build, Roll out, Close)', 'A project in four phases: a kick-off and an approved scope, the build and a test with the users, a change approval before the roll-out, and a lessons-learned meeting to close.', '', 'Kick-off date', 42]);
                $tid = (int)$db->lastInsertId();
                $ik = $db->prepare('INSERT INTO itops_case_template_tasks (template_id, seq, title, kind, who, offset_days, at_time, minutes, depends_seq, show_requester, public_title, invite_requester, duration_days, phase_name) VALUES (?,?,?,?,?,?,?,?,?,0,NULL,0,?,?)');
                foreach ([[1, 'Kick-off meeting', 'appointment', 'owner', 0, '10:00', 60, null, 1, 'Plan'], [2, 'Scope, plan and budget approved', 'approval', 'ask', 5, null, null, 1, 1, 'Plan'],
                          [3, 'Build and configure', 'task', 'owner', 25, null, null, 2, 15, 'Build'], [4, 'Test with the users', 'task', 'ask', 30, null, null, 3, 5, 'Build'],
                          [5, 'Change approved for the roll-out', 'approval', 'ask', 31, null, null, 4, 1, 'Roll out'], [6, 'Roll out', 'task', 'owner', 36, null, null, 5, 5, 'Roll out'],
                          [7, 'Lessons-learned meeting', 'appointment', 'owner', 40, '14:00', 60, 6, 1, 'Close'], [8, 'Hand over to support and close', 'task', 'owner', 42, null, null, 7, 1, 'Close']] as $k)
                    $ik->execute(array_merge([$tid], $k));
                $seeded = '; a first project template (IT project: Plan, Build, Roll out, Close)';
            }
            return 'Cases & Projects: projects (PR-…) with phases and milestones, cases without a ticket (origin: planned change, problem investigation, internal task), IT Budget lines on a project' . ($n ? '; ' . $n . ' existing case' . ($n === 1 ? '' : 's') . ' marked as from a request' : '') . $seeded . '.';
        },
        '2026_10_18_01_case_timeline' => function (PDO $db): string {
            // BT79: the case timeline (Gantt) — a task has a start date as well as a due date; template tasks have a length
            $col = fn($t, $c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();
            if (!$col('itops_case_tasks', 'start_date')) $db->exec('ALTER TABLE itops_case_tasks ADD COLUMN start_date DATE NULL');
            if (!$col('itops_case_template_tasks', 'duration_days')) $db->exec('ALTER TABLE itops_case_template_tasks ADD COLUMN duration_days INT NOT NULL DEFAULT 1');
            return 'Cases: the timeline (Gantt) — tasks have a start date (until set, a task starts on its due date) and template tasks a length in business days.';
        },
        '2026_10_17_01_case_templates' => function (PDO $db): string {
            // BT78 (Phase 4): case templates — tasks with business-day offsets, people and what the requester sees
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_templates (
                id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, description TEXT NULL, case_title VARCHAR(255) NULL, anchor_label VARCHAR(60) NOT NULL DEFAULT 'Start date',
                target_offset INT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, updated_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
                UNIQUE KEY uq_ctpl_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_template_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY, template_id INT NOT NULL, seq INT NOT NULL, title VARCHAR(255) NOT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'task', who VARCHAR(10) NOT NULL DEFAULT 'owner',
                assignee_id INT NULL, offset_days INT NOT NULL DEFAULT 0, at_time VARCHAR(5) NULL, minutes SMALLINT NULL, depends_seq INT NULL, show_requester TINYINT(1) NOT NULL DEFAULT 0,
                public_title VARCHAR(255) NULL, invite_requester TINYINT(1) NOT NULL DEFAULT 0, attendees TEXT NULL, INDEX idx_ctt_tpl (template_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if ((int)$db->query('SELECT COUNT(*) FROM itops_case_templates')->fetchColumn() > 0) return 'Case templates: tables ready (templates already there kept).';
            // the first three templates (process doc, Part 2 "Templates"); every person is the case owner or chosen when used
            $T = [
              ['New hire', 'Everything a new employee needs on the first day: approval, Microsoft 365 account, laptop, phone and company systems, a desk setup with them, and their confirmation.', null, 'Start date', 2, [
                ['Manager approval', 'approval', 'ask', -5, null, null, null, 0, null, 0],
                ['Microsoft 365 account', 'task', 'owner', -3, null, null, 1, 1, 'Account ready', 0],
                ['Laptop and equipment agreement', 'task', 'owner', -2, null, null, 1, 1, 'Laptop ready', 0],
                ['Phone and company systems', 'task', 'owner', -2, null, null, 1, 0, null, 0],
                ['Desk setup with the new hire', 'appointment', 'owner', 0, '09:30', 60, null, 1, 'Desk setup', 1],
                ['Confirm everything works', 'requester', 'requester', 2, null, null, 5, 1, 'Confirm everything works', 0]]],
              ['Offboarding', 'A leaver: the last day confirmed, hand-over of mailbox and files, equipment back, accounts closed and access checked again.', null, 'Last day', 5, [
                ['Confirm the last day and who receives the files', 'requester', 'requester', -5, null, null, null, 1, 'Confirm the last day and the hand-over', 0],
                ['Plan the hand-over of mailbox and files', 'task', 'owner', -3, null, null, 1, 1, 'Hand-over planned', 0],
                ['Collect the laptop and equipment', 'appointment', 'owner', 0, '15:00', 30, null, 1, 'Equipment collected', 0],
                ['Offboard Microsoft 365 and company systems', 'task', 'owner', 0, null, null, 2, 1, 'Accounts closed', 0],
                ['Check again that access is removed', 'task', 'owner', 5, null, null, 4, 0, null, 0]]],
              ['Desk or office move', 'People or a team moving desks or offices: approval, survey, equipment, the move itself, a walk-through and their confirmation.', null, 'Move day', 2, [
                ['Approve the move plan', 'approval', 'ask', -10, null, null, null, 0, null, 0],
                ['Survey network points and power', 'task', 'owner', -7, null, null, 1, 0, null, 0],
                ['Order missing cables and equipment', 'task', 'owner', -5, null, null, 2, 0, null, 0],
                ['Move and reconnect computers, phones and printers', 'task', 'owner', 0, null, null, 3, 1, 'Move done', 0],
                ['Walk-through with the team', 'appointment', 'owner', 1, '10:00', 30, 4, 1, 'Walk-through', 1],
                ['Confirm everything works', 'requester', 'requester', 2, null, null, 5, 1, 'Confirm everything works', 0]]],
            ];
            $it = $db->prepare("INSERT INTO itops_case_templates (name, description, case_title, anchor_label, target_offset, active, created_at, updated_at) VALUES (?,?,?,?,?,1,NOW(),NOW())");
            $ik = $db->prepare('INSERT INTO itops_case_template_tasks (template_id, seq, title, kind, who, offset_days, at_time, minutes, depends_seq, show_requester, public_title, invite_requester) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($T as [$n, $d, $ct, $al, $to, $tasks]) {
                $it->execute([$n, $d, $ct, $al, $to]); $tid = (int)$db->lastInsertId();
                foreach ($tasks as $i => $k) $ik->execute([$tid, $i + 1, $k[0], $k[1], $k[2], $k[3], $k[4], $k[5], $k[6], $k[7], $k[8], $k[9]]);
            }
            return 'Case templates: New hire, Offboarding and Desk or office move are ready (Cases → Templates). A Super Admin can name who else keeps them.';
        },
        '2026_10_16_01_case_calendar' => function (PDO $db): string {
            // BT76 → BT77 (Phase 3): meetings as case tasks, the daily case digest
            $col = fn($t, $c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();
            if (!$col('itops_case_tasks', 'start_at')) $db->exec('ALTER TABLE itops_case_tasks ADD COLUMN start_at DATETIME NULL');
            if (!$col('itops_case_tasks', 'minutes')) $db->exec('ALTER TABLE itops_case_tasks ADD COLUMN minutes SMALLINT NULL');
            if (!$col('itops_case_tasks', 'event_id')) { $db->exec('ALTER TABLE itops_case_tasks ADD COLUMN event_id INT NULL'); try { $db->exec('ALTER TABLE itops_case_tasks ADD INDEX idx_ct_event (event_id)'); } catch (\Throwable $e) {} }
            if (!$col('itops_case_tasks', 'attendees')) $db->exec('ALTER TABLE itops_case_tasks ADD COLUMN attendees TEXT NULL');
            if (!$col('itops_case_tasks', 'invite_requester')) $db->exec('ALTER TABLE itops_case_tasks ADD COLUMN invite_requester TINYINT(1) NOT NULL DEFAULT 0');
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_digest (
                user_id INT NOT NULL PRIMARY KEY, last_date DATE NULL, last_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Cases: meetings as tasks (Outlook meetings with a Teams link), case task dates on the calendar, emails when a task is yours or can start, and the daily case digest (7:00 AM on business days; Settings → General → Cases & Projects).';
        },
        '2026_10_15_01_cases' => function (PDO $db): string {
            // BT76 (Phase 2): cases — tickets that need several people, with tasks, approvals and a requester view
            $db->exec("CREATE TABLE IF NOT EXISTS itops_cases (
                id INT AUTO_INCREMENT PRIMARY KEY, case_number VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open', owner_id INT NULL, target_date DATE NULL, summary TEXT NULL, created_by INT NULL,
                created_at DATETIME NULL, updated_at DATETIME NULL, resolved_at DATETIME NULL, closed_at DATETIME NULL,
                UNIQUE KEY uq_case_number (case_number), INDEX idx_case_status (status), INDEX idx_case_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_watchers (
                case_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (case_id, user_id), INDEX idx_cw_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY, case_id INT NOT NULL, seq INT NOT NULL DEFAULT 0, title VARCHAR(255) NOT NULL, public_title VARCHAR(255) NULL,
                show_requester TINYINT(1) NOT NULL DEFAULT 0, kind VARCHAR(12) NOT NULL DEFAULT 'task', assignee_id INT NULL, due_date DATE NULL, depends_on INT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open', reason VARCHAR(255) NULL, decision VARCHAR(10) NULL, decision_note TEXT NULL,
                done_at DATETIME NULL, done_by INT NULL, created_at DATETIME NULL,
                INDEX idx_ct_case (case_id), INDEX idx_ct_assignee (assignee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_case_updates (
                id INT AUTO_INCREMENT PRIMARY KEY, case_id INT NOT NULL, user_id INT NULL, body TEXT NOT NULL, is_public TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NULL,
                INDEX idx_cu_case (case_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $col = fn($c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_tickets' AND COLUMN_NAME='$c'")->fetchColumn();
            if (!$col('case_id')) { $db->exec('ALTER TABLE itops_tickets ADD COLUMN case_id INT NULL'); try { $db->exec('ALTER TABLE itops_tickets ADD INDEX idx_tk_case (case_id)'); } catch (\Throwable $e) {} }
            if (!$col('case_main')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN case_main TINYINT(1) NOT NULL DEFAULT 0');
            if (!$col('case_close_mode')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN case_close_mode VARCHAR(8) NULL');
            // who gets Cases & Projects: Managers and Department Admins create (and Department Admins see every case);
            // everyone who works tickets can open the Cases page to work the tasks given to them
            $n = 0;
            foreach ($db->query('SELECT id, slug, permissions FROM itops_roles')->fetchAll() as $r) {
                if (($r['slug'] ?? '') === 'super_admin') continue;
                $p = json_decode((string)$r['permissions'], true);
                if (!is_array($p) || array_key_exists('cases', $p)) continue;
                $granular = false; foreach ($p as $pv) { if (is_array($pv)) { $granular = true; break; } }
                if (!$granular) continue;
                $tk = is_array($p['tickets'] ?? null) ? $p['tickets'] : [];
                if ($r['slug'] === 'dept_admin') $p['cases'] = ['view', 'create', 'all'];
                elseif ($r['slug'] === 'manager') $p['cases'] = ['view', 'create'];
                elseif (in_array('edit', $tk, true) || in_array('all', $tk, true)) $p['cases'] = ['view'];
                else continue;
                $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p), $r['id']]); $n++;
            }
            return 'Cases & Projects: cases, tasks, approvals and the requester view' . ($n ? '; ' . $n . ' role' . ($n === 1 ? '' : 's') . ' received the Cases & Projects permission (Managers create cases, Department Admins create and see every case, ticket agents work their tasks)' : '') . '.';
        },
        '2026_10_14_01_ticket_lifecycle' => function (PDO $db): string {
            // BT75 (Phase 1): why a ticket is Pending, and where a merged ticket went
            $col = fn($c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_tickets' AND COLUMN_NAME='$c'")->fetchColumn();
            if (!$col('pending_reason')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN pending_reason VARCHAR(16) NULL');
            if (!$col('merged_into_id')) { $db->exec('ALTER TABLE itops_tickets ADD COLUMN merged_into_id INT NULL'); try { $db->exec('ALTER TABLE itops_tickets ADD INDEX idx_tk_merged (merged_into_id)'); } catch (\Throwable $e) {} }
            if (!$col('resolution_notes')) $db->exec('ALTER TABLE itops_tickets ADD COLUMN resolution_notes TEXT NULL');
            // tickets already Pending get "something else" so they can be saved; the agent can choose the real reason
            $n = $db->exec("UPDATE itops_tickets SET pending_reason='other' WHERE status='pending' AND (pending_reason IS NULL OR pending_reason='')");
            return 'Tickets: Pending reasons and merging' . ($n ? ' (' . $n . ' Pending ticket' . ($n === 1 ? '' : 's') . ' set to "something else")' : '') . '; resolved tickets close on their own after 3 business days (Settings → SLA & Hours).';
        },
        '2026_10_13_01_help_downloads_removed' => function (PDO $db): string {
            // BT74: the Word guides are support files kept outside the app; who could download them (BT71) is no longer used
            $n = $db->exec("DELETE FROM itops_settings WHERE skey='help_download_access'");
            return 'Help Center: the guides are no longer downloaded from the app' . ($n ? ' (the old download choices removed)' : '') . '.';
        },
        '2026_10_12_05_backup_running' => function (PDO $db): string {
            // BT71: a WHM backup date is only recorded once every account is in it (WHM makes the date when the run starts)
            $col = fn($c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_backup_jobs' AND COLUMN_NAME='$c'")->fetchColumn();
            if (!$col('pending_date')) $db->exec('ALTER TABLE itops_backup_jobs ADD COLUMN pending_date DATE NULL');
            if (!$col('pending_since')) $db->exec('ALTER TABLE itops_backup_jobs ADD COLUMN pending_since DATETIME NULL');
            // a backup recorded too early (read while it was running: accounts missing) is checked again at the next read
            $n = $db->exec("UPDATE itops_backup_jobs j SET j.pending_date=j.last_backup_date, j.pending_since=NOW() WHERE j.kind='whm' AND j.last_backup_date >= CURDATE() - INTERVAL 1 DAY
                            AND EXISTS (SELECT 1 FROM itops_backup_events e WHERE e.job_id=j.id AND e.backup_date=j.last_backup_date AND e.detail LIKE '%not in this backup%')");
            return 'Backups: a WHM backup is recorded once it is complete' . ($n ? ' (' . $n . ' to check again)' : '') . '.';
        },
        '2026_10_12_04_scorecard_visibility' => function (PDO $db): string {
            // BT70: a Scorecard measurable can be limited to chosen roles and people (NULL = everyone who can open the Scorecard)
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_scorecard_measurables' AND COLUMN_NAME='visible_to'")->fetchColumn();
            if (!$has) $db->exec('ALTER TABLE itops_scorecard_measurables ADD COLUMN visible_to TEXT NULL');
            return 'Scorecard: each measurable can be limited to chosen roles and people.';
        },
        '2026_10_12_03_outlook_import' => function (PDO $db): string {
            // BT68: events tagged in Outlook come into ITOps; the event remembers where it came from.
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_calendar_events' AND COLUMN_NAME='origin'")->fetchColumn();
            if (!$has) $db->exec("ALTER TABLE itops_calendar_events ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT ''");
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_outlook_users' AND COLUMN_NAME='import_scan_at'")->fetchColumn();
            if (!$has) $db->exec('ALTER TABLE itops_outlook_users ADD COLUMN import_scan_at DATETIME NULL');   // UTC: the last complete read of the tagged Outlook events
            $k = (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_outlook_links' AND INDEX_NAME='idx_ol_ical'")->fetchColumn();
            if (!$k) { try { $db->exec('ALTER TABLE itops_outlook_links ADD KEY idx_ol_ical (ical_uid)'); } catch (Throwable $e) {} }
            return 'Outlook sync: events tagged in Outlook can come into the calendar (calendar events: origin; Outlook people: import_scan_at).';
        },
        '2026_10_12_02_vtiger_users' => function (PDO $db): string {
            // BT67: vTiger users (profile, employee record) and vTiger passwords. Roles that already provision vTiger users
            // (Onboard / Offboard to vTiger) get vTiger users · View and Edit; vTiger passwords is granted to nobody automatically.
            $n = 0;
            foreach ($db->query('SELECT id, slug, permissions FROM itops_roles')->fetchAll() as $r) {
                if (($r['slug'] ?? '') === 'super_admin') continue;
                $p = json_decode((string)$r['permissions'], true); if (!is_array($p)) continue;
                $granular = false; foreach ($p as $pv) { if (is_array($pv)) { $granular = true; break; } } if (!$granular) continue;
                $has = fn($m) => isset($p[$m]) && is_array($p[$m]) && in_array('edit', $p[$m], true);
                if (($has('provision_onboard') || $has('provision_offboard')) && !array_key_exists('vtiger_users', $p)) { $p['vtiger_users'] = ['view', 'edit'];
                    $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p), $r['id']]); $n++; }
            }
            return "vTiger users: $n role(s) that provision vTiger users can now see and edit them (vTiger passwords: grant it in Roles & Permissions).";
        },
        '2026_10_12_01_outlook_sync' => function (PDO $db): string {
            // BT66: two-way sync between the ITOps calendar and each person's Outlook / Teams calendar (Microsoft Graph)
            $db->exec("CREATE TABLE IF NOT EXISTS itops_outlook_users (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NULL,
                kinds VARCHAR(100) NULL,
                last_sync_at DATETIME NULL,
                last_ok_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                items INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_outlook_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                item_key VARCHAR(40) NOT NULL,
                role VARCHAR(12) NOT NULL DEFAULT 'item',
                graph_id VARCHAR(400) NOT NULL DEFAULT '',
                change_key VARCHAR(200) NOT NULL DEFAULT '',
                hash CHAR(40) NOT NULL DEFAULT '',
                sent TEXT NULL,
                item_date DATETIME NULL,
                teams TINYINT(1) NOT NULL DEFAULT 0,
                dismissed TINYINT(1) NOT NULL DEFAULT 0,
                error VARCHAR(500) NULL,
                synced_at DATETIME NULL,
                pulled_at DATETIME NULL,
                ical_uid VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_item (user_id, item_key), KEY idx_item (item_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Outlook / Teams calendar sync: people\'s choices and the links between ITOps items and Outlook events.';
        },
        '2026_10_11_03_ia_cron' => function (PDO $db): string {
            // BT64: ITOps's own registry entry also recognises cron-uptime.php (uptime and backup checks, BT61/BT62)
            try { $r = $db->query("SELECT id, job_identifiers FROM itops_integration_registry WHERE seed_key='itops_cron'")->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) { return 'Integration Audit not installed — nothing to do.'; }
            if (!$r) return 'No ITOps scheduled-jobs entry — nothing to do.';
            $ids = json_decode((string)$r['job_identifiers'], true); if (!is_array($ids)) $ids = [];
            if (in_array('cron-uptime.php', $ids, true)) return 'ITOps scheduled-jobs entry already knows cron-uptime.php.';
            $ids[] = 'cron-uptime.php';
            $db->prepare('UPDATE itops_integration_registry SET job_identifiers=? WHERE id=?')->execute([json_encode(array_values($ids)), (int)$r['id']]);
            return 'ITOps scheduled-jobs entry now recognises cron-uptime.php.';
        },
        '2026_10_11_02_scorecard_views' => function (PDO $db): string {
            // BT63: how a row's weekly values combine into a month or a year (avg | sum | last; NULL = from its source and unit)
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_scorecard_measurables' AND COLUMN_NAME='rollup'")->fetchColumn();
            if (!$has) { $db->exec("ALTER TABLE itops_scorecard_measurables ADD COLUMN rollup VARCHAR(8) NULL AFTER cadence"); return 'Scorecard rows can say how they combine over a month or a year.'; }
            return 'Scorecard rows already have the rollup setting.';
        },
        '2026_10_11_01_backups' => function (PDO $db): string {
            // BT62: backup monitoring (WHM backups and backup scripts that check in), servers chosen for the Scorecard,
            // and idle runs of the every-minute cron jobs (kept for a day only).
            $has = fn(string $t, string $c) => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn() > 0;
            $tbl = fn(string $t) => (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn() > 0;
            $out = [];
            if ($tbl('itops_uptime_monitors') && !$has('itops_uptime_monitors', 'on_scorecard')) { $db->exec("ALTER TABLE itops_uptime_monitors ADD COLUMN on_scorecard TINYINT(1) NOT NULL DEFAULT 0 AFTER alert"); $out[] = 'uptime monitors can be chosen for the Scorecard'; }
            if ($tbl('itops_cron_runs') && !$has('itops_cron_runs', 'idle')) { $db->exec("ALTER TABLE itops_cron_runs ADD COLUMN idle TINYINT(1) NOT NULL DEFAULT 0, ADD KEY idx_idle (idle, started_at)"); $out[] = 'idle cron runs are kept for a day'; }
            $db->exec("CREATE TABLE IF NOT EXISTS itops_backup_jobs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                kind VARCHAR(10) NOT NULL DEFAULT 'checkin',
                slug VARCHAR(60) NOT NULL,
                key_hash CHAR(64) NULL,
                key_hint VARCHAR(8) NOT NULL DEFAULT '',
                expect_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
                grace_min SMALLINT UNSIGNED NOT NULL DEFAULT 120,
                active TINYINT(1) NOT NULL DEFAULT 1,
                alert TINYINT(1) NOT NULL DEFAULT 1,
                on_scorecard TINYINT(1) NOT NULL DEFAULT 1,
                state VARCHAR(12) NOT NULL DEFAULT 'waiting',
                state_since DATETIME NULL,
                last_ok_at DATETIME NULL,
                wait_from DATETIME NULL,
                last_seen_at DATETIME NULL,
                last_detail VARCHAR(500) NOT NULL DEFAULT '',
                last_read_at DATETIME NULL,
                last_backup_date DATE NULL,
                notes VARCHAR(1000) NOT NULL DEFAULT '',
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_slug (slug), UNIQUE KEY uq_key (key_hash), KEY idx_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_backup_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id INT UNSIGNED NOT NULL,
                at DATETIME NOT NULL,
                ok TINYINT(1) NOT NULL,
                kind VARCHAR(10) NOT NULL DEFAULT 'checkin',
                detail VARCHAR(500) NOT NULL DEFAULT '',
                via VARCHAR(10) NOT NULL DEFAULT '',
                backup_date DATE NULL,
                size_mb DECIMAL(12,1) NULL,
                duration_s INT UNSIGNED NULL,
                KEY idx_job_time (job_id, at), KEY idx_time (at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // the monthly report of the scheduled jobs: one line per job and month (the runs themselves are kept for the current month)
            $db->exec("CREATE TABLE IF NOT EXISTS itops_cron_months (
                ym CHAR(7) NOT NULL,
                job VARCHAR(20) NOT NULL,
                runs INT UNSIGNED NOT NULL DEFAULT 0,
                ok INT UNSIGNED NOT NULL DEFAULT 0,
                warning INT UNSIGNED NOT NULL DEFAULT 0,
                failed INT UNSIGNED NOT NULL DEFAULT 0,
                idle INT UNSIGNED NOT NULL DEFAULT 0,
                total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_ms INT UNSIGNED NULL,
                first_at DATETIME NULL,
                last_at DATETIME NULL,
                PRIMARY KEY (ym, job)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $out[] = 'tables itops_backup_jobs, itops_backup_events, itops_cron_months ready';
            return ucfirst(implode('; ', $out)) . '.';
        },
        '2026_10_10_03_uptime' => function (PDO $db): string {
            // BT61: uptime monitoring — monitors (websites and WHM/cPanel accounts), every check, and the outages.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_uptime_monitors (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                kind VARCHAR(10) NOT NULL DEFAULT 'http',
                url VARCHAR(500) NOT NULL,
                method VARCHAR(4) NOT NULL DEFAULT 'GET',
                expect_status VARCHAR(40) NOT NULL DEFAULT '200-399',
                keyword VARCHAR(200) NOT NULL DEFAULT '',
                interval_min SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                timeout_s TINYINT UNSIGNED NOT NULL DEFAULT 10,
                verify_ssl TINYINT(1) NOT NULL DEFAULT 1,
                ssl_warn_days TINYINT UNSIGNED NOT NULL DEFAULT 14,
                fail_threshold TINYINT UNSIGNED NOT NULL DEFAULT 2,
                alert TINYINT(1) NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 1,
                source VARCHAR(10) NOT NULL DEFAULT 'manual',
                cpanel_user VARCHAR(64) NULL,
                suspended TINYINT(1) NOT NULL DEFAULT 0,
                notes TEXT NULL,
                state VARCHAR(10) NOT NULL DEFAULT 'unknown',
                state_since DATETIME NULL,
                last_check DATETIME NULL,
                last_ms INT UNSIGNED NULL,
                last_code SMALLINT UNSIGNED NULL,
                last_error VARCHAR(300) NOT NULL DEFAULT '',
                ssl_expires DATE NULL,
                ssl_alerted_for DATE NULL,
                consecutive_fail SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                first_fail_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_active (active), KEY idx_kind (kind), KEY idx_cpuser (cpanel_user)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_uptime_checks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                monitor_id INT UNSIGNED NOT NULL,
                checked_at DATETIME NOT NULL,
                ok TINYINT(1) NOT NULL,
                ms INT UNSIGNED NULL,
                code SMALLINT UNSIGNED NULL,
                error VARCHAR(300) NULL,
                KEY idx_mon_time (monitor_id, checked_at), KEY idx_time (checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_uptime_incidents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                monitor_id INT UNSIGNED NOT NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                duration_s INT UNSIGNED NULL,
                cause VARCHAR(300) NOT NULL DEFAULT '',
                note VARCHAR(500) NOT NULL DEFAULT '',
                KEY idx_mon (monitor_id, started_at), KEY idx_open (ended_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Uptime monitoring: monitors, checks and outages.';
        },
        '2026_10_10_02_calendar' => function (PDO $db): string {
            // BT60: calendar events with types, times, recurrence, reminders, attendees, offices; saved calendar reports.
            $col = function (string $c) use ($db): ?array { $s = $db->prepare("SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_calendar_events' AND COLUMN_NAME=?"); $s->execute([$c]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; };
            $t = $col('event_type');
            if ($t && $t['DATA_TYPE'] === 'enum') {
                $db->exec("ALTER TABLE itops_calendar_events MODIFY event_type VARCHAR(40) NOT NULL DEFAULT 'meeting'");
                $db->exec("UPDATE itops_calendar_events SET event_type = CASE WHEN event_type IN ('meeting','task') THEN event_type WHEN event_type IN ('sop_review','contract_expiry','contract_renewal','credential_rotation','document_expiry') THEN 'deadline' ELSE 'other' END");
            }
            if (($col('start_date')['DATA_TYPE'] ?? '') === 'date') $db->exec('ALTER TABLE itops_calendar_events MODIFY start_date DATETIME NOT NULL');
            if (($col('end_date')['DATA_TYPE'] ?? '') === 'date') $db->exec('ALTER TABLE itops_calendar_events MODIFY end_date DATETIME NULL');
            $add = ['location' => "VARCHAR(200) NOT NULL DEFAULT ''", 'site_id' => 'INT UNSIGNED NULL', 'online_url' => "VARCHAR(500) NOT NULL DEFAULT ''", 'visibility' => "VARCHAR(10) NOT NULL DEFAULT 'team'",
                    'status' => "VARCHAR(12) NOT NULL DEFAULT 'scheduled'", 'recur' => 'TEXT NULL', 'exdates' => 'TEXT NULL', 'reminders' => 'VARCHAR(200) NULL', 'owner_id' => 'INT UNSIGNED NULL',
                    'series_id' => 'INT UNSIGNED NULL', 'updated_by' => 'INT UNSIGNED NULL', 'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'];
            foreach ($add as $c => $def) if (!$col($c)) $db->exec("ALTER TABLE itops_calendar_events ADD COLUMN $c $def");
            $db->exec('UPDATE itops_calendar_events SET owner_id = created_by WHERE owner_id IS NULL');
            $hasK = fn($k) => (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_calendar_events' AND INDEX_NAME='$k'")->fetchColumn() > 0;
            if (!$hasK('idx_cal_type')) $db->exec('ALTER TABLE itops_calendar_events ADD KEY idx_cal_type (event_type)');
            if (!$hasK('idx_cal_owner')) $db->exec('ALTER TABLE itops_calendar_events ADD KEY idx_cal_owner (owner_id)');
            $db->exec("CREATE TABLE IF NOT EXISTS itops_calendar_attendees (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                email VARCHAR(190) NOT NULL DEFAULT '',
                name VARCHAR(160) NOT NULL DEFAULT '',
                response VARCHAR(10) NOT NULL DEFAULT 'none',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_event (event_id), KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_calendar_reminders_sent (
                event_id INT UNSIGNED NOT NULL, occ DATE NOT NULL, minutes INT NOT NULL, user_id INT UNSIGNED NOT NULL,
                sent_at DATETIME NOT NULL,
                PRIMARY KEY (event_id, occ, minutes, user_id), KEY idx_sent (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_calendar_reports (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                type VARCHAR(30) NOT NULL,
                filters TEXT NULL,
                owner_id INT UNSIGNED NULL,
                shared TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Calendar: event types, times, recurrence, reminders, attendees and saved reports.';
        },
        '2026_10_10_01_cron_runs' => function (PDO $db): string {
            // BT60: every run of a scheduled job (cron) — steps, duration, result — for the Scheduled jobs page and its alerts.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_cron_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job VARCHAR(40) NOT NULL,
                via VARCHAR(10) NOT NULL DEFAULT 'cli',
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                duration_ms INT UNSIGNED NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'running',
                summary VARCHAR(500) NOT NULL DEFAULT '',
                steps MEDIUMTEXT NULL,
                error TEXT NULL,
                php VARCHAR(20) NOT NULL DEFAULT '',
                host VARCHAR(80) NOT NULL DEFAULT '',
                pid INT UNSIGNED NULL,
                mem_mb DECIMAL(8,1) NULL,
                KEY idx_job_started (job, started_at), KEY idx_started (started_at), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Scheduled jobs: run history.';
        },
        '2026_10_09_02_budget_contract' => function (PDO $db): string {
            // BT59: a budget line names its contract or agreement (a term of itops_contracts; renewals are followed forward).
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_budget_lines' AND COLUMN_NAME='contract_id'")->fetchColumn();
            if (!$has) { $db->exec('ALTER TABLE itops_budget_lines ADD COLUMN contract_id INT UNSIGNED NULL AFTER vendor_id'); $db->exec('ALTER TABLE itops_budget_lines ADD KEY idx_contract (contract_id)'); }
            return 'IT budget lines: contract or agreement.';
        },
        '2026_10_09_01_budget' => function (PDO $db): string {
            // BT58: IT budget — sites (offices), budget lines per fiscal year, actual spend. Settings live in itops_settings
            // (budget_cfg, budget_m365_prices). The sites are seeded on the first visit of the Budget area, not here.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_budget_sites (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'office',
                address VARCHAR(300) NOT NULL DEFAULT '',
                region VARCHAR(80) NOT NULL DEFAULT '',
                match_names VARCHAR(500) NOT NULL DEFAULT '',
                notes TEXT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_budget_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fy SMALLINT UNSIGNED NOT NULL,
                name VARCHAR(160) NOT NULL,
                pool VARCHAR(30) NOT NULL DEFAULT 'other',
                tower VARCHAR(30) NOT NULL DEFAULT '',
                nature VARCHAR(12) NOT NULL DEFAULT 'run',
                cost_type VARCHAR(8) NOT NULL DEFAULT 'opex',
                billing VARCHAR(12) NOT NULL DEFAULT 'annual',
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                qty DECIMAL(10,2) NOT NULL DEFAULT 1,
                months TINYINT UNSIGNED NOT NULL DEFAULT 12,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                site_id INT UNSIGNED NULL,
                vendor_id INT UNSIGNED NULL,
                department_id INT UNSIGNED NULL,
                owner_id INT UNSIGNED NULL,
                source VARCHAR(12) NOT NULL DEFAULT 'manual',
                source_ref VARCHAR(64) NOT NULL DEFAULT '',
                renewal_date DATE NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'planned',
                needs_price TINYINT(1) NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_by INT UNSIGNED NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_fy (fy), KEY idx_src (fy, source, source_ref), KEY idx_site (site_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_budget_actuals (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fy SMALLINT UNSIGNED NOT NULL,
                line_id INT UNSIGNED NULL,
                pool VARCHAR(30) NOT NULL DEFAULT 'other',
                site_id INT UNSIGNED NULL,
                period CHAR(7) NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                vendor_id INT UNSIGNED NULL,
                reference VARCHAR(120) NOT NULL DEFAULT '',
                note VARCHAR(500) NOT NULL DEFAULT '',
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_fy (fy), KEY idx_line (line_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'IT budget: sites, budget lines and actual spend tables.';
        },
        '2026_08_18_01_scorecard' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_scorecard_measurables (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                owner_id INT UNSIGNED DEFAULT NULL,
                goal_op ENUM('gte','lte','gt','lt','eq','between') DEFAULT 'gte',
                goal_value DECIMAL(14,2) DEFAULT 0,
                goal_value2 DECIMAL(14,2) DEFAULT NULL,
                unit VARCHAR(24) DEFAULT '',
                source VARCHAR(40) DEFAULT 'manual',
                cadence ENUM('weekly','monthly') DEFAULT 'weekly',
                sort_order INT DEFAULT 0,
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_active (active), KEY idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_scorecard_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                measurable_id INT UNSIGNED NOT NULL,
                period_end DATE NOT NULL,
                value DECIMAL(14,2) DEFAULT NULL,
                is_auto TINYINT(1) DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_meas_period (measurable_id, period_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Scorecard tables ready.';
        },
        '2026_08_17_04_loan_flow_attachments' => function (PDO $db): string {
            $add = function ($tbl, $col, $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE $tbl ADD COLUMN $ddl");
            };
            $add('itops_asset_loans', 'ack_status', "ack_status VARCHAR(20) DEFAULT 'signed'");
            $add('itops_asset_loans', 'return_requested_at', 'return_requested_at DATETIME DEFAULT NULL');
            $db->exec("CREATE TABLE IF NOT EXISTS itops_attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(40) NOT NULL, entity_id INT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) DEFAULT '', file_size INT UNSIGNED DEFAULT 0,
                uploaded_by INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ent (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Loan acceptance flow + attachments ready.';
        },
        '2026_08_17_03_webhook_queue' => function (PDO $db): string {
            try { $db->exec("ALTER TABLE itops_webhook_deliveries MODIFY COLUMN status ENUM('delivered','failed','queued') DEFAULT 'failed'"); } catch (\Throwable $e) {}
            return 'Webhook delivery queue status ready.';
        },
        '2026_08_17_02_assets' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_assets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_tag VARCHAR(64) NOT NULL,
                category VARCHAR(40) NOT NULL DEFAULT 'laptop',
                make VARCHAR(120) DEFAULT '', model VARCHAR(120) DEFAULT '',
                serial_number VARCHAR(120) DEFAULT '',
                status ENUM('available','loaned','maintenance','retired','lost') DEFAULT 'available',
                cond VARCHAR(40) DEFAULT 'good',
                purchase_date DATE DEFAULT NULL, purchase_cost DECIMAL(10,2) DEFAULT NULL,
                warranty_expiry DATE DEFAULT NULL, location VARCHAR(160) DEFAULT '',
                department_id INT UNSIGNED DEFAULT NULL, assigned_to INT UNSIGNED DEFAULT NULL,
                notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tag (asset_tag), KEY idx_status (status), KEY idx_cat (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_asset_loans (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                borrower_name VARCHAR(160) NOT NULL DEFAULT '',
                borrower_email VARCHAR(190) DEFAULT '',
                issued_by INT UNSIGNED DEFAULT NULL,
                issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                due_date DATE DEFAULT NULL,
                returned_at DATETIME DEFAULT NULL,
                condition_out VARCHAR(60) DEFAULT 'good',
                condition_in VARCHAR(60) DEFAULT NULL,
                accessories TEXT,
                content LONGTEXT,
                signed_name VARCHAR(160) DEFAULT NULL,
                signed_at DATETIME DEFAULT NULL,
                signature_img MEDIUMTEXT,
                ack_ip VARCHAR(64) DEFAULT NULL,
                reminded_at DATETIME DEFAULT NULL,
                status ENUM('out','returned') DEFAULT 'out',
                notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_asset (asset_id), KEY idx_status (status), KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Asset inventory + equipment loan tables ready.';
        },
        '2026_08_17_01_kb_deflect' => function (PDO $db): string {
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_kb_articles' AND COLUMN_NAME='deflect_count'")->fetchColumn();
            if (!$has) $db->exec("ALTER TABLE itops_kb_articles ADD COLUMN deflect_count INT UNSIGNED DEFAULT 0");
            return 'KB deflection counter ready.';
        },
        '2026_08_16_06_ob_duedates_std' => function (PDO $db): string {
            $add = function ($tbl, $col, $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE $tbl ADD COLUMN $ddl");
            };
            $add('itops_ob_template_tasks', 'due_offset_days', 'due_offset_days INT DEFAULT NULL');
            $add('itops_ob_run_tasks', 'due_date', 'due_date DATE DEFAULT NULL');
            $add('itops_ob_run_tasks', 'reminded_at', 'reminded_at DATETIME DEFAULT NULL');
            $add('itops_ob_templates', 'std_req_ids', 'std_req_ids TEXT DEFAULT NULL');
            $add('itops_ob_runs', 'std_req_ids', 'std_req_ids TEXT DEFAULT NULL');
            return 'Onboarding due-dates + evidence columns ready.';
        },
        // Heal: ensure every feature table exists even if an earlier install marked migrations
        // applied without creating them (fresh-install seed path). All idempotent (IF NOT EXISTS).
        '2026_08_16_05_heal_tables' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_kb_gaps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        query_norm VARCHAR(180) NOT NULL,
        query_text VARCHAR(500),
        hits INT UNSIGNED DEFAULT 1,
        answered_from VARCHAR(16) DEFAULT 'none',
        status ENUM('open','resolved') DEFAULT 'open',
        last_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_q (query_norm),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        kind ENUM('onboard','offboard') NOT NULL DEFAULT 'onboard',
        description TEXT,
        department_id INT UNSIGNED DEFAULT NULL,
        status ENUM('active','archived') DEFAULT 'active',
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kind (kind, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_template_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        task_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        config MEDIUMTEXT,
        assignee_role VARCHAR(40) DEFAULT 'it',
        required TINYINT(1) DEFAULT 1,
        KEY idx_tpl (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED DEFAULT NULL,
        kind ENUM('onboard','offboard') NOT NULL DEFAULT 'onboard',
        subject_name VARCHAR(190) NOT NULL,
        subject_email VARCHAR(190) DEFAULT NULL,
        subject_upn VARCHAR(190) DEFAULT NULL,
        department_id INT UNSIGNED DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        status ENUM('in_progress','completed','cancelled') DEFAULT 'in_progress',
        notes TEXT,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        KEY idx_status (kind, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_run_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        task_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        config MEDIUMTEXT,
        assignee_role VARCHAR(40) DEFAULT 'it',
        required TINYINT(1) DEFAULT 1,
        status ENUM('pending','done','skipped','failed') DEFAULT 'pending',
        result TEXT,
        completed_by INT UNSIGNED DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        KEY idx_run (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_alerts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        alert_key VARCHAR(160) NOT NULL,
        level ENUM('warning','error') DEFAULT 'warning',
        channel VARCHAR(60) DEFAULT 'guardian',
        message VARCHAR(500),
        status ENUM('open','cleared') DEFAULT 'open',
        first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        cleared_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_key (alert_key),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_event_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                level ENUM('error','warning','info','debug') NOT NULL DEFAULT 'info',
                channel VARCHAR(60) NOT NULL DEFAULT 'app',
                message VARCHAR(500) NOT NULL DEFAULT '',
                context JSON DEFAULT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT '',
                KEY idx_ts (ts),
                KEY idx_level_ts (level, ts),
                KEY idx_channel_ts (channel, ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        a_type VARCHAR(32) NOT NULL,
        a_id INT UNSIGNED NOT NULL,
        b_type VARCHAR(32) NOT NULL,
        b_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_a (a_type, a_id),
        KEY idx_b (b_type, b_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_jd_versions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        jd_id INT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        content LONGTEXT,
        change_summary TEXT,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_jd (jd_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_signature_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_type VARCHAR(32) NOT NULL,
        doc_id INT UNSIGNED NOT NULL,
        doc_title VARCHAR(255),
        message TEXT,
        status ENUM('open','completed','cancelled') DEFAULT 'open',
        requested_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        KEY idx_doc (doc_type, doc_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_signature_signers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        signer_name VARCHAR(160),
        signer_email VARCHAR(190),
        status ENUM('pending','signed','declined') DEFAULT 'pending',
        signature_data MEDIUMTEXT,
        signature_type VARCHAR(16),
        signed_at DATETIME DEFAULT NULL,
        signed_ip VARCHAR(64),
        KEY idx_req (request_id),
        KEY idx_user (user_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_api_keys (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        key_prefix VARCHAR(32) NOT NULL,
        key_hash CHAR(64) NOT NULL,
        scopes TEXT,
        status ENUM('active','revoked') DEFAULT 'active',
        last_used_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_key_hash (key_hash),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_webhooks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        url VARCHAR(500) NOT NULL,
        secret VARCHAR(120) NOT NULL,
        events TEXT,
        status ENUM('active','paused') DEFAULT 'active',
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_webhook_deliveries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        webhook_id INT UNSIGNED NOT NULL,
        delivery_id VARCHAR(32) NOT NULL,
        event VARCHAR(64) NOT NULL,
        payload MEDIUMTEXT,
        response_status INT DEFAULT 0,
        error TEXT,
        attempts INT DEFAULT 1,
        status ENUM('delivered','failed') DEFAULT 'failed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_hook (webhook_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_job_descriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) DEFAULT NULL,
        content LONGTEXT,
        department_id INT UNSIGNED DEFAULT NULL,
        reports_to VARCHAR(255) DEFAULT NULL,
        employment_type VARCHAR(64) DEFAULT NULL,
        location VARCHAR(255) DEFAULT NULL,
        status ENUM('draft','under_review','approved','published','archived') DEFAULT 'draft',
        current_version INT UNSIGNED DEFAULT 1,
        owner_id INT UNSIGNED DEFAULT NULL,
        review_frequency_days INT DEFAULT 365,
        next_review_date DATE DEFAULT NULL,
        last_review_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_forms (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(150) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL, title VARCHAR(190) DEFAULT '', description TEXT DEFAULT NULL, type ENUM('ticket','data') NOT NULL DEFAULT 'ticket', schema_json MEDIUMTEXT DEFAULT NULL, settings_json TEXT DEFAULT NULL, status ENUM('active','inactive') DEFAULT 'active', created_by INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_form_submissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, ticket_id INT UNSIGNED DEFAULT NULL, data_json MEDIUMTEXT DEFAULT NULL, submitter_name VARCHAR(190) DEFAULT NULL, submitter_email VARCHAR(190) DEFAULT NULL, ip_address VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_form (form_id), KEY idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sla_alerts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id INT UNSIGNED NOT NULL, kind VARCHAR(40) NOT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_ticket_kind (ticket_id,kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try {
                $col = $db->query("SHOW COLUMNS FROM itops_signature_signers LIKE 'sign_token'")->fetch();
                if (!$col) $db->exec("ALTER TABLE itops_signature_signers ADD COLUMN sign_token VARCHAR(64) DEFAULT NULL, ADD KEY idx_token (sign_token)");
            } catch (\Throwable $e) {}
            return 'Verified all feature tables exist.';
        },
        '2026_08_16_04_kb_gaps' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_kb_gaps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        query_norm VARCHAR(180) NOT NULL,
        query_text VARCHAR(500),
        hits INT UNSIGNED DEFAULT 1,
        answered_from VARCHAR(16) DEFAULT 'none',
        status ENUM('open','resolved') DEFAULT 'open',
        last_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_q (query_norm),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'KB content-gap tracking ready.';
        },
        '2026_08_16_03_onboarding' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        kind ENUM('onboard','offboard') NOT NULL DEFAULT 'onboard',
        description TEXT,
        department_id INT UNSIGNED DEFAULT NULL,
        status ENUM('active','archived') DEFAULT 'active',
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kind (kind, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_template_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        task_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        config MEDIUMTEXT,
        assignee_role VARCHAR(40) DEFAULT 'it',
        required TINYINT(1) DEFAULT 1,
        KEY idx_tpl (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED DEFAULT NULL,
        kind ENUM('onboard','offboard') NOT NULL DEFAULT 'onboard',
        subject_name VARCHAR(190) NOT NULL,
        subject_email VARCHAR(190) DEFAULT NULL,
        subject_upn VARCHAR(190) DEFAULT NULL,
        department_id INT UNSIGNED DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        status ENUM('in_progress','completed','cancelled') DEFAULT 'in_progress',
        notes TEXT,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        KEY idx_status (kind, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_ob_run_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        task_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        config MEDIUMTEXT,
        assignee_role VARCHAR(40) DEFAULT 'it',
        required TINYINT(1) DEFAULT 1,
        status ENUM('pending','done','skipped','failed') DEFAULT 'pending',
        result TEXT,
        completed_by INT UNSIGNED DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        KEY idx_run (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Onboarding/offboarding workflow tables ready.';
        },
        '2026_08_16_02_alerts' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_alerts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        alert_key VARCHAR(160) NOT NULL,
        level ENUM('warning','error') DEFAULT 'warning',
        channel VARCHAR(60) DEFAULT 'guardian',
        message VARCHAR(500),
        status ENUM('open','cleared') DEFAULT 'open',
        first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        cleared_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_key (alert_key),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Alerts table ready.';
        },
        '2026_08_16_01_event_log' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_event_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                level ENUM('error','warning','info','debug') NOT NULL DEFAULT 'info',
                channel VARCHAR(60) NOT NULL DEFAULT 'app',
                message VARCHAR(500) NOT NULL DEFAULT '',
                context JSON DEFAULT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT '',
                KEY idx_ts (ts),
                KEY idx_level_ts (level, ts),
                KEY idx_channel_ts (channel, ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Created itops_event_log.';
        },
        '2026_06_28_01_links' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        a_type VARCHAR(32) NOT NULL,
        a_id INT UNSIGNED NOT NULL,
        b_type VARCHAR(32) NOT NULL,
        b_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_a (a_type, a_id),
        KEY idx_b (b_type, b_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Cross-links table ready.';
        },
        '2026_06_27_01_jd_versions' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_jd_versions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        jd_id INT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        content LONGTEXT,
        change_summary TEXT,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_jd (jd_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try {
                $db->exec("INSERT INTO itops_jd_versions (jd_id,version_number,content,change_summary,created_by)
                           SELECT j.id, GREATEST(COALESCE(j.current_version,1),1), j.content, 'Existing version', j.owner_id
                           FROM itops_job_descriptions j
                           WHERE NOT EXISTS (SELECT 1 FROM itops_jd_versions v WHERE v.jd_id = j.id)");
            } catch (\Throwable $e) {}
            return 'Job Description version history ready.';
        },
        '2026_06_26_01_sign_tokens' => function (PDO $db): string {
            $col = $db->query("SHOW COLUMNS FROM itops_signature_signers LIKE 'sign_token'")->fetch();
            if (!$col) {
                $db->exec("ALTER TABLE itops_signature_signers ADD COLUMN sign_token VARCHAR(64) DEFAULT NULL, ADD KEY idx_token (sign_token)");
            }
            return 'External-signer tokens ready.';
        },
        '2026_06_25_01_signatures' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_signature_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_type VARCHAR(32) NOT NULL,
        doc_id INT UNSIGNED NOT NULL,
        doc_title VARCHAR(255),
        message TEXT,
        status ENUM('open','completed','cancelled') DEFAULT 'open',
        requested_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        KEY idx_doc (doc_type, doc_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_signature_signers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        signer_name VARCHAR(160),
        signer_email VARCHAR(190),
        status ENUM('pending','signed','declined') DEFAULT 'pending',
        signature_data MEDIUMTEXT,
        signature_type VARCHAR(16),
        signed_at DATETIME DEFAULT NULL,
        signed_ip VARCHAR(64),
        KEY idx_req (request_id),
        KEY idx_user (user_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Signature request tables ready.';
        },
        '2026_06_24_01_integrations' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_api_keys (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        key_prefix VARCHAR(32) NOT NULL,
        key_hash CHAR(64) NOT NULL,
        scopes TEXT,
        status ENUM('active','revoked') DEFAULT 'active',
        last_used_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_key_hash (key_hash),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_webhooks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        url VARCHAR(500) NOT NULL,
        secret VARCHAR(120) NOT NULL,
        events TEXT,
        status ENUM('active','paused') DEFAULT 'active',
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_webhook_deliveries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        webhook_id INT UNSIGNED NOT NULL,
        delivery_id VARCHAR(32) NOT NULL,
        event VARCHAR(64) NOT NULL,
        payload MEDIUMTEXT,
        response_status INT DEFAULT 0,
        error TEXT,
        attempts INT DEFAULT 1,
        status ENUM('delivered','failed') DEFAULT 'failed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_hook (webhook_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Integrations tables ready (API keys, webhooks, deliveries).';
        },
        '2026_06_23_01_job_descriptions' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_job_descriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) DEFAULT NULL,
        content LONGTEXT,
        department_id INT UNSIGNED DEFAULT NULL,
        reports_to VARCHAR(255) DEFAULT NULL,
        employment_type VARCHAR(64) DEFAULT NULL,
        location VARCHAR(255) DEFAULT NULL,
        status ENUM('draft','under_review','approved','published','archived') DEFAULT 'draft',
        current_version INT UNSIGNED DEFAULT 1,
        owner_id INT UNSIGNED DEFAULT NULL,
        review_frequency_days INT DEFAULT 365,
        next_review_date DATE DEFAULT NULL,
        last_review_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Job Descriptions table ready.';
        },
        '2026_06_15_01_sop_content' => function (PDO $db): string {
            $has = (int)$db->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'itops_sops' AND column_name = 'content'"
            )->fetchColumn();
            if ($has === 0) {
                $db->exec("ALTER TABLE itops_sops ADD COLUMN content LONGTEXT AFTER slug");
                return 'Added content column to itops_sops.';
            }
            return 'itops_sops.content already present.';
        },
        '2026_06_16_08_rename_terms' => function (PDO $db): string {
            $db->prepare("UPDATE itops_kb_categories SET name='Accreditation Evidence', slug='accreditation-evidence' WHERE slug='standards-evidence'")->execute();
            return 'Renamed legacy accreditation labels.';
        },
        '2026_06_15_07_forms' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_forms (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(150) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL, title VARCHAR(190) DEFAULT '', description TEXT DEFAULT NULL, type ENUM('ticket','data') NOT NULL DEFAULT 'ticket', schema_json MEDIUMTEXT DEFAULT NULL, settings_json TEXT DEFAULT NULL, status ENUM('active','inactive') DEFAULT 'active', created_by INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_form_submissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, ticket_id INT UNSIGNED DEFAULT NULL, data_json MEDIUMTEXT DEFAULT NULL, submitter_name VARCHAR(190) DEFAULT NULL, submitter_email VARCHAR(190) DEFAULT NULL, ip_address VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_form (form_id), KEY idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $exists = (int)$db->query("SELECT COUNT(*) FROM itops_forms WHERE slug='support'")->fetchColumn();
            if (!$exists) {
                $schema = json_encode(['fields' => [
                    ['key'=>'name','label'=>'Your name','type'=>'text','required'=>false,'maps_to'=>'requester_name','placeholder'=>'Jane Doe'],
                    ['key'=>'email','label'=>'Email','type'=>'email','required'=>true,'maps_to'=>'requester_email','placeholder'=>'jane@company.com'],
                    ['key'=>'category','label'=>'Category','type'=>'group_category','required'=>false,'maps_to'=>'category'],
                    ['key'=>'subject','label'=>'Subject','type'=>'text','required'=>true,'maps_to'=>'subject'],
                    ['key'=>'description','label'=>'Description','type'=>'textarea','required'=>true,'maps_to'=>'description'],
                ]]);
                $settings = json_encode(['success_message'=>'Thanks! Your request has been logged and we will follow up shortly.','redirect_url'=>'']);
                $db->prepare("INSERT INTO itops_forms (slug,name,title,description,type,schema_json,settings_json,status) VALUES ('support','Help Request','Submit a Help Request','Tell us what you need and we will get back to you.','ticket',?,?,'active')")->execute([$schema,$settings]);
            }
            return 'Forms tables ready.';
        },
        '2026_06_15_06_mfa_backup' => function (PDO $db): string {
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='itops_users' AND column_name='mfa_backup_codes'")->fetchColumn();
            if (!$has) { $db->exec("ALTER TABLE itops_users ADD COLUMN mfa_backup_codes TEXT NULL AFTER mfa_secret"); return 'Added mfa_backup_codes column.'; }
            return 'mfa_backup_codes already present.';
        },
        '2026_06_15_05_sop_version_backfill' => function (PDO $db): string {
            $db->exec("INSERT INTO itops_sop_versions (sop_id,version_number,content,change_summary,created_by)
                       SELECT s.id, COALESCE(s.current_version,1), s.content, 'Imported baseline', s.owner_id
                       FROM itops_sops s
                       WHERE NOT EXISTS (SELECT 1 FROM itops_sop_versions v WHERE v.sop_id=s.id)");
            return 'SOP baseline versions backfilled.';
        },
        '2026_06_15_04_sla_alerts' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sla_alerts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id INT UNSIGNED NOT NULL, kind VARCHAR(40) NOT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_ticket_kind (ticket_id,kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'SLA alert tracking table ready.';
        },
        '2026_06_15_03_sla_tracking' => function (PDO $db): string {
            $colExists = function($table,$col) use ($db) {
                return (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$table' AND column_name='$col'")->fetchColumn() > 0;
            };
            $done = [];
            if (!$colExists('itops_tickets','sla_response_due'))  { $db->exec("ALTER TABLE itops_tickets ADD COLUMN sla_response_due DATETIME NULL AFTER due_date"); $done[]='tickets.sla_response_due'; }
            if (!$colExists('itops_tickets','first_response_at'))  { $db->exec("ALTER TABLE itops_tickets ADD COLUMN first_response_at DATETIME NULL AFTER sla_response_due"); $done[]='tickets.first_response_at'; }
            if (!$colExists('itops_ticket_categories','sla_response_hours')) { $db->exec("ALTER TABLE itops_ticket_categories ADD COLUMN sla_response_hours INT NULL AFTER sla_hours"); $done[]='categories.sla_response_hours'; }
            // Backfill due dates for existing tickets that have none
            $db->exec("UPDATE itops_tickets t LEFT JOIN itops_ticket_categories c ON c.id=t.category_id
                       SET t.due_date = DATE_ADD(t.created_at, INTERVAL COALESCE(NULLIF(c.sla_hours,0), CASE t.priority WHEN 'p1_critical' THEN 4 WHEN 'p2_high' THEN 8 WHEN 'p3_medium' THEN 24 WHEN 'p4_low' THEN 48 ELSE 120 END) HOUR)
                       WHERE t.due_date IS NULL");
            $db->exec("UPDATE itops_tickets t LEFT JOIN itops_ticket_categories c ON c.id=t.category_id
                       SET t.sla_response_due = DATE_ADD(t.created_at, INTERVAL COALESCE(NULLIF(c.sla_response_hours,0), CASE t.priority WHEN 'p1_critical' THEN 1 WHEN 'p2_high' THEN 2 WHEN 'p3_medium' THEN 4 WHEN 'p4_low' THEN 8 ELSE 24 END) HOUR)
                       WHERE t.sla_response_due IS NULL");
            // Backfill first_response_at from the first non-requester comment
            $db->exec("UPDATE itops_tickets t
                       JOIN (SELECT c.ticket_id, MIN(c.created_at) fa FROM itops_ticket_comments c JOIN itops_tickets tt ON tt.id=c.ticket_id WHERE c.user_id<>tt.requester_id GROUP BY c.ticket_id) x ON x.ticket_id=t.id
                       SET t.first_response_at = x.fa WHERE t.first_response_at IS NULL");
            return 'SLA tracking ready' . ($done ? ' (added: '.implode(', ',$done).')' : '') . '; due dates backfilled.';
        },
        '2026_06_15_02_content_library' => function (PDO $db): string {
            $file = __DIR__ . '/../migrations/content_v1.php';
            if (!is_file($file)) return 'Content library file missing — skipped.';
            $stmts = include $file;
            $inserted = 0; $ran = 0;
            foreach ($stmts as $sql) {
                if (!is_string($sql) || $sql === '') continue;
                $affected = $db->exec($sql);
                $ran++;
                if ($affected) $inserted += (int)$affected;
            }
            return "Content library applied: {$ran} items processed, {$inserted} new rows inserted.";
        },
        '2026_09_22_01_vendor_contacts' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_vendor_contacts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vendor_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                title VARCHAR(150) DEFAULT '',
                email VARCHAR(255) DEFAULT '',
                phone VARCHAR(50) DEFAULT '',
                role ENUM('general','billing','support','sales','technical','escalation') DEFAULT 'general',
                is_primary TINYINT(1) DEFAULT 0,
                notes VARCHAR(500) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_vc_vendor (vendor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Vendor contacts table ready.';
        },

        /* 0621-BS — Granular roles. Rewrite the SEEDED system roles from the legacy
         * string vocabulary ("crud", "read_all", "own"...) to explicit per-action arrays
         * covering every module in the app, so the Roles editor shows their real access.
         * Only touches a role whose permissions JSON is still byte-identical to the
         * original seed — any role an admin has customised is left exactly as-is. */
        '2026_09_22_02_roles_granular' => function (PDO $db): string {
            $seed = [
                'dept_admin' => '{"sops":"crud","docs":"crud","creds":"read_use","contracts":"crud","kb":"crud","tickets":"crud","users":"dept_manage","reports":"dept"}',
                'manager'    => '{"sops":"crud","docs":"crud","creds":"read","contracts":"read","kb":"crud","tickets":"crud","reports":"dept"}',
                'editor'     => '{"sops":"create_edit","docs":"crud","creds":"none","contracts":"read","kb":"create_edit","tickets":"own","reports":"none"}',
                'viewer'     => '{"sops":"read","docs":"read","creds":"none","contracts":"read_assigned","kb":"read","tickets":"own","reports":"none"}',
                'auditor'    => '{"sops":"read_all","docs":"read_all","creds":"audit","contracts":"read_all","kb":"read_all","tickets":"read_all","reports":"all","audit":"read"}',
            ];
            $V = ['view']; $VCE = ['view','create','edit']; $ALL = ['view','create','edit','delete'];
            $new = [
                'dept_admin' => ['tickets'=>$ALL,'calendar'=>$ALL,'reports'=>$V,'scorecard'=>$ALL,'standards'=>$VCE,
                                 'kb'=>$ALL,'kb_assist'=>$V,'sops'=>$ALL,'job_descriptions'=>$ALL,
                                 'assets'=>$ALL,'creds'=>$VCE,'contracts'=>$ALL,'vendors'=>$ALL,
                                 'm365'=>$V,'guardian'=>$V,'onboarding'=>$ALL,
                                 'users'=>$ALL,'roles'=>$V,'categories'=>$ALL,'forms'=>$ALL,'import'=>$V,
                                 'audit'=>$V,'event_log'=>$V,'kb_gaps'=>['view','edit'],'changelog'=>$V],
                'manager'    => ['tickets'=>$ALL,'calendar'=>$ALL,'reports'=>$V,'scorecard'=>$ALL,'standards'=>['view','edit'],
                                 'kb'=>$ALL,'kb_assist'=>$V,'sops'=>$ALL,'job_descriptions'=>$ALL,
                                 'assets'=>$VCE,'creds'=>$V,'contracts'=>$V,'vendors'=>$V,'onboarding'=>$VCE,'changelog'=>$V],
                'editor'     => ['tickets'=>$VCE,'calendar'=>$V,'kb'=>$VCE,'kb_assist'=>$V,'sops'=>$VCE,'job_descriptions'=>$V,
                                 'assets'=>$V,'contracts'=>$V,'vendors'=>$V,'changelog'=>$V],
                'viewer'     => ['tickets'=>['view','create'],'calendar'=>$V,'kb'=>$V,'kb_assist'=>$V,'sops'=>$V,'job_descriptions'=>$V,
                                 'assets'=>$V,'contracts'=>$V,'vendors'=>$V,'changelog'=>$V],
                'auditor'    => ['tickets'=>$V,'calendar'=>$V,'reports'=>$V,'scorecard'=>$V,'standards'=>$V,
                                 'kb'=>$V,'kb_assist'=>$V,'sops'=>$V,'job_descriptions'=>$V,
                                 'assets'=>$V,'creds'=>$V,'contracts'=>$V,'vendors'=>$V,'m365'=>$V,'guardian'=>$V,'onboarding'=>$V,
                                 'users'=>$V,'roles'=>$V,'categories'=>$V,'forms'=>$V,'audit'=>$V,'event_log'=>$V,'changelog'=>$V],
            ];
            $done = 0; $kept = 0;
            $sel = $db->prepare('SELECT id, permissions FROM itops_roles WHERE slug=? LIMIT 1');
            $upd = $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?');
            foreach ($seed as $slug => $orig) {
                $sel->execute([$slug]); $row = $sel->fetch();
                if (!$row) continue;
                $stored = trim((string)$row['permissions']);
                // NB: == (order-insensitive) not === — key order in stored JSON must not matter.
                $same = ($stored === $orig) || (json_decode($stored, true) == json_decode($orig, true));
                if ($same) { $upd->execute([json_encode($new[$slug]), (int)$row['id']]); $done++; } else { $kept++; }
            }
            // Super Admin is always the wildcard.
            $db->exec("UPDATE itops_roles SET permissions='{\"*\":true}' WHERE slug='super_admin'");
            return "Granular roles: $done seeded role(s) upgraded to explicit per-module permissions, $kept customised role(s) left untouched.";
        },

        /* 0621-BS3 — Fix for the granular-roles migration above. The original used order-sensitive
         * comparison to decide whether a system role was "untouched", so any role whose stored JSON had
         * a different key order was wrongly treated as customised and left on the legacy format — which
         * is missing the newer menu items. This re-upgrades ANY of the five known system roles that is
         * still in the legacy string format (has a plain-string value, or the old "docs" key). Roles that
         * are already in the new per-action ARRAY format are left untouched, so an admin's own edits and
         * every custom role are preserved. Super Admin stays the wildcard. */
        '2026_09_22_03_roles_granular_fix' => function (PDO $db): string {
            $V = ['view']; $VCE = ['view','create','edit']; $ALL = ['view','create','edit','delete'];
            $new = [
                'dept_admin' => ['tickets'=>$ALL,'calendar'=>$ALL,'reports'=>$V,'scorecard'=>$ALL,'standards'=>$VCE,
                                 'kb'=>$ALL,'kb_assist'=>$V,'sops'=>$ALL,'job_descriptions'=>$ALL,
                                 'assets'=>$ALL,'creds'=>$VCE,'contracts'=>$ALL,'vendors'=>$ALL,
                                 'm365'=>$V,'guardian'=>$V,'onboarding'=>$ALL,
                                 'users'=>$ALL,'roles'=>$V,'categories'=>$ALL,'forms'=>$ALL,'import'=>$V,
                                 'audit'=>$V,'event_log'=>$V,'kb_gaps'=>['view','edit'],'changelog'=>$V],
                'manager'    => ['tickets'=>$ALL,'calendar'=>$ALL,'reports'=>$V,'scorecard'=>$ALL,'standards'=>['view','edit'],
                                 'kb'=>$ALL,'kb_assist'=>$V,'sops'=>$ALL,'job_descriptions'=>$ALL,
                                 'assets'=>$ALL,'creds'=>$V,'contracts'=>$ALL,'vendors'=>$ALL,'m365'=>$V,'guardian'=>$V,'onboarding'=>$ALL,'users'=>$V,'categories'=>$V,'forms'=>$V,'changelog'=>$V,'kb_gaps'=>['view','edit']],
                'editor'     => ['tickets'=>$VCE,'calendar'=>$V,'kb'=>$VCE,'kb_assist'=>$V,'sops'=>$VCE,'job_descriptions'=>$V,
                                 'assets'=>$V,'contracts'=>$V,'vendors'=>$V,'changelog'=>$V],
                'viewer'     => ['tickets'=>['view','create'],'calendar'=>$V,'kb'=>$V,'kb_assist'=>$V,'sops'=>$V,'job_descriptions'=>$V,
                                 'assets'=>$V,'contracts'=>$V,'vendors'=>$V,'changelog'=>$V],
                'auditor'    => ['tickets'=>$V,'calendar'=>$V,'reports'=>$V,'scorecard'=>$V,'standards'=>$V,
                                 'kb'=>$V,'kb_assist'=>$V,'sops'=>$V,'job_descriptions'=>$V,
                                 'assets'=>$V,'creds'=>$V,'contracts'=>$V,'vendors'=>$V,'m365'=>$V,'guardian'=>$V,'onboarding'=>$V,
                                 'users'=>$V,'roles'=>$V,'categories'=>$V,'forms'=>$V,'audit'=>$V,'event_log'=>$V,'changelog'=>$V],
            ];
            $isLegacy = function ($perms): bool {
                if (!is_array($perms) || !$perms) return true;         // empty/garbage → treat as legacy
                if (isset($perms['*'])) return false;                   // wildcard is not legacy
                if (array_key_exists('docs', $perms)) return true;      // old "docs" module key
                foreach ($perms as $v) { if (!is_array($v)) return true; } // any string/bool value = legacy format
                return false;
            };
            $done = 0; $kept = 0;
            $sel = $db->prepare('SELECT id, permissions FROM itops_roles WHERE slug=? LIMIT 1');
            $upd = $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?');
            foreach ($new as $slug => $granular) {
                $sel->execute([$slug]); $row = $sel->fetch();
                if (!$row) continue;
                $perms = json_decode((string)$row['permissions'], true);
                if ($isLegacy($perms)) { $upd->execute([json_encode($granular), (int)$row['id']]); $done++; }
                else { $kept++; }
            }
            $db->exec("UPDATE itops_roles SET permissions='{\"*\":true}' WHERE slug='super_admin'");
            return "Granular roles fix: $done legacy system role(s) upgraded, $kept already-granular role(s) left as-is.";
        },

        /* 0621-BS8 — outbound email log, credential history reveals, ticket record scope. */
        '2026_09_23_01_mail_log_ticket_scope' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_mail_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                to_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) DEFAULT '',
                method VARCHAR(20) DEFAULT '',
                kind VARCHAR(30) DEFAULT '',
                ticket_id INT UNSIGNED DEFAULT NULL,
                ok TINYINT(1) DEFAULT 0,
                error VARCHAR(1000) DEFAULT '',
                sent_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ml_created (created_at), KEY idx_ml_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try { $db->exec("ALTER TABLE itops_cred_access_log MODIFY action ENUM('view','copy','update','rotate','create','delete','view_history') NOT NULL"); } catch (Throwable $e) {}
            // Ticket record scope: roles that manage the queue get tickets:"all"; everyone else sees
            // only tickets they opened or are assigned. Applied to granular (array) roles only.
            $touched = 0;
            foreach (['dept_admin','manager','auditor'] as $slug) {
                $st = $db->prepare('SELECT id, permissions FROM itops_roles WHERE slug=?'); $st->execute([$slug]); $r = $st->fetch();
                if (!$r) continue;
                $p = json_decode((string)$r['permissions'], true);
                if (!is_array($p) || isset($p['*']) || !isset($p['tickets']) || !is_array($p['tickets'])) continue;
                if (!in_array('all', $p['tickets'], true)) { $p['tickets'][] = 'all'; $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p), (int)$r['id']]); $touched++; }
            }
            return "Mail log table ready; $touched queue role(s) granted tickets:all.";
        },

        /* 0621-BS9 — Terms & renewals for contracts and credentials (see lib/Terms.php). */
        '2026_09_23_02_terms' => function (PDO $db): string {
            $add = function (string $tbl, string $col, string $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE $tbl ADD COLUMN $ddl");
            };
            foreach (['itops_contracts', 'itops_credentials'] as $t) {
                $add($t, 'series_id',       'series_id INT UNSIGNED DEFAULT NULL');
                $add($t, 'term_no',         'term_no INT UNSIGNED NOT NULL DEFAULT 1');
                $add($t, 'renewed_from_id', 'renewed_from_id INT UNSIGNED DEFAULT NULL');
                $add($t, 'renewed_to_id',   'renewed_to_id INT UNSIGNED DEFAULT NULL');
                $add($t, 'renewal_note',    'renewal_note TEXT DEFAULT NULL');
                $add($t, 'ended_at',        'ended_at DATETIME DEFAULT NULL');
                $add($t, 'end_reason',      'end_reason VARCHAR(40) DEFAULT NULL');
                try { $db->exec("ALTER TABLE $t ADD KEY idx_series (series_id)"); } catch (Throwable $e) {}
                $db->exec("UPDATE $t SET series_id=id WHERE series_id IS NULL");
            }
            $add('itops_credentials', 'valid_from',  'valid_from DATE DEFAULT NULL');
            $add('itops_credentials', 'valid_until', 'valid_until DATE DEFAULT NULL');
            // Soft-delete writes status='archived', which the original ENUMs did not allow.
            try { $db->exec("ALTER TABLE itops_contracts MODIFY status ENUM('active','expiring','expired','renewed','terminated','draft','archived') DEFAULT 'active'"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE itops_credentials MODIFY status ENUM('current','due_soon','overdue','disabled','retired','archived') DEFAULT 'current'"); } catch (Throwable $e) {}
            // Existing contracts already marked 'renewed' by hand have no successor — treat them as closed history.
            $db->exec("UPDATE itops_contracts SET ended_at=COALESCE(ended_at, updated_at), end_reason=COALESCE(end_reason,'renewed') WHERE status='renewed' AND renewed_to_id IS NULL");
            return 'Term columns ready on contracts and credentials; every existing record is now term 1 of its own series.';
        },

        /* 0621-BT1 — editable email templates (English + Spanish) and per-user email language. */
        '2026_09_24_02_offboarding_verify' => function (PDO $db): string {
            $add = function ($tbl, $col, $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE $tbl ADD COLUMN $ddl");
            };
            $add('itops_ob_runs', 'm365_id', 'm365_id VARCHAR(64) DEFAULT NULL');
            $add('itops_ob_runs', 'vtiger_id', 'vtiger_id VARCHAR(32) DEFAULT NULL');
            $add('itops_ob_runs', 'verify_json', 'verify_json MEDIUMTEXT');
            $add('itops_ob_runs', 'verified_at', 'verified_at DATETIME DEFAULT NULL');
            $add('itops_ob_runs', 'verify_clean', 'verify_clean TINYINT(1) DEFAULT NULL');
            // Temporary passwords used to be kept in the task result — remove them for good.
            $n = 0;
            $up = $db->prepare('UPDATE itops_ob_run_tasks SET result=? WHERE id=?');
            foreach ($db->query("SELECT id, result FROM itops_ob_run_tasks WHERE result LIKE '%password:%'")->fetchAll() as $r) {
                $clean = preg_replace('/(temporary|temp) password:\s*\S+(\s*\([^)]*\))?/i', 'temporary password removed (it is no longer stored)', (string)$r['result']);
                if ($clean !== $r['result']) { $up->execute([$clean, (int)$r['id']]); $n++; }
            }
            return 'Offboarding verification columns ready; ' . $n . ' stored temporary password(s) removed from onboarding history.';
        },
        '2026_09_24_03_onboarding_profile' => function (PDO $db): string {
            $cols = ['first_name' => 'VARCHAR(80)', 'last_name' => 'VARCHAR(80)', 'job_title' => 'VARCHAR(120)', 'department' => 'VARCHAR(120)',
                     'manager_upn' => 'VARCHAR(190)', 'employee_id' => 'VARCHAR(60)', 'mobile_phone' => 'VARCHAR(40)', 'office_location' => 'VARCHAR(120)'];
            foreach ($cols as $c => $t) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_ob_runs' AND COLUMN_NAME='$c'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE itops_ob_runs ADD COLUMN $c $t DEFAULT NULL");
            }
            return 'Onboarding processes can now carry first/last name, job title, department, manager, employee ID, mobile and office.';
        },
        '2026_09_24_04_ob_schedule' => function (PDO $db): string {
            $cols = ['auto_run_at' => 'DATETIME', 'auto_run_status' => 'VARCHAR(20)', 'auto_run_by' => 'INT UNSIGNED', 'auto_run_log' => 'MEDIUMTEXT'];
            foreach ($cols as $c => $t) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_ob_runs' AND COLUMN_NAME='$c'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE itops_ob_runs ADD COLUMN $c $t DEFAULT NULL");
            }
            return 'Offboarding processes can now be scheduled (e.g. 5:00 pm on the last day).';
        },
        '2026_09_24_05_security_contacts' => function (PDO $db): string {
            // 1) Tickets from people who aren't ITOps users (email / web page / form) remember who they are,
            //    so replies and status emails reach THEM instead of the fallback admin account.
            foreach (['contact_email' => 'VARCHAR(190)', 'contact_name' => 'VARCHAR(160)'] as $c => $t) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_tickets' AND COLUMN_NAME='$c'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE itops_tickets ADD COLUMN $c $t DEFAULT NULL");
            }
            // Backfill from the "From: Name <email>" / "Submitted via web form by Name <email>" line older builds wrote.
            $n = 0; $up = $db->prepare('UPDATE itops_tickets SET contact_email=?, contact_name=? WHERE id=?');
            foreach ($db->query("SELECT id, description FROM itops_tickets WHERE contact_email IS NULL AND requester_id=1 AND (description LIKE 'From: %' OR description LIKE 'Submitted via web form by %')")->fetchAll() as $r) {
                if (preg_match('/^(?:From:|Submitted via web form by)\s*(.*?)\s*<([^>\s]+@[^>\s]+)>/', (string)$r['description'], $m)) { $up->execute([strtolower($m[2]), mb_substr(trim($m[1]), 0, 160), (int)$r['id']]); $n++; }
            }
            // 2) Remove password hashes / 2FA secrets that older builds copied into the audit log.
            $s = 0; $ua = $db->prepare('UPDATE itops_audit_log SET old_value=?, new_value=? WHERE id=?');
            foreach ($db->query("SELECT id, old_value, new_value FROM itops_audit_log WHERE entity_type='user' AND (old_value LIKE '%password_hash%' OR new_value LIKE '%password_hash%' OR old_value LIKE '%mfa_secret%' OR new_value LIKE '%mfa_secret%' OR old_value LIKE '%mfa_backup_codes%')")->fetchAll() as $r) {
                $clean = function ($j) { if ($j === null || $j === '') return $j; $a = json_decode($j, true); if (!is_array($a)) return $j; unset($a['password_hash'], $a['mfa_secret'], $a['mfa_backup_codes']); return json_encode($a); };
                $ua->execute([$clean($r['old_value']), $clean($r['new_value']), (int)$r['id']]); $s++;
            }
            return 'External ticket contacts ready (' . $n . ' older ticket(s) backfilled); ' . $s . ' audit entr(y/ies) cleaned of password hashes / 2FA secrets.';
        },
        '2026_09_24_01_email_templates' => function (PDO $db): string {
            $db->exec("CREATE TABLE IF NOT EXISTS itops_email_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tpl_key VARCHAR(60) NOT NULL,
                lang CHAR(2) NOT NULL DEFAULT 'en',
                subject VARCHAR(300) NOT NULL,
                body TEXT NOT NULL,
                updated_by INT UNSIGNED DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tpl_lang (tpl_key, lang)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_users' AND COLUMN_NAME='language'")->fetchColumn();
            if (!$has) $db->exec("ALTER TABLE itops_users ADD COLUMN language VARCHAR(5) DEFAULT NULL");
            return 'Email templates table ready; users can now have an email language (English / Español). Templates start from the built-in defaults.';
        },
        '2026_09_25_01_m365_policy_backups' => function (PDO $db): string {
            // Intune / app-protection policy backups (BT12): full JSON of each policy as Microsoft returned it,
            // so any policy type can be restored or cloned. One row per policy per backup run.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_m365_policy_backups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch VARCHAR(40) NOT NULL,
                kind VARCHAR(40) NOT NULL,
                policy_id VARCHAR(80) NOT NULL,
                name VARCHAR(255) NOT NULL DEFAULT '',
                platform VARCHAR(60) NOT NULL DEFAULT '',
                payload MEDIUMTEXT NOT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY k_policy (kind, policy_id), KEY k_batch (batch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Microsoft 365 policy backups table ready (Devices → All policies → Back up / Restore).';
        },
        '2026_09_24_06_vendor_archive' => function (PDO $db): string {
            // Deleting a vendor archives it (status='archived'); the column didn't allow that value.
            $col = (string)$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_vendors' AND COLUMN_NAME='status'")->fetchColumn();
            if ($col !== '' && stripos($col, "'archived'") === false)
                $db->exec("ALTER TABLE itops_vendors MODIFY status ENUM('active','inactive','under_review','archived') DEFAULT 'active'");
            return 'Vendors can now be deleted (they are archived and hidden from the list, keeping their history).';
        },
        '2026_09_27_01_ticket_routing' => function (PDO $db): string {
            // BT17: owner + backup per category (inherited from the group, then a default), backup on every ticket.
            $add = function ($tbl, $col, $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE $tbl ADD COLUMN $ddl");
            };
            $add('itops_ticket_categories', 'default_assignee_id', 'default_assignee_id INT UNSIGNED DEFAULT NULL');
            $add('itops_ticket_categories', 'backup_assignee_id', 'backup_assignee_id INT UNSIGNED DEFAULT NULL');
            $add('itops_tickets', 'backup_id', 'backup_id INT UNSIGNED DEFAULT NULL AFTER assignee_id');
            $hasIdx = (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_tickets' AND INDEX_NAME='idx_backup'")->fetchColumn();
            if (!$hasIdx) $db->exec('ALTER TABLE itops_tickets ADD INDEX idx_backup (backup_id)');
            return 'Ticket routing ready: set an owner and a backup on each category (Ticket Categories).';
        },
        '2026_10_04_01_device_inventory' => function (PDO $db): string {
            // BT27: every device seen signing in with a work account (Entra devices + sign-in log). Later sources
            // (Teams tab, desktop Companion) write into the same table, told apart by `source`.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_device_inventory (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                dkey VARCHAR(100) NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'entra',
                entra_id VARCHAR(64) DEFAULT NULL,
                device_id VARCHAR(64) DEFAULT NULL,
                name VARCHAR(190) NOT NULL DEFAULT '',
                os VARCHAR(60) NOT NULL DEFAULT '',
                os_version VARCHAR(60) NOT NULL DEFAULT '',
                os_label VARCHAR(60) NOT NULL DEFAULT '',
                os_family VARCHAR(12) NOT NULL DEFAULT 'other',
                kind VARCHAR(12) NOT NULL DEFAULT 'computer',
                ownership VARCHAR(14) NOT NULL DEFAULT 'unknown',
                trust_type VARCHAR(20) NOT NULL DEFAULT '',
                model VARCHAR(190) NOT NULL DEFAULT '',
                is_managed TINYINT(1) NOT NULL DEFAULT 0,
                is_compliant TINYINT(1) DEFAULT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                os_eol TINYINT(1) DEFAULT NULL,
                user_upn VARCHAR(190) NOT NULL DEFAULT '',
                user_name VARCHAR(190) NOT NULL DEFAULT '',
                owner_enabled TINYINT(1) DEFAULT NULL,
                owner_last_active DATETIME DEFAULT NULL,
                users_json TEXT,
                registered_at DATETIME DEFAULT NULL,
                last_seen DATETIME DEFAULT NULL,
                last_ip VARCHAR(64) NOT NULL DEFAULT '',
                last_location VARCHAR(190) NOT NULL DEFAULT '',
                last_app VARCHAR(120) NOT NULL DEFAULT '',
                last_client VARCHAR(120) NOT NULL DEFAULT '',
                seen_count INT UNSIGNED NOT NULL DEFAULT 0,
                in_directory TINYINT(1) NOT NULL DEFAULT 1,
                first_seen DATETIME DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_dkey (dkey),
                KEY idx_user (user_upn),
                KEY idx_seen (last_seen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Device inventory ready — open Microsoft 365 → Entra ID → Device inventory (needs the Graph permission Device.Read.All).';
        },
        '2026_10_05_01_announcements' => function (PDO $db): string {
            // BT28: announcements delivered in the app, by email and to a Teams channel, with "I have read this".
            // All date-times in these tables are UTC.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_announcements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                body TEXT NOT NULL,
                title_es VARCHAR(200) NOT NULL DEFAULT '',
                body_es TEXT,
                level VARCHAR(12) NOT NULL DEFAULT 'info',
                audience VARCHAR(10) NOT NULL DEFAULT 'all',
                requires_ack TINYINT(1) NOT NULL DEFAULT 0,
                ack_due DATE DEFAULT NULL,
                ch_email TINYINT(1) NOT NULL DEFAULT 1,
                teams_channel VARCHAR(40) NOT NULL DEFAULT '',
                remind_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(12) NOT NULL DEFAULT 'draft',
                publish_at DATETIME DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                published_by INT UNSIGNED DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                delivery_json TEXT,
                last_reminder_at DATETIME DEFAULT NULL,
                reminders_sent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_status (status, publish_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_announcement_audience (
                announcement_id INT UNSIGNED NOT NULL,
                kind VARCHAR(12) NOT NULL,
                ref_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (announcement_id, kind, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_announcement_recipients (
                announcement_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                email_state VARCHAR(10) NOT NULL DEFAULT 'none',
                email_at DATETIME DEFAULT NULL,
                seen_at DATETIME DEFAULT NULL,
                ack_at DATETIME DEFAULT NULL,
                reminded_at DATETIME DEFAULT NULL,
                PRIMARY KEY (announcement_id, user_id),
                KEY idx_user (user_id),
                KEY idx_email (email_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Announcements ready — open Announcements in the menu. To let someone publish, give their role "Announcements" under Roles & Permissions.';
        },
        '2026_10_06_01_teams_app' => function (PDO $db): string {
            // BT29: the Device inventory also records when a person opened the app (in Teams or a browser) and what the browser reported.
            $add = function ($col, $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_device_inventory' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE itops_device_inventory ADD COLUMN $ddl");
            };
            $add('app_at', 'app_at DATETIME DEFAULT NULL');
            $add('app_host', "app_host VARCHAR(20) NOT NULL DEFAULT ''");
            $add('app_client', "app_client VARCHAR(60) NOT NULL DEFAULT ''");
            $add('app_os', "app_os VARCHAR(60) NOT NULL DEFAULT ''");
            return 'Teams app ready — set it up under Logs & Integrations → Integrations → Microsoft Teams.';
        },
        '2026_10_07_01_people_links' => function (PDO $db): string {
            // BT44: "this person has this account in this system" — the record behind the company-system steps of
            // onboarding and offboarding, and the Access overview. A link is closed (status removed), never deleted.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_people_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                system_key VARCHAR(32) NOT NULL,
                email VARCHAR(190) NOT NULL,
                person_name VARCHAR(190) DEFAULT NULL,
                account VARCHAR(190) NOT NULL DEFAULT '',
                note VARCHAR(500) DEFAULT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'active',
                source VARCHAR(12) NOT NULL DEFAULT 'manual',
                created_by INT DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_by INT DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                removed_by INT DEFAULT NULL,
                removed_at DATETIME DEFAULT NULL,
                removed_run INT DEFAULT NULL,
                UNIQUE KEY uq_people_link (system_key, email, account),
                KEY k_people_link_email (email),
                KEY k_people_link_sys (system_key, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Company systems ready — choose the systems your company uses under Administration → Settings → Company systems.';
        },
        '2026_10_08_03_category_sla_decimal' => function (PDO $db): string {
            // BT52: category SLA hours may have decimals (0.5 = a 30-minute response target).
            $type = function ($col) use ($db) { return strtolower((string)$db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_ticket_categories' AND COLUMN_NAME='$col'")->fetchColumn()); };
            if ($type('sla_hours') !== 'decimal') $db->exec('ALTER TABLE itops_ticket_categories MODIFY sla_hours DECIMAL(7,2) DEFAULT 24');
            if ($type('sla_response_hours') !== 'decimal') $db->exec('ALTER TABLE itops_ticket_categories MODIFY sla_response_hours DECIMAL(7,2) DEFAULT NULL');
            return 'Ticket categories: SLA hours accept decimals (0.5 = 30 minutes).';
        },
        '2026_10_08_02_ticket_sla_pause' => function (PDO $db): string {
            // BT52: Pending pauses the SLA clock. When the pause started, and how many working seconds the ticket has spent
            // in Pending so far (added to the due times when they are recalculated).
            $add = function ($col, $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_tickets' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE itops_tickets ADD COLUMN $ddl");
            };
            $add('sla_paused_at', 'sla_paused_at DATETIME DEFAULT NULL');
            $add('sla_paused_secs', 'sla_paused_secs INT NOT NULL DEFAULT 0');
            return 'Tickets: Pending now pauses the SLA clock, and the due times move on when the ticket leaves Pending.';
        },
        '2026_10_08_01_people_links_ext' => function (PDO $db): string {
            // BT45: a link that is read from a connected system (JustCall first) carries that system's id for the account,
            // the email the system reports, what the system says about it, and whether a person assigned it by hand.
            $add = function ($col, $ddl) use ($db) {
                $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_people_links' AND COLUMN_NAME='$col'")->fetchColumn();
                if (!$has) $db->exec("ALTER TABLE itops_people_links ADD COLUMN $ddl");
            };
            $add('ext_id', 'ext_id VARCHAR(64) DEFAULT NULL');
            $add('ext_email', 'ext_email VARCHAR(190) DEFAULT NULL');
            $add('pinned', 'pinned TINYINT NOT NULL DEFAULT 0');
            $add('detail', 'detail TEXT DEFAULT NULL');
            $add('seen_at', 'seen_at DATETIME DEFAULT NULL');
            $hasIx = (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_people_links' AND INDEX_NAME='k_people_link_ext'")->fetchColumn();
            if (!$hasIx) $db->exec("ALTER TABLE itops_people_links ADD KEY k_people_link_ext (system_key, ext_id)");
            return 'Company systems can now be connected — JustCall first: Administration → Settings → Company systems → Systems → JustCall.';
        },
        '2026_10_01_01_db_snapshots' => function (PDO $db): string {
            // BT26: one row per day with the size of every ITOps table, for the growth chart on Settings → Maintenance.
            $db->exec("CREATE TABLE IF NOT EXISTS itops_db_snapshots (
                day DATE NOT NULL PRIMARY KEY,
                total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                rows_est BIGINT UNSIGNED NOT NULL DEFAULT 0,
                tables_json MEDIUMTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return 'Database size history ready — a snapshot is taken once a day (Settings → Maintenance).';
        },
        '2026_09_30_01_sop_audience' => function (PDO $db): string {
            // BT24: SOPs get an owner (reviews it) and an audience (who must read and acknowledge it).
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_sops' AND COLUMN_NAME='audience'")->fetchColumn();
            if (!$has) $db->exec("ALTER TABLE itops_sops ADD COLUMN audience VARCHAR(20) NOT NULL DEFAULT 'all' AFTER owner_id");
            $db->exec("CREATE TABLE IF NOT EXISTS itops_sop_audience (
                sop_id INT UNSIGNED NOT NULL,
                kind VARCHAR(12) NOT NULL,
                ref_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (sop_id, kind, ref_id),
                KEY idx_ref (kind, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $noOwner = (int)$db->query("SELECT COUNT(*) FROM itops_sops WHERE owner_id IS NULL AND status<>'archived'")->fetchColumn();
            return 'SOP owner and audience ready. Existing SOPs keep "Everyone" as their audience' . ($noOwner ? "; $noOwner SOP(s) have no owner yet — set one in Knowledge → SOPs." : '.');
        },
        '2026_09_29_01_m365_hub' => function (PDO $db): string {
            // BT21: Microsoft 365 area.
            // 1) Onboarding templates (Microsoft 365 fields, licenses, groups, manager + vTiger defaults).
            $db->exec("CREATE TABLE IF NOT EXISTS itops_m365_templates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                description VARCHAR(500) NULL,
                m365_json MEDIUMTEXT NULL,
                vtiger_json TEXT NULL,
                legacy_template_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                KEY idx_legacy (legacy_template_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // 2) Existing onboarding checklists → new templates (the old tables are left untouched for history / rollback).
            $conv = 0; $dropped = 0;
            try {
                $have = $db->query("SELECT legacy_template_id FROM itops_m365_templates WHERE legacy_template_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                $tk = $db->prepare("SELECT task_type, config FROM itops_ob_template_tasks WHERE template_id=? ORDER BY sort_order, id");
                foreach ($db->query("SELECT * FROM itops_ob_templates WHERE kind='onboard'")->fetchAll() as $t) {
                    if (in_array((string)$t['id'], array_map('strval', $have), true)) continue;
                    $m = ['licenses' => [], 'groups' => [], 'usage_location' => 'US', 'fields' => [], 'manager' => null];
                    $v = ['create' => false, 'roleid' => '', 'status' => 'Active'];
                    $tk->execute([(int)$t['id']]);
                    foreach ($tk->fetchAll() as $x) {
                        $c = json_decode((string)$x['config'], true) ?: [];
                        switch ($x['task_type']) {
                            case 'm365_create':
                                if (!empty($c['usageLocation'])) $m['usage_location'] = strtoupper(substr((string)$c['usageLocation'], 0, 2));
                                foreach ((array)($c['fields'] ?? []) as $fk => $fv) if (trim((string)$fv) !== '') $m['fields'][$fk] = trim((string)$fv);
                                if (!empty($c['department'])) $m['fields']['department'] = (string)$c['department'];
                                if (!empty($c['jobTitle'])) $m['fields']['jobTitle'] = (string)$c['jobTitle'];
                                break;
                            case 'm365_license':
                                foreach ((array)($c['skus'] ?? []) as $sk) if (!empty($sk['skuId'])) $m['licenses'][] = (string)$sk['skuId'];
                                if (!empty($c['skuId'])) $m['licenses'][] = (string)$c['skuId'];
                                break;
                            case 'm365_group':
                                foreach ((array)($c['groups'] ?? []) as $g) if (!empty($g['id'])) $m['groups'][] = ['id' => (string)$g['id'], 'name' => (string)($g['name'] ?? $g['id'])];
                                if (!empty($c['groupId'])) $m['groups'][] = ['id' => (string)$c['groupId'], 'name' => (string)($c['groupLabel'] ?? $c['groupId'])];
                                break;
                            case 'vtiger_create':
                                $v['create'] = true; $v['roleid'] = (string)($c['roleid'] ?? '');
                                break;
                            default: $dropped++;
                        }
                    }
                    $m['licenses'] = array_values(array_unique($m['licenses']));
                    $m['department'] = $m['fields']['department'] ?? ''; $m['job_title'] = $m['fields']['jobTitle'] ?? '';
                    $db->prepare("INSERT INTO itops_m365_templates (name, description, m365_json, vtiger_json, legacy_template_id, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())")
                       ->execute([(string)$t['name'], (string)($t['description'] ?? ''), json_encode($m), json_encode($v), (int)$t['id'], $t['created_by'] ?? null]);
                    $conv++;
                }
            } catch (\Throwable $e) { /* onboarding tables not installed — nothing to convert */ }
            // 3) Permissions: the Microsoft 365 split. Roles saved by the granular editor get the new modules from what they had
            //    (M365 Admin edit → accounts, credentials, licenses, groups; Onboarding edit → vTiger onboard / offboard).
            //    Microsoft directory roles (m365_roles) are granted to nobody automatically.
            $n = 0;
            foreach ($db->query('SELECT id, slug, permissions FROM itops_roles')->fetchAll() as $r) {
                if (($r['slug'] ?? '') === 'super_admin') continue;
                $p = json_decode((string)$r['permissions'], true);
                if (!is_array($p)) continue;
                $granular = false; foreach ($p as $pv) { if (is_array($pv)) { $granular = true; break; } }
                if (!$granular) continue;                                    // legacy string roles inherit (itops_can)
                $has = fn($m, $a) => isset($p[$m]) && is_array($p[$m]) && in_array($a, $p[$m], true);
                $chg = false;
                if ($has('m365_admin', 'edit')) foreach (['m365_users', 'm365_credentials', 'm365_licenses', 'm365_groups'] as $m) if (!array_key_exists($m, $p)) { $p[$m] = ['view', 'edit']; $chg = true; }
                if ($has('onboarding', 'edit') || $has('onboarding', 'create')) foreach (['provision_onboard', 'provision_offboard'] as $m) if (!array_key_exists($m, $p)) { $p[$m] = ['view', 'edit']; $chg = true; }
                if ($chg) { $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p), $r['id']]); $n++; }
            }
            return "Microsoft 365 area: templates table ready, $conv onboarding template(s) converted"
                 . ($dropped ? " ($dropped checklist step(s) that are not Microsoft 365 / vTiger settings were not carried over)" : '')
                 . "; $n role(s) received the new Microsoft 365 permissions from their existing access.";
        },
        '2026_09_28_01_settings_areas' => function (PDO $db): string {
            // BT18: the single "Connection & Settings" grant became four areas (settings, branding, email_cfg, sla_cfg).
            // Roles saved by the granular editor inherit nothing, so copy their old "settings" grant to the new areas
            // Nobody gains or loses access.
            $n = 0;
            foreach ($db->query('SELECT id, slug, permissions FROM itops_roles')->fetchAll() as $r) {
                if (($r['slug'] ?? '') === 'super_admin') continue;
                $p = json_decode((string)$r['permissions'], true);
                if (!is_array($p) || !array_key_exists('settings', $p)) continue;
                $granular = false; foreach ($p as $pv) { if (is_array($pv)) { $granular = true; break; } }
                if (!$granular) continue;                                    // legacy string roles keep inheriting
                $base = is_array($p['settings']) ? array_values(array_intersect($p['settings'], ['view', 'edit'])) : [];
                $chg = false;
                foreach (['branding', 'email_cfg', 'sla_cfg'] as $m) { if (!array_key_exists($m, $p)) { $p[$m] = $base; $chg = true; } }
                if ($chg) { $db->prepare('UPDATE itops_roles SET permissions=? WHERE id=?')->execute([json_encode($p), $r['id']]); $n++; }
            }
            return "Settings access is now granted per area (General, Branding, Email, SLA & Hours); $n role(s) carried their existing access over.";
        },
        '2026_09_26_01_integration_audit' => function (PDO $db): string {
            // BT16: Integration Audit & Observability. Additive only — ten new tables and a registry seed.
            // Changes NOTHING on production: no MariaDB/Apache/logging setting, no grant, no collector switched on.
            // Rollback: DROP the ten itops_integration_* / itops_collector_runs / itops_server_metric_samples tables and
            // DELETE FROM itops_settings WHERE skey LIKE 'ia\_%' (see BT16-RELEASE-NOTES.md).
            require_once __DIR__ . '/IntegrationAudit.php';
            foreach (itops_ia_schema() as $ddl) $db->exec($ddl);
            // Seed: integrations already known or suspected. Everything not taken from ITOps's own configuration is
            // a CANDIDATE / UNVERIFIED lead (handoff §19) — nothing here attributes activity to Microsoft Fabric.
            $vdb = [];
            try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='vtiger_db'")->fetchColumn(); $vdb = $v ? (json_decode((string)$v, true) ?: []) : []; } catch (\Throwable $e) {}
            $vprov = [];
            try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='vtiger_provision'")->fetchColumn(); $vprov = $v ? (json_decode((string)$v, true) ?: []) : []; } catch (\Throwable $e) {}
            $bridgeHost = !empty($vprov['bridge_url']) ? (string)parse_url((string)$vprov['bridge_url'], PHP_URL_HOST) : '';
            $J = fn(array $a) => json_encode(array_values(array_filter($a)));
            $seed = [
                ['itops_vtiger_db', 'ITOps — vTiger DB lookup', 'itops', 'active', !empty($vdb['user']) ? 'partial' : 'unverified', 'IT (ITOps)',
                 'Read-only duplicate check before a vTiger user is created during onboarding (ItopsVTigerDb, SELECT only, 8 s timeout).',
                 'ITOps', 'vTiger MariaDB', 'MariaDB (PDO, SELECT only)', 'DB password (encrypted in ITOps)', (string)($vdb['user'] ?? ''),
                 $J([(string)($vdb['user'] ?? '')]), $J([]), $J([]), $J([]), $J(['ItopsVTigerDb']), 'vtiger_users (read)', 'ITOps event log (channel vtiger)', 1,
                 !empty($vdb['user']) ? 'DB account taken from ITOps\'s own configuration.' : 'vTiger DB lookup not configured in ITOps yet.', 'event'],
                ['itops_bridge', 'ITOps — vTiger bridge', 'itops', 'active', 'partial', 'IT (ITOps)',
                 'Creates / updates vTiger users through vTiger\'s own model (itops-bridge.php, signed requests).',
                 'ITOps', 'vTiger (PHP bridge)', 'HTTPS → itops-bridge.php', 'HMAC shared secret (encrypted in ITOps)', '',
                 $J([]), $J([]), $J(['*itops-bridge.php*']), $J([]), $J(['itops-bridge']), 'vTiger users', 'ITOps event log (channel vtiger)', 1,
                 'Endpoint from the shipped bridge' . ($bridgeHost !== '' ? ' — configured host ' . $bridgeHost : '') . '.', 'event'],
                ['itops_cron', 'ITOps — scheduled jobs', 'itops', 'active', 'partial', 'IT (ITOps)',
                 'cron-sla.php, cron-mail.php and the in-app tick: SLA scan, mail intake, reminders, scheduled offboarding.',
                 'ITOps', 'ITOps database / mailbox', 'cron (cPanel) or in-app tick', 'Cron token header', '',
                 $J([]), $J([]), $J(['*cron-sla.php*', '*cron-mail.php*', '*cron-audit.php*', '*/api/tick*']), $J([]), $J(['cron-sla.php', 'cron-mail.php', 'cron-audit.php']), 'ITOps only', 'ITOps event log (channel cron)', 1,
                 'Usually every 5 minutes — confirm against the real cron list.', 'interval'],
                ['server_backups', 'Server backups (cPanel / WHM)', 'backup', 'candidate', 'unverified', '',
                 'cPanel/WHM account and database backups; can read every table.',
                 'Server', 'Backup destination', 'cPanel backup / mysqldump', '', '',
                 $J([]), $J([]), $J([]), $J([]), $J(['pkgacct', 'mysqldump', 'backup_cpanel']), 'All databases', 'Server Guardian backup check + backup logs', 0,
                 'Schedule and window to be confirmed from WHM backup configuration / logs.', 'unknown'],
            ];
            // BT96: only what every installation has; vTiger's own scheduler only where vTiger is connected. Leads found on
            // one installation are entered there by hand, never shipped.
            if (!empty($vdb['user']) || !empty($vprov['endpoint']) || !empty($vprov['bridge_url'])) $seed[] = ['vtiger_cron', 'vTiger internal cron', 'cron', 'candidate', 'unverified', '',
                 'vTiger\'s own scheduler (vtigercron.php): workflows, reports, recurring tasks.',
                 'vTiger', 'vTiger MariaDB', 'cron → PHP', '', '',
                 $J([]), $J([]), $J(['*vtigercron.php*', '*cron/vtigercron*']), $J([]), $J(['vtigercron']), '', 'Cron inventory import', 0,
                 'Known recurring job — frequency to be confirmed from the cron inventory.', 'unknown'];
            $ins = $db->prepare('INSERT IGNORE INTO itops_integration_registry (seed_key,name,type,status,verification,owner,purpose,source_system,destination_system,
                connection_method,auth_type,account_identifier,db_accounts,source_hosts,endpoints,user_agents,job_identifiers,data_scope,monitoring_method,itops_managed,notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $sch = $db->prepare('INSERT INTO itops_integration_schedules (integration_id,schedule_type,interval_minutes,frequency_label,timezone,notes) VALUES (?,?,?,?,?,?)');
            $made = 0;
            foreach ($seed as $r) {
                $stype = array_pop($r);
                $ins->execute($r);
                if ($ins->rowCount() === 1) {
                    $id = (int)$db->lastInsertId(); $made++;
                    $sch->execute([$id, $stype, $stype === 'interval' ? 5 : null, $stype === 'interval' ? 'Every 5 minutes (to confirm)' : ($stype === 'event' ? 'On demand (onboarding)' : 'Under investigation'),
                                   date_default_timezone_get(), 'Seeded by BT16 — review.']);
                }
            }
            return 'Integration audit tables ready; ' . $made . ' registry item(s) seeded (candidates unverified). All collectors OFF.';
        },
        '2026_09_25_02_encrypt_secrets' => function (PDO $db): string {
            // BT14: encrypt integration secrets that were stored as plaintext, and unify the Microsoft 365 app
            // configuration. Every value is encrypted AND decrypted back before the plaintext is replaced; nothing is
            // logged. If the vault key is missing or invalid this throws, so the migration is retried after fixing it.
            require_once __DIR__ . '/Secrets.php';
            if (!itops_vault_ok()) throw new RuntimeException('VAULT_ENCRYPTION_KEY in config.php is missing or invalid — secrets were left unchanged. Fix config.php and run db-update.php again.');
            $get = function ($k) use ($db) { $st = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v; };
            $put = function ($k, $v) use ($db) { $db->prepare('INSERT INTO itops_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')->execute([$k, $v]); };
            $del = function ($k) use ($db) { $db->prepare('DELETE FROM itops_settings WHERE skey=?')->execute([$k]); };
            $done = []; $warn = [];
            // 1) Microsoft 365: one tenant, one encrypted secret.
            $tid = $get('o365_tenant_id'); $ten = $get('o365_tenant');
            if (($tid ?? '') === '' && ($ten ?? '') !== '') { $put('o365_tenant_id', $ten); $done[] = 'tenant synced'; }
            if (($ten ?? '') === '' && ($tid ?? '') !== '') { $put('o365_tenant', $tid); $done[] = 'tenant synced'; }
            if (($tid ?? '') !== '' && ($ten ?? '') !== '' && strcasecmp($tid, $ten) !== 0) $warn[] = 'the Graph and sign-in cards have different tenant values — check Integrations → System';
            $plain = $get('o365_client_secret');
            if (($plain ?? '') !== '') {
                $encOld = $get('o365_client_secret_enc'); $cur = '';
                if (($encOld ?? '') !== '') { try { $cur = vault_decrypt($encOld); } catch (\Throwable $e) { $cur = ''; } }
                $enc = vault_encrypt($plain);
                if (vault_decrypt($enc) !== $plain) throw new RuntimeException('Encryption self-check failed — Microsoft 365 secret left unchanged.');
                if ($cur === '') { $put('o365_client_secret_enc', $enc); $done[] = 'Microsoft 365 secret encrypted'; }
                elseif (hash_equals($cur, $plain)) { $done[] = 'Microsoft 365 secret already encrypted (duplicate plaintext removed)'; }
                else { $put('o365_graph_secret_enc', $enc); $warn[] = 'two different Microsoft 365 secrets were saved — both kept encrypted; save the current one once in Integrations → System'; }
                $del('o365_client_secret');
            }
            // 2) Guardian WHM token / IMAP password (in place, prefixed).
            foreach (itops_secret_setting_keys() as $k) {
                $v = $get($k); if (($v ?? '') === '' || itops_secret_is_wrapped($v)) continue;
                $w = itops_secret_wrap_checked($v); if ($w === null) throw new RuntimeException('Encryption self-check failed for ' . $k);
                $put($k, $w); $done[] = $k . ' encrypted';
            }
            // 3) vTiger JSON blobs.
            $F = itops_secret_vtiger_fields();
            foreach (['vtiger_provision' => $F['prov'], 'vtiger_db' => $F['db']] as $k => $fields) {
                $raw = $get($k); if (($raw ?? '') === '') continue;
                $a = json_decode($raw, true); if (!is_array($a)) continue;
                $n = 0;
                foreach ($fields as $f) if (!empty($a[$f]) && is_string($a[$f]) && !itops_secret_is_wrapped($a[$f])) {
                    $w = itops_secret_wrap_checked($a[$f]); if ($w === null) throw new RuntimeException('Encryption self-check failed for ' . $k . '.' . $f);
                    $a[$f] = $w; $n++;
                }
                if ($n) { $put($k, json_encode($a)); $done[] = $k . ' (' . $n . ' field' . ($n > 1 ? 's' : '') . ') encrypted'; }
            }
            // 4) Webhook signing secrets.
            try {
                $rows = $db->query('SELECT id, secret FROM itops_webhooks')->fetchAll();
                $up = $db->prepare('UPDATE itops_webhooks SET secret=? WHERE id=?'); $n = 0;
                foreach ($rows as $r) {
                    if (($r['secret'] ?? '') === '' || itops_secret_is_wrapped($r['secret'])) continue;
                    $w = itops_secret_wrap_checked((string)$r['secret']); if ($w === null || strlen($w) > 120) { $warn[] = 'webhook #' . $r['id'] . ' secret left as is (too long to encrypt in place)'; continue; }
                    $up->execute([$w, $r['id']]); $n++;
                }
                if ($n) $done[] = $n . ' webhook secret(s) encrypted';
            } catch (\Throwable $e) { /* no webhooks table yet */ }
            return ($done ? implode('; ', $done) : 'No plaintext secrets found') . ($warn ? ' — NOTE: ' . implode('; ', $warn) : '') . '.';
        },
    ];
}

function itops_migrations_table(PDO $db): void {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS itops_migrations (
            version VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function itops_applied_versions(PDO $db): array {
    itops_migrations_table($db);
    return $db->query("SELECT version FROM itops_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function itops_pending_versions(PDO $db): array {
    $applied = itops_applied_versions($db);
    return array_values(array_diff(array_keys(itops_migrations_list()), $applied));
}

/** Run all pending migrations; returns a per-migration report. Stops at the first error. */
/**
 * BT95: names used by builds before 0621-BT95 (a framework's and an accreditation body's names in tables, columns, keys and
 * migration ids) are moved to the neutral ones before anything else runs. Idempotent: on a new installation, or once done,
 * it finds nothing to move. To be removed once every installation runs 0621-BT95 or later.
 */
function itops_legacy_rename(PDO $db): array {
    $L = ['c' => 'c' . 'arf', 'i' => 'i' . 'til'];   // the legacy words, only here
    $done = [];
    $tbl = function (string $t) use ($db): bool { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $q->execute([$t]); return (int)$q->fetchColumn() > 0; };
    $col = function (string $t, string $c) use ($db): bool { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $q->execute([$t, $c]); return (int)$q->fetchColumn() > 0; };
    try {
        // migration ids
        if ($tbl('itops_migrations')) foreach (['2026_08_16_06_ob_duedates_' . $L['c'] => '2026_08_16_06_ob_duedates_std', '2026_10_20_01_' . $L['i'] . '_basics' => '2026_10_20_01_svc_basics', '2026_10_21_01_' . $L['i'] . '_changes' => '2026_10_21_01_svc_changes'] as $o => $n) {
            $q = $db->prepare('SELECT COUNT(*) FROM itops_migrations WHERE version=?'); $q->execute([$o]);
            if ((int)$q->fetchColumn()) { $db->prepare('INSERT IGNORE INTO itops_migrations (version) VALUES (?)')->execute([$n]); $db->prepare('DELETE FROM itops_migrations WHERE version=?')->execute([$o]); $done['ids'] = 1; }
        }
        // tables
        foreach (['itops_' . $L['c'] . '_requirements' => 'itops_std_requirements', 'itops_' . $L['c'] . '_evidence' => 'itops_std_evidence', 'itops_' . $L['c'] . '_domains' => 'itops_std_domains', 'itops_case_' . $L['i'] => 'itops_case_svc'] as $o => $n) {
            if (!$tbl($o)) continue;
            if ($tbl($n)) { if ((int)$db->query("SELECT COUNT(*) FROM `$n`")->fetchColumn() > 0) continue; $db->exec("DROP TABLE `$n`"); }
            $db->exec("RENAME TABLE `$o` TO `$n`"); $done['tables'][] = $n;
        }
        // columns
        foreach (['itops_ob_runs', 'itops_ob_templates'] as $t) if ($tbl($t) && $col($t, $L['c'] . '_req_ids') && !$col($t, 'std_req_ids')) { $db->exec("ALTER TABLE `$t` CHANGE `" . $L['c'] . "_req_ids` std_req_ids TEXT NULL"); $done['columns'] = 1; }
        // keys stored in data: permissions, the read-only reviewer role, links, reminders sent, email texts, bell links, Audit Log kinds
        if ($tbl('itops_roles')) {
            $n = $db->exec("UPDATE itops_roles SET permissions=REPLACE(permissions, '\"" . $L['c'] . "\":', '\"standards\":') WHERE permissions LIKE '%\"" . $L['c'] . "\":%'");
            $n += $db->exec("UPDATE itops_roles SET slug='ext_reviewer', name=IF(name='Surv' 'eyor','External reviewer',name), description='Read-only access to the Standards & Audits area only (for an outside reviewer or auditor).' WHERE slug='surv' 'eyor'");
            if ($n) $done['roles'] = $n;
        }
        if ($tbl('itops_links')) { $n = $db->exec("UPDATE itops_links SET a_type='std_req' WHERE a_type='" . $L['c'] . "_req'") + $db->exec("UPDATE itops_links SET b_type='std_req' WHERE b_type='" . $L['c'] . "_req'"); if ($n) $done['links'] = $n; }
        if ($tbl('itops_settings')) $db->exec("UPDATE IGNORE itops_settings SET skey=CONCAT('rem_std_', SUBSTRING(skey, " . (strlen('rem_' . $L['c'] . '_') + 1) . ")) WHERE skey LIKE 'rem\\_" . $L['c'] . "\\_%'");
        if ($tbl('itops_email_templates')) $db->exec("UPDATE itops_email_templates SET tpl_key=REPLACE(tpl_key, '" . $L['c'] . "_review_', 'std_review_') WHERE tpl_key LIKE '" . $L['c'] . "\\_review\\_%'");
        if ($tbl('itops_notifications')) $db->exec("UPDATE itops_notifications SET link=REPLACE(link, '" . $L['c'] . "', 'standards') WHERE link IN ('" . $L['c'] . "', '" . $L['c'] . "_dashboard')");
        if ($tbl('itops_audit_log')) {
            $n = $db->exec("UPDATE itops_audit_log SET action=REPLACE(action, '" . $L['c'] . "_', 'std_') WHERE action LIKE '%" . $L['c'] . "\\_%'");
            $n += $db->exec("UPDATE itops_audit_log SET entity_type=REPLACE(entity_type, '" . $L['c'] . "_', 'std_') WHERE entity_type LIKE '" . $L['c'] . "\\_%'");
            if ($n) $done['audit_kinds'] = $n;
        }
        // uploaded evidence files: public/uploads/<legacy>/ → public/uploads/standards/
        if (defined('UPLOAD_DIR')) {
            $from = rtrim(UPLOAD_DIR, '/') . '/' . $L['c']; $to = rtrim(UPLOAD_DIR, '/') . '/standards';
            if (is_dir($from) && !is_dir($to) && @rename($from, $to)) $done['files'] = 1;
            if ($tbl('itops_std_evidence')) $db->exec("UPDATE itops_std_evidence SET file_path=CONCAT('standards/', SUBSTRING(file_path, " . (strlen($L['c'] . '/') + 1) . ")) WHERE file_path LIKE '" . $L['c'] . "/%'");
        }
        // program files of the old names left behind when the new build was copied over the old one
        $root = dirname(__DIR__);
        foreach (['/lib/' . ucfirst($L['c']) . 'Setup.php', '/lib/' . ucfirst($L['i']) . '.php', '/routes/' . $L['c'] . '.php', '/routes/' . $L['c'] . '_reqs.php'] as $f)
            if (is_file($root . $f) && @unlink($root . $f)) $done['old_files'] = ($done['old_files'] ?? 0) + 1;
    } catch (Throwable $e) { $done['error'] = $e->getMessage(); }
    $GLOBALS['ITOPS_LEGACY_DONE'] = $done;
    return $done;
}

function itops_run_migrations(PDO $db): array {
    // Review OPS-26: one runner at a time (two people pressing "Database update", or the update and a scheduled run)
    $got = false; try { $got = (int)$db->query("SELECT GET_LOCK('itops_migrations', 30)")->fetchColumn() === 1; } catch (Throwable $e) { $got = true; }
    if (!$got) return [['version' => '-', 'status' => 'error', 'message' => 'Another database update is running. Try again in a minute.']];
    try { return itops_run_migrations_locked($db); } finally { try { $db->query("SELECT RELEASE_LOCK('itops_migrations')"); } catch (Throwable $e) {} }
}
function itops_run_migrations_locked(PDO $db): array {
    itops_migrations_table($db);
    itops_legacy_rename($db);   // BT95
    $applied = itops_applied_versions($db);
    $mark = $db->prepare("INSERT IGNORE INTO itops_migrations (version) VALUES (?)");
    $report = [];
    // Always run in chronological order (the list itself is not strictly ordered), so a fresh
    // database can be built entirely from migrations without one step needing a later table.
    $list = itops_migrations_list(); ksort($list, SORT_STRING);
    foreach ($list as $version => $fn) {
        if (in_array($version, $applied, true)) {
            $report[] = ['version' => $version, 'status' => 'skipped', 'message' => 'Already applied.'];
            continue;
        }
        try {
            $message = $fn($db);
            $mark->execute([$version]);
            $report[] = ['version' => $version, 'status' => 'applied', 'message' => $message];
        } catch (Throwable $e) {
            $report[] = ['version' => $version, 'status' => 'error', 'message' => $e->getMessage()];
            break;
        }
    }
    return $report;
}

