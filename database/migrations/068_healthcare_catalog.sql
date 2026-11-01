-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Healthcare national starter catalog + provider type on holders (healthcare Phase 2).
--
-- Seeds the standard, verified credentialing document set a US provider tracks, as GLOBAL rows
-- (tenant_id NULL) so every healthcare organization starts with the standards rather than a blank
-- list. These are documents to TRACK and MANAGE — not applications to submit; FlxCoreHC deliberately
-- does not integrate PECOS/CAQH submission.
--
-- applies_to for healthcare uses provider-type tokens (physician,nurse,lab,clinic,group) rather than
-- the insurance tokens (agent,agency,fmo). The two verticals never share holders, so the token
-- vocabulary can differ per vertical without collision.
--
-- Sources (verified): DEA Diversion Control Division (36-month practitioner cycle; NPI is lifetime);
-- CMS CLIA program (42 CFR Part 493; PR included as a "State"); CAQH ProView (re-attestation
-- ~120 days); standard credentialing checklists for board certification, malpractice, CME.

-- 1) Provider type on the holder (defaults preserve existing rows).
ALTER TABLE credential_holders
  ADD COLUMN provider_type VARCHAR(20) NULL AFTER holder_type;

-- 1b) Tag catalog rows by vertical so a tenant sees only credential types for its own vertical.
--     Existing seeded rows are insurance; the healthcare rows below set 'healthcare'. Custom rows
--     added by a tenant are matched by tenant_id, so they need no vertical tag.
ALTER TABLE credential_catalog ADD COLUMN vertical VARCHAR(20) NULL AFTER authority;
UPDATE credential_catalog SET vertical = 'insurance' WHERE tenant_id IS NULL AND vertical IS NULL;

