-- 107_external_shares.sql
-- Controlled, read-only, password-protected, time-limited web view of a provider's profile for a
-- recipient who is NOT a FlxCoreHC user (payer, state agency, accreditation body, hospital, other).
-- Two factors: the URL token AND a password (Argon2id); the password is never emailed with the link.
-- Expiry is set per link in days (capped). Every creation, view and document download is logged.
CREATE TABLE IF NOT EXISTS external_shares (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)     NOT NULL,
  holder_id       CHAR(36)     NOT NULL,
  recipient_type  VARCHAR(20)  NOT NULL,          -- payer|state_agency|accreditation|hospital|other
  recipient_name  VARCHAR(160) NOT NULL,
  scope           JSON         NULL,              -- {"credential_ids":[...], "include_documents":true}
  token_hash      CHAR(64)     NOT NULL,          -- sha256(token); token itself is only in the URL
  password_hash   VARCHAR(255) NOT NULL,          -- Argon2id
  expires_at      DATETIME     NOT NULL,
  max_views       INT          NULL,
  view_count      INT          NOT NULL DEFAULT 0,
  last_viewed_at  DATETIME     NULL,
  failed_attempts INT          NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,
  consent_id      CHAR(36)     NULL,
  created_by      CHAR(36)     NULL,
  created_at      DATETIME     NOT NULL,
  revoked_at      DATETIME     NULL,
  revoked_by      CHAR(36)     NULL,
  UNIQUE KEY uq_es_token (token_hash),
  KEY idx_es_holder (tenant_id, holder_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every access to an external share (successful view / download / failed password) — the log the
-- tenant can show: "Triple-S viewed Dr. Rivera's file on Sep 12 from 203.0.113.9".
CREATE TABLE IF NOT EXISTS external_share_access (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  share_id     CHAR(36)     NOT NULL,
  tenant_id    CHAR(36)     NOT NULL,
  event        VARCHAR(20)  NOT NULL,             -- view|download|password_failed|locked|expired
  document_id  CHAR(36)     NULL,
  ip_address   VARCHAR(45)  NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL,
  KEY idx_esa_share (share_id, created_at),
  KEY idx_esa_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
