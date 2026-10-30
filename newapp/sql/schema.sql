-- ═══════════════════════════════════════════════════════════════
-- ITOps — Complete MySQL Schema
-- © 2026 Indisse-BTS. All rights reserved. Proprietary; licensed, not sold.
-- ═══════════════════════════════════════════════════════════════

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- ── CLEAN SLATE (installer is a fresh-setup tool; makes retries idempotent) ──
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `itops_notifications`;
DROP TABLE IF EXISTS `itops_case_updates`;
DROP TABLE IF EXISTS `itops_case_digest`;
DROP TABLE IF EXISTS `itops_case_svc`;
DROP TABLE IF EXISTS `itops_ci_links`;
DROP TABLE IF EXISTS `itops_ticket_csat`;
DROP TABLE IF EXISTS `itops_major_reviews`;
DROP TABLE IF EXISTS `itops_case_phases`;
DROP TABLE IF EXISTS `itops_case_budget`;
DROP TABLE IF EXISTS `itops_case_template_tasks`;
DROP TABLE IF EXISTS `itops_case_templates`;
DROP TABLE IF EXISTS `itops_case_tasks`;
DROP TABLE IF EXISTS `itops_case_watchers`;
DROP TABLE IF EXISTS `itops_improvements`;
DROP TABLE IF EXISTS `itops_case_task_checks`;
DROP TABLE IF EXISTS `itops_cases`;
DROP TABLE IF EXISTS `itops_notification_prefs`;
DROP TABLE IF EXISTS `itops_std_domains`;
DROP TABLE IF EXISTS `itops_calendar_events`;
DROP TABLE IF EXISTS `itops_ticket_attachments`;
DROP TABLE IF EXISTS `itops_ticket_comments`;
DROP TABLE IF EXISTS `itops_tickets`;
DROP TABLE IF EXISTS `itops_ticket_categories`;
DROP TABLE IF EXISTS `itops_kb_votes`;
DROP TABLE IF EXISTS `itops_kb_articles`;
DROP TABLE IF EXISTS `itops_kb_categories`;
DROP TABLE IF EXISTS `itops_contracts`;
DROP TABLE IF EXISTS `itops_vendors`;
DROP TABLE IF EXISTS `itops_cred_access_log`;
DROP TABLE IF EXISTS `itops_credentials`;
DROP TABLE IF EXISTS `itops_cred_categories`;
DROP TABLE IF EXISTS `itops_doc_access_log`;
DROP TABLE IF EXISTS `itops_documents`;
DROP TABLE IF EXISTS `itops_doc_categories`;
DROP TABLE IF EXISTS `itops_sop_acks`;
DROP TABLE IF EXISTS `itops_sop_versions`;
DROP TABLE IF EXISTS `itops_sops`;
DROP TABLE IF EXISTS `itops_sop_categories`;
DROP TABLE IF EXISTS `itops_audit_log`;
DROP TABLE IF EXISTS `itops_sessions`;
DROP TABLE IF EXISTS `itops_users`;
DROP TABLE IF EXISTS `itops_departments`;
DROP TABLE IF EXISTS `itops_roles`;
DROP TABLE IF EXISTS `itops_settings`;
SET FOREIGN_KEY_CHECKS = 1;

-- ── SETTINGS ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `skey` VARCHAR(100) NOT NULL UNIQUE,
  `sval` TEXT,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_settings` (`skey`,`sval`) VALUES
('app_name','ITOps'),
('app_tagline','IT Master Operations Hub'),
('logo_path',''),
('favicon_path',''),
('accent_color','#4A7FB5'),
('default_theme','light'),
('support_email','support@company.com'),
('o365_tenant_id',''),
('o365_client_id',''),
('o365_client_secret',''),
('o365_redirect_uri',''),
('o365_enabled','0'),
('session_timeout_hours','8'),
('mfa_required_for_vault','1'),
('installed_at',NOW());

-- ── ROLES ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(80) NOT NULL,
  `slug` VARCHAR(80) NOT NULL UNIQUE,
  `description` TEXT,
  `permissions` JSON,
  `is_system` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_roles` (`name`,`slug`,`description`,`permissions`,`is_system`) VALUES
('Super Admin','super_admin','Full system access across all departments and modules','{"*":true}',1),
('Department Admin','dept_admin','Full CRUD within assigned department, user management within department','{"sops":"crud","docs":"crud","creds":"read_use","contracts":"crud","kb":"crud","tickets":"crud","users":"dept_manage","reports":"dept"}',1),
('Manager','manager','Create/edit SOPs, view credentials, manage tickets, view contracts','{"sops":"crud","docs":"crud","creds":"read","contracts":"read","kb":"crud","tickets":"crud","reports":"dept"}',1),
('Editor','editor','Create/edit drafts, upload documents, manage own tickets','{"sops":"create_edit","docs":"crud","creds":"none","contracts":"read","kb":"create_edit","tickets":"own","reports":"none"}',1),
('Viewer','viewer','Read published SOPs, view documents, submit tickets','{"sops":"read","docs":"read","creds":"none","contracts":"read_assigned","kb":"read","tickets":"own","reports":"none"}',1),
('Auditor','auditor','Read-only access to all content and audit trails','{"sops":"read_all","docs":"read_all","creds":"audit","contracts":"read_all","kb":"read_all","tickets":"read_all","reports":"all","audit":"read"}',1);

