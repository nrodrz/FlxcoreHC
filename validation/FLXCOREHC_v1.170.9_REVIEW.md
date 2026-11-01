# FlxCoreHC v1.170.9 — Independent Review (Claude Code)

**Date:** 2026-09-30  **Reviewer:** Claude Code (independent of the Claude.ai session that wrote the app)
**Environment actually used:** PHP 8.4.19 CLI, MariaDB 10.11.14, Chromium via Playwright, `php -S` dev server.
**Code was NOT modified.** Every finding below was reproduced live or confirmed by reading the cited code.

---

## 1. Executive summary

FlxCoreHC is **much better engineered than typical AI-generated PHP**. Tenant isolation, CSRF, SQL injection defence, encryption and upload handling are all solid, and every automated suite in `CLAUDE_CODE_RUNTIME_CHECK.md` **passes on a real database** (380 checks, exit 0). The fresh install works.

However, the tests mostly confirm what the author already thought about. The independent review found:

- **3 high-severity security issues** that no test catches:
  - a Manager can self-grant any capability, including PII unmasking;
  - suspending a user or resetting their password does not end their live session;
  - a platform admin can bypass the tenant consent gate.
- **2 data-integrity bugs**:
  - editing a provider silently wipes `provider_type` and drops fields;
  - soft-deleted credentials are still counted.
- **A migration runner that can mark a failed migration as "applied".**
- **Need-to-know leaks**: notifications, group/location counts and the audit log show restricted users other providers' data.
- **Domain gaps** that block selling to an NCQA-grade credentialing org or CVO: no NPDB, no committee decision, no 180-day / 36-month timeliness rules, no NPI check-digit validation, and PR seed errors.
- **Maintainability debt**: a 4,909-line `index.php`, 376 raw SQL calls outside the repository layer, heavy insurance-era naming, and a partly cosmetic test suite.

**Verdict:** a good MVP foundation. It is **not production-ready for real provider PII** until the P0 items in §6 are fixed.

---

## 2. Runtime validation results (the checklist Claude.ai asked for)

### Step 1 — Fresh install: **EXECUTED — PASS**
- "Installation complete — created 89 tables", with no "Migration failed".
- `tenants.group_npi` = `varchar(10)` and `tenants.clia_number` = `varchar(20)`, one row each.

### Step 2 — `bash tools/runtime_check.sh`: **EXECUTED — PASS (exit 0, 380 PASS lines)**

| Stage | Result |
|---|---|
| 1 Syntax (`php -l` on all files) | PASS |
| 2 Isolation check | PASS, but the check is ineffective (see B-7) |
| 3 Agency tests | ALL PASS |
| 3a Healthcare-only (28) | ALL PASS |
| 3b Purge + security | ALL PASS |
| 3c Provider-scope static | ALL PASS |
| 3d Authorization suite | ALL PASS |
| 3e Migration scenarios A–D | ALL PASS |
| 3f HTTP smoke: probes plus crawl of 6 roles × 220 pages | ALL PASS, no 5xx |
| 4 `harness.sh` | Not shipped (dev-only) |

> Note: stages 3, 3b, 3d and 3f exit with `SKIP(3)` until `app/config.php` exists. **The installer must be run first**; the doc should say so.

### Step 3 — Upgrade from v1.170.6: **NOT EXECUTED**
No v1.170.6 database was available. Migration scenarios B, C and D (stage 3e) cover it synthetically and pass.

### Step 3b — Security spot-checks (v1.170.8)

| # | Result | Evidence |
|---|---|---|
| 1 Manager → Master Admin | EXECUTED — PASS | "Only a Master Admin can grant…" on change, add and invite. **But see S-1:** the same Manager can escalate through the role editor instead. |
| 2 Staff "assigned only" | EXECUTED — PASS | Credentials, tasks, calendar and CSV export show only the assigned provider; another provider's URL → 404. |
| 3 Staff opens provider page | EXECUTED — PASS | 200, no 500. |
| 4 2FA disable brute force | EXECUTED — PASS | Attempt 9 → "Too many attempts". |
| 5 Audit-Readiness year | EXECUTED — PASS | "Reporting year 2026". |

### Step 3c — Need-to-know and portal (v1.170.9)

