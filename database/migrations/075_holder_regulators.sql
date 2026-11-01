-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 075_holder_regulators.sql
-- Which regulators each provider (or the organization entity) must answer to. Assigning a regulator
-- to a provider means the provider inherits that regulator's requirement list in their compliance
-- view — Dr. Rivera answering to CMS, the PR board and Triple-S automatically owes each of their
-- requirements, without hand-assigning every credential.

CREATE TABLE IF NOT EXISTS holder_regulators (
  id           CHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)  NOT NULL,
  holder_id    CHAR(36)  NOT NULL,
  regulator_id CHAR(36)  NOT NULL,
  created_at   DATETIME  NOT NULL,
  UNIQUE KEY uq_holder_regulator (tenant_id, holder_id, regulator_id),
  KEY idx_hr_holder (tenant_id, holder_id),
  KEY idx_hr_regulator (tenant_id, regulator_id),
  CONSTRAINT fk_hr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
