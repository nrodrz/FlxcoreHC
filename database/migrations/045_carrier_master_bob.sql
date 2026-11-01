-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Platform carrier master + producer book-of-business review (v1.51).
--
-- Two features:
--  1. A platform-level carrier catalog (no tenant_id) that Indisse-BTS maintains once and
--     every tenant selects from — so "Wellcare" is one entity, not a dozen typo variants.
--     Seeded with the Florida Medicare Advantage organizations verified against the CMS
--     2026 plan landscape (medicare.gov county data; Forbes/US News parent-org mapping).
--  2. Producer self-entered policies get a review flag so managers can vet them.

CREATE TABLE IF NOT EXISTS platform_carriers (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  name          VARCHAR(140) NOT NULL,            -- CMS contracting organization name
  short_name    VARCHAR(30)  NULL,
  parent_org    VARCHAR(120) NULL,                -- ultimate parent (e.g. Elevance, UnitedHealth)
  naic          VARCHAR(10)  NULL,
  `lines`       VARCHAR(60)  NOT NULL DEFAULT 'MA', -- comma list: MA,MAPD,PDP,MEDSUPP,DSNP
  states        VARCHAR(120) NOT NULL DEFAULT 'FL', -- comma list of state codes
  website       VARCHAR(190) NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'active', -- active | inactive
  notes         VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_platform_carrier_name (name),
  INDEX idx_platform_carrier_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link a tenant carrier to a platform master entry (nullable: custom carriers stay local).
ALTER TABLE carriers ADD COLUMN IF NOT EXISTS platform_carrier_id CHAR(36) NULL AFTER name;
ALTER TABLE carriers ADD COLUMN IF NOT EXISTS is_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER platform_carrier_id;

-- Producer book-of-business review workflow.
ALTER TABLE policies ADD COLUMN IF NOT EXISTS entered_by_producer TINYINT(1) NOT NULL DEFAULT 0 AFTER holder_id;
ALTER TABLE policies ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER entered_by_producer;
ALTER TABLE policies ADD COLUMN IF NOT EXISTS reviewed_by CHAR(36) NULL AFTER reviewed_at;

-- Seed the Florida Medicare Advantage organizations (CMS 2026 landscape; verified 2026-07).
-- Parent-org attribution per US News / Forbes carrier directories. NAIC left null where not
-- needed operationally; the agency only needs to SELECT the carrier.
INSERT INTO platform_carriers (id, name, short_name, parent_org, `lines`, states, website, status, created_at)
SELECT * FROM (
  SELECT UUID() AS id, 'UnitedHealthcare' AS name, 'UHC' AS short_name, 'UnitedHealth Group' AS parent_org, 'MA,MAPD,PDP,MEDSUPP,DSNP' AS `lines`, 'FL' AS states, 'uhc.com' AS website, 'active' AS status, NOW() AS created_at
  UNION ALL SELECT UUID(), 'Humana', 'HUM', 'Humana Inc.', 'MA,MAPD,PDP,MEDSUPP,DSNP', 'FL', 'humana.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'CarePlus Health Plans', 'CarePlus', 'Humana Inc.', 'MA,MAPD,DSNP', 'FL', 'careplushealthplans.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Aetna Medicare', 'Aetna', 'CVS Health', 'MA,MAPD,PDP,DSNP', 'FL', 'aetnamedicare.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Wellcare', 'Wellcare', 'Centene Corporation', 'MA,MAPD,PDP,DSNP', 'FL', 'wellcare.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Cigna Healthcare', 'Cigna', 'The Cigna Group', 'MA,MAPD,PDP', 'FL', 'cigna.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Devoted Health', 'Devoted', 'Devoted Health', 'MA,MAPD,DSNP', 'FL', 'devoted.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Florida Blue Medicare', 'FL Blue', 'GuideWell', 'MA,MAPD,MEDSUPP,PDP', 'FL', 'floridablue.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Simply Healthcare Plans', 'Simply', 'Elevance Health', 'MA,MAPD,DSNP', 'FL', 'simplyhealthcareplans.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Freedom Health', 'Freedom', 'Anthem/Elevance', 'MA,MAPD,DSNP', 'FL', 'freedomhealth.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Optimum HealthCare', 'Optimum', 'Anthem/Elevance', 'MA,MAPD,DSNP', 'FL', 'youroptimumhealthcare.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'HealthSun Health Plans', 'HealthSun', 'Anthem/Elevance', 'MA,MAPD,DSNP', 'FL', 'healthsun.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'AvMed', 'AvMed', 'AvMed Inc.', 'MA,MAPD', 'FL', 'avmed.org', 'active', NOW()
  UNION ALL SELECT UUID(), 'Doctors HealthCare Plans', 'Doctors HCP', 'Doctors HealthCare Plans', 'MA,MAPD,DSNP', 'FL', 'doctorshcp.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Solis Health Plans', 'Solis', 'Solis Health Plans', 'MA,MAPD,DSNP', 'FL', 'solishealthplans.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Health First Health Plans', 'Health First', 'Health First', 'MA,MAPD', 'FL', 'healthfirst.org', 'active', NOW()
  UNION ALL SELECT UUID(), 'Leon Health', 'Leon', 'Leon Medical Centers', 'MA,MAPD,DSNP', 'FL', 'leonhealth.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Capital Health Plan', 'CHP', 'Capital Health Plan', 'MA,MAPD', 'FL', 'capitalhealth.com', 'active', NOW()
  UNION ALL SELECT UUID(), 'Ultimate Health Plans', 'Ultimate', 'Ultimate Health Plans', 'MA,MAPD,DSNP', 'FL', 'ultimatehp.com', 'active', NOW()
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM platform_carriers pc WHERE pc.name = seed.name);
