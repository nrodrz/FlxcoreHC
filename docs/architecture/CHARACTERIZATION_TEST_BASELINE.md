# FlxCoreHC v1.166.0 — Characterization Baseline (Phase 0.1)

**Status: suite CORRECTED and READY; runtime capture PENDING (no PHP/MySQL in the authoring sandbox).**
Phase 0.1 fixes the Phase 0 methodology error (comparing unrelated metrics) and adds six scenarios,
decision-level comparison, and the §12 classification. Several findings below are **statically proven
from source** and do not require execution; all *observed runtime values* are PENDING until
`php tests/characterization/run.php` is run on a PHP 8 + MySQL host. No production code/schema/data changed.

## 1. Test environment
- **Intended:** PHP 8.3 CLI + MySQL/MariaDB; isolated DB `flx_char`; config-swap-and-restore (bootstrap.php
  hard-loads app/config.php, verified line 46 — no override hook).
- **Authoring sandbox (this run):** no PHP, no MySQL, restricted network → cannot execute or `php -l`.
  Runtime rows are PENDING. This is a stated limitation, not a simulated result.

## 2. Application version
1.166.0 (`FLXCOREHC_VERSION`, app/bootstrap.php).

## 3. Fixture description (`tests/characterization/fixture.php`)
One isolated tenant per scenario (AuthService::registerTenant 6-arg form, as DemoHealthcareService::buildOrg:87),
`seedTemplatesFor`, a physician built via HolderRepository, required set via
`TemplateService::applyByProviderType` → `producer_requirements`; credentials via CredentialRepository;
one document via DocumentRepository (`credential_documents` cols: credential_id,file_path,original_name,
mime_type,uploaded_by); verification via `VerificationService::recordManual` → `source_verifications`.
**Expiring threshold = the app's own +30 days** (ComplianceLevelService.php:75) — not invented.

## 4. Scenarios (A–F)
- **complete** — all required satisfied/active.
- **missing_requirement** — one required token omitted.
- **expiring_credential** — one within the 30-day window (stored `expiring` + soon date).
- **expired_credential** — one expired (stored `expired` + past date).
- **verification_discrepancy** — all active; one has a `source_verifications` record, others none.
- **hard_stop_exclusion** — all active; **exclusion state NOT created** (limitation §12) — recorded, not faked.

## 5. Results by engine
**PENDING** (per scenario, in CHARACTERIZATION_TEST_BASELINE.json): CatalogService.coverageDetail (raw
token→status map), ChecklistService.forProvider (done/total/pct + rows), ComplianceLevelService.evaluate
(level/label/reasons/hard_stop), flx_compliance_data (per-provider roll-up or RUNTIME_FAILURE).

## 6. Requirement-level comparisons (the corrected core)
Each engine is normalized to a per-token decision — **satisfied** (status active|expiring) vs
**unsatisfied** (missing|expired|absent) — over the SAME required token set, then compared:
`CatalogService_vs_ChecklistService` and `CatalogService_vs_flx_compliance_data`. Classifications:
AGREEMENT / REPRESENTATION_DIFFERENCE / REAL_BUSINESS_DISAGREEMENT / RUNTIME_FAILURE / UNKNOWN.
Values PENDING; but two structural facts are proven statically (§10, §11 below).

## 7. Overall-compliance comparison (SEPARATE concept)
`ComplianceLevelService::evaluate` returns an overall `{level 0–5, label, reasons, hard_stop}` — a
different business concept from per-requirement coverage. It is captured on its own and **not** equated
to checklist counts or coverage counts (the Phase 0 error). flx_compliance_data's provider roll-up
(`total/active/pct`) is captured separately; whether its `pct` means the same as a "level" is
**not assumed** — recorded for the architect to judge. Values PENDING.

## 8. Verification-store observations (§9)
Statically proven from source:
- `source_verifications` — written/read by `VerificationService` (recordManual/verifyNppes/verifySam;
  forHolder). Migration 111.
- `credential_verifications` — written/read by `VerificationRepository` (index.php, 4 sites). Migration 076.
- **Neither `ComplianceLevelService::evaluate` nor `CatalogService::coverageDetail` takes a verification
  argument** (verified signatures). → Verification does **not** feed requirement coverage or the
  compliance level directly. Whether any reader consults *both* stores for one credential is
  **RELATIONSHIP_UNKNOWN** from static analysis; scenario E probes it at runtime (PENDING).

