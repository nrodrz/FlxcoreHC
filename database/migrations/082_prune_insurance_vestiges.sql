-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 082_prune_insurance_vestiges.sql
-- Structural pruning of insurance-era vestige tables and columns that no live healthcare
-- code reads or writes. Each table below was audited against the entire live codebase
-- (index.php + app/, excluding migrations) and found to have zero live references, OR (for
-- policies/commissions) had its live references removed in this same release before this drop.
--
-- Kept deliberately (still live, do NOT drop): beneficiary_interactions, nipr_interest,
-- calendar_events, requirement_templates, requirement_template_items.
--
-- Idempotent: DROP ... IF EXISTS re-runs to a no-op. FK checks are disabled around the drops
-- because two pairs reference each other (bookings->booking_types, commissions->policies);
-- disabling avoids ordering fragility on installs where only some tables exist.

SET FOREIGN_KEY_CHECKS = 0;

-- Booking / scheduling (retired in v1.96)
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS booking_types;

-- Scope of Appointment (insurance)
DROP TABLE IF EXISTS soa_forms;

-- Carrier interaction log + certification calendar (insurance)
DROP TABLE IF EXISTS carrier_interactions;
DROP TABLE IF EXISTS carrier_cert_schedule;

-- CMS sync (insurance)
DROP TABLE IF EXISTS cms_sync_log;
DROP TABLE IF EXISTS cms_sync_source;

-- Platform carrier catalog (insurance)
DROP TABLE IF EXISTS platform_carrier_aliases;
DROP TABLE IF EXISTS platform_carrier_plans;
DROP TABLE IF EXISTS platform_carriers;

-- Network invites (unused)
DROP TABLE IF EXISTS network_invites;

-- Book of business (insurance) — commissions references policies, drop it first
DROP TABLE IF EXISTS commissions;
DROP TABLE IF EXISTS policies;

SET FOREIGN_KEY_CHECKS = 1;

-- Drop the now-unused per-plan policy cap. Guarded so it is a no-op if already gone.
-- (No portable "DROP COLUMN IF EXISTS" on this MariaDB target, so check first.)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'max_policies');
SET @ddl := IF(@col > 0, 'ALTER TABLE plans DROP COLUMN max_policies', 'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
