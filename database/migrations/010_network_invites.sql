-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FlxCoreHC — consent-based network invites (self-serve downline management).
-- A parent (FMO/agency) issues an invite code; a downline tenant accepts it, which
-- sets its parent_tenant_id. Oversight is read-only and the downline can leave anytime.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS network_invites (
  id                 CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id          CHAR(36)    NOT NULL,
  invite_code        VARCHAR(24) NOT NULL,
  invited_email      VARCHAR(255) NULL,
  role               VARCHAR(20) NOT NULL DEFAULT 'agency',
  status             VARCHAR(12) NOT NULL DEFAULT 'pending',
  accepted_tenant_id CHAR(36)    NULL,
  expires_at         DATETIME    NULL,
  created_at         DATETIME    NOT NULL,
  accepted_at        DATETIME    NULL,
  UNIQUE KEY uq_invite_code (invite_code),
  INDEX idx_invite_tenant (tenant_id, status),
  CONSTRAINT fk_invite_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT chk_invite_role   CHECK (role IN ('agency','agent')),
  CONSTRAINT chk_invite_status CHECK (status IN ('pending','accepted','revoked','expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
