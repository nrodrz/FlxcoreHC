-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 087_medicaid_exclusions.sql
-- Local copy of state/territory Medicaid exclusion lists, starting with Puerto Rico (PR). Many states
-- publish their own Medicaid exclusion list of individuals/entities barred from Medicaid participation,
-- separate from the federal OIG LEIE and SAM.gov lists and in wildly varying formats. This holds any
-- of them, tagged by jurisdiction (a 2-letter state/territory code), so PR works now and other states
-- drop in with no new code.
--
-- A match is a REVIEW SIGNAL stamped with the list vintage, never a verdict; identity is confirmed at
-- the issuing agency before any action. Rows are platform-global.

CREATE TABLE IF NOT EXISTS medicaid_imports (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  jurisdiction CHAR(2)      NOT NULL,          -- 'PR', 'FL', 'TX', ...
  file_month   CHAR(7)      NOT NULL,          -- YYYY-MM vintage
  row_count    INT          NOT NULL DEFAULT 0,
  source_note  VARCHAR(120) NULL,
  imported_by  CHAR(36)     NULL,
  imported_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_medicaid_juris_month (jurisdiction, file_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS medicaid_exclusions (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  import_id     CHAR(36)     NOT NULL,
  jurisdiction  CHAR(2)      NOT NULL,
  last_name     VARCHAR(120) NULL,
  first_name    VARCHAR(120) NULL,
  business_name VARCHAR(200) NULL,
  npi           CHAR(10)     NULL,             -- often present on Medicaid lists (may be blank)
  provider_type VARCHAR(120) NULL,
  excl_reason   VARCHAR(200) NULL,
  excl_date     VARCHAR(20)  NULL,
  name_key      VARCHAR(200) NULL,
  KEY idx_medicaid_npi (npi),
  KEY idx_medicaid_namekey (name_key),
  KEY idx_medicaid_juris (jurisdiction),
  KEY idx_medicaid_import (import_id),
  CONSTRAINT fk_medicaid_import FOREIGN KEY (import_id) REFERENCES medicaid_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
