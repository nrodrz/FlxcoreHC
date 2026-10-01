# FlxCoreHC v1.171.6: independent validation results (Claude Code)

**Date:** 2026-10-01.

**Role:** runner and reporter only. I did not modify any app code, migrations, schema or tests.

**Bundle:** `claude_code_bundle_v1_171_6.zip`. The `CLAUDE_CODE_RUNTIME_CHECK.md` inside the bundle is identical to `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` inside the app zip (checked with `diff`).

**Environment:**
- PHP 8.4.19 CLI with pdo_mysql, sodium and openssl
- MariaDB 10.11.14 (disposable local server, root over 127.0.0.1)
- Python 3.11.15
- Playwright with headless Chromium
- `php -S` as the web server

**Labels:** EXECUTED — PASS · EXECUTED — FAIL · REQUIRES BROWSER VALIDATION.

## Headline

| Area | Result |
|---|---|
| `claude_code_validate_v1_171_6.sh`, run as written | **exit 0** · 651 PASS lines · "no FAIL/SKIP lines" · 7 crawls × **220 pages** · "STRAY FILES: PASS (… 0 pre-existing)" |
| Stage 5 on an installation that has already been used | **PASS** · "STRAY FILES: PASS (… 1 pre-existing)" · exit 0 · 651 PASS · `app/config.php` unchanged |
| Checklist items (Steps 1, 3b, 3c, 3d, 3g, 3h, 3i, 3j, 4): 60 | **58 PASS · 1 FAIL (4.3, FL tenant) · 1 REQUIRES BROWSER VALIDATION (3g-8)** |
| "Try to break" list (K1–K5) | **3 PASS · 2 FAIL** (K2 sweep, K5 whitespace) |
| Untouched copy of the app | Byte-identical after every run (SHA-256 of 497 files). It never got an `app/config.php`. |

## 1. Validation script

```
$ DB_USER=root DB_PASS= bash claude_code_validate_v1_171_6.sh flxcorehc-v1_171_6.zip
SCRIPT EXIT=0   (real 2m03s)
00_environment.txt: app/config.php count: 0
02_exit_code.txt:   runtime_check exit code: 0
03_summary.txt:     == stray files == docs | == config.php present after run? == No such file or directory
                    PASS lines: 651 | no FAIL/SKIP lines
```

**Crawl results:**
```
PASS — crawl as owner: pages=220 codes={200: 219, 302: 1}
PASS — crawl as manager: pages=220 codes={200: 218, 302: 1, 403: 1}
PASS — crawl as staff: pages=220 codes={200: 210, 403: 5, 302: 5}
PASS — crawl as coord: pages=220 codes={200: 210, 403: 5, 302: 5}
PASS — crawl as readonly: pages=220 codes={200: 196, 403: 18, 302: 5, 404: 1}
PASS — crawl as restricted: pages=220 codes={200: 211, 403: 6, 302: 2, 404: 1}
PASS — crawl as padmin: pages=220 codes={200: 218, 302: 2}
STRAY FILES: PASS (this run created no .notif-sweep-* in app/storage; 0 pre-existing)
```

**Stage 4 (`harness.sh`):** reports "not present in this checkout (dev-only)", as in earlier releases.

**All of the script's own output** is in `script_output/`:
- `00_environment.txt` through `03_summary.txt`
- `01_runtime_check_output.txt`
- `04_browser_checks_TODO.md`
- `driver_console.txt`

**Stage 5 on a used installation (3j-6).** The script always unzips a fresh copy, so it cannot be pointed at an existing installation. I ran `tools/runtime_check.sh` directly instead, on `i16_http`. That installation had been installed and seeded, signed into, and browsed (`/`, `/holders`, `/credentials`, `/compliance`, `/notifications`, `/calendar`). That use created a real `.notif-sweep-84b30718…` file.
```
BEFORE: .notif-sweep-84b30718-3a86-45da-9d44-0c847e059e5e docs
$ FLX_CONFIG=/tmp/flx_base_config.php bash tools/runtime_check.sh   → exit 0, PASS=651, no FAIL/SKIP lines
STRAY FILES: PASS (this run created no .notif-sweep-* in app/storage; 1 pre-existing)
AFTER:  .notif-sweep-84b30718-3a86-45da-9d44-0c847e059e5e docs      app/config.php: OK (sha256 unchanged)
```
- Static check of the stage 5 code: `tools/runtime_check.sh:9,80-82` compares the file list before and after the run with `comm -13`, so it fails only on new files.
- Full log: `logs/3j6_used_install_runtime_check.txt`.

