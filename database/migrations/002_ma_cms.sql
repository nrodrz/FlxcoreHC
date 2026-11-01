-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FlxCoreHC v1.1 — Medicare Advantage / CMS compliance layer
-- Carriers + plan-year credentials. Run once on existing installs.

CREATE TABLE IF NOT EXISTS carriers (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  name        VARCHAR(120) NOT NULL,
  short_name  VARCHAR(20)  NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_at  DATETIME     NOT NULL,
  INDEX idx_carriers_tenant (tenant_id, status),
  CONSTRAINT fk_carriers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE credentials
  ADD COLUMN plan_year SMALLINT NULL AFTER category,
  ADD COLUMN carrier_id CHAR(36) NULL AFTER plan_year,
  ADD INDEX idx_cred_plan_year (tenant_id, plan_year),
  ADD CONSTRAINT fk_cred_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id);
