-- FlxCoreHC — Calendar & client-interaction system.
-- Unifies tasks, events, client interactions, and compliance deadlines into one
-- timeline an agent can see and act on. Sources of events:
--   * manual tasks/events the agent creates
--   * client interactions (calls, appointments, SOAs) — beneficiary name encrypted
--   * auto-generated compliance deadlines (credential expiries, requirement due dates,
--     CMS season milestones) materialized by a sync routine.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS calendar_events (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  holder_id     CHAR(36)     NULL,                 -- agent the event belongs to (optional)
  kind          VARCHAR(24)  NOT NULL DEFAULT 'task', -- task|appointment|client_call|soa|deadline|cms_milestone|carrier|note
  source        VARCHAR(16)  NOT NULL DEFAULT 'manual', -- manual|credential|requirement|cms|carrier
  source_ref    CHAR(36)     NULL,                 -- id of the originating record (for auto events)
  title         VARCHAR(200) NOT NULL,
  detail        TEXT         NULL,
  -- client interaction fields (beneficiary identity encrypted at rest)
  client_name_enc VARBINARY(512) NULL,
  policy_id     CHAR(36)     NULL,
  carrier_id    CHAR(36)     NULL,
  -- scheduling
  due_at        DATETIME     NOT NULL,
  end_at        DATETIME     NULL,
  all_day       TINYINT(1)   NOT NULL DEFAULT 1,
  priority      VARCHAR(10)  NOT NULL DEFAULT 'normal', -- low|normal|high
  status        VARCHAR(12)  NOT NULL DEFAULT 'open',    -- open|done|cancelled
  completed_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    DATETIME     NULL DEFAULT NULL,
  INDEX idx_cal_tenant_due (tenant_id, due_at),
  INDEX idx_cal_status (tenant_id, status),
  UNIQUE KEY uq_cal_source (tenant_id, source, source_ref),  -- one auto-event per source record
  CONSTRAINT fk_cal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT chk_cal_kind CHECK (kind IN ('task','appointment','client_call','soa','deadline','cms_milestone','carrier','note')),
  CONSTRAINT chk_cal_status CHECK (status IN ('open','done','cancelled')),
  CONSTRAINT chk_cal_priority CHECK (priority IN ('low','normal','high'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
