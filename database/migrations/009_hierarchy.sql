-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FlxCoreHC — Tenant hierarchy (the FMO scale-up spine).
-- A tenant tree: FMO -> downline agencies -> agents, via self-referential parent.
-- CRITICAL: this does NOT change per-tenant isolation. TenantRepository stays
-- strictly single-tenant. Hierarchy adds ONE separate, read-only "oversight" lane
-- (OversightService) that lets a parent read AGGREGATES across its own subtree only.
SET NAMES utf8mb4;

ALTER TABLE tenants
  ADD COLUMN parent_tenant_id CHAR(36) NULL AFTER id,
  ADD COLUMN hierarchy_role   VARCHAR(20) NOT NULL DEFAULT 'standalone' AFTER plan_tier,
  ADD INDEX idx_tenant_parent (parent_tenant_id),
  ADD CONSTRAINT fk_tenant_parent FOREIGN KEY (parent_tenant_id) REFERENCES tenants(id),
  ADD CONSTRAINT chk_tenant_hier_role CHECK (hierarchy_role IN ('fmo','agency','agent','standalone'));
