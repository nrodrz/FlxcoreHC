-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 098_ui_translations.sql
-- Editable UI translations. The English source text is the key; `target` is the translation in
-- `locale`. Rows with target NULL are strings the translator has SEEN rendered but that have no
-- translation yet — the console editor lists them so the whole UI can be finished in-app, and the
-- final front-end wording is editable without touching code. Platform-wide (not per tenant).

CREATE TABLE IF NOT EXISTS ui_translations (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  locale      VARCHAR(8)   NOT NULL,
  source_hash CHAR(40)     NOT NULL,            -- sha1(source) for a compact unique key
  source      TEXT         NOT NULL,
  target      TEXT         NULL,
  seen_count  INT          NOT NULL DEFAULT 1,
  updated_by  CHAR(36)     NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_ui_tr (locale, source_hash),
  KEY idx_ui_tr_missing (locale, target(1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
