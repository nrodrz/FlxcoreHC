-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 119_field_level_security.sql
-- Field-level security, levels 2 and 3 (v1.158).
--  Level 2 — per-field rules in the role editor: a role can be granted or denied specific sensitive
--            fields, finer than the blanket `pii.view` capability. Stored as role → field grants.
--  Level 3 — tenant-defined custom fields can be marked sensitive: encrypted at rest, masked unless
--            the viewer holds pii.view (or a per-field grant), and excluded from packets/exports.
CREATE TABLE IF NOT EXISTS role_field_grants (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id  CHAR(36)    NOT NULL,
  role_slug  VARCHAR(40) NOT NULL,
  field_key  VARCHAR(80) NOT NULL,      -- 'dob', 'ssn_last4', or 'cf:<definition_id>'
  allowed    TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME    NOT NULL,
  UNIQUE KEY uq_rfg (tenant_id, role_slug, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE custom_field_definitions ADD COLUMN is_sensitive TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required;
-- Sensitive custom-field values are stored encrypted in this column; value_text stays NULL for them.
ALTER TABLE custom_field_values ADD COLUMN value_enc VARBINARY(4096) NULL AFTER value_text;
