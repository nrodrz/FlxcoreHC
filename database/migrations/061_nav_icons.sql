-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Per-tenant navigation icon overrides, stored as a small JSON map of slot => icon name
-- (e.g. {"nav.billing":"wallet"}). NULL means "use the product defaults", and only slots the
-- agency actually changed are stored, so the column stays tiny and defaults can evolve later.
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS nav_icons TEXT NULL;
