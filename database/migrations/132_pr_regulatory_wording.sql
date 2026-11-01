-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 132_pr_regulatory_wording.sql (v1.171.4) — Puerto Rico regulatory wording, idempotent.
--  * The nursing board is named "Junta Examinadora de Enfermería" on the Puerto Rico Department of Health site
--    (salud.pr.gov/CMS/126); rename the seeded "…Enfermeras y Enfermeros" rows (new tenants get the new name from 120/126 directly).
--  * CME breakdown (10 h prevention / 6 h bioethics) and the negative controlled-substances certificate were NOT confirmed
--    against a primary source; the catalog notes now say so instead of stating them as universal requirements.

UPDATE regulators r SET r.name = 'Junta Examinadora de Enfermería'
WHERE r.name = 'Junta Examinadora de Enfermeras y Enfermeros'
  AND NOT EXISTS (SELECT 1 FROM (SELECT tenant_id, name FROM regulators) x WHERE x.tenant_id = r.tenant_id AND x.name = 'Junta Examinadora de Enfermería');

UPDATE requirement_sources SET authority = REPLACE(authority, 'Junta Examinadora de Enfermeras y Enfermeros', 'Junta Examinadora de Enfermería'),
  summary = REPLACE(summary, 'Junta Examinadora de Enfermeras y Enfermeros', 'Junta Examinadora de Enfermería')
WHERE authority LIKE '%Enfermeras y Enfermeros%' OR summary LIKE '%Enfermeras y Enfermeros%';

UPDATE credential_catalog SET name = REPLACE(name, 'Junta Examinadora de Enfermeras y Enfermeros', 'Junta Examinadora de Enfermería'),
  notes = REPLACE(notes, 'Junta Examinadora de Enfermeras y Enfermeros', 'Junta Examinadora de Enfermería')
WHERE name LIKE '%Enfermeras y Enfermeros%' OR notes LIKE '%Enfermeras y Enfermeros%';

UPDATE regulator_requirements SET notes = REPLACE(notes, 'Junta Examinadora de Enfermeras y Enfermeros', 'Junta Examinadora de Enfermería')
WHERE notes LIKE '%Enfermeras y Enfermeros%';

UPDATE credential_catalog SET
  notes = 'PR physicians recertify every three years through the JLDM and must complete continuing education, including bioethics and professionalism hours (Ley 139-2008). Total hours and topic breakdown are set by JLDM regulation and are UNVERIFIED here (about 60 h per cycle is commonly cited) - confirm the current requirement with the Junta. Track against the 36-month cycle.'
WHERE tenant_id IS NULL AND code = 'pr_cme';

UPDATE credential_catalog SET
  notes = 'Some PR licensure filings have asked for a negative controlled-substances (drug-test) certificate. Whether it applies to a provider and license class, and its wording, are UNVERIFIED - confirm with the licensing board before requiring it. A DEA or state controlled-substance registration is a different document and does not satisfy it.'
WHERE tenant_id IS NULL AND code = 'pr_cds_negative';
