-- Producer self-service portal + CE hour tracking.
-- users.holder_id already exists in the base schema; this adds the CE ledger
-- and the birth month that anchors Florida's biennial CE cycle (F.S. 626.2815).
ALTER TABLE credential_holders
  ADD COLUMN birth_month TINYINT NULL;

CREATE TABLE IF NOT EXISTS ce_entries (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id      CHAR(36)     NOT NULL,
  holder_id      CHAR(36)     NOT NULL,
  course_name    VARCHAR(200) NOT NULL,
  provider       VARCHAR(160) NULL,
  hours          DECIMAL(4,1) NOT NULL DEFAULT 0,
  category       VARCHAR(30)  NOT NULL DEFAULT 'general',  -- general | law_ethics | flood | other
  completed_date DATE         NOT NULL,
  created_at     DATETIME     NOT NULL,
  INDEX idx_ce_tenant (tenant_id, holder_id, completed_date),
  CONSTRAINT fk_ce_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allow the new self-service role past the users role CHECK constraint.
ALTER TABLE users DROP CONSTRAINT chk_user_role;
ALTER TABLE users
  ADD CONSTRAINT chk_user_role CHECK (role IN ('tenant_owner','manager','staff','provider_client','agent','producer'));
