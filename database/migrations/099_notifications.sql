-- 099_notifications.sql
-- In-app notification center. One row per (tenant, user, event). `dedupe_key` makes generation
-- idempotent so the opportunistic sweep can run on every dashboard load without duplicating.
-- Need-to-know is applied at generation time: a restricted user is only notified about providers
-- they can see. Nothing here is ever emailed; it is the in-app "bell".

CREATE TABLE IF NOT EXISTS notifications (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  user_id     CHAR(36)     NOT NULL,
  kind        VARCHAR(32)  NOT NULL,          -- expiring | expired | reverify | overdue | assigned | import | screening | info
  severity    VARCHAR(8)   NOT NULL DEFAULT 'info',   -- info | warn | danger
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(500) NULL,
  link        VARCHAR(255) NULL,
  entity_type VARCHAR(32)  NULL,
  entity_id   CHAR(36)     NULL,
  dedupe_key  VARCHAR(120) NOT NULL,
  read_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_notif (tenant_id, user_id, dedupe_key),
  KEY idx_notif_user_unread (tenant_id, user_id, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
