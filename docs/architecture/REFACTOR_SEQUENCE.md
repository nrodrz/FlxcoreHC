# FlxCoreHC v1.166.0 — Safe Refactor Sequence

Read-only recommendation. **No code or DB changes performed.** The sequence is ordered so each step is
backward-compatible, independently shippable, gate-verifiable (`harness.sh full` + `check_isolation.sh`),
and never touches the provider master, tenant isolation, or crypto AAD.

## Guiding constraints (from the brief, preserved)
- No `providers` table; `credential_holders` stays the provider master.
- No removal of insurance/producer vertical.
- No destructive DB changes in this phase.
- The single biggest hazard is **compliance/verification ambiguity (P0-1, P0-2)** — resolve by making
  one component authoritative *before* deleting anything.

## Phase 0 — Instrument before touching (write nothing destructive)
1. **Add characterization tests first.** There are currently **zero unit tests**. Before any
   consolidation, add tests that pin the *current* output of `ComplianceLevelService::evaluate`,
   `CatalogService::coverageDetail`, `ChecklistService::forProvider`, and `flx_compliance_data()` for a
   fixed fixture provider. This converts "5 systems might disagree" from a guess into a measurable fact
   and guards the consolidation. (Test harness exists via `harness.sh`; unit-level scaffolding does not.)

## Phase 1 — Establish single authorities (no deletions)
2. **Declare ComplianceLevelService + CatalogService::coverageDetail the canonical compliance authority.**
   Make `flx_compliance_data()` and the dashboard *call* them instead of recomputing. Keep
   `flx_compliance_data()` as a thin adapter (13 callers unchanged) — behavior-preserving.
3. **Reconcile the two verification stores (P0-2).** Do not merge tables yet. First add a read-through
   that treats `source_verifications` as authoritative and `credential_verifications` as legacy-read,
   logging any disagreement. Only after a clean period consider migrating rows.
4. **Unify readiness (P1-2).** Pick `ApplicationReadinessService` as canonical; make `ReadinessService`
   delegate or be retired behind a compatibility shim (it already caused one shipped bug).

## Phase 2 — Quarantine dormant components (still no drops)
5. **Mark dormant requirement path.** `RequirementRepository`/`holder_requirements`/`requirement_defs`
   (P1-1) and `renewal_tasks` (P1-3): add a deprecation note + guard so nothing new writes to them.
   Confirm `regulator_requirements` (P1-4) call path — if truly dead, mark for removal in a later phase.

## Phase 3 — Consolidate (reversible, compatibility shims retained)
6. Fold `compliance_items`/`tenant_compliance` reads behind `ComplianceEventService` (P1-5).
7. Define regulator-vs-template precedence explicitly (P2-2) and centralize coverage math (P2-3).

## Phase 4 — Only after a full release cycle of stability
8. Consider actual table retirement of proven-dead tables (with backups + a reversible migration).
   Not before dependencies are proven by the Phase 0 tests.

---

# 1. Top 20 Architectural Risks
1. **Five overlapping compliance engines can disagree** (P0-1). *Highest.*
2. **Two live verification stores** with unknown relationship (P0-2).
3. **Zero unit tests** — no safety net for any refactor.
4. `flx_compliance_data()` — 4,668-line index.php hosts a 13-caller compliance function.
5. Two readiness services with incompatible shapes (P1-2) — already caused a shipped bug.
6. `index.php` is a 4,668-line front controller — routing, auth, and business logic interleaved.
7. Dormant `holder_requirements`/`requirement_defs` still in schema (write-to-wrong-model risk).
8. `RegulatorRequirementRepository` points at a **non-existent table** (P1-4).
9. `renewal_tasks` vs `ReminderService` — two "what's due" mechanisms (P1-3).
10. `compliance_items` (oldest) still live alongside `compliance_events` (P1-5).
11. Base tables live in `schema.sql`, not the numbered migration sequence — split source of schema truth.
12. `cron/sweep_expiring.php` behavior UNKNOWN — an unattended writer.
13. No migration framework of its own; ordered list in `install.php` is hand-maintained.
14. Encryption keys in `app/config.php` — single point of catastrophic loss (crypto AAD is safe though).
15. Coverage math duplicated (CatalogService vs flx_compliance_data) — drift risk (P2-3).
16. `applications` (077) live status unknown — possible orphan domain (P2-1).
17. Regulator-vs-template requirement precedence undefined (P2-2).
18. 376 `tenant_id` columns but only 40 DB-level FKs to `tenants` — isolation is app-enforced, not DB.
19. Many inline `Authz::can` checks in index.php — easy to miss one on a new branch.
20. Verification/exclusion hard-stop depends on screening freshness; staleness handling not proven.

