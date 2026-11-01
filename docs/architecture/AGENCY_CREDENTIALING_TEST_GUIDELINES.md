# Agency Credentialing — Test Guidelines
**Applies to:** FlxCoreHC v1.168.0 · **File:** `tests/agency/test_agency_credentialing.php`
**Status of the suite:** TEST AUTHORED — RUNTIME VALIDATION PENDING (authored without a PHP/MySQL runtime;
never reported as RUNTIME PASS until actually executed).

This document is the reference for what the Agency Credentialing tests cover, how to run them, and the
rules any new test must follow. It is safe to hand to a second reviewer (e.g. ChatGPT) so both tools test
against the same expectations.

---

## 1. How the tests run (runtime environment)
- **Requires:** PHP 8 CLI + MySQL/MariaDB. The tests run against an **isolated throwaway database**
  named `flx_char` — never production. They reuse the same isolated-DB bootstrap approach as
  `tests/characterization/run.php` (it swaps `app/config.php` to point at `flx_char`, then restores it).
- **Run:**
  ```
  php -l tests/agency/test_agency_credentialing.php     # syntax check
  php tests/agency/test_agency_credentialing.php         # runs all assertions; prints PASS/FAIL per line
  ./harness.sh full                                      # route smoke test (ALL_ROUTES_PASS)
  ./tools/check_isolation.sh                             # static isolation check (no PHP needed)
  ```
- **Exit code:** 0 = all pass; 1 = one or more failed. The last line prints `ALL PASS` or `SOME FAILED`.
- **Never** run against a production database, and **never** alter `schema.sql` or migrations for test
  convenience. If the runtime is unavailable, classify results as `TEST AUTHORED — RUNTIME VALIDATION
  PENDING`, never `RUNTIME PASS`.

## 2. Fixture model (deterministic)
Each run builds two isolated tenants (A and B), each with an owner user and at least one physician
provider, via the real code paths (`AuthService::registerTenant`, `seedTemplatesFor`, `HolderRepository`).
This lets the suite prove both **cross-tenant** isolation (A vs B) and **within-tenant cross-case**
isolation (case c1 vs c2 in tenant A). Requirement codes come from the live `credential_catalog`
(`CatalogService::labelsFor`), never invented.

## 3. Coverage — current assertions (v1.168.0, 37 total)
Grouped by concern. Each line is an executable assertion in the suite.

### A/B/C — Tenant isolation (the top invariant)
- **A** — Tenant B cannot READ tenant A's case, nor tenant A's case requirement.
- **B** — Tenant B cannot UPDATE or DELETE tenant A's case (base repo scopes by tenant → no rows affected).
- **C** — A provider shared via the consent/link mechanism does NOT expose the owning tenant's cases
  (shared provider ≠ shared cases).

### D/F — Evidence ownership integrity
- **D** — Evidence link rejected when the target is: (i) a different tenant's row, (ii) the same tenant
  but a different provider; accepted only for a valid same-provider target.
- **F** — Exactly-one-target rule: providing multiple evidence targets is rejected.

### E — Case-creation FK validation
- **E** — Case creation is rejected for an invalid holder, invalid client group, invalid payer, and an
  assignee from another tenant. Nothing is created on failure.

### F — Requirement code validation
- Adding a requirement with a code that is not in the active `credential_catalog` is rejected.

### G — Requirement mutation via the service
- Changing a requirement's status through `CredentialingCaseService` writes a case-history event.

### H — Hard-stop fails CLOSED
- When the provider-level compliance evaluation cannot complete, the protected `ready_for_submission`
  transition is blocked with a `compliance_check_error` (never treated as "no blocker").

### I — Backward compatibility (case_id nullable)
- `applications` inserts work with `case_id` NULL; `compliance_events::record()` works without a
  `case_id` (existing consumers unaffected).

### J — credential_documents unchanged (real assertions)
- **J-A** — `credential_documents.credential_id` is still NOT NULL (verified via information_schema).
- **J-B** — a valid credential-scoped document can still be created.
- **J-C** — a NULL `credential_id` insert is REJECTED (proves the column was not made optional).