| # | Result | Evidence |
|---|---|---|
| 1 Restricted staff | **EXECUTED — PARTIAL FAIL** | **Pass:** dashboard, case KPIs, pasted IDs (404), and assign/unassign taking effect immediately. **Fail (a):** `/notifications` and the bell (12 unread) list other providers' names. **Fail (b):** group and location *list* counts are unfiltered for a restricted Manager ("Clinica Bayamon — 3" when only 1 is assigned). **Fail (c):** staff menu shows Groups and Locations, but they return 403. |
| 2 Coordinator: no Verify buttons | EXECUTED — PASS | |
| 3 Custom portal role | EXECUTED — PASS | Lands on `/me`; other provider's document → 404; unlinked login → 403 with no loop. |
| 4 iCal | EXECUTED — PASS | Only the assigned provider's events; suspended user → 404. |
| 5 Audit events | EXECUTED — PASS | `user_role_changed`, `owner_level_role_granted` and `share_link_revoked` all present. |

### Step 4 — Browser spot-checks

| # | Result |
|---|---|
| 1 Sign-up (no "Other", PR preselected, PR ticked after approval) | EXECUTED — PASS |
| 2 Compliance ruleset / "Providers" tab | EXECUTED — PASS. The ruleset line is under the tabs, not in a footer. |
| 3 Provider page (no NIPR/NPN; CME categories; 24-month total updates) | EXECUTED — PASS |
| 4 Entity compliance (NPI `123` rejected, CLIA `40D1234567` saved) | EXECUTED — PASS |
| 5 Public verification link | EXECUTED — PASS with caveat: **specialty is never shown**, because it cannot be saved (bug B-1). |
| 6 Audit-Readiness package title | EXECUTED — PASS |
| 7 Case form dropdowns | EXECUTED — PASS |
| 8 Calendar event types | EXECUTED — PASS |

### Problems in the checklist and tooling itself
- `runtime_check.sh:65` says "browser spot-checks … **Step 6**", but the doc has no Step 6.
- The doc says the smoke test "logs in as **8 roles**", but the crawl covers **6**.
- The demo-admin password is `coastal-demo-2026`, not `FlxDemo2026!` as the doc implies.
- Running `php -S … index.php` (as the smoke tool does) sends `/assets/*` and `/install.php` through the front controller. The pages then have no CSS/JS and the installer redirects to itself. A tiny `router.php` that mirrors `.htaccess` should ship in `tools/`.

---

## 3. THE GOOD

1. **Tenant isolation by construction.**
   - `TenantRepository` injects `tenant_id` into every find, all, count, insert and update.
   - Column and ORDER BY names are allow-listed.
   - 29 repositories extend it.
2. **Need-to-know (provider scope) is enforced in SQL and fails closed.**
   - `ProviderScope.php:52-63` builds the scope into the query.
   - `AssignmentService.php:45-75` returns "sees nothing" when a lookup fails.
   - EXPLAIN shows the query uses an indexed semijoin, so it is efficient.
3. **Security baseline is strong.**
   - CSRF on every POST, with a 256-bit token compared with `hash_equals`.
   - All SQL uses prepared statements.
   - Passwords use Argon2id with rehash-on-login, a dummy hash for timing parity, and per-IP and per-account throttling.
   - Nonce-based CSP with no `unsafe-inline`, plus HSTS, X-Frame-Options DENY and nosniff.
   - Open-redirect protection, and email headers encoded per RFC 2047.
4. **Encryption is done right.**
   - XChaCha20-Poly1305 or AES-256-GCM, with AAD bound to tenant, table and column, and HMAC blind indexes.
   - Refuses to store plaintext in production.
   - PII reveal is gated behind `pii.view` and every reveal is written to the access log.
5. **Uploads.**
   - MIME type checked with finfo against an allow-list, stored outside the web root under UUID names, and EXIF metadata stripped.
   - `uploads/.htaccess` blocks PHP execution.
6. **Public links.**
   - External shares use hashed 192-bit tokens, a password, an expiry, a view cap and lockout.
   - Verification links can be revoked, and iCal honours need-to-know and suspension.
7. **Migrations are exercised for real.**
   - Scenarios A–D cover fresh install, legacy data and upgrades from older versions.
   - There is a `schema_migrations` tracking table.
