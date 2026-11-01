-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 123_nonclinical_catalog_fix.sql
-- Fix for migration 122 (Finding B): 122 omitted the `id` column and used a wrong column list, so under
-- INSERT IGNORE only the first row seeded (with id='') and the other 8 collided on the empty PK and were
-- dropped. This migration (a) removes any broken empty-id row 122 left behind, then (b) seeds all 9
-- non-clinical catalog types idempotently with the per-row INSERT ... SELECT UUID() ... WHERE NOT EXISTS
-- pattern (matching 068_healthcare_catalog.sql), which correctly dedupes global tenant_id IS NULL rows.
-- No schema change. Safe to run whether or not 122 ran.

-- (a) clean up the broken row 122 may have created
DELETE FROM credential_catalog WHERE tenant_id IS NULL AND id = '';

-- (b) idempotent per-row seed (columns match 068: id, code, name, category, authority, vertical,
--     applies_to, renewal_months, is_required, state, notes, sort)
INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'biz_license', 'Business License', 'license', 'state', 'healthcare', 'organization', 12, 1, NULL,
  'Municipal/state business or operating license for the organization.', 300
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'biz_license');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'gen_liability', 'General Liability Insurance', 'insurance', 'private', 'healthcare', 'organization', 12, 1, NULL,
  'Organization general liability coverage certificate.', 301
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'gen_liability');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'workers_comp', 'Workers Compensation Insurance', 'insurance', 'state', 'healthcare', 'organization', 12, 1, NULL,
  'Workers compensation coverage (where staff are employed).', 302
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'workers_comp');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'w9', 'W-9 (Taxpayer ID)', 'identifier', 'irs', 'healthcare', 'organization', NULL, 1, NULL,
  'Current IRS Form W-9 for the organization.', 303
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'w9');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'org_npi', 'Organization NPI (Type 2)', 'identifier', 'nppes', 'healthcare', 'organization', NULL, 1, NULL,
  'Entity-level (Type 2) National Provider Identifier.', 304
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'org_npi');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'hipaa_training', 'HIPAA Training', 'training', 'hhs', 'healthcare', 'staff', 12, 1, NULL,
  'Annual HIPAA privacy/security training for staff.', 310
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'hipaa_training');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'osha_training', 'OSHA / Workplace Safety Training', 'training', 'osha', 'healthcare', 'staff', 12, 0, NULL,
  'OSHA/bloodborne-pathogen or general workplace safety training.', 311
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'osha_training');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'bls_cpr', 'BLS / CPR Certification', 'certification', 'aha', 'healthcare', 'staff', 24, 0, NULL,
  'Basic Life Support / CPR certification (staff, where applicable).', 320
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'bls_cpr');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'background_check', 'Background Check', 'background', 'private', 'healthcare', 'staff', 24, 0, NULL,
  'Staff background screening on file.', 321
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'background_check');
