-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 094_locations.sql
-- Practice locations within a tenant. A billing company (or group/lab/clinic) commonly manages
-- providers across several physical sites; this lets a tenant define its locations and affiliate
-- providers with one or more of them. Purely additive — existing providers simply have no location
-- affiliations until one is added, and nothing about current behavior changes.
--
-- This is also the in-tenant foundation for the (separately scoped) cross-tenant multi-org work: a
-- "practice a provider is credentialed at" is a location. Cross-tenant identity linkage is deliberately
-- NOT modeled here.

CREATE TABLE IF NOT EXISTS locations (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  name        VARCHAR(160) NOT NULL,
  npi         CHAR(10)     NULL,               -- optional Type-2 (organizational) NPI of the site
  address1    VARCHAR(160) NULL,
  address2    VARCHAR(160) NULL,
  city        VARCHAR(100) NULL,
  state       CHAR(2)      NULL,
  zip         VARCHAR(10)  NULL,
  phone       VARCHAR(40)  NULL,
  status      VARCHAR(12)  NOT NULL DEFAULT 'active',  -- active | inactive
  note        VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL,
  deleted_at  DATETIME     NULL,
  KEY idx_locations_tenant (tenant_id, status),
  KEY idx_locations_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which providers practice at which locations (many-to-many within the tenant).
CREATE TABLE IF NOT EXISTS holder_locations (
  id           CHAR(36)  NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)  NOT NULL,
  holder_id    CHAR(36)  NOT NULL,
  location_id  CHAR(36)  NOT NULL,
  role_at_site VARCHAR(120) NULL,              -- e.g. "Attending", "Supervising physician"
  is_primary   TINYINT(1) NOT NULL DEFAULT 0,  -- the provider's primary practice site
  affiliated_at DATETIME NOT NULL,
  affiliated_by CHAR(36) NULL,
  UNIQUE KEY uq_holder_location (tenant_id, holder_id, location_id),
  KEY idx_hl_holder (tenant_id, holder_id),
  KEY idx_hl_location (tenant_id, location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
