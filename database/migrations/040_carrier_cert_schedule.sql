-- Carrier certification schedule — the maintained calendar of when each carrier's annual
-- Medicare certification opens, what platform it runs on, and the link to start it.
--
-- This turns the AEP readiness screen from "here's what's missing" into "here's what's
-- missing and here's exactly where to go fix it." Dates change year to year and mid-season,
-- so this is an admin-editable table (not hard-coded), seeded with verified PY2027 dates.
--
-- Verified PY2027 sources (retrieved 2026-07): AHIP/NABIP open 2026-06-22; UnitedHealthcare
-- 2026-06-08; Aetna/Devoted 2026-06-23; Cigna HealthSpring 2026-07-01; Humana 2026-07-08;
-- Wellcare/Centene 2026-07-21. AEP begins 2026-10-15. Agents must always confirm against the
-- carrier's own portal — these are planning aids, not guarantees.

CREATE TABLE IF NOT EXISTS carrier_cert_schedule (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  plan_year    SMALLINT     NOT NULL,
  carrier      VARCHAR(120) NOT NULL,
  platform     VARCHAR(60)  NULL,           -- AHIP | NABIP | Miramar | Pinpoint | Carrier portal | Upline
  opens_on     DATE         NULL,
  closes_on    DATE         NULL,           -- last day to certify, if published
  cert_url     VARCHAR(300) NULL,
  requires_ahip TINYINT(1)  NOT NULL DEFAULT 1,
  notes        VARCHAR(300) NULL,
  is_seed      TINYINT(1)   NOT NULL DEFAULT 0,
  sort         SMALLINT     NOT NULL DEFAULT 100,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NULL,
  INDEX idx_ccs_tenant (tenant_id, plan_year, sort),
  CONSTRAINT fk_ccs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