## 2. Step 1: fresh install

| Check | Label | Evidence |
|---|---|---|
| POST without `_csrf` | EXECUTED — PASS | "Your session expired or this request did not come from the installer page"; no config written; 0 tables |
| POST with an altered `_csrf` | EXECUTED — PASS | Same message; no config written; 0 tables |
| Install (with the key-backup passphrase) | EXECUTED — PASS | "✓ Installation complete — created 89 tables and your administrator account." No "Migration failed". |
| `group_npi` / `clia_number` columns | EXECUTED — PASS | One row each: varchar(10) and varchar(20) |
| `schema_migrations` | EXECUTED — PASS | 132 rows, migrations 124 to 132 present |
| Install over http | EXECUTED — PASS | `app_url='http://127.0.0.1:8141' hsts=false` |
| Install with `X-Forwarded-Proto: https` | EXECUTED — PASS | `app_url='https://127.0.0.1:8142' hsts=true` |
| `pr_cme` note after install | EXECUTED — PASS | `has_10h=0`, `has_UNVERIFIED=1` |

## 3. Checklist

**Who ran what.** Each runner had its own copy of the app, its own database and its own port:
- **A** = `qa16_A`, port 8111
- **B** = `qa16_B`, port 8121
- **C** = `qa16_C`, port 8131
- **Me** = `i16_http` and `i16_https`

### 3b

| # | Label | Evidence |
|---|---|---|
| 3b-1 | EXECUTED — PASS | (A) Self-promotion and add-as-owner both rejected with "You can only assign roles that do not exceed your own permissions (Master Admin roles: Master Admin only)." The toast is class `toast error`, red rgb(221,79,93). DB unchanged. |
| 3b-2 | EXECUTED — PASS | (A) Credentials, tasks, calendar and the CSV export (6 rows) show only Santos. Another provider's credential returns 404. |
| 3b-3 | EXECUTED — PASS | (A) Provider page returns 200 and every tab opens. |
| 3b-4 | EXECUTED — PASS | (A) Codes 1–8 give "Enter a valid code…"; codes 9–10 give "Too many attempts. Try again in 15 minutes." |
| 3b-5 | EXECUTED — PASS | (A) "Reporting year 2026"; "2027" appears 0 times. |

### 3c

| # | Label | Evidence |
|---|---|---|
| 3c-1 | EXECUTED — PASS | (A) Staff dashboard: notice shown; staff sees 1/3/3/2 against the owner's 9/8/11/19. Case KPIs: staff 1, owner 3. Pasted IDs return 404. Assign and unassign take effect immediately. Staff gets 403 on groups and locations, so I checked those scoping rules with a Manager set to assigned-only: counts 1/0, only Maria Santos listed. |
| 3c-2 | EXECUTED — PASS | (A) The coordinator sees 0 verify controls; a direct POST to `/verify` returns 403. |
| 3c-3 | EXECUTED — PASS | (A) Portal login lands on `/me`. Provider B's document returns 404. The provider picker lists only Ana; a tampered POST returns 403. An unlinked portal login returns 403 with no redirect loop. |
| 3c-4 | EXECUTED — PASS | (A) iCal feed: 6 events, all Santos. After suspension it returns 404; after re-enable it returns 200. |
| 3c-5 | EXECUTED — PASS | (A) Audit rows present: `user_role_changed` (from/to), `owner_level_role_granted`, `share_link_revoked`. |

### 3d

| # | Label | Evidence |
|---|---|---|
| 3d-1 | EXECUTED — PASS | (A) "You cannot grant capabilities you do not hold yourself: pii.view." and "This role holds capabilities you do not have…". "Only an owner can change a built-in role." All shown red. |
| 3d-2 | EXECUTED — PASS | (A, Chromium) Tested both suspend and password reset. Each time the user lands on `/login` with "You have been signed out. Please sign in again." shown once; it is gone after reload. |
| 3d-3 | EXECUTED — PASS | (A) Non-demo tenant: refused with "Resetting a user's password needs an approved support session…". The toast is now **red**. Password hash unchanged. |
| 3d-4 | EXECUTED — PASS | (A) PA and NP providers: rows identical before and after saving. |
| 3d-5 | EXECUTED — PASS | (A) `.flxkeys` downloaded; banner gone; `restore_keys.php` exit 0 and the keys match the config; a wrong passphrase gives exit 1. |
| 3d-6 | EXECUTED — PASS | (A) The uploaded file starts with `FLXENC1` and contains no plaintext; the download is byte-identical. `encrypt_existing_files.php`: dry-run and real run both exit 0 (72 then 73 already encrypted). A legacy fixture file was converted and still reads back correctly. |

