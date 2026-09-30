# FlxCoreHC v1.171.5 — Independent validation results (Claude Code)

**Date:** 2026-09-30.

**Role:** runner and reporter only. I did not modify any application code, migrations, schema or tests.

**Environment:**
- PHP 8.4.19 (CLI; pdo_mysql, sodium, openssl)
- MariaDB 10.11.14, a disposable local server, root over 127.0.0.1
- python 3.11.15
- Playwright + Chromium (headless)
- PHP built-in server (`php -S`)

**Labels:** EXECUTED — PASS · EXECUTED — FAIL · REQUIRES BROWSER VALIDATION.

## Headline

| Area | Result |
|---|---|
| Step 2 `tools/runtime_check.sh` (pristine tree, `FLX_CONFIG`, no `app/config.php`) | **exit 0**. 639 PASS lines, **0 FAIL**, 0 SKIP results. 7 crawls × **220 pages**. Stage 5: no `.notif-sweep-*`. Pristine tree **byte-identical** afterwards (494 files, SHA-256). |
| Step 1 fresh install, http and https installer | **PASS** |
| Checklist items (3b, 3c, 3d, 3g, 3h, 3i, 4): 54 | **49 PASS · 4 FAIL · 1 REQUIRES BROWSER VALIDATION** |
| "Try to break it" list: 10 | **7 PASS · 3 FAIL** |
| Found outside the checklist | **1 data-loss bug** (provider POST blanks fields it did not send, reproduced independently), plus about 15 other observations (§6) |

**FAILs:**
- **3i-2:** some success messages show as red toasts, and some refusals as green.
- **3i-4 / B3:** no QR code is shown when enrolling 2FA.
- **3i-7 / B5:** JLDM is listed under "State board", not "Licensing board".
- **3i-11 / B9:** the CSV template repeats NPI 1234567893, so the importer skips one line.

**REQUIRES BROWSER VALIDATION:** 3g-8, which needs a production https install.

## 0. Script and setup

| Item | Label | Command and output |
|---|---|---|
| `claude_code_validate_v1_171_5.sh` | **NOT AVAILABLE** | The script was not among the uploaded files and is not inside the zip (`unzip -l … \| grep validate` → nothing). I performed its documented steps manually: unzip, confirm no `app/config.php`, run `runtime_check.sh` with `FLX_CONFIG`, package the results. |
| Zip has no `app/config.php` | EXECUTED — PASS | `unzip -l flxcorehc-v1_171_5.zip \| grep -c app/config.php` → `0`. After unzipping, `ls app/config.php` → "No such file or directory". |
| `FLX_CONFIG` | Set | `/tmp/flx_base_config.php` = `<?php return ['db_host'=>'127.0.0.1','db_port'=>3306,'db_user'=>'root','db_pass'=>''];` |

## 1. Step 2: `bash tools/runtime_check.sh`

```
$ cd v1715 && FLX_CONFIG=/tmp/flx_base_config.php bash tools/runtime_check.sh
real 2m14s   runtime_check exit=0
```

Every stage passed:
- **1** syntax
- **2** isolation
- **3** agency
- **3a** healthcare
- **3b** purge
- **3c** static
- **3d** authz
- **3e** migrations A–D
- **3f** HTTP smoke
- **3f2** installer over http and https
- **3g** security suite
- **5** stray files: "STRAY FILES: PASS (no .notif-sweep-* in app/storage)"

**Stage 4 (`harness.sh`)** did not run: "harness.sh not present in this checkout (dev-only)".

**Final line:** "All automated stages PASS…"

**Crawl lines:**
```
PASS — crawl as owner: pages=220 codes={200: 219, 302: 1}
PASS — crawl as manager: pages=220 codes={200: 218, 302: 1, 403: 1}
PASS — crawl as staff: pages=220 codes={200: 209, 403: 6, 302: 4, 404: 1}
PASS — crawl as coord: pages=220 codes={200: 209, 403: 6, 302: 4, 404: 1}
PASS — crawl as readonly: pages=220 codes={200: 199, 403: 16, 302: 4, 404: 1}
PASS — crawl as restricted: pages=220 codes={200: 211, 403: 6, 302: 2, 404: 1}
PASS — crawl as padmin: pages=220 codes={200: 218, 302: 2}
```

