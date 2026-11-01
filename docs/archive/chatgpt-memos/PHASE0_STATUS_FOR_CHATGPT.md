# FlxCoreHC v1.166.0 — Phase 0 / 0.1 Status (for the ChatGPT reviewer)

**Purpose of this note:** to synchronize the two AI reviewers. Several recent prompts have re-specified a
`capture.php` correction that **was already implemented** in Phase 0.1. This document states precisely
what exists, so effort isn't spent re-doing completed work. Nothing in production code or schema has been
changed in any of this — Phase 0/0.1 is characterization-only.

## TL;DR
- The "compare unrelated metrics" flaw in `capture.php` is **already fixed** (Phase 0.1). The corrected,
  decision-level comparison model with the 5-way classification is in the tree now.
- The one artifact that was genuinely missing — a written **design/formula document** — has now been
  added: `docs/architecture/CHARACTERIZATION_TEST_DESIGN.md`.
- The **only** thing still outstanding is **executing** the suite. That is blocked by environment
  (no PHP, no MySQL in the review sandbox), not by any remaining design work.

## What exists in the tree (current)
| File | State |
|---|---|
| `tests/characterization/fixture.php` | DONE — 6 deterministic scenarios (complete, missing_requirement, expiring_credential, expired_credential, verification_discrepancy, hard_stop_exclusion). Uses only verified APIs/columns. |
| `tests/characterization/capture.php` | DONE — **corrected** model: normalizes each engine to per-token satisfied/unsatisfied; compares only equivalent decisions; captures overall compliance separately; 5-way classification (AGREEMENT / REPRESENTATION_DIFFERENCE / REAL_BUSINESS_DISAGREEMENT / RUNTIME_FAILURE / UNKNOWN). |
| `tests/characterization/run.php` | DONE — iterates the 6 scenarios against an isolated `flx_char` DB; writes `CHARACTERIZATION_TEST_BASELINE.json`; restores `app/config.php`. |
| `tests/characterization/install_into_char.php` | DONE — applies schema.sql + migrations in install.php order into `flx_char`. |
| `docs/architecture/CHARACTERIZATION_TEST_BASELINE.md` | DONE — human-readable baseline; runtime values marked PENDING (no runtime available). |
| `docs/architecture/CHARACTERIZATION_TEST_DESIGN.md` | **NEW** — the comparison model + both overall-compliance formulas documented side by side (this was the missing piece). |
| The 5 audit docs (`DEPENDENCY_MAP`, `BUSINESS_RULE_MAP`, `DUPLICATION_REPORT`, `DATABASE_DOMAIN_MAP`, `REFACTOR_SEQUENCE`) | DONE — in `docs/architecture/`. |

## Findings already established from source (no runtime needed)
1. **flx_compliance_data delegates coverage to CatalogService::coverageDetail** (index.php ~4618). So at
   the requirement-coverage layer they are not independent engines; they should AGREE unless flx fails.
2. **flx_compliance_data has an UNRESOLVED_RUNTIME_DEPENDENCY:** it calls
   `RegulatorRequirementRepository::byRegulator()` → `SELECT * FROM regulator_requirements`, unguarded;
   that table has no CREATE TABLE anywhere → flx throws under the current schema. Call sites:
   index.php 866/1468/1738/2367 (+708).
3. **ComplianceLevelService (ladder 0–5 + hard stops) and flx_compliance_data (coverage %) are different
   business concepts** and must not be equated — see CHARACTERIZATION_TEST_DESIGN.md §4.
4. **Verification does not feed coverage or level directly** (neither engine's signature takes a
   verification argument). Cross-store (source_verifications vs credential_verifications) relationship is
   UNKNOWN from static analysis.

## What is still PENDING (and why)
Executing `php tests/characterization/run.php` to capture the actual per-token agreement/disagreement
counts, confirm the flx runtime failure live, and probe the verification stores. **Blocked solely by the
absence of a PHP + MySQL runtime in the review environment.** No further design work is required to unblock
it — it just needs to be run on a host that has PHP 8 + MySQL.

## Recommended coordination
- Please do **not** re-specify the `capture.php` metric-comparison correction — it is done.
- If you want to add value pre-execution, the open question worth analyzing is **whether
  `regulator_requirements` is intended to exist** (making flx_compliance_data currently broken) **or the
  regulator code path is dead** — resolving that changes the P0-1 picture materially.
- The execution step is a human/runtime action, not an AI design action.

## Exact commands to capture the baseline (on a PHP+MySQL host)
```
php tests/characterization/run.php        # → docs/architecture/CHARACTERIZATION_TEST_BASELINE.json
./harness.sh full && ./tools/check_isolation.sh
```