-- ── DEPARTMENTS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_departments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `manager_id` INT UNSIGNED DEFAULT NULL,
  `color` VARCHAR(7) DEFAULT '#4A7FB5',
  `icon` VARCHAR(50) DEFAULT 'folder',
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`parent_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_departments` (`name`,`slug`,`description`,`color`) VALUES
('Information Technology','it','IT infrastructure, systems, security, and support','#4A7FB5'),
('Human Resources','hr','Employee management, recruitment, training, and benefits','#7B6AAF'),
('Finance','finance','Accounting, budgeting, payroll, and financial reporting','#3D9A6E'),
('Marketing','marketing','Brand, digital marketing, campaigns, and analytics','#C08A30'),
('Operations','operations','Day-to-day business operations and process management','#C44545'),
('Compliance','compliance','Regulatory compliance, accreditation, audits, and certifications','#4A7FB5'),
('Executive','executive','Executive leadership and strategic planning','#2B2926');

-- ── USERS ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `display_name` VARCHAR(150) NOT NULL,
  `first_name` VARCHAR(80) DEFAULT '',
  `last_name` VARCHAR(80) DEFAULT '',
  `avatar_path` VARCHAR(500) DEFAULT '',
  `role_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `auth_provider` ENUM('local','o365','both') DEFAULT 'local',
  `o365_oid` VARCHAR(255) DEFAULT NULL,
  `mfa_secret` VARCHAR(255) DEFAULT NULL,
  `mfa_enabled` TINYINT(1) DEFAULT 0,
  `mfa_backup_codes` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive','pending') DEFAULT 'active',
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `login_count` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `itops_roles`(`id`),
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SESSIONS ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_sessions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL UNIQUE,
  `refresh_token_hash` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45),
  `user_agent` VARCHAR(500),
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `itops_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AUDIT LOG ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_audit_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `user_name` VARCHAR(150) DEFAULT 'System',
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `entity_name` VARCHAR(255) DEFAULT '',
  `old_value` JSON DEFAULT NULL,
  `new_value` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
  INDEX `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SOP CATEGORIES ────────────────────────────────────────────
-- BT138: moved into itops_categories by the database update that runs right after installation.
CREATE TABLE IF NOT EXISTS `itops_sop_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `sort_order` INT DEFAULT 0,
  `icon` VARCHAR(50) DEFAULT '',
  `color` VARCHAR(7) DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_cat_slug_dept` (`slug`, `department_id`),
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_sop_categories` (`name`,`slug`,`department_id`,`sort_order`) VALUES
('Systems','systems',1,1),('Business Platforms','business-platforms',1,2),('Infrastructure','infrastructure',1,3),
('Operations','operations',1,4),('Security','security',1,5),('Compliance','compliance',1,6),
('HR Policies','hr-policies',2,1),('Recruitment','recruitment',2,2),('Training','training',2,3),
('Finance SOPs','finance-sops',3,1),('Payroll','payroll',3,2),
('Marketing SOPs','marketing-sops',4,1),('Campaigns','campaigns',4,2),
('Operations SOPs','ops-sops',5,1);

