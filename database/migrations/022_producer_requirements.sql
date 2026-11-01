-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Per-producer assignment of required credentials (from credential_catalog).
-- Drives the credential-compliance view: each producer's assigned requirements
-- are checked against the credentials on file.
CREATE TABLE IF NOT EXISTS producer_requirements (
  id           CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)    NOT NULL,
  holder_id    CHAR(36)    NOT NULL,
  catalog_code VARCHAR(60) NOT NULL,
  created_at   DATETIME    NOT NULL,
  UNIQUE KEY uq_producer_req (tenant_id, holder_id, catalog_code),
  INDEX idx_pr_holder (tenant_id, holder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
