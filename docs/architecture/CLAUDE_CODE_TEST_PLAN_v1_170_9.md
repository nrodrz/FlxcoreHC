# FlxCoreHC v1.170.9 — Test Plan for Claude Code (tests + expected results)

**Rules:** test/staging DB only. Run and report — do NOT edit code, tests or migrations to make anything pass.
Label every result: `EXECUTED — PASS` · `EXECUTED — FAIL` · `STATICALLY REVIEWED` · `REQUIRES BROWSER VALIDATION` · `REQUIRES EXTERNAL RUNTIME VALIDATION`.
Companion file: `CLAUDE_CODE_RUNTIME_CHECK.md` (exact commands). Target host is MySQL 8 / cPanel; my own runs were on MariaDB 10.11.

## A. Automated (one command: `bash tools/runtime_check.sh`)
| # | Stage | Expected | If it fails, it means |
|---|---|---|---|
| A1 | Fresh install into blank DB (schema + all migrations in install.php order) | 0 SQL errors | Migration order/syntax problem on your MySQL version — highest-priority finding |
| A2 | Agency credentialing suite | 50 PASS / 0 FAIL | Case/application/document flows broken |
| A3 | Healthcare-only suite | 28 PASS | Insurance residue or scope declaration regressed |
| A4 | Purge/retention suite | 48 PASS | Delete/restore/purge logic regressed |
| A5 | Provider-scope static (DB-free) | 57 PASS | A scoped repo/service lost its predicate |
| A6 | Authorization suite | 145 PASS | Cross-tenant / cross-provider / role leak |
| A7 | Migration scenarios A–D | 30 PASS | Upgrade path (incl. migration 125 role rename) broken |
| A8 | HTTP smoke (isolated `flx_smoke`, php -S) | 22 PASS, no 5xx, no PHP warnings on ~220 pages × 6 roles | Runtime error on a real page |
| A9 | Characterization suite | exit 0; only known `flx_compliance_data runtime_fail=6` | Anything else = new regression |
Expected total: **380 PASS / 0 FAIL**. MySQL 8 vs MariaDB differences (collation, strict mode, JSON) are the most likely source of a *new* failure — report exact error text.

## B. Fresh install (real host)
| # | Test | Expected |
|---|---|---|
| B1 | Run `install.php` on a blank DB | Completes, creates platform admin, lands on login |
| B2 | Re-run `install.php` | Refuses/locks (no reinstall over live data) |
| B3 | Login as platform admin → Console | Loads; no warnings |
| B4 | Missing crypto keys in `app_env=production` | App fails closed (refuses to run) — by design |

## C. Demo data (Console → System → Build healthcare demo)
Result of my run on v1.170.9: **builds cleanly** — 5 orgs (Coastal 9 providers/46 creds, Ramirez 2/11, Bayamon 7/40, Isla IPA 12/67, Anesthesia PR 7/40), 0 PHP warnings, all logins work, ~150-page crawl per role has no 5xx.
| # | Test | Expected |
|---|---|---|
| C1 | Build demo | Returns owner email + password, no "demo extras:" warning (that warning appears only if crypto keys are unset) |
| C2 | Owner logins: `demo-admin@coastalcred.example`/`coastal-demo-2026`, `office@ramirezfp.example`/`ramirez-demo-2026`, `admin@bayamonmg.example`/`bayamon-demo-2026`, `admin@islaipa.example`/`isla-demo-2026`, `admin@anesthesiapr.example`/`anes-demo-2026` | All log in to dashboard |
| C3 | Team logins (password `FlxDemo2026!`): manager@, staff@, coordinator@, readonly@ `coastalcred.example`; `frontdesk@ramirezfp.example` | All log in; pages 200 except expected 403s |
| C4 | Portal logins (`FlxDemo2026!`): ana.rivera@ / luis.colon@ `coastalcred.example`, ana.rivera@bayamonmg.example | Land on `/me` only |
| C5 | Teardown then rebuild | Both succeed; no orphans |
Note: no demo user is need-to-know-restricted by default — do C6 to create one.
| C6 | As owner, set `staff@coastalcred.example` to "assigned" and assign ONE provider (Team/user page) | Staff then sees exactly 1 provider on Holders, Credentials, Calendar, Dashboard (I verified: 1 row, dashboard names only that provider) |

## D. Security spot-checks (demo org)
| # | Test | Expected |
|---|---|---|
| D1 | Coordinator (`coordinator@`) POST verify on a document | 403; no Verify/Mark-verified buttons on credential edit page |
| D2 | Staff/manager verifies a document | Succeeds (302), audit row written |
| D3 | Restricted staff opens another provider's `/holders/{id}`, `/credentials/{id}/edit`, `/documents/{id}` (download) | 403 or 404, never data |
| D4 | Restricted staff POST-edits another provider's credential | 403/404; DB unchanged |
| D5 | Restricted staff dashboard, counts, work queue, forecast, calendar, iCal feed | Only assigned provider's numbers/names; no other provider's name anywhere |
| D6 | Restricted staff exports (Data export, reports, cases) | Only assigned provider's rows |
| D7 | Portal user visits `/`, `/holders`, `/credentials`, `/cases`, `/team`, `/settings`, `/entity` | All redirect to `/me` (verified) |
| D8 | Portal user tries another provider's document/credential ID | 403/404 |
| D9 | Custom portal-only role (Team → Roles → create with "portal only") user | Same confinement as D7 |
| D10 | Manager tries to grant `tenant_owner`/`master_admin` | Refused or degraded to `staff`; audit `owner_level_role_granted` only when an owner does it |
| D11 | Restricted user opens `/entity` | 403 (previously 500 + duplicate holders) |
| D12 | Tenant A owner requests tenant B's holder/credential/doc IDs | 404 |
| D13 | Read-only role attempts any POST | 403 |
| D14 | Unknown `/cases/{bad-id}` | Plain 404 text, not a fatal error |
| D15 | CSRF: POST without `_csrf` | Rejected |
| D16 | Share link (external share): open with password, wrong password, after revoke | OK / rejected / rejected; revoke writes `share_link_revoked` audit |

## E. Functional walk-through (browser) — `REQUIRES BROWSER VALIDATION`
Dashboard renders (no layout breaks); Holders list/search/filter; provider page (CV, credentials, drift panel — previously had a stray-code bug); add/edit credential; upload document; case workflow; calendar; iCal subscribe URL; CSV/PDF exports download; Spanish/English toggle if enabled; mobile width; session timeout (30 min idle).

## F. Known gaps — report as-is, do not fix
- Audit log view/export (`audit.view`) is tenant-wide even for restricted users (R1).
- Cross-org link list not provider-scoped (R2).
- A restricted user who creates a provider can't see it until assigned (R3).
- restore/purge scope is caller-checked (R4).
- I did not run: browsers, iCal/export downloads over HTTP, `/me` for an unlinked portal user (static review only), MySQL 8.

## Report format
Per test id: label, evidence (command/output or status code), and for any FAIL the exact error text and file:line. Finish with: total PASS/FAIL, MySQL version, PHP version, and anything unexpected.