# 2. Top 20 Duplicated Responsibilities
1. Compliance status/score: `flx_compliance_data` vs `ComplianceLevelService` vs `CatalogService::coverage*`.
2. "Verified?": `source_verifications` vs `credential_verifications`.
3. Readiness: `ApplicationReadinessService` vs `ReadinessService`.
4. Required-set: `producer_requirements` vs `holder_requirements`/`requirement_defs`.
5. "What's due": `ReminderService` vs `renewal_tasks`.
6. Compliance ledger: `compliance_events` vs `compliance_items`/`tenant_compliance`.
7. Coverage math: CatalogService vs flx_compliance_data (per-row status).
8. Provider checklist rows: ChecklistService vs flx_compliance_data.
9. Compliance roll-up: CompliancePostureService vs ComplianceLevelService aggregation.
10. Regulator requirements: template-driven vs regulator-scoped (precedence unclear).
11. Catalog labelling: `CatalogService::all` vs `labelsFor` vs retired-type handling.
12. Group membership: `holder_groups` vs `provider_groups`.
13. Enrollment vs applications intent (`payer_credentialing`/`program_enrollments` vs `applications`).
14. Exclusion data: `exclusion_screenings` vs the raw `*_exclusions` import tables.
15. Status chips rendered in many views (cosmetic).
16. `Authz::can('records.write')` repeated across index.php branches.
17. Reminder generation vs calendar-event sync (TaskSyncService vs ReminderService).
18. NPI identity: `credential_holders.npi_*` vs `npi_cache` vs `provider_links`.
19. Attestation vs verification records (`holder_attestations` vs verification stores).
20. Two share models: `credential_shares`/`cross_share_access` vs `external_shares`/`share_links`.

# 3. Top 20 Tables Requiring Careful Migration Planning
1. `credential_holders` — provider master; 7 inbound FKs; **never replace**.
2. `credentials` — held-documents master; 3 inbound FKs.
3. `producer_requirements` — live required-set; touched by onboarding/templates/checklist.
4. `source_verifications` + 5. `credential_verifications` — must reconcile before either migrates.
6. `compliance_events` + 7. `compliance_items` + 8. `tenant_compliance` — three-way overlap.
9. `holder_requirements` + 10. `requirement_defs` — dormant but FK-linked to each other.
11. `requirement_templates` + 12. `requirement_template_items` — drive auto-apply.
13. `renewal_tasks` — near-dormant; confirm no cron writes before retiring.
14. `credential_reminders` + 15. `entity_reminders` — live reminder path.
16. `applications` + `application_status_history` — unknown live status.
17. `credential_documents` — evidence; checksums; FK to credentials.
18. `regulators` + `holder_regulators` + `requirement_sources` — regulator grounding.
19. `payer_credentialing` + `program_enrollments` + `ocs_profiles` — enrollment.
20. `audit_logs` — append-only; large; any schema change must preserve history.

# 4. First Five Refactoring Steps (recommended)
1. **Write characterization tests** for the 4 compliance/coverage entry points (Phase 0, step 1) — no
   behavior change, just a safety net. Nothing else should precede this.
2. **Make `flx_compliance_data()` delegate** per-row status to `CatalogService::coverageDetail` and level
   to `ComplianceLevelService` (behavior-preserving adapter; 13 callers untouched).
3. **Add a verification read-through** that treats `source_verifications` as authoritative and logs any
   `credential_verifications` disagreement (no table change yet).
4. **Retire `ReadinessService`** behind a shim delegating to `ApplicationReadinessService` (kills the
   shape-collision bug class permanently).
5. **Add deprecation guards** to the dormant requirement path (`RequirementRepository`,
   `holder_requirements`, `requirement_defs`) so nothing new writes to it.

# 5. Code That MUST NOT Change Until Dependencies Are Understood
- `credential_holders` table + `HolderRepository` — the provider master (29 usages).
- `TenantRepository` base class + `TenantContext` — tenant isolation (376 tenant_id columns; app-enforced).
- `Crypto` + `TenantRepository::aadFor` — the encryption AAD (`tenant|table|column`); changing it makes
  all `*_enc` data (incl. SSN/DOB) undecryptable.
- `ProducerRequirementRepository` + `producer_requirements` — the live required-set (onboarding/checklist).
- `TemplateService::applyByProviderType` — the profession→requirements engine.
- `ChecklistService` — the canonical per-provider merged view.
- `VerificationService` + `source_verifications` — do not drop `credential_verifications` until P0-2 resolved.
- Insurance/producer vertical tables (carriers, policies, commissions, bookings, soa_forms, etc.).
- `install.php` migration order list + `database/schema.sql` — the schema source of truth.
- `audit_logs` / `audit_log()` — append-only governance record.
