-- 076_verifications_attestations.sql
-- Slice 4: the two records that turn an expiry tracker into a credentialing system.
--
-- credential_verifications — a verification-tracking trail (customer-recorded) for a credential: who
-- verified it, how (primary source, copy on file, database check, provider attestation), against
-- which source, on what date, and the result. Multiple verifications accumulate as history; the
-- most recent still stamps credentials.last_verified_at for the existing "last verified" display.
--
-- holder_attestations — the provider's own signed/acknowledged statement that their information is
-- accurate and complete (the legal backbone of delegated credentialing). Recorded by the provider
-- in their portal, or by staff on their behalf with the method noted.

CREATE TABLE IF NOT EXISTS credential_verifications (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  credential_id CHAR(36)     NOT NULL,
  holder_id     CHAR(36)     NOT NULL,               -- denormalised for per-provider queries
  method        VARCHAR(20)  NOT NULL DEFAULT 'primary_source', -- primary_source|copy|database|attestation|other
  source        VARCHAR(200) NULL,                   -- e.g. "NPPES", "PR Board website", "issuer phone"
  verified_on   DATE         NOT NULL,
  verified_by   VARCHAR(160) NULL,                   -- verifier name (free text; staff user or external)
  verified_by_user CHAR(36)  NULL,                   -- linked app user when verified in-app
  result        VARCHAR(16)  NOT NULL DEFAULT 'verified',      -- verified|discrepancy|unable|expired
  notes         VARCHAR(400) NULL,
  created_at    DATETIME     NOT NULL,
  KEY idx_cv_cred (tenant_id, credential_id),
  KEY idx_cv_holder (tenant_id, holder_id),
  CONSTRAINT fk_cv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holder_attestations (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  holder_id    CHAR(36)     NOT NULL,
  statement    VARCHAR(600) NOT NULL,                -- the text the provider attested to
  method       VARCHAR(20)  NOT NULL DEFAULT 'portal_ack', -- portal_ack|esign|wet_signature|verbal
  attested_by  VARCHAR(160) NULL,                    -- name captured at attestation time
  attested_by_user CHAR(36) NULL,                    -- app user who attested (provider or staff)
  attested_on  DATETIME     NOT NULL,
  ip_address   VARCHAR(45)  NULL,                    -- acknowledgement provenance
  notes        VARCHAR(400) NULL,
  created_at   DATETIME     NOT NULL,
  KEY idx_att_holder (tenant_id, holder_id),
  CONSTRAINT fk_att_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
