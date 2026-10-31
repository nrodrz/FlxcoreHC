-- ═════════════════════════════════════════════════
-- ITOps — database schema (fresh installation)
-- © 2026 Indisse-BTS. All rights reserved. Proprietary; licensed, not sold.
-- 0001-A005: the baseline. Every table this product uses, in its final shape, with the default roles, departments and
-- settings. It replaces the reference app's original tables plus its 121 database updates. Later changes are new steps in
-- api/lib/Migrations.php (named <date>_<n>_<build>_<what>), so a fresh installation and an updated one reach the same structure.
-- ═════════════════════════════════════════════════

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- ── CLEAN SLATE (the installer is a fresh-setup tool; it refuses a database that already holds an installation) ──
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `itops_alerts`;
DROP TABLE IF EXISTS `itops_api_keys`;
DROP TABLE IF EXISTS `itops_audit_log`;
DROP TABLE IF EXISTS `itops_auth_throttle`;
DROP TABLE IF EXISTS `itops_cron_months`;
DROP TABLE IF EXISTS `itops_cron_runs`;
DROP TABLE IF EXISTS `itops_db_snapshots`;
DROP TABLE IF EXISTS `itops_departments`;
DROP TABLE IF EXISTS `itops_device_inventory`;
DROP TABLE IF EXISTS `itops_email_templates`;
DROP TABLE IF EXISTS `itops_event_log`;
DROP TABLE IF EXISTS `itops_lm_billing`;
DROP TABLE IF EXISTS `itops_lm_charges`;
DROP TABLE IF EXISTS `itops_lm_clients`;
DROP TABLE IF EXISTS `itops_lm_installs`;
DROP TABLE IF EXISTS `itops_lm_licenses`;
DROP TABLE IF EXISTS `itops_lm_links`;
DROP TABLE IF EXISTS `itops_lm_payments`;
DROP TABLE IF EXISTS `itops_lm_prices`;
DROP TABLE IF EXISTS `itops_lm_releases`;
DROP TABLE IF EXISTS `itops_m365_dev_requests`;
DROP TABLE IF EXISTS `itops_m365_policy_backups`;
DROP TABLE IF EXISTS `itops_m365_role_changes`;
DROP TABLE IF EXISTS `itops_m365_templates`;
DROP TABLE IF EXISTS `itops_mail_log`;
DROP TABLE IF EXISTS `itops_migrations`;
DROP TABLE IF EXISTS `itops_notifications`;
DROP TABLE IF EXISTS `itops_ob_runs`;
DROP TABLE IF EXISTS `itops_ob_run_tasks`;
DROP TABLE IF EXISTS `itops_ob_templates`;
DROP TABLE IF EXISTS `itops_ob_template_tasks`;
DROP TABLE IF EXISTS `itops_pc_people`;
DROP TABLE IF EXISTS `itops_recycle`;
DROP TABLE IF EXISTS `itops_risk_actions`;
DROP TABLE IF EXISTS `itops_risk_followups`;
DROP TABLE IF EXISTS `itops_risk_people`;
DROP TABLE IF EXISTS `itops_risk_runs`;
DROP TABLE IF EXISTS `itops_roles`;
DROP TABLE IF EXISTS `itops_sa_ai_suggestions`;
DROP TABLE IF EXISTS `itops_sa_ca_changes`;
DROP TABLE IF EXISTS `itops_sa_change_requests`;
DROP TABLE IF EXISTS `itops_sa_drift`;
DROP TABLE IF EXISTS `itops_sa_plans`;
DROP TABLE IF EXISTS `itops_sa_plan_items`;
DROP TABLE IF EXISTS `itops_sa_results`;
DROP TABLE IF EXISTS `itops_sa_runs`;
DROP TABLE IF EXISTS `itops_sessions`;
DROP TABLE IF EXISTS `itops_settings`;
DROP TABLE IF EXISTS `itops_terms_acceptances`;
DROP TABLE IF EXISTS `itops_users`;
DROP TABLE IF EXISTS `itops_webhooks`;
DROP TABLE IF EXISTS `itops_webhook_deliveries`;


