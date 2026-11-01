-- FlxCoreHC — Book of Business: policies, carrier interactions, commissions/payments.
-- POLICY/commercial view only — NO clinical/health-management data.
-- Beneficiary identifiers are stored ENCRYPTED (client_name_enc, client_mbi_enc) via
-- the Crypto service; a blind index on the member id supports exact-match lookup.
-- Financial amounts are clear (DECIMAL) so they can be aggregated in SQL.
SET NAMES utf8mb4;

-- The agent's sold policies (the book of business).
CREATE TABLE IF NOT EXISTS policies (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)     NOT NULL,
  holder_id       CHAR(36)     NOT NULL,            -- writing agent (individual holder)
  carrier_id      CHAR(36)     NOT NULL,
  -- beneficiary identity (encrypted at rest)
  client_name_enc VARBINARY(512) NULL,
  client_mbi_enc  VARBINARY(255) NULL,             -- Medicare Beneficiary Id (sensitive)
  client_mbi_bidx CHAR(64)     NULL,               -- blind index for lookup
  client_ref      VARCHAR(60)  NULL,               -- agency's own non-PHI reference, optional
  -- policy/commercial detail
  plan_name       VARCHAR(160) NOT NULL,
  plan_type       VARCHAR(20)  NOT NULL DEFAULT 'MAPD', -- MAPD|MA|PDP|MEDSUPP|OTHER
  plan_year       SMALLINT     NULL,
  policy_number   VARCHAR(80)  NULL,               -- carrier-assigned member/policy number
  effective_date  DATE         NULL,
  term_date       DATE         NULL,               -- disenrollment / lapse
  status          VARCHAR(20)  NOT NULL DEFAULT 'active', -- pending|active|lapsed|disenrolled|cancelled
  premium         DECIMAL(10,2) NULL,
  notes           TEXT         NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME     NULL DEFAULT NULL,
  INDEX idx_pol_tenant (tenant_id, status),
  INDEX idx_pol_holder (tenant_id, holder_id),
  INDEX idx_pol_mbi_bidx (tenant_id, client_mbi_bidx),
  CONSTRAINT fk_pol_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_pol_holder  FOREIGN KEY (tenant_id, holder_id) REFERENCES credential_holders(tenant_id, id),
  CONSTRAINT fk_pol_carrier FOREIGN KEY (tenant_id, carrier_id) REFERENCES carriers(tenant_id, id),
  CONSTRAINT chk_pol_type   CHECK (plan_type IN ('MAPD','MA','PDP','MEDSUPP','OTHER')),
  UNIQUE KEY uq_pol_tenant (tenant_id, id),
  CONSTRAINT chk_pol_status CHECK (status IN ('pending','active','lapsed','disenrolled','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commission / payment records, tied to a policy.
CREATE TABLE IF NOT EXISTS commissions (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)     NOT NULL,
  policy_id       CHAR(36)     NOT NULL,
  holder_id       CHAR(36)     NOT NULL,            -- agent credited (rollup convenience)
  carrier_id      CHAR(36)     NOT NULL,
  comp_type       VARCHAR(20)  NOT NULL DEFAULT 'initial', -- initial|renewal|override|bonus
  period          VARCHAR(7)   NULL,                -- YYYY-MM the payment covers
  amount_expected DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount_received DECIMAL(10,2) NOT NULL DEFAULT 0,
  status          VARCHAR(20)  NOT NULL DEFAULT 'expected', -- expected|received|partial|discrepancy|chargeback
  paid_date       DATE         NULL,
  statement_ref   VARCHAR(80)  NULL,
  notes           TEXT         NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME     NULL DEFAULT NULL,
  INDEX idx_comm_tenant (tenant_id, status),
  INDEX idx_comm_holder (tenant_id, holder_id),
  INDEX idx_comm_policy (tenant_id, policy_id),
  CONSTRAINT fk_comm_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_comm_policy  FOREIGN KEY (tenant_id, policy_id) REFERENCES policies(tenant_id, id),
  CONSTRAINT fk_comm_holder  FOREIGN KEY (tenant_id, holder_id) REFERENCES credential_holders(tenant_id, id),
  CONSTRAINT fk_comm_carrier FOREIGN KEY (tenant_id, carrier_id) REFERENCES carriers(tenant_id, id),
  CONSTRAINT chk_comm_type   CHECK (comp_type IN ('initial','renewal','override','bonus')),
  CONSTRAINT chk_comm_status CHECK (status IN ('expected','received','partial','discrepancy','chargeback'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lightweight carrier interactions log (statements, issues, communications, agreements).
CREATE TABLE IF NOT EXISTS carrier_interactions (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  carrier_id    CHAR(36)     NOT NULL,
  holder_id     CHAR(36)     NULL,                 -- optional: which agent
  kind          VARCHAR(20)  NOT NULL DEFAULT 'note', -- statement|issue|communication|agreement|note
  occurred_on   DATE         NULL,
  subject       VARCHAR(200) NOT NULL,
  detail        TEXT         NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'open', -- open|resolved
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    DATETIME     NULL DEFAULT NULL,
  INDEX idx_ci_tenant (tenant_id, status),
  CONSTRAINT fk_ci_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_ci_carrier FOREIGN KEY (tenant_id, carrier_id) REFERENCES carriers(tenant_id, id),
  CONSTRAINT chk_ci_kind   CHECK (kind IN ('statement','issue','communication','agreement','note')),
  CONSTRAINT chk_ci_status CHECK (status IN ('open','resolved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
