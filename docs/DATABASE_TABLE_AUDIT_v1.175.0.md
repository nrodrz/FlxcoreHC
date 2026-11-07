# Database table audit — FlxCoreHC v1.175.0

Prepared 11/07/2026 by Claude Code. Read-only: nothing was changed. Method: a fresh database built from `database/manifest.php`, then for every table its columns, foreign keys, seed rows, and every place the code reads or writes it (SQL in `app/`, `index.php`, `cron/`, repositories and the classes that use them).

## Short answer

- **94 tables** = 93 created by the migrations + `schema_migrations`. At install only **9** hold rows (reference data); the other **84** start empty.
- **3 tables are not in use at all** (nothing reads or writes them): `nipr_interest`, `requirement_defs`, `holder_requirements`. They can be dropped with no change in behaviour.
- **About 5 more** can be merged into a sibling with little risk (same shape, tiny tables).
- The rest are separate for a reason: join tables, per-source reference lists, logs, and tables whose encrypted columns are bound to the table name. Merging them would save table count but cost clarity, speed or isolation.
- Realistic target: **94 → about 86** (or **83** if the three-source exclusion lists are also merged, which I do not recommend).

## What counts toward the 94

| Kind | Tables | Notes |
|---|---:|---|
| Join / child tables (a row says "this provider has this") | 25 | holder_groups, holder_locations, holder_regulators, holder_assignments, role_capabilities, requirement_template_items, case_*, invoice_items, … Normal in a relational design. |
| Logs, tokens, outbox | 12 | audit_logs, access_logs, error_log, login_attempts, auth_email_codes, password_resets, email_outbox, … Kept apart on purpose (retention, size, privacy). |
| Reference data / catalogs | 15 | Credential catalog, regulators, payers, compliance controls, training, templates. 9 are seeded at install. |
| Core business entities | ~40 | tenants, users, providers, credentials, documents, cases, applications, invoices, … |
| Not in use | 3 | See below. |

## Tables not in use

| Table | Evidence | Action |
|---|---|---|
| `nipr_interest` | Referenced only by `PurgeService` (deleting from it). No read, no write anywhere. Left over from the insurance edition (migration 082 kept it "deliberately"). | Drop |
| `requirement_defs` | Used only by `RequirementRepository`. That class is not used by any route, view, service or cron job. | Drop |
| `holder_requirements` | Same dormant `RequirementRepository`; the case-requirements code says it is "NOT the dormant requirement_defs/holder_requirements path". | Drop |

Also dead code that goes with them: `RequirementRepository`, and their entries in `PurgeService`, `SchemaDoctor` and `database/tables.php`. A migration (`DROP TABLE IF EXISTS`) removes the tables.

## Consolidation candidates

### Tier A — low risk, recommended (−5 tables)

| Merge | Why it works | What changes |
|---|---|---|
| `credential_reminders` + `entity_reminders` → `reminder_log` (with `entity_type`) | Both record "reminder X sent to Y"; 5 of 8 columns identical; the rest become nullable. | ReminderService, LeieService, CredentialRepository, cron sweep, PurgeService |
| `auth_email_codes` + `password_resets` → `auth_tokens` (with `purpose`) | Both are short-lived single-use secrets stored as hashes; 5 columns identical. | AuthService, RateLimiter-adjacent code, tests |
| `external_share_access` + `cross_share_access` → `share_access_log` | Both log "someone opened a share"; 6 columns identical. | ExternalShareService, CrossTenantService, share log views |
| `invoice_counter` → a key in `platform_settings` | One row, one number. | InvoiceService |
| `requirement_template_items` → a JSON list on `requirement_templates` | A template is a name plus a list of tokens. | TemplateService |

Cost: new migration that creates the merged table and drops the old ones (there is no data to move on a fresh install), code changes in the files above, test updates. Each merge keeps `tenant_id` so tenant isolation tests still apply.

### Tier B — possible, not recommended

| Candidate | Saves | Why I would not |
|---|---:|---|
| LEIE, SAM and state-Medicaid lists: 6 tables → 2 (`exclusion_records`, `exclusion_imports` with a `source` column) | 4 | The three lists have different keys and 8–9 source-specific columns each; LEIE alone is ~80,000 rows replaced monthly. One wide table is slower to import and to search, and its columns are mostly empty. The three import screens share almost no SQL. |
| `program_enrollments`, `payer_credentialing`, `ocs_profiles` → one `enrollments` table | 2 | Three different workflows (Medicare/Medicaid programs, payer contracts, Puerto Rico OCS). They share only 6 generic columns; merging gives a sparse 30-column table. |
| `provider_education` + `provider_work_history` | 1 | 10 shared columns but different meaning (CAQH asks for them separately). |
| `role_capabilities` → JSON on `roles` | 1 | Permission checks and the Roles editor query it as rows; the permission validator planned next relies on that. |
| `training_sources` into `training_library` | 1 | Different grain (many sources per article). |

