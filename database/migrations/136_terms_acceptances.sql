-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 136_terms_acceptances.sql — v1.173.2: Terms of Use acceptance (Ownership & White-Label Standard, element 10).
--   One row per acceptance: a user accepting for themselves (scope 'user') or a person with the permission terms.accept accepting
--   for the whole organization (scope 'organization'). The row names the Terms version, so a new version needs everyone to accept again.
--   Acceptances are never edited or deleted; each one is also an audit row (action terms_accepted).
CREATE TABLE IF NOT EXISTS terms_acceptances (
  id            CHAR(36)    NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)    NOT NULL,
  user_id       CHAR(36)    NOT NULL,
  scope         VARCHAR(12) NOT NULL,
  terms_version VARCHAR(40) NOT NULL,
  accepted_at   DATETIME    NOT NULL,
  KEY idx_terms_user (user_id, scope, terms_version),
  KEY idx_terms_tenant (tenant_id, scope, terms_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
