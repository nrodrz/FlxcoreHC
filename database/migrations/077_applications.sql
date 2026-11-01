-- 077_applications.sql
-- Slice 5: Application / Enrollment tracking — the "in flight" submission the strategy doc's
-- Enrollment Center and Credentialing Workspace track (PECOS/PRMP/payer applications moving
-- draft → submitted → under review → approved). Each application is a provider's submission to a
-- regulator/payer/program, carried through a status lifecycle with an append-only status history.

CREATE TABLE IF NOT EXISTS applications (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  holder_id     CHAR(36)     NOT NULL,
  regulator_id  CHAR(36)     NULL,                  -- which payer/agency/program (Regulator model)
  kind          VARCHAR(20)  NOT NULL DEFAULT 'enrollment', -- enrollment|credentialing|recredentialing|revalidation|other
  title         VARCHAR(160) NOT NULL,              -- e.g. "Medicare enrollment", "Aetna credentialing"
  reference     VARCHAR(80)  NULL,                  -- external tracking / application number (PECOS ID…)
  status        VARCHAR(20)  NOT NULL DEFAULT 'not_started', -- not_started|in_progress|submitted|under_review|approved|denied|withdrawn
  submitted_on  DATE         NULL,
  decision_on   DATE         NULL,
  due_on        DATE         NULL,                  -- target / revalidation deadline
  notes         VARCHAR(600) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_app_holder (tenant_id, holder_id),
  KEY idx_app_status (tenant_id, status),
  CONSTRAINT fk_app_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_status_history (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id      CHAR(36)     NOT NULL,
  application_id CHAR(36)     NOT NULL,
  status         VARCHAR(20)  NOT NULL,
  note           VARCHAR(400) NULL,
  changed_by     VARCHAR(160) NULL,
  changed_by_user CHAR(36)    NULL,
  changed_at     DATETIME     NOT NULL,
  KEY idx_ash_app (tenant_id, application_id),
  CONSTRAINT fk_ash_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