8. **The schema is consistent.**
   - utf8mb4 everywhere, InnoDB, UUID keys and 23 CHECK constraints.
   - Soft deletes are used on core entities.
9. **Puerto Rico domain content is well researched.**
   - ORCPS, JLDM, OCS/SICRO (Ley 73-2023), PEP revalidation, ASES/Vital, ASSMCA and Ley 8-2025 telehealth.
   - PR payers are seeded (Triple-S, MCS, MMM…).
   - Buena Conducta and ASUME are included.
10. **Primary source verification (PSV) records are complete.**
    - Each one stores the source, method, outcome, verifier, date, evidence and re-verify date.
    - NPPES checks are automated with a name-mismatch check.
    - SAM uses its API, and LEIE is imported from the monthly file.
    - When no list is loaded, a screening is never recorded as "clear".
11. **Spanish UI exists** (about 1,270 strings, plus Spanish help), and sessions time out after 30 minutes idle or 12 hours absolute.
12. **CLI scripts refuse to run from the web**, and every support directory ships `Require all denied`.
13. **Static analysis:** PHPStan level 5 on `app/src` found **no type errors**, only dead code.

---

## 4. THE BAD — findings (severity-ranked, with file:line and fix)

### 4.1 Security

**S-1 HIGH — A Manager can grant their own role any capability, including `pii.view`** (verified live)
- **Where:** `index.php:2298-2315` → `RoleService::update()` `app/src/RoleService.php:260-290` (also `create()`).
- **Cause:** the route only requires `team.manage`, and a role may be given any capability in `Capabilities::all()`.
- **Exploit:** a Manager POSTs `action=update&capabilities[]=…` to `/organization/roles/{managerRoleId}`. Their role went from 17 to 18 capabilities and gained `pii.view`, which reveals SSN last-4 and DOB. This undoes the v1.170.8 fix.
- **Fix:**
  - Reject any capability the acting user does not hold: `array_diff($caps, Authz::capsFor($actor)) === []`.
  - Only `tenant_owner` may edit system roles.
  - Add a regression test.

**S-2 HIGH — Suspending, resetting, demoting or changing a password does not end the user's live session** (verified live)
- **Where:** `TenantContext::fromSession()` `app/src/TenantContext.php:35-55`. The role and status come from the session; only `platform_admin` is re-checked (`index.php:91`).
- **Exploit:** after being suspended and having their password reset, a Manager's old cookie still loaded `/team` and could still create locations, for up to 12 hours.
- **Fix:**
  - On every request, reload `users.status` and `role` plus a new `session_version`.
  - Increment `session_version` on password change or reset, suspension, role change and 2FA disable.

**S-3 HIGH — A platform admin can bypass the tenant support-consent gate**
- **Where:** `index.php:3578-3601`; `SuperadminService::resetUserPassword` (:133), `createUserInTenant` (:535), `setUserRole` (:325).
- **Cause:** impersonation needs a tenant grant, but a platform admin can simply reset a tenant owner's password, or create a new owner, and sign in with full PII access. Platform admins are also not required to use 2FA.
- **Fix:**
  - Put these actions behind `SupportAccess::activeGrant`, or send an emailed reset link instead of setting the password.
  - Notify the tenant owner.
  - Require TOTP for every `platform_admin`.

**S-4 MEDIUM — HTML injection into a `<script>` block** (verified live)
- **Where:** `app/views/calendar/index.php:206-207`: `json_encode($emap, JSON_UNESCAPED_SLASHES)`. The same pattern is at `app/views/credentials/form.php:266`.
- **Exploit:** an event title such as `</script><h1>…` renders as raw markup for the whole tenant. CSP stops script execution, but phishing forms and a broken calendar UI remain.
- **Fix:** `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`, as `reports/credentials.php:121` already does. Better, add a `json_script()` helper.

**S-5 MEDIUM — The tenant SMTP settings can be used to probe the internal network**
- **Where:** `TenantMailService::save` (`app/src/TenantMailService.php:43-58`) accepts any host and any port 1–65535. The "Test" action echoes the raw socket error (`index.php:2169-2179`).
- **Exploit:** point the SMTP host at an internal address and compare the errors to map open ports.
- **Fix:**
  - Resolve the host and reject private, loopback, link-local and metadata ranges.
  - Allow only ports 25, 465, 587 and 2525.
  - Show a generic error message.

