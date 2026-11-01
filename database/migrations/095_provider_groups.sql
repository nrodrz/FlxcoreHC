-- 095_provider_groups.sql
-- Intermediate grouping layer INSIDE a tenant: "groups". One entity that different org types read
-- differently — a billing company's "client groups" (the medical groups it serves), a hospital's
-- "departments", a large practice's "divisions". This is the structure that lets a billing company
-- say "these providers belong to Grupo Médico A, those to Grupo B, and these are independent," and a
-- hospital manage providers by department.
--
-- Stays entirely within one tenant — this is NOT the cross-tenant multi-org problem. Many-to-many
-- membership: a physician can cover several groups, a nurse can work for several. Purely additive.

CREATE TABLE IF NOT EXISTS provider_groups (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  name        VARCHAR(160) NOT NULL,
  npi         CHAR(10)     NULL,               -- optional Type-2 (organizational) NPI of the group
  code        VARCHAR(40)  NULL,               -- optional internal/client code
  status      VARCHAR(12)  NOT NULL DEFAULT 'active',  -- active | inactive
  note        VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL,
  deleted_at  DATETIME     NULL,
  KEY idx_pgroups_tenant (tenant_id, status),
  KEY idx_pgroups_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which providers belong to which groups (many-to-many within the tenant).
CREATE TABLE IF NOT EXISTS holder_groups (
  id           CHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)  NOT NULL,
  holder_id    CHAR(36)  NOT NULL,
  group_id     CHAR(36)  NOT NULL,
  role_in_group VARCHAR(120) NULL,             -- e.g. "Medical director", "Staff nurse"
  is_primary   TINYINT(1) NOT NULL DEFAULT 0,  -- the provider's primary group affiliation
  joined_at    DATETIME  NOT NULL,
  added_by     CHAR(36)  NULL,
  UNIQUE KEY uq_holder_group (tenant_id, holder_id, group_id),
  KEY idx_hg_holder (tenant_id, holder_id),
  KEY idx_hg_group (tenant_id, group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