**About the word "skip":** `grep -i skip` finds 4 lines, but none of them is a SKIP result.
- Three are PASS lines whose descriptions contain the word (B2, B3, V5).
- The fourth is stage 4's "harness.sh not present … skip if you don't have it".

Full output: `logs/runtime_check_output.txt` (702 lines).

**Tree integrity:** a SHA-256 of all 494 files taken before and after Step 2 is identical. `app/config.php` remained absent.

## 2. Step 1: fresh install (instances `i15_http` and `i15_https`, empty DBs)

| Check | Label | Evidence |
|---|---|---|
| POST without `_csrf` refused | EXECUTED — PASS | HTTP 200 "Your session expired or this request did not come from the installer page". Config absent, 0 tables. |
| POST with altered `_csrf` refused | EXECUTED — PASS | Same message. Config absent, 0 tables. |
| Install with passphrase field | EXECUTED — PASS | "✓ Installation complete — created 89 tables and your administrator account." No "Migration failed". |
| `group_npi` / `clia_number` | EXECUTED — PASS | One row each: `varchar(10)` and `varchar(20)`. |
| `schema_migrations` | EXECUTED — PASS | 132 rows, 124 through 132 present. |
| Installer over http | EXECUTED — PASS | `app_url='http://127.0.0.1:8141' hsts=false` |
| Installer with `X-Forwarded-Proto: https` | EXECUTED — PASS | `app_url='https://127.0.0.1:8142' hsts=true` |
| `pr_cme` note right after install | EXECUTED — PASS | `has_10h=0 has_unverified=1` |

## 3. Checklist results

Runners, each on its own copy, database and port:
- **A** = qa15_A, port 8111
- **B** = qa15_B, port 8121
- **C** = qa15_C, port 8131
- **Me** = i15_http / i15_https

### 3b

| # | Label | Evidence |
|---|---|---|
| 3b-1 | EXECUTED — PASS | (A) Manager self-promotion and add-as-owner (injected `tenant_owner`) both show "You can only assign roles that do not exceed your own permissions (Master Admin roles: Master Admin only)." as `toast error`, border rgb(221,79,93). DB unchanged. |
| 3b-2 | EXECUTED — PASS | (A) Staff assigned only to Maria Santos. Credentials, tasks, calendar and the CSV export (6 rows) show only Maria. Pedro's credential edit → 404. |
| 3b-3 | EXECUTED — PASS | (A) `/holders/{Maria}` 200, all tabs open; an unassigned provider → 404. |
| 3b-4 | EXECUTED — PASS | (A) Attempts 1–8: "Enter a valid code…"; attempts 9–10: "Too many attempts. Try again in 15 minutes."; `totp_enabled` still 1. |
| 3b-5 | EXECUTED — PASS | (A) "Reporting year 2026". |

### 3c

| # | Label | Evidence |
|---|---|---|
| 3c-1 | EXECUTED — PASS | (A) See the notes below this table. |
| 3c-2 | EXECUTED — PASS | (A) The coordinator's credential edit page has 0 verify controls. A POST to `/verify` → 403. |
| 3c-3 | EXECUTED — PASS | (A) The portal role lands on `/me`. Provider B's document → 404. The picker lists only Ana. An unlinked login → 403 "Your login is not linked to a provider record…", with no loop. |
| 3c-4 | EXECUTED — PASS | (A) iCal: 6 events, all Maria. After suspension → 404; after re-enabling → 200. |
| 3c-5 | EXECUTED — PASS | (A) `user_role_changed` with from/to, `owner_level_role_granted` and `share_link_revoked` are all present in the DB and the UI. |

