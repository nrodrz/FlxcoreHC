# FlxCoreHC v1.172.11 — independent validation (Claude Code independent execution)

**Date:** 2026-10-16 · **Role:** runner/reporter only — no app code, migrations, schema, tests or docs modified. All evidence: **Claude Code independent execution**.

**Environment:** PHP 8.4.19 (intl, openssl, pdo_mysql, sodium) · MariaDB 10.11.14 (throwaway) · Python Playwright 1.56 + Chromium + OpenCV · `php -S`. No outbound DNS. Clock at the browser run: 2026-10-16 04:23 UTC = 10/15 18:23 Honolulu, 21:23 Los Angeles (org day ≠ UTC day) but already 10/16 in New York (so NY could not exercise the "day differs" case). The timezone dropdown offers only Puerto Rico, New York, Chicago, Denver, Los Angeles, Anchorage, Honolulu and UTC — no Pago_Pago / Kiritimati.

**Release:** `flxcorehc-v1_172_11.zip` SHA-256 `5074f16e…c7872`; no `app/config.php`; 536 files (+2 vs 1.172.10: `tests/dates/test_v1172_11.php`, `tests/ui/test_v1172_11_ui.py`). Harness scripts differ from 1.172.10 only in version strings plus the new stage 3a7. As in 1.172.10 no driver script came with the release; I used the v1.172.7 driver with only the version strings changed (`script_output/driver_used.sh`). The two attached `runtime_check_output_v1.172.11*.txt` files are **byte-identical** (same SHA-256).

## Verdict

| Area | Result |
|---|---|
| Validation driver | **exit 0 · 941 PASS · no FAIL/SKIP** · 7 crawls × 220 pages · STRAY FILES PASS · new stage 3a7 PASS · pristine tree byte-identical |
| Compared with the attached Claude.ai log | identical PASS set (941 vs 941, 0 differing lines) |
| `tools/browser_check/run.sh` | **ALL PASS 14/14**, QR decoded, secret matches |
| Step 1 fresh install (http + https) | PASS — 91 tables, 133 migrations, intl loaded, CSRF refused, hsts false/true |
| The 1.172.10 findings re-tested | **Fixed:** credential expiring today (web paths), all-day agenda/banner, 23:30 typed time, package PDF date, `@flx_today` in the web layer. **Not fully fixed:** 2 (cron, timed-event overdue) + **1 new defect** + Spanish leftovers |
| Regression (3q-2/5, 3o-4, 3n-2, F1) | PASS |

**Material findings (all reproduced; the first two confirmed in code):**
1. **`cron/sweep_expiring.php` still uses `CURDATE()`** (lines 25, 30, 49, 50) and does not set the org day, so the nightly sweep **flips a credential that expires today (local) to `expired` a day early** — undoing the 1.172.11 fix: SQL before `expiring`, after the run `expired`; the UI then shows "0 expired" rows and the credentials pill "Expired (6)" while the dashboard says "4 Expired". The changelog says "no CURDATE() left", but test T3 only scans `app/` and `index.php`, which is why 3a7 passed — **the stage 3a7 check has a blind spot over `cron/`**.
2. **Month-end evening events vanish from their own month's grid and list.** `CalendarRepository::filtered()` (`:158-159`) compares the *local* month bounds against the *UTC* `due_at`: a Honolulu 12/31 23:30 event (stored `2027-01-01 09:30 UTC`) is absent from December and appears in January's grid (cell `2026-12-31`) — likewise NY 10/31 23:30 (absent from the Oct grid) and HI 02/29/2028 23:30 (absent from Feb 2028). New with this release, because typed times were previously stored unconverted.
3. **A timed event that has already passed today** is counted overdue by the banner and the list but not by the agenda "Overdue" bucket (HI: banner 6, list chips 6, agenda 5; UTC: 9 / 9 / 8). The Oct-15 grid cell is highlighted overdue as a whole, so it also highlights that day's non-overdue all-day items.
4. **Spanish sign-in pages still have English strings** (list in §3 D5).

## 1. Automated
```
$ DB_USER=root DB_PASS= bash claude_code_validate_v1_172_11.sh flxcorehc-v1_172_11.zip   → SCRIPT EXIT=0
02_exit_code.txt: runtime_check exit code: 0 · 03_summary.txt: PASS lines: 941 | no FAIL/SKIP lines | app/config.php absent
3a7 "Today is the organization's day; typed times are stored in UTC": 19 PASS (T1 Pago_Pago today/yesterday/tomorrow; T2 Honolulu 23:30 → 09:30 UTC next day, NY EDT/EST, unparseable unchanged, UTC unchanged; T3 no CURDATE() in app/ + index.php, @flx_today set, repo uses the org's day, create/edit/move convert)
PASS — crawl as owner/manager/staff/coord/readonly/restricted/padmin: pages=220 each
STRAY FILES: PASS (0 pre-existing)
$ FLX_CONFIG=/tmp/flx_base_config.php bash tools/browser_check/run.sh → ALL PASS (14), secret_matches=True
```
Full outputs in `script_output/`, `browser_check/` (+8 PNG), `logs/`.