### Tier C — keep separate (and why)

- **Anything with an encrypted column** (credential_holders, credentials, credential_documents, addresses, provider_*, custom_field_values): the encryption context includes the table name, so merging means changing how data is sealed.
- **Tenant-scoped vs platform-wide tables** are never mixed (tenant isolation relies on every tenant table having `tenant_id` and going through a repository).
- **Logs** (audit, access, error, outbox, login attempts): different retention and size; the audit log must stay append-only and alone.
- **Sharing**: three real mechanisms (public verification link, external share with its own access log, organization-to-organization link). Only their access logs are worth merging (Tier A).

## Full inventory

Columns / rows after install / number of code files that mention the table / tables that reference it by foreign key.

### 1. Organizations, users, access, platform settings

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `tenants` | 49 | 0 | 52 | 31 |  |
| `tenant_domains` | 5 | 0 | 2 | 0 |  |
| `tenant_mail_settings` | 19 | 0 | 6 | 0 |  |
| `tenant_licenses` | 20 | 0 | 3 | 0 |  |
| `users` | 22 | 0 | 58 | 0 |  |
| `roles` | 11 | 6 | 26 | 1 |  |
| `role_capabilities` | 5 | 23 | 2 | 0 | Permission rows per role (relational on purpose). |
| `role_field_grants` | 6 | 0 | 1 | 0 |  |
| `plans` | 15 | 4 | 17 | 0 |  |
| `platform_settings` | 3 | 0 | 5 | 0 |  |
| `impersonation_requests` | 14 | 0 | 4 | 0 |  |
| `terms_versions` | 5 | 0 | 2 | 0 |  |
| `terms_acceptances` | 6 | 0 | 2 | 0 |  |

### 2. Providers (credential_holders) and what hangs off them

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `credential_holders` | 47 | 0 | 33 | 2 |  |
| `addresses` | 24 | 0 | 13 | 0 |  |
| `provider_contacts` | 16 | 0 | 2 | 0 |  |
| `provider_education` | 16 | 0 | 2 | 0 |  |
| `provider_work_history` | 14 | 0 | 2 | 0 |  |
| `holder_attestations` | 11 | 0 | 4 | 0 |  |
| `provider_consents` | 11 | 0 | 2 | 0 |  |
| `holder_assignments` | 7 | 0 | 7 | 0 |  |
| `holder_groups` | 8 | 0 | 6 | 0 |  |
| `holder_locations` | 8 | 0 | 6 | 0 |  |
| `holder_regulators` | 5 | 0 | 3 | 0 |  |
| `provider_groups` | 10 | 0 | 12 | 0 |  |
| `locations` | 15 | 0 | 31 | 0 |  |
| `ce_entries` | 9 | 0 | 3 | 0 |  |
| `custom_field_definitions` | 16 | 0 | 2 | 0 |  |
| `custom_field_values` | 8 | 0 | 2 | 0 |  |

### 3. Credentials, documents, verification, reminders

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `credentials` | 19 | 0 | 86 | 3 |  |
| `credential_documents` | 19 | 0 | 19 | 0 |  |
| `credential_verifications` | 12 | 0 | 2 | 0 |  |
| `source_verifications` | 16 | 0 | 6 | 0 |  |
| `document_requests` | 19 | 0 | 7 | 0 |  |
| `credential_reminders` | 8 | 0 | 4 | 0 | Same shape as entity_reminders (who got which reminder, once). |
| `entity_reminders` | 8 | 0 | 3 | 0 | Same shape as credential_reminders. |
| `npi_cache` | 10 | 0 | 2 | 0 |  |
| `producer_requirements` | 7 | 0 | 3 | 0 |  |

### 4. Reference data: catalogs, regulators, payers, templates, training

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `credential_catalog` | 19 | 36 | 4 | 0 |  |
| `compliance_items` | 11 | 13 | 3 | 1 |  |
| `tenant_compliance` | 11 | 0 | 1 | 0 |  |
| `requirement_sources` | 12 | 16 | 4 | 0 |  |
| `regulators` | 11 | 0 | 21 | 1 |  |
| `regulator_requirements` | 10 | 0 | 3 | 0 |  |
| `requirement_templates` | 12 | 0 | 5 | 0 |  |
| `requirement_template_items` | 5 | 0 | 1 | 0 | Five columns; just a list of tokens per template. |
| `requirement_defs` | 16 | 0 | 2 | 1 | **NOT IN USE.** Only the dormant , which no page, service or job uses. |
| `holder_requirements` | 14 | 0 | 3 | 0 | **NOT IN USE.** Same dormant  (its own source calls it "the dormant path"). |
| `payers` | 8 | 9 | 21 | 0 |  |
| `training_library` | 12 | 15 | 2 | 0 |  |
| `training_sources` | 6 | 0 | 2 | 0 |  |
| `training_resources` | 8 | 0 | 2 | 0 |  |
| `calendar_event_types` | 9 | 0 | 2 | 0 |  |

