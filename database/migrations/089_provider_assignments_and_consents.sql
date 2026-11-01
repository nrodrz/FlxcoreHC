-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 089_provider_assignments_and_consents.sql
-- Privacy architecture, two pieces:
--
-- 1) NEED-TO-KNOW WITHIN A TENANT. Providers are explicitly assigned to team members
--    (holder_assignments), and each user has a provider access mode: 'all' (owners/managers see the
--    whole roster) or 'assigned' (staff see ONLY their assigned providers). The 'assigned' mode is
--    enforced inside HolderRepository — the same layer that enforces tenant scoping — so it applies
--    everywhere providers are resolved and cannot be bypassed by a route. Default is 'all' so
--    existing installs are unchanged until an owner deliberately restricts a user.
--
-- 2) CROSS-TENANT "SECURITY CLEARANCE" LEDGER. Tenants never share data at the database level; two
--    tenants tracking the same real-world provider hold independent encrypted records. When info must
--    cross that boundary, the provider authorizes it: provider_consents records who granted what
--    scope, to whom, from when to when, and whether it was revoked. Share links (the read-only,
--    expiring view mechanism) can be tied to a consent via consent_id. The ledger is the audit spine
--    of the clearance process.

CREATE TABLE IF NOT EXISTS holder_assignments (
  id          CHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)  NOT NULL,
  holder_id   CHAR(36)  NOT NULL,
  user_id     CHAR(36)  NOT NULL,             -- the team member responsible for this provider
  assigned_by CHAR(36)  NULL,
  assigned_at DATETIME  NOT NULL,
  note        VARCHAR(200) NULL,
  UNIQUE KEY uq_assignment (tenant_id, holder_id, user_id),
  KEY idx_assign_user (tenant_id, user_id),
  KEY idx_assign_holder (tenant_id, holder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user provider visibility: 'all' or 'assigned'. Nullable+default 'all' → no backfill needed.
ALTER TABLE users ADD COLUMN provider_access VARCHAR(10) NOT NULL DEFAULT 'all' AFTER locale;

CREATE TABLE IF NOT EXISTS provider_consents (
  id           CHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)  NOT NULL,            -- the tenant holding the record being shared
  holder_id    CHAR(36)  NOT NULL,            -- the provider granting clearance
  granted_to   VARCHAR(200) NOT NULL,         -- recipient (organization / person), as recorded
  scope        VARCHAR(30)  NOT NULL DEFAULT 'summary',  -- summary | full (mirrors share scopes)
  method       VARCHAR(30)  NOT NULL DEFAULT 'written',  -- written | portal | verbal_documented
  granted_at   DATETIME  NOT NULL,
  expires_at   DATETIME  NULL,                -- clearance is time-limited by default
  revoked_at   DATETIME  NULL,
  recorded_by  CHAR(36)  NULL,
  note         VARCHAR(300) NULL,
  KEY idx_consent_holder (tenant_id, holder_id),
  KEY idx_consent_active (tenant_id, holder_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tie a share link to the consent that authorizes it (nullable: internal shares need none).
ALTER TABLE share_links ADD COLUMN consent_id CHAR(36) NULL AFTER scope;