**S-6 MEDIUM — TOTP secrets are stored in plaintext and codes can be replayed**
- **Where:** `AuthService.php:415-417`, `Totp::verify` :44-53.
- **Fix:** encrypt the secret with `Crypto`, and store `totp_last_counter` so a used code is rejected.

**S-7 MEDIUM — The "require 2FA for admins" check is keyed on role names, not capabilities**
- **Where:** `index.php:756-762`.
- **Cause:** custom admin roles are exempt, and `/billing` and the dashboard are reachable before the check runs.
- **Fix:** base it on capabilities (`team.manage`, `org.manage`, `pii.view`) and move it right after the lifecycle gate.

**S-8 LOW**
- Anyone who knows an email address can keep that account locked out, because a hard per-account lock applies even to the correct password (`AuthService.php:246-249`).
- `/settings` checks role names instead of `org.manage` (`index.php:3897`).
- GET requests with side effects:
  - `?setlang=` writes to the DB;
  - GET `/compliance` runs reminders and sends mail (`:4304`);
  - GET `/admin` flushes mail (`:3121`).
- The installer has no CSRF token or one-time install secret.
- `share_links`, `invite_token` and `ical_token` are stored in plaintext (`external_shares` correctly hashes its tokens), and iCal tokens never expire.
- `Crypto::decrypt` accepts dev-plaintext and legacy empty-AAD data in production.

### 4.2 Functional and data-integrity bugs

**B-1 HIGH — Editing a provider silently loses data** (verified live and in code)
- **Where:** the create path allows 14 provider types (`index.php:1277`); the edit path allows only `physician, nurse, lab, clinic, group` (`index.php:2051`), and `:862` allows only `physician, nurse`.
- **Impact:** saving an unchanged PA, NP, therapist or similar sets `provider_type` to **NULL**. The page still says "Holder updated."
- **Also:** Primary specialty, CAQH, PTAN and other form fields are not saved at all. That is why the public verification page never shows specialty.
- **Fix:**
  - One shared `PROVIDER_TYPES` constant used by create, edit and import.
  - Persist every field the form posts.
  - Add an HTTP test that runs a round-trip edit.

**B-2 HIGH — Soft-deleted credentials are still counted**
- **Where:** `HolderRepository.php:82` (`withCredentialSummary`) and `:99` (`trashed`) join `credentials` without `c.deleted_at IS NULL`. There are 7 such joins in total.
- **Impact:** the provider list over-counts credentials, expiring items and expired items.
- **Same bug in cron:** `cron/sweep_expiring.php:46-52` flips *deleted* credentials to `expiring`.
- **Fix:** add the filter everywhere, and add a test.

**B-3 HIGH — The migration runner can record a failed migration as applied**
- **Where:** `Migrator.php:15` `BENIGN` includes:
  - 1054 unknown column, 1146 missing table, 1364 no default value;
  - 1005 FK creation failed, 3821/3822 CHECK constraint errors.
- **Cause:** those errors count as "skipped", and the file is then inserted into `schema_migrations` (`:66-72`). A typo in a future migration is silently marked done and never retried.
- **Also:**
  - The UI never shows the skipped count.
  - There is no lock against concurrent runs.
  - Migration numbers are duplicated (`098_*` ×2, `099_*` ×3).
  - `install.php` keeps its own copy of the migration order and its own statement splitter, separate from `manifest.php` and `Migrator`.
- **Fix:**
  - Tolerate only "already exists" codes (1050, 1060, 1061, 1062, 1091, 1826, 1022).
  - Show the skipped count; add `GET_LOCK`.
  - Make the installer use `Migrator` and `manifest.php`.

**B-4 MEDIUM — Need-to-know leaks outside the core lists** (verified live)
- `/notifications` and the bell show restricted users other providers' names. Items created before the restriction are never re-filtered.
- Group and location **list counts** are unfiltered.
- `/audit` for a restricted Manager shows other providers' names.
- **Fix:**
  - Filter notifications in SQL through `ProviderScope` at read time, not only when they are created.
  - Scope the aggregate counts and the audit view.

