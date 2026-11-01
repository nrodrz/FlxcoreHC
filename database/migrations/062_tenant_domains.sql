-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Map hostnames to tenants so a white-labelled deployment can serve each agency its own branded
-- sign-in page from the very first visit, before anyone has logged in.
--
-- A separate table rather than a column on tenants, because an agency commonly needs more than one
-- host (apex + www, a legacy domain kept alive during a migration, a staging hostname).
--
-- IMPORTANT: this drives presentation only. The Host header is client-supplied and therefore not
-- trustworthy for authorisation — it selects a logo and a colour palette, never access. Session and
-- tenant scoping remain the sole authority for what anyone can see.
CREATE TABLE IF NOT EXISTS tenant_domains (
  id         CHAR(36)     NOT NULL,
  tenant_id  CHAR(36)     NOT NULL,
  host       VARCHAR(190) NOT NULL,          -- lowercase, no port, no leading "www."
  is_primary TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_domains_host (host),  -- a host can only belong to one tenant
  KEY idx_tenant_domains_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
