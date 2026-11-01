# FlxCoreHC v1.171.5 — response to Claude Code's independent runtime validation of v1.171.4

Status: **Production Candidate (tracker, not NCQA-grade) — pending Claude Code's independent re-run.**
Labels: "Claude.ai sandbox PASS" = executed by the author in a sandbox (PHP 8.x + MariaDB); it is **not** an independent PASS.
Sandbox result: `FLX_CONFIG=<base config> bash tools/runtime_check.sh` with **`app/config.php` absent** → exit 0 (output: `runtime_check_output_v1.171.5.txt`).
No new features. No new migrations. No existing test was edited to pass (tests were only **added**; one fixture changed, see "Transparency").

## Dispositions
| # | Finding | Fix (files) | Regression test | Sandbox result | Remaining risk |
|---|---|---|---|---|---|
| A | `index.php` ignored FLX_CONFIG; vacuous smoke PASS | `index.php` line 5, `bootstrap.php flx_config_path()`; `drive.py` preflight + vacuous-crawl guard | Whole smoke now runs with app/config.php absent; crawl fails if <8 pages or <max(5,n/3) 200s; installer check "with no config, / hands off to installer" | PASS (7 crawls, 220 pages each) | none known |
| B | no flash at /login after forced sign-out | `index.php` (flash type 'warn'), `views/auth/login.php` | HTTP: suspended user and reset-password user see "signed out" once | PASS | none |
| C | `.keep` made encrypt_existing_files exit 2 | `database/encrypt_existing_files.php` skips dot-files | DB suite: sweep with `.keep` + legacy PDF → exit 0, PDF sealed | PASS | none |
| D | OCS panel under FL-only | `views/holders/show.php` | HTTP: FL tenant with an OCS row → no panel; PR → panel (mutation run proved it fails on the old code) | PASS | the OCS event can still appear in the provider's compliance timeline (history of a real event) |
| E | PR boards missing on /regulators | `RegulatorRepository::CATEGORIES`, `regulators/index.php` ("Other" panel), `lang/es.php` | DB + HTTP: licensing board listed | PASS | none |
| F | `seedTemplatesFor` reverted migration 132 | `AuthService::isGlobalCatalogWrite`, skipped in seed loop | DB: re-apply 132, sign up a tenant, notes still UNVERIFIED; unit test of the classifier | PASS | none |
| G | throttle semantics | **Decision: keep** (see below); docs + tests | DB: 13 wrong from one IP → new IP accepted (by design); failures spread over IPs → throttled; NAT owner held only from that IP | PASS | shared-NAT hold (15 min) remains, documented |
| H | GET /admin/email flushed outbox | `index.php` | HTTP: row stays queued, attempts 0 | PASS | none |
| I | known before 2FA | `AuthService::markKnownPlace` called from `establishSession` | DB: first-factor-only address still throttled per account | PASS | none |
| J | error flashes green | `bootstrap.php flx_flash_type`, `flash($m,$type)`, `layout.php` | DB (classifier) + HTTP (refusal → `data-toast-type="error"`) | PASS | classification is message-based; new messages should be reviewed |
| K | read-only can't enrol 2FA | `index.php` read-only block exempts own 2FA actions | HTTP: read-only `2fa_start` works; data POST still refused | PASS | none |
| L | installer https/hsts on dev | `install.php` | `tools/http_smoke/installer_check.sh` runs the real installer over http and https | PASS | none |
| M | duplicate Master Admin / audit / raw slug | `RoleService::all`, `index.php`, `TeamService::roleLabel` + views | HTTP: one Master Admin, one `role_created` row, no `tenant_owner` in Manager's Team page | PASS (mutation-proven) | none |
| N | audit package Requirement/evidence | `RiskScoreService` title, `index.php` passes `requirementRows`, `reports/audit_package.php` | HTTP: evidence rows present, non-blank, name ≠ authority word | PASS | none |
| O | CSV template NPIs | `index.php` template rows (1234567893, 1245319599, 1003000126) | HTTP: every NPI in the template passes Luhn | PASS | none |
| P | PR CME 24 vs 36 | `ChecklistService` uses `flx_ce_months` | DB: PR 36 / FL 24 | PASS | none |
| Q | `.notif-sweep-*` in app tree | `NotificationService::stampPath`; smoke uses temp docs_path | `runtime_check.sh` stage 5 | PASS | none |
| R | stale text | `runtime_check.sh` (v1.171.5, stages 3f2 and 5), checklist corrected | n/a | n/a | — |

## G — decision
Intended behaviour: (1) one address can make at most **8** guesses per e-mail per 15 min; attempts refused by that limit do not feed the per-account counter, so one address cannot lock the owner out everywhere (13 wrong from one address, then the correct password from a *new* address → accepted; the old checklist expectation was wrong). (2) Failures from **many** unknown addresses still reach the per-account limit (12). (3) "Known place" (complete sign-in incl. 2FA within 30 days) exempts the owner from the per-account counter only. (4) Shared NAT: the owner is held 15 min from that address only. Owner may prefer a stricter rule; it is a one-line change in `AuthService::attemptLogin` and needs your decision.

## Checklist corrections (docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md)
Step 1 (passphrase field, http/https installer), Step 2 prerequisite (FLX_CONFIG, app/config.php may be absent), 3b-1 and 3d-1 exact refusal wording, 3d-2 flash, 3g-1 throttle expectations, 4.3 (24 FL / 36 PR), new Step 3i.

## Not done (Claude Code section 6, not required)
Spanish credential-form sentence in English; duplicate "Compliance deadline" in calendar filter; staff GETs writing "Renewal tasks" audit rows; `credential_documents.sha256` NULL; documents inside web root (keep `docs_path` outside it — documented); demo tenants skipping the support-grant check. Proposed for a later release, none is a data-exposure defect.

## REGULATORY FACTS (unchanged from v1.171.4)
Verified: JLDM name; nursing board "Junta Examinadora de Enfermería"; bioethics/professionalism hours mandated by statute. **UNVERIFIED** (labelled in the app): ~60 h/3 yr, 10 h/6 h breakdown, ORCPS triennial cycle, negative drug-test certificate. Owner action: confirm the physician renewal requirements (salud.pr.gov/CMS/120).

## Transparency
- Tests added in `tests/security/test_v1171.php` (V5 block) and `tools/http_smoke/drive.py`; none removed or loosened. S8/NAT tests now call `AuthService::markKnownPlace` after `attemptLogin` because the known-place recording moved from the first factor to the completed sign-in (behaviour change I).
- The V5 F test first re-applies migration 132 because an earlier v1.171.4 test deliberately replays 126 (legacy-state simulation).
- Smoke fixture: the OCS row is inserted by SQL (not `saveOcs`) so it does not create a timeline event that would make the existing D-5 "OCS / SICRO absent" check meaningless.
- Mutation runs: reverting the OCS panel condition, the Master Admin dedupe, and the duplicate audit made the new HTTP tests FAIL.
- Not independently verified: everything above is the author's sandbox. Browser-visual items (toast colour) are REQUIRES BROWSER VALIDATION (the HTTP test checks the `data-toast-type` attribute, not rendering).