**B-5 MEDIUM — The team role dropdown lists roles that don't exist in the tenant**
- **Symptom:** Master Admin appears twice; "Admin", "Regular User", "Viewer" and "Provider" appear twice.
- **Impact:** choosing one of them silently saves the user as **Staff** with the message "Role updated." During QA a Manager demoted himself this way.
- **Fix:** list only the roles that exist in the tenant, and reject unknown slugs.

**B-6 MEDIUM — CSV exports break on PHP 8.4**
- **Where:** `fputcsv()` is called without the `$escape` argument (`index.php:3829`, `:3834` and the other export paths).
- **Impact:** a Deprecated notice. In dev mode it is written into the CSV body and triggers "headers already sent", so `/reports/credentials/export` is served as text/html.
- **Fix:** pass `escape: ''` (or `"\\"`) explicitly.

**B-7 MEDIUM — The tenant-isolation check (`tools/check_isolation.sh`) gives false confidence**
- It scans `app public`, but `public/` does not exist, so the 33 raw queries in `index.php` are never scanned.
- It allow-lists 73 files; 11 of them no longer exist.
- It cannot fail.
- **Fix:** scan `index.php` too, clean the allow-list, and fail when a new file uses raw PDO.

**B-8 LOW — UX and wording issues**
- The staff menu shows Groups, Locations, Entity compliance, Regulators and Applications, which then return 403 or silently redirect to `/`.
- Leftover or unclear wording:
  - "Holder updated.", the "Holder" label, and the CSV header "Holder, Holder type";
  - the installer placeholder "Brightline Benefits".
- Raw internal values shown to users:
  - the case list shows raw ID prefixes instead of provider names;
  - the audit log shows raw slugs and IDs.
- Wrong labels:
  - the audit package's authority column reads "federal federal" (malpractice is tagged FEDERAL);
  - the 2FA confirm page says "Off" next to "Two-factor is on".
- Wrong or misleading guidance:
  - "Configure under Platform → Email" is shown to tenants, who can't reach that page;
  - the iCal URL is shown as `https://` on an http install;
  - the Coastal demo org says "Operating states: FL" although all its data is PR;
  - the "Getting started" checklist is shown to restricted staff.
- A read-only user cannot set up 2FA.

### 4.3 Healthcare domain and compliance gaps

**D-1 CRITICAL — Required NCQA credentialing elements are missing**
- **No NPDB query tracking.** Zero occurrences of "npdb" in the codebase.
- **No credentialing committee or clean-file decision** (approve/deny/defer, approver, decision date). The case statuses end at `submitted → completed → closed` (`CredentialingCaseService.php:18-26`), which is a payer-submission flow, not a credentialing decision.
- **No timeliness rules:**
  - no check that verifications are ≤180 days old at decision time;
  - no 36-month re-credentialing cycle — `recredentialing_date` is free text.
- **PSV data is split across 3 stores** (`credential_verifications`, `source_verifications`, and the verification columns on documents). No catalog type says which sources are acceptable for PSV. So "was every element PSV'd from an approved source?" cannot be answered for an audit.

**D-2 HIGH — Full SSN is never stored** (`118_pii_fields.sql`)
- CMS-855I, CAQH, PEP and NPDB queries all need the full SSN.
- **Fix:** store it encrypted, gated behind a capability, and access-logged.

**D-3 HIGH — NPI validation checks length only**
- **Where:** `index.php:857,1405`, `OrgService.php:142`, `NpiService.php:24`.
- **Missing:**
  - the Luhn check digit with the `80840` prefix;
  - a check that a Group NPI is NPI Type 2;
  - DEA and taxonomy format checks.

**D-4 HIGH — Exclusion screening is weak**
- Matching is exact NPI or exact name only; DOB, REINDATE and prior names are ignored.
- The LEIE download is triggered by an admin; cron re-screens against a list that may be stale, with no staleness alert.
- SAM matching is by name only.
- Not covered: Medicare Opt-Out, the CMS Preclusion List and the SSA Death Master File.

**D-5 HIGH — Errors in the Puerto Rico seed data**
- The board is named "PR Board of Medical Examiners"; it has been JLDM since 2008.
- `pr_medical_license` and `pr_cme` also apply to nurses, who are licensed by the Junta de Enfermería.
- The negative drug-test certificate is attributed to a DOH lab in one place and to ASSMCA in another, and `state_cds` wrongly satisfies it (`ApplicationReadinessService.php:40`).
- CME is totalled over **24 months**, but PR uses a **36-month / 60-hour** cycle.
- PR-only readiness items (OCS, PEP, PR licence) apply to every tenant.
- The tenant jurisdiction still falls back to **FL** (`OrgService.php:66`, `CatalogService.php:260`).

