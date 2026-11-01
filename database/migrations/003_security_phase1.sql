-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FlxCoreHC — Security Hardening Phase 1 (code/schema only)
-- Addresses audit findings: M2 (composite tenant FKs), H3 (soft-delete + retention),
-- C1 (encrypted PHI columns), M5 (CHECK constraints), H2 (access logging),
-- L1 (updated_at), L3 (document hash), plus the per-tenant compliance checklist feature.
--
-- Idempotent-ish: written to run once on a v1.1.0 database.
-- Run inside a maintenance window; it rebuilds child foreign keys.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- M2. Composite tenant foreign keys — make cross-tenant references
--     IMPOSSIBLE at the database layer, not merely discouraged.
--     Parent tables need a UNIQUE(tenant_id, id) for the composite FK to target.
-- =====================================================================
ALTER TABLE credential_holders ADD UNIQUE KEY uq_holder_tenant (tenant_id, id);
ALTER TABLE carriers           ADD UNIQUE KEY uq_carrier_tenant (tenant_id, id);
ALTER TABLE credentials        ADD UNIQUE KEY uq_cred_tenant   (tenant_id, id);

-- credentials.holder_id / carrier_id must belong to the SAME tenant
ALTER TABLE credentials DROP FOREIGN KEY fk_cred_holder;
ALTER TABLE credentials DROP FOREIGN KEY fk_cred_carrier;
ALTER TABLE credentials
  ADD CONSTRAINT fk_cred_holder_t  FOREIGN KEY (tenant_id, holder_id)
      REFERENCES credential_holders (tenant_id, id),
  ADD CONSTRAINT fk_cred_carrier_t FOREIGN KEY (tenant_id, carrier_id)
      REFERENCES carriers (tenant_id, id);

-- renewal_tasks.credential_id same-tenant
ALTER TABLE renewal_tasks DROP FOREIGN KEY fk_renewal_cred;
ALTER TABLE renewal_tasks
  ADD CONSTRAINT fk_renewal_cred_t FOREIGN KEY (tenant_id, credential_id)
      REFERENCES credentials (tenant_id, id);

-- credential_documents.credential_id same-tenant
ALTER TABLE credential_documents DROP FOREIGN KEY fk_doc_cred;
ALTER TABLE credential_documents
  ADD CONSTRAINT fk_doc_cred_t FOREIGN KEY (tenant_id, credential_id)
      REFERENCES credentials (tenant_id, id);

-- credential_reminders.credential_id same-tenant
ALTER TABLE credential_reminders DROP FOREIGN KEY fk_rem_cred;
ALTER TABLE credential_reminders
  ADD CONSTRAINT fk_rem_cred_t FOREIGN KEY (tenant_id, credential_id)
      REFERENCES credentials (tenant_id, id);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- H3. Soft-delete + retention. NULL = live row. Set = logically deleted.
-- =====================================================================
ALTER TABLE credentials        ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER created_at;
ALTER TABLE credential_holders ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER created_at;
ALTER TABLE carriers           ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER created_at;
ALTER TABLE renewal_tasks      ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER created_at;
ALTER TABLE credential_documents ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER created_at;

-- =====================================================================
-- L1. updated_at on mutable tables (auto-maintained by MariaDB).
-- =====================================================================
ALTER TABLE credentials        ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE credential_holders ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE carriers           ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE renewal_tasks      ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- =====================================================================
-- C1. Encrypted PHI/PII storage. Ciphertext columns hold libsodium AEAD
--     blobs; a blind index (HMAC) enables exact-match lookups without
--     decrypting. App writes/reads via Crypto service. Plaintext columns
--     are migrated then dropped by a separate data-migration step (098).
-- =====================================================================
ALTER TABLE credential_holders
  ADD COLUMN npi_enc      VARBINARY(255) NULL AFTER npi,
  ADD COLUMN npi_bidx     CHAR(64)       NULL AFTER npi_enc,
  ADD COLUMN npn_enc      VARBINARY(255) NULL AFTER npn,
  ADD COLUMN npn_bidx     CHAR(64)       NULL AFTER npn_enc,
  ADD COLUMN email_enc    VARBINARY(512) NULL AFTER email,
  ADD INDEX idx_holder_npi_bidx (tenant_id, npi_bidx),
  ADD INDEX idx_holder_npn_bidx (tenant_id, npn_bidx);

