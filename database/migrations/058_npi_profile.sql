-- NPI-verified producer profile. CMS's NPPES registry OWNS certain fields (legal name, entity type,
-- taxonomy/specialty, practice city/state, professional credential). We store them as a VERIFIED
-- SNAPSHOT separate from the agency's own editable fields, stamped with when CMS last confirmed them.
-- This cleanly resolves the "manual edit vs re-sync" problem: CMS-owned fields are read-only and are
-- only ever refreshed by a re-verification against CMS; agency-owned fields are never touched by the
-- sync, so nothing a manager types can be silently overwritten.
ALTER TABLE credential_holders
  ADD COLUMN IF NOT EXISTS npi_verified_at   DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS npi_verified_by   CHAR(36)     NULL,
  ADD COLUMN IF NOT EXISTS npi_legal_name    VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS npi_entity_type   VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS npi_credential    VARCHAR(60)  NULL,
  ADD COLUMN IF NOT EXISTS npi_taxonomy      VARCHAR(160) NULL,
  ADD COLUMN IF NOT EXISTS npi_city          VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS npi_state         VARCHAR(4)   NULL;

-- Per-tenant seat override: a specific customer can be granted a custom seat count regardless of
-- their plan tier (a sales lever — "we'll give you 5 more seats"). NULL = use the plan's limit.
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS seat_override INT NULL;
