-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Producer-seat pricing as a per-plan lever. Staff seats (max_users) cover managers/staff/agents;
-- producer portal logins are a SEPARATE billing dimension. producer_price is the monthly charge per
-- producer login: NULL or 0 means producers are included free. producer_included is a free allowance
-- before per-seat charges begin (e.g. first 10 producers free, then $X each).
ALTER TABLE plans
  ADD COLUMN IF NOT EXISTS producer_price     DECIMAL(8,2) NULL,
  ADD COLUMN IF NOT EXISTS producer_included  INT          NOT NULL DEFAULT 0;
