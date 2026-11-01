-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 126_pr_seed_corrections.sql
-- Puerto Rico seed corrections from the independent v1.170.9 review (D-5). Fully idempotent; also applied
-- to new signups by AuthService::seedTemplatesFor.
--  * The physician licensing board is the Junta de Licenciamiento y Disciplina Médica (JLDM), not "PR Board of Medical Examiners".
--  * pr_medical_license / pr_cme applied to nurses; nurses are licensed by the Junta Examinadora de Enfermería.
--  * The negative controlled-substances certificate was attributed to two different agencies; it is described neutrally now.

UPDATE regulators r SET r.name = 'Junta de Licenciamiento y Disciplina Médica (JLDM)'
WHERE r.name = 'PR Board of Medical Examiners'
  AND NOT EXISTS (SELECT 1 FROM (SELECT tenant_id, name FROM regulators) x
                  WHERE x.tenant_id = r.tenant_id AND x.name = 'Junta de Licenciamiento y Disciplina Médica (JLDM)');

UPDATE credential_catalog SET name = 'PR Medical License (JLDM)', applies_to = 'physician',
  notes = 'License to practice issued by the Junta de Licenciamiento y Disciplina Médica de Puerto Rico (JLDM); permanent licenses are registered and recertified every three years through ORCPS. Distinct from any mainland state license; required to treat patients located in Puerto Rico. Nurses and other health professionals are licensed by their own boards.'
WHERE tenant_id IS NULL AND code = 'pr_medical_license';

UPDATE credential_catalog SET name = 'PR Continuing Education — physicians (triennial)', applies_to = 'physician',
  notes = 'JLDM recertification requires 60 continuing-education hours per 3-year cycle, including 10 hours in disease prevention/health promotion and 6 hours in bioethics and professionalism. Track completion against the license cycle (36-month window).'
WHERE tenant_id IS NULL AND code = 'pr_cme';

UPDATE credential_catalog SET name = 'Negative Controlled-Substances (Drug-Test) Certificate (PR)',
  notes = 'Negative controlled-substances detection certificate from a licensed laboratory, required with Puerto Rico licensure and recertification filings. Track its issue date. A DEA registration or a state controlled-substance registration is a different document and does not satisfy it.'
WHERE tenant_id IS NULL AND code = 'pr_cds_negative';

INSERT INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, state, notes, sort)
SELECT UUID(), 'pr_nursing_license', 'PR Nursing License', 'license', 'state', 'healthcare', 'nurse', 36, 1, 'PR',
  'License issued by the Junta Examinadora de Enfermería de Puerto Rico; permanent licenses are registered and recertified every three years through ORCPS based on continuing education. Confirm the continuing-education hours and topics required for the license class directly with the Board.', 401
WHERE NOT EXISTS (SELECT 1 FROM credential_catalog c WHERE c.tenant_id IS NULL AND c.code = 'pr_nursing_license');

UPDATE regulator_requirements SET name = 'Negative controlled-substances (drug-test) certificate'
WHERE catalog_code = 'pr_cds_negative' AND name = 'Negative controlled-substances certificate (ASSMCA)';

INSERT INTO regulator_requirements (id, tenant_id, regulator_id, kind, catalog_code, name, category, renewal_months, notes, created_at)
SELECT UUID(), r.tenant_id, r.id, 'credential', 'pr_nursing_license', 'PR nursing license', 'credential', 36,
       'Registration and triennial recertification through ORCPS.', UTC_TIMESTAMP()
FROM regulators r JOIN tenants t ON t.id = r.tenant_id
WHERE r.name = 'Junta Examinadora de Enfermería' AND t.vertical = 'healthcare'
  AND NOT EXISTS (SELECT 1 FROM regulator_requirements q WHERE q.regulator_id = r.id AND q.catalog_code = 'pr_nursing_license');
