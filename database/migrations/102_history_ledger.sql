-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 102_history_ledger.sql
-- Permanent, append-only history so a client's full record is retained across years, not just the
-- current state. Three additions:
--
-- 1) compliance_events — an immutable ledger: every meaningful moment in a provider's compliance life
--    (credential added / renewed / edited / expired, requirement met or lapsed, document uploaded /
--    verified / replaced). Stamped with the actor and time; never updated or deleted. This is the
--    "when did X happen and who did it" record, queryable by year/month.
-- 2) credential_documents.supersedes_id — links a renewed document to the one it replaces, so the
--    renewal chain over the years is a visible timeline. Old documents are retained (soft-deleted),
--    never purged.
-- 3) audit_logs already stores a `changes` JSON; from now on edits write before→after there, so the
--    prior values are preserved. No schema change needed for that, only in the application.

CREATE TABLE IF NOT EXISTS compliance_events (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  holder_id    CHAR(36)     NULL,               -- the provider this concerns (null for org-level)
  credential_id CHAR(36)    NULL,
  document_id  CHAR(36)     NULL,
  event_type   VARCHAR(40)  NOT NULL,           -- credential_added|credential_renewed|credential_edited|
                                                -- credential_removed|credential_expired|requirement_met|
                                                -- requirement_lapsed|document_uploaded|document_verified|
                                                -- document_replaced|document_removed|status_changed
  summary      VARCHAR(300) NOT NULL,           -- human-readable, kept verbatim for the record
  detail       JSON         NULL,               -- structured before/after or context
  effective_on DATE         NULL,               -- the date the fact applies to (e.g. met date)
  actor_id     CHAR(36)     NULL,               -- who caused it (null = system/sweep)
  actor_name   VARCHAR(160) NULL,               -- denormalized so history survives user deletion
  created_at   DATETIME     NOT NULL,
  KEY idx_ce_holder (tenant_id, holder_id, created_at),
  KEY idx_ce_cred (tenant_id, credential_id),
  KEY idx_ce_type_time (tenant_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE credential_documents
  ADD COLUMN supersedes_id CHAR(36) NULL AFTER credential_id;
