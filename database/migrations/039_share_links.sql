-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Carrier-facing share links — read-only credential verification without a login.
--
-- When an upline or carrier needs to confirm a producer is appointment-ready, the agency
-- today emails a PDF. A share link replaces that: a tokenized, expiring, revocable URL that
-- shows exactly one producer's credential status — no login, no access to anything else,
-- and every view is logged. The token is unguessable (32 hex chars) and the link carries
-- its own expiry so it cannot outlive its purpose.

CREATE TABLE IF NOT EXISTS share_links (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  holder_id    CHAR(36)     NOT NULL,          -- the producer being verified
  token        CHAR(32)     NOT NULL,          -- unguessable, in the URL
  label        VARCHAR(120) NULL,              -- e.g. "For Humana contracting"
  scope        VARCHAR(20)  NOT NULL DEFAULT 'summary',  -- summary | full (with evidence counts)
  created_by   CHAR(36)     NULL,
  expires_at   DATETIME     NOT NULL,
  revoked_at   DATETIME     NULL,
  view_count   INT          NOT NULL DEFAULT 0,
  last_viewed  DATETIME     NULL,
  created_at   DATETIME     NOT NULL,
  UNIQUE KEY uq_share_token (token),
  INDEX idx_share_tenant (tenant_id, holder_id),
  CONSTRAINT fk_share_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