**D-6 MEDIUM — Data protection priorities are inverted**
- Encrypted: NPI (a public number) and email.
- Plaintext:
  - **uploaded documents** (licence and ID scans);
  - **residential addresses**;
  - **backups** (gzip only; `BackupService.php` also builds the whole dump in memory).
- There is no retention schedule and no legal hold.
- Provider-record reads are not access-logged; only reveals, downloads and exports are.

**D-7 MEDIUM — Missing workflow features**
- No work-history gap calculation (the CAQH >6-month rule).
- Attestation is a single 600-character text field: no disclosure questions and no signed release.
- The Spanish UI is incomplete:
  - 73 of 119 views have no `t()` call, including login, the public verify page and reports;
  - 73 keys are missing from `es.php`;
  - tenants default to `en`.

### 4.4 Architecture and maintainability

**A-1 — Monolithic routing**
- `index.php` is **4,909 lines**. The `holders` block alone is 984 lines and `admin` is 652.
- It contains 33 inline SQL queries.
- There is no router, no controllers and no middleware.

**A-2 — Tenant scoping depends on discipline, not structure**
- **376 `Database::pdo()` calls** sit in services outside the repository layer.

**A-3 — No Composer, no PHPUnit and no CI**
- Of the 57 provider-scope "tests", **46 are regex/`str_contains` checks over source text**, not behavioural tests.
- `test_healthcare_only.php` `eval()`s a function extracted from the source by regex.
- The test runners **overwrite the live `app/config.php`**, so a killed run on a real server leaves the test config in place.

**A-4 — Insurance-era leftovers**
- Counts: "producer" 449 hits, "carrier" 323, "insurance" 194, "NPN" 56, "holder" 2,679.
- Still in the schema:
  - tables `producer_requirements` and `nipr_interest`;
  - columns `npn_enc`, `entity_npn`, `agency_license` and `producer_price`;
  - `fk_cred_carrier*` FK names.
- Dead code:
  - the empty `CatalogService` constants;
  - `RequirementRepository` and `MiniZip`;
  - a `carriers` table in `softDeletes()`;
  - `002_ma_cms.sql`, which is not in the manifest.

**A-5 — Scale risks**
- No pagination on the provider list or most other lists.
- `TenantRepository::all()` silently caps results at 500.
- N+1 queries in group/location member lists and in `ReminderService`.
- Cron jobs have no `flock`.
- Only 2 of 30 `holder_id` tables have a foreign key.
- `holder_id` is missing an index on `users`, `renewal_tasks` and `calendar_events`.
- Document paths are stored as **absolute paths**, so moving hosts breaks every file link.

**A-6 — i18n by string replacement**
- The whole rendered HTML is run through exact-match string replacement (`I18n::translateHtml`). This is fragile and slow.

**A-7 — Documentation drift**
- `PRODUCT.md` and `KICKOFF.md` are frozen at v1.87 and still say "no PSV".
- `INSTALL.md` is 3,199 lines of upgrade notes, including insurance sections.
- 9 of the 23 files in `docs/architecture/` are ChatGPT hand-off memos.

---

## 5. What the existing tests don't prove

The suite is well designed for **regressions of previously found bugs**. It does not independently cover:
- privilege escalation through the role editor (S-1);
- session invalidation (S-2);
- data round-trips on edit (B-1);
- soft-delete aggregates (B-2);
- read-side need-to-know on secondary screens (B-4);
- migration-runner failure handling (B-3).

Treat "ALL PASS" as necessary, not sufficient.

---

## 6. Recommendations (prioritized)

### P0 — before any real provider data
1. Fix **S-1** (capability ceiling on role create/update) and add a test.
2. Fix **S-2** (per-request check of user status, role and `session_version`).
3. Fix **S-3** (grant-gate admin password reset and owner creation; require 2FA for platform admins).
4. Fix **B-1** (single provider-type list; persist every form field; round-trip test).
5. Fix **B-2** (`deleted_at IS NULL` on the 7 joins and in `sweep_expiring.php`).
6. Fix **B-3** (narrow `Migrator::BENIGN`; never record a partial migration as applied; show skipped counts; add a lock).
7. Fix **B-4** (notifications, aggregate counts and audit through `ProviderScope`).
8. **Encrypt uploaded documents and backups at rest.**

