-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 112_requirement_source_links.sql
-- Level 1 of regulatory grounding: link each credential/requirement type to the dated legal source
-- that justifies it, so a provider page can answer "why is this required?" inline. The source table
-- (requirement_sources, migration 110) is the authority; here we just add the join key on the catalog.
ALTER TABLE credential_catalog ADD COLUMN source_code VARCHAR(40) NULL AFTER notes;

-- Tag the PR/federal healthcare catalog items with their verified sources.
UPDATE credential_catalog SET source_code = 'license_recert' WHERE code IN ('state_medical_license','license','state_license','professional_license');
UPDATE credential_catalog SET source_code = 'good_standing'  WHERE code = 'good_standing';
UPDATE credential_catalog SET source_code = 'dea'            WHERE code = 'dea';
UPDATE credential_catalog SET source_code = 'ce_recert'      WHERE code IN ('cme','ce','continuing_education');
UPDATE credential_catalog SET source_code = 'oig_leie'       WHERE code IN ('oig','leie','exclusion');

-- Corrected & added regulatory sources (verified against PR government sources, September 2026).
-- CE was broadly suspended by OA 542 (2022) then PARTIALLY RESTORED by OA 544 (LGBTTQ+ cultural
-- competence); the boards still run 3-year CE-based recertification with hours set per trienio.
-- CE is NOT waived — this corrects an earlier oversimplification.
INSERT IGNORE INTO requirement_sources (id, code, authority, regulation, url, effective_date, superseded_date, last_reviewed, verified_by, summary, created_at) VALUES
 (UUID(),'ce_recert','PR Departamento de Salud — ORCPS / Juntas Examinadoras','Recertificación por educación continua (Ley 11-1976; p.ej. Reglamento 9104 Enfermería); OA 542-2022 y OA 544','https://www.salud.pr.gov/CMS/261',NULL,NULL,'2026-09-06','FlxCoreHC','Licenses recertify every 3 years based on continuing education, with hours set per trienio by each board. OA 542 (2022) broadly suspended CE; OA 544 restored CE on LGBTTQ+ cultural competence. CE is required — do not treat it as waived.',UTC_TIMESTAMP()),
 (UUID(),'universal_recognition','PR Departamento de Salud / Asamblea Legislativa','Ley 102-2025 — Reconocimiento Universal de Licencias; Reglamento (en evolución, revisión JSF pendiente)','https://www.lexjuris.com/lexlex/Leyes2025/lexl2025102.htm','2025-08-01',NULL,'2026-09-06','FlxCoreHC','A provider holding a valid U.S.-jurisdiction license (held >=1 yr, practiced >=3 consecutive yrs) may obtain an equivalent PR license by recognition; provisional licenses issued while completing PR requirements. Lawyers excluded. Implementing regulation still evolving — treat as an intake pathway, not a settled rule.',UTC_TIMESTAMP());

-- Amend the earlier license_recert summary to point CE detail at ce_recert (keeps the package note).
UPDATE requirement_sources
   SET summary = 'Permanent licenses are recertified every three years. Package: continuing-education evidence (see ce_recert), PR criminal background certificate (Buena Conducta), ASUME child-support negative certificate, board-specific documents.',
       last_reviewed = '2026-09-06'
 WHERE code = 'license_recert';