-- ── SOPs ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_sops` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `content` LONGTEXT,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft','under_review','approved','published','archived') DEFAULT 'draft',
  `current_version` INT UNSIGNED DEFAULT 1,
  `owner_id` INT UNSIGNED DEFAULT NULL,
  `review_frequency_days` INT DEFAULT 90,
  `next_review_date` DATE DEFAULT NULL,
  `last_review_date` DATE DEFAULT NULL,
  `tags` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `itops_sop_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`owner_id`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SOP VERSIONS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_sop_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sop_id` INT UNSIGNED NOT NULL,
  `version_number` INT UNSIGNED NOT NULL,
  `content` LONGTEXT,
  `change_summary` TEXT DEFAULT '',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sop_id`) REFERENCES `itops_sops`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SOP ACKNOWLEDGMENTS ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_sop_acks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sop_id` INT UNSIGNED NOT NULL,
  `version_number` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `acknowledged_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) DEFAULT '',
  UNIQUE KEY `uq_ack` (`sop_id`,`version_number`,`user_id`),
  FOREIGN KEY (`sop_id`) REFERENCES `itops_sops`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `itops_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DOCUMENT CATEGORIES ───────────────────────────────────────
-- BT138: moved into itops_categories by the database update that runs right after installation.
CREATE TABLE IF NOT EXISTS `itops_doc_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `retention_days` INT DEFAULT NULL,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_doc_categories` (`name`,`slug`,`department_id`,`sort_order`) VALUES
('Network Diagrams','network-diagrams',1,1),('Vendor Quotes','vendor-quotes',1,2),
('Policies','policies',1,3),('Accreditation Evidence','accreditation-evidence',6,1),
('Hardware Specs','hardware-specs',1,4),('Budgets','budgets',3,1),
('HR Documents','hr-docs',2,1),('Contracts','contracts-docs',NULL,1),
('Marketing Assets','marketing-assets',4,1),('Training Materials','training-materials',2,2);

-- ── DOCUMENTS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_documents` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT '',
  `file_path` VARCHAR(500) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_size` BIGINT UNSIGNED DEFAULT 0,
  `mime_type` VARCHAR(100) DEFAULT '',
  `category_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `version_number` INT UNSIGNED DEFAULT 1,
  `tags` JSON DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `status` ENUM('active','archived','expired') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FULLTEXT KEY `ft_docs` (`title`,`description`),
  FOREIGN KEY (`category_id`) REFERENCES `itops_doc_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`uploaded_by`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DOCUMENT ACCESS LOG ───────────────────────────────────────
-- BT138: moved into itops_access_log by the database update that runs right after installation.
CREATE TABLE IF NOT EXISTS `itops_doc_access_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` ENUM('view','download','print','share') NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`document_id`) REFERENCES `itops_documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `itops_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CREDENTIAL CATEGORIES ─────────────────────────────────────
-- BT138: moved into itops_categories by the database update that runs right after installation.
CREATE TABLE IF NOT EXISTS `itops_cred_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(50) DEFAULT 'vault',
  `sort_order` INT DEFAULT 0,
  `department_id` INT UNSIGNED DEFAULT NULL,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_cred_categories` (`name`,`icon`,`sort_order`,`department_id`) VALUES
('SaaS Platforms','cloud',1,1),('Cloud Admin','shield',2,1),('Infrastructure','server',3,1),
('Network Equipment','router',4,1),('Database','database',5,1),('Vendor Portals','globe',6,1),
('API Keys','key',7,1),('Finance Systems','dollar',8,3);

-- ── CREDENTIALS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_credentials` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT '',
  `category_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `system_url` VARCHAR(500) DEFAULT '',
  `username_enc` TEXT DEFAULT NULL,
  `password_enc` TEXT DEFAULT NULL,
  `api_key_enc` TEXT DEFAULT NULL,
  `mfa_details_enc` TEXT DEFAULT NULL,
  `notes_enc` TEXT DEFAULT NULL,
  `environment` ENUM('production','staging','development','other') DEFAULT 'production',
  `owner_id` INT UNSIGNED DEFAULT NULL,
  `rotation_days` INT DEFAULT 90,
  `last_rotated_at` DATE DEFAULT NULL,
  `next_rotation_date` DATE DEFAULT NULL,
  `status` ENUM('current','due_soon','overdue','disabled') DEFAULT 'current',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `itops_cred_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`owner_id`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CREDENTIAL ACCESS LOG ─────────────────────────────────────
