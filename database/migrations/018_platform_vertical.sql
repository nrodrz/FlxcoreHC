-- Allow the 'platform' vertical for the SaaS operator (master admin) organization.
-- Safe to run on existing installs (widens the CHECK constraint).
ALTER TABLE tenants DROP CONSTRAINT chk_tenant_vertical;
ALTER TABLE tenants ADD CONSTRAINT chk_tenant_vertical CHECK (vertical IN ('healthcare','insurance','custom','platform'));
