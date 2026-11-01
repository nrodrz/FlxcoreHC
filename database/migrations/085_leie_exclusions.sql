-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 085_leie_exclusions.sql
-- Local copy of the OIG LEIE (List of Excluded Individuals/Entities). The OIG publishes NO API —
-- it offers a monthly downloadable CSV — so automated screening works by ingesting that file into
-- this table and matching against it. Rows are platform-global (not tenant-scoped): the exclusion
-- list is the same public dataset for everyone.
--
-- A match here is a REVIEW SIGNAL, never a verdict: identity must be confirmed at oig.hhs.gov before
-- any action, and some LEIE rows carry no NPI. Screening records therefore capture the list vintage
-- (leie_imports.file_month) so every result shows exactly which publication it came from.

CREATE TABLE IF NOT EXISTS leie_imports (
  id          CHAR(36)    NOT NULL PRIMARY KEY,
  file_month  CHAR(7)     NOT NULL,           -- YYYY-MM the file represents (its vintage)
  row_count   INT         NOT NULL DEFAULT 0,
  source_note VARCHAR(120) NULL,              -- e.g. 'UPDATED.csv 2026-08' or 'manual upload'
  imported_by CHAR(36)    NULL,
  imported_at DATETIME    NOT NULL,
  UNIQUE KEY uq_leie_month (file_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leie_exclusions (
  id            CHAR(36)    NOT NULL PRIMARY KEY,
  import_id     CHAR(36)    NOT NULL,
  -- Identity columns mirror the LEIE file layout. Names are stored uppercased for matching.
  last_name     VARCHAR(120) NULL,            -- LASTNAME (individuals)
  first_name    VARCHAR(120) NULL,            -- FIRSTNAME
  midname       VARCHAR(60)  NULL,
  business_name VARCHAR(200) NULL,            -- BUSNAME (entities)
  npi           CHAR(10)     NULL,            -- NPI (may be '0000000000'/blank in the file)
  upin          VARCHAR(20)  NULL,
  general       VARCHAR(120) NULL,            -- GENERAL (provider category)
  specialty     VARCHAR(120) NULL,            -- SPECIALTY
  excltype      VARCHAR(20)  NULL,            -- EXCLTYPE (statutory basis, e.g. 1128a1)
  excldate      CHAR(8)      NULL,            -- EXCLDATE (YYYYMMDD)
  state         CHAR(2)      NULL,
  name_key      VARCHAR(200) NULL,            -- normalized 'LAST|FIRST' or business name for matching
  KEY idx_leie_npi (npi),
  KEY idx_leie_namekey (name_key),
  KEY idx_leie_import (import_id),
  CONSTRAINT fk_leie_import FOREIGN KEY (import_id) REFERENCES leie_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
