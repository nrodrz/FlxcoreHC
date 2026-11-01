# FlxCoreHC v1.166.0 — Duplication Report

Read-only. Ranked by severity. Classes: **A** canonical/keep · **B** legacy but required for
compatibility · **C** duplicate, consolidate eventually · **D** unsafe/ambiguous (>1 component can
produce different results) · **E** unknown, manual review.

## P0 — Critical (ambiguous results in the core compliance/verification path)

**P0-1 [Class D] — Compliance is computed by five overlapping systems that can disagree.**
- `flx_compliance_data()` — index.php:4561, **13 call sites**, computes its own per-row status + score.
- `ComplianceLevelService::evaluate` — app/src/ComplianceLevelService.php, per-provider level 0–5, 3 sites.
- `CompliancePostureService` — app/src/CompliancePostureService.php, roll-up, 1 site.
- `CatalogService::coverage/coverageTokens/coverageDetail` — app/src/CatalogService.php:320/346/377.
- `ChecklistService::forProvider` — app/src/ChecklistService.php:27 (newest, per-provider).
- Plus `ComplianceRepository` over `compliance_items`/`tenant_compliance` (oldest, migration 003), still
  instantiated (1 site).
- **Risk:** the provider page (ChecklistService/ComplianceLevelService), the dashboard
  (`flx_compliance_data`), and the old `/compliance` route (ComplianceRepository) can each report a
  different compliance state for the same provider. **This is the single highest architectural risk.**

**P0-2 [Class D] — Two live verification stores with an unproven relationship.**
- `source_verifications` (migration 111) written/read by `VerificationService` (Tier A/B).
- `credential_verifications` (migration 076) written/read by `VerificationRepository` — **4 sites in
  index.php** (insert + read).
- **Risk:** "is this credential verified?" has two answers depending on which store is queried.
  Relationship between the two tables is **UNKNOWN** — needs manual review before any consolidation.

## P1 — High

**P1-1 [Class B] — Requirements: live `producer_requirements` vs dormant `holder_requirements`/`requirement_defs`.**
- LIVE: `ProducerRequirementRepository` (8 uses) fully reads/writes `producer_requirements` (022).
- DORMANT: `RequirementRepository` → `holder_requirements` + `requirement_defs` (005). **Zero runtime
  read/write** outside the repo class and `SchemaDoctor`. Tables still created by migration 005.
- **Risk:** two requirement models in the schema; a future contributor may write to the wrong one.

**P1-2 [Class D] — Two readiness services with incompatible output shapes.**
- `ApplicationReadinessService::evaluate` → `{score, ok, total, groups}`.
- `ReadinessService::forProvider` → `{overall, summary, scores}`.
- Both were passed into the provider view historically (fixed once in v1.163.1). **Risk:** same collision
  class that already caused a shipped bug; still two engines answering "how ready is this provider?".

**P1-3 [Class B] — Reminders vs renewal_tasks.**
- LIVE: `ReminderService` → `credential_reminders` (schema.sql) + `entity_reminders` (081).
- NEAR-DORMANT: `RenewalTaskRepository` → `renewal_tasks` (schema.sql), 1 usage.
- **Risk:** two "what's due" mechanisms; `renewal_tasks` appears superseded but still present.

**P1-4 [Class E] — `regulator_requirements` repository points at a non-existent table.**
- `RegulatorRequirementRepository` (2 refs) + `SchemaDoctor` reference `regulator_requirements`, but
  **no CREATE TABLE exists** in schema.sql or any migration. Either dead code or a runtime error waiting
  if invoked. **Manual review required** — do not assume dead until the call path is traced.

**P1-5 [Class C] — Compliance events (new) vs compliance_items (old).**
- `ComplianceEventService` → `compliance_events` (102, 8 sites) is the current ledger.
- `compliance_items` + `tenant_compliance` (003, oldest tables) still have a live `ComplianceRepository`
  and the `/compliance` route. **Risk:** overlapping "compliance record" concepts across three migrations.

## P2 — Medium

**P2-1 [Class E] — `applications` (077) vs enrollment tables.** `ApplicationRepository` exists; no live
runtime read path proven. May overlap `payer_credentialing`/`program_enrollments` (110). Manual review.

**P2-2 [Class C] — Regulator-vs-template requirement precedence is unproven.** Both
`requirement_templates` and regulator-scoped requirements can add rows to a provider's set; which wins on
conflict is not established in code. Needs a defined precedence rule.

**P2-3 [Class C] — Coverage math duplicated between CatalogService and flx_compliance_data.** Same
"does a credential satisfy a token" computed in two places; risk of drift.

**P2-4 [Class B] — Catalog labelling paths.** `CatalogService::all` + `labelsFor` + retired-type handling
appear in multiple call sites; low risk but duplicated lookups.

## P3 — Low

**P3-1 [Class A] — Multiple status-chip renderers** across views (cosmetic duplication, no logic risk).

**P3-2 [Class A] — Repeated `Authz::can('records.write')` checks** inline in many index.php branches
(correct, but could be centralised; no behavioral risk).

## Summary counts
- P0: 2 (both Class D) · P1: 5 (B×2, C×1, D×1, E×1) · P2: 4 · P3: 2.
- Class D (unsafe/ambiguous, highest priority to resolve): **P0-1, P0-2, P1-2** (+ compliance data).
