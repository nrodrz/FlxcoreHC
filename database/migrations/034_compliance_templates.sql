-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Compliance templates: the missing middle layer.
--
-- Until now a producer's requirements came from an implicit "role default" — every
-- catalog item flagged is_required for their role. Real agencies think in terms of
-- LICENCE TYPES: a Florida 2-15 Medicare agent needs one bundle, a 2-20 commercial
-- producer another, a 4-40 CSR a third. A template is a named, reusable, editable
-- bundle of requirements anchored to a licence, which an agency applies to a producer.

CREATE TABLE IF NOT EXISTS requirement_templates (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  name         VARCHAR(120) NOT NULL,
  description  VARCHAR(400) NULL,
  applies_to   VARCHAR(20)  NOT NULL DEFAULT 'agent',   -- agent | agency | fmo
  line         VARCHAR(20)  NOT NULL DEFAULT 'all',     -- medicare | commercial | all
  license_code VARCHAR(40)  NULL,                       -- catalog code of the anchoring licence
  is_seed      TINYINT(1)   NOT NULL DEFAULT 0,         -- shipped by FlxCoreHC (editable, but marked)
  sort         INT          NOT NULL DEFAULT 100,
  created_at   DATETIME     NOT NULL,
  INDEX idx_rt_tenant (tenant_id, applies_to),
  CONSTRAINT fk_rt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requirement_template_items (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  template_id CHAR(36)     NOT NULL,
  token       VARCHAR(120) NOT NULL,   -- catalog code | code@carrierId | cr:<carrier_requirement_id>
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_rti (template_id, token),
  INDEX idx_rti_tenant (tenant_id, template_id),
  CONSTRAINT fk_rti_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A producer records the licence they actually hold, and which template governs them.
ALTER TABLE credential_holders
  ADD COLUMN license_code VARCHAR(40) NULL,
  ADD COLUMN template_id  CHAR(36)    NULL;
