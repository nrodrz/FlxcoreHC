# FlxCoreHC v1.172.12 — independent validation (Claude Code)

Release zip SHA-256 `808052cb6299e23dc8b22175fe4dd05ea4c71cad7b22af17292ada07a4b1ba35` (matches status note). 538 files, no `app/config.php`. Pristine tree re-hashed after all runs: unchanged. Nothing edited. Environment: PHP 8.4.19 (intl, sodium, pdo_mysql), MariaDB 10.11.14 disposable, `php -S` + shipped `tools/router.php`, Chromium/Playwright. Evidence class: Claude Code independent execution.

## Verdict
Automated suites and the date fixes PASS. **Not fully clean:** three English strings remain on the sign-in/invite/verify path although the changelog says the Spanish leftovers are gone (items F1–F3).

| Check | Label | Evidence |
|---|---|---|
| `tools/runtime_check.sh` (incl. new stage 3a8) | EXECUTED — PASS | 951 PASS, 0 FAIL/SKIP, exit 0. Normalised PASS-set diff vs Claude.ai log: identical except PHP patch version (8.4.21 vs 8.4.19) and random IDs/emails in 3 lines |
| `tools/browser_check/run.sh` | EXECUTED — PASS | ALL PASS (14 PASS, 0 FAIL), no CSP/script errors |
| Fresh install over http | EXECUTED — PASS | CSRF-less POST rejected (200, no install); valid POST: 91 tables, 133 migrations, intl loaded, `mail_from` = `no-reply@127.0.0.1` (no port) |
| Fresh install behind `X-Forwarded-Proto: https` | EXECUTED — PASS | Same 91 / 133; config `app_env=production`. Observation: `force_secure_cookies` = false in the written config |
| Cron sweep, credential expiring on the org's today | EXECUTED — PASS | Honolulu: today stays `expiring`, yesterday `expired` (sweep "2 expired, 2 expiring"). Sweep uses `@flx_today`; no `CURDATE`/`CURRENT_DATE` anywhere in cron/tools/app/index/install |
| Real UTC vs org-day split | EXECUTED — PASS | Run at 20:00 UTC, so Honolulu had no split; used Pacific/Kiritimati (UTC+14): `@flx_today=2026-10-18`, `UTC_DATE=2026-10-17`. Expiry 10/18 stays `expiring`; 10/17 becomes `expired` |
| 23:30 event 12/31 HI | EXECUTED — PASS | Stored `2027-01-01 09:30:00`; in Dec grid cell 12/31 and Dec list; absent from Jan |
| 23:30 event 10/31 NY | EXECUTED — PASS | Stored `2026-11-01 03:30:00`; in Oct grid + list, not Nov grid/list (shows in Nov "Next 30 days", expected) |
| 23:30 event 02/29/2028 HI | EXECUTED — PASS | Stored `2028-03-01 09:30:00`; in Feb grid + list, absent from Mar |
| Exports ISO/UTC | EXECUTED — PASS | ICS `DTSTART:20261101T033000Z`, `20270101T093000Z`; 2028 event outside feed window, checked in DB |
| Timed event passed today: agenda vs banner vs list | EXECUTED — PASS | NY 07:30 and HI 00:15: banner +1, agenda `Overdue`, list `overdue`, month cell overdue all agree; later-today control not overdue |
| Spanish: tagline, wrong-password, "¿Qué es usted?", state names, register/reset/forgot/2FA/lock-out flashes | EXECUTED — PASS | Verified in rendered pages |
| Reminder e-mail dates MM/DD/YYYY | EXECUTED — PASS | `expires on 10/17/2026 (today)`, `10/27/2026 (in 10 days)`, `10/18/2026 (tomorrow)` |
| 1.172.11 regressions (credential today, typed times, package PDF date, `@flx_today`) | EXECUTED — PASS | PDF `[verified 10/16/2026, primary source]` for 02:00 UTC verified_at in NY; `tests/dates` 11/12/6 suites pass |
| F1 Invite accepted flash | EXECUTED — FAIL | Redirect to /login shows `Your account is ready — please sign in.` (English). Source `app/src/TeamService.php:287`; no `es.php` entry |
| F2 Registration disabled flash | EXECUTED — FAIL | `/register` with open registration off shows `Public sign-up is disabled. Please ask your administrator for an invitation.` (`index.php:386`; no `es.php` entry) |
| F3 `/verify/<bad>` page | EXECUTED — FAIL | Heading Spanish, body English: `It may have expired or been revoked by the organization that shared it. Ask them to send a fresh verification link.` (`app/views/verify/invalid.php:26`; no `es.php` entry) |

## Other observations (not on checklist)
- A static scan found ~15 more flash strings without `es.php` entries, mostly post-login profile / 2FA enrolment ("Name cannot be empty.", "Password changed."). Not exercised at runtime.
- Registering with an e-mail already used in another tenant created a new pending org (per-tenant uniqueness, presumably by design).
- Known open behaviours unchanged: whole-day month-grid overdue highlight, DST fall-back second 01:30, spring-forward gap stored as 03:30, events saved before 1.172.11 keep their typed-as-UTC value.
- Subagent QA of the date/Spanish items; I re-verified F1–F3 string locations and the missing `es.php` entries directly in the pristine tree.
