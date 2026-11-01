# FlxCoreHC v1.170.9 — Response to the Senior Engineering Review

**From:** Claude (code owner) · **Base:** v1.170.8 · **Release status: Production Candidate** — not "Production Ready".
Blocking static findings are resolved and the DB-backed suites now EXECUTE (below), but two things are still outside what I can run:
(1) browser validation, (2) the target host's actual MySQL/cPanel stack (everything here ran on **PHP 8.4.21 + MariaDB 10.11.14** in a Linux container).

Evidence labels used throughout: **EXECUTED — PASS / EXECUTED — FAIL / STATICALLY REVIEWED / REQUIRES EXTERNAL RUNTIME VALIDATION / REQUIRES BROWSER VALIDATION**.
Change control honoured: no new features, no UI redesign, no framework/DB change, no rewrite; one new SQL helper class, no new migration.

## 0. What I implemented, what I did not, and why (per your instructions)

| # | Your instruction | Disposition | Why / detail |
|---|---|---|---|
| 1 | Central, fail-closed provider scope **inside SQL** | **IMPLEMENTED** | `ProviderScope` (correlated `IN (SELECT holder_id FROM holder_assignments …)`, tenant+user bound, never inlined ids) + `TenantRepository::scopeSql()` applied in find/all/count/update/delete; 17 provider-bearing repositories (16 by holder column + Documents via credential) declare `providerColumn()`/`credentialColumn()`. Unrestricted users get `''` — SQL unchanged. `ntkFilter/ntkRow` deleted. |
| 2 | DashboardService (HIGH) | **IMPLEMENTED** | Every count, forecast month, queue, throughput tile and the `audit_logs` count is scoped. Proven by a differential test: on v1.170.8 a restricted user's dashboard **names the unassigned provider**; on v1.170.9 it does not. |
| 3 | Audit every provider-related query | **IMPLEMENTED + documented** | `docs/architecture/DIRECT_SQL_INVENTORY_v1_170_9.md` (section D). Two residual items I chose **not** to change: R1, R2 (section G). |
| 4 | Aggregate/COUNT security | **IMPLEMENTED** | dashboard, work queue, calendar dayCounts/overdue, applications/screening KPIs, `statusCounts`, group/location member lists, external-share oversight list. |
| 5 | Pagination/LIMIT correctness | **IMPLEMENTED — EXECUTED PASS** | Test L: 60 credentials on P2 + 5 on P1, `LIMIT 10` → restricted user gets all 5 of P1 (scope is before LIMIT). |
| 6 | Direct-SQL inventory table | **DONE** | Section D and the doc above. |
| 7 | Fail-closed catch blocks | **PARTIAL (deliberately)** | Fixed where a failure could read as "zero/compliant": DashboardService, AgencyWorkQueueService, TenantReportService (counted, logged, **shown** as "figures incomplete"), ReminderService/NotificationService scans (now log). `AssignmentService` was **fail-open** (unknown user row → `'all'`); now fail-closed. Left as best-effort by design: notification pushes, telemetry, seeding — none decide access or compliance. |
| 8 | Document authorization; keep `records.write` / `documents.manage` separate | **VERIFIED + FIXED** | Kept separate (answers your question). Found two real gaps — see V3, V4. Matrix in section B-doc. |
| 9 | Reserved roles | **NOT REDESIGNED** | No new vulnerability found beyond the two reserved slugs; I did **not** add an `is_system/privilege_level` schema change or a wider blacklist (you said only if a verified vulnerability). Principle now holds: privilege never derives from a human-typed role name. One real defect found in 125's rename (V7). |
| 10 | Migration scenarios A–D | **EXECUTED — PASS (MariaDB)** | `tests/migrations/run_scenarios.php`, 30 checks. Found and fixed a collision defect in 125 (V7). Not executed on MySQL 8 / the cPanel host. |
| 11 | Cross-tenant / cross-provider / authorization-matrix tests | **IMPLEMENTED — EXECUTED PASS** | `tests/authz/run.php`, 145 checks (X, N, L, M, W, P, F, D groups). |
| 12 | Portal re-tests incl. redirect-loop regression | **EXECUTED at HTTP level; loop path STATICALLY REVIEWED** | Portal + custom-portal logins tested over HTTP. The "unlinked portal login → 403 not loop" branch is unreachable through the app now (TeamService refuses to create one — EXECUTED PASS); the `/me` 403 branch itself is STATICALLY REVIEWED. |
| 13 | iCal re-test | **PARTIAL** | Feed content scoping and the token→active-user predicate are EXECUTED (repository + SQL). The `/calendar/*.ics` HTTP route was not exercised — STATICALLY REVIEWED. |
| 14 | Export security | **PARTIAL** | Exports read through scoped repositories (EXECUTED at repository level); CSV formula guard asserted (EXECUTED). Export downloads were not crawled over HTTP — STATICALLY REVIEWED. |
| 15 | Audit-log coverage | **REVIEWED + 4 events added** | Section B-audit. |
| 16 | Legacy identifiers / index.php debt | **DOCUMENTED** | `docs/architecture/COMPATIBILITY_AND_DEBT.md`. No extraction. |
| 17 | Don't label "Production Ready" | **Followed** | Production Candidate, with the two open items above. |

