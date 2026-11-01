-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 086_sam_exclusions.sql
-- Local copy of the SAM.gov (System for Award Management) exclusions list — the government-wide
-- debarment/suspension list that succeeded EPLS. Screened alongside OIG LEIE. SAM offers both a REST
-- API and a downloadable public extract; both require a free SAM.gov API key, so the paste/upload
-- path here works without one.
--
-- SAM records do NOT carry an NPI (SAM is government-wide, not healthcare-specific), so provider
-- matching against SAM is name-based only — a weaker signal than an NPI match, and therefore always a
-- REVIEW SIGNAL for human confirmation at sam.gov, never a verdict. Rows are platform-global.

CREATE TABLE IF NOT EXISTS sam_imports (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  file_month  CHAR(7)      NOT NULL,           -- YYYY-MM vintage the file represents
  row_count   INT          NOT NULL DEFAULT 0,
  source_note VARCHAR(120) NULL,
  imported_by CHAR(36)     NULL,
  imported_at DATETIME     NOT NULL,
  UNIQUE KEY uq_sam_month (file_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sam_exclusions (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  import_id      CHAR(36)     NOT NULL,
  classification VARCHAR(30)  NULL,            -- Firm | Individual | Vessel | Special Entity Designation
  entity_name    VARCHAR(200) NULL,            -- firm / entity name
  first_name     VARCHAR(120) NULL,            -- individuals
  last_name      VARCHAR(120) NULL,
  middle_name    VARCHAR(60)  NULL,
  uei            VARCHAR(20)  NULL,            -- Unique Entity ID (firms)
  cage           VARCHAR(20)  NULL,
  excl_type      VARCHAR(120) NULL,            -- Exclusion Type
  excl_program   VARCHAR(120) NULL,            -- Exclusion Program (e.g. Reciprocal, Procurement)
  excl_agency    VARCHAR(120) NULL,            -- Excluding Agency
  active_date    VARCHAR(20)  NULL,
  state          VARCHAR(10)  NULL,
  name_key       VARCHAR(200) NULL,            -- normalized entity name or 'LAST|FIRST' for matching
  KEY idx_sam_namekey (name_key),
  KEY idx_sam_uei (uei),
  KEY idx_sam_import (import_id),
  CONSTRAINT fk_sam_import FOREIGN KEY (import_id) REFERENCES sam_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
