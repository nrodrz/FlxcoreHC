-- 124_healthcare_only.sql — v1.170.7: FlxCoreHC is healthcare-only.
-- 1) Healthcare entity profile columns on tenants (replaces the insurance "agency licence" / "entity NPN").
-- 2) Data clean-up for installs that pre-date the healthcare edition (all idempotent; no-ops on a fresh install).
-- Never put a bare SELECT in a migration (see 122).

ALTER TABLE tenants ADD COLUMN group_npi        VARCHAR(10)  NULL;
ALTER TABLE tenants ADD COLUMN taxonomy_code    VARCHAR(20)  NULL;
ALTER TABLE tenants ADD COLUMN medicare_ptan    VARCHAR(40)  NULL;
ALTER TABLE tenants ADD COLUMN medicaid_id      VARCHAR(40)  NULL;
ALTER TABLE tenants ADD COLUMN clia_number      VARCHAR(20)  NULL;
ALTER TABLE tenants ADD COLUMN facility_license VARCHAR(60)  NULL;
ALTER TABLE tenants ADD COLUMN accreditation    VARCHAR(120) NULL;

-- Carry a licence number already typed into the old "Agency licence number" field.
UPDATE tenants SET facility_license = LEFT(agency_license, 60)
 WHERE facility_license IS NULL AND agency_license IS NOT NULL AND agency_license <> '';

-- Every organization is healthcare. Retired insurance-era org shapes map to their healthcare equivalents.
UPDATE tenants SET vertical = 'healthcare' WHERE vertical IN ('insurance', 'custom');
UPDATE tenants SET org_shape = 'billing_company' WHERE org_shape IN ('agency', 'fmo');
UPDATE tenants SET org_shape = 'solo_practice'   WHERE org_shape = 'solo_agent';
UPDATE tenants SET org_shape = 'medical_group'   WHERE org_shape = 'custom';
UPDATE credential_holders SET vertical = 'healthcare' WHERE vertical <> 'healthcare';

-- Portal role display name (slug 'producer' is kept: it is stored on users.role).
UPDATE roles SET name = 'Provider (self-service)' WHERE slug = 'producer' AND name = 'Producer (self-service)';
UPDATE roles SET description = REPLACE(description, 'producer', 'provider') WHERE slug = 'producer' AND description LIKE '%producer%';

-- Plan display names (codes unchanged — they are referenced by plan_tier and billing).
UPDATE plans SET name = 'Solo practice'  WHERE code = 'solo'        AND name = 'Solo';
UPDATE plans SET name = 'Group practice' WHERE code = 'small_group' AND name = 'Small_group';
UPDATE plans SET name = 'Organization'   WHERE code = 'agency'      AND name = 'Agency';

-- Tenant-defined document types saved with the old default applies_to='agent' matched no healthcare
-- provider, so they were never required. Apply them to every healthcare provider type.
-- The full healthcare list is 114 characters; the column was VARCHAR(80) (020), so widen it first —
-- under STRICT mode the UPDATE (and adding a custom type) would otherwise fail with error 1406.
ALTER TABLE credential_catalog MODIFY COLUMN applies_to VARCHAR(255) NOT NULL DEFAULT 'physician';
UPDATE credential_catalog
   SET applies_to = 'physician,np,pa,nurse,dentist,pharmacist,therapist,behavioral,chiropractor,technologist,other_pro,lab,clinic,group'
 WHERE tenant_id IS NOT NULL AND is_custom = 1 AND applies_to = 'agent';

-- Future training articles default to healthcare (was 'insurance', which silently hid them).
ALTER TABLE training_library ALTER COLUMN vertical SET DEFAULT 'healthcare';

-- Insurance requirement templates seeded by 035/037 into tenants that existed at the time.
UPDATE credential_holders SET template_id = NULL
 WHERE template_id IN (SELECT id FROM (SELECT id FROM requirement_templates WHERE is_seed = 1 AND name IN (
   'FL Medicare Agent (2-15)', 'FL Medicare Agent (2-40 Health only)', 'FL Commercial Producer (2-20 General Lines)',
   'FL Personal Lines Producer (20-44)', 'FL Customer Representative (4-40)', 'Dual-line Producer (Medicare + P&C)',
   'Flood (NFIP) add-on', 'Agency / Entity', 'FMO / Upline')) AS x);
DELETE FROM requirement_template_items
 WHERE template_id IN (SELECT id FROM (SELECT id FROM requirement_templates WHERE is_seed = 1 AND name IN (
   'FL Medicare Agent (2-15)', 'FL Medicare Agent (2-40 Health only)', 'FL Commercial Producer (2-20 General Lines)',
   'FL Personal Lines Producer (20-44)', 'FL Customer Representative (4-40)', 'Dual-line Producer (Medicare + P&C)',
   'Flood (NFIP) add-on', 'Agency / Entity', 'FMO / Upline')) AS x);
DELETE FROM requirement_templates WHERE is_seed = 1 AND name IN (
   'FL Medicare Agent (2-15)', 'FL Medicare Agent (2-40 Health only)', 'FL Commercial Producer (2-20 General Lines)',
   'FL Personal Lines Producer (20-44)', 'FL Customer Representative (4-40)', 'Dual-line Producer (Medicare + P&C)',
   'Flood (NFIP) add-on', 'Agency / Entity', 'FMO / Upline');

-- Calendar: the insurance "Client call" event type is retired.
UPDATE calendar_events SET kind = 'appointment' WHERE kind = 'client_call';
