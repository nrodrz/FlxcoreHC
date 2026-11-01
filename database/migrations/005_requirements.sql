-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FlxCoreHC — Compliance Requirements engine (per agent & per agency)
-- Distinct from `credentials` (documents/licenses) and `compliance_items` (org-level
-- platform/security checklist). Tracks recurring REGULATORY obligations each agent
-- and each agency must keep current — CMS marketing rules, annual trainings,
-- attestations, retention obligations.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS requirement_defs (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NULL,
  authority     VARCHAR(40)  NOT NULL DEFAULT 'CMS',
  applies_to    VARCHAR(20)  NOT NULL DEFAULT 'agent',
  vertical      VARCHAR(20)  NOT NULL DEFAULT 'insurance',
  code          VARCHAR(50)  NOT NULL,
  title         VARCHAR(200) NOT NULL,
  description   TEXT         NULL,
  cadence       VARCHAR(20)  NOT NULL DEFAULT 'annual',
  evidence_kind VARCHAR(20)  NOT NULL DEFAULT 'attestation',
  satisfied_by_credential_type VARCHAR(80) NULL,
  severity      VARCHAR(20)  NOT NULL DEFAULT 'required',
  grace_days    INT          NOT NULL DEFAULT 0,
  sort_order    INT          NOT NULL DEFAULT 100,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL,
  INDEX idx_reqdef_scope (tenant_id, vertical, applies_to, active),
  CONSTRAINT chk_reqdef_applies CHECK (applies_to IN ('agent','agency','both')),
  CONSTRAINT chk_reqdef_cadence CHECK (cadence IN ('annual','plan_year','once','ongoing')),
  CONSTRAINT chk_reqdef_evidence CHECK (evidence_kind IN ('credential','attestation','document','policy')),
  CONSTRAINT chk_reqdef_sev CHECK (severity IN ('required','recommended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holder_requirements (
  id              CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)    NOT NULL,
  holder_id       CHAR(36)    NOT NULL,
  requirement_id  CHAR(36)    NOT NULL,
  plan_year       SMALLINT    NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'open',
  completed_on    DATE        NULL,
  expires_on      DATE        NULL,
  evidence_url    VARCHAR(500) NULL,
  note            TEXT        NULL,
  updated_by      CHAR(36)    NULL,
  created_at      DATETIME    NOT NULL,
  updated_at      DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME    NULL DEFAULT NULL,
  UNIQUE KEY uq_holder_req_py (tenant_id, holder_id, requirement_id, plan_year),
  INDEX idx_hreq_tenant (tenant_id, status),
  INDEX idx_hreq_holder (tenant_id, holder_id),
  CONSTRAINT fk_hreq_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_hreq_holder FOREIGN KEY (tenant_id, holder_id) REFERENCES credential_holders(tenant_id, id),
  CONSTRAINT fk_hreq_def    FOREIGN KEY (requirement_id) REFERENCES requirement_defs(id),
  CONSTRAINT chk_hreq_status CHECK (status IN ('open','in_progress','met','waived','overdue'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
