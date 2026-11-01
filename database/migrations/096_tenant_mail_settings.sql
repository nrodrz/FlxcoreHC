-- 096_tenant_mail_settings.sql
-- Per-tenant email delivery. Each organization can send through ITS OWN mailbox/provider — Google
-- Workspace, Microsoft 365, a cPanel mailbox, or any SMTP submission endpoint — independent of the
-- platform's mail. Unconfigured tenants fall back to the platform default (current behavior).
-- Credentials are encrypted at rest with the app's crypto layer; the password is never rendered back.
-- 'transport' leaves room for future oauth/graph transports without a schema change.

CREATE TABLE IF NOT EXISTS tenant_mail_settings (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL UNIQUE,
  transport   VARCHAR(12)  NOT NULL DEFAULT 'platform',   -- platform | smtp
  from_email  VARCHAR(190) NULL,
  from_name   VARCHAR(120) NULL,
  smtp_host   VARCHAR(190) NULL,
  smtp_port   SMALLINT     NULL,
  smtp_secure VARCHAR(6)   NULL,                          -- tls | ssl | none
  smtp_user   VARCHAR(190) NULL,
  smtp_pass_enc VARBINARY(1024) NULL,                   -- encrypted (binary), never plaintext
  verified_at DATETIME     NULL,                          -- last successful test send
  last_error  VARCHAR(255) NULL,
  updated_by  CHAR(36)     NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