CREATE TABLE `itops_alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `alert_key` varchar(160) NOT NULL,
  `level` enum('warning','error') DEFAULT 'warning',
  `channel` varchar(60) DEFAULT 'guardian',
  `message` varchar(500) DEFAULT NULL,
  `status` enum('open','cleared') DEFAULT 'open',
  `first_seen` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_seen` datetime DEFAULT CURRENT_TIMESTAMP,
  `cleared_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key` (`alert_key`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `key_prefix` varchar(32) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `scopes` text DEFAULT NULL,
  `status` enum('active','revoked') DEFAULT 'active',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key_hash` (`key_hash`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(150) DEFAULT 'System',
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `entity_name` varchar(255) DEFAULT '',
  `old_value` json DEFAULT NULL,
  `new_value` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT '',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_auth_throttle` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `k` varchar(120) NOT NULL,
  `at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_at_k` (`k`,`at`),
  KEY `idx_at_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_cron_months` (
  `ym` char(7) NOT NULL,
  `job` varchar(20) NOT NULL,
  `runs` int(10) unsigned NOT NULL DEFAULT 0,
  `ok` int(10) unsigned NOT NULL DEFAULT 0,
  `warning` int(10) unsigned NOT NULL DEFAULT 0,
  `failed` int(10) unsigned NOT NULL DEFAULT 0,
  `idle` int(10) unsigned NOT NULL DEFAULT 0,
  `total_ms` bigint(20) unsigned NOT NULL DEFAULT 0,
  `max_ms` int(10) unsigned DEFAULT NULL,
  `first_at` datetime DEFAULT NULL,
  `last_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ym`,`job`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_cron_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job` varchar(40) NOT NULL,
  `via` varchar(10) NOT NULL DEFAULT 'cli',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'running',
  `summary` varchar(500) NOT NULL DEFAULT '',
  `steps` mediumtext DEFAULT NULL,
  `error` text DEFAULT NULL,
  `php` varchar(20) NOT NULL DEFAULT '',
  `host` varchar(80) NOT NULL DEFAULT '',
  `pid` int(10) unsigned DEFAULT NULL,
  `mem_mb` decimal(8,1) DEFAULT NULL,
  `idle` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_job_started` (`job`,`started_at`),
  KEY `idx_started` (`started_at`),
  KEY `idx_status` (`status`),
  KEY `idx_idle` (`idle`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_db_snapshots` (
  `day` date NOT NULL,
  `total_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `rows_est` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tables_json` mediumtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_departments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `manager_id` int(10) unsigned DEFAULT NULL,
  `color` varchar(7) DEFAULT '#4A7FB5',
  `icon` varchar(50) DEFAULT 'folder',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `itops_departments_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `itops_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_device_inventory` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dkey` varchar(100) NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'entra',
  `entra_id` varchar(64) DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `name` varchar(190) NOT NULL DEFAULT '',
  `os` varchar(60) NOT NULL DEFAULT '',
  `os_version` varchar(60) NOT NULL DEFAULT '',
  `os_label` varchar(60) NOT NULL DEFAULT '',
  `os_family` varchar(12) NOT NULL DEFAULT 'other',
  `kind` varchar(12) NOT NULL DEFAULT 'computer',
  `ownership` varchar(14) NOT NULL DEFAULT 'unknown',
  `trust_type` varchar(20) NOT NULL DEFAULT '',
  `model` varchar(190) NOT NULL DEFAULT '',
  `is_managed` tinyint(1) NOT NULL DEFAULT 0,
  `is_compliant` tinyint(1) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `os_eol` tinyint(1) DEFAULT NULL,
  `user_upn` varchar(190) NOT NULL DEFAULT '',
  `user_name` varchar(190) NOT NULL DEFAULT '',
  `owner_enabled` tinyint(1) DEFAULT NULL,
  `owner_last_active` datetime DEFAULT NULL,
  `users_json` text DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `last_ip` varchar(64) NOT NULL DEFAULT '',
  `last_location` varchar(190) NOT NULL DEFAULT '',
  `last_app` varchar(120) NOT NULL DEFAULT '',
  `last_client` varchar(120) NOT NULL DEFAULT '',
  `seen_count` int(10) unsigned NOT NULL DEFAULT 0,
  `in_directory` tinyint(1) NOT NULL DEFAULT 1,
  `first_seen` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `app_at` datetime DEFAULT NULL,
  `app_host` varchar(20) NOT NULL DEFAULT '',
  `app_client` varchar(60) NOT NULL DEFAULT '',
  `app_os` varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dkey` (`dkey`),
  KEY `idx_user` (`user_upn`),
  KEY `idx_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_email_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tpl_key` varchar(60) NOT NULL,
  `lang` char(2) NOT NULL DEFAULT 'en',
  `subject` varchar(300) NOT NULL,
  `body` text NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tpl_lang` (`tpl_key`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_event_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ts` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `level` enum('error','warning','info','debug') NOT NULL DEFAULT 'info',
  `channel` varchar(60) NOT NULL DEFAULT 'app',
  `message` varchar(500) NOT NULL DEFAULT '',
  `context` json DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_ts` (`ts`),
  KEY `idx_level_ts` (`level`,`ts`),
  KEY `idx_channel_ts` (`channel`,`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_billing` (
  `license_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `cycle` varchar(10) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `list_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `breakdown` text DEFAULT NULL,
  `starts` date NOT NULL,
  `next_start` date NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `ended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`license_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_charges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(20) NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `license_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(300) NOT NULL DEFAULT '',
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `due_date` date NOT NULL,
  `voided` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lmch_number` (`number`),
  UNIQUE KEY `uq_lmch_period` (`license_id`,`period_start`),
  KEY `idx_lmch_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `contact_name` varchar(200) NOT NULL DEFAULT '',
  `email` varchar(200) NOT NULL DEFAULT '',
  `phone` varchar(40) NOT NULL DEFAULT '',
  `address` varchar(400) NOT NULL DEFAULT '',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lmc_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_installs` (
  `install_id` varchar(19) NOT NULL,
  `label` varchar(200) NOT NULL DEFAULT '',
  `token_hash` char(64) NOT NULL DEFAULT '',
  `token_set_at` datetime DEFAULT NULL,
  `build` varchar(40) NOT NULL DEFAULT '',
  `php` varchar(20) NOT NULL DEFAULT '',
  `dbv` varchar(60) NOT NULL DEFAULT '',
  `users` int(11) DEFAULT NULL,
  `last_update` varchar(40) NOT NULL DEFAULT '',
  `last_seen` datetime DEFAULT NULL,
  `last_ip` varchar(45) NOT NULL DEFAULT '',
  `last_serial` varchar(40) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `in_use_serial` varchar(40) NOT NULL DEFAULT '',
  `client_state` varchar(20) NOT NULL DEFAULT '',
  `last_problem` varchar(300) NOT NULL DEFAULT '',
  `channel` varchar(10) NOT NULL DEFAULT 'stable',
  PRIMARY KEY (`install_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_licenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `serial` varchar(20) NOT NULL,
  `licensee` varchar(200) NOT NULL,
  `contact` varchar(200) NOT NULL DEFAULT '',
  `install_id` varchar(19) NOT NULL,
  `agreement` varchar(60) NOT NULL DEFAULT '',
  `kind` varchar(40) NOT NULL DEFAULT '',
  `plan` varchar(40) NOT NULL DEFAULT '',
  `modules` text DEFAULT NULL,
  `users` int(10) unsigned NOT NULL DEFAULT 0,
  `term_start` date NOT NULL,
  `term_end` date DEFAULT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(10) NOT NULL DEFAULT 'issued',
  `replaces_id` int(10) unsigned DEFAULT NULL,
  `issued_at` datetime NOT NULL,
  `issued_by` int(10) unsigned DEFAULT NULL,
  `key_id` varchar(16) NOT NULL,
  `signature` varchar(120) NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_reason` varchar(300) DEFAULT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lm_serial` (`serial`),
  KEY `idx_lm_install` (`install_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_links` (
  `token_hash` char(64) NOT NULL,
  `label` varchar(200) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `claimed_install` varchar(19) NOT NULL DEFAULT '',
  `claimed_at` datetime DEFAULT NULL,
  `stopped` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `charge_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `paid_on` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20) NOT NULL,
  `reference` varchar(120) NOT NULL DEFAULT '',
  `note` varchar(500) NOT NULL DEFAULT '',
  `voided` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lmpay_charge` (`charge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_prices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_type` varchar(10) NOT NULL,
  `item_key` varchar(60) NOT NULL,
  `cycle` varchar(10) NOT NULL,
  `base_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `included_users` int(10) unsigned NOT NULL DEFAULT 0,
  `per_user` decimal(12,2) NOT NULL DEFAULT 0.00,
  `effective_from` date NOT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lmp_item` (`item_type`,`item_key`,`cycle`,`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_lm_releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `build` varchar(40) NOT NULL,
  `notes` text DEFAULT NULL,
  `install_id` varchar(19) NOT NULL DEFAULT '',
  `key_id` varchar(16) NOT NULL,
  `size` int(10) unsigned NOT NULL DEFAULT 0,
  `file_name` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `stable_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lmr_build` (`build`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_m365_dev_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(80) NOT NULL,
  `device_name` varchar(255) NOT NULL DEFAULT '',
  `device_user` varchar(255) NOT NULL DEFAULT '',
  `device_os` varchar(80) NOT NULL DEFAULT '',
  `device_owner` varchar(20) NOT NULL DEFAULT '',
  `device_serial` varchar(120) NOT NULL DEFAULT '',
  `action` varchar(20) NOT NULL,
  `options` varchar(255) NOT NULL DEFAULT '{}',
  `reason` varchar(1000) NOT NULL,
  `requested_by` int(10) unsigned NOT NULL,
  `requested_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(1000) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `result` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mdr_status` (`status`,`requested_at`),
  KEY `idx_mdr_device` (`device_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_m365_policy_backups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `batch` varchar(40) NOT NULL,
  `kind` varchar(40) NOT NULL,
  `policy_id` varchar(80) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `platform` varchar(60) NOT NULL DEFAULT '',
  `payload` mediumtext NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_policy` (`kind`,`policy_id`),
  KEY `k_batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_m365_role_changes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(20) NOT NULL,
  `principal_id` varchar(40) NOT NULL,
  `principal_name` varchar(255) NOT NULL DEFAULT '',
  `role_id` varchar(40) NOT NULL,
  `role_name` varchar(255) NOT NULL DEFAULT '',
  `scope` varchar(255) NOT NULL DEFAULT '/',
  `assignment_id` varchar(200) DEFAULT NULL,
  `eligibility` varchar(200) DEFAULT NULL,
  `by_user` int(10) unsigned NOT NULL,
  `at` datetime NOT NULL,
  `request_id` int(10) unsigned DEFAULT NULL,
  `undone_at` datetime DEFAULT NULL,
  `undone_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mrc_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_m365_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `m365_json` mediumtext DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_mail_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT '',
  `method` varchar(20) DEFAULT '',
  `kind` varchar(30) DEFAULT '',
  `ok` tinyint(1) DEFAULT 0,
  `error` varchar(1000) DEFAULT '',
  `sent_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ml_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_migrations` (
  `version` varchar(190) NOT NULL,
  `applied_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT '',
  `type` varchar(50) DEFAULT 'info',
  `link` varchar(500) DEFAULT '',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `itops_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `itops_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_ob_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned DEFAULT NULL,
  `kind` enum('onboard','offboard') NOT NULL DEFAULT 'onboard',
  `subject_name` varchar(190) NOT NULL,
  `subject_email` varchar(190) DEFAULT NULL,
  `subject_upn` varchar(190) DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `status` enum('in_progress','completed','cancelled') DEFAULT 'in_progress',
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `m365_id` varchar(64) DEFAULT NULL,
  `verify_json` mediumtext DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verify_clean` tinyint(1) DEFAULT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `job_title` varchar(120) DEFAULT NULL,
  `department` varchar(120) DEFAULT NULL,
  `manager_upn` varchar(190) DEFAULT NULL,
  `employee_id` varchar(60) DEFAULT NULL,
  `mobile_phone` varchar(40) DEFAULT NULL,
  `office_location` varchar(120) DEFAULT NULL,
  `auto_run_at` datetime DEFAULT NULL,
  `auto_run_status` varchar(20) DEFAULT NULL,
  `auto_run_by` int(10) unsigned DEFAULT NULL,
  `auto_run_log` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`kind`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_ob_run_tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `task_type` varchar(40) NOT NULL DEFAULT 'manual',
  `config` mediumtext DEFAULT NULL,
  `assignee_role` varchar(40) DEFAULT 'it',
  `required` tinyint(1) DEFAULT 1,
  `status` enum('pending','done','skipped','failed') DEFAULT 'pending',
  `result` text DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_ob_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `kind` enum('onboard','offboard') NOT NULL DEFAULT 'onboard',
  `description` text DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kind` (`kind`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_ob_template_tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `task_type` varchar(40) NOT NULL DEFAULT 'manual',
  `config` mediumtext DEFAULT NULL,
  `assignee_role` varchar(40) DEFAULT 'it',
  `required` tinyint(1) DEFAULT 1,
  `due_offset_days` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tpl` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_pc_people` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `person_name` varchar(190) NOT NULL DEFAULT '',
  `token_hash` char(64) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'invited',
  `invited_by` int(10) unsigned DEFAULT NULL,
  `invited_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `consent_at` datetime DEFAULT NULL,
  `consent_name` varchar(190) DEFAULT NULL,
  `consent_ip` varchar(45) DEFAULT NULL,
  `consent_version` int(11) DEFAULT NULL,
  `report` mediumtext DEFAULT NULL,
  `reported_at` datetime DEFAULT NULL,
  `reports` int(11) NOT NULL DEFAULT 0,
  `path` varchar(10) DEFAULT NULL,
  `package_at` datetime DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdraw_note` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `k_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_recycle` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(40) NOT NULL,
  `record_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `data` longtext NOT NULL,
  `files` text DEFAULT NULL,
  `rows_kept` int(10) unsigned NOT NULL DEFAULT 1,
  `bytes` int(10) unsigned NOT NULL DEFAULT 0,
  `legacy` tinyint(1) NOT NULL DEFAULT 0,
  `module` varchar(40) NOT NULL DEFAULT '',
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `deleted_by_name` varchar(150) NOT NULL DEFAULT '',
  `deleted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recycle_kind` (`kind`,`record_id`),
  KEY `idx_recycle_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_risk_actions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `followup_id` int(10) unsigned NOT NULL,
  `user_oid` varchar(64) NOT NULL,
  `upn` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL DEFAULT '',
  `action` varchar(20) NOT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `result` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ra_status` (`status`),
  KEY `idx_ra_fu` (`followup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_risk_followups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_oid` varchar(64) NOT NULL,
  `upn` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL DEFAULT '',
  `mail` varchar(255) NOT NULL DEFAULT '',
  `level` varchar(8) NOT NULL DEFAULT '',
  `first_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `factors` varchar(500) NOT NULL DEFAULT '[]',
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `deadline` date NOT NULL,
  `started_at` datetime NOT NULL,
  `reminded_at` datetime DEFAULT NULL,
  `escalated_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `close_note` varchar(500) DEFAULT NULL,
  `manager_mail` varchar(255) DEFAULT NULL,
  `first_run_id` int(10) unsigned DEFAULT NULL,
  `last_run_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rf_oid` (`user_oid`,`status`),
  KEY `idx_rf_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_risk_people` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `user_oid` varchar(64) NOT NULL,
  `upn` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL DEFAULT '',
  `mail` varchar(255) NOT NULL DEFAULT '',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `level` varchar(8) NOT NULL DEFAULT 'low',
  `factors` varchar(500) NOT NULL DEFAULT '[]',
  `details` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rp_run` (`run_id`,`score`),
  KEY `idx_rp_oid` (`user_oid`,`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_risk_runs` (
  `run_id` int(10) unsigned NOT NULL,
  `sources` varchar(255) NOT NULL DEFAULT '{}',
  `people` int(10) unsigned NOT NULL DEFAULT 0,
  `high` int(10) unsigned NOT NULL DEFAULT 0,
  `medium` int(10) unsigned NOT NULL DEFAULT 0,
  `low` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `permissions` json DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_ai_suggestions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) unsigned NOT NULL,
  `rule_id` varchar(60) NOT NULL,
  `decision` varchar(10) NOT NULL,
  `why` varchar(300) NOT NULL DEFAULT '',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sx_plan_rule` (`plan_id`,`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_ca_changes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(20) NOT NULL,
  `policy_id` varchar(400) NOT NULL DEFAULT '',
  `policy_name` varchar(255) NOT NULL DEFAULT '',
  `before_json` mediumtext DEFAULT NULL,
  `after_json` mediumtext DEFAULT NULL,
  `by_user` int(10) unsigned NOT NULL,
  `at` datetime NOT NULL,
  `request_id` int(10) unsigned DEFAULT NULL,
  `undone_at` datetime DEFAULT NULL,
  `undone_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cac_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_change_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(20) NOT NULL,
  `params` text NOT NULL,
  `label` varchar(400) NOT NULL,
  `reason` varchar(1000) NOT NULL,
  `requested_by` int(10) unsigned NOT NULL,
  `requested_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `note` varchar(1000) DEFAULT NULL,
  `result` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scr_status` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_drift` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `prev_run_id` int(10) unsigned NOT NULL,
  `rule_id` varchar(60) NOT NULL,
  `from_status` varchar(10) NOT NULL,
  `to_status` varchar(10) NOT NULL,
  `detected_at` datetime NOT NULL,
  `evidence` mediumtext DEFAULT NULL,
  `plan_decision` varchar(12) DEFAULT NULL,
  `ack_by` int(10) unsigned DEFAULT NULL,
  `ack_at` datetime DEFAULT NULL,
  `ack_note` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sd_run_rule` (`run_id`,`rule_id`),
  KEY `idx_sd_at` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version` int(10) unsigned NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `baseline` varchar(20) NOT NULL DEFAULT 'standard',
  `run_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `signed_name` varchar(150) DEFAULT NULL,
  `signed_title` varchar(150) DEFAULT NULL,
  `signed_note` varchar(1000) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `signed_by` int(10) unsigned DEFAULT NULL,
  `signed_snapshot` mediumtext DEFAULT NULL,
  `signed_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sp_status` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_plan_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) unsigned NOT NULL,
  `rule_id` varchar(60) NOT NULL,
  `stage` tinyint(3) unsigned NOT NULL,
  `seq` int(10) unsigned NOT NULL,
  `found_status` varchar(10) NOT NULL,
  `found_detail` varchar(1000) DEFAULT NULL,
  `affected_count` int(10) unsigned NOT NULL DEFAULT 0,
  `decision` varchar(12) NOT NULL DEFAULT 'undecided',
  `note` varchar(1000) DEFAULT NULL,
  `owner_id` int(10) unsigned DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_spi_plan` (`plan_id`,`stage`,`seq`),
  KEY `idx_spi_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_results` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `check_id` varchar(60) NOT NULL,
  `status` varchar(10) NOT NULL,
  `why` varchar(12) NOT NULL DEFAULT '',
  `affected_count` int(10) unsigned NOT NULL DEFAULT 0,
  `affected` mediumtext DEFAULT NULL,
  `detail` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sa_run` (`run_id`),
  KEY `idx_sa_check` (`check_id`,`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sa_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `via` varchar(10) NOT NULL DEFAULT 'manual',
  `started_by` int(10) unsigned DEFAULT NULL,
  `score` tinyint(3) unsigned DEFAULT NULL,
  `counts` varchar(255) NOT NULL DEFAULT '{}',
  `extra` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sa_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `refresh_token_hash` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `itops_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `itops_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `skey` varchar(100) NOT NULL,
  `sval` text DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_terms_acceptances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `version` varchar(20) NOT NULL,
  `scope` varchar(4) NOT NULL DEFAULT 'user',
  `org_name` varchar(120) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `accepted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tou_user` (`user_id`,`version`),
  KEY `idx_tou_scope` (`version`,`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `display_name` varchar(150) NOT NULL,
  `first_name` varchar(80) DEFAULT '',
  `last_name` varchar(80) DEFAULT '',
  `avatar_path` varchar(500) DEFAULT '',
  `role_id` int(10) unsigned NOT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `auth_provider` enum('local','o365','both') DEFAULT 'local',
  `o365_oid` varchar(255) DEFAULT NULL,
  `mfa_secret` varchar(255) DEFAULT NULL,
  `mfa_enabled` tinyint(1) DEFAULT 0,
  `mfa_backup_codes` text DEFAULT NULL,
  `status` enum('active','inactive','pending') DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `login_count` int(10) unsigned DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `language` varchar(5) DEFAULT NULL,
  `mfa_last_step` bigint(20) DEFAULT NULL,
  `o365_link_ok` tinyint(1) NOT NULL DEFAULT 0,
  `ui_prefs` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `itops_users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `itops_roles` (`id`),
  CONSTRAINT `itops_users_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `itops_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_webhooks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(120) NOT NULL,
  `events` text DEFAULT NULL,
  `status` enum('active','paused') DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `itops_webhook_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `webhook_id` int(10) unsigned NOT NULL,
  `delivery_id` varchar(32) NOT NULL,
  `event` varchar(64) NOT NULL,
  `payload` mediumtext DEFAULT NULL,
  `response_status` int(11) DEFAULT 0,
  `error` text DEFAULT NULL,
  `attempts` int(11) DEFAULT 1,
  `status` enum('delivered','failed','queued') DEFAULT 'failed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hook` (`webhook_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DEFAULT DATA ──
INSERT INTO `itops_roles` (`id`,`name`,`slug`,`description`,`permissions`) VALUES
(1,'Super Admin','super_admin','Everything, including roles, updates and database maintenance.','{"*":true}'),
(2,'Department Admin','dept_admin','The users of their own department, onboarding and a read view of Microsoft 365.','{"users":["view","create","edit"],"onboarding":["view","edit"],"m365":["view"],"changelog":["view"]}'),
(3,'Manager','manager','Day-to-day account work plus mailboxes, devices, security incidents, the onboarding templates and a read view of users.','{"m365":["view"],"m365_users":["edit"],"m365_credentials":["edit"],"m365_licenses":["edit"],"m365_groups":["edit"],"m365_mail":["view","edit"],"m365_devices":["edit"],"m365_incidents":["edit"],"onboarding":["view","create","edit","delete"],"security_assessment":["view"],"users":["view"],"changelog":["view"]}'),
(4,'Service desk (Microsoft 365)','service_desk','Reset passwords and MFA, block and restore sign-in, licenses and groups, onboarding and offboarding from the templates.','{"m365":["view"],"m365_users":["edit"],"m365_credentials":["edit"],"m365_licenses":["edit"],"m365_groups":["edit"],"m365_mail":["view"],"onboarding":["view"],"security_assessment":["view"],"changelog":["view"]}'),
(5,'Signed in, no access','viewer','Given to a person the first time they sign in with Microsoft: sees nothing until an administrator gives them a role.','{"changelog":["view"]}'),
(6,'Auditor','auditor','Read-only across the whole app including the audit and event logs. No changes anywhere.','{"m365":["view"],"onboarding":["view","all"],"security_assessment":["view"],"users":["view"],"roles":["view"],"audit":["view"],"event_log":["view"],"jobs":["view"],"changelog":["view"]}'),
(7,'Read-only','readonly','Every Microsoft 365 page, the onboarding history and the posture. No changes.','{"m365":["view"],"onboarding":["view"],"security_assessment":["view"],"changelog":["view"]}'),
(8,'Admin','admin','Every Microsoft 365 action, users, integrations and settings. Cannot change roles or run database maintenance.','{"m365":["view"],"m365_admin":["view","edit"],"m365_mail":["view","edit"],"m365_users":["edit"],"m365_credentials":["edit"],"m365_licenses":["edit"],"m365_groups":["edit"],"m365_devices":["edit"],"m365_incidents":["edit"],"m365_ca":["edit"],"m365_device_wipe":["edit"],"m365_keys":["view"],"m365_roles":["edit"],"onboarding":["view","create","edit","delete","all"],"security_assessment":["view","edit"],"users":["view","create","edit","delete","all"],"roles":["view"],"import":["view"],"integrations":["view","edit"],"settings":["view","edit"],"branding":["view","edit"],"email_cfg":["view","edit"],"audit":["view"],"event_log":["view"],"jobs":["view","edit"],"recycle":["view"],"changelog":["view"]}');

INSERT INTO `itops_departments` (`id`,`name`,`slug`,`description`,`color`,`icon`,`status`) VALUES
(1,'Information Technology','it','IT infrastructure, systems, security, and support','#4A7FB5','folder','active'),
(2,'Human Resources','hr','Employee management, recruitment, training, and benefits','#7B6AAF','folder','active'),
(3,'Finance','finance','Accounting, budgeting, payroll, and financial reporting','#3D9A6E','folder','active'),
(4,'Marketing','marketing','Brand, digital marketing, campaigns, and analytics','#C08A30','folder','active'),
(5,'Operations','operations','Day-to-day business operations and process management','#C44545','folder','active'),
(6,'Compliance','compliance','Regulatory compliance, accreditation, audits, and certifications','#4A7FB5','folder','active'),
(7,'Executive','executive','Executive leadership and strategic planning','#2B2926','folder','active');

INSERT INTO `itops_settings` (`skey`,`sval`) VALUES
('app_name','ITOps'),
('app_tagline','Microsoft 365 administration'),
('logo_path',''),
('favicon_path',''),
('accent_color','#4A7FB5'),
('default_theme','light'),
('support_email',''),
('o365_tenant_id',''),
('o365_client_id',''),
('o365_client_secret',''),
('o365_enabled','0');

SET FOREIGN_KEY_CHECKS = 1;
