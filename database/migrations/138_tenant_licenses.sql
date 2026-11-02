-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 138_tenant_licenses.sql — v1.175.0: signed license records (Ownership & White-Label Standard, licensing).
--   One row per license issued to an organization by the platform operator (Console → Licenses). The fields that decide what is switched on
--   (serial, agreement, licensee, workspace_id, kind, plan, modules, users, term_start, term_end, notes, issued_at, deployment) are signed
--   with Ed25519; key_id and signature sit beside them. A row whose signature does not verify is ignored. Rows are never edited: a renewal is a
--   new row, a revocation sets revoked_at.
CREATE TABLE IF NOT EXISTS tenant_licenses (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  serial       VARCHAR(24)  NOT NULL,
  agreement    VARCHAR(80)  NULL,
  licensee     VARCHAR(200) NOT NULL,
  workspace_id CHAR(19)     NOT NULL,
  kind         VARCHAR(12)  NOT NULL,
  plan         VARCHAR(60)  NULL,
  modules      VARCHAR(400) NOT NULL,
  users        INT          NULL,
  term_start   DATE         NOT NULL,
  term_end     DATE         NULL,
  notes        VARCHAR(500) NULL,
  issued_at    DATETIME     NOT NULL,
  deployment   VARCHAR(12)  NOT NULL DEFAULT 'hosted',
  key_id       CHAR(16)     NOT NULL,
  signature    VARCHAR(140) NOT NULL,
  issued_by    CHAR(36)     NULL,
  revoked_at   DATETIME     NULL,
  revoked_by   CHAR(36)     NULL,
  UNIQUE KEY uq_license_serial (serial),
  KEY idx_license_tenant (tenant_id, issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