### P1 — next 2–4 releases
9. S-4 to S-7 (JSON escaping helper, SMTP host/port restrictions, encrypted TOTP with replay protection, capability-based 2FA policy).
10. B-5 to B-7 (role dropdown, `fputcsv` on PHP 8.4, a real isolation check).
11. D-3 (NPI Luhn check and NPI-2 for groups, DEA and taxonomy validation).
12. D-5 (PR seed corrections: JLDM, nursing board, 36-month CME, jurisdiction-aware readiness, remove the FL fallback).
13. Stop the test runners from overwriting `app/config.php` (use an `FLX_CONFIG` env var). Ship `tools/router.php` for `php -S`.

### P2 — product and strategic
14. **Decide the market position.** Either stay a "credentialing tracker / payer-enrollment tool", or become an NCQA-grade credentialing system (D-1):
    - NPDB;
    - committee and clean-file decisions;
    - a 180-day verification-age gate and auto re-credentialing at 36 months;
    - one unified PSV model;
    - full SSN (encrypted).
15. Exclusion hardening (D-4): automatic monthly LEIE download plus a staleness alert, fuzzy matching using DOB, Opt-Out, Preclusion and DMF.
16. Complete Spanish (D-7) and allow a tenant default of `es`. PR users will expect it.
17. **Engineering foundation:**
    - Composer (PSR-4) plus PHPUnit plus PHPStan (level 6 with a baseline) plus CI (GitHub Actions); keep shipping a vendor-bundled zip for cPanel.
    - Split `index.php` into a router and per-area controllers, starting with `holders` and `admin`.
    - Move service SQL behind scoped repositories.
    - Replace source-grep tests with HTTP behavioural tests.
18. A one-pass domain rename (holder→provider; drop `npn_*`, `producer_*`, `nipr_*`, `carrier`), plus pagination, indexes and FKs, relative document paths, and streamed backups.
19. Documentation clean-up:
    - move release notes to a `CHANGELOG`;
    - update `PRODUCT.md` to reflect the current product;
    - archive the ChatGPT memos;
    - fix the runtime-check doc ("Step 6", "8 roles", the demo-admin password, and "run the installer before `runtime_check.sh`").

---

## 7. Suggested message to Claude.ai

> Independent review of v1.170.9 by Claude Code on PHP 8.4 + MariaDB 10.11. The fresh install and all runtime_check stages pass (380/380). However, please fix the following in v1.171.0, each with a behavioural (DB/HTTP) regression test rather than a source-grep test:
> 1. S-1 Manager self-grants capabilities via /organization/roles (RoleService::create/update must cap to the actor's own capabilities; system roles owner-only).
> 2. S-2 Sessions are not invalidated on suspend/password reset/role change (add a per-request check of users.status/role/session_version).
> 3. S-3 Platform admin can reset a tenant owner's password or create an owner without a SupportAccess grant; require TOTP for platform admins.
> 4. B-1 Provider edit (index.php:2051, :862) accepts 5 of 14 provider types, nulling PA/NP/etc., and drops specialty/CAQH/PTAN.
> 5. B-2 Missing `c.deleted_at IS NULL` in HolderRepository.php:82/:99 (+5 other joins) and cron/sweep_expiring.php:46.
> 6. B-3 Migrator::BENIGN tolerates 1054/1146/1364/1005/3821/3822 and then records the file as applied.
> 7. B-4 Need-to-know leaks: /notifications + bell, group/location list counts, /audit.
> 8. B-5 Team role dropdown shows non-existent roles and silently saves Staff; B-6 fputcsv $escape on PHP 8.4; S-4 json_encode into <script> without JSON_HEX_TAG (calendar/index.php:206, credentials/form.php:266).
> 9. Encrypt uploaded documents and backups at rest.
> Then address the domain gaps: NPI Luhn check, the PR seed corrections (JLDM, nursing board, 36-month CME, jurisdiction-aware readiness, FL fallback), and a plan for NPDB, committee decisions and NCQA timeliness.