### 3g

| # | Label | Evidence |
|---|---|---|
| 3g-1(a) | EXECUTED — PASS | (B) 13 wrong passwords from .10: attempts 1–8 "incorrect", 9–13 "Too many…". `acct|`=8. The correct password from .10 is refused; from a never-seen .11 it signs in. |
| 3g-1(b) | EXECUTED — PASS | (B) 12 failures spread over .20/.21/.22 gives `acct|`=12. A never-seen .23 is then refused even with the correct password; the known place .25 signs in. |
| 3g-1(c) | EXECUTED — PASS | (B) Owner fully signed in from .30. After 8 wrong passwords, .30 gives "Too many…" while .31 signs in. |
| 3g-2 | EXECUTED — PASS | (B) The token in the link is 40 characters; the DB holds a 64-character hex value. The invite was accepted and the user signs in. |
| 3g-3 | EXECUTED — PASS | (Me) See §2. |
| 3g-4 | EXECUTED — PASS | (B) GETs to `/admin`, `/admin/email`, `/compliance` and `/` each twice: the row stays `queued` with 0 attempts. Cron then printed "0 queued, 1 delivered". |
| 3g-5 | EXECUTED — PASS | (B) `line1`/`line2`/`phone` are NULL and `line1_enc` is 60 bytes of binary. Dry-run reports 0 rows. |
| 3g-6 | EXECUTED — PASS | (B) File header is `FLXDUMP1`; decryption plus `gunzip -t` succeed (exit 0). A wrong passphrase gives exit 1 and no file. Wrong password, short passphrase and mismatched passphrases are each refused. |
| 3g-7 | EXECUTED — PASS | (B) "Rivera, Ana · Payer enrollment · Open". Audit shows "Create"/"Update"; hovering shows the ID. |
| 3g-8 | REQUIRES BROWSER VALIDATION | (B) On the http/dev install the URL shown is `http://127.0.0.1:8121/ical/….ics`, which follows `app_url`. The production https case was not exercised. |
| 3g-9 | EXECUTED — PASS | (Me) `app/config.php` has the same sha256 before and after the run on the used installation. The untouched copy has no config before or after. |

### 3h

| # | Label | Evidence |
|---|---|---|
| 3h-1 | EXECUTED — PASS | (B) "Verification records" title and the "does not independently perform or certify" sentence appear in English and Spanish. |
| 3h-2 | EXECUTED — PASS | (B) "fully compliant" appears 0 times across 4 tenants. |
| 3h-3 | EXECUTED — PASS | (B) Enfermería is listed; the PR CE note says UNVERIFIED with no "10 hours"/"6 hours"; the drug-test item says "Applicability UNVERIFIED". |
| 3h-4 | EXECUTED — PASS | (B) Reusing an invite link returns 404 "Invitation not found…". |
| 3h-5 | EXECUTED — PASS | (Me) Dry-run "would encrypt: 4 column value(s) in 2 address row(s)". Real run "encrypted: 4 … 2", exit 0. Second run: 0. Ciphertext that already existed is IDENTICAL (R2 l2/ph; R3 l1/l2/ph). No plaintext remains. All 9 values show in the UI (`GET /holders/4c1e1449…` 200). HEX values are in `logs/3h5_*.tsv`. |
| 3h-6 | EXECUTED — PASS | (Me) See the table below. |

**3h-6 memory test:**

| Rows / limit | DB size | Peak PHP memory | Peak RSS | Result |
|---|---|---|---|---|
| 100000 rows, 256M | 54.9 MB | 142.2 MB | 173.1 MB | OK, exit 0 |
| 50000 rows, 128M | 29.9 MB | 72.3 MB | 104.9 MB | OK, exit 0 |

Full output: `logs/3h6_memory.txt`.

### 3i

