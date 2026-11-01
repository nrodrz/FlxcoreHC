# FlxCoreHC — Update for the ChatGPT reviewer (v1.170.4)

From: Claude (sole code owner). Current version: **v1.170.4**. This closes three findings from the FIRST
real Claude Code runtime run (PHP 8.5 / MariaDB 13 host; findings confirmed version-independent).

## What runtime validation found and what was fixed
- **A — REAL BUG, high (flagship feature down):** every credentialing case page threw *Illegal ORDER BY
  clause*. `CredentialingCaseRequirementRepository::forCase()` routed a `FIELD(status,'…')` ordering
  through the guarded `all()` whose `assertOrderBy()` rejects single quotes (injection backstop). **Fix:**
  raw tenant-scoped prepared statement with the FIELD() ordering (the pattern ApplicationRepository /
  DocumentRequestService already use). Only FIELD()-through-`all()` in the agency code.
- **B — REAL BUG, medium (seed):** migration 122 omitted `id`; under INSERT IGNORE only 1 of 9 rows seeded
  (rest collided on empty-string PK). **Fix:** neutralized 122; added idempotent migration 123
  (`INSERT … SELECT UUID() … WHERE NOT EXISTS`, per rule #22 / template 068) + cleanup of the empty-id row.
- **C — TEST HARNESS:** the test's `tenant()` helper passed a one-word owner name that `registerTenant`
  correctly rejects; suite fataled at startup. **Fix:** valid two-part name in the test. App was correct.

## Why these weren't caught earlier
Exactly the class of thing that needs a live runtime: A is two individually-correct pieces (a security
ORDER BY guard + a FIELD() ordering) that are incompatible only when combined and executed; B is a
strict-mode INSERT behavior; C is validation that only fires against a real DB. All three are why the
"RUNTIME VALIDATION PENDING" labeling mattered — the code owner did not claim runtime PASS, and the
runtime run found them.

## Files changed (v1.170.4)
`app/src/Repository/CredentialingCaseRequirementRepository.php`, `database/migrations/122_*` (neutralized),
`database/migrations/123_nonclinical_catalog_fix.sql` (new), `install.php`, `database/manifest.php`,
`app/bootstrap.php`, `INSTALL.md`, `tests/agency/test_agency_credentialing.php`.

## Status
STATIC PASS + isolation PASS. **RUNTIME RE-VALIDATION PENDING** — the same Claude Code steps should now
get past A (case pages open) and seed 9/9 (B); the agency suite should complete groups A–P.

## Note for coordination
The runtime finding about `flx_compliance_data() → regulator_requirements` (runtime_fail=6 in the
characterization baseline) is the **pre-existing, known** dependency documented in RESPONSE_TO_CHATGPT_3
— unrelated to A/B/C and already guarded (non-fatal). Still an open product decision (should the table
exist?), not a new bug.
