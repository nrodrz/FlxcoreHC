-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Puerto Rico healthcare jurisdiction content (healthcare Phase 4).
--
-- Providers in Puerto Rico carry the same FEDERAL credentials as mainland providers (NPI, DEA, CLIA,
-- Medicare enrollment) — those global healthcare rows already apply, unchanged. This migration adds
-- the PR-SPECIFIC credentials, tagged state='PR', so they surface only for organizations operating
-- in Puerto Rico. Same mechanism as the Georgia insurance content: a jurisdiction value plus verified,
-- cited entries.
--
-- Sources (verified):
--  * PR Board of Medical Licensure and Discipline (PR BOM) — triennial recertification on continuing
--    education (20 LPRA §135e; PR BOM CME: 60 hours / 3 years, incl. 10 hrs disease prevention and
--    6 hrs bioethics & professionalism).
--  * Certificate of Good Conduct (Policía de Puerto Rico) — required credentialing input; for some
--    processes must be dated within 30 days.
--  * Negative controlled-substances detection certificate from a PR Dept. of Health-licensed lab
--    (per N.I.D.A. guidelines) — cited in PR licensure statutes (20 LPRA §544i).
--  * PR laboratory personnel licensure — PR Department of Health licenses lab personnel (one of the
--    jurisdictions with lab-personnel licensing, in addition to federal CLIA).

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'pr_medical_license', 'PR Medical License (PR BOM)', 'license', 'state', 'healthcare', 'physician,nurse', 36, 1, 'PR',
  'License to practice issued by the Puerto Rico Board of Medical Licensure and Discipline (PR BOM). Recertified triennially on continuing education. Distinct from any mainland state license; required to treat patients located in Puerto Rico.', 400
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'pr_medical_license');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'pr_cme', 'PR Continuing Education (Triennial)', 'training', 'state', 'healthcare', 'physician,nurse', 36, 0, 'PR',
  'PR BOM requires 60 continuing-education hours per 3-year recertification cycle, including 10 hours in disease prevention/health promotion and 6 hours in bioethics and professionalism. Track completion against the license cycle.', 405
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'pr_cme');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'pr_good_conduct', 'Certificate of Good Conduct (PR Police)', 'background', 'state', 'healthcare', 'physician,nurse', NULL, 0, 'PR',
  'Certificate of good conduct issued by the Puerto Rico Police, used as a credentialing/background input. Some processes require it be dated within 30 days of submission, so treat it as periodically refreshed rather than one-time.', 410
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'pr_good_conduct');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'pr_cds_negative', 'Negative Controlled-Substances Certificate (PR)', 'background', 'state', 'healthcare', 'physician,nurse', NULL, 0, 'PR',
  'Negative controlled-substances detection certificate from a laboratory licensed by the PR Department of Health under N.I.D.A. guidelines, required in PR professional licensure. Track its issue date.', 415
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'pr_cds_negative');

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'pr_lab_personnel_license', 'PR Laboratory Personnel License', 'license', 'state', 'healthcare', 'lab', 12, 0, 'PR',
  'Puerto Rico licenses clinical-laboratory personnel through the PR Department of Health, in addition to the facility''s federal CLIA certificate. Applies to lab staff practicing in PR.', 420
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'pr_lab_personnel_license');
