-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- v1.175.1 — table clean-up: 94 -> 86 tables. Three unused tables are dropped and five pairs/singletons are
-- merged into one table each. Existing rows (if any) are copied across before the old tables go.

-- 1) Reminders: credential_reminders + entity_reminders -> reminder_log
CREATE TABLE IF NOT EXISTS reminder_log (
  id           CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)    NOT NULL,
  subject_type VARCHAR(24) NOT NULL,   -- credential | application | screening | attestation | leie_sweep ...
  subject_id   CHAR(36)    NOT NULL,   -- the credential / row / provider the reminder is about
  marker       VARCHAR(80) NOT NULL,   -- trigger instance key ('30:2026-12-01' for a credential threshold)
  channel      VARCHAR(20) NOT NULL DEFAULT 'email',
  queued_at    DATETIME    NOT NULL,
  sent_at      DATETIME    NULL,
  UNIQUE KEY uq_reminder_log (tenant_id, subject_type, subject_id, marker),
  KEY idx_rl_subject (subject_id),
  CONSTRAINT fk_rl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reminder_log (id, tenant_id, subject_type, subject_id, marker, channel, queued_at, sent_at)
SELECT id, tenant_id, 'credential', credential_id, CONCAT(days_before, ':', COALESCE(expiry_snapshot, '')), channel, queued_at, sent_at
  FROM credential_reminders;
INSERT IGNORE INTO reminder_log (id, tenant_id, subject_type, subject_id, marker, channel, queued_at, sent_at)
SELECT id, tenant_id, entity_type, entity_id, marker, channel, queued_at, sent_at
  FROM entity_reminders;

-- 2) Sign-in tokens: auth_email_codes + password_resets -> auth_tokens
CREATE TABLE IF NOT EXISTS auth_tokens (
  id           CHAR(36)    NOT NULL PRIMARY KEY,
  user_id      CHAR(36)    NOT NULL,
  tenant_id    CHAR(36)    NULL,       -- set for password-reset links; e-mailed codes are keyed by user only
  kind         VARCHAR(12) NOT NULL,   -- login | 2fa_on | ... (e-mailed code purposes) | pwreset (forgot-password link)
  token_hash   CHAR(64)    NOT NULL,   -- keyed hash of a code, or SHA-256 of a link token
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME    NOT NULL,
  used_at      DATETIME    NULL,
  requested_ip VARCHAR(45) NULL,
  created_at   DATETIME    NOT NULL,
  KEY idx_at_user (user_id, kind),
  KEY idx_at_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO auth_tokens (id, user_id, tenant_id, kind, token_hash, attempts, expires_at, used_at, requested_ip, created_at)
SELECT id, user_id, NULL, purpose, code_hash, attempts, expires_at, used_at, NULL, created_at FROM auth_email_codes;
INSERT INTO auth_tokens (id, user_id, tenant_id, kind, token_hash, attempts, expires_at, used_at, requested_ip, created_at)
SELECT id, user_id, tenant_id, 'pwreset', token_hash, 0, expires_at, used_at, requested_ip, created_at FROM password_resets;

-- 3) Share access logs: external_share_access + cross_share_access -> share_access_log
CREATE TABLE IF NOT EXISTS share_access_log (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  scope               VARCHAR(8)   NOT NULL,   -- external (link) | cross (organisation to organisation)
  share_id            CHAR(36)     NOT NULL,
  owner_tenant_id     CHAR(36)     NOT NULL,   -- the organisation that owns the shared file
  recipient_tenant_id CHAR(36)     NULL,       -- cross shares only
  user_id             CHAR(36)     NULL,       -- cross shares only
  event               VARCHAR(20)  NOT NULL,
  document_id         CHAR(36)     NULL,
  ip_address          VARCHAR(45)  NULL,
  user_agent          VARCHAR(255) NULL,
  created_at          DATETIME     NOT NULL,
  KEY idx_sal_share (share_id, created_at),
  KEY idx_sal_owner (owner_tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO share_access_log (id, scope, share_id, owner_tenant_id, recipient_tenant_id, user_id, event, document_id, ip_address, user_agent, created_at)
SELECT id, 'external', share_id, tenant_id, NULL, NULL, event, document_id, ip_address, user_agent, created_at FROM external_share_access;
INSERT INTO share_access_log (id, scope, share_id, owner_tenant_id, recipient_tenant_id, user_id, event, document_id, ip_address, user_agent, created_at)
SELECT id, 'cross', share_id, owner_tenant_id, recipient_tenant_id, user_id, event, document_id, ip_address, NULL, created_at FROM cross_share_access;

-- 4) Invoice counter -> a platform_settings key
INSERT IGNORE INTO platform_settings (skey, sval, updated_at)
SELECT 'invoice_seq', CAST(next_seq - 1 AS CHAR), UTC_TIMESTAMP() FROM invoice_counter WHERE id = 1;

-- 5) Drop the merged tables and the three that nothing ever used
DROP TABLE IF EXISTS credential_reminders;
DROP TABLE IF EXISTS entity_reminders;
DROP TABLE IF EXISTS auth_email_codes;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS external_share_access;
DROP TABLE IF EXISTS cross_share_access;
DROP TABLE IF EXISTS invoice_counter;
DROP TABLE IF EXISTS holder_requirements;
DROP TABLE IF EXISTS requirement_defs;
DROP TABLE IF EXISTS nipr_interest;
