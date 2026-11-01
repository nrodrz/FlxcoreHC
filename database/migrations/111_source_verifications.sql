-- 111_source_verifications.sql
-- Verification records against authoritative registries (FlxCoreHC records the lookup/evidence; it does not certify primary-source verification), honest about what each source offers:
--   Tier A (automated) — NPPES (free public API) and SAM.gov (free public API with a key)
--   Tier B (assisted)  — ORCPS Good Standing, OCS/SICRO, Medicaid PEP, Medicare PECOS: NO public API,
--                        so the app deep-links to the official page, the user reads the result and
--                        records it with the evidence document. Same record, method = 'manual_source'.
-- Each verification stamps who/when, the source, the outcome, and an optional re-verify date so the
-- system prompts checks on a cadence.

CREATE TABLE IF NOT EXISTS source_verifications (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  holder_id     CHAR(36)     NOT NULL,
  credential_id CHAR(36)     NULL,               -- optional: tie to a specific credential
  source        VARCHAR(24)  NOT NULL,           -- nppes | sam | leie | orcps | ocs | medicaid | medicare | other
  method        VARCHAR(16)  NOT NULL DEFAULT 'manual_source',  -- api | manual_source
  outcome       VARCHAR(16)  NOT NULL,           -- match | mismatch | not_found | flagged | clear | recorded | error
  detail        JSON         NULL,               -- what was compared / returned (no SSN etc.)
  reference     VARCHAR(160) NULL,               -- confirmation #, screenshot filename, etc.
  document_id   CHAR(36)     NULL,               -- the evidence upload, when provided
  verified_by   CHAR(36)     NULL,               -- NULL = system (API)
  verified_by_name VARCHAR(160) NULL,
  verified_on   DATE         NOT NULL,
  reverify_on   DATE         NULL,
  created_at    DATETIME     NOT NULL,
  KEY idx_sv_holder (tenant_id, holder_id, created_at),
  KEY idx_sv_reverify (tenant_id, reverify_on),
  KEY idx_sv_cred (credential_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

