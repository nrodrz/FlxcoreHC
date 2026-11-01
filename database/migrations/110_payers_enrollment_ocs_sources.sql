-- 110_payers_enrollment_ocs_sources.sql
-- From "license tracker" to credentialing & regulatory compliance platform (Puerto Rico):
--   payers               — reference list of insurers/plans (platform-seeded PR payers + tenant-added)
--   payer_credentialing  — where a provider stands with EACH payer (the billing company's daily pain)
--   program_enrollments  — Medicaid/Plan Vital (PEP) and Medicare (PECOS/PTAN) enrollment + revalidation
--   ocs_profiles         — OCS / SICRO centralized credentialing profile (Law 73-2023; compulsory)
--   requirement_sources  — the legal/regulatory citation behind each requirement, dated and reviewed
-- Also: Good Standing, Buena Conducta and ASUME certificates as catalog document types (ORCPS
-- recertification package), and a telehealth-enabled flag (Law 8-2025: no certification required).

CREATE TABLE IF NOT EXISTS payers (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NULL,                 -- NULL = platform-wide reference entry
  name        VARCHAR(120) NOT NULL,
  kind        VARCHAR(24)  NOT NULL DEFAULT 'commercial',   -- commercial|medicare_advantage|medicaid_mco|government|other
  short_code  VARCHAR(16)  NULL,
  website     VARCHAR(255) NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  KEY idx_payers_tenant (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payers (id, tenant_id, name, kind, short_code, created_at) VALUES
 (UUID(), NULL, 'Triple-S Salud',           'commercial',        'SSS',  UTC_TIMESTAMP()),
 (UUID(), NULL, 'MCS',                      'commercial',        'MCS',  UTC_TIMESTAMP()),
 (UUID(), NULL, 'First Medical Health Plan','commercial',        'FMHP', UTC_TIMESTAMP()),
 (UUID(), NULL, 'Plan de Salud Menonita',   'commercial',        'PSM',  UTC_TIMESTAMP()),
 (UUID(), NULL, 'MMM Healthcare',           'medicare_advantage','MMM',  UTC_TIMESTAMP()),
 (UUID(), NULL, 'MAPFRE Salud',             'commercial',        'MAPF', UTC_TIMESTAMP()),
 (UUID(), NULL, 'Humana Puerto Rico',       'commercial',        'HUM',  UTC_TIMESTAMP()),
 (UUID(), NULL, 'Plan Vital (ASES)',        'government',        'VITAL',UTC_TIMESTAMP()),
 (UUID(), NULL, 'Medicare (Original)',      'government',        'MCR',  UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS payer_credentialing (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id           CHAR(36)     NOT NULL,
  holder_id           CHAR(36)     NOT NULL,
  payer_id            CHAR(36)     NOT NULL,
  network             VARCHAR(80)  NULL,          -- plan / line of business
  status              VARCHAR(24)  NOT NULL DEFAULT 'not_started',
                      -- not_started|application_pending|under_review|credentialed|contracted|participating|
                      -- non_participating|denied|suspended|terminated|recredentialing|expired
  application_date    DATE NULL,
  approval_date       DATE NULL,
  effective_date      DATE NULL,
  recredentialing_date DATE NULL,
  termination_date    DATE NULL,
  contract_number     VARCHAR(80)  NULL,
  payer_provider_id   VARCHAR(80)  NULL,
  notes               VARCHAR(500) NULL,
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_pc (holder_id, payer_id, network),
  KEY idx_pc_tenant (tenant_id, status),
  KEY idx_pc_recred (tenant_id, recredentialing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS program_enrollments (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id           CHAR(36)     NOT NULL,
  holder_id           CHAR(36)     NOT NULL,
  program             VARCHAR(16)  NOT NULL,      -- medicaid | medicare
  enrollment_id       VARCHAR(80)  NULL,          -- Medicaid provider ID / Medicare PTAN
  status              VARCHAR(24)  NOT NULL DEFAULT 'not_enrolled',
                      -- not_enrolled|pending|enrolled|revalidation_due|revalidating|terminated|opted_out
  enrollment_type     VARCHAR(24)  NULL,          -- individual|group|ordering_referring
  effective_date      DATE NULL,
  revalidation_date   DATE NULL,                  -- PEP: 5y medical / 3y non-medical (42 CFR 455.414); PECOS: 5y
  expiration_date     DATE NULL,
  mac_or_region       VARCHAR(80)  NULL,
  group_affiliation   VARCHAR(160) NULL,
  locations_registered TINYINT(1)  NOT NULL DEFAULT 0,   -- PEP: service locations registered (claims deny otherwise since 2024-05-15)
  notes               VARCHAR(500) NULL,
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_pe (holder_id, program),
  KEY idx_pe_tenant (tenant_id, status),
  KEY idx_pe_reval (tenant_id, revalidation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ocs_profiles (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id           CHAR(36)     NOT NULL,
  holder_id           CHAR(36)     NOT NULL,
  sicro_status        VARCHAR(20)  NOT NULL DEFAULT 'not_registered',   -- not_registered|registered|attested|pending|expired
  profile_id          VARCHAR(80)  NULL,
  last_attestation    DATE NULL,
  attestation_expires DATE NULL,
  delegate_cvo        VARCHAR(160) NULL,
  affidavit_on_file   TINYINT(1)   NOT NULL DEFAULT 0,   -- institutions (Phase II) require a notarized affidavit
  notes               VARCHAR(500) NULL,
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_ocs (holder_id),
  KEY idx_ocs_tenant (tenant_id, sicro_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requirement_sources (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  code            VARCHAR(40)  NOT NULL,          -- what it backs, e.g. 'license_recert', 'ocs_sicro'
  authority       VARCHAR(160) NOT NULL,
  regulation      VARCHAR(200) NULL,
  url             VARCHAR(255) NULL,
  effective_date  DATE NULL,
  superseded_date DATE NULL,
  last_reviewed   DATE NULL,
  review_every_days INT NOT NULL DEFAULT 180,
  verified_by     VARCHAR(120) NULL,
  summary         VARCHAR(500) NULL,
  created_at      DATETIME     NOT NULL,
  UNIQUE KEY uq_rs_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO requirement_sources (id, code, authority, regulation, url, effective_date, superseded_date, last_reviewed, verified_by, summary, created_at) VALUES
 (UUID(),'license_recert','PR Departamento de Salud — ORCPS','Registro y Recertificación de Licencia Permanente (cada 3 años)','https://orcps.salud.pr.gov/',NULL,NULL,'2026-09-05','FlxCoreHC','Permanent licenses are recertified every three years. Package: CE evidence, PR criminal background certificate (Buena Conducta), ASUME child-support negative certificate, board-specific documents.',UTC_TIMESTAMP()),
 (UUID(),'good_standing','PR Departamento de Salud — ORCPS / JLDM','Verificación de Licencia (Good Standing)','https://orcps.salud.pr.gov/mbps/verificacion',NULL,NULL,'2026-09-05','FlxCoreHC','ORCPS/JLDM issue Good Standing verification for registered licenses; payers and OCS request it.',UTC_TIMESTAMP()),
 (UUID(),'ocs_sicro','Oficina del Comisionado de Seguros (OCS)','Ley 73-2023; Carta Circular CC-2025-2044-D (Fase II, instituciones)','https://www.ocs.pr.gov/en-us/credencializacion','2024-10-01',NULL,'2026-09-05','FlxCoreHC','Centralized credentialing portal (SICRO). OCS is depository/administrator; insurers credential. Registration compulsory; Phase II (Jan 2025) extends to institutions with a notarized affidavit.',UTC_TIMESTAMP()),
 (UUID(),'medicaid_pep','Programa Medicaid de Puerto Rico (PRMP)','Provider Enrollment Portal (PEP); 42 CFR §455.414 revalidation','https://www.medicaid.pr.gov/Home/PEPRevalidation/','2024-05-15',NULL,'2026-09-05','FlxCoreHC','Enrollment in PEP is required for Plan Vital. Revalidate every 5 years (medical) / 3 years (non-medical enrolled on/after 2023-01-01). Since 2024-05-15 claims deny when the service location is not registered in PEP.',UTC_TIMESTAMP()),
 (UUID(),'medicare_pecos','CMS — Medicare','PECOS enrollment and revalidation (every 5 years)','https://www.cms.gov/medicare/enrollment-renewal','2024-01-01',NULL,'2026-09-05','FlxCoreHC','Medicare enrollment via PECOS; PTAN assigned by the MAC; revalidation on a 5-year cycle.',UTC_TIMESTAMP()),
 (UUID(),'telehealth','PR Legislature / Departamento de Salud','Ley 168-2018 as amended by Ley 8-2025 (2025-04-11)','https://www.lexjuris.com/lexlex/Leyes2025/lexl2025008.htm','2025-04-11',NULL,'2026-09-05','FlxCoreHC','A current PR license is sufficient to practice telemedicine/telehealth; the separate board certification previously required by Law 168-2018 was removed by Law 8-2025. Track as an enabled flag, not a credential.',UTC_TIMESTAMP()),
 (UUID(),'oig_leie','HHS Office of Inspector General','List of Excluded Individuals/Entities (LEIE), monthly updates','https://oig.hhs.gov/exclusions/exclusions_list.asp',NULL,NULL,'2026-09-05','FlxCoreHC','Screen providers against LEIE at least monthly; the database is updated monthly.',UTC_TIMESTAMP()),
 (UUID(),'dea','U.S. Drug Enforcement Administration','DEA registration (3-year cycle) for controlled-substance prescribers','https://www.deadiversion.usdoj.gov/',NULL,NULL,'2026-09-05','FlxCoreHC','Prescribers hold a DEA registration renewed every three years; separate from any PR controlled-substance authorization.',UTC_TIMESTAMP());

-- Telehealth flag on providers (Law 8-2025: enabled by license, no certificate).
ALTER TABLE credential_holders ADD COLUMN telehealth_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- ORCPS recertification package items + Good Standing, as catalog document types (healthcare).
INSERT IGNORE INTO credential_catalog (id, code, name, category, authority, vertical, applies_to, renewal_months, is_required, sort, created_at) VALUES
 (UUID(),'good_standing','Good Standing (ORCPS / JLDM verification)','license','PR Departamento de Salud — ORCPS','healthcare','physician,np,pa,nurse,dentist,pharmacist,therapist,behavioral,chiropractor,technologist,other_pro',12,0,15,UTC_TIMESTAMP()),
 (UUID(),'buena_conducta','Certificado de Antecedentes Penales (Buena Conducta)','identifier','Policía de Puerto Rico','healthcare','physician,np,pa,nurse,dentist,pharmacist,therapist,behavioral,chiropractor,technologist,other_pro',36,0,16,UTC_TIMESTAMP()),
 (UUID(),'asume','Certificado Negativo de Pensión Alimentaria (ASUME)','identifier','ASUME','healthcare','physician,np,pa,nurse,dentist,pharmacist,therapist,behavioral,chiropractor,technologist,other_pro',36,0,17,UTC_TIMESTAMP());
