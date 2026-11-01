-- 088_user_locale.sql
-- Per-user interface language preference for the Puerto Rico market (Spanish-first). Defaults to
-- English; a user can switch to Spanish and it persists. Nullable so existing rows need no backfill.

ALTER TABLE users ADD COLUMN locale CHAR(5) NULL DEFAULT NULL AFTER status;
