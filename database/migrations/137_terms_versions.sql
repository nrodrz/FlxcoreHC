-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 137_terms_versions.sql — v1.173.3: the Terms of Use can be edited by the platform operator (Console → Terms of Use).
--   Every save is a NEW version row (never edited, never deleted); the newest row is the current Terms. With no row, the Terms
--   shipped in app/terms/en.php and es.php apply. A new version makes every user accept again at their next sign-in.
CREATE TABLE IF NOT EXISTS terms_versions (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  version    VARCHAR(40) NOT NULL,
  content    MEDIUMTEXT  NOT NULL,
  created_by CHAR(36)    NOT NULL,
  created_at DATETIME    NOT NULL,
  UNIQUE KEY uq_terms_version (version),
  KEY idx_terms_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
