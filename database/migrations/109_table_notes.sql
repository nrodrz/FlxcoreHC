-- 109_table_notes.sql
-- Operator notes against the data dictionary. The dictionary itself is generated live from the
-- schema; this table stores the platform team's own annotations (why a table exists, gotchas,
-- retention decisions) so institutional knowledge lives with the product rather than in a doc.
CREATE TABLE IF NOT EXISTS schema_notes (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  table_name VARCHAR(64)  NOT NULL,
  note       TEXT         NULL,
  updated_by CHAR(36)     NULL,
  updated_at DATETIME     NOT NULL,
  UNIQUE KEY uq_sn_table (table_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
