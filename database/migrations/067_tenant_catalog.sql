-- Tenant-extensible credential catalog.
--
-- Until now the catalog was global and migration-seeded: only a code release could add a credential
-- type. A healthcare billing company tracking documents for many provider types must be able to add
-- their own types ("X-ray Supervisor Cert") without waiting on a release. This makes the catalog
-- two-tier: seeded rows stay global (tenant_id NULL, visible to everyone); custom rows belong to one
-- tenant and are visible only to it.
--
-- The primary key moves from `code` to a surrogate `id` with UNIQUE (tenant_id, code), so two
-- tenants can each define the same custom code without colliding. MariaDB treats NULLs as distinct
-- in unique keys, which is fine: global codes are kept unique by the seeding discipline (INSERT
-- IGNORE + release review), and custom codes are validated in the service against BOTH tiers, so a
-- custom code can never shadow a global one.
ALTER TABLE credential_catalog
  ADD COLUMN id CHAR(36) NULL AFTER code,
  ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
  ADD COLUMN is_custom TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN retired_at DATETIME NULL,
  ADD COLUMN created_by CHAR(36) NULL,
  ADD COLUMN created_at DATETIME NULL;

-- Give every existing (seeded) row a surrogate id.
UPDATE credential_catalog SET id = UUID() WHERE id IS NULL;

-- Re-key: surrogate PK, uniqueness per (tenant, code).
ALTER TABLE credential_catalog
  DROP PRIMARY KEY,
  MODIFY COLUMN id CHAR(36) NOT NULL,
  ADD PRIMARY KEY (id),
  ADD UNIQUE KEY uq_catalog_tenant_code (tenant_id, code),
  ADD INDEX idx_catalog_tenant (tenant_id, sort);
