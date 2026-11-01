-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Custom-role engine (v1.49): permissions move from hardcoded code into per-tenant data.
--
-- Until now a user's role was a fixed string and Authz held a hardcoded capability matrix.
-- This lets each tenant define its own roles and tick which features each role may use.
--
-- Two new tables, both tenant-scoped:
--   roles              — a named role per tenant (system roles seeded for every tenant)
--   role_capabilities  — which capability slugs each role grants
--
-- users.role stays as-is (the role SLUG). System role slugs match the old strings
-- (tenant_owner, manager, staff, agent, producer, read_only) so nothing breaks and no
-- user rows need rewriting. Authz now resolves a user's capabilities by joining
-- users.role -> roles(slug) -> role_capabilities, scoped to the tenant.

CREATE TABLE IF NOT EXISTS roles (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  slug         VARCHAR(40)  NOT NULL,             -- stable key stored on users.role
  name         VARCHAR(80)  NOT NULL,             -- editable display name
  description  VARCHAR(200) NULL,
  is_system    TINYINT(1)   NOT NULL DEFAULT 0,   -- seeded roles: protected from deletion
  portal_only  TINYINT(1)   NOT NULL DEFAULT 0,   -- confined to the producer self-service portal
  sort_order   SMALLINT     NOT NULL DEFAULT 100,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NULL,
  UNIQUE KEY uq_role_tenant_slug (tenant_id, slug),
  INDEX idx_role_tenant (tenant_id),
  CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_capabilities (
  id          CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)    NOT NULL,
  role_id     CHAR(36)    NOT NULL,
  capability  VARCHAR(40) NOT NULL,               -- e.g. 'records.write'
  created_at  DATETIME    NOT NULL,
  UNIQUE KEY uq_rolecap (role_id, capability),
  INDEX idx_rolecap_tenant (tenant_id),
  CONSTRAINT fk_rolecap_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rolecap_role   FOREIGN KEY (role_id)   REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users.role can now hold a custom role slug, so the hardcoded whitelist CHECK from v1.45
-- no longer fits. Role validity is enforced by the roles table (a slug must exist for the
-- tenant); dropping the CHECK is what lets custom roles be assigned to users.
ALTER TABLE users DROP CONSTRAINT chk_user_role;