ALTER TABLE credentials
  ADD COLUMN credential_number_enc VARBINARY(512) NULL AFTER credential_number;

-- =====================================================================
-- L3. Document integrity hash.
-- =====================================================================
ALTER TABLE credential_documents
  ADD COLUMN sha256 CHAR(64) NULL AFTER mime_type;

-- =====================================================================
-- M5. CHECK constraints on tenant-bearing enums (MariaDB 10.2+ enforces).
-- =====================================================================
ALTER TABLE tenants
  ADD CONSTRAINT chk_tenant_vertical CHECK (vertical IN ('healthcare','insurance','custom','platform')),
  ADD CONSTRAINT chk_tenant_status   CHECK (status   IN ('active','suspended','closed'));
ALTER TABLE users
  ADD CONSTRAINT chk_user_role   CHECK (role   IN ('tenant_owner','manager','staff','provider_client','agent')),
  ADD CONSTRAINT chk_user_status CHECK (status IN ('active','suspended','invited'));
ALTER TABLE credentials
  ADD CONSTRAINT chk_cred_status CHECK (status IN ('active','expiring','expired','suspended'));

-- =====================================================================
-- H2. Access logging (reads/downloads), separate from mutation audit_logs.
--     Append-only by intent; in production the app uses an INSERT-only DB
--     account for this table.
-- =====================================================================
CREATE TABLE IF NOT EXISTS access_logs (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  user_id      CHAR(36)     NULL,
  action       VARCHAR(40)  NOT NULL,        -- view | download | export | login | logout
  entity_type  VARCHAR(60)  NULL,
  entity_id    CHAR(36)     NULL,
  ip_address   VARCHAR(45)  NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL,
  INDEX idx_access_tenant (tenant_id, created_at),
  CONSTRAINT fk_access_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Per-tenant compliance checklist feature.
--   compliance_items  = catalog of checklist items (global templates +
--                       tenant-custom), gated by vertical.
--   tenant_compliance = each tenant's status per applicable item.
-- =====================================================================
CREATE TABLE IF NOT EXISTS compliance_items (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NULL,            -- NULL = global template item
  applies_to   VARCHAR(20)  NOT NULL DEFAULT 'all',  -- all | healthcare | insurance
  category     VARCHAR(60)  NOT NULL,        -- access | audit | encryption | retention | training | policy
  code         VARCHAR(40)  NOT NULL,        -- stable key e.g. 'mfa_admins'
  title        VARCHAR(200) NOT NULL,
  description  TEXT         NULL,
  severity     VARCHAR(20)  NOT NULL DEFAULT 'recommended', -- required | recommended
  sort_order   INT          NOT NULL DEFAULT 100,
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL,
  INDEX idx_compitem_scope (tenant_id, applies_to, active),
  CONSTRAINT chk_compitem_applies CHECK (applies_to IN ('all','healthcare','insurance')),
  CONSTRAINT chk_compitem_sev CHECK (severity IN ('required','recommended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_compliance (
  id            CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)    NOT NULL,
  item_id       CHAR(36)    NOT NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'not_started', -- not_started | in_progress | done | na
  note          TEXT        NULL,
  evidence_url  VARCHAR(500) NULL,
  reviewed_at   DATETIME    NULL,
  reviewed_by   CHAR(36)    NULL,
  due_date      DATE        NULL,
  created_at    DATETIME    NOT NULL,
  updated_at    DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenant_item (tenant_id, item_id),
  INDEX idx_tcomp_tenant (tenant_id, status),
  CONSTRAINT fk_tcomp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tcomp_item   FOREIGN KEY (item_id)   REFERENCES compliance_items(id),
  CONSTRAINT chk_tcomp_status CHECK (status IN ('not_started','in_progress','done','na'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
