# FlxCoreHC v1.166.0 — Database Domain Map

Read-only classification of all tables (from `database/schema.sql` + 120 migrations). Origin migration
shown where relevant. "Legacy/Compatibility" = present and possibly written by migrations/seed but with
no proven live runtime read path. FK facts: 75 FK constraints total; 40 are `tenant_id → tenants(id)`;
key entity FKs: `credential_holders(tenant_id)`, `credentials.credential_id? ` no — `credentials(tenant_id)`,
7× `→ credential_holders`, 3× `→ credentials`, 1× `holder_requirements.requirement_id → requirement_defs`.

## Provider Identity
- `credential_holders` (schema.sql) — **the provider master. MUST NOT be replaced.** 7 FKs point to it.
- `provider_links` (108) — cross-tenant identity links by NPI.
- `npi_cache` (—) — cached NPPES lookups (integration cache, not identity of record).

## Professional
- `provider_education` (115), `provider_work_history` (115) — CAQH-style history.
- `holder_attestations` (076) — attestation records.
- `custom_field_definitions`, `custom_field_values` — tenant-defined provider fields (EAV).

## Organizations
- `tenants` (schema.sql) — org master. `tenant_domains`, `tenant_mail_settings`.
- `holder_groups`, `provider_groups`, `locations`, `holder_locations` — grouping & sites.
- `roles`, `role_capabilities`, `role_field_grants` — org RBAC + field-level grants.
- `holder_assignments` — user↔provider assignment (restricted-visibility model).

## Requirements
- `producer_requirements` (022) — **LIVE canonical** per-provider required set.
- `requirement_templates` (034), `requirement_template_items` (034) — **LIVE** profession templates.
- `requirement_sources` (110) — **LIVE** dated legal citations.
- `requirement_defs` (005), `holder_requirements` (005) — **DORMANT** (see DUPLICATION P1-1) → Legacy.
- `regulator_requirements` — **NO TABLE EXISTS** (repo references it); UNKNOWN/dead.
- `requirement_source_links` — **DOES NOT EXIST** (named in brief only).
- `carrier_requirements` (007) — insurance-vertical requirements (separate vertical; keep).

## Credentials
- `credentials` (schema.sql) — **LIVE canonical** documents-held master.
- `credential_catalog` (020) — catalog of possible credential types.
- `credential_shares` (108) — per-credential cross-org shares.

## Evidence / Documents
- `credential_documents` (schema.sql) — uploaded files + checksums.
- `document_requests` (—) — requests to provider/office to supply a document.

## Verification
- `source_verifications` (111) — **LIVE** (VerificationService). Tier A/B verification-tracking records (customer-recorded verification evidence and method).
- `credential_verifications` (076) — **LIVE** (VerificationRepository, 4 sites). **Overlaps above (P0-2).**
- `exclusion_screenings` (—), `sam_exclusions`, `leie_exclusions`, `medicaid_exclusions`,
  `sam_imports`, `leie_imports`, `medicaid_imports`, `cms_sync_log`, `cms_sync_source` — screening + import.

## Enrollment
- `payer_credentialing` (110), `program_enrollments` (110) — **LIVE** enrollment.
- `payers` (110), `ocs_profiles` (110) — payer + PR OCS/SICRO profile.
- `nipr_interest` (—) — insurance-vertical enrollment interest.

## Training
- `training_library`, `training_resources`, `training_sources`, `ce_entries` — CE tracking.

## Compliance
- `compliance_events` (102) — **LIVE canonical** ledger (ComplianceEventService).
- `compliance_items` (003), `tenant_compliance` (—) — **oldest**, still live via ComplianceRepository +
  `/compliance` route. **Overlaps (P1-5)** → candidate Legacy after consolidation.

## Tasks
- `credential_reminders` (schema.sql), `entity_reminders` (081) — **LIVE** (ReminderService).
- `notifications` (—) — in-app notifications.
- `renewal_tasks` (schema.sql) — **NEAR-DORMANT** (1 use) → Legacy candidate (P1-3).

## Sharing / Consent
- `provider_consents` (108), `credential_shares`, `cross_share_access`, `external_shares`,
  `external_share_access`, `share_links` — consent-based disclosure ledger + tokenized shares.
- `network_invites`, `impersonation_requests` — cross-org invite + support access.

## Audit / Governance
- `audit_logs` (schema.sql) — global audit. `access_logs` — PII access log. `error_log` — errors.
- `application_status_history` (077) — status trail. `schema_notes` — migration bookkeeping.
- `platform_settings` — key/value platform config (incl. app_timezone, keys base64'd).

## Applications
- `applications` (077), `application_status_history` (077) — **UNKNOWN live status (P2-1)** → verify.

## Legacy / Compatibility (insurance/producer vertical — MUST NOT remove per brief)
- `carriers`, `carrier_requirements`, `carrier_cert_schedule`, `carrier_interactions`, `commissions`,
  `policies`, `bookings`, `booking_types`, `soa_forms`, `beneficiary_interactions`,
  `platform_carriers`, `platform_carrier_plans`, `platform_carrier_aliases` — insurance vertical.
- `invoices`, `invoice_items`, `invoice_counter`, `payments`, `plans` — billing (Stripe).

## Unknown (require manual review)
- `regulator_requirements` (no table; repo refers to it), `requirement_source_links` (absent),
  `tenant_compliance` (origin migration not pinned), `nipr_interest`, `soa_forms` live-status.
- `cron/sweep_expiring.php` target table(s) — behavior UNKNOWN.