### K — Assignment ownership
- A non-existent assignee and an assignee from another tenant are both rejected; valid same-tenant
  assignment and unassignment (null) succeed.

### L — Cross-case mutation within one tenant
- Case A cannot delete Case B's document or unlink Case B's evidence (same tenant); the rightful case can.

### M — Template-driven requirement population
- `populateRequirements()` seeds a case's requirements from the provider's profession template; running
  it again is idempotent (no duplicates).

### N — Evidence reuse (validity, no auto-satisfy)
- Reusable evidence marks an EXPIRED credential as not valid and a CURRENT one as valid; the presence of
  existing evidence does NOT auto-satisfy a requirement (human selection required).

## 4. Coverage matrix vs the directive's minimum list
| Required area | Covered by | Status |
|---|---|---|
| Case creation | E (validation), M (population) | AUTHORED |
| Automatic/template requirement population | M | AUTHORED |
| Duplicate prevention | M (idempotent), plus repo unique (case,code) | AUTHORED |
| Evidence reuse | N | AUTHORED |
| Invalid evidence rejection | D, F | AUTHORED |
| Requirement status | G | AUTHORED |
| Hard stops | H | AUTHORED |
| Application association | I (case_id nullable) | AUTHORED |
| Verification association | I (case_id nullable on source_verifications) | AUTHORED (association-level) |
| Case completion | transition state-machine (open→…→completed) exercised via H setup | PARTIAL — see gaps |
| Work-queue tenant isolation | A/B/C establish tenant scoping; queue queries are tenant-scoped by construction | PARTIAL — see gaps |
| credential_documents unchanged | J-A/B/C | AUTHORED |
| Assignment/cross-case integrity | K, L | AUTHORED |

## 5. Rules for any NEW test (so the suite stays trustworthy)
1. **A test must be able to FAIL.** (The old "test J" set a pass flag on both success and exception and
   could never detect a regression — it was replaced. Do not write assertions like that.)
2. **Use the service/repository layer**, not raw SQL, for anything that has ownership rules — so the test
   exercises the real validation, not a bypass.
3. **Prove rejection, not just acceptance.** For every "valid X is accepted," add the matching "invalid/
   cross-tenant/cross-case X is rejected."
4. **Never weaken a protection to make a test pass.** If a test can't pass without loosening validation,
   the test is wrong or the finding is real — stop and report it.
5. **Deterministic fixtures only** — fixed provider/tenant setup, dates relative to a clear anchor; no
   reliance on ambient data.
6. **Isolated DB only** (`flx_char`); never production; never edit schema/migrations for convenience.
7. **Label honestly:** STATIC PASS (structure verified, not executed) · TEST AUTHORED (written, not run)
   · RUNTIME PASS (only after actual execution) · RUNTIME VALIDATION PENDING.

## 6. Known gaps / recommended additional tests (not yet authored)
These are honest gaps in the current suite — good candidates for the next test pass:
- **Case completion end-to-end:** open → in_progress → ready_for_submission (all hard stops resolved) →
  submitted → completed, asserting `completed_at` and history at each step, and that a completed case is
  retained (not deleted).
- **Application creation from a case** and asserting `case_id` is set (currently only nullability is tested).
- **Verification association to a case** (writing a `source_verifications` row with a `case_id` and
  asserting it surfaces on the case) — currently only the nullable column is proven.
- **Document-request from a missing requirement** with `case_id` set, and appearing in the case workspace.
- **Work-queue bucket correctness:** seed known data and assert each bucket's count for tenant A excludes
  tenant B's rows (stronger than the current construction-only guarantee).
- **`is_hard_stop` gate with multiple requirements** (mix of resolved/unresolved) to confirm partial
  resolution still blocks.
- **Evidence unlink history** event assertion (link is implied; unlink event should be asserted).

## 7. What the tests deliberately do NOT do
- They do not test the UI/templates directly (business rules live in the service; the UI calls it).
- They do not assert on ongoing provider compliance (`holder_requirements`) as if it were case
  completion — the two are kept separate by design.
- They do not create or require any of the prohibited structures (no `work_queue`, `client_organizations`,
  `authorities`, `regulators`, or a second provider/requirement/verification/application master).