## 9. Runtime failures (§10 — PROVEN STATICALLY, high-value finding)
**`flx_compliance_data()` has an unresolved runtime dependency on a non-existent table.**
- `flx_compliance_data()` (index.php:4561) calls `$regulatorReqs->byRegulator()` **unguarded** at its top.
- `RegulatorRequirementRepository::byRegulator()` (app/src/Repository/RegulatorRequirementRepository.php:28)
  runs `SELECT * FROM regulator_requirements` with **no try/catch**.
- `regulator_requirements` has **no CREATE TABLE** in `database/schema.sql` or any of the 120 migrations
  (SchemaDoctor only *describes* it; it does not create it).
- **Therefore, on any schema without that table, `flx_compliance_data()` throws a PDOException and cannot
  execute.** Call sites affected: index.php:866, 1468, 1738, 2367 (+ the roll-up at 708).
- Classification: **UNRESOLVED_RUNTIME_DEPENDENCY** — MITIGATED in v1.166.1 by a `hasTable()` guard in
  RegulatorRequirementRepository (read methods return empty when the table is absent, so flx no longer
  throws). The underlying schema question (should the table exist?) remains open. The runner confirms this live and
  records whether it threw. *(If it does NOT throw in flx_char, that means a migration created the table
  in the test DB and the finding must be re-examined — the runner flags this explicitly.)*

## 10. Genuine disagreements
PENDING runtime — but note one **structural** divergence already visible in source: `ComplianceLevelService`
computes "expiring" from **date + 30 days** (line 75), while `CatalogService::coverageDetail` reads the
**stored `status` column** and does not recompute (line 356). For a credential whose stored status and
date disagree, these two engines will classify it differently. The expiring scenario is designed to
expose this; the JSON will classify it AGREEMENT vs REAL_BUSINESS_DISAGREEMENT once run.

## 11. Representation differences
PENDING — the comparator records same-decision/different-raw-status as REPRESENTATION_DIFFERENCE (e.g.
one engine says `active`, another `satisfied`), distinct from a real decision flip.

## 12. Unknowns
- Whether `flx_compliance_data` throws in every environment or only where the table is absent (runner resolves).
- Whether any code reads both verification stores for one credential (RELATIONSHIP_UNKNOWN; scenario E probes).
- CompliancePostureService: not yet wired into this capture (1 call site; add in a follow-up if it is on a
  live compliance path). Marked UNKNOWN.

## 13. Limitations
- **No runtime in the authoring sandbox** → all observed values PENDING.
- **Scenario F** does not create exclusion state (needs screening write path + import data) → hard stop
  not exercised end-to-end.
- Fixture sets stored credential status explicitly; this is intentional to probe the date-vs-stored divergence.

## 14. Recommendation for Phase 1
Do **not** begin consolidation until the runner is executed on a real host and this baseline's PENDING
values are filled. The static findings already shift the P0-1 picture: **`flx_compliance_data()` may be
non-functional under the current schema** (missing `regulator_requirements`), so "five engines disagree"
may in part be "one engine cannot run." Resolve the regulator dependency question first (is the table
supposed to exist? is the code path dead?), then consolidate coverage behind `CatalogService::coverageDetail`
(which flx already delegates to) and overall level behind `ComplianceLevelService`.

## Summary table
| Area | Result | Classification | Action |
|---|---|---|---|
| Requirement coverage | PENDING runtime; Catalog vs Checklist vs flx compared at decision level | PENDING (comparator ready) | Run runner; expect Catalog≡flx (flx delegates to coverageDetail) unless flx fails |
| Overall compliance | Captured separately (level 0–5 vs flx roll-up) | PENDING; not equated | Do not equate level with counts |
| Checklist | Captured (done/total/rows), normalized to per-token decisions | PENDING | Compare decisions, not counts |
| Verification | 2 stores; neither feeds level/coverage per signatures | RELATIONSHIP_UNKNOWN (static) | Probe scenario E; keep both stores |
| Hard stops | Not exercised (no exclusion state) | LIMITATION | Provide screening fixture in a later pass |
| Regulator dependency | flx_compliance_data → SELECT missing `regulator_requirements`, unguarded | **UNRESOLVED_RUNTIME_DEPENDENCY** (static) | Resolve before any consolidation |

## How to execute (fills every PENDING)
```
php tests/characterization/run.php    # → docs/architecture/CHARACTERIZATION_TEST_BASELINE.json
./harness.sh full && ./tools/check_isolation.sh
```
