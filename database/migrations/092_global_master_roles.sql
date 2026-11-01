-- 092_global_master_roles.sql
-- Global "master" roles: role definitions owned by the platform (tenant_id = NULL), edited only by the
-- superadmin and applied to every tenant. Tenants see them read-only and may still create their own
-- roles. Role resolution is tenant-local-first, then global, so a tenant can shadow a global slug with
-- its own row if ever needed, but cannot edit the global definition.
--
-- Makes tenant_id nullable on roles + role_capabilities, flags global rows, and seeds the master set:
-- Master Admin (full app), Admin, Manager, Regular User, Viewer, plus the portal-only Provider role.
-- Existing per-tenant seeded copies are left in place (tenant-local wins), so nothing changes for
-- current tenants until they remove their local copy; new tenants rely on the global set.

ALTER TABLE roles            MODIFY tenant_id CHAR(36) NULL;
ALTER TABLE role_capabilities MODIFY tenant_id CHAR(36) NULL;
ALTER TABLE roles ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER is_system;

-- Seed globals idempotently. Capabilities are inserted by a follow-up seeding pass in RoleService
-- (RoleService::seedGlobals) invoked from install; here we just guarantee the role rows exist so the
-- app has a master set even before that runs.
INSERT INTO roles (id, tenant_id, slug, name, is_system, is_global, portal_only, sort_order, created_at)
SELECT * FROM (
  SELECT 'g-master-admin' AS id, NULL AS tenant_id, 'master_admin' AS slug, 'Master Admin' AS name, 1 AS is_system, 1 AS is_global, 0 AS portal_only, 5 AS sort_order, UTC_TIMESTAMP() AS created_at
  UNION ALL SELECT 'g-admin',   NULL, 'admin',   'Admin',        1,1,0,10, UTC_TIMESTAMP()
  UNION ALL SELECT 'g-manager', NULL, 'manager', 'Manager',      1,1,0,20, UTC_TIMESTAMP()
  UNION ALL SELECT 'g-regular', NULL, 'regular', 'Regular User', 1,1,0,30, UTC_TIMESTAMP()
  UNION ALL SELECT 'g-viewer',  NULL, 'viewer',  'Viewer',       1,1,0,40, UTC_TIMESTAMP()
  UNION ALL SELECT 'g-provider',NULL, 'provider','Provider (self-service)', 1,1,1,50, UTC_TIMESTAMP()
) seed
WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.tenant_id IS NULL AND r.slug = seed.slug);

-- Seed global role capabilities (tenant_id = NULL). Idempotent via INSERT IGNORE on a stable id.
-- Master Admin intentionally gets NO explicit rows here — capabilitiesForSlug() always returns the
-- full capability set for master_admin, so it can never be under-provisioned by data.

-- Admin
INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at)
SELECT CONCAT('gc-admin-', c.cap), NULL, r.id, c.cap, UTC_TIMESTAMP()
FROM roles r JOIN (
  SELECT 'org.manage' cap UNION ALL SELECT 'team.manage' UNION ALL SELECT 'billing.view'
  UNION ALL SELECT 'records.write' UNION ALL SELECT 'records.delete' UNION ALL SELECT 'documents.manage'
  UNION ALL SELECT 'documents.view' UNION ALL SELECT 'share.manage' UNION ALL SELECT 'audit.view' UNION ALL SELECT 'npi.lookup'
) c WHERE r.tenant_id IS NULL AND r.slug='admin';

-- Manager
INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at)
SELECT CONCAT('gc-manager-', c.cap), NULL, r.id, c.cap, UTC_TIMESTAMP()
FROM roles r JOIN (
  SELECT 'team.manage' cap UNION ALL SELECT 'records.write' UNION ALL SELECT 'records.delete'
  UNION ALL SELECT 'documents.manage' UNION ALL SELECT 'documents.view' UNION ALL SELECT 'share.manage'
  UNION ALL SELECT 'audit.view' UNION ALL SELECT 'npi.lookup' UNION ALL SELECT 'billing.view'
) c WHERE r.tenant_id IS NULL AND r.slug='manager';

-- Regular User
INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at)
SELECT CONCAT('gc-regular-', c.cap), NULL, r.id, c.cap, UTC_TIMESTAMP()
FROM roles r JOIN (
  SELECT 'records.write' cap UNION ALL SELECT 'documents.manage' UNION ALL SELECT 'documents.view' UNION ALL SELECT 'npi.lookup'
) c WHERE r.tenant_id IS NULL AND r.slug='regular';

-- Viewer
INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at)
SELECT CONCAT('gc-viewer-', c.cap), NULL, r.id, c.cap, UTC_TIMESTAMP()
FROM roles r JOIN (SELECT 'documents.view' cap UNION ALL SELECT 'audit.view') c
WHERE r.tenant_id IS NULL AND r.slug='viewer';

-- Provider (self-service portal)
INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at)
SELECT 'gc-provider-documents.view', NULL, r.id, 'documents.view', UTC_TIMESTAMP()
FROM roles r WHERE r.tenant_id IS NULL AND r.slug='provider';
