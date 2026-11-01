-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Subscription plans: seat limits + included features per tenant.
CREATE TABLE IF NOT EXISTS plans (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  price_monthly DECIMAL(8,2) NULL,
  max_users     INT          NULL,            -- NULL = unlimited seats
  features      JSON         NULL,            -- array of feature codes
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tenants ADD COLUMN plan_id CHAR(36) NULL;

INSERT INTO plans (id, code, name, price_monthly, max_users, features, is_default, active, sort_order, created_at) VALUES
 (UUID(),'starter','Starter',0.00,1,'[]',0,1,10,UTC_TIMESTAMP()),
 (UUID(),'solo','Solo',29.00,2,'["documents","scheduling","analytics"]',0,1,20,UTC_TIMESTAMP()),
 (UUID(),'small_group','Small_group',99.00,10,'["documents","scheduling","analytics"]',0,1,30,UTC_TIMESTAMP()),
 (UUID(),'agency','Agency',149.00,25,'["documents","scheduling","analytics"]',1,1,40,UTC_TIMESTAMP());
