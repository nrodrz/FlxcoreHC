-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Master catalog of carrier plan NAMES, so agents pick from a controlled list when entering
-- their book of business instead of free-texting (which fragments plan-level reporting).
-- Platform-level (not tenant-scoped), like platform_carriers: one authoritative list the
-- operator curates + refreshes yearly via CSV. Keyed by plan_year so each season is separate;
-- 2027 names are added when CMS publishes the landscape (~late Sept 2026).
CREATE TABLE IF NOT EXISTS platform_carrier_plans (
  id                  CHAR(36)     NOT NULL,
  platform_carrier_id CHAR(36)     NULL,               -- link to platform_carriers (may be null if unmatched on import)
  carrier_name        VARCHAR(160) NOT NULL,           -- denormalized: display + CSV import matching
  plan_name           VARCHAR(200) NOT NULL,
  plan_type           VARCHAR(20)  NOT NULL DEFAULT 'MAPD',   -- MAPD/MA/PDP/MEDSUPP/DSNP/OTHER
  contract_id         VARCHAR(12)  NULL,               -- CMS contract (H/R/S number), optional
  pbp                 VARCHAR(8)   NULL,               -- plan benefit package id, optional
  plan_year           SMALLINT     NOT NULL,
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  source              VARCHAR(24)  NULL,               -- 'seed' | 'import' | 'manual'
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_plan (carrier_name, plan_name, plan_year),
  KEY idx_carrier_year (platform_carrier_id, plan_year, active),
  KEY idx_year (plan_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verified starter set: recognizable FL MA plan brand names for PY2026, matched to the
-- platform_carriers seed by name. Sources: carrier Medicare sites + CMS 2026 landscape
-- (Humana, UHC/AARP, Aetna, Florida Blue, Wellcare, Cigna/HealthSpring, Devoted, CarePlus,
-- Freedom, Optimum, HealthSun, Simply), verified 2026-07. This is a STARTER list — the full
-- county-segmented landscape is loaded via CSV import (Admin → Carrier plans → Upload).
INSERT INTO platform_carrier_plans (id, platform_carrier_id, carrier_name, plan_name, plan_type, plan_year, active, source, created_at)
SELECT UUID(), pc.id, s.carrier_name, s.plan_name, s.plan_type, 2026, 1, 'seed', NOW()
FROM (
    SELECT 'Humana' carrier_name, 'Humana Gold Plus (HMO)' plan_name, 'MAPD' plan_type
    UNION ALL SELECT 'Humana', 'HumanaChoice (PPO)', 'MAPD'
    UNION ALL SELECT 'Humana', 'Humana USAA Honor (PPO)', 'MAPD'
    UNION ALL SELECT 'Humana', 'Humana Gold Plus SNP-DE (HMO D-SNP)', 'DSNP'
    UNION ALL SELECT 'CarePlus Health Plans', 'CarePlus (HMO)', 'MAPD'
    UNION ALL SELECT 'CarePlus Health Plans', 'CarePlus Premier (HMO)', 'MAPD'
    UNION ALL SELECT 'CarePlus Health Plans', 'CarePlus Bright (HMO D-SNP)', 'DSNP'
    UNION ALL SELECT 'UnitedHealthcare', 'AARP Medicare Advantage Choice (PPO)', 'MAPD'
    UNION ALL SELECT 'UnitedHealthcare', 'AARP Medicare Advantage (HMO-POS)', 'MAPD'
    UNION ALL SELECT 'UnitedHealthcare', 'UnitedHealthcare Dual Complete (HMO D-SNP)', 'DSNP'
    UNION ALL SELECT 'Aetna Medicare', 'Aetna Medicare Eagle (PPO)', 'MAPD'
    UNION ALL SELECT 'Aetna Medicare', 'Aetna Medicare Elite (HMO)', 'MAPD'
    UNION ALL SELECT 'Aetna Medicare', 'Aetna Medicare Premier (HMO-POS)', 'MAPD'
    UNION ALL SELECT 'Aetna Medicare', 'Aetna Medicare Assure (HMO D-SNP)', 'DSNP'
    UNION ALL SELECT 'Florida Blue Medicare', 'BlueMedicare Complete (HMO)', 'MAPD'
    UNION ALL SELECT 'Florida Blue Medicare', 'BlueMedicare Preferred (PPO)', 'MAPD'
    UNION ALL SELECT 'Florida Blue Medicare', 'BlueMedicare Premier (HMO)', 'MAPD'
    UNION ALL SELECT 'Wellcare', 'Wellcare Giveback (HMO)', 'MAPD'
    UNION ALL SELECT 'Wellcare', 'Wellcare Premier (HMO)', 'MAPD'
    UNION ALL SELECT 'Wellcare', 'Wellcare Dual Access (HMO D-SNP)', 'DSNP'
    UNION ALL SELECT 'Cigna Healthcare', 'Cigna HealthSpring Preferred (HMO)', 'MAPD'
    UNION ALL SELECT 'Cigna Healthcare', 'Cigna HealthSpring TotalCare (HMO D-SNP)', 'DSNP'
    UNION ALL SELECT 'Devoted Health', 'Devoted Core (HMO)', 'MAPD'
    UNION ALL SELECT 'Devoted Health', 'Devoted Giveback (PPO)', 'MAPD'
    UNION ALL SELECT 'Freedom Health', 'Freedom Total Plus (HMO)', 'MAPD'
    UNION ALL SELECT 'Freedom Health', 'Freedom Dividend (HMO)', 'MAPD'
    UNION ALL SELECT 'Optimum HealthCare', 'Optimum Gold (HMO)', 'MAPD'
    UNION ALL SELECT 'Optimum HealthCare', 'Optimum Platinum (HMO)', 'MAPD'
    UNION ALL SELECT 'HealthSun Health Plans', 'HealthSun Advantage (HMO)', 'MAPD'
    UNION ALL SELECT 'HealthSun Health Plans', 'HealthSun MediMax (HMO D-SNP)', 'DSNP'
    UNION ALL SELECT 'Simply Healthcare Plans', 'Simply Complete (HMO)', 'MAPD'
    UNION ALL SELECT 'Simply Healthcare Plans', 'Simply Dual Advantage (HMO D-SNP)', 'DSNP'
) AS s
JOIN platform_carriers pc ON pc.name = s.carrier_name
ON DUPLICATE KEY UPDATE platform_carrier_plans.updated_at = NOW();
