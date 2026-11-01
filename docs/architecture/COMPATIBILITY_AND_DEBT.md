# FlxCoreHC — internal compatibility identifiers and known structural debt (v1.170.9)

## Legacy identifiers kept on purpose (never user-visible)
The product is healthcare-only. These internal names date from the retired insurance edition and are retained ONLY because
they are stored data or referenced by billing/URLs; renaming them is a schema/data migration with no user benefit.
User-visible wording is guarded by `tests/healthcare/test_healthcare_only.php` (H5/H5b string scans).

| Identifier | Where | Why kept |
|---|---|---|
| role slug `producer` (label "Provider (self-service)") | `users.role`, `roles.slug`, TeamService, portal guard | stored on user rows; portal confinement now uses `RoleService::isPortalContext` so the slug is only a default |
| table/repo `producer_requirements`, route `/compliance/producers` | schema, repository, URL | provider requirement assignments; URL is bookmarked |
| column `business_lines` | `tenants` | unused by healthcare logic; dropping needs a migration |
| plan code `agency` (display name "Organization") | `plans.code`, billing | referenced by `plan_tier`/invoices |
| category codes `insurance`, `appointment` (relabelled "Malpractice / liability", "Payer enrollment") | `credential_catalog.category` | stored on custom types |
| retired tables (carriers, policies, …) | schema | data retention; a decision on dropping them is pending (question H4) |

## Known structural debt (documented, NOT changed here)
- `index.php` is a ~4,900-line front controller. Extracting controllers is a refactor with regression risk and is out of
  scope for a security release. Proposed later path: extract one route group at a time behind the existing tests
  (start with `/documents`, `/credentials`), keeping `$seg` dispatch until each group has characterization coverage.
- Capability checks live in routes; provider scope now lives in the data layer (`ProviderScope` + `TenantRepository`).
  A future step is to make repositories refuse restricted contexts for tables that forgot to declare a scope
  (deny-by-default) — currently guarded by `tests/authz/test_provider_scope_static.php` (S3).