### 5. Credentialing cases, payer applications, enrollments

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `credentialing_cases` | 21 | 0 | 4 | 0 |  |
| `credentialing_case_requirements` | 14 | 0 | 4 | 0 |  |
| `case_documents` | 16 | 0 | 3 | 0 |  |
| `case_requirement_evidence` | 10 | 0 | 2 | 0 |  |
| `applications` | 15 | 0 | 19 | 0 |  |
| `application_status_history` | 8 | 0 | 1 | 0 |  |
| `payer_credentialing` | 16 | 0 | 3 | 0 |  |
| `program_enrollments` | 16 | 0 | 3 | 0 |  |
| `ocs_profiles` | 12 | 0 | 3 | 0 |  |
| `nipr_interest` | 5 | 0 | 1 | 0 | **NOT IN USE.** Insurance-era table; only the purge routine deletes from it. Nothing reads or writes it. |

### 6. Exclusion screening (OIG LEIE, SAM.gov, state Medicaid)

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `exclusion_screenings` | 11 | 0 | 9 | 0 |  |
| `leie_exclusions` | 14 | 0 | 3 | 0 |  |
| `leie_imports` | 6 | 0 | 3 | 1 |  |
| `sam_exclusions` | 15 | 0 | 3 | 0 |  |
| `sam_imports` | 6 | 0 | 3 | 1 |  |
| `medicaid_exclusions` | 11 | 0 | 2 | 0 |  |
| `medicaid_imports` | 7 | 0 | 3 | 1 |  |

### 7. Sharing a provider's credentials (links, external, between organizations)

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `share_links` | 15 | 0 | 3 | 0 |  |
| `external_shares` | 19 | 0 | 7 | 0 |  |
| `external_share_access` | 8 | 0 | 3 | 0 | Access log of external shares; shape close to cross_share_access. |
| `credential_shares` | 10 | 0 | 3 | 0 |  |
| `cross_share_access` | 9 | 0 | 3 | 0 | Access log of organization-to-organization shares. |
| `provider_links` | 19 | 0 | 4 | 0 |  |

### 8. Calendar, tasks, notifications

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `calendar_events` | 20 | 0 | 6 | 0 |  |
| `renewal_tasks` | 15 | 0 | 4 | 0 |  |
| `notifications` | 13 | 0 | 16 | 0 |  |
| `compliance_events` | 13 | 0 | 5 | 0 |  |

### 9. Billing (platform console)

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `invoices` | 16 | 0 | 15 | 2 |  |
| `invoice_items` | 8 | 0 | 1 | 0 |  |
| `invoice_counter` | 2 | 1 | 2 | 0 | One row, two columns (the next invoice number). |
| `payments` | 11 | 0 | 5 | 0 |  |

### 10. Security, logs, outbox, system

| Table | Cols | Rows at install | Code files | FK from | Note |
|---|---:|---:|---:|---:|---|
| `audit_logs` | 9 | 0 | 15 | 0 |  |
| `access_logs` | 9 | 0 | 4 | 0 |  |
| `error_log` | 13 | 0 | 7 | 0 |  |
| `login_attempts` | 3 | 0 | 2 | 0 |  |
| `auth_email_codes` | 8 | 0 | 2 | 0 | One-time sign-in codes; shape close to password_resets. |
| `password_resets` | 8 | 0 | 2 | 0 | Single-use reset links; shape close to auth_email_codes. |
| `email_outbox` | 15 | 0 | 4 | 0 |  |
| `ui_translations` | 10 | 0 | 3 | 0 |  |
| `schema_notes` | 5 | 0 | 2 | 0 |  |
| `schema_migrations` | — | — | — | 0 | Created by the installer, not by a migration file. |

## Decisions I need

1. Drop the 3 unused tables and their dead code? (recommended — no behaviour change)
2. Do the Tier A merges (−5)? (recommended while there is no data; each is a small, tested change)
3. Anything in Tier B you want anyway? (I would leave them.)

After your answer I would ship it as one release (v1.175.1): migration 139, code and test changes, the table list (`database/tables.php`) and the install check updated to the new count, full runtime check and browser suite, delivered as a zip for a fresh install.
