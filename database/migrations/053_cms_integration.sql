-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- CMS data integration: richer plan attributes from the MA/Part D Landscape files, plus the
-- config + audit tables that drive automated (cron) and manual refreshes, and an NPI cache.

-- 1. Enrich the plan catalog to hold what the CMS landscape/enrollment files carry.
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS segment_id   VARCHAR(8)   NULL AFTER pbp;
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS org_name     VARCHAR(180) NULL AFTER carrier_name;   -- CMS contract organization name
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS cms_plan_type VARCHAR(48) NULL AFTER plan_type;      -- raw CMS type, e.g. "Local HMO"
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS part_d       TINYINT(1)   NOT NULL DEFAULT 0 AFTER cms_plan_type; -- includes Part D drug coverage
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS snp_type     VARCHAR(12)  NULL AFTER part_d;          -- DSNP/CSNP/ISNP/NULL
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS premium      DECIMAL(7,2) NULL AFTER snp_type;        -- monthly consolidated premium
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS star_rating  DECIMAL(2,1) NULL AFTER premium;         -- overall star rating
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS service_area VARCHAR(255) NULL AFTER star_rating;     -- e.g. "20 FL counties"
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS cms_synced_at DATETIME    NULL AFTER updated_at;      -- last touched by a CMS sync

-- 2. Configured CMS sources the sync fetches (one row per data type + year + state).
CREATE TABLE IF NOT EXISTS cms_sync_source (
  id             CHAR(36)     NOT NULL,
  data_type      VARCHAR(24)  NOT NULL DEFAULT 'landscape',  -- 'landscape' | 'enrollment'
  plan_year      SMALLINT     NOT NULL,
  state          VARCHAR(4)   NOT NULL DEFAULT 'FL',
  url            VARCHAR(512) NOT NULL,                       -- direct URL to CSV / ZIP / gzip
  label          VARCHAR(120) NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  last_synced_at DATETIME     NULL,
  last_status    VARCHAR(16)  NULL,                           -- 'ok' | 'error'
  last_result    VARCHAR(255) NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_active (active, data_type, plan_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Audit log of every sync run (cron or manual).
CREATE TABLE IF NOT EXISTS cms_sync_log (
  id          CHAR(36)    NOT NULL,
  source_id   CHAR(36)    NULL,
  data_type   VARCHAR(24) NULL,
  plan_year   SMALLINT    NULL,
  trigger_by  VARCHAR(16) NULL,        -- 'cron' | 'admin'
  status      VARCHAR(16) NOT NULL DEFAULT 'ok',
  added       INT         NOT NULL DEFAULT 0,
  updated     INT         NOT NULL DEFAULT 0,
  skipped     INT         NOT NULL DEFAULT 0,
  message     VARCHAR(500) NULL,
  started_at  DATETIME    NOT NULL,
  finished_at DATETIME    NULL,
  PRIMARY KEY (id),
  KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Cache for NPPES NPI Registry lookups (the API is slow + rate-limited, so cache aggressively).
CREATE TABLE IF NOT EXISTS npi_cache (
  npi          VARCHAR(10)  NOT NULL,
  entity_type  VARCHAR(12)  NULL,        -- 'individual' | 'organization'
  name         VARCHAR(200) NULL,
  credential   VARCHAR(60)  NULL,
  taxonomy     VARCHAR(160) NULL,
  city         VARCHAR(80)  NULL,
  state        VARCHAR(4)   NULL,
  payload      MEDIUMTEXT   NULL,        -- raw JSON for anything else we need later
  found        TINYINT(1)   NOT NULL DEFAULT 0,
  fetched_at   DATETIME     NOT NULL,
  PRIMARY KEY (npi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