## 2. Step 1 (me)
CSRF missing → "Your session expired or this request did not come from the installer page", nothing written · http → "✓ Installation complete — created 91 tables…", `schema_migrations` 133, intl `loaded`, `app_url=http://…`, `hsts=false`, `mail_from='no-reply@127.0.0.1'`, `pr_cme` UNVERIFIED · `X-Forwarded-Proto: https` → `https://…`, `hsts=true`.

## 3. Runner C results
| Item | Label | Evidence |
|---|---|---|
| **D1** credential expiring today (org-local) | **EXECUTED — FAIL (cron)** | **Web paths PASS in Honolulu and Los Angeles** (yesterday/today/tomorrow → `expired`/`expiring`/`expiring`; list `-1 expired / 0 expiring / 1 expiring`; dashboard counts HI 4/11/15 equal SQL on the local date; provider chip; CSV import; `/compliance/run-reminders` queued "expires today"/"expires tomorrow" and nothing for yesterday). **FAIL:** `php cron/sweep_expiring.php` in HI → `sweep: 2 expired, 0 expiring, 0 reminders queued`; both local-today credentials `expiring` → `expired`; UI then `10/15/2026 0 expired`, chip "4 expired · 3 needed · 3 expiring soon", pill "Expired (6)" vs dashboard "4 Expired". NY/UTC give correct results but NY's day equalled UTC's at test time. |
| **D2** agenda and overdue banner | **EXECUTED — FAIL (timed events)** | **All-day PASS:** HI an all-day item due Oct 15 is under "Due this week"; banner 5 = agenda Overdue 5 = list chips 5; NY/UTC banner 8 = agenda 8 = list 8; timed events placed in the agenda by their local day (HI 00:30 and 23:30 both under 10/15). **FAIL:** a timed event already past today: HI 00:30 (stored 2026-10-15 10:30 UTC) → banner 6, list chips 6, agenda `Overdue · 5` (the event sits in "Due this week · 9"); UTC banner 9 / list 9 / agenda 8. |
| **D3** timed event 23:30 | **EXECUTED — FAIL (month-end)** | **Core PASS:** Add-event modal HI 2026-10-20 23:30 → SQL `2026-10-21 09:30:00`, grid cell 10-20, edit modal 10-20 / 23:30, re-save leaves SQL unchanged, list/Next-30-days "10/20/2026 11:30 PM"; drag to 10-22 → `2026-10-23 09:30:00`, modal 23:30; `/calendar/new` works; NY 10/27 → `03:30` next day, 11/10 and 03/10/2027, 03/20/2027 correct, drags across DST keep the wall clock; UTC unchanged; iCal `DTSTART:20261023T093000Z` / `…T043000Z` / `…T053000Z`, all-day `VALUE=DATE:20270313`. **FAIL:** a 23:30 event on the last day of a month is missing from that month: HI `2026-12-31T23:30` → stored `2027-01-01 09:30:00`; absent from the December grid and list (`from=2026-12-01&to=2026-12-31` and `from=2026-12-31&to=2026-12-31`), present in January (grid cell `2026-12-31`, list `from=2027-01-01&to=2027-01-31`). NY `2026-10-31T23:30` → `2026-11-01 03:30:00`: absent from the Oct grid/list; the Nov list shows "10/31/2026 11:30 PM". |
| **D4** package PDF + ISO scan | EXECUTED — PASS | `[verified MM/DD/YYYY, primary source]` for all 8 Coastal providers (Ana in HI "10/15/2026", in UTC "10/16/2026"). Visible-text scan in HI/NY/UTC of dashboard, `/me`, notifications, provider pages (all tabs), packet, package: 0 hits for ISO dates (other than the known "required since 2024-05-15"), 24-h times, "UTC", "Mon D". *(Reminder e-mail bodies still contain ISO dates, e.g. "expires on 2026-10-15 (today)"; e-mail was not in the scan list.)* |
| **D5** sign-in pages Spanish | **EXECUTED — FAIL (leftovers)** | Pages identical for `?lang=es`, cookie `flx_lang=es`, `Accept-Language: es`; "(Equipo → Establecer contraseña)" and "¿Es nuevo aquí?" translated; registration intro/fields and invitation text translated. **Still English:** `/login` tagline "Healthcare Compliance Intelligence Platform"; `/login` flash "Email or password is incorrect."; `/register` label "What are you?"; ten state options (New Hampshire, New Jersey, New Mexico, New York, North Carolina, North Dakota, South Carolina, South Dakota, West Virginia, Rhode Island — others like Hawái, Luisiana, Pensilvania are translated, so the list is inconsistent); `/register` flashes "Password must be at least 10 characters.", "Enter your organization name and your real first and last name.", "Enter a valid email address.", "Choose an organization type.", "Welcome to FlxCoreHC. Your organization is under review — you have read-only access until it's approved."; `/reset` POST "New password must be at least 10 characters."; `/invite` POST "Password must be at least 10 characters."; `/login/verify` wrong code "That code is not valid. Check your authenticator app and try again."; `/forgot` rate limit "Too many requests. Try again in 15 minutes." |
| **D6** `@flx_today` | EXECUTED — PASS (web layer) | ~30 Coastal pages + `/admin`, billing, plans, system, security, audit in HI/NY/UTC: no 5xx, no SQL/"Unknown system variable"/"@flx_today"; `error_log` 0 rows; dashboard (expired/≤30/≤90) equals SQL on the local date (HI 4/11/15, NY and UTC 6/10/13); credentials CSV "Days left" correct per zone; readiness export, invoices, billing 200. The only database-side date logic left on `CURDATE()` is the cron sweep (D1). |
| **D7** regression | EXECUTED — PASS | 3q-2 transports `smtp`/`mail`; `smtp_pass value=""`. 3q-5 `/admin/audit` 403 for an org user, 200 for platform admin. 3o-4 inline `<svg>`, cv2 decode → `otpauth://…` = setup key, TOTP enables 2FA. 3n-2 NBSP/ZWSP-only org names refused, padded names saved trimmed. F1 credential expiring 2027-03-13 in `data-date="2027-03-13"` (HI/NY/UTC); iCal all-day `VALUE=DATE:20270313`. |
| **D8** try to break | **EXECUTED — FAIL (month-end)** | No fatals. NY `2026-11-01T01:30` (ambiguous) → 05:30 UTC (first occurrence) shown 1:30 AM; NY `2027-03-14T02:30` (non-existent) → `07:30:00`, shown 3:30 AM; HI/NY/UTC leap day and year-end stored correctly. Switching zone after saving: a stored instant keeps its `due_at` and only the display moves (HI1 `2026-10-23 09:30 UTC` shows 11:30 PM 10/22 HI, 5:30 AM 10/23 NY, 9:30 AM 10/23 UTC); all-day items stay put. **FAIL:** HI 2028-02-29 23:30 (stored `2028-03-01 09:30`) absent from the Feb 2028 grid/list — same defect as D3. |

