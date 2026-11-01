-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Carrier aliasing: CMS landscape files identify plans by legal ORGANIZATION name
-- ("AETNA LIFE INSURANCE COMPANY", "AETNA HEALTH INC. (FL)") and CONTRACT ID (H5521, H1609),
-- not by the marketing brand ("Aetna Medicare") we seed the master carrier list with. Without a
-- map, every CMS plan lands under a new org-named carrier that doesn't match the master list —
-- producing "unmatched" rows and apparent duplicates. This table maps org names AND contract IDs
-- to a master platform_carriers row so imported plans attach to the right brand.
CREATE TABLE IF NOT EXISTS platform_carrier_aliases (
  id                  CHAR(36)     NOT NULL,
  platform_carrier_id CHAR(36)     NOT NULL,
  alias               VARCHAR(160) NOT NULL,          -- an org name OR a contract id, upper-cased
  alias_type          VARCHAR(12)  NOT NULL DEFAULT 'org',  -- 'org' | 'contract'
  created_at          DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias (alias),
  KEY idx_carrier (platform_carrier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed contract-ID aliases (the most reliable key — a contract belongs to exactly one carrier).
-- Web-verified Florida MA contracts (CMS 2026 star ratings, Oct 2025).
INSERT IGNORE INTO platform_carrier_aliases (id, platform_carrier_id, alias, alias_type, created_at)
SELECT UUID(), pc.id, x.alias, x.atype, NOW()
FROM (
  SELECT 'Aetna Medicare' AS brand, 'H1609' AS alias, 'contract' AS atype UNION ALL
  SELECT 'Aetna Medicare', 'H5521', 'contract' UNION ALL
  SELECT 'Aetna Medicare', 'AETNA HEALTH INC. (FL)', 'org' UNION ALL
  SELECT 'Aetna Medicare', 'AETNA LIFE INSURANCE COMPANY', 'org' UNION ALL
  SELECT 'Aetna Medicare', 'AETNA HEALTH INC.', 'org' UNION ALL
  SELECT 'Humana', 'HUMANA MEDICAL PLAN, INC.', 'org' UNION ALL
  SELECT 'Humana', 'HUMANA INSURANCE COMPANY', 'org' UNION ALL
  SELECT 'Humana', 'HUMANA HEALTH PLAN, INC.', 'org' UNION ALL
  SELECT 'UnitedHealthcare', 'UNITEDHEALTHCARE OF FLORIDA, INC.', 'org' UNION ALL
  SELECT 'UnitedHealthcare', 'UNITEDHEALTHCARE INSURANCE COMPANY', 'org' UNION ALL
  SELECT 'Wellcare', 'WELLCARE OF FLORIDA, INC.', 'org' UNION ALL
  SELECT 'Wellcare', 'WELLCARE HEALTH PLANS OF FLORIDA, INC.', 'org' UNION ALL
  SELECT 'Cigna Healthcare', 'CIGNA HEALTHCARE OF FLORIDA, INC.', 'org' UNION ALL
  SELECT 'CarePlus Health Plans', 'CAREPLUS HEALTH PLANS, INC.', 'org'
) x
JOIN platform_carriers pc ON pc.name = x.brand;

-- Sync run detail: a JSON breakdown of exactly what each run changed, for the sync report view.
ALTER TABLE cms_sync_log ADD COLUMN IF NOT EXISTS detail MEDIUMTEXT NULL AFTER message;
