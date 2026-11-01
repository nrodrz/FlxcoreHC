-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 078_exclusion_screenings.sql
-- Slice 7: Exclusion / sanctions screening (strategy doc §5, §6, §17 Phase 3). Providers must be
-- screened against federal and state exclusion lists — OIG LEIE, SAM.gov, and state/PR Medicaid
-- exclusion lists — and a clean screening evidenced. The official lists stay the source of truth;
-- this records each screening check (source, date, result, reference, who) with an accumulating
-- history, and feeds a Screening readiness signal. An "excluded" result is a hard stop a billing
-- company must catch before submitting claims.

CREATE TABLE IF NOT EXISTS exclusion_screenings (
  id               CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id        CHAR(36)     NOT NULL,
  holder_id        CHAR(36)     NOT NULL,
  source           VARCHAR(20)  NOT NULL DEFAULT 'oig_leie', -- oig_leie|sam_gov|state_medicaid|pr_medicaid|other
  screened_on      DATE         NOT NULL,
  result           VARCHAR(16)  NOT NULL DEFAULT 'clear',     -- clear|potential_match|excluded
  reference        VARCHAR(120) NULL,                         -- verification #, screenshot ref
  screened_by      VARCHAR(160) NULL,
  screened_by_user CHAR(36)     NULL,
  notes            VARCHAR(400) NULL,
  created_at       DATETIME     NOT NULL,
  KEY idx_scr_holder (tenant_id, holder_id),
  KEY idx_scr_result (tenant_id, result),
  CONSTRAINT fk_scr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
