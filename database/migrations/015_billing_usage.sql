-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Billing (Stripe) + usage caps.
ALTER TABLE plans
  ADD COLUMN stripe_price_id VARCHAR(80) NULL,
  ADD COLUMN max_holders     INT NULL,
  ADD COLUMN max_policies    INT NULL;

ALTER TABLE tenants
  ADD COLUMN stripe_customer_id     VARCHAR(80) NULL,
  ADD COLUMN stripe_subscription_id VARCHAR(80) NULL,
  ADD COLUMN billing_status         VARCHAR(20) NOT NULL DEFAULT 'none',
  ADD COLUMN current_period_end     DATETIME NULL;

-- example caps on seeded plans (NULL = unlimited)
UPDATE plans SET max_holders=25,   max_policies=250   WHERE code='starter';
UPDATE plans SET max_holders=500,  max_policies=2500  WHERE code='solo';
UPDATE plans SET max_holders=5000, max_policies=25000 WHERE code='agency';
-- fmo stays unlimited
