-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 121_agency_credentialing.sql
-- Agency Credentialing — an ORCHESTRATION layer over existing FlxCoreHC entities.
-- Adds the Credentialing Case as the operational unit of work, its case-specific requirement
-- checklist, a case-document store (for evidence that is NOT tied to a credential), and an explicit
-- typed evidence-link table. Extends four existing tables with a NULLABLE case_id (additive,
-- backward-compatible — existing rows and code paths keep working with case_id = NULL).
--
-- Conventions match the codebase: CHAR(36) UUID PKs, tenant_id CHAR(36) NOT NULL on every tenant-owned
-- table, VARCHAR status/type (not native ENUM), DATETIME timestamps, TINYINT(1) booleans, soft-delete
-- via deleted_at, tenant-first composite KEYs, InnoDB/utf8mb4. Isolation is app-enforced (no DB FKs),
-- consistent with every existing migration.
--
-- Final decisions honored (see docs/architecture ADR + Implementation Directive):
--   provider master = credential_holders; client group = provider_groups (nullable FK); requirement
--   identity = credential_catalog.code; credential_documents.credential_id stays NOT NULL (untouched);
--   evidence = typed nullable FKs (no polymorphic); authority = payers (no regulators table created).

-- 1) Credentialing Case — one provider has many cases over time.
CREATE TABLE IF NOT EXISTS credentialing_cases (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id         CHAR(36)     NOT NULL,
  holder_id         CHAR(36)     NOT NULL,                 -- credential_holders.id (provider master)
  client_group_id   CHAR(36)     NULL,                     -- provider_groups.id (client/group context)
  location_id       CHAR(36)     NULL,                     -- locations.id
  payer_id          CHAR(36)     NULL,                     -- payers.id (enrollment target; NOT a regulators FK)
  case_type         VARCHAR(32)  NOT NULL DEFAULT 'initial', -- initial|recredential|revalidation|enrollment|payer_change|license_renewal|location_change|demographic_update|other
  status            VARCHAR(24)  NOT NULL DEFAULT 'open',    -- open|in_progress|ready_for_submission|submitted|completed|closed|cancelled
  priority          VARCHAR(8)   NULL,                       -- low|normal|high
  external_reference VARCHAR(120) NULL,
  opened_at         DATETIME     NOT NULL,
  target_date       DATE         NULL,
  submitted_at      DATETIME     NULL,
  completed_at      DATETIME     NULL,
  closed_at         DATETIME     NULL,
  assigned_to       CHAR(36)     NULL,                       -- users.id
  notes             VARCHAR(1000) NULL,
  created_by        CHAR(36)     NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NULL,
  deleted_at        DATETIME     NULL,
  KEY idx_cases_holder   (tenant_id, holder_id, deleted_at),
  KEY idx_cases_status   (tenant_id, status, deleted_at),
  KEY idx_cases_target   (tenant_id, target_date),
  KEY idx_cases_assigned (tenant_id, assigned_to, status),
  KEY idx_cases_group    (tenant_id, client_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Case Requirements — the case-specific checklist (distinct from ongoing holder_requirements).
--    requirement_code references the ACTIVE credential_catalog.code identity.
CREATE TABLE IF NOT EXISTS credentialing_case_requirements (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)     NOT NULL,
  case_id         CHAR(36)     NOT NULL,                    -- credentialing_cases.id
  requirement_code VARCHAR(64) NOT NULL,                    -- credential_catalog.code
  label           VARCHAR(160) NULL,                        -- snapshot of the catalog label at add-time
  status          VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- pending|in_progress|satisfied|waived|failed
  is_required     TINYINT(1)   NOT NULL DEFAULT 1,
  is_hard_stop    TINYINT(1)   NOT NULL DEFAULT 0,
  due_date        DATE         NULL,
  completed_at    DATETIME     NULL,
  assigned_to     CHAR(36)     NULL,
  notes           VARCHAR(600) NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_case_req (tenant_id, case_id, requirement_code),
  KEY idx_creq_case   (tenant_id, case_id, status),
  KEY idx_creq_status (tenant_id, status),
  KEY idx_creq_due    (tenant_id, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Case Documents — evidence that does NOT belong to a specific credential (CV, W-9, application
--    form, payer form). credential_documents is left untouched for credential-tied documents.
CREATE TABLE IF NOT EXISTS case_documents (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  case_id       CHAR(36)     NULL,                          -- credentialing_cases.id (nullable: provider-level case evidence)
  holder_id     CHAR(36)     NOT NULL,                      -- credential_holders.id (always provider-scoped)
  doc_type      VARCHAR(48)  NULL,                          -- cv|w9|application|attestation|payer_form|other
  file_path     VARCHAR(255) NOT NULL,                      -- storage reference (same convention as credential_documents)
  original_name VARCHAR(255) NULL,
  mime_type     VARCHAR(120) NULL,
  byte_size     INT          NULL,
  checksum      VARCHAR(64)  NULL,
  expires_on    DATE         NULL,
  uploaded_by   CHAR(36)     NULL,
  notes         VARCHAR(400) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL,
  deleted_at    DATETIME     NULL,
  KEY idx_casedoc_case   (tenant_id, case_id, deleted_at),
  KEY idx_casedoc_holder (tenant_id, holder_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Case Requirement Evidence — explicit, typed traceability. Exactly ONE target column is populated
--    per row (enforced in the service layer; MySQL lacks a portable multi-column XOR CHECK across
--    versions used on shared hosting, so validation is mandatory in CaseRequirementEvidenceRepository).
CREATE TABLE IF NOT EXISTS case_requirement_evidence (
  id                   CHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id            CHAR(36)  NOT NULL,
  case_requirement_id  CHAR(36)  NOT NULL,                  -- credentialing_case_requirements.id
  credential_id        CHAR(36)  NULL,                      -- credentials.id
  credential_document_id CHAR(36) NULL,                     -- credential_documents.id
  case_document_id     CHAR(36)  NULL,                      -- case_documents.id
  verification_id      CHAR(36)  NULL,                      -- source_verifications.id
  attestation_id       CHAR(36)  NULL,                      -- holder_attestations.id
  created_by           CHAR(36)  NULL,
  created_at           DATETIME  NOT NULL,
  KEY idx_evi_req (tenant_id, case_requirement_id),
  KEY idx_evi_cred (tenant_id, credential_id),
  KEY idx_evi_verif (tenant_id, verification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5-8) Nullable case_id on existing tables (additive; existing rows/code keep working).
ALTER TABLE applications        ADD COLUMN case_id CHAR(36) NULL AFTER holder_id;
ALTER TABLE source_verifications ADD COLUMN case_id CHAR(36) NULL AFTER holder_id;
ALTER TABLE document_requests   ADD COLUMN case_id CHAR(36) NULL AFTER holder_id;
ALTER TABLE compliance_events   ADD COLUMN case_id CHAR(36) NULL AFTER holder_id;

-- indexes for case-scoped lookups on the extended tables
ALTER TABLE applications        ADD KEY idx_app_case  (tenant_id, case_id);
ALTER TABLE source_verifications ADD KEY idx_sv_case  (tenant_id, case_id);
ALTER TABLE document_requests   ADD KEY idx_dr_case   (tenant_id, case_id);
ALTER TABLE compliance_events   ADD KEY idx_ce_case   (tenant_id, case_id);