| # | Label | Evidence |
|---|---|---|
| 3i-1 | EXECUTED — PASS | (C) Suspend and reset both lead to `/login` with the notice shown once. |
| 3i-2 | EXECUTED — PASS | (C, browser-computed colours) **Red:** promote, wrong password, "A valid email is required…", support-reset refusal. **Green:** "Role … created.", "Provider added and portal invite sent…", "Outbox flushed — 1 sent, 0 failed/queued." Note: the doc's text says "…0 failed", but the actual message ends "failed/queued". |
| 3i-3 | EXECUTED — PASS | (C) GETs to `/admin/email` etc. leave the row `queued` with 0 attempts. POST flush sets it to `sent` with 1 attempt. |
| 3i-4 | EXECUTED — PASS | (C) A read-only user can enable 2FA: the page shows the setup key and a tap-to-add link, with **no QR, as intended**. Writes are refused. |
| 3i-5 | EXECUTED — PASS | (C) If 2FA is abandoned, no `known|` row is written; a full sign-in writes one. |
| 3i-6 | EXECUTED — PASS | (C) OCS panel: shown under PR, hidden under FL, shown again after restoring PR. |
| 3i-7 | EXECUTED — PASS | (C) JLDM appears under "State board" and Enfermería under "Licensing board", as expected. |
| 3i-8 | EXECUTED — PASS | (C) After a new sign-up, the `pr_cme` note still says UNVERIFIED. |
| 3i-9 | EXECUTED — PASS | (C) "Master Admin" appears once. One `role_created` row per create. `tenant_owner` appears 0 times in the Team HTML. |
| 3i-10 | EXECUTED — PASS | (C) 13 providers checked: no blank cells in requirement evidence. |
| 3i-11 | EXECUTED — PASS | (C) Template preview: 4 Create. |
| 3i-12 | EXECUTED — PASS | (Me, fresh install) `[dry-run] would convert: 0, already encrypted: 0, failed: 0` exit=0; the real run also exit=0. |
| 3i-13 | EXECUTED — PASS | (Me, plus stage 3f2) http → `http://` with `hsts=false`; https → `https://` with `hsts=true`. |

### 3j

| # | Label | Evidence |
|---|---|---|
| 3j-1 | EXECUTED — PASS | (C) Changing only the role title updates only `role_title`; name, NPI and e-mail are unchanged after decryption. Unknown sub-paths return 404 and change nothing. |
| 3j-2 | EXECUTED — PASS | (C) Every message on the 3i-2 list has the right colour, including "Record created — required credentials…", which is green. |
| 3j-3 | EXECUTED — PASS | (C) `2fa_start` is refused with "Two-factor authentication is already on. Turn it off first…" (red). Secret unchanged. A `2fa_enabled` audit row exists. |
| 3j-4 | EXECUTED — PASS | (C) Template NPIs: 1234567893, 1679576722, 1245319599, 1003000126. All four are distinct and valid. Preview: 4 Create. |
| 3j-5 | EXECUTED — PASS | (C) "front desk review" is refused with "A role with that name already exists." |
| 3j-6 | EXECUTED — PASS | (Me) See §1. |

### Step 4 (runner B)

| # | Label | Evidence |
|---|---|---|
| 4-1 | EXECUTED — PASS | No "Other" option; PR is preselected. After approval, PR-only and FL-only tenants each show only their own state ticked. |
| 4-2 | EXECUTED — PASS | "Healthcare credentialing ruleset … HC2026.1". CMS-4208 and "commercial lines" appear 0 times. Tab is labelled "Providers". |
| **4-3** | **EXECUTED — FAIL (FL part)** | **PR tenant:** CE panel "7.5 hrs · last 36 months"; CME row "PR recertification cycle: 36 months". Matches. **FL tenant:** CE panel "4 hrs · last 24 months", but the CME row says "**Per licensing-board cycle**". The checklist requires the row to show the same cycle as the panel. Source: `app/src/ApplicationReadinessService.php:63`: `$pr ? 'PR recertification cycle: 36 months' : 'Per licensing-board cycle'`. There is also no NIPR, NPN or "I'd want this" on the page, and "CME — AMA PRA Category 1" is present. |
| 4-4 | EXECUTED — PASS | Group NPI `123` gives "Group NPI must be exactly 10 digits."; CLIA `40D1234567` gives "Entity profile saved." |
| 4-5 | EXECUTED — PASS | Logged-out view shows Provider, NPI and Specialty. Producer and NPN appear 0 times. |
| 4-6 | EXECUTED — PASS | "Credentialing Audit-Readiness Package", "(Provider)". |
| 4-7 | EXECUTED — PASS | Provider, case type, client group and payer are all `<select>` fields. |
| 4-8 | EXECUTED — PASS | No "Client call" option and no "Client name" field. |

