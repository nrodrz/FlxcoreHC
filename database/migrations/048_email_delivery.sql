-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Email delivery (v1.53). On shared hosting PHP mail() frequently fails silently, so every
-- message is queued in an outbox first, then a sender attempts delivery (SMTP if configured,
-- otherwise mail()) and records the result. This gives retries and an audit trail, and lets
-- the operator configure and test SMTP from the console instead of editing config files.

-- Platform-level key/value settings (no tenant_id — SMTP config is operator-wide).
CREATE TABLE IF NOT EXISTS platform_settings (
  skey       VARCHAR(60)  NOT NULL PRIMARY KEY,
  sval       TEXT         NULL,
  updated_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_outbox (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NULL,                 -- source tenant if applicable (nullable: platform mail)
  to_email     VARCHAR(190) NOT NULL,
  to_name      VARCHAR(120) NULL,
  subject      VARCHAR(255) NOT NULL,
  body         MEDIUMTEXT   NOT NULL,
  category     VARCHAR(40)  NOT NULL DEFAULT 'general', -- invite | approval | rejection | test | reminder
  status       VARCHAR(20)  NOT NULL DEFAULT 'queued',  -- queued | sent | failed
  attempts     SMALLINT     NOT NULL DEFAULT 0,
  last_error   VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL,
  sent_at      DATETIME     NULL,
  INDEX idx_outbox_status (status, created_at),
  INDEX idx_outbox_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
