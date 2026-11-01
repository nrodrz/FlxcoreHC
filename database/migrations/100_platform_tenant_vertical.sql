-- 100_platform_tenant_vertical.sql
-- Repair for installs created before the platform operator's own tenant adopted the edition vertical.
-- The SaaS top-level account was created with vertical='platform', which hid every healthcare-gated
-- feature (providers, groups, locations, templates, calendar, notifications) from the operator's own
-- workspace. Move it to the edition vertical so the operator can use the product they sell. The
-- org_shape stays 'platform', so console powers (driven by the platform_admin flag) are unaffected.
-- Healthcare Edition only; harmless if already healthcare.

UPDATE tenants SET vertical = 'healthcare'
 WHERE org_shape = 'platform' AND vertical = 'platform';

-- Any provider record seeded under the platform tenant should match.
UPDATE credential_holders h
   JOIN tenants t ON t.id = h.tenant_id
   SET h.vertical = 'healthcare'
 WHERE t.org_shape = 'platform' AND h.vertical = 'platform';