**3c-1 notes:**
- **Staff dashboard:** "Showing your assigned providers only."; Providers 1, Expiring 3, Due≤90 3, Docs 2. The owner sees 9/8/11/19.
- **Case KPIs:** staff 1/1, owner 3/3.
- **Pasted IDs:** 404 "Not found." / "Document not found.".
- **Assign/unassign:** takes effect immediately.
- **Groups/Locations/Applications:** staff is refused (403, or redirected to `/`), so I tested with a Manager set to assigned-only: counts 0/1, members Maria only.
- **Screening KPI:** there is no tenant-level view, so it could not be observed.

### 3d

| # | Label | Evidence |
|---|---|---|
| 3d-1 | EXECUTED — PASS | (A) "You cannot grant capabilities you do not hold yourself: pii.view."; "Only an owner can change a built-in role."; "This role holds capabilities you do not have, so you cannot edit it." All shown as red toasts. |
| 3d-2 | EXECUTED — PASS | (A, Chromium) After both suspend and password reset, the next click lands on `/login` showing "You have been signed out. Please sign in again." Gone after a reload. |
| 3d-3 | EXECUTED — PASS | (A) Non-demo tenant: "Resetting a user's password needs an approved support session…"; hash unchanged. *The refusal is shown as a green toast (§6).* |
| 3d-4 | EXECUTED — PASS | (A) PA and NP providers: type, specialty, CAQH and PTAN are identical after an unchanged save. |
| 3d-5 | EXECUTED — PASS | (A) A short passphrase is refused. `.flxkeys` downloaded; the banner disappears after confirming. `restore_keys.php` exit 0 and the keys match the config. A wrong passphrase gives exit 1. |
| 3d-6 | EXECUTED — PASS | (A) The upload is stored with `FLXENC1`, no plaintext. The download is byte-identical. Script output is below this table. |

**3d-6 script output:**
- Before the upload: `[dry-run] would convert: 0, already encrypted: 72, failed: 0` exit 0; real run exit 0.
- Legacy fixture: `converted: 1`.
- Fresh install (me): `[dry-run] would convert: 0, already encrypted: 0, failed: 0` exit=0; real run exit=0; `.keep` untouched.

### 3g

| # | Label | Evidence |
|---|---|---|
| 3g-1(a) | EXECUTED — PASS | (B) 13 wrong passwords from 127.0.0.10: attempts 1–8 "incorrect", 9–13 "Too many…". `acct|` = 8. Correct password from .10 → refused; from never-seen .11 → signed in. |
| 3g-1(b) | EXECUTED — PASS | (B) 12 failures spread over .20/.21/.22, so `acct|` = 12. Never-seen .23 with the correct password → "Too many sign-in attempts. Try again in 15 minutes." |
| 3g-1(c) | EXECUTED — PASS | (B) Owner fully signed in from .30, then 8 wrong attempts from .30: owner from .30 → "Too many…"; from .31 → signed in. |
| 3g-2 | EXECUTED — PASS | (B) The link token is 40 characters; DB `invite_token` is 64 hex. Accepting the invite and signing in both work. |
| 3g-3 | EXECUTED — PASS | (Me) See §2. |
| 3g-4 | EXECUTED — PASS | (B) GET `/admin`, `/admin/email` and `/compliance` ×2 → 4 rows still `queued`, attempts 0. Cron: "0 queued, 4 delivered". |
| 3g-5 | EXECUTED — PASS | (B) Home address displays; `line1`, `line2`, `phone` NULL; `line1_enc` 60 B binary; dry-run 0 rows. |
| 3g-6 | EXECUTED — PASS | (B) `.sql.gz.flxenc` with header `FLXDUMP1`; `gunzip -t` → "not in gzip format". Decrypt exit 0, then `gunzip -t` exit 0. Wrong passphrase gives exit 1 and no file. Wrong account password, a 9-character passphrase and a mismatch are each refused. |
| 3g-7 | EXECUTED — PASS | (B) "Santos, Maria · Initial credentialing · Open"; "Create"/"Update"; hover shows "ID …". |
| 3g-8 | REQUIRES BROWSER VALIDATION | (B) On the http/dev install the URL is `http://127.0.0.1:8121/ical/….ics` (200 text/calendar). The https behaviour needs a production https install. |
| 3g-9 | EXECUTED — PASS | (Me) The pristine tree is byte-identical after Step 2, and `app/config.php` was never created. |

