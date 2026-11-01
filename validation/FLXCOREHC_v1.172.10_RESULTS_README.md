# FlxCoreHC v1.172.10 — independent validation (Claude Code independent execution)

**Date:** 2026-10-16 · **Role:** runner/reporter only — no app code, migrations, schema, tests or docs modified. All evidence: **Claude Code independent execution** (Claude.ai's sandbox log was used only for comparison, §1).

**Environment:** PHP 8.4.19 (intl, openssl, pdo_mysql, sodium) · MariaDB 10.11.14 (throwaway) · Python Playwright 1.56 + Chromium + OpenCV · `php -S`. No outbound DNS in this sandbox. Reference time during the browser run: 2026-10-16 02:2x UTC = 10/15 22:2x New York = 10/15 16:2x Honolulu (so "today" differs between UTC and the app zones — the ideal condition for the date checks).

**Release:** `flxcorehc-v1_172_10.zip` SHA-256 matches `status-v1.172.10.md` (`050b4b47…ff39`); no `app/config.php`; 534 files (+6 vs 1.172.7: `auth/_lang.php`, `share/_lang.php`, i18n/ui tests for 1.172.9/10). No driver script was supplied with this release, so I used the v1.172.7 driver with only the version strings changed (`script_output/driver_used.sh`; byte-for-byte identical otherwise).

## Verdict

| Area | Result |
|---|---|
| Validation driver (`tools/runtime_check.sh`, FLX_CONFIG, no app/config.php) | **exit 0 · 922 PASS · no FAIL/SKIP** · 7 crawls × 220 pages · STRAY FILES PASS · new stages 3a5/3a6 PASS · pristine tree byte-identical |
| Compared with the attached Claude.ai log | identical PASS set (922 vs 922, 0 differing lines). *The status note says "938 PASS"; the attached log and my run both say 922.* |
| `tools/browser_check/run.sh` | **ALL PASS 14/14, exit 0**; QR decoded by OpenCV, secret matches |
| Step 1 fresh install (http + https) | PASS — 91 tables, 133 migrations, intl loaded, CSRF refused, `mail_from` no port, hsts false/true |
| The 7 fixes claimed for 1.172.10 | **5 verified PASS** (SMTP unresolvable host, all-day deadline day, temp-password ≥10, OAuth callback, onboarding Spanish) · **2 PARTIAL** (ISO text: 2 residual strings; sign-in Spanish: several English leftovers) |
| Regression (3q-2/3/5, 3o-4/6, 3p-4, 3n-2, 3m-1) | **8 / 8 PASS** |
| Date break attempts | **2 new FAILs** in the "today" logic (§4) |

**Material for this release:** the date work is half-done. Rendering now uses the app zone, but **"today" is still UTC** in the places that decide status: a credential expiring today (local) is marked **expired** a day early (`index.php:4922` `new DateTimeImmutable('today')`, PHP default UTC), the calendar agenda buckets today's item under **"Overdue"** and the banner counts it (`CalendarRepository.php:139` `due_at < UTC_TIMESTAMP()`, `:206` `'today'`), while the month grid/list on the same page correctly do not. And **manually created timed events are saved without local→UTC conversion** (`index.php:4342`): a meeting entered at 23:30 in Honolulu shows as 1:30 PM. `today_local()` exists (`bootstrap.php:500`) but is not used in these paths. Nothing here crosses a tenant boundary or touches security; it is correctness of the headline feature.

## 1. Automated
```
$ DB_USER=root DB_PASS= bash claude_code_validate_v1_172_10.sh flxcorehc-v1_172_10.zip   → SCRIPT EXIT=0
02_exit_code.txt: runtime_check exit code: 0 · 03_summary.txt: PASS lines: 922 | no FAIL/SKIP lines | app/config.php absent
3a5 Spanish gaps / 3a6 "Validation findings: SMTP host guard, ISO dates, sign-in language, password rules" — ALL PASS
PASS — crawl as owner/manager/staff/coord/readonly/restricted/padmin: pages=220 each
STRAY FILES: PASS (0 pre-existing)
$ FLX_CONFIG=/tmp/flx_base_config.php bash tools/browser_check/run.sh → ALL PASS (14), secret_matches=True
```
Full outputs: `script_output/01_runtime_check_output.txt`, `browser_check/browser_check_output.txt` (+8 screenshots), `logs/compare_with_claude_ai_log.txt`. Stage 2's non-failing raw-SQL warning is unchanged.

## 2. Step 1 (me)
CSRF missing → "Your session expired or this request did not come from the installer page", nothing written · http → "✓ Installation complete — created 91 tables…", `schema_migrations` 133, intl `loaded`, `app_url=http://…`, `hsts=false`, `mail_from='no-reply@127.0.0.1'`, `pr_cme` UNVERIFIED · `X-Forwarded-Proto: https` → `https://…`, `hsts=true`. (My first http attempt raced the server start on a busy box and was discarded; the re-run above is what is reported.)

## 3. Fix verification (runner C)
| Fix claimed | Label | Evidence |
|---|---|---|
| F1 all-day deadline west of UTC-9 | **EXECUTED — PASS** (rendering) | Credential expiring 2027-03-13 → event `2027-03-13 09:00:00 all_day=1`. Honolulu, UTC and New York month grids all place it in `data-date="2027-03-13"`; "Next 30 days" shows **date only, no clock time** ("10/26/2026 QA210C Plus10 DEA expires — …") identical in all three zones; list/agenda same dates; iCal `DTSTART;VALUE=DATE:20270313`. DST boundaries 2026-11-01 / 2027-03-14 correct; a deadline created under UTC and viewed in Honolulu stays on its day. |
| F1 overdue only after the day passes locally | **EXECUTED — FAIL** | Item due 10/15 (today in HI/NY, yesterday in UTC): month grid cell is `today` (not overdue) and the list row has no "overdue" chip in HI/NY — correct — **but the agenda puts it under "Overdue · 4" and the banner says "4 items overdue — highlighted below." in HI and NY exactly as in UTC.** Code: `CalendarRepository::agenda()` uses `new \DateTimeImmutable('today')` (UTC) and `overdueCount()` compares `due_at < UTC_TIMESTAMP()`. |
| F2 ISO/UTC text on provider page and dashboard | **EXECUTED — PASS with 2 residues** | Scanned visible text of `/`, `/me`, notifications (96 rows), credentials, calendar, audit, billing, sources, tasks, cases, applications, compliance, reports, all 9 provider pages (all tabs incl. History details "expiry_date: 03/13/2027", "10/15/2026 4:24 PM"), package/packet. **0 hits** for "UTC", 24-h times or "Mon D". ISO residues: Medicaid PEP hint "required since 2024-05-15" (regulatory sentence the status note deliberately keeps) and the package PDF "[verified 2026-10-16, primary source]" (the status note says it could not be located — it is in the package PDF text stream). CSVs still ISO/UTC. |
| F3 Own-SMTP unresolvable host | EXECUTED — PASS | `smtp.example.com:587`, `mail.coastalcred.example:587` → "The SMTP host name could not be resolved." (302, nothing stored, `error_log` 0 → 0). Guard battery unchanged: private/loopback/metadata IPs → "must be a public mail server"; localhost/[::1]/2130706433/0x7f000001 → "must be a public server name"; ports 22/80/8080/2526 → "must be one of 25, 465, 587, 2525"; 8.8.8.8:587 saved. |
| F4 sign-in pages Spanish | **EXECUTED — PASS with English leftovers** | `/login`, `/forgot`, `/reset/<t>`, `/register`, `/invite/<t>`, `/login/verify`: `?lang=es`, cookie `flx_lang=es` and `Accept-Language: es` → `lang="es"`; `fr`/none → `en`; switch present ("Inglés · Español", not literally "English | Español"); sign-out keeps Spanish; `?lang=en` switches back. `/reset` and `/login/verify` fully Spanish. **Still English:** `/login` "New here? Create your organization"; `/register` the whole intro paragraph ("Create your organization. Pick the shape that matches how you work…"), "Organization / practice name", "What are you?", "Where do your providers practice?", "Sets which state licence rules apply…", "Your name", "Work email", "(helps us verify you)", "At least 10 characters.", "Confirm password", "Already set up?"; `/invite` "You've been invited to … Set a password to activate your account (…). Choose a password", "Activate account"; `/forgot` "(Team → Set password)". |
| F5 temporary password ≥10 | EXECUTED — PASS (create org) · REQUIRES BROWSER VALIDATION (platform-admin create) | Create organization: 9 chars → "Temporary password must be at least 10 characters." (no tenant); 10 chars → created, owner signs in; blank → invite link shown once; form `minlength="10"`. Password absent from audit, outbox, error_log, php -S log, SMTP sink. The platform-admin "create user" form (`/admin/tenant/<platform>` create_user) is gated by a support-session approval even for the platform's own tenant, so the server-side length rule there could not be reached. |
| F6 Microsoft OAuth callback | EXECUTED — PASS | `error_description=<b>INJECTED</b>+User+declined` → flash "Microsoft sign-in was cancelled or failed. Try again from Connect." (supplied text absent); `error=<script>…` → same; bogus state → "Sign-in session expired or invalid — try again." Audit rows `m365_connect_failed {"ok":false,"error":"access_denied"}` / sanitised code. |
| F7 onboarding Spanish | EXECUTED — PASS | "Grupo cliente (el cliente al que pertenece este registro) — Aún no hay grupo cliente —". (Only `/?setlang=es` switches a logged-in session; `?lang=es` on an inner page does not.) |

## 4. Date break attempts
| Probe | Label | Evidence |
|---|---|---|
| Timed event entered 23:30 in Honolulu | **EXECUTED — FAIL** | Stored `due_at 2026-10-20 23:30:00` unchanged (`index.php:4342` no conversion); displayed HI **"1:30 PM"**, UTC "11:30 PM", NY "7:30 PM"; the edit modal still shows "23:30". Entered wall-clock ≠ displayed time in every non-UTC zone. |
| Credential expiring TODAY (local) | **EXECUTED — FAIL** | Expiry 2026-10-15 created at 10/15 22:24 EDT → stored `status=expired` immediately; credentials list "10/15/2026 **-1 expired**", dashboard work queue "expired", provider chip "expired exp 10/15/2026" — while it is still 10/15 in NY and HI. `credential_status_for()` (`index.php:4922`) uses UTC "today"; same pattern at `CalendarRepository.php:275/295`. |
| DST boundaries NY (11/01/2026, 03/14/2027) | EXECUTED — PASS | Correct cells, list dates and iCal `VALUE=DATE`. |
| iCal all-day / created-in-UTC-viewed-in-HI | EXECUTED — PASS | See F1. |

## 5. Regression (all PASS)
3q-2 e-mail options unchanged · 3q-3 password `value=""`, blank re-save keeps identical `smtp_pass_enc` · 3q-5 `/admin/audit` 403 for the tenant Master Admin, 200 for platform admin · 3o-4 QR inline `<svg>`, OpenCV decode → `otpauth://…secret=…` = setup key → TOTP enables 2FA · 3o-6 reset link single-use (`used_at` set, second GET → "This link is no longer valid"), not signed in after reset, 2FA still enforced · 3p-4 cross-type fields not stored · 3n-2 org name NBSP refused, surrounding NBSP trimmed · 3m-1 "Managеr" (Cyrillic е) refused.

## 6. Surprising observations (nothing changed)
1. The same calendar page disagrees with itself in HI/NY: no cell is highlighted overdue for today's item, yet the banner says "4 items overdue" (§3 F1).
2. `status-v1.172.10.md` says 938 PASS; the attached log and my run both show 922.
3. A tenant user hitting `/oauth/microsoft/callback` without a pending OAuth session is redirected to `/admin/email` → 403, so the flash is never shown; the audit row is written under the tenant with `entity_id "platform"`.
4. The forgot-password mail was attempted immediately through the tenant's Own SMTP (8.8.8.8:587, ~10 s timeout); body correctly replaced by the "[security message — content removed…]" placeholder.
5. Switching platform SMTP to a working sink immediately flushed 4 older queued messages.
6. No PHP warnings/notices/deprecations, no 5xx, `error_log` 0 rows on every instance.

## 7. Harness and fixtures (test instances only)
Shipped `tools/router.php` used for the browser run (`qa210_C`, port 8131); my installs `i210_http`/`i210_https` (8141/8142); browser suite `bc17210` (8097); driver on 8098/8099. Instance config `allow_open_registration` toggled for `/register` and restored (diff verified). SMTP sink on 8171 (copy of the shipped `smtp_sink.py`, run with `python3 -I` from a scratch dir). No SQL writes; all fixtures through the UI (several QA210C credentials/events on Maria Santos, two QA210C tenants, 2FA on manager@/staff@, manager password changed via reset). Databases: `flx_i210_http`, `flx_i210_https`, `flx_qa210_c`, plus the suites' own.

## Files
`README.md` · `script_output/` (00–04, driver console, `driver_used.sh`) · `browser_check/` (output + 8 PNGs) · `logs/step1_installer_config.txt`, `logs/compare_with_claude_ai_log.txt` · `screenshots/` (12 PNGs `C_*`: F1 grids/agenda, F4 sign-in pages, 3o-4 QR, H credentials list).
