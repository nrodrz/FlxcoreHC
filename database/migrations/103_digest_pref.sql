-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 103_digest_pref.sql
-- Opt-in for the weekly (or daily) activity-pulse email digest. Per user so each admin chooses.
-- 'off' (default) sends nothing; 'daily' / 'weekly' send a summary of the tenant's activity.
ALTER TABLE users ADD COLUMN activity_digest VARCHAR(8) NOT NULL DEFAULT 'off' AFTER locale;