### 3h

| # | Label | Evidence |
|---|---|---|
| 3h-1 | EXECUTED — PASS | (B) "Verification records" plus the certify disclaimer on the provider panel, the credential form and Help (EN and ES). |
| 3h-2 | EXECUTED — PASS | (B) "fully compliant" appears 0 times on the dashboard, `/compliance`, the empty state and the portal. |
| 3h-3 | EXECUTED — PASS | (B) "Junta Examinadora de Enfermería" appears under Licensing board. The `pr_cme` note says UNVERIFIED and has no "10 hours"/"6 hours". The drug-test item says "Applicability UNVERIFIED". |
| 3h-4 | EXECUTED — PASS | (B) Reusing the invite → 404 "Invitation not found…". |
| 3h-5 | EXECUTED — PASS | (Me) See §4. |
| 3h-6 | EXECUTED — PASS | (Me) See §4. |

### 3i

| # | Label | Evidence |
|---|---|---|
| 3i-1 | EXECUTED — PASS | (C, and A in 3d-2) The notice appears on `/login` once after suspend and after reset; it is gone on reload. |
| **3i-2** | **EXECUTED — FAIL** | (C) The listed refusals are red and "Role … created." / "Profile updated." are green. **But some successes are red:** `{"cls":"toast error","txt":"Record created — required credentials for this profession were added automatically."}`; `'Outbox flushed — 2 sent, 0 failed/queued.' ['error']`; `"Welcome to FlxCoreHC. Your organization is under review — you have read-only access until it's approved." ['error']`. **And some refusals are green** (A): "Resetting a user's password needs an approved support session…" → `toast ok`; "Pick which provider this login belongs to." → `ok`. Cause observed: `flx_flash_type()` in `app/bootstrap.php:35-38` guesses the colour from keywords in the message. |
| 3i-3 | EXECUTED — PASS | (C) 9× GET `/admin/email`, a second tab, HEAD, and `?action=flush_outbox` → rows `queued`, attempts 0, SMTP sink count unchanged. POST "Send queued now" → `sent`, attempts 1. |
| **3i-4** | **EXECUTED — FAIL** | (C) Enabling works for read-only (Off → On, `totp_enabled=1`) and every write is refused. **Missing: the QR code.** `QR-like elements in 2FA panel: img 0 svg 0 canvas 0`. Only "Setup key" and the link "On a phone? Tap here to add it automatically ↗" are shown. Static check: no view renders a QR (`app/views/profile.php:69` shows only the `otpauth:` link), although `Totp.php:27` mentions "the QR encoder we ship". |
| 3i-5 | EXECUTED — PASS | (C) Password accepted but 2FA abandoned from .21, or a wrong code from .22 → no `known|…` row. Completed from .23 → `known|readonly@coastalcred.example|127.0.0.23`. |
| 3i-6 | EXECUTED — PASS | (C) FL-only: 0 OCS panels on all 9 providers, including one with an OCS row. PR: 1. FL+PR: 1. |
| **3i-7** | **EXECUTED — FAIL** | (C) "Junta Examinadora de Enfermería" is under **Licensing board**, but **JLDM is under "State board"**: `{"Junta de Licenciamiento y Disciplina Médica (JLDM)":"State board","Junta Examinadora de Enfermería":"Licensing board"}`. SQL: `JLDM … | state_board | PR`. Both are visible, and both detail pages return 200. |
| 3i-8 | EXECUTED — PASS | (C) The `pr_cme` note MD5 was unchanged through the demo build, 2 rebuilds, 2 sign-ups (PR and FL), 2 approvals and "Sync templates". UNVERIFIED shows in the UI; 0 tenant-level `pr_cme` rows. |
| 3i-9 | EXECUTED — PASS | (C) "Master Admin" appears once. `role_created` count goes 0→1→2→3, one row per create. The Manager's `/team` HTML contains `tenant_owner` 0 times. *(The audit log, however, shows "to: tenant_owner" to a Manager; see §6.)* |
| 3i-10 | EXECUTED — PASS | (C) 42 providers across 7 organizations, 325 evidence rows: 0 blank names, states or details. Example: "Laboratory Director Qualifications \| cms \| Missing \| No credential on file". |
| **3i-11** | **EXECUTED — FAIL** | (C) Template NPIs: 1234567893 (Jane) → Create; **1234567893 (Carlos, line 3) → `Skip | Duplicate NPI within the file (line 2).`**; 1245319599 → Create; 1003000126 → Create. Commit: "3 provider(s) imported, 1 skipped." All 4 NPIs are valid, but the template repeats one (`index.php:1188-1189`, confirmed). |
| 3i-12 | EXECUTED — PASS | (Me, fresh install) `--dry-run` → `would convert: 0 … failed: 0` exit 0; real run exit 0; `.keep` skipped. |
| 3i-13 | EXECUTED — PASS | (Me, and runtime stage 3f2) http → `http://`, `hsts=false`; `X-Forwarded-Proto: https` → `https://`, `hsts=true`. |

