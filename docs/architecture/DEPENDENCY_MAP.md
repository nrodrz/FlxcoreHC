# FlxCoreHC v1.166.0 — Dependency Map

**Audit type:** read-only. No application or database files were modified.
**Source of truth:** `flxcorehc-v1_166_0.zip` (extracted read-only); schema parsed from
`database/schema.sql` + `database/migrations/*.sql` (no live DB engine required).
**Scope:** 235 PHP files · 72 services (`app/src/*.php`) · 26 repositories · 115 views · 120 migrations
+ `database/schema.sql` · `index.php` = 4,668-line front controller · 4 cron scripts.

**Global facts that apply to every row below:**
- **Tests: NONE.** The only automated coverage is `harness.sh` (HTTP route smoke test → `ALL_ROUTES_PASS`)
  and `tools/check_isolation.sh` (static grep for raw DB access). There are **zero unit tests** in the
  codebase (`find . -name '*Test.php'` → 0). Every "Tests" cell below is therefore **NONE (smoke only)**.
- **Controller:** all modules route through `index.php`, dispatched on `$seg[0]` (the first URL segment).
- **AuthZ:** `Authz::requireCap()` / `Authz::can()` gate writes per module (capability names vary).
- **Audit:** most state changes call the global `audit_log()` helper → `AuditLogRepository` → `audit_logs`.
- **Isolation:** every repository extends `TenantRepository` and is scoped by `TenantContext`.

