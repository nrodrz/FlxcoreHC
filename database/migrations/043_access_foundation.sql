-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Access foundation (v1.45): the read_only role and the tenant lifecycle.
--
-- Two independent axes from here on:
--   * platform_admin (users.platform_admin) — Indisse-BTS staff only. Grants the SaaS
--     console. Never granted by signup; verified against the database on every request
--     so a revoked flag dies immediately instead of living in a stale session.
--   * tenant role (users.role) — what a person may do INSIDE their organization.
--     New role read_only: sees everything in their tenant, mutates nothing. Also the
--     effective role for every user of a tenant that is not yet approved.
--
-- Tenant lifecycle: pending -> active -> suspended / rejected (closed kept for legacy).
--   pending    signed up, not yet approved — all users effectively read_only
--   active     normal operation
--   suspended  sign-in blocked with a message; sessions ended on next request
--   rejected   sign-in blocked; data retained for the grace window

-- 1. users: admit the read_only role.
ALTER TABLE users DROP CONSTRAINT chk_user_role;
ALTER TABLE users
  ADD CONSTRAINT chk_user_role CHECK (role IN ('tenant_owner','manager','staff','provider_client','agent','producer','read_only'));

-- 2. tenants: admit the lifecycle states.
ALTER TABLE tenants DROP CONSTRAINT chk_tenant_status;
ALTER TABLE tenants
  ADD CONSTRAINT chk_tenant_status CHECK (status IN ('pending','active','suspended','rejected','closed'));

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS status_reason VARCHAR(200) NULL;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS approved_at  DATETIME NULL;

-- Existing active tenants are considered approved as of their creation.
UPDATE tenants SET approved_at = created_at WHERE approved_at IS NULL AND status = 'active';