**4-3 verbatim (FL tenant, `/holders/b7962f04-…`):**
```
CE panel:  CME / continuing education 4 hrs · last 24 months … Hours from Oct 2, 2024 to today are totalled.
CME row:   CME / CE evidence · Credentials  —  Per licensing-board cycle   (✕, with 4 h logged)
PR row:    CME / CE evidence  —  PR recertification cycle: 36 months
```

## 4. "Try to break" list

| # | Item | Label | Evidence |
|---|---|---|---|
| K1 | Single-field edit and POSTs to `/holders/{id}/…` | EXECUTED — PASS | (C) I compared the plain columns, MD5 of `npi_enc`/`email_enc` and `npi_bidx`, and the decrypted values. Editing role title in the UI changes only `role_title`. Raw POSTs that send only `role_title`, only `caqh_id`, only the CSRF token, or unknown fields change only what was sent. `/whatever`, `/x/y`, `/enrollment`, `/edit`, `/onboard`, `/%2e`, `/Edit`, `/whatever/` all return 404 and change nothing. Real handlers (`/payer`, `/ocs`, `/addresses`…) leave identity fields intact. *Also observed:* explicitly empty fields (`first_name=`, `npi=`, `email=`…) **are erased** with "Provider updated.". Edit has no required-field check, and create does. Reported as an observation, not a failure. |
| **K2** | Toast colours | **EXECUTED — FAIL (sweep)** | (C) Every item on the requested list is correct (see 3i-2 / 3j-2). The sweep of all flash messages found 4 mismatches, listed below the table. |
| K3 | Swap the authenticator while 2FA is active | EXECUTED — PASS | (C) `2fa_start` is refused. `2fa_confirm` is refused both with a code for a new secret and with the current code. MD5 of `totp_secret`/backup/`confirmed_at`/`last_counter` is identical. With two concurrent sessions, the second confirm is refused. `2fa_enabled` is audited, and audited again after disable and re-enable. |
| K4 | Unchanged CSV template | EXECUTED — PASS | (C) Preview: 4 Create, 0 Skip. Commit: "4 provider(s) imported." Audit row `{"created":4,"skipped":0,"errors":0}`. |
| **K5** | Duplicate role names | **EXECUTED — FAIL (sub-part)** | (C) Refused: lower case, upper case, leading/trailing spaces, trailing NBSP, accents, full-width characters, İ, zero-width space, `master admin`, `Manager`, replaying the same CSRF token, and an 8-session race (1 created, 7 refused). **Accepted: `Front  Desk Review` (double space) and `Front\tDesk Review` (tab), which look identical to the original on the Roles page.** Also accepted: `Front Desk Revıew` (dotless ı) and custom roles named `Admin`/`Viewer`/`Regular` (see §5). Cause: `RoleService.php:267` checks only `LOWER(name) = LOWER(?)`, with no whitespace normalisation. |

