-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 081_entity_reminders.sql
-- Generic dedup ledger for reminders on entities beyond credentials (applications, screening
-- cadence, attestation cadence). Mirrors credential_reminders but keyed generically by
-- (entity_type, entity_id, marker) so the same reminder never fires twice. The marker encodes
-- the specific trigger instance (e.g. an application's threshold + due date, or a cadence period),
-- so a changed date or a new period starts a fresh cycle.

CREATE TABLE IF NOT EXISTS entity_reminders (
  id          CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)    NOT NULL,
  entity_type VARCHAR(24) NOT NULL,   -- application|screening|attestation
  entity_id   CHAR(36)    NOT NULL,   -- the row (or the holder, for cadence reminders)
  marker      VARCHAR(60) NOT NULL,   -- trigger instance key
  channel     VARCHAR(12) NOT NULL DEFAULT 'email',
  queued_at   DATETIME    NOT NULL,
  sent_at     DATETIME    NULL,
  UNIQUE KEY uq_entity_reminder (tenant_id, entity_type, entity_id, marker),
  CONSTRAINT fk_entrem_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