### Step 4 (runner B)

| # | Label | Evidence |
|---|---|---|
| 4-1 | EXECUTED — PASS | No "Other"; PR is preselected; after approval only [PR] is ticked. |
| 4-2 | EXECUTED — PASS | "…ruleset HC2026.1". No CMS-4208 or "commercial lines". The tab is "Providers". |
| 4-3 | EXECUTED — PASS | PR tenant: 0 → 2.5 hrs "last 36 months", checklist row "RENEWS EVERY 36 MO". FL tenant: "last 24 months" / "RENEWS EVERY 24 MO". No NIPR, NPN or "I'd want this". |
| 4-4 | EXECUTED — PASS | All 7 fields present. "Group NPI must be exactly 10 digits."; CLIA `40D1234567` → "Entity profile saved." |
| 4-5 | EXECUTED — PASS | Logged out: Provider, NPI and Specialty shown (specialty only when set). No Producer or NPN. |
| 4-6 | EXECUTED — PASS | "Credentialing Audit-Readiness Package", "(Provider)". |
| 4-7 | EXECUTED — PASS | Provider, client group and payer are all `<select>`. |
| 4-8 | EXECUTED — PASS | No "Client call" and no `client_name`. |

## 4. Items run by me (numbers)

### 3h-5 address back-fill

- **Instance:** i15_http, provider "Luis Colon".
- **Fixtures:** 3 rows inserted by SQL, with ciphertext produced by the app's own `Crypto::encrypt`.

```
$ php database/encrypt_existing_addresses.php --dry-run
[dry-run] would encrypt: 4 column value(s) in 2 address row(s)
$ php database/encrypt_existing_addresses.php
encrypted: 4 column value(s) in 2 address row(s)          exit=0
$ php database/encrypt_existing_addresses.php            (second run)
encrypted: 0 column value(s) in 0 address row(s)
pre-existing ciphertext comparison:
QA-R2 line1-legacy  l2: IDENTICAL   ph: IDENTICAL
QA-R3 migrated      l1: IDENTICAL   l2: IDENTICAL   ph: IDENTICAL
```

- **Full before/after HEX:** `logs/3h5_addresses_before.tsv` and `logs/3h5_addresses_after.tsv`.
- **UI:** `GET /holders/61ccec52…` → 200. All 9 values render (each occurs 2 times).

### 3h-6 memory

Run as `FLX_CONFIG=<flx_memtest15 config> php -d memory_limit=… tools/dump_memory_check.php …`. OS peak memory (RSS) is from a python `getrusage` wrapper.