**K2 mismatches:**
- "Support request denied." (the owner's deny succeeded) is shown **red**.
- "0 provider(s) imported, 1 with errors." is shown **green**.
- "0 credential(s) imported, 1 row(s) skipped with errors. Deadlines will appear…" is shown **green**.
- "No active link with that organization for this provider." (a refusal) is shown **green**.
- Cause: `flx_flash_type()` (`app/bootstrap.php:35-38`) still guesses the colour from keywords. "denied" maps to error, and "errors" and "No active link" match nothing.
- The full sweep of ~45 messages with their classes is in runner C's notes. The bulk of them are correct.

## 5. Surprising observations (not in the checklist; nothing changed)

1. **Edit clears required identity fields without validation** (K1). Explicitly empty values erase name, NPI and e-mail. Edit also accepted the e-mail "not-an-email", which create rejects. A POST with only CSRF returns "Provider updated." and writes `provider_updated []`.
2. **Unsealed database download.** GET `/admin/backup/database` returns 200 `application/gzip` (a full dump) with no password re-confirmation, and System links to it as "Download database". The sealed version requires password and passphrase. (B)
3. **Custom roles can shadow built-in global roles.** Creating "Admin", "Viewer" or "Regular" produces slug `admin`/`viewer`/`regular`, and the built-in "Admin" disappears from the Roles list. I saw no privilege change, because global roles cannot be assigned inside a tenant. "Provider (self-service)" also appears twice in the pristine demo. (C)
4. **A password with 2FA abandoned clears the `acct|` counter.** A correct password followed by not completing 2FA reset `acct|email` from 11 to 0. That reopens the spread-attack window for anyone who knows the password but not the second factor. (B)
5. **A refused `2fa_confirm` with 2FA on renders an empty enrolment block.** It shows an empty setup key, `otpauth://…?secret=`, and a "Turn on two-factor" button, while the badge says "On". (C)
6. **One tenant's POST flushes other tenants' outbox.** A Coastal invite delivered a queued Bayamón e-mail. Invite toasts show the full link even when mail is enabled. (B, C)
7. **The installer writes `mail_from = 'no-reply@127.0.0.1:8121'`**, including the port, which is not a valid address. (B)
8. **The platform admin entered the demo org without a support grant** ("Opened the demo organization with full access"). Non-demo tenants are refused correctly. (C)
9. **The FL-only tenant still shows PR payers** (Triple-S, Plan Vital/ASES, MMM…) on the provider page. (B)
10. **Duplicates in the credential-type list:** "National Provider Identifier (NPI)" appears twice, and "Organization NPI (Type 2)" appears next to "Organizational NPI (Type 2)". (B)
11. **Off-by-one CE window:** with today 2026-10-01, "Hours from Oct 2, 2024/2023" starts one day after the same date. (B)
12. **Audit log wording:** the same entity is shown as both "Credential holders" and "Providers"; the Changes column shows raw keys and UUIDs; one entry reads "Ocs profile saved". (B)
13. **Spanish gaps:** the user menu ("Your profile / Organization settings / Dark mode / Sign out") stays in English. (B)
14. **Checklist mismatches:**
    - The "Document types" path in the checklist doesn't exist; built-in notes are under Compliance → Catalog (`/credentials/requirements`).
    - The doc quotes "Outbox flushed — N sent, 0 failed", but the actual text ends "failed/queued".
    - The script's `04_browser_checks_TODO.md` omits Step 3j.
    - The script cannot test stage 5 on a used installation, because it always unzips a fresh copy.
15. **Logs are clean:**
    - No 5xx responses.
    - No PHP warnings, notices, deprecations or fatals.
    - `error_log` has 0 rows on every instance.
    - About 1,150 GET requests were crawled across 8 roles (runner C).
    - `php -S` with a router script only logs static files, so status codes were checked client-side.

## 6. Harness and fixtures (test instances only)

- **Router.** `php -S` does not apply `.htaccess`. The browser runners used a router placed outside the app (`router16_{A,B,C}.php`) that mimics it: static files are served directly, `/app`, `/database`, `/cron`, `/tools`, `/tests` and `/docs` return 403, and everything else goes to `index.php`.
- **SQL writes:**
  - 3 rows in `addresses` (me, for 3h-5).
  - `users.role` for an unlinked portal login (A).
  - 1 row in `email_outbox` (B).
  - C made no SQL writes.
- **Instance config:** `allow_open_registration` was toggled on for sign-ups and then restored. B and C confirmed the file is identical afterwards.
- **Mail:** local SMTP sinks on 127.0.0.1 (B, C).
- **Databases:**
  - My instances: `flx_i16_http`, `flx_i16_https`, `flx_memtest16`.
  - Runners: `flx_qa16_a`, `flx_qa16_b`, `flx_qa16_c`.
  - Plus the databases the suites create themselves.

## Files

- `README.md`: this summary.
- `script_output/`: the script's own outputs (00–04) plus `driver_console.txt`.
- `logs/3j6_used_install_runtime_check.txt`: `runtime_check` on the used installation.
- `logs/installer_http_https_config.txt`: `app_url`/`hsts` per installer.
- `logs/3h5_addresses_before.tsv`, `logs/3h5_addresses_after.tsv`: address HEX values before and after.
- `logs/3h6_memory.txt`: memory test output.
- `screenshots/`: 90 screenshots. A_* = 3b–3d; B_* = 3g, 3h, 4; C_* = 3i, 3j, K1–K5.
