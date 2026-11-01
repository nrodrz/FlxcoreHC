-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Error log — production breakage that reaches a screen instead of a phone call.
--
-- Uncaught exceptions and fatal errors already go to PHP's error_log(), which on shared cPanel
-- hosting is effectively invisible: no shell, buried per-account log, no alerting. This records
-- the same failures into a table the admin can actually read, with enough context to locate the
-- cause (message, class, file:line, the request path, method, tenant, and user if known) but
-- WITHOUT stack-trace internals or request bodies, which could hold personal data.
CREATE TABLE IF NOT EXISTS error_log (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  created_at  DATETIME     NOT NULL,
  level       VARCHAR(12)  NOT NULL DEFAULT 'error',   -- error | fatal
  error_class VARCHAR(160) NULL,                        -- e.g. PDOException
  message     VARCHAR(500) NOT NULL,
  file        VARCHAR(300) NULL,
  line        INT          NULL,
  path        VARCHAR(300) NULL,                        -- request path, query string stripped
  method      VARCHAR(10)  NULL,
  tenant_id   CHAR(36)     NULL,
  user_id     CHAR(36)     NULL,
  resolved_at DATETIME     NULL,                        -- admin can mark handled
  seen        TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_error_log_created (created_at),
  KEY idx_error_log_unseen (seen, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