| Module | Route (seg[0]) | View(s) | Controller/Router | Service(s) | Repository(ies) | Tables | External deps | Tests |
|---|---|---|---|---|---|---|---|---|
| 1. Providers / Workspace | `/holders`, `/holders/{id}` | `holders/show.php`, `holders/_provider_header.php`, `holders/form.php`, `holders/index.php` | index.php `$seg[0]==='holders'` | HolderRepository (29 uses), ChecklistService, ComplianceLevelService, ApplicationReadinessService, DashboardService, CustomFieldService | HolderRepository, CredentialRepository, ProducerRequirementRepository, AddressRepository, ProviderContactRepository | `credential_holders` (schema.sql), `credentials`, `producer_requirements`, `addresses`, `provider_contacts`, `provider_education`, `provider_work_history`, `custom_field_values` | NpiService (NPPES) on verify | NONE (smoke) |
| 2. Onboarding | `/holders/{id}/onboard/{step}`, `/onboarding` | `holders/onboard.php`, `holders/_onboarding.php` | index.php `holders` + `onboarding` branches | TemplateService (`applyByProviderType`), HolderRepository, ProducerRequirementRepository | HolderRepository, ProducerRequirementRepository, TemplateRepository | `credential_holders`, `producer_requirements`, `requirement_templates`, `requirement_template_items` | — | NONE (smoke) |
| 3. Credentials | `/credentials`, `/credentials/{id}/edit`, `/credentials/new` | `credentials/index.php`, `credentials/form.php` | index.php `$seg[0]==='credentials'` | CatalogService, CredentialPackageService, ChecklistService | CredentialRepository (11 uses), DocumentRepository, CatalogRepository | `credentials` (schema.sql), `credential_catalog`, `credential_documents` | — | NONE (smoke) |
| 4. Requirements | (no own route; surfaced in Providers/Onboarding/Compliance) | via `holders/show.php`, `holders/_checklist.php` | index.php `compliance/assign`, `holders` | TemplateService, CatalogService | **ProducerRequirementRepository (LIVE)**; RequirementRepository + RegulatorRequirementRepository (**DORMANT**) | **LIVE:** `producer_requirements`, `requirement_templates`, `requirement_template_items`. **DORMANT:** `requirement_defs`, `holder_requirements`; **NO TABLE:** `regulator_requirements` | — | NONE (smoke) |
| 5. Documents | `/documents`, `/credentials/{id}` (upload) | `credentials/*`, `holders/show.php` | index.php `$seg[0]==='documents'` + `credentials` | DocumentService (upload/checksum), CredentialPackageService | DocumentRepository (`credential_documents`) | `credential_documents` (schema.sql), `document_requests` | file storage (`app/storage`) | NONE (smoke) |
| 6. Verification | `/holders/{id}` (verify), `/verify/*` | `holders/show.php` (verify pane), `verify/` | index.php `holders` verify actions | **VerificationService → `source_verifications`** (Tier A/B); SamService, LeieService, MedicaidExclusionService | **VerificationRepository → `credential_verifications`** (4 sites in index.php); ExclusionScreeningRepository | **TWO STORES:** `source_verifications` (111) AND `credential_verifications` (076); `exclusion_screenings`, `sam_exclusions`, `leie_exclusions`, `medicaid_exclusions` | SAM.gov API, OIG LEIE, NPPES, PR Medicaid | NONE (smoke) |
| 7. Enrollment / Payers | `/holders/{id}` (payers pane), `/applications` | `holders/show.php` (payers pane) | index.php `holders` payer actions | EnrollmentService, PlanService | PayerCredentialingRepository, ProgramEnrollmentRepository, PayerRepository | `payer_credentialing` (110), `program_enrollments` (110), `payers`, `ocs_profiles` | Stripe (billing, separate) | NONE (smoke) |
| 8. Training | `/training` | `training/`, `holders/_ce.php` | index.php `$seg[0]==='training'` | TrainingService | TrainingRepository, CeRepository | `training_library`, `training_resources`, `training_sources`, `ce_entries` | — | NONE (smoke) |
| 9. Compliance | `/compliance` | `compliance/`, `holders/_checklist.php`, `dashboard.php` | index.php `$seg[0]==='compliance'` | **5 OVERLAPPING:** `flx_compliance_data()` (13 sites), ComplianceLevelService (3), CompliancePostureService (1), CatalogService::coverage*/coverageTokens/coverageDetail (3), ChecklistService | ComplianceRepository (`compliance_items`/`tenant_compliance`), CatalogRepository | `compliance_items` (003), `tenant_compliance`, `compliance_events` (102), `credentials`, `producer_requirements` | — | NONE (smoke) |
| 10. Tasks / Alerts | `/tasks`, `/notifications` | `tasks/`, `notifications/` | index.php `$seg[0]==='tasks'` / `notifications` | ReminderService (LIVE), NotificationService, TaskSyncService | **LIVE:** (reminders via ReminderService); RenewalTaskRepository (1 use, **near-dormant**) | **LIVE:** `credential_reminders` (schema.sql), `entity_reminders` (081), `notifications`. **NEAR-DORMANT:** `renewal_tasks` (schema.sql) | cron: `send_reminders.php`, `sweep_expiring.php` | NONE (smoke) |
| 11. Applications | `/applications` | `applications/` | index.php `$seg[0]==='applications'` | ApplicationReadinessService, ReadinessService | ApplicationRepository (`applications`, 077) — **appears DORMANT at runtime** | `applications` (077), `application_status_history` | — | NONE (smoke) |
| 12. Regulators | `/regulators` | `regulators/` | index.php `$seg[0]==='regulators'` | RegulatorService, RegulatorySources | RegulatorRepository (`regulators`, 073), HolderRegulatorRepository (`holder_regulators`) | `regulators` (073), `holder_regulators`, `requirement_sources` (110) | — | NONE (smoke) |
| 13. Orgs / Groups / Locations | `/groups`, `/locations`, `/organization` | `groups/`, `locations/`, `org/` | index.php `groups`/`locations`/`organization` | GroupService, LocationService, RoleService | GroupRepository, LocationRepository, HolderGroupRepository, HolderLocationRepository | `holder_groups`, `provider_groups`, `locations`, `holder_locations`, `roles`, `role_capabilities`, `role_field_grants` | — | NONE (smoke) |
| 14. Cross-tenant Sharing / Consent | `/holders/{id}` (crossorg), `/s/{token}` | `holders/show.php` (sharing pane), `share/` | index.php `holders` crossorg + `$seg[0]==='s'` | CrossTenantService, ExternalShareService, ConsentService | CredentialShareRepository, ExternalShareRepository, ProviderConsentRepository, ProviderLinkRepository | `credential_shares`, `cross_share_access`, `external_shares`, `external_share_access`, `provider_consents`, `provider_links`, `share_links` | — | NONE (smoke) |
| 15. Audit | `/audit` | `audit/` | index.php `$seg[0]==='audit'` | (global `audit_log()`), AccessLogService | AuditLogRepository (`audit_logs`), AccessLogRepository (`access_logs`) | `audit_logs` (schema.sql), `access_logs`, `error_log`, `compliance_events` | — | NONE (smoke) |
| 16. External integrations | (various, within modules) | — | index.php + services | NpiService, SamService, VerificationService, LeieService, MedicaidExclusionService, MicrosoftOAuth, BillingService/PlanService, MailService | NpiCacheRepository, various | `npi_cache`, `cms_sync_log`, `cms_sync_source`, `sam_imports`, `leie_imports`, `medicaid_imports`, `email_outbox`, `tenant_mail_settings` | NPPES, SAM.gov, OIG LEIE, PR Medicaid, Microsoft Graph, Stripe | NONE (smoke) |

## Cron / background dependencies
- `cron/screen_leie.php` → LEIE/exclusion screening sweep (writes `exclusion_screenings`).
- `cron/send_digests.php` → activity digests (ActivityDigestService → `email_outbox`).
- `cron/send_reminders.php` → reminder emails (ReminderService → `credential_reminders`/`entity_reminders`).
- `cron/sweep_expiring.php` → expiry sweep. **Exact behavior UNKNOWN** beyond the filename + a `sweep` call;
  needs manual review to confirm which table(s) it writes.

## Dormant / dead components proven from source (see DUPLICATION_REPORT for classification)
- `RequirementRepository` (→ `holder_requirements`, `requirement_defs`): instantiated in code but these
  tables have **zero runtime read/write** outside the repo class + `SchemaDoctor`. **DORMANT.**
- `RegulatorRequirementRepository` (→ `regulator_requirements`): **the table does not exist** in
  `schema.sql` or any migration. Repo points at a non-existent table. **DEAD / UNKNOWN — manual review.**
- `renewal_tasks` / `RenewalTaskRepository`: 1 usage; superseded by ReminderService. **NEAR-DORMANT.**
- `requirement_source_links`: named in the brief but **not present** in schema or code. **DOES NOT EXIST.**
- `applications` / `ApplicationRepository` (077): present, but no live runtime read path proven. **UNKNOWN.**
