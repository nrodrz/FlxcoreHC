# FlxCoreHC v1.171.8 — independent validation results (Claude Code)

**Date:** 2026-10-01.

**Role:** runner and reporter only. I did not modify any app code, migrations, schema or tests.

**Bundle:** `claude_code_bundle_v1_171_8.zip`.
- Apart from the version number, the script is identical to v1.171.7's.
- The `CLAUDE_CODE_RUNTIME_CHECK.md` in the bundle is identical to the copy in the app zip.
- The only checklist change is the new Step 3l.

**Environment:**
- PHP 8.4.19 CLI (pdo_mysql, sodium, openssl)
- MariaDB 10.11.14, a throwaway local server
- Python 3.11.15
- Playwright + headless Chromium
- `php -S` as the web server

**Labels:** EXECUTED — PASS · EXECUTED — FAIL · REQUIRES BROWSER VALIDATION.

## Headline

| Area | Result |
|---|---|
| `claude_code_validate_v1_171_8.sh` | **exit 0** · **669 PASS lines** · "no FAIL/SKIP lines" · 7 crawls × **220 pages** · "STRAY FILES: PASS" |
| Compared with the attached `runtime_check_output_v1.171.8.txt` (another environment, PHP 8.4.21) | Same 669 PASS lines (normalised and compared with `diff`: no differences). |
| Step 3l (new), items 1–4 | **4 / 4 PASS** in real Chromium |
| "Try to break" list | **R2 and R4 PASS.** **R1 and R3 FAIL** on sub-parts outside 3l: role *create* accepts empty or 1-character names; platform-admin global roles have no duplicate check; provider/organization *create* accepts invisible or empty names. |
| Step 1 regression check | PASS: CSRF refused, 89 tables, 132 migrations, `mail_from` without port, `pr_cme` UNVERIFIED, `encrypt_existing_files --dry-run` exit 0 |
| Pristine copy of the app | Byte-identical after the run (505 files, SHA-256); `app/config.php` never created |

## 1. Validation script

```
$ DB_USER=root DB_PASS= bash claude_code_validate_v1_171_8.sh flxcorehc-v1_171_8.zip     → SCRIPT EXIT=0
02_exit_code.txt: runtime_check exit code: 0
03_summary.txt:   PASS lines: 669 | no FAIL/SKIP lines | app/config.php after run: No such file or directory
PASS — crawl as owner: pages=220 codes={200: 219, 302: 1}
PASS — crawl as manager: pages=220 codes={200: 218, 302: 1, 403: 1}
PASS — crawl as staff: pages=220 codes={200: 209, 403: 5, 302: 6}
PASS — crawl as coord: pages=220 codes={200: 209, 403: 5, 302: 6}
PASS — crawl as readonly: pages=220 codes={200: 194, 403: 20, 302: 6}
PASS — crawl as restricted: pages=220 codes={200: 211, 403: 6, 302: 2, 404: 1}
PASS — crawl as padmin: pages=220 codes={200: 218, 302: 2}
STRAY FILES: PASS (this run created no .notif-sweep-* in app/storage; 0 pre-existing)
```

**Note:** stage 2 prints a non-failing `⚠ WARNING — raw SQL on tenant tables found in the controller/view layer` listing three `index.php` lines.
- `index.php:2641` is a false positive: it only compares the literal confirmation phrase `'PERMANENTLY DELETE'`.
- `index.php:502` and `index.php:1379` are real raw SQL, and both filter by `tenant_id`.

## 2. Step 3l

| # | Label | Evidence |
|---|---|---|
| 3l-1 | EXECUTED — PASS | Created "Rename A" and "Rename B" (green). Renaming B to "rename  a" (two spaces) or to "Manager" is refused with "A role with that name already exists." (red toast). SQL name stays `Rename B`. Re-casing to "rename b" gives "Role updated." |
| 3l-2 | EXECUTED — PASS | On Bahia Clinical Laboratory, changing only the name gives "Provider updated."; `license_code` stays `clia` → `clia`, and the select shows "clia" selected before and after. |
| 3l-3 | EXECUTED — PASS | Setting first name to NBSP gives "First and last name are required." (red); SQL diff is empty. |
| 3l-4 | EXECUTED — PASS | Two real browser contexts. When A confirms after B has finished enrolment, the page goes to `/profile` with a red toast "Two-factor authentication is already on…". There is no setup key, no `otpauth://` link and no confirm form. A fresh sign-in with B's secret works. |

## 3. "Try to break" list

