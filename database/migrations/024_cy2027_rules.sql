-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- CY2027 CMS Final Rule (CMS-4208-F3, issued 2026-04-02) + PY2027 certification season.
-- Marketing/compliance provisions apply from 2026-10-01 (ahead of AEP for plan year 2027).
-- Idempotent: INSERT IGNORE for new items, UPDATEs for revised guidance.

-- NEW: NABIP Medicare certification — CMS-compliant alternative to AHIP (MMACR),
-- accepted by 60+ carriers for PY2027 but NOT universally (verify per carrier).
INSERT IGNORE INTO credential_catalog
  (code, name, category, authority, applies_to, renewal_months, is_required, state, notes, sort) VALUES
 ('nabip', 'NABIP Medicare Certification (MMACR)', 'certification', 'cms', 'agent', 12, 0, NULL,
  'Alternative to AHIP for annual Medicare/MA/compliance training. PY2027 opened 06/22/2026; accepted by 60+ carriers but not all — verify each carrier before relying on it.', 15);

-- NEW: Call recording & retention compliance (TPMO obligation).
INSERT IGNORE INTO credential_catalog
  (code, name, category, authority, applies_to, renewal_months, is_required, state, notes, sort) VALUES
 ('call_recording', 'Call Recording & Retention Compliance', 'training', 'cms', 'agent,agency,fmo', 12, 1, NULL,
  'Record marketing/sales calls with beneficiaries. CY2027 Final Rule cut retention for marketing/sales recordings to 6 years (from 10); enrollment records and enrollment-call portions remain 10 years. Applies 10/01/2026.', 155);

-- UPDATED guidance on existing items (CY2027 Final Rule + PY2027 season).
UPDATE credential_catalog SET notes =
  'Annual AHIP Medicare + FWA training; required before selling MA/PDP each plan year. PY2027 training opened 06/22/2026 (50 questions, 90% pass). Many carriers also accept NABIP as an alternative.'
  WHERE code = 'ahip';

UPDATE credential_catalog SET notes =
  'Per-carrier annual certification for the plan year. PY2027 openings: UnitedHealthcare June, Aetna/Devoted 06/23/2026, Humana 07/08/2026, Wellcare 07/21/2026. Complete AHIP/NABIP first.'
  WHERE code = 'carrier_cert';

UPDATE credential_catalog SET notes =
  'Attestation to CMS marketing rules per the CY2027 Final Rule (effective 10/01/2026): TPMO disclaimer before any plan-benefit discussion (SHIP reference removed); 48-hour SOA waiting period eliminated (SOA itself still required, e-signature valid); SOA collection now allowed at educational events; superlative language permitted if not misleading.'
  WHERE code = 'cms_marketing';

UPDATE credential_catalog SET notes =
  'Carrier RTS = AHIP or NABIP + carrier certification + active appointment + valid state license, all complete for the plan year. Confirm status in each carrier portal before marketing.'
  WHERE code = 'rts';
