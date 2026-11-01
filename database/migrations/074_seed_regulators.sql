-- 074_seed_regulators.sql
-- Citation-checked starter set of regulators for healthcare tenants: the federal agencies,
-- frameworks, accreditor and state boards (FL + PR) most billing companies answer to. Each
-- requirement carries its legal citation in notes. Editable/deletable like any tenant data —
-- a verified starting point, not a cage. Seeded ONLY into healthcare tenants and idempotent
-- via NOT EXISTS on name. New signups receive the same set via AuthService::seedTemplatesFor.

INSERT INTO regulators (id, tenant_id, name, category, jurisdiction, short_name, is_custom, status, created_at)
SELECT UUID(), t.id, s.name, s.category, s.jurisdiction, s.short_name, 0, 'active', UTC_TIMESTAMP()
FROM tenants t
CROSS JOIN (
  SELECT 'Centers for Medicare & Medicaid Services' AS name, 'government' AS category, NULL AS jurisdiction, 'CMS' AS short_name
  UNION ALL SELECT 'Drug Enforcement Administration', 'government', NULL, 'DEA'
  UNION ALL SELECT 'Food & Drug Administration', 'government', NULL, 'FDA'
  UNION ALL SELECT 'Centers for Disease Control & Prevention', 'government', NULL, 'CDC'
  UNION ALL SELECT 'HIPAA', 'regulation', NULL, NULL
  UNION ALL SELECT 'OSHA', 'regulation', NULL, NULL
  UNION ALL SELECT 'The Joint Commission', 'accreditation', NULL, 'TJC'
  UNION ALL SELECT 'Florida Board of Medicine', 'state_board', 'FL', NULL
  UNION ALL SELECT 'Junta de Licenciamiento y Disciplina Médica (JLDM)', 'state_board', 'PR', 'JLDM'
  UNION ALL SELECT 'PR Department of Health', 'government', 'PR', NULL
) s
WHERE t.vertical = 'healthcare'
  AND NOT EXISTS (
    SELECT 1 FROM regulators r WHERE r.tenant_id = t.id AND r.name = s.name
  );

INSERT INTO regulator_requirements (id, tenant_id, regulator_id, kind, catalog_code, name, category, renewal_months, notes, created_at)
SELECT UUID(), t.id, r.id, s.kind, s.catalog_code, s.rname, s.kind, s.renewal_months, s.notes, UTC_TIMESTAMP()
FROM tenants t
JOIN regulators r ON r.tenant_id = t.id
CROSS JOIN (
  SELECT 'Centers for Medicare & Medicaid Services' AS reg, 'credential' AS kind, 'npi' AS catalog_code,
         'National Provider Identifier (NPI)' AS rname, NULL AS renewal_months,
         'NPPES enrollment; standard unique identifier — 45 CFR 162.406/162.410.' AS notes
  UNION ALL SELECT 'Centers for Medicare & Medicaid Services', 'credential', 'medicare_ptan',
         'Medicare enrollment (PTAN)', NULL,
         'PECOS / CMS-855 enrollment; revalidation generally every 5 years — 42 CFR 424.515.'
  UNION ALL SELECT 'Centers for Medicare & Medicaid Services', 'credential', 'clia',
         'CLIA certificate (laboratories)', 24,
         'Certificates issued on a 2-year cycle — 42 CFR Part 493.'
  UNION ALL SELECT 'Drug Enforcement Administration', 'credential', 'dea',
         'DEA registration', 36,
         'Practitioner registration renews every 3 years — 21 CFR 1301.13.'
  UNION ALL SELECT 'HIPAA', 'document', NULL,
         'HIPAA privacy & security workforce training', 12,
         'Workforce training, documented — 45 CFR 164.530(b) and 164.308(a)(5); annual refresh is standard practice.'
  UNION ALL SELECT 'HIPAA', 'document', NULL,
         'Business Associate Agreements on file', NULL,
         '45 CFR 164.308(b) and 164.502(e).'
  UNION ALL SELECT 'OSHA', 'document', NULL,
         'Bloodborne pathogens training certificate', 12,
         'Annual training required — 29 CFR 1910.1030(g)(2).'
  UNION ALL SELECT 'OSHA', 'document', NULL,
         'Exposure control plan (annual review)', 12,
         '29 CFR 1910.1030(c)(1).'
  UNION ALL SELECT 'Food & Drug Administration', 'document', NULL,
         'Establishment registration (where applicable)', 12,
         'Annual registration for covered establishments — 21 CFR Parts 607/807.'
  UNION ALL SELECT 'The Joint Commission', 'document', NULL,
         'Accreditation certificate', 36,
         'Accreditation on an approximately 3-year survey cycle (unannounced surveys).'
  UNION ALL SELECT 'The Joint Commission', 'document', NULL,
         'Primary-source verification files', NULL,
         'TJC HR standards require primary-source verification of licensure.'
  UNION ALL SELECT 'Florida Board of Medicine', 'credential', 'state_medical_license',
         'Florida medical license', 24,
         'Biennial renewal — s. 458.319, Fla. Stat.'
  UNION ALL SELECT 'Florida Board of Medicine', 'credential', 'cme',
         'Florida CME (biennial)', 24,
         '40 hours per biennium incl. mandated topics — Rule 64B8-13.005, F.A.C.'
  UNION ALL SELECT 'Junta de Licenciamiento y Disciplina Médica (JLDM)', 'credential', 'pr_medical_license',
         'PR medical license', 36,
         'Licensure and triennial renewal — 20 LPRA § 131 et seq. (JLDM).'
  UNION ALL SELECT 'Junta de Licenciamiento y Disciplina Médica (JLDM)', 'credential', 'pr_cme',
         'PR continuing education (triennial)', 36,
         'Triennial CME with renewal — 20 LPRA / JLDM regulation.'
  UNION ALL SELECT 'Junta de Licenciamiento y Disciplina Médica (JLDM)', 'credential', 'pr_good_conduct',
         'Certificate of good conduct (PR Police)', NULL,
         'Required with licensure/renewal filings.'
  UNION ALL SELECT 'Junta de Licenciamiento y Disciplina Médica (JLDM)', 'credential', 'pr_cds_negative',
         'Negative controlled-substances (drug-test) certificate', NULL,
         'Required with renewal filings.'
  UNION ALL SELECT 'PR Department of Health', 'document', NULL,
         'Health facility license (Depto. de Salud)', 12,
         'Facility licensing — 24 LPRA (Ley Núm. 101).'
  UNION ALL SELECT 'PR Department of Health', 'credential', 'pr_lab_personnel_license',
         'PR laboratory personnel license', NULL,
         'Laboratory personnel licensure — 20 LPRA ch. 111.'
) s
WHERE t.vertical = 'healthcare'
  AND NOT EXISTS (
    SELECT 1 FROM regulator_requirements rr
     WHERE rr.tenant_id = t.id AND rr.regulator_id = r.id AND rr.name = s.rname
  )
  AND r.name = s.reg;