| # | Item | Label | Evidence |
|---|---|---|---|
| **R1** | Renaming or creating roles with duplicate names | **EXECUTED — FAIL (sub-parts beyond the list)** | **Every listed variant is refused**, on rename through the UI and through a raw POST, and on create, with SQL unchanged: double space, tab, NBSP, ZWSP, leading/trailing spaces, upper and lower case, dotless ı, Manager, Master Admin, Admin, Viewer, Provider (self-service), Read-only, Staff, Coordinator, Regular User, U+3000, U+2003, BOM. Valid renames still work (re-case, brand-new name, Staff→STAFF→Staff). **The failures:** (1) Create accepts an empty or 1-character name: `"​​"` → `Role "" created.`, `"x​"` → `Role "x" created.`, `"z​"` in the browser. In `RoleService.php:262-267` the 2–80 length check runs *before* `cleanName()`, while rename checks after cleaning and correctly refuses. (2) These look-alike names get through on both create and rename: Cyrillic `е` ("Renamе A"), soft hyphen U+00AD, word joiner U+2060, and full-width "Ｍａｎａｇｅｒ". (3) The platform admin's global roles (`/admin/roles`) have **no duplicate check**: second copies of "Manager", "manager", "Master Admin", "Admin" and "Viewer" were created, and renames to "Viewer" or "Regular User" were accepted. The tenant's Roles page then listed "Admin", "Master Admin", "Manager" and "Viewer" twice each. |
| R2 | Editing an organization that has a licence | EXECUTED — PASS | Bahia: UI rename, untouched save, a full-form raw POST of 25 fields, a partial POST, and a POST without `license_code` all keep `clia`. Individual Pedro Marin keeps `state_medical_license`. The select shows the stored value selected. |
| **R3** | Invisible names | **EXECUTED — FAIL (create)** | **Edit is correct for every listed character** (NBSP, ZWSP, BOM, combinations, ZWNJ/ZWJ, U+2060, U+180E): refused, SQL unchanged. **Create accepts them.** The validation in `HolderInput.php:68-76` only runs `if (!$creating)`, even though its comment says "create already enforces both". Examples: UI `/holders/new` with first=NBSP, last=ZWSP gives "Provider added and portal invite sent…". Org with org_name=ZWSP gives "Provider added.". Onboarding with NBSP/ZWSP gives "Record created…". **A raw `/holders` POST with first="" and last="" also gives "Provider added and portal invite sent…"** (first and last stored as NULL), and the same happens for an org with org_name="". The provider list then shows rows with blank names. **Also on edit:** U+00AD, U+3164 and U+2800 are accepted, because `HolderInput::blank()` does not cover them. |
| R4 | 2FA with a pending secret in another session | EXECUTED — PASS | Tried A's own code, a wrong code and B's code: all give the red "already on" message on `/profile`, with no form. MD5 of the stored secret is unchanged. B's secret works and A's does not. One `2fa_enabled` audit row. With 2FA off, a wrong code shows the red `.alert` "That code did not match…". |

The full verbatim output is in runner C's notes and the screenshots `C_r1_*`, `C_r3_*` and `C_l*`.

## 4. Surprising observations (nothing changed)

1. **A role update that sends only `name` removes every capability.** A raw `action=update` POST with no `capabilities[]` wiped all 8 capabilities of the built-in Staff role (8 → 0). The UI always sends capabilities, so normal use is not affected. Restored by SQL, disclosed in §5.
2. **Global custom roles appear editable to tenants.** `/organization/roles` shows "Edit" links for them, but a POST returns "Role not found." and nothing changes.
3. **"Licence held" options:** CLIA is listed by its raw code, "clia". "Business License" appears twice (`biz_license` and `business_license`).
4. **Saving an organization shows "Provider updated."** Every full-form save also re-encrypts `npi_enc` (same value).
5. **A plain reload of `/profile` after the "already on" message shows nothing.** The message is a flash shown once.
6. **Server health:**
   - No PHP warnings, notices or deprecations.
   - No 5xx responses.
   - `error_log` has 0 rows.
   - The php -S log only records static-file requests in router mode, so status codes were checked on the client side.

## 5. Harness and fixtures (test instances only)

**Router:** a test-only router outside the app, `router18_C.php`, which emulates the shipped `.htaccess` (php -S ignores it). Real files are served directly, `/app`, `/database`, `/cron`, `/tools`, `/tests` and `/docs` return 403, and everything else goes to `index.php`.

**Data left behind:**
- Probe roles and holders (empty, invisible and look-alike names) stay in `flx_qa18_c` as evidence.
- The 9 test global roles were deleted through the app.

**SQL write:** one only, re-inserting the 8 Staff capabilities removed in observation 1.

**Databases:** `flx_qa18_c` and `flx_i18_http`, plus the databases the test suites create themselves.

## Files

- `README.md` — this file.
- `script_output/` — the script's own outputs 00–04, plus `driver_console.txt`.
- `logs/installer_config_http.txt` — installer-written `app_url`, `hsts` and `mail_from`.
- `screenshots/` — 27 screenshots: `C_l*` for 3l, `C_r*` for R1–R4.
