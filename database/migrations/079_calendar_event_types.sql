-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 079_calendar_event_types.sql
-- Configurable calendar event types. Replaces the old hardcoded, insurance-flavoured kind list
-- (which included SOA/Carrier) with a tenant-editable set. Manual events reference a type by its
-- code; auto-generated events (credential-expiry deadlines) use the system 'deadline' type.
-- Seeded into every healthcare tenant; new signups get the same set via registration seeding.

CREATE TABLE IF NOT EXISTS calendar_event_types (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id  CHAR(36)     NOT NULL,
  code       VARCHAR(24)  NOT NULL,
  label      VARCHAR(60)  NOT NULL,
  color      VARCHAR(7)   NULL,
  is_system  TINYINT(1)   NOT NULL DEFAULT 0,   -- system types (deadline) can't be deleted
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort       INT          NOT NULL DEFAULT 100,
  created_at DATETIME     NOT NULL,
  UNIQUE KEY uq_cet_code (tenant_id, code),
  KEY idx_cet_tenant (tenant_id),
  CONSTRAINT fk_cet_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO calendar_event_types (id, tenant_id, code, label, color, is_system, is_active, sort, created_at)
SELECT UUID(), t.id, s.code, s.label, s.color, s.is_system, 1, s.sort, UTC_TIMESTAMP()
FROM tenants t
CROSS JOIN (
  SELECT 'deadline'   AS code, 'Compliance deadline' AS label, '#d5831f' AS color, 1 AS is_system, 10 AS sort
  UNION ALL SELECT 'meeting',    'Meeting',      '#2f6fd6', 0, 20
  UNION ALL SELECT 'appointment','Appointment',  '#1f9d63', 0, 30
  UNION ALL SELECT 'call',       'Call',         '#7a5bd6', 0, 40
  UNION ALL SELECT 'renewal',    'Renewal',      '#d5831f', 0, 50
  UNION ALL SELECT 'site_visit', 'Site visit',   '#2f9db0', 0, 60
  UNION ALL SELECT 'note',       'Note',         '#5b6575', 0, 70
) s
WHERE t.vertical = 'healthcare'
  AND NOT EXISTS (SELECT 1 FROM calendar_event_types x WHERE x.tenant_id = t.id AND x.code = s.code);

-- The original calendar table pinned `kind` to a fixed insurance-era list via a CHECK constraint;
-- configurable types make that obsolete. Drop it if present (idempotent).
ALTER TABLE calendar_events DROP CONSTRAINT chk_cal_kind;