-- 2) National healthcare catalog (global rows). sort 300+ keeps them clear of the insurance block.
-- Idempotent per-row insert: the (tenant_id, code) unique key can't dedupe rows where
-- tenant_id IS NULL (SQL treats NULLs as distinct), so each global row is guarded by an
-- explicit NOT EXISTS on its code. Safe to re-run.
INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'npi', 'National Provider Identifier (NPI)', 'identifier', 'federal', 'healthcare', 'physician,nurse,lab,clinic,group', NULL, 1, NULL,
   'Lifetime 10-digit identifier from NPPES; does not expire. Individuals hold a Type 1 NPI; organizations (groups, clinics, labs) hold a Type 2. Verified but not renewed.', 300
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'npi');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'state_medical_license', 'State Professional License', 'license', 'state', 'healthcare', 'physician,nurse', 24, 1, NULL,
   'The provider''s license to practice, issued by the state (or territory) board. Renewal cadence and CE tie to the issuing board; commonly every 1-2 years. Jurisdiction-specific — one per state where the provider practices.', 305
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'state_medical_license');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'dea', 'DEA Registration', 'license', 'federal', 'healthcare', 'physician,nurse', 36, 0, NULL,
   'Federal authorization to prescribe controlled substances (DEA Diversion Control Division). Standard practitioner cycle is 36 months; researchers/labs renew annually. State-specific and location-specific — a separate registration per state and principal place of business. Only for prescribers.', 310
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'dea');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'state_cds', 'State Controlled-Substance Registration', 'license', 'state', 'healthcare', 'physician,nurse', 24, 0, NULL,
   'Where a state requires its own controlled-substance registration separate from the federal DEA. Not all states have one (e.g., California does not). Prescribers only.', 315
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'state_cds');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'board_cert', 'Board Certification', 'certification', 'federal', 'healthcare', 'physician,nurse', NULL, 0, NULL,
   'Specialty board certification with recertification/Maintenance of Certification (MOC) obligations. Cadence is set by the certifying board (traditionally ~10 years, increasingly continuous MOC), so renewal is tracked by the certificate''s own expiry rather than a fixed interval.', 320
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'board_cert');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'malpractice', 'Malpractice / Professional Liability Insurance', 'insurance', 'federal', 'healthcare', 'physician,nurse,clinic,group', 12, 1, NULL,
   'Current professional liability coverage — track the declaration page and policy period. Typically renews annually. Payers and facilities require evidence of active coverage and, often, claims history.', 325
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'malpractice');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'caqh', 'CAQH ProView Attestation', 'certification', 'ncqa', 'healthcare', 'physician,nurse', 4, 0, NULL,
   'The provider''s CAQH ProView profile must be re-attested roughly every 120 days for payers to keep pulling current data. FlxCoreHC tracks the attestation date; it does not perform the attestation.', 330
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'caqh');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'cme', 'Continuing Medical Education (CME/CE)', 'training', 'state', 'healthcare', 'physician,nurse', 24, 0, NULL,
   'Continuing education hours required by the state board and/or specialty board each license cycle. Track completion against the applicable cycle.', 335
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'cme');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'gov_id', 'Government-Issued ID', 'identifier', 'federal', 'healthcare', 'physician,nurse', NULL, 0, NULL,
   'Copy of a current government-issued photo ID retained in the credentialing file.', 340
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'gov_id');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'cv', 'Curriculum Vitae / Work History', 'contract', 'federal', 'healthcare', 'physician,nurse', NULL, 0, NULL,
   'Current CV with a continuous work history (gaps explained). Kept current for re-credentialing.', 345
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'cv');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'npi_type2', 'Organizational NPI (Type 2)', 'identifier', 'federal', 'healthcare', 'lab,clinic,group', NULL, 1, NULL,
   'The organization''s own Type 2 NPI, distinct from any individual provider''s Type 1. Lifetime identifier; does not expire.', 350
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'npi_type2');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'ein', 'Tax ID / EIN (CP-575)', 'identifier', 'federal', 'healthcare', 'lab,clinic,group', NULL, 1, NULL,
   'Employer Identification Number and the IRS CP-575 confirmation letter establishing the entity for billing.', 355
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'ein');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'business_license', 'Business License', 'license', 'state', 'healthcare', 'lab,clinic,group', 12, 0, NULL,
   'State/local business license for the practice entity, where required. Renewal cadence is jurisdiction-specific.', 360
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'business_license');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'group_malpractice', 'Group Malpractice Coverage', 'insurance', 'federal', 'healthcare', 'lab,clinic,group', 12, 0, NULL,
   'Entity-level professional liability coverage for the group or facility. Track the declaration page and policy period.', 365
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'group_malpractice');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'medicare_ptan', 'Medicare PTAN', 'identifier', 'cms', 'healthcare', 'lab,clinic,group', NULL, 0, NULL,
   'Provider Transaction Access Number issued by Medicare, linking the provider/group for billing. Tracked as an identifier, not renewed.', 370
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'medicare_ptan');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'clia', 'CLIA Certificate', 'certification', 'cms', 'healthcare', 'lab', 24, 1, NULL,
   'Clinical Laboratory Improvement Amendments certificate (42 CFR Part 493) — required for any facility testing human specimens. Certificate type (waiver, PPM, compliance, accreditation) sets scope; certificates run on a 2-year cycle. CLIA law names Puerto Rico explicitly as a covered jurisdiction.', 375
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'clia');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'state_lab_license', 'State Laboratory License', 'license', 'state', 'healthcare', 'lab', 12, 0, NULL,
   'Some states license laboratories separately from (and in addition to) CLIA. Required only where the state has its own lab-licensing program.', 380
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'state_lab_license');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'lab_director_quals', 'Laboratory Director Qualifications', 'certification', 'cms', 'healthcare', 'lab', NULL, 0, NULL,
   'Evidence that the laboratory director meets CLIA education/experience requirements for the testing complexity performed (non-waived testing). Kept on file per 42 CFR 493 subpart M.', 385
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'lab_director_quals');