-- BT138: moved into itops_access_log by the database update that runs right after installation.
CREATE TABLE IF NOT EXISTS `itops_cred_access_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `credential_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` ENUM('view','copy','update','rotate','create','delete') NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`credential_id`) REFERENCES `itops_credentials`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `itops_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── VENDORS ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_vendors` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `website` VARCHAR(500) DEFAULT '',
  `primary_contact_name` VARCHAR(150) DEFAULT '',
  `primary_contact_email` VARCHAR(255) DEFAULT '',
  `primary_contact_phone` VARCHAR(50) DEFAULT '',
  `account_manager` VARCHAR(150) DEFAULT '',
  `support_url` VARCHAR(500) DEFAULT '',
  `support_phone` VARCHAR(50) DEFAULT '',
  `support_email` VARCHAR(255) DEFAULT '',
  `sla_response_hours` INT DEFAULT NULL,
  `risk_tier` ENUM('low','medium','high','critical') DEFAULT 'medium',
  `category` VARCHAR(100) DEFAULT '',
  `notes` TEXT DEFAULT '',
  `department_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('active','inactive','under_review','archived') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_vendors` (`name`,`website`,`primary_contact_name`,`primary_contact_email`,`support_url`,`sla_response_hours`,`risk_tier`,`category`,`department_id`,`status`) VALUES
('Phone system provider','','Account Manager','','',4,'medium','Cloud Phone/SMS',1,'active'),
('Forms provider','','Account Manager','','',8,'low','Forms/Workflows',1,'active'),
('E-signature provider','','Account Manager','','',4,'medium','E-Signatures',1,'active'),
('Call analytics provider','','Account Manager','','',8,'medium','Call Analytics',1,'active'),
('eFax provider','','Account Manager','','',4,'medium','eFax',1,'active'),
('Microsoft','https://microsoft.com','MS Account Team','','https://admin.microsoft.com',2,'critical','Cloud Platform',1,'active'),
('Hosting provider','','Account Manager','','',1,'critical','Hosting/Infrastructure',1,'active'),
('Firewall vendor','','Partner Engineer','','',4,'high','Network Security',1,'active'),
('CRM provider','','Account Manager','','',4,'high','CRM',1,'active'),
('Internet provider (primary)','','Account Team','','',8,'medium','ISP / Connectivity',1,'active'),
('Internet provider (backup)','','Account Team','','',8,'medium','ISP / Connectivity',1,'active');

-- ── CONTRACTS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_contracts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `vendor_id` INT UNSIGNED DEFAULT NULL,
  `contract_number` VARCHAR(100) DEFAULT '',
  `contract_type` ENUM('subscription','license','service','lease','maintenance','other') DEFAULT 'subscription',
  `department_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('active','expiring','expired','renewed','terminated','draft') DEFAULT 'active',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `auto_renew` TINYINT(1) DEFAULT 0,
  `renewal_notice_days` INT DEFAULT 90,
  `total_value` DECIMAL(12,2) DEFAULT 0,
  `payment_frequency` ENUM('monthly','quarterly','semi_annual','annual','one_time') DEFAULT 'annual',
  `payment_amount` DECIMAL(12,2) DEFAULT 0,
  `owner_id` INT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT '',
  `tags` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`vendor_id`) REFERENCES `itops_vendors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`owner_id`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── KB CATEGORIES ─────────────────────────────────────────────
-- BT138: moved into itops_categories by the database update that runs right after installation.
CREATE TABLE IF NOT EXISTS `itops_kb_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `icon` VARCHAR(50) DEFAULT '',
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_kb_categories` (`name`,`slug`,`department_id`,`sort_order`) VALUES
('Microsoft 365','microsoft-365',1,1),('Phone System','phone-system',1,2),('Forms','forms',1,3),
('Network','network',1,4),('eFax','efax',1,5),('Hardware','hardware',1,6),
('Security','security',1,7),('Finance','finance-kb',3,1),('HR','hr-kb',2,1),
('General','general',NULL,99);

-- ── KB ARTICLES ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_kb_articles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `content` LONGTEXT,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `author_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft','published','archived') DEFAULT 'draft',
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `views_count` INT UNSIGNED DEFAULT 0,
  `helpful_yes` INT UNSIGNED DEFAULT 0,
  `helpful_no` INT UNSIGNED DEFAULT 0,
  `tags` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FULLTEXT KEY `ft_kb` (`title`,`content`),
  FOREIGN KEY (`category_id`) REFERENCES `itops_kb_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`author_id`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_kb_votes` (
  `article_id` INT NOT NULL,
  `voter` CHAR(32) NOT NULL,
  `kind` VARCHAR(8) NOT NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`article_id`,`voter`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TICKET CATEGORIES ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_ticket_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `description` TEXT DEFAULT '',
  `sla_hours` DECIMAL(7,2) DEFAULT 24,
  `sla_response_hours` DECIMAL(7,2) DEFAULT NULL,
  `ticket_type` VARCHAR(10) NOT NULL DEFAULT 'request',
  `sort_order` INT DEFAULT 0,
  `icon` VARCHAR(50) DEFAULT '',
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IT Department ticket categories
INSERT INTO `itops_ticket_categories` (`name`,`slug`,`department_id`,`parent_id`,`description`,`sla_hours`,`sort_order`,`icon`) VALUES
-- IT > Hardware
('Hardware Request','it-hardware',1,NULL,'Hardware-related requests and issues',24,1,'desktop'),
('New PC/Laptop Request','it-new-pc',1,1,'Request a new workstation for a new hire or replacement',48,1,''),
('Hardware Repair/Replace','it-hw-repair',1,1,'Broken keyboard, mouse, monitor, docking station',24,2,''),
('Printer/Copier Issue','it-printer',1,1,'Printer jam, toner replacement, scanner issue',8,3,''),
('Peripheral Request','it-peripheral',1,1,'Monitor, headset, webcam, docking station request',48,4,''),
-- IT > Software & Access
('Software & Access','it-software',1,NULL,'Software installs, access requests, licenses',24,2,'key'),
('New Software Install','it-sw-install',1,6,'Request a new software application installation',24,1,''),
('Access Request','it-access',1,6,'Request access to a system, folder, or application',8,2,''),
('Password Reset','it-password',1,6,'Cannot reset via self-service, account locked',2,3,''),
('Microsoft 365 Issue','it-m365',1,6,'Teams, Outlook, OneDrive, SharePoint issues',4,4,''),
('License Request','it-license',1,6,'New or upgraded software license needed',48,5,''),
-- IT > Network & Connectivity
('Network & Connectivity','it-network',1,NULL,'Internet, VPN, Wi-Fi, and network issues',4,3,'wifi'),
('Internet Outage','it-internet-out',1,12,'Complete internet loss at a location',1,1,''),
('VPN Issue','it-vpn',1,12,'Cannot connect to VPN, slow VPN performance',4,2,''),
('Wi-Fi Problem','it-wifi',1,12,'Weak signal, cannot connect, wrong network',4,3,''),
('New Office Network Setup','it-new-office-net',1,12,'Internet and network for a new satellite office',120,4,''),
-- IT > Phone & Communications
('Phone & Communications','it-phone',1,NULL,'Phone system, desk phone, voicemail, call routing',4,4,'phone'),
('Phone System Issue','it-phone-system',1,17,'Call quality, cannot make/receive calls, app issue',4,1,''),
('Extension/Routing Change','it-ext-change',1,17,'New extension, call group change, IVR update',24,2,''),
('Voicemail Setup','it-voicemail',1,17,'New voicemail box, greeting change, forwarding',24,3,''),
-- IT > Business Platforms
('Business Platforms','it-platforms',1,NULL,'Forms, e-signature, call tracking, eFax, CRM issues',8,5,'cloud'),
('Forms Issue','it-forms',1,21,'Form not working, submission error, integration issue',8,1,''),
('E-signature Issue','it-esign',1,21,'Signing failure, template issue, document routing',8,2,''),
('Call Tracking','it-calltracking',1,21,'Tracking number, DNI issue, reporting problem',8,3,''),
('eFax Issue','it-efax',1,21,'Cannot send/receive fax, quality issue',8,4,''),
('CRM Issue','it-crm',1,21,'CRM access, data issue, report not working',8,5,''),
-- IT > Security
('Security Incident','it-security',1,NULL,'Suspected breach, phishing, unauthorized access',1,6,'shield'),
('Phishing Report','it-phishing',1,27,'Suspicious email received, possible phishing',2,1,''),
('Account Compromise','it-acct-compromise',1,27,'Suspected unauthorized access to an account',1,2,''),
('Data Loss/Leak','it-data-loss',1,27,'Accidental data exposure or suspected data leak',1,3,''),
-- IT > Reports & Data
('Reports & Data','it-reports',1,NULL,'Report requests, data exports, analytics, DWH',48,7,'chart'),
('Custom Report Request','it-custom-report',1,31,'Need a new report built or an existing one modified',48,1,''),
('Data Export','it-data-export',1,31,'Need data exported from CRM, database, or platform',24,2,''),
('Dashboard Request','it-dashboard',1,31,'New dashboard or widget for operational metrics',72,3,''),
('Data Warehouse Query','it-dwh-query',1,31,'Ad-hoc query against the data warehouse',48,4,''),
('BI Tool Access','it-bi-access',1,31,'Access to analytics/BI platform or specific reports',24,5,''),
-- IT > Onboarding/Offboarding
('Employee IT Setup','it-employee',1,NULL,'New hire setup and departing employee offboarding',24,8,'user'),
('New Hire IT Setup','it-new-hire',1,37,'Full technology setup for a new employee',48,1,''),
('Employee Offboarding','it-offboarding',1,37,'Deactivate all accounts, collect hardware',4,2,''),
('Role/Department Change','it-role-change',1,37,'Access adjustments for internal transfer',24,3,''),
-- HR Tickets
('HR Requests','hr-requests',2,NULL,'Human Resources service requests',24,1,'users'),
('Time Off Request','hr-time-off',2,41,'PTO, sick leave, personal day requests',24,1,''),
('Benefits Question','hr-benefits',2,41,'Health insurance, 401k, benefits enrollment',48,2,''),
('Training Request','hr-training',2,41,'Professional development, certification, course approval',72,3,''),
('Policy Clarification','hr-policy',2,41,'Question about company policy or handbook',24,4,''),
-- Finance Tickets
('Finance Requests','fin-requests',3,NULL,'Finance and accounting service requests',24,1,'dollar'),
('Expense Report','fin-expense',3,46,'Submit or question about expense reimbursement',48,1,''),
('Invoice Processing','fin-invoice',3,46,'Vendor invoice submission or payment inquiry',48,2,''),
('Budget Request','fin-budget',3,46,'Request for budget allocation or adjustment',72,3,''),
('Purchase Approval','fin-purchase',3,46,'Request approval for a purchase over threshold',24,4,''),
-- Operations Tickets
('Operations Requests','ops-requests',5,NULL,'Operations and facilities service requests',24,1,'tool'),
('Facility Maintenance','ops-facility',5,51,'Building repair, HVAC, lighting, plumbing',24,1,''),
('Office Supplies','ops-supplies',5,51,'Request for office supplies, furniture, equipment',48,2,''),
('Vehicle Request','ops-vehicle',5,51,'Company vehicle reservation or maintenance',48,3,''),
-- Marketing Tickets
('Marketing Requests','mkt-requests',4,NULL,'Marketing service requests',48,1,'megaphone'),
('Graphic Design','mkt-design',4,55,'Request for graphics, brochures, social media assets',72,1,''),
('Website Update','mkt-website',4,55,'Content update, new page, form change on website',48,2,''),
('Campaign Support','mkt-campaign',4,55,'Help with email campaign, ad setup, analytics',48,3,'');

-- ── TICKETS ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_tickets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_number` VARCHAR(20) NOT NULL UNIQUE,
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `priority` ENUM('p1_critical','p2_high','p3_medium','p4_low','p5_planned') DEFAULT 'p3_medium',
  `status` ENUM('open','in_progress','pending','resolved','closed','cancelled') DEFAULT 'open',
  `requester_id` INT UNSIGNED NOT NULL,
  `assignee_id` INT UNSIGNED DEFAULT NULL,
  `due_date` DATETIME DEFAULT NULL,
  `sla_response_due` DATETIME DEFAULT NULL,
  `first_response_at` DATETIME DEFAULT NULL,
  `sla_paused_at` DATETIME DEFAULT NULL,
  `sla_paused_secs` INT NOT NULL DEFAULT 0,
  `resolved_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `resolution_notes` TEXT DEFAULT '',
  `pending_reason` VARCHAR(16) DEFAULT NULL,
  `merged_into_id` INT DEFAULT NULL,
  `case_id` INT DEFAULT NULL,
  `case_main` TINYINT(1) NOT NULL DEFAULT 0,
  `case_close_mode` VARCHAR(8) DEFAULT NULL,
  `ticket_type` VARCHAR(10) DEFAULT NULL,
  `major` TINYINT(1) NOT NULL DEFAULT 0,
  `major_parent_id` INT DEFAULT NULL,
  `major_at` DATETIME DEFAULT NULL,
  `major_by` INT DEFAULT NULL,
  `major_summary` VARCHAR(255) DEFAULT NULL,
  `major_banner` TINYINT(1) NOT NULL DEFAULT 0,
  `verify_user_id` INT DEFAULT NULL,
  `verify_token` CHAR(64) DEFAULT NULL,
  `tags` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ticket_status` (`status`),
  INDEX `idx_ticket_priority` (`priority`),
  INDEX `idx_ticket_requester` (`requester_id`),
  INDEX `idx_ticket_assignee` (`assignee_id`),
  INDEX `idx_tk_merged` (`merged_into_id`),
  INDEX `idx_major_parent` (`major_parent_id`),
  INDEX `idx_tk_case` (`case_id`),
  FOREIGN KEY (`category_id`) REFERENCES `itops_ticket_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`requester_id`) REFERENCES `itops_users`(`id`),
  FOREIGN KEY (`assignee_id`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CASES (BT76: tickets that need several people) ──────────
CREATE TABLE IF NOT EXISTS `itops_cases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_number` VARCHAR(20) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `owner_id` INT NULL,
  `target_date` DATE NULL,
  `summary` TEXT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  `resolved_at` DATETIME NULL,
  `closed_at` DATETIME NULL,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'case',
  `origin` VARCHAR(12) NULL,
  `sponsor_id` INT NULL,
  `former_number` VARCHAR(20) NULL,
  `template_id` INT NULL,
  UNIQUE KEY `uq_case_number` (`case_number`),
  INDEX `idx_case_template` (`template_id`),
  INDEX `idx_case_status` (`status`),
  INDEX `idx_case_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_task_checks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `task_id` INT NOT NULL,
  `seq` INT NOT NULL DEFAULT 0,
  `text` VARCHAR(255) NOT NULL,
  `done` TINYINT(1) NOT NULL DEFAULT 0,
  `done_by` INT NULL,
  `done_at` DATETIME NULL,
  `created_at` DATETIME NULL,
  INDEX `idx_check_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_improvements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `number` VARCHAR(20) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `benefit` TEXT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'new',
  `priority` VARCHAR(8) NULL,
  `effort` VARCHAR(8) NULL,
  `owner_id` INT NULL,
  `target_date` DATE NULL,
  `measure` TEXT NULL,
  `progress` TEXT NULL,
  `source_type` VARCHAR(16) NOT NULL DEFAULT 'suggestion',
  `source_id` INT NULL,
  `source_label` VARCHAR(120) NULL,
  `suggested_by` INT NULL,
  `decided_by` INT NULL,
  `decided_at` DATETIME NULL,
  `decision_note` TEXT NULL,
  `result` VARCHAR(8) NULL,
  `result_note` TEXT NULL,
  `done_at` DATETIME NULL,
  `case_id` INT NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  UNIQUE KEY `uq_improvement_number` (`number`),
  INDEX `idx_imp_status` (`status`),
  INDEX `idx_imp_owner` (`owner_id`),
  INDEX `idx_imp_by` (`suggested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_watchers` (
  `case_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  PRIMARY KEY (`case_id`, `user_id`),
  INDEX `idx_cw_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `seq` INT NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL,
  `public_title` VARCHAR(255) NULL,
  `show_requester` TINYINT(1) NOT NULL DEFAULT 0,
  `kind` VARCHAR(12) NOT NULL DEFAULT 'task',
  `assignee_id` INT NULL,
  `due_date` DATE NULL,
  `depends_on` INT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `reason` VARCHAR(255) NULL,
  `decision` VARCHAR(10) NULL,
  `decision_note` TEXT NULL,
  `done_at` DATETIME NULL,
  `done_by` INT NULL,
  `created_at` DATETIME NULL,
  `start_at` DATETIME NULL,
  `minutes` SMALLINT NULL,
  `event_id` INT NULL,
  `attendees` TEXT NULL,
  `invite_requester` TINYINT(1) NOT NULL DEFAULT 0,
  `start_date` DATE NULL,
  `phase_id` INT NULL,
  INDEX `idx_ct_case` (`case_id`),
  INDEX `idx_ct_assignee` (`assignee_id`),
  INDEX `idx_ct_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `case_title` VARCHAR(255) NULL,
  `anchor_label` VARCHAR(60) NOT NULL DEFAULT 'Start date',
  `target_offset` INT NULL,
  `std_change` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `updated_by` INT NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  UNIQUE KEY `uq_ctpl_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_template_tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_id` INT NOT NULL,
  `seq` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `kind` VARCHAR(12) NOT NULL DEFAULT 'task',
  `who` VARCHAR(10) NOT NULL DEFAULT 'owner',
  `assignee_id` INT NULL,
  `offset_days` INT NOT NULL DEFAULT 0,
  `at_time` VARCHAR(5) NULL,
  `minutes` SMALLINT NULL,
  `depends_seq` INT NULL,
  `show_requester` TINYINT(1) NOT NULL DEFAULT 0,
  `public_title` VARCHAR(255) NULL,
  `invite_requester` TINYINT(1) NOT NULL DEFAULT 0,
  `attendees` TEXT NULL,
  `duration_days` INT NOT NULL DEFAULT 1,
  `phase_name` VARCHAR(100) NULL,
  INDEX `idx_ctt_tpl` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_svc` (
  `case_id` INT NOT NULL PRIMARY KEY,
  `root_cause` TEXT NULL,
  `workaround` TEXT NULL,
  `known_error` TINYINT(1) NOT NULL DEFAULT 0,
  `kb_article_id` INT NULL,
  `change_type` VARCHAR(10) NULL,
  `risk` VARCHAR(8) NULL,
  `impact` TEXT NULL,
  `backout` TEXT NULL,
  `window_start` DATETIME NULL,
  `window_end` DATETIME NULL,
  `emergency_reason` TEXT NULL,
  `approval_state` VARCHAR(12) NOT NULL DEFAULT 'none',
  `approval_tasks` TEXT NULL,
  `outcome` VARCHAR(12) NULL,
  `outcome_note` TEXT NULL,
  `std_template_id` INT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_ci_links` (
  `entity_type` VARCHAR(8) NOT NULL,
  `entity_id` INT NOT NULL,
  `asset_id` INT NOT NULL,
  `linked_by` INT NULL,
  `linked_at` DATETIME NULL,
  PRIMARY KEY (`entity_type`, `entity_id`, `asset_id`),
  INDEX `idx_ci_asset` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_ticket_csat` (
  `ticket_id` INT NOT NULL PRIMARY KEY,
  `token` CHAR(48) NOT NULL,
  `rating` VARCHAR(10) NULL,
  `comment` TEXT NULL,
  `rated_at` DATETIME NULL,
  `rated_by` INT NULL,
  `rated_via` VARCHAR(10) NULL,
  `created_at` DATETIME NULL,
  UNIQUE KEY `uq_csat_token` (`token`),
  INDEX `idx_csat_rated` (`rated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_major_reviews` (
  `ticket_id` INT NOT NULL PRIMARY KEY,
  `what_happened` TEXT NULL,
  `cause` TEXT NULL,
  `actions` TEXT NULL,
  `problem_case_id` INT NULL,
  `written_by` INT NULL,
  `written_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_case_phases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `seq` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(100) NOT NULL,
  `milestone_date` DATE NULL,
  `created_at` DATETIME NULL,
  INDEX `idx_cph_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_budget` (
  `case_id` INT NOT NULL,
  `line_id` INT NOT NULL,
  PRIMARY KEY (`case_id`, `line_id`),
  INDEX `idx_cb_line` (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_digest` (
  `user_id` INT NOT NULL PRIMARY KEY,
  `last_date` DATE NULL,
  `last_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `itops_case_updates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT NOT NULL,
  `user_id` INT NULL,
  `body` TEXT NOT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL,
  INDEX `idx_cu_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TICKET COMMENTS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_ticket_comments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `is_internal` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `itops_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `itops_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TICKET ATTACHMENTS ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_ticket_attachments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_size` BIGINT UNSIGNED DEFAULT 0,
  `mime_type` VARCHAR(100) DEFAULT '',
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `itops_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CALENDAR EVENTS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_calendar_events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT '',
  `event_type` ENUM('sop_review','contract_expiry','contract_renewal','credential_rotation','document_expiry','meeting','task','custom') NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL,
  `all_day` TINYINT(1) DEFAULT 1,
  `color` VARCHAR(7) DEFAULT '#4A7FB5',
  `department_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cal_dates` (`start_date`,`end_date`),
  INDEX `idx_cal_entity` (`entity_type`,`entity_id`),
  FOREIGN KEY (`department_id`) REFERENCES `itops_departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `itops_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ACCREDITATION COMPLIANCE ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_std_domains` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT '',
  `sort_order` INT DEFAULT 0,
  `score` INT DEFAULT 0,
  `evidence_count` INT DEFAULT 0,
  `last_assessed_at` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_std_domains` (`name`,`description`,`sort_order`,`score`,`evidence_count`) VALUES
('Information Management Plan','Documented plan for managing organizational information',1,0,0),
('Data Collection & Analysis','Systematic collection, analysis, and use of data',2,0,0),
('Accessibility & Availability','Information systems accessible to authorized users',3,0,0),
('Confidentiality & Security','Protection of sensitive personal information',4,0,0),
('Data Integrity','Accuracy, completeness, and consistency of data',5,0,0),
('Disaster Recovery','Documented and tested DR/BC procedures',6,0,0),
('Record Retention','Compliant retention and disposal of records',7,0,0),
('Staff Training','Staff trained on IS, security, and confidentiality',8,0,0),
('Risk Management','Identification and mitigation of IS risks',9,0,0);


-- ── NOTIFICATIONS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `itops_sla_alerts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT UNSIGNED NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_ticket_kind` (`ticket_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_forms` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `name` VARCHAR(190) NOT NULL,
  `title` VARCHAR(190) DEFAULT '',
  `description` TEXT DEFAULT NULL,
  `type` ENUM('ticket','data') NOT NULL DEFAULT 'ticket',
  `schema_json` MEDIUMTEXT DEFAULT NULL,
  `settings_json` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_form_submissions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `form_id` INT UNSIGNED NOT NULL,
  `ticket_id` INT UNSIGNED DEFAULT NULL,
  `data_json` MEDIUMTEXT DEFAULT NULL,
  `submitter_name` VARCHAR(190) DEFAULT NULL,
  `submitter_email` VARCHAR(190) DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_form` (`form_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `itops_forms` (`slug`,`name`,`title`,`description`,`type`,`schema_json`,`settings_json`,`status`)
SELECT 'support','Help Request','Submit a Help Request','Tell us what you need and we will get back to you.','ticket','{"fields":[{"key":"name","label":"Your name","type":"text","required":false,"maps_to":"requester_name","placeholder":"Jane Doe"},{"key":"email","label":"Email","type":"email","required":true,"maps_to":"requester_email","placeholder":"jane@company.com"},{"key":"category","label":"Category","type":"group_category","required":false,"maps_to":"category"},{"key":"subject","label":"Subject","type":"text","required":true,"maps_to":"subject"},{"key":"description","label":"Description","type":"textarea","required":true,"maps_to":"description"}]}','{"success_message":"Thanks! Your request has been logged and we will follow up shortly.","redirect_url":""}','active'
WHERE NOT EXISTS (SELECT 1 FROM `itops_forms` WHERE `slug`='support');

CREATE TABLE IF NOT EXISTS `itops_notifications` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT DEFAULT '',
  `type` VARCHAR(50) DEFAULT 'info',
  `link` VARCHAR(500) DEFAULT '',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notif_user` (`user_id`,`is_read`,`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `itops_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `itops_job_descriptions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) DEFAULT NULL,
  `content` LONGTEXT,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `reports_to` VARCHAR(255) DEFAULT NULL,
  `employment_type` VARCHAR(64) DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft','under_review','approved','published','archived') DEFAULT 'draft',
  `current_version` INT UNSIGNED DEFAULT 1,
  `owner_id` INT UNSIGNED DEFAULT NULL,
  `review_frequency_days` INT DEFAULT 365,
  `next_review_date` DATE DEFAULT NULL,
  `last_review_date` DATE DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---- Feature tables (added 0621-U): ensure fresh installs include these ----
CREATE TABLE IF NOT EXISTS itops_kb_gaps (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_ob_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        kind ENUM('onboard','offboard') NOT NULL DEFAULT 'onboard',
        description TEXT,
        department_id INT UNSIGNED DEFAULT NULL,
        status ENUM('active','archived') DEFAULT 'active',
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kind (kind, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_ob_template_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        task_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        config MEDIUMTEXT,
        assignee_role VARCHAR(40) DEFAULT 'it',
        required TINYINT(1) DEFAULT 1,
        KEY idx_tpl (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_ob_runs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_ob_run_tasks (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_alerts (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_event_log (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        a_type VARCHAR(32) NOT NULL,
        a_id INT UNSIGNED NOT NULL,
        b_type VARCHAR(32) NOT NULL,
        b_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_a (a_type, a_id),
        KEY idx_b (b_type, b_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_jd_versions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        jd_id INT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        content LONGTEXT,
        change_summary TEXT,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_jd (jd_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_signature_requests (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_signature_signers (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_api_keys (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_webhooks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        url VARCHAR(500) NOT NULL,
        secret VARCHAR(120) NOT NULL,
        events TEXT,
        status ENUM('active','paused') DEFAULT 'active',
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_webhook_deliveries (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_job_descriptions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_forms (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(150) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL, title VARCHAR(190) DEFAULT '', description TEXT DEFAULT NULL, type ENUM('ticket','data') NOT NULL DEFAULT 'ticket', schema_json MEDIUMTEXT DEFAULT NULL, settings_json TEXT DEFAULT NULL, status ENUM('active','inactive') DEFAULT 'active', created_by INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_form_submissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, ticket_id INT UNSIGNED DEFAULT NULL, data_json MEDIUMTEXT DEFAULT NULL, submitter_name VARCHAR(190) DEFAULT NULL, submitter_email VARCHAR(190) DEFAULT NULL, ip_address VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_form (form_id), KEY idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itops_sla_alerts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id INT UNSIGNED NOT NULL, kind VARCHAR(40) NOT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_ticket_kind (ticket_id,kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