| Args | DB size | memory_limit | PHP peak | OS peak (RSS) | Result |
|---|---|---|---|---|---|
| 100000 400 | 54.9 MB | 256M | 142.2 MB | 173.2 MB | `result: OK (completed within memory_limit)`, exit 0, 10.6 s |
| 50000 400 | 29.9 MB | 128M | 72.3 MB | 105.0 MB | `result: OK (completed within memory_limit)`, exit 0, 3.7 s |

## 5. "Try to break it" list

| # | Item | Label | Evidence |
|---|---|---|---|
| B1 | "You have been signed out" on `/login` | EXECUTED — PASS | (C, A) Suspend → 1 notice, reload → 0. Reset → 1 then 0. A stale-form POST → 1. Second tab → 1 in one tab, 0 in the other. A role change does not sign the user out (the new role applies on the next request). **Edge case:** if the stale session's next request is `/login` itself, the notice is never shown (`count=0`). |
| B2 | GET `/admin/email` does not send | EXECUTED — PASS | (C, B) Rows stay `queued`/0 attempts after repeated GETs, HEAD and `?action=flush_outbox`. POST "Send queued now" sends. |
| **B3** | Read-only can enable 2FA but not write | **EXECUTED — FAIL** | (C) 2FA turns on and all 22 write POSTs are refused with "Your account has read-only access." (SQL confirms nothing changed). **FAIL only because no QR code is shown** (see 3i-4). |
| B4 | FL-only: no OCS panel; PR: panel | EXECUTED — PASS | (C) See 3i-6, and a sign-up FL tenant with an OCS fixture row → 0. |
| **B5** | PR boards visible on `/regulators` | **EXECUTED — FAIL** | (C) They are visible, but JLDM is under "State board" instead of "Licensing board" (see 3i-7). |
| B6 | `pr_cme` UNVERIFIED after a NEW sign-up | EXECUTED — PASS | (C) Unchanged MD5 after 2 sign-ups; UNVERIFIED in the UI and SQL. |
| B7 | Master Admin once / one `role_created` / Manager never sees `tenant_owner` | EXECUTED — PASS | (C) Within the checklist's scope (Roles and Team). **But the Manager's Audit log shows "Users from: agent · to: tenant_owner".** |
| B8 | Audit package evidence rows | EXECUTED — PASS | (C) 325 rows, 0 blank. |
| **B9** | Every template NPI accepted | **EXECUTED — FAIL** | (C) The duplicate 1234567893 → "Skip — Duplicate NPI within the file (line 2)." |
| B10 | Installer http → http/`hsts=false`; https → https/`hsts=true` | EXECUTED — PASS | (Me) `logs/installer_http_https_config.txt`, plus stage 3f2 ALL PASS. |

## 6. Surprising observations (not in the checklist; nothing changed)

1. **DATA LOSS: a provider POST blanks fields the form did not send.** I reproduced this independently on i15_http.
   - `POST /holders/61ccec52…` with only `_csrf` and `telehealth_enabled=1` → 302, "Provider updated.".
   - Before (a DB copy taken before the POST): `Luis | Colon | npi_set=1 | email_set=1`. After: `NULL | NULL | 0 | 0`.
   - `audit_logs`: `provider_updated ["first_name","last_name","org_name","email","role_title","npi","birth_month","license_code"]`.
   - The comment at `index.php:2027` claims it "never blanks a field the form did not submit".
   - Runner C saw the same from unknown sub-paths: `POST /holders/{id}/enrollment` wiped Maria Santos.
