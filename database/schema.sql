-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FlxCoreHC MVP schema — MariaDB 10.6+ / MySQL 8
-- All timestamps UTC DATETIME. All business tables tenant-scoped.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tenants (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  name          VARCHAR(200) NOT NULL,
  vertical      VARCHAR(40)  NOT NULL,
  org_shape     VARCHAR(40)  NOT NULL,
  plan_tier     VARCHAR(40)  NOT NULL DEFAULT "starter",
  has_commission_module TINYINT(1) NOT NULL DEFAULT 0,
  status        VARCHAR(20)  NOT NULL DEFAULT "active",
  created_at    DATETIME     NOT NULL,
  INDEX idx_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(200) NOT NULL,
  role          VARCHAR(40)  NOT NULL DEFAULT "tenant_owner",
  holder_id     CHAR(36)     NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT "active",
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_tenant (tenant_id, role),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  identifier  VARCHAR(300) NOT NULL,
  attempted_at DATETIME    NOT NULL,
  INDEX idx_attempts (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credential_holders (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  holder_type   VARCHAR(20)  NOT NULL,
  first_name    VARCHAR(100) NULL,
  last_name     VARCHAR(100) NULL,
  org_name      VARCHAR(200) NULL,
  npi           VARCHAR(20)  NULL,
  npn           VARCHAR(20)  NULL,
  email         VARCHAR(255) NULL,
  department    VARCHAR(100) NULL,
  role_title    VARCHAR(100) NULL,
  vertical      VARCHAR(40)  NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT "active",
  created_at    DATETIME     NOT NULL,
  INDEX idx_holders_tenant (tenant_id, holder_type),
  CONSTRAINT fk_holders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT chk_holder_type CHECK (holder_type IN ("individual","organization"))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS carriers (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  name        VARCHAR(120) NOT NULL,
  short_name  VARCHAR(20)  NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_at  DATETIME     NOT NULL,
  INDEX idx_carriers_tenant (tenant_id, status),
  CONSTRAINT fk_carriers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credentials (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id         CHAR(36)     NOT NULL,
  holder_id         CHAR(36)     NOT NULL,
  credential_type   VARCHAR(80)  NOT NULL,
  name              VARCHAR(200) NOT NULL,
  issuing_body      VARCHAR(200) NULL,
  credential_number VARCHAR(100) NULL,
  issued_date       DATE         NULL,
  expiry_date       DATE         NULL,
  status            VARCHAR(20)  NOT NULL DEFAULT "active",
  category          VARCHAR(60)  NULL,
  plan_year         SMALLINT     NULL,
  carrier_id        CHAR(36)     NULL,
  notes             TEXT         NULL,
  last_verified_at  DATETIME     NULL,
  created_at        DATETIME     NOT NULL,
  INDEX idx_cred_tenant_expiry (tenant_id, expiry_date),
  INDEX idx_cred_holder (tenant_id, holder_id),
  INDEX idx_cred_plan_year (tenant_id, plan_year),
  CONSTRAINT fk_cred_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_cred_holder FOREIGN KEY (holder_id) REFERENCES credential_holders(id),
  CONSTRAINT fk_cred_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS renewal_tasks (
  id            CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)    NOT NULL,
  credential_id CHAR(36)    NOT NULL,
  assigned_to   CHAR(36)    NULL,
  title         VARCHAR(200) NOT NULL,
  status        VARCHAR(20) NOT NULL DEFAULT "pending",
  due_date      DATE        NULL,
  steps         JSON        NULL,
  completed_at  DATETIME    NULL,
  created_at    DATETIME    NOT NULL,
  INDEX idx_renewal_tenant (tenant_id, status),
  CONSTRAINT fk_renewal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_renewal_cred FOREIGN KEY (credential_id) REFERENCES credentials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credential_documents (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  credential_id CHAR(36)     NOT NULL,
  file_path     VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NULL,
  uploaded_by   CHAR(36)     NULL,
  created_at    DATETIME     NOT NULL,
  INDEX idx_doc_tenant (tenant_id, credential_id),
  CONSTRAINT fk_doc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_doc_cred FOREIGN KEY (credential_id) REFERENCES credentials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credential_reminders (
  id            CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)    NOT NULL,
  credential_id CHAR(36)    NOT NULL,
  days_before   INT         NOT NULL,
  queued_at     DATETIME    NOT NULL,
  sent_at       DATETIME    NULL,
  channel      VARCHAR(20) NOT NULL DEFAULT "email",
  UNIQUE KEY uq_reminder (credential_id, days_before),
  INDEX idx_rem_tenant (tenant_id, sent_at),
  CONSTRAINT fk_rem_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rem_cred FOREIGN KEY (credential_id) REFERENCES credentials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  user_id       CHAR(36)     NULL,
  action        VARCHAR(40)  NOT NULL,
  entity_type   VARCHAR(60)  NOT NULL,
  entity_id     CHAR(36)     NULL,
  changes       JSON         NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL,
  INDEX idx_audit_tenant (tenant_id, created_at),
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
