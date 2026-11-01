-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 106_document_requests.sql
-- A tenant asks its provider (or their office) for a document; the office uploads it in the provider
-- portal against the request. The tenant is the credential manager: after ACCEPT the document is the
-- tenant's, the provider keeps a read-only view of what they submitted and can never edit or delete.
-- While open/returned the provider may submit a replacement. Every transition is history.
CREATE TABLE IF NOT EXISTS document_requests (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)     NOT NULL,
  holder_id       CHAR(36)     NOT NULL,
  credential_id   CHAR(36)     NULL,             -- target credential, or NULL = "please supply X"
  requested_kind  VARCHAR(80)  NOT NULL,         -- e.g. "Malpractice policy (2027)"
  message         VARCHAR(500) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'open',   -- open|submitted|accepted|returned|cancelled
  document_id     CHAR(36)     NULL,             -- set on submit
  due_at          DATE         NULL,
  requested_by    CHAR(36)     NULL,
  requested_at    DATETIME     NOT NULL,
  submitted_at    DATETIME     NULL,
  submitted_by    VARCHAR(160) NULL,             -- who at the provider side (denormalized)
  accepted_by     CHAR(36)     NULL,
  accepted_at     DATETIME     NULL,
  returned_note   VARCHAR(500) NULL,
  returned_at     DATETIME     NULL,
  cancelled_at    DATETIME     NULL,
  KEY idx_dr_holder (tenant_id, holder_id, status),
  KEY idx_dr_status (tenant_id, status, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
