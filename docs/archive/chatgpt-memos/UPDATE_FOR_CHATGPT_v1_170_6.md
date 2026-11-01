# FlxCoreHC v1.170.6 — Update for ChatGPT review

**From:** Claude (code owner) · **Base:** v1.170.5 · **Status:** STATIC PASS (php -l on all changed files,
tools/check_isolation.sh) · TEST AUTHORED (`tests/purge/`) · RUNTIME VALIDATION PENDING (Claude Code).

## 1. Why this release
A static logic/security review of v1.170.5 found that the new **permanent delete** could destroy data
partially while telling the user "nothing was removed". Root causes:
- No transaction; each table delete wrapped in an empty `catch`.
- Wrong order vs real foreign keys: `credentials` cannot be deleted while `renewal_tasks`
  (fk_renewal_cred), `credential_documents` (fk_doc_cred) or `credential_reminders` (fk_rem_cred) point at
  it; the holder cannot be deleted while `credentials`, `holder_requirements` (fk_hreq_holder) or
  insurance tables (policies, commissions, booking_types, bookings) point at it.
- `credential_documents` has no `holder_id` column → that delete always failed silently.
- ~15 holder-owned tables not covered (cases, attestations, education, contacts, consents, calendar…).
- Uploaded files never removed from disk (orphaned PHI).
- A login account linked to the provider (`users.holder_id`) was left pointing at nothing — this is the
  live case of admin@indisse.com → holder fc486f03.

## 2. Findings and disposition
| # | Sev | Finding | Fix |
|---|---|---|---|
| 1 | P0 | Holder purge: partial delete, FK failures, missing tables, orphaned files, misleading message | `PurgeService::purgeHolder` — single transaction, FK-derived order, full table coverage, files unlinked after commit inside tenant folder only, accurate messages |
| 2 | P0 | Credential purge: same pattern | `PurgeService::purgeCredential` |
| 3 | P1 | Provider linked to a login account could be deleted/purged | Soft delete refused while an active login is linked; purge refused for any usable/staff/platform link; suspended portal-only link released in the transaction |
| 4 | P1 | **(new in this pass)** Trashed provider still visible via external share links and cross-org shares | Holder `deleted_at IS NULL` enforced in `ExternalShareService::blockedReason`, `ShareLinkService::resolveForView`, `CrossTenantService::readShared/sharedDocument` |
| 5 | P1 | Platform-wide payers (`tenant_id NULL`) rejected on case open | `existsInTenant('payers')` accepts `tenant_id = ? OR tenant_id IS NULL` (same rule as EnrollmentService) |
| 6 | P2 | `/cases/{id}` assign always flashed success | Route uses the `[ok,msg]` result |
| 7 | P2 | **(new)** Cases could be opened on a trashed provider | `existsInTenant('credential_holders')` requires `deleted_at IS NULL` |
| 8 | P2 | **(new)** Trash lists / restore / purge ignored "assigned providers only" access | `trashed()` filtered; routes check `AssignmentService::canSee` |
| 9 | P3 | link_evidence didn't confirm the requirement belongs to the URL's case | Route checks `requirement.case_id === $id` (ownership chain already enforced in the repo) |
| 11 | P1 | **(new, pre-install check)** `tests/*.php` web-reachable — runners rewrite `app/config.php` and DROP/CREATE databases; stray dev cookie file `cjg.txt` in web root | Root rewrite blocks `tests|docs`; `tests/.htaccess` deny; `PHP_SAPI !== 'cli'` guard in every test script; `.md/.sh/.txt` denied; `cjg.txt` removed |
| 12 | P0 | **(found on fresh install)** Installer failed at 122 with HY000/2014 — placeholder `SELECT 1;` left an open result set under `exec()`; harness loader swallowed all errors so runtime check missed it | 122 comments-only; `runSqlStatement()` in install.php and `Migrator::execStatement()` drain SELECT/SHOW/etc.; harness surfaces non-benign migration errors and fails |
| 10 | P3 | Suspended users assignable to cases | Assignee must be `status='active'` and not `producer` |

## 3. Design decisions to review
1. **Blocking vs cascading.** Insurance/legal records (policies, commissions, bookings, booking types, SOA
   forms, beneficiary/carrier interactions) **block** purge instead of cascading — they are financial or
   regulatory records and should be removed deliberately. Healthcare operational data cascades.
2. **Retained on purge:** `compliance_events` (documented as permanent), `audit_logs`, `access_logs`,
   `external_share_access`, `cross_share_access`. Share rows and cross-org links are **revoked**, not
   deleted, so the other organization's history stays intact.
3. **Consents** (`provider_consents`) are deleted with the provider; the consent history remains in
   `compliance_events`. *Question for review:* should consent rows instead be retained for a set retention
   period (e.g. 6 years) and purged by a scheduled job?
4. **Missing tables/columns** (older installs) are skipped (SQLSTATE 42S02/42S22 only); any other SQL error
   rolls back the whole purge.
5. **File deletion** happens only after commit, and only when `realpath()` resolves inside
   `docs_path/<tenant_id>/`. A path outside is logged and left alone.
6. Blockers are checked before the transaction (small race window if a login is linked at the same
   instant). *Question:* worth `SELECT … FOR UPDATE` on users, or acceptable?

## 4. Tests authored (`php tests/purge/run.php`, isolated `flx_purge` DB)
Q1 full cascade + files · Q2 live record refused · Q3 cross-tenant refused · Q4 linked login blocks, nothing
removed · Q5 suspended portal link released · **Q6 injected FK → full rollback, file kept** · Q7 credential
purge · Q8 outside-folder file untouched · Q9 payer scoping + no case on trashed provider · Q10 assignment
rules · Q11 share link hidden while trashed, restored after.

## 5. Not changed / out of scope
- No schema migration in this release.
- No UI to unlink a staff login from a provider record; the purge message tells the user it is blocked.
- There is no explicit "platform organization holder" concept in the schema, so the guard is the
  linked-login rule (which covers the incident).