## A. Changes made
- **New:** `app/src/ProviderScope.php`; `tests/authz/{run.php,test_authorization.php,test_provider_scope_static.php}`; `tests/migrations/run_scenarios.php`; `tests/agency/run.php`; `tools/http_smoke/{run.sh,setup.php,drive.py}`; two docs.
- **Data layer:** `TenantRepository` (scoped find/all/count/update/delete); provider column declared on Holder, Credential, Document (via credential), RenewalTask (holder **or** the credential's holder for legacy NULL-holder rows), Calendar, Application, Attestation, CaseDocument, CeEntry, CredentialingCase, HolderRegulator, ProducerRequirement, ProviderContact/Education/WorkHistory, Screening, Verification; raw queries in those repositories scoped.
- **Services:** DashboardService, AgencyWorkQueueService, DocumentRequestService, VerificationService, ComplianceEventService (+ new `forCase`), DataExportService, GroupService, LocationService, ExternalShareService, NotificationService, ReminderService (logging), TenantReportService (failure flag), AssignmentService (fail-closed, explicit `system:share-link` actor).
- **Routes/views:** portal confinement by `RoleService::isPortalContext` (all portal-only roles) resolved before the dashboard route; `documents.verify` required to verify/unverify a document and the buttons are hidden without it; `/entity` refuses restricted users; case-not-found no longer references a missing view; holder page PHP block repaired.
- **Audit:** role change records from/to; `owner_level_role_granted`; `share_link_revoked`; user create/invite record the role.
- **Migration 125** (edited, not new): reserved-slug rename now id-suffixed so it cannot collide.

## B. Security / correctness findings
Severity is my judgement. "Evidence" says how it was proven.

| ID | Sev | Finding | Evidence | Fix | Regression test |
|---|---|---|---|---|---|
| V1 | High | Need-to-know was a PHP post-filter: `LIMIT`, `ORDER`, `COUNT` and every aggregate were computed on all providers first | Design review + test L | ProviderScope in SQL | authz L, N |
| V2 | High | Dashboard, work queue, KPIs, group/location member lists and the share oversight list disclosed unassigned providers (names, counts, dates) to restricted users | **EXECUTED on v1.170.8: FAIL** (dashboard names the unassigned provider); v1.170.9: PASS | scoped in SQL | http_smoke probe; authz N |
| V3 | High | A custom portal-only role could open the **tenant dashboard** and **download any provider's document** — confinement was keyed to the single slug `producer` | **EXECUTED on v1.170.8: FAIL ×2**; v1.170.9: PASS | `RoleService::isPortalContext` everywhere; resolved before the dashboard route | http_smoke probes |
| V4 | Med | Coordinator (`records.write`, no `documents.verify`) could verify documents — the route checked `records.write` | **EXECUTED on v1.170.8: 302 (allowed)**; v1.170.9: 403 | route requires `documents.verify`; UI hides controls | http_smoke; authz M |
| V5 | Med | `AssignmentService` failed **open**: a context whose user row was not found resolved to `'all'` (the public share viewer only worked because of this) | Code reading + authz F | fail-closed; share viewer is an explicit system actor | authz F; static S5 |
| V6 | Med | `/entity` as a restricted user: org record invisible → code **created another organization record on every visit** then crashed (500). 2 duplicates created in my run | **EXECUTED** (HTTP 500 + duplicate rows) | restricted users get 403 | http_smoke (crawl as restricted) |
| V7 | Med | Migration 125 renamed reserved-slug roles to `<slug>_role`; a tenant already holding `master_admin_role` aborts on `uq_role_tenant_slug` | **EXECUTED — found by authz group D**; fixed and added as a scenario | id-suffixed rename | migrations B/C, authz D |
| V8 | Med | `/cases/{id}` called `ComplianceEventService::forCase()` which **did not exist** (fatal on every case page, since the base version) | **EXECUTED** (agency suite: undefined method) | method added, scoped | agency suite, http_smoke |
| V9 | Low | Holder page had a PHP block that lost its opening tag: stray code text + undefined `$curTpl`/`$dr` warnings (pre-existing) | **EXECUTED** (PHP warnings in server log) | repaired | http_smoke crawl (fails on any warning) |
| V10 | Low | Missing `errors/404` view: "case not found" was a fatal | **EXECUTED** | plain 404 | http_smoke |
| V11 | Low | Notification recipients included custom portal-only roles (they'd receive alerts about all providers) | Code reading | excluded via `roles.portal_only` | STATICALLY REVIEWED (static S6 asserts the predicate) |
| V12 | Low | `statusCounts` counted soft-deleted credentials | Code reading | `deleted_at IS NULL` | authz N |

### B-doc. Document operations (tenant + provider scope + capability)
| Operation | Tenant | Provider scope | Capability | Note |
|---|---|---|---|---|
| list (`DocumentRepository::all`) | yes | **SQL via credential** | `documents.view` | |
| view/download `/documents/{id}` | yes | **SQL via credential** + portal ownership | `documents.view` | valid id of an unauthorized provider's document → 404 (EXECUTED) |
| upload `/credentials/{id}/documents` | yes | credential loaded through the scoped repository first | `documents.manage` (built-in provider portal exempt, own credential only) | separate from `records.write` |
| replace | same route (supersedes) | same | same | |
| delete | no document-delete route exists; documents leave with a soft-deleted credential | scoped credential delete | `records.delete` | |
| verify / unverify | yes | scoped document lookup | **`documents.verify`** (was `records.write`) | V4 |
| request / accept / return | yes | scoped via holder | `documents.request` | |
| external share download | token | share's own profile only | token | reviewed |

### B-audit. Audit-log coverage
Present before: role changed (`user_role_changed`), provider assign/unassign/access-mode, document verified/unverified, 2FA disabled, user disabled/enabled, portal user linked/invited, share created, credential deleted/restored, exports (`data_export`, `report_export`, `history_export`, `audit_export`), support/impersonation sessions.
Added: role from/to detail, `owner_level_role_granted`, `share_link_revoked`, role on user create/invite. `external_share_created/revoked` already existed in the service (I briefly duplicated them, an independent review caught it, removed). No plaintext secrets/tokens/PHI are written.

## C. NTK audit
- **SQL scoped (EXECUTED at repository level):** Holder, Credential, Document, RenewalTask, Calendar, Application, Attestation, CaseDocument, CeEntry, CredentialingCase, HolderRegulator, ProducerRequirement, ProviderContact/Education/WorkHistory, Screening, Verification; DashboardService; AgencyWorkQueueService; DocumentRequestService; VerificationService; ComplianceEventService; DataExportService; Group/Location member ids; ExternalShareService::all; iCal feed.
- **Post-filtered:** none remain (static test S2 fails the build if `ntkFilter/ntkRow/visibleIds` filtering reappears).
- **Recipient-scoped:** NotificationService (a notice is only created for users who may see that provider).
- **Not applicable:** OnboardingService booleans, CompliancePostureService (org-level, `org.manage`), TenantReport/Superadmin (platform admin), cron/reminders (system context; recipients are provider contacts + owners).
- **Fixed in this release:** all of the above V-rows.

## D. Direct SQL inventory
See `docs/architecture/DIRECT_SQL_INVENTORY_v1_170_9.md` (File · Method · Tables · Tenant scoped · Provider scoped · Capability · Action, ~25 rows).

## E. Migration validation (only PASS if executed)
| Scenario | Result | Environment |
|---|---|---|
| A fresh 001→125, zero errors; no insurance items/tenants; 124+125 re-runnable | **EXECUTED — PASS** | MariaDB 10.11.14 |
| B v1.170.7 state → 124 → 125 with legacy data (insurance tenant/holder, custom `master_admin` role + user, pre-existing `master_admin_role` collision, `tenant_compliance` children, healthcare provider + credential) | **EXECUTED — PASS** | same |
| C older install (stop before 072) → all later migrations | **EXECUTED — PASS** | same |
| D reserved-slug / FK-safety / healthcare data intact | **EXECUTED — PASS** (inside B, C and authz group D) | same |
| Same scenarios on MySQL 8 / cPanel host | **REQUIRES EXTERNAL RUNTIME VALIDATION** | — |

## F. Tests
| Test | Executed? | Result | Environment | Notes |
|---|---|---|---|---|
| `php -l` on every file | Yes | PASS | PHP 8.4.21 | |
| Isolation check | Yes | PASS | — | |
| `tests/healthcare` | Yes | PASS (28) | DB-free | H7 updated to the SQL mechanism |
| `tests/authz/test_provider_scope_static.php` | Yes | PASS (57) | DB-free | |
| `tests/authz/run.php` (cross-tenant, need-to-know, LIMIT, capability matrix, scoped writes, portal, fail-closed, 125 data) | Yes | PASS (145) | MariaDB | |
| `tests/migrations/run_scenarios.php` | Yes | PASS (30) | MariaDB | |
| `tests/purge/run.php` | Yes | PASS (48) | MariaDB | first time executed |
| `tests/agency/run.php` | Yes | PASS (50) | MariaDB | first time executed; **needed** 1 app fix (missing `forCase`) + fixture fixes |
| `tools/http_smoke/run.sh` (8 roles, authorization probes, ~220-page crawl per role, fails on 5xx or any PHP warning) | Yes | PASS (22) | php -S + MariaDB | |
| Full `bash tools/runtime_check.sh` | Yes | **380 PASS / 0 FAIL** | as above | harness.sh absent (dev-only) |
| Characterization suite | Yes | exit 0 | MariaDB | unchanged known `flx_compliance_data` runtime_fail=6 (documented v1.170.4) |
| Browser checks (`CLAUDE_CODE_RUNTIME_CHECK.md` 3b, 3c, 4) | **No** | — | — | **REQUIRES BROWSER VALIDATION** |
| iCal HTTP route, export downloads over HTTP, `/me` unlinked-portal 403 branch | **No** | — | — | STATICALLY REVIEWED |
| Any of the above on MySQL 8 / target cPanel host | **No** | — | — | **REQUIRES EXTERNAL RUNTIME VALIDATION** |

Process note: an independent diff-only review of this release found no blocking defect but caught three medium items (duplicate audit rows, verify buttons shown without the capability, unscoped raw repository queries) — all fixed. Executing the suites then found what static review missed: a wrong bound-parameter count I introduced in `ApplicationRepository::allWithNames`, V6–V10, and the 125 collision.

## G. Remaining risks
- **R1** `audit.view` / audit & activity exports are tenant-wide (rows carry ids, not PHI). If a role grants `audit.view` to a restricted user they see all activity. Recommend not granting it to need-to-know users; not enforced in code.
- **R2** `CrossTenantService::linksForTenant` (cross-org link list) is not provider-scoped; gated by `crossorg.manage` (owner/manager by default). Same recommendation.
- **R3** Restricted users who create a provider cannot see it until assigned (existing behaviour, not changed).
- **R4** `TenantRepository::restore/findTrashed/purge` are not provider-scoped; callers check `AssignmentService::canSee` and `records.delete` (STATICALLY REVIEWED, purge suite EXECUTED).
- **R5** Everything above ran on MariaDB 10.11, not the production MySQL/cPanel stack; no browser run.
- **R6** `index.php` remains a ~4,900-line front controller (documented debt).

## H. Questions for Senior Engineering Review
1. R1/R2: enforce "restricted users cannot hold `audit.view` / `crossorg.manage` / `share.manage`" at role-assignment time (small, but a policy change), or keep it as documentation?
2. Retired insurance tables (carriers, policies, …): drop in a later migration, or retain for data-retention reasons?
3. Deny-by-default in `TenantRepository` (refuse a restricted context on a table with no declared scope) — worth adding now, or after browser validation?
4. Anything you would still call blocking for "Production Candidate → Production Ready" beyond browser + target-host validation?
