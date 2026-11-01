-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Training Center: a global reference library (CMS + certification + carrier process)
-- plus a tenant-authored resources table for an agency's own materials & links.
CREATE TABLE IF NOT EXISTS training_library (
  code       VARCHAR(60)  NOT NULL PRIMARY KEY,
  title      VARCHAR(200) NOT NULL,
  category   VARCHAR(40)  NOT NULL,            -- cms | certification | marketing | rts | state | fwa | carrier
  authority  VARCHAR(20)  NULL,               -- cms | state | federal | carrier | ncqa
  summary    VARCHAR(400) NULL,
  body       MEDIUMTEXT   NULL,               -- lightweight markup: paragraphs + "- " bullets
  link       VARCHAR(400) NULL,
  link_label VARCHAR(120) NULL,
  minutes    INT          NULL,               -- estimated read/learn time
  sort       INT          NOT NULL DEFAULT 100,
  updated_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_resources (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id  CHAR(36)     NOT NULL,
  carrier_id CHAR(36)     NULL,
  title      VARCHAR(200) NOT NULL,
  category   VARCHAR(40)  NOT NULL DEFAULT 'carrier',
  body       TEXT         NULL,
  link       VARCHAR(400) NULL,
  created_at DATETIME     NOT NULL,
  INDEX idx_tr_tenant (tenant_id),
  CONSTRAINT fk_tr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