## 4. Surprising observations (nothing changed)
1. **Test blind spot:** stage 3a7 T3 asserts "no `CURDATE()` left in app/ or index.php" but `cron/` is not scanned — it is where the last four `CURDATE()` calls are.
2. A manual edit or unchanged re-save of the *second* 01:30 on 2026-11-01 in NY moves it by one hour (stored `06:30` → `05:30`; the modal shows 01:30 and re-reads it as the first occurrence). A non-existent time (02:30 on 2027-03-14) is silently stored and shown as 3:30 AM.
3. Events saved by earlier versions keep their typed-as-UTC value and are not rewritten (documented); after upgrade their displayed time is off by the org's offset.
4. `/calendar/new` and the Add-event modal always create timed events — there is no all-day option in the UI.
5. Create organization with an already-used e-mail is refused ("A user with that email already exists."); `/register` with an existing user's e-mail succeeds and creates a pending organization.
6. Reminder/digest code skips demo orgs (`is_demo=1`).
7. The agenda bucket rows show the date only (no time) for timed events.
8. No PHP warnings/notices/deprecations, no 5xx, `error_log` 0 rows on every instance.

## 5. Harness and fixtures (test instances only)
Shipped `tools/router.php` (`qa211_C`, 8131); my installs `i211_http`/`i211_https` (8141/8142); browser suite `bc17211` (8097); driver 8098/8099. Instance config `allow_open_registration` toggled for `/register`, restored (diff empty; 3 pending "QA Reg" orgs created). SQL fixtures (disclosed): an e-mail swap to obtain a user in two organizations for the picker, and a `password_resets.token_hash` update to obtain a usable reset URL. Org timezone changed repeatedly (left at America/New_York). Databases: `flx_i211_http`, `flx_i211_https`, `flx_qa211_c`, plus the suites' own.

## Files
`README.md` · `script_output/` · `browser_check/` (output + 8 PNG) · `logs/` · `screenshots/` (19 PNG: D1, D2, D3 modals, D5 login/register, D8 Dec/Jan grids, 3o-4 QR).
