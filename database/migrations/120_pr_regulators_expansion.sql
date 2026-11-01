-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 120_pr_regulators_expansion.sql
-- Puerto Rico regulator expansion, verified against PR government sources (September 2026).
-- Adds the profession-specific examining boards under ORCPS and the key oversight agencies, plus
-- source-cited entries for the boards that lacked one. Regulators are seeded per tenant via the
-- standard tenant cross-join (idempotent on short_name); sources are global.
--
-- Context (verified): ORCPS administers ~48 examining boards ("Juntas Examinadoras") that license and
-- oversee each health profession; all permanent licenses recertify every 3 years through ORCPS with
-- CE evidence + Buena Conducta + ASUME. JLDM handles physicians/PAs specifically. OCS runs the
-- centralized credentialing portal (SICRO, Ley 73-2023). ASES administers Medicaid/Plan Vital.
-- ASSMCA licenses and certifies mental-health & addiction service providers.

-- ---- Regulators (one row per tenant; skip if this tenant already has the short_name) ----
INSERT INTO regulators (id, tenant_id, name, category, jurisdiction, is_custom, short_name, status, created_at)
SELECT UUID(), t.id, x.name, x.category, 'PR', 0, x.short_name, 'active', UTC_TIMESTAMP()
FROM tenants t
JOIN (
  SELECT 'Oficina de Reglamentación y Certificación de los Profesionales de la Salud' AS name, 'licensing' AS category, 'ORCPS' AS short_name
  UNION ALL SELECT 'Oficina del Comisionado de Seguros de Puerto Rico', 'insurance', 'OCS'
  UNION ALL SELECT 'Administración de Seguros de Salud (Plan Vital)', 'medicaid', 'ASES'
  UNION ALL SELECT 'Administración de Servicios de Salud Mental y Contra la Adicción', 'behavioral_health', 'ASSMCA'
  UNION ALL SELECT 'Junta Examinadora de Enfermería', 'licensing', 'JE Enfermería'
  UNION ALL SELECT 'Junta de Farmacia de Puerto Rico', 'licensing', 'Junta Farmacia'
  UNION ALL SELECT 'Junta Dental Examinadora', 'licensing', 'Junta Dental'
  UNION ALL SELECT 'Junta Examinadora de Psicólogos', 'licensing', 'JE Psicólogos'
  UNION ALL SELECT 'Junta Examinadora de Consejeros Profesionales', 'licensing', 'JE Consejeros'
  UNION ALL SELECT 'Junta Examinadora de Tecnólogos Médicos', 'licensing', 'JE Tec. Médicos'
  UNION ALL SELECT 'Junta Examinadora de Terapia Física', 'licensing', 'JE Terapia Física'
  UNION ALL SELECT 'Junta Examinadora de Terapia Ocupacional', 'licensing', 'JE Terapia Ocup.'
  UNION ALL SELECT 'Junta Examinadora de Patología del Habla-Lenguaje, Audiología y Terapia del Habla', 'licensing', 'JE Habla-Lenguaje'
  UNION ALL SELECT 'Junta Examinadora de Nutricionistas y Dietistas', 'licensing', 'JE Nutrición'
  UNION ALL SELECT 'Junta Examinadora de Tecnólogos Radiológicos', 'licensing', 'JE Tec. Radiológicos'
  UNION ALL SELECT 'Junta Examinadora de Técnicos de Emergencias Médicas', 'licensing', 'JE TEM'
  UNION ALL SELECT 'Junta Examinadora de Médicos Asistentes', 'licensing', 'JE Méd. Asistentes'
  UNION ALL SELECT 'Junta Examinadora de Quiroprácticos', 'licensing', 'JE Quiroprácticos'
  UNION ALL SELECT 'Junta Examinadora de Optómetras', 'licensing', 'JE Optómetras'
  UNION ALL SELECT 'Junta Examinadora de Administradores de Servicios de Salud', 'licensing', 'JE Admin. Salud'
) x
WHERE t.vertical = 'healthcare'
  AND NOT EXISTS (SELECT 1 FROM regulators r WHERE r.tenant_id = t.id AND r.short_name = x.short_name);

-- ---- Source-cited entries for boards/agencies not yet in requirement_sources (global; idempotent) ----
INSERT IGNORE INTO requirement_sources (id, code, authority, regulation, url, effective_date, superseded_date, last_reviewed, verified_by, summary, created_at) VALUES
 (UUID(),'orcps_recert','PR Departamento de Salud — ORCPS','Recertificación de licencia permanente cada 3 años (juntas examinadoras)','https://orcps.salud.pr.gov/','1976-01-01',NULL,'2026-09-07','FlxCoreHC','ORCPS administers ~48 examining boards and processes 3-year recertification for every health-profession license. Package: continuing-education evidence, Buena Conducta certificate, and (PR residents) ASUME certificate. Documents uploaded as PDF/JPG up to 10MB.',UTC_TIMESTAMP()),
 (UUID(),'nursing_lic','PR Junta Examinadora de Enfermería','Ley 9 / Ley para Regular la Práctica de la Enfermería (20 LPRA cap. 9, 9A)','https://www.salud.pr.gov/CMS/126',NULL,NULL,'2026-09-07','FlxCoreHC','Nursing licensure and 3-year recertification in PR run through the nursing examining board under ORCPS; CE hours set per trienio.',UTC_TIMESTAMP()),
 (UUID(),'pharmacy_lic','PR Junta de Farmacia','Ley de Farmacia de Puerto Rico (Ley 247-2004, según enmendada)','https://www.salud.pr.gov/CMS/42',NULL,NULL,'2026-09-07','FlxCoreHC','Pharmacist and pharmacy licensure/recertification through the PR Board of Pharmacy under ORCPS; controlled-substance dispensing also subject to DEA and PR CDS rules.',UTC_TIMESTAMP()),
 (UUID(),'dental_lic','PR Junta Dental Examinadora','Ley Núm. 75 de 1925, según enmendada (20 LPRA cap. 5)','https://www.salud.pr.gov/CMS/444',NULL,NULL,'2026-09-07','FlxCoreHC','Dentist and dental-tech licensure/recertification through the PR Dental Examining Board under ORCPS.',UTC_TIMESTAMP()),
 (UUID(),'behavioral_lic','PR ASSMCA','Certificación de proveedores de salud mental y contra la adicción (Ley 67-1993, según enmendada)','https://www.assmca.pr.gov/',NULL,NULL,'2026-09-07','FlxCoreHC','ASSMCA evaluates, monitors and certifies public and private agencies licensed to provide mental-health, addiction-prevention, treatment and rehabilitation services. Facilities serving these populations need ASSMCA licensure in addition to provider licenses.',UTC_TIMESTAMP()),
 (UUID(),'ases_vital','PR Administración de Seguros de Salud (ASES)','Ley Núm. 72 de 7 de septiembre de 1993','https://www.ases.pr.gov/',NULL,NULL,'2026-09-07','FlxCoreHC','ASES administers the government health plan (Plan Vital) covering ~1.6M residents and contracts/oversees the participating insurers; provider participation flows through those insurers and PR Medicaid (PEP).',UTC_TIMESTAMP());
