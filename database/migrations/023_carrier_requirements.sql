-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Carrier-specific compliance:
-- 1) carrier_requirements — each carrier's own extra requirements (tenant-defined).
-- 2) producer_requirements gains a carrier dimension so items like
--    "Humana certification" and "UnitedHealthcare certification" track separately.
CREATE TABLE IF NOT EXISTS carrier_requirements (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id      CHAR(36)     NOT NULL,
  carrier_id     CHAR(36)     NOT NULL,
  name           VARCHAR(160) NOT NULL,
  category       VARCHAR(40)  NOT NULL DEFAULT 'certification',
  renewal_months INT          NULL,
  notes          VARCHAR(400) NULL,
  created_at     DATETIME     NOT NULL,
  INDEX idx_carrier_req (tenant_id, carrier_id),
  CONSTRAINT fk_carrier_req_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE producer_requirements
  ADD COLUMN carrier_id CHAR(36) NULL AFTER catalog_code;
ALTER TABLE producer_requirements DROP INDEX uq_producer_req;
ALTER TABLE producer_requirements
  ADD UNIQUE KEY uq_producer_req (tenant_id, holder_id, catalog_code, carrier_id);
