-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 113_pr_training_content.sql
-- Puerto Rico regulatory & certification knowledge in the Training Center, source-verified
-- (September 2026). These become the platform's curated PR guidance and, later, the seed knowledge
-- for the compliance assistant. Each carries the authority and an official link.
INSERT IGNORE INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at) VALUES
('pr_license_recert', 'PR license recertification (3-year cycle)', 'compliance', 'healthcare', 'state',
 'Permanent PR professional licenses recertify every three years through ORCPS, based on continuing education.',
 'Puerto Rico permanent professional licenses are registered and recertified every three (3) years through the Oficina de Reglamentacion y Certificacion de los Profesionales de la Salud (ORCPS), under Ley 11-1976. Recertification is based on continuing education, with the specific hours set per trienio by each examining board. The recertification package typically also includes: a PR criminal background certificate (Certificado de Antecedentes Penales / "Buena Conducta"), an ASUME child-support negative certificate, and board-specific documents. Track the license expiry on a 3-year cycle and keep CE, Buena Conducta and ASUME evidence on file.',
 'https://orcps.salud.pr.gov/', 'ORCPS', 4, 10, UTC_TIMESTAMP()),

('pr_continuing_education', 'Continuing education & recertification (OA 542 / OA 544)', 'compliance', 'healthcare', 'state',
 'CE is required for recertification. OA 542 (2022) broadly suspended it; OA 544 restored CE on LGBTTQ+ cultural competence. Boards still run 3-year CE-based recertification.',
 'Continuing education (CE) is a core recertification requirement in Puerto Rico. History to understand: Orden Administrativa 542 (July 2022) amended OA 74 and OA 398 to broadly eliminate the CE requirement for health professionals; Orden Administrativa 544 then amended OA 542 to RESTORE CE requirements related to sensitivity and cultural competence in serving the LGBTTQ+ community. In practice the examining boards continue to run three-year, CE-based recertification (e.g. the nursing regulation, Reglamento 9104), with hours set per trienio. Do not assume CE is waived — track CE evidence for each provider.',
 'https://www.salud.pr.gov/CMS/261', 'Ordenes Administrativas — Salud PR', 4, 11, UTC_TIMESTAMP()),

('pr_universal_recognition', 'Universal license recognition (Ley 102-2025)', 'compliance', 'healthcare', 'state',
 'A provider licensed in another U.S. jurisdiction may obtain an equivalent PR license by recognition. Implementing regulation still evolving.',
 'Ley 102-2025 (signed August 1, 2025), the Ley de Reconocimiento Universal de Licencias Ocupacionales y Licencias Profesionales, lets a person holding a valid U.S.-jurisdiction license apply for an equivalent Puerto Rico license by recognition, provided they held the license at least 1 year and practiced at least 3 consecutive years before applying. Provisional licenses are issued while the applicant completes PR requirements. Lawyers are excluded. IMPORTANT: the implementing regulation is still evolving — it is subject to Financial Oversight Board (PROMESA) review and the governor has signaled amendments. Treat this as an intake pathway for reciprocity/provisional licenses, not a settled rule; re-check before relying on specifics.',
 'https://www.lexjuris.com/lexlex/Leyes2025/lexl2025102.htm', 'Ley 102-2025 (LexJuris)', 5, 12, UTC_TIMESTAMP()),

('pr_ocs_sicro', 'OCS / SICRO centralized credentialing (Ley 73-2023)', 'compliance', 'healthcare', 'state',
 'Registration in the OCS credentialing portal (SICRO) is compulsory. OCS is the depository; insurers do the credentialing. Phase II adds institutions with a notarized affidavit.',
 'The Oficina del Comisionado de Seguros (OCS) administers Puerto Rico''s centralized healthcare provider credentialing portal (SICRO) under Ley 73-2023. OCS is the depository/administrator; the actual credentialing is performed by insurers and health service organizations. Registration is compulsory — insurers stopped accepting other portals. Phase II (January 2025) extended the requirement to institutions, which must file a notarized affidavit. Track each provider''s SICRO status, profile ID, last attestation and attestation expiry, and any delegate/CVO.',
 'https://www.ocs.pr.gov/', 'OCS Puerto Rico', 4, 13, UTC_TIMESTAMP()),

('pr_medicaid_pep', 'Medicaid / Plan Vital enrollment (PEP)', 'compliance', 'healthcare', 'federal',
 'Enroll and revalidate in the PR Medicaid Provider Enrollment Portal (PEP). Service locations must be registered or claims deny (since May 15, 2024).',
 'Puerto Rico Medicaid (Plan Vital / ASES) requires enrollment through the Provider Enrollment Portal (PEP). Revalidate on the federal cycle (42 CFR 455.414): every 5 years for medical providers, every 3 years for non-medical providers enrolled on or after January 1, 2023. Critically, since May 15, 2024, claims are denied when a service location is not registered in PEP — so every practice location must be registered, not just the provider. Track the Medicaid provider ID, effective and revalidation dates, and confirm all service locations are registered.',
 'https://www.medicaid.pr.gov/', 'PR Medicaid PEP', 4, 14, UTC_TIMESTAMP()),

('pr_telehealth', 'Telehealth / telemedicine (Ley 8-2025)', 'compliance', 'healthcare', 'state',
 'A current PR license is sufficient to practice telehealth. The separate board certification previously required by Ley 168-2018 was removed by Ley 8-2025.',
 'Puerto Rico previously required a separate telehealth certification from the licensing board under Ley 168-2018. Ley 8-2025 (April 11, 2025) removed that requirement: a current, valid PR professional license is now sufficient to provide telemedicine/telehealth. Track telehealth as an enabled flag on the provider, not as a separate credential. Do not require or expire a telehealth certificate.',
 'https://www.lexjuris.com/lexlex/Leyes2025/lexl2025008.htm', 'Ley 8-2025 (LexJuris)', 3, 15, UTC_TIMESTAMP());
