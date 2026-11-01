-- Give the plan catalog a state dimension (so a CMS sync can reconcile within an exact
-- year+state scope) and let each source choose what happens to plans CMS has dropped.
ALTER TABLE platform_carrier_plans ADD COLUMN IF NOT EXISTS state VARCHAR(4) NULL AFTER plan_year;
UPDATE platform_carrier_plans SET state = 'FL' WHERE state IS NULL AND source IN ('seed','cms');

-- sync_mode controls reconciliation of dropped plans:
--   'reconcile' (default) — deactivate CMS plans in scope that are no longer in the file
--   'replace'             — hard-delete CMS plans in scope that are no longer in the file
--   'append'              — never remove anything (upsert only)
ALTER TABLE cms_sync_source ADD COLUMN IF NOT EXISTS sync_mode VARCHAR(12) NOT NULL DEFAULT 'reconcile' AFTER state;