2. **Stage 5 will fail on any installation that has been used.** Normal app use writes `app/storage/.notif-sweep-<tenant>`. `NotificationService::stampPath()` uses `dirname(docs_path)` = `app/storage`, and it happens on login and demo build. qa15_A has 2, qa15_B 5, qa15_C 11. Running stage 5's exact test on qa15_C prints "STRAY FILES: FAIL — test runners left files in app/storage:" although no test runner ran there. It passed in Step 2 only because the pristine tree was never used.
3. **2FA can be re-enrolled without the current code.** With 2FA on, POST `2fa_start` then `2fa_confirm` with a code for a new secret replaces the secret. This bypasses the rate-limited check that disabling requires. Enabling and re-enrolling write no audit row; only `2fa_disabled` is audited. (C)
4. **An invite POST flushes the whole outbox.** A Coastal team invite also delivered 3 earlier queued reminders, and organization approval flushes too. (C) In B's run with mail off, the invite stayed queued until cron.
5. **The unsealed database backup is still downloadable.** GET `/admin/backup/database` → 200 `application/gzip` `.sql.gz`, alongside the sealed option. (B)
6. **Platform SMTP accepts 127.0.0.1:2621**, while tenant SMTP refuses private hosts. (B)
7. **A known-place sign-in clears the per-e-mail counter.** After a spread attack (`acct|`=12), one correct sign-in from a known place deletes `acct|…`. (B)
8. **Staff can export CSV** (`/reports/credentials/export` → 200) although `docs/DEMO_ACCOUNTS.md` says Staff has "no export". The export is correctly scoped. (A)
9. **FL-only tenant still shows PR content** outside the OCS panel: "What CAQH, OCS/SICRO, Medicaid PEP…", PR payers, all PR boards on `/regulators`, and "⚖ Why? PR Departamento de Salud — ORCPS" on FL checklist rows. (B, C)
10. **The forced sign-out flash is set as `warn` but renders as a blue info box.** After a forced sign-out, `/login` keeps the prior tenant's branding. (A)
11. **Replaying a role-create POST** (same CSRF token) creates a second role with the same name. (C)
12. **Raw values in the audit UI:** slugs ("to: tenant_owner", visible to Managers) and UUIDs in the "Changes" column. (A, B, C)
13. **Spanish gaps:** the credential form's verification paragraph, "Not payer-scoped", "Manage the list under", and the Team role names "Staff", "Provider (self-service)", "Read-only" stay in English. (B, C)
14. **Read-only users see actions they cannot perform:** the nav shows `/credentials/new`, and Profile shows "Save profile" / "Update password", which are then refused. (C)
15. **Minor:**
    - `/reports/readiness` → 404 (only `/export` exists).
    - The calendar modal and `/calendar/new` offer different event-type lists.
    - The Compliance KPI "1/9 current" vs the dashboard "L5 … 0".
    - The CSV-import help links "Document types" to the custom-types page only.
    - A wrong 2FA code writes no `login_attempts` row.
16. **No server errors:** no 5xx, no PHP warnings/notices/deprecations/fatals, and 0 rows in `error_log` on all instances. Note that `php -S` with a router script logs only static files, so status codes were checked from the client side.

## 7. Test harness and fixtures (test instances only)

- **Router:** `php -S` does not apply `.htaccess`, so the browser runs used a router outside the app (`router15_{A,B,C}.php`) that mirrors it: static files served, `/app|database|cron|tools|tests|docs` → 403, everything else → `index.php`.
- **SQL fixture writes:**
  - 3 `addresses` rows (me)
  - `users.role`/`status` fixes for a portal login and a crashed-script restore (A)
  - 2 `email_outbox` rows and 1 `ocs_profiles` row (C)
- **Instance config:** `allow_open_registration` toggled for sign-up, then restored (hash identical) (B, C).
- **SMTP:** local SMTP sinks on 127.0.0.1 (B, C).
- **Throwaway databases:**
  - `flx_i15_http`, `flx_i15_https`, `flx_qa15_a/b/c`, `flx_memtest15`
  - plus those the suites create (`flx_agency`, `flx_purge`, `flx_authz`, `flx_mig_*`, `flx_smoke`, `flx_security`, `flx_install_chk`)

## Files in this bundle

- `README.md`: this summary.
- `logs/runtime_check_output.txt`: the complete Step 2 output.
- `logs/installer_http_https_config.txt`: the `app_url` and `hsts` values each installer wrote.
- `logs/3h5_addresses_before.tsv` and `logs/3h5_addresses_after.tsv`: before/after HEX of the address ciphertext.
- `screenshots/`: 94 screenshots (A_* = 3b–3d; B_* = 3g, 3h, 4; C_* = 3i and the break list).
