# FlxCoreHC v1.171.4 ("Production Candidate"): Independent Runtime Report

**Runner:** Claude Code, acting as independent runner and reporter.
- I did not edit any application code, tests, SQL or docs, and I did not fix anything to make it pass.
- I propose no code changes.
- Claude.ai's own sandbox result (589 PASS) is **not** part of this report. Everything below is what I ran.

**Date:** 2026-09-30

**Environment**

| Item | Value |
|---|---|
| PHP | 8.4.19 (cli, NTS) with pdo_mysql, sodium and openssl loaded |
| Database | MariaDB 10.11.14 (`10.11.14-MariaDB-0ubuntu0.24.04.1`), local, root over 127.0.0.1 |
| mysql client | 15.1 Distrib 10.11.14-MariaDB |
| python3 | 3.11.15 |
| Browser automation | Playwright with Chromium (headless) |
| Web server | `php -S` (PHP built-in development server) |

Labels used: **EXECUTED — PASS**, **EXECUTED — FAIL**, **STATICALLY REVIEWED**, **REQUIRES EXTERNAL RUNTIME VALIDATION**, **REQUIRES BROWSER VALIDATION**.

---

## 0. Setup

| Check | Label | Evidence |
|---|---|---|
| Zip contains no `app/config.php` | EXECUTED — PASS | `unzip -l flxcorehc-v1_171_4.zip \| grep -c app/config.php` returned `0`. |
| Pristine tree, `app/config.php` stays absent | EXECUTED — PASS | Unzipped to an empty folder `v1714/` and took a SHA-256 of all 494 files before Step 2, after Step 2 and at the end. No existing file changed, and `app/config.php` never appeared. Two new empty files did appear (see §6-1). |
| `FLX_CONFIG` | Set | `/tmp/flx_base_config.php` contains exactly `<?php return ['db_host'=>'127.0.0.1','db_port'=>3306,'db_user'=>'root','db_pass'=>''];` |

**How the work was split:**
- **Step 1** (fresh install through the web installer) cannot leave `app/config.php` absent, because the installer always writes it. So I ran it in a separate copy, `v1714_install/`, against DB `flx_install`.
- **Browser checks** ran on two more separate copies, each with its own database and port: `qa_A` (`flx_qa_a`, port 8111) and `qa_B` (`flx_qa_b`, port 8121).
- **The pristine tree `v1714/`** was used only for Step 2 and for the memory tool.

**Test harness disclosure:**
- The app needs Apache's `.htaccess` rewrite rules, which `php -S` does not read. So the browser checks used a small router script placed *outside* the app folder (`scratchpad/router_A.php` and `router_B.php`). It mirrors the shipped `.htaccess`: it serves real files, returns 403 for `app/`, `database/`, `tools/`, `tests/`, `cron/` and `docs/`, and sends everything else to `index.php`.
- It was started as `php -S … -t <copy> router_X.php`. With it, `/app/config.php` returns 403.
- For its first ~10 minutes runner B started it with the wrong document root, so CSS/JS 404'd. HTTP-level results from that window are not affected.

---

## 1. Step 1: Fresh install (`docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` Step 1)

| Check | Label | Evidence |
|---|---|---|
| Installer refuses a POST **without** `_csrf` | EXECUTED — PASS | HTTP 200 with "Your session expired or this request did not come from the installer page". No `app/config.php` was written and 0 tables were created. |
| Installer refuses a POST with an **altered** `_csrf` (valid token plus one extra character) | EXECUTED — PASS | Same message; no config written; 0 tables. |
| The form has a hidden `_csrf` field | EXECUTED — PASS | `name="_csrf" value="658a0135…"`, 64 hex characters. |
| Fresh install on an empty DB | EXECUTED — PASS | "✓ Installation complete — created 89 tables and your administrator account." No "Migration failed" message. |
| `tenants.group_npi` and `tenants.clia_number` | EXECUTED — PASS | One row each: `group_npi varchar(10)` and `clia_number varchar(20)`. |
| Migrations up to 132 recorded in `schema_migrations` | EXECUTED — PASS | 132 rows. 124 through 132 are each present, all applied at `2026-09-30 20:37:08`. |

**Notes:**
- `database/migrations/` holds 133 files. Two of them are not in `schema_migrations`:
  - `002_ma_cms.sql`, which is not referenced by `manifest.php` or `install.php`;
  - `098_encrypt_existing.php`, which is a PHP script, not SQL.
- The installer now also requires a key-backup passphrase (`key_passphrase` / `key_passphrase2`, at least 12 characters). My first valid-token POST without it was refused with "Use a backup passphrase of at least 12 characters." This is not listed in Step 1 of the checklist.

---

## 2. Step 2: Automated suite

### 2a. `bash tools/runtime_check.sh`

Run from the pristine tree with `FLX_CONFIG=/tmp/flx_base_config.php` and `app/config.php` absent. **Exit 1. Wall time 1m09s.**

| Stage | Label | Evidence |
|---|---|---|
| 1 Syntax (`php -l`) | EXECUTED — PASS | `SYNTAX: PASS (all files)` |
| 2 Isolation | EXECUTED — PASS | See the log. |
| 3 Agency tests | EXECUTED — PASS | ALL PASS |
| 3a Healthcare-only | EXECUTED — PASS | ALL PASS |
| 3b Purge and security | EXECUTED — PASS | ALL PASS |
| 3c Provider-scope static guards | EXECUTED — PASS | ALL PASS |
| 3d Authorization suite | EXECUTED — PASS | ALL PASS |
| 3e Migration scenarios A–D | EXECUTED — PASS | ALL PASS |
| **3f HTTP smoke** | **EXECUTED — FAIL** | `SOME FAILED`, then `HTTP SMOKE: FAIL/SKIP (exit 1)`. 30 FAIL lines, verbatim below. |
| 3g Security suite v1.171.0 | EXECUTED — PASS | ALL PASS |
| 4 `harness.sh` | Not run | "harness.sh not present in this checkout (dev-only)" |
| RESULT | EXECUTED — FAIL | "One or more stages FAILED" |

Totals in the log: 559 `PASS` lines and 30 `FAIL` lines. The complete output is in the attached **`runtime_check_output_v1.171.4.txt`** (642 lines) and is reproduced in Appendix A.

**Stage 3f verbatim (FAIL lines and the crawl lines):**
```
FAIL — coordinator cannot verify a document (documents.verify) status 302
FAIL — staff credential page offers verification status 302
FAIL — restricted user cannot POST-edit P2 credential status 302
FAIL — owner dashboard shows both providers/expiries
FAIL — custom portal role cannot edit another provider credential status 302
FAIL — custom portal role cannot read another provider document status 302
FAIL — custom portal role is redirected from the dashboard to /me status 302 install.php
FAIL — S2 victim can use the app before suspension status 302
FAIL — S2 suspending a user ends their live session on the very next request status 302 install.php
FAIL — S2 a password reset ends the user's other sessions status 302
FAIL — S2 a role change applies immediately (manager -> read-only loses /team) before 302 after 302
FAIL — B4 restricted /notifications shows P1 notice and hides P2 notice status 302
FAIL — B1 editing a provider as PA keeps type=pa and saves specialty / CAQH / PTAN status 302 row physician|NULL|NULL|NULL
FAIL — S3 platform admin WITHOUT 2FA is sent to their profile, not the console status 302 install.php
FAIL — S3 once 2FA is on, the console opens status 302
FAIL — S3/K System page renders with the encryption-key backup panel + reminder (no PHP warnings) status 302
FAIL — K banner reminds the platform admin to back up the keys status 302
FAIL — K key backup downloads as an attachment, hides the keys, and decrypts back to the installed keys status 302
FAIL — K after confirming, the reminder banner is gone status 302
FAIL — D6 sealed dump downloads as an attachment with the FLXDUMP1 header status 302
FAIL — E HTTP upload stores the file sealed (header present, no plaintext on disk)
FAIL — E HTTP download returns the original bytes status=0
FAIL — D-5 PR tenant: CE panel totals the last 36 months status=302
FAIL — D-5 non-PR tenant: CE panel totals the last 24 months; PR-only readiness items absent status=302 24m=False ocs_ctx=''
FAIL — B-8 staff menu does not list Groups / Locations / Applications / Regulators status=302 found=[]
FAIL — B-8 owner menu still lists them status=302
FAIL — S7 manager without 2FA is sent to /profile from / /billing /holders /team when the policy is on {'/': (302, 'install.php'), '/billing': (302, 'install.php'), '/holders': (302, 'install.php'), '/team': (302, 'install.php')}
FAIL — S7 the profile page (where they enrol) stays reachable status=302
FAIL — S7 staff (no privileged capability) is not gated status=302
FAIL — S7 policy off again: manager reaches the dashboard status=302
PASS — crawl as owner: pages=1 codes={302: 1}
PASS — crawl as manager: pages=1 codes={302: 1}
PASS — crawl as staff: pages=1 codes={302: 1}
PASS — crawl as coord: pages=1 codes={302: 1}
PASS — crawl as readonly: pages=1 codes={302: 1}
PASS — crawl as restricted: pages=1 codes={302: 1}
PASS — crawl as padmin: pages=1 codes={302: 1}
SOME FAILED
HTTP SMOKE: FAIL/SKIP (exit 1) — send the exact output to Claude; do not edit code or tests.
```

**What I observed (not interpreted as a fix):**
- `index.php:5` reads `if (!is_file(__DIR__ . '/app/config.php') && is_file(__DIR__ . '/install.php')) { header('Location: install.php'); …`.
- `app/bootstrap.php:38-42` (`flx_config_path()`) does honour `FLX_CONFIG`.
- Under the prescribed setup (`FLX_CONFIG` set, `app/config.php` absent), every web request therefore gets 302 → `install.php`.
- The stage also prints **PASS** lines that exercised nothing: all 7 crawls report `pages=1 codes={302: 1}`, and the "restricted … hides P2" checks pass on 302.

### 2b. Supplementary run of stage 3f only (not part of the prescribed configuration; labelled separately)

`bash tools/http_smoke/run.sh` in `v1714_install/`, where `app/config.php` exists, with `FLX_CONFIG=/tmp/flx_base_config.php`.

| Check | Label | Evidence |
|---|---|---|
| 3f with `app/config.php` present | EXECUTED — PASS | Exit 0, 53 PASS lines, `ALL PASS`. Crawls: owner 220 pages `{200: 219, 302: 1}`; manager `{200: 218, 302: 1, 403: 1}`; staff and coord `{200: 209, 403: 6, 302: 4, 404: 1}`; readonly `{200: 199, 403: 16, 302: 4, 404: 1}`; restricted `{200: 211, 403: 6, 302: 2, 404: 1}`; padmin `{200: 218, 302: 2}`. Full output: `http_smoke_supplementary_v1.171.4.txt`. |

### 2c. `php database/encrypt_existing_addresses.php --dry-run`

| Invocation | Label | Evidence (verbatim) |
|---|---|---|
| Pristine tree with `FLX_CONFIG=/tmp/flx_base_config.php` (as instructed) | EXECUTED — FAIL | `Crypto keys not configured; aborting.`, exit 1. The base config has no crypto keys or `db_name`. |
| Installed instance (`v1714_install`, its own `app/config.php`, `FLX_CONFIG` unset) | EXECUTED — PASS | `[dry-run] would encrypt: 0 column value(s) in 0 address row(s)`, exit 0. |

---

## 3. Step 3: Browser and DB checks

**Checklist Step 3 (upgrade from v1.170.6):** REQUIRES EXTERNAL RUNTIME VALIDATION. No v1.170.6 database with data was available. Migration scenarios B–D in stage 3e pass.

**Summary of the 47 numbered checks:** 40 EXECUTED — PASS, 6 EXECUTED — FAIL (3d-2, 3d-6, 3e-2, 3e-3, 3g-1, 3h-3) and 1 STATICALLY REVIEWED (3g-8).

**Demo login note:** Coastal demo-admin's password is `coastal-demo-2026`; the other demo users use `FlxDemo2026!`.

### Step 3b (v1.170.8), instance qa_A

| Check | Label | Evidence |
|---|---|---|
| 3b-1 Manager cannot grant Master Admin | EXECUTED — PASS | POST /team with role changed to `tenant_owner`, with add, and with invite, each refused: "You can only assign roles that do not exceed your own permissions (Master Admin roles: Master Admin only)." `master_admin` gives "That role does not exist in this organization." `users` is unchanged. The wording differs from the checklist's "Only a Master Admin can…". |
| 3b-2 Staff limited to assigned providers | EXECUTED — PASS | Staff assigned only to Maria Santos. `/credentials` shows only her 6 credentials. Another provider's credential and its edit URL → 404 "Not found." `/tasks` and `/calendar` show only Maria. `/reports/credentials/export` has 6 rows, all Maria. |
| 3b-3 Staff opens a provider page | EXECUTED — PASS | `/holders/<Maria>` 200; another provider → 404. |
| 3b-4 2FA-disable brute force | EXECUTED — PASS | Attempts 1–8: "Enter a valid code to turn two-factor off."; attempt 9: "Too many attempts. Try again in 15 minutes." A 10th attempt with the *correct* code was also refused and `totp_enabled` stayed 1. Run as coordinator (see §6-8). |
| 3b-5 Reporting year | EXECUTED — PASS | `/reports` and `/reports/audit/<id>` show "Reporting year 2026". |

### Step 3c (v1.170.9), instance qa_A

| Check | Label | Evidence |
|---|---|---|
| 3c-1 Need-to-know | EXECUTED — PASS (some parts not observable) | Staff dashboard: "Showing your assigned providers only.", 1 provider, 3 expiring, forecast Oct 3, work queue shows only Maria's items, the 30-day tile shows 2 documents (matches SQL; owner sees 19). No other names appear. Cases KPIs: staff 1/1, owner 3/3. Another provider's credential edit URL → 404 "Not found."; their document → 404 "Document not found.". Assigning Pedro shows him immediately; unassigning removes him (404). **Not directly observable:** staff gets 403 on `/groups` and `/locations` (by design, see 3e-4). Checked instead with a Manager set to assigned-only: group "Grupo Medico del Norte" shows 1 member, Clinica Bayamon 0, Caguas 1, San Juan 0 (owner sees 3/3 and 5/4). The screening KPI is computed but not rendered. The applications page has no KPI tiles; the Manager stand-in sees only Maria's application. |
| 3c-2 Coordinator has no verify controls | EXECUTED — PASS | No "Record verification" or verify/clear forms. A direct POST to `/credentials/<id>/verify` → 403 "Forbidden — your role does not permit this action." |
| 3c-3 Custom portal role | EXECUTED — PASS | Role "QA Portal Viewer" (portal-only, `documents.view` only). Sign-in → `/me`, and `/` always redirects to `/me`. Another provider's document → 404. `/credentials/new` lists only the linked provider; a tampered POST → 403. Portal login with no linked provider → 403 "Your login is not linked to a provider record — ask your administrator.", with no loop. *That unlinked login was created by one SQL fixture write (`UPDATE users SET holder_id=NULL …`), because the UI refuses to create one.* |
| 3c-4 iCal feed | EXECUTED — PASS | 200 `text/calendar` with 6 events, all "— Maria Santos". After suspending the user, the same URL → 404 "Not found." |
| 3c-5 Audit events | EXECUTED — PASS | `user_role_changed {"from":"read_only","to":"agent"}`, `owner_level_role_granted {"from":"agent","to":"tenant_owner"}`, `share_link_revoked {"link_id":"9f62edf8-…"}`. All visible in `/audit`. |

### Step 3d (v1.171.0), instance qa_A

| Check | Label | Evidence |
|---|---|---|
| 3d-1 Role ceiling | EXECUTED — PASS | A Manager creating a role with `pii.view` → "You cannot grant capabilities you do not hold yourself: pii.view." and no role is created. A Manager editing built-in Staff or Read-only → "Only an owner can change a built-in role." SQL confirms capabilities unchanged. The wording differs from the checklist's "cannot exceed your own permissions". |
| **3d-2 Session revalidation** | **EXECUTED — FAIL** | The part that fails is **"with a flash"**. After the owner resets the password or suspends the user, the staff browser's next navigation lands on `/login`, which is correct, but **no message is shown there**. The flash appears only after the next successful sign-in. Verbatim below. |
| 3d-3 Support access | EXECUTED — PASS | Before 2FA, `/admin/system` redirected to `/profile`. After enrolment, with no support grant, resetting the password of a non-demo tenant owner was refused: "Resetting a user's password needs an approved support session for this organization. Request support access and wait for the organization's Master Admin to approve it (each approval is a 4-hour window)." The password hash is unchanged. |
| 3d-4 Provider edit keeps fields | EXECUTED — PASS | Jorge Vega (PA): specialty, CAQH and PTAN set; then only `role_title` edited → "Provider updated.". SQL shows `pa`, specialty, CAQH and PTAN unchanged. Maria Santos (NP) → `np` unchanged. |
| 3d-5 Key backup | EXECUTED — PASS | A short passphrase is refused. A valid one downloads `attachment; filename="flxcorehc-keys-c152a2f697b9.flxkeys"`. The banner stays until "I stored it", then disappears. `restore_keys.php` prints the keys (verbatim below). The wrong passphrase gives "Could not open the backup: wrong passphrase or the file is damaged." with exit 1. The installer's backup file decrypts to the same keys. |
| **3d-6 Encrypted documents** | **EXECUTED — FAIL** | The failing part is the legacy script run **without** `--dry-run`. The upload works: the download is byte-identical, and on disk the 658-byte file starts `FLXENC1` with no `%PDF` or readable text; all 73 files start `FLXENC1`. But `encrypt_existing_files.php` fails on the shipped placeholder `app/storage/docs/.keep` (verbatim below). **I reproduced this independently on `v1714_install`, a fresh install with zero documents:** `--dry-run` reports `would convert: 1`, and the real run gives `failed: 1`, exit 2. |

### Step 3e (v1.171.1), instance qa_B

| Check | Label | Evidence |
|---|---|---|
| 3e-1 NPI check digit | EXECUTED — PASS | Provider with 1234567890 → "NPI is not a valid NPI (check digit does not match). Re-check the number." 1234567893 is accepted. The Group NPI field refuses 1234567890. CSV import flags the bad line: "1 provider(s) imported, 1 with errors." |
| **3e-2 PR jurisdiction** | **EXECUTED — FAIL** | PR: CE "last 36 months" and OCS, Buena Conducta and ASUME are present. FL only: "last 24 months" and Buena Conducta, ASUME and the OCS readiness item are gone. **The failing part:** the **OCS/SICRO panel stays under FL-only** for a provider that already has an OCS record (Ana Rivera). Verbatim below. |
| **3e-3 PR regulators** | **EXECUTED — FAIL** | `/regulators` lists "Florida Board of Medicine" and "Junta de Licenciamiento y Disciplina Médica (JLDM)". **"Junta Examinadora de Enfermería" is not listed.** The row exists in the DB with category `licensing`, but `RegulatorRepository::CATEGORIES` contains payer, government, regulation, accreditation and state_board, with no `licensing`. I verified this with SQL. The item "PR nursing license" exists on the board's detail page (reached by direct URL). "PR Board of Medical Examiners" appears 0 times. |
| 3e-4 Staff menu | EXECUTED — PASS | The staff menu has no Groups, Locations, Applications or Regulators. `/groups` and `/locations` → 403; `/applications` and `/regulators` → 302 to `/`. |

### Step 3f (v1.171.2), instance qa_B

| Check | Label | Evidence |
|---|---|---|
| 3f-1 SMTP | EXECUTED — PASS | Host 127.0.0.1 → "SMTP host must be a public mail server (private and internal addresses are not allowed)." Port 22 → "SMTP port must be one of 25, 465, 587, 2525." smtp.gmail.com:587 saves. Send-test failures show one generic message: "Test failed: could not connect to the SMTP server (check host, port and security mode)". |
| 3f-2 2FA policy | EXECUTED — PASS | With the policy on, the Manager and a custom "Manage team" role are sent from every page to `/profile` with "Your organization requires two-factor authentication. Please set it up to continue." Staff and coordinator are not affected (200). |
| 3f-3 TOTP | EXECUTED — PASS | A code is accepted once. The same code reused in the same 30-second window → "That code is not valid…". The next code is accepted. The status chip reads "On" after enrolment. |
| 3f-4 Wording | EXECUTED — PASS | "Provider updated."; the audit package shows malpractice authority "insurer". |

### Step 3g (v1.171.3)

| Check | Label | Evidence |
|---|---|---|
| **3g-1 Lockout exemption** (qa_B) | **EXECUTED — FAIL** | The owner signed in earlier from 127.0.0.1. 13 wrong passwords from 127.0.0.2: attempts 1–8 "Email or password is incorrect.", 9–13 "Too many sign-in attempts…". The owner from 127.0.0.1 then signed in normally, which passes. **The failing part:** the checklist's parenthetical "(A never-seen address is still throttled.)". After 13 wrong passwords from one address (127.0.0.7), the correct password from never-seen 127.0.0.8 was **accepted, not throttled**. A never-seen address was throttled only when the failures were spread over two addresses. Client IP is taken only from `REMOTE_ADDR` (`AuthService.php:243`); addresses were simulated by binding the client to 127.0.0.x. |
| 3g-2 Invite link | EXECUTED — PASS | The link token is 40 characters; DB `invite_token` is 64 hex characters and differs from it. The link works logged out, and the new account can sign in. |
| 3g-3 Installer CSRF | EXECUTED — PASS | See §1: missing and altered `_csrf` both refused, with nothing written. |
| 3g-4 No mail on GET | EXECUTED — PASS | The queued fixture row stays `queued` (0 attempts) after GET /admin, /admin/tenants, /admin/system, /compliance and /. `php cron/send_reminders.php` → "reminders: 0 queued, 1 delivered, 0 failed/queued". *But see §6-5: GET `/admin/email` does send.* |
| 3g-5 Address encryption | EXECUTED — PASS | The Home address displays normally. SQL: `line1`, `line2`, `phone` NULL; `line1_enc` 59 B, `line2_enc` 47 B, `phone_enc` 53 B, all binary. Dry-run: "would encrypt: 0 column value(s) in 0 address row(s)". |
| 3g-6 Sealed backup | EXECUTED — PASS | `flxcred-db-2026-09-30-205559.sql.gz.flxenc` (183,698 B, header `FLXDUMP1`); `gunzip -t` on it says "not in gzip format". `decrypt_dump.php` → "Decrypted to …out.sql.gz (183641 bytes)" and `gunzip -t` exits 0. The wrong passphrase gives exit 1 and no output file. A wrong account password, a short passphrase and mismatched passphrases are each refused with a specific message. |
| 3g-7 Cases and audit wording | EXECUTED — PASS | "Rivera, Ana · Initial credentialing · Open"; audit shows "Create"/"Update" and "Credentialing cases" / "Addresses", with hover "ID <uuid>". |
| 3g-8 Calendar feed shows https | STATICALLY REVIEWED | On this dev/http install the page shows `https://127.0.0.1:8121/ical/….ics`. `calendar/subscribe.php` upgrades `http://` to `https://` when `app_env !== 'dev'`, and `install.php:190` always writes `app_url => 'https://'.HTTP_HOST`. Not executed on a real production install. |
| 3g-9 HTTP smoke leaves `app/config.php` untouched | EXECUTED — PASS | In the §2b run, `app/config.php` had the same SHA-256 before and after (`sha256sum -c` → OK), the same size (1121) and mtime (1790800626), and no renamed copy appeared. In the pristine tree it never existed. |

### Step 3h (v1.171.4)

| Check | Label | Evidence |
|---|---|---|
| 3h-1 Verification wording | EXECUTED — PASS | Provider page, `/help/verification` (EN) and the credential form show "…It does not independently perform or certify primary-source verification." In Spanish: "Registros de verificación … No realiza ni certifica por sí mismo la verificación de fuente primaria…". |
| 3h-2 No "Fully compliant" | EXECUTED — PASS | Dashboard legend "L5 · All tracked requirements current"; compliance KPI "Providers with all tracked requirements current"; empty state "No providers yet…"; portal "✓ All your tracked requirements are current". "fully compliant" appears 0 times. |
| **3h-3 PR wording** | **EXECUTED — FAIL** | The drug-test item passes: "…Applicability UNVERIFIED — confirm with your licensing board…". **Failing part (a):** Regulators does not list "Junta Examinadora de Enfermería" (same cause as 3e-3). **Failing part (b):** the note on "PR Continuing Education — physicians" still states 10 h / 6 h and has no UNVERIFIED. **I verified (b) by SQL on all three DBs, including `flx_install`, a plain fresh install with one tenant and no demo:** `pr_cme` notes contain "10 hours" = 1 and "UNVERIFIED" = 0, even though `132_pr_regulatory_wording.sql` is recorded as applied. `AuthService.php:159` (`seedTemplatesFor`) re-runs `126_pr_seed_corrections.sql` whenever a tenant is created. Line 19 of that file restores the old `pr_cme` notes (`WHERE tenant_id IS NULL`). |
| 3h-4 Invite reuse | EXECUTED — PASS | Second use → 404 "Invitation not found — This invitation link is invalid or has expired…". |
| 3h-5 Address back-fill | EXECUTED — PASS | See §4.1: pre-existing ciphertext is byte-identical and all 9 values render in the UI. |
| 3h-6 Memory check | EXECUTED — PASS | See §4.2: both runs "result: OK (completed within memory_limit)". |

### Step 4 (browser), instance qa_B

| Check | Label | Evidence |
|---|---|---|
| 4-1 Sign-up | EXECUTED — PASS | With `allow_open_registration=true` set in the qa_B instance config (restored afterwards): no "Other" option; PR is preselected; after approval, Operating jurisdictions shows only [PR] ticked. |
| 4-2 Compliance | EXECUTED — PASS | "…ruleset HC2026.1" appears (as a hint line under the heading, not in a page footer). No CMS-4208 or "FL commercial lines". The tab reads "Providers". |
| 4-3 Provider page | EXECUTED — PASS | No NIPR, "I'd want this" or NPN. "CME — AMA PRA Category 1" is offered. Adding 7.5 h changed "0 hrs · last 36 months" to "7.5 hrs · last 36 months". The tenant is PR, so the window is 36 months, not the checklist's "24-month"; 24 months was seen under FL in 3e-2. |
| 4-4 Entity compliance | EXECUTED — PASS | All 7 fields are present. Group NPI `123` → "Group NPI must be exactly 10 digits."; CLIA `40D1234567` → "Entity profile saved." |
| 4-5 Share link | EXECUTED — PASS | Logged out: "PROVIDER Nico QaNpi · NPI 1234567893 · SPECIALTY Internal Medicine". No Producer or NPN. Demo providers have no specialty, so a fixture provider was used. |
| 4-6 Audit-Readiness package | EXECUTED — PASS | "Credentialing Audit-Readiness Package"; "Ana Rivera (Provider) · … · Reporting year 2026". |
| 4-7 New case | EXECUTED — PASS | Provider, client group and payer are all `<select>`. |
| 4-8 Calendar event | EXECUTED — PASS | Types are Task, Appointment and Note. No "Client call" or "Client name". |

### Verbatim output for the Step 3 checks that did not pass

**3d-2** (after the password reset; identical after suspension):
```
at http://127.0.0.1:8111/login
after click url http://127.0.0.1:8111/login toasts []
body: CO Coastal Credentialing Partners Healthcare Compliance Intelligence Platform Email Password Sign in
```
curl: `GET /credentials` → `302 /login`; `/login` 200 with no flash. After signing in again, the dashboard toast is `['You have been signed out. Please sign in again.']`.

**3d-6** (qa_A), with the same result reproduced on v1714_install:
```
$ php database/encrypt_existing_files.php --dry-run
[dry-run] would convert: 1, already encrypted: 73, failed: 0
exit=0
$ php database/encrypt_existing_files.php
FAILED: …/qa_A/app/storage/docs/.keep
converted: 0, already encrypted: 73, failed: 1
exit=2
```
```
# v1714_install (fresh, zero documents)
[dry-run] would convert: 1, already encrypted: 0, failed: 0      exit=0
FAILED: …/v1714_install/app/storage/docs/.keep
converted: 0, already encrypted: 0, failed: 1                    exit=2
```

**3d-5 `restore_keys.php`** (PASS; shown because the check asked for it; keys cut to 8 characters):
```
Backup passphrase: Add these to app/config.php:
    'crypto_data_key'  => 'fffea80d…',
    'crypto_index_key' => 'dade57df…',
Key fingerprint: c152a2f697b9
```

**3e-2** (Coastal switched to FL only, Ana Rivera page):
```
…Save OCS / SICRO centralized credentialing (Ley 73-2023 — compulsory) Attested attested 2026-06-02 · expires 2027-06-02 Update SICRO status…
```
Observed code: `holders/show.php:887` shows the panel when `in_array('PR', tenantStates) || !empty($enroll['ocs'])`.

**3e-3 / 3h-3(a)** (`/regulators`, State board section):
```
State board Name Jurisdiction Status Florida Board of Medicine FL active Junta de Licenciamiento y Disciplina Médica (JLDM) JLDM PR active Add a regulator
```
SQL: `SELECT name, category, jurisdiction FROM regulators WHERE name LIKE 'Junta Examinadora%'` returns rows such as `Junta Examinadora de Enfermería | licensing | PR` (14 boards × 7 tenants in flx_qa_b).

**3h-3(b)** (`/credentials/requirements`):
```
PR Continuing Education — physicians (triennial) JLDM recertification requires 60 continuing-education hours per 3-year cycle, including 10 hours in disease prevention/health promotion and 6 hours in bioethics and professionalism. Track completion against the license cycle (36-month window).
```
```
== flx_install   pr_cme  has_10h=1  has_unverified=0  tenants=1
== flx_qa_b      pr_cme  has_10h=1  has_unverified=0  tenants=7
== flx_qa_a      pr_cme  has_10h=1  has_unverified=0  tenants=7
```

**3g-1:**
- 13 wrong passwords from 127.0.0.7: attempts 1–8 "Email or password is incorrect.", 9–13 "Too many sign-in attempts. Try again in 15 minutes."
- The correct password from never-seen 127.0.0.8 → `/login/verify` (accepted, no throttle).
- Control: with failures spread over .4 and .5, never-seen .6 got "Too many sign-in attempts. Try again in 15 minutes."

---

## 4. Specific items requested by the reviewer (numbers only)

### 4.1 Address back-fill on partially migrated rows (3h-5)

**Setup:**
- Instance `v1714_install` (DB `flx_install`), provider `8c146baf…` (physician, created by `tools/seed_demo_healthcare.php`).
- Three rows inserted by raw SQL. Ciphertext was produced with the app's own `Crypto::encrypt($v, "<tenant>|addresses|<col>_enc")`, using the same associated data the repository uses. The fixture script is in the scratchpad, outside the app.

**Commands and output:**
- `php database/encrypt_existing_addresses.php --dry-run` → `[dry-run] would encrypt: 4 column value(s) in 2 address row(s)`
- `php database/encrypt_existing_addresses.php` → `encrypted: 4 column value(s) in 2 address row(s)`, exit 0
- A second run → `encrypted: 0 column value(s) in 0 address row(s)`

**BEFORE**

| row | line1 | line2 | phone | HEX(line1_enc) | HEX(line2_enc) | HEX(phone_enc) |
|---|---|---|---|---|---|---|
| R1 all-legacy | `100 Calle Legacy` | `Apt 1L` | `787-555-0101` | NULL | NULL | NULL |
| R2 line1-legacy | `200 Calle Parcial` | NULL | NULL | NULL | `02F0383643AC792EB598032F5B018509C2DA8C0E1BFADF1B4440F77CBB96DBB52AE24467DA6AABEE6AF946869B1C58` | `021C6CA98EFEB2DDDB66E6A86E84AB5AA911C78D8AE6E9775E6390B0E15142F58934285BAC3A8A9BEBC46AEAB2A47011C091A5DBAE` |
| R3 migrated | NULL | NULL | NULL | `0293397594A896EAF6FA2EC5D9E726FD47A6FD378D761CAA398E1F01EFF53BF7903625601AB32A0DBEA768DCADC1F321F53E6CC70CBB07D38EAF` | `024AC10783946364589DD52408995E638A48D841460971DFB6889CADE7A068B1C064AC23AC3BF216F3D52A1475D7FA` | `025A86F4CB646D2C9723B1BF0DE29062599C63112675607D97BC52623097BACD6C6D538D65823DCF584D06D45951CF9818A2EE70DD` |

**AFTER**

| row | line1 | line2 | phone | HEX(line1_enc) | HEX(line2_enc) | HEX(phone_enc) |
|---|---|---|---|---|---|---|
| R1 all-legacy | NULL | NULL | NULL | `022288CC0480738C65AADBA32CDCA902FC522A4F51CB9D1648D3E68E6BB7E9C6056888B44BE0EAE757ADFF8FAE9FA6A8888AD4ED80F22C9B7C` (new) | `0258509450E0A62E0C0FD77CD6BB7CAC6AA09F6F2542EC16A6EDDCB9A2F11D7C34653CE5BFC66211EA93655CBA768F` (new) | `02B02CF4FECAA52478397969DC62E1E90026EF9AFC43C2C0333994DCA31C4DE88818AC84625FD9D93EC852E52984B76A7775020860` (new) |
| R2 line1-legacy | NULL | NULL | NULL | `02B06725D39BEC5D41D02758143703582DA351E63D5D541D5727811B15C45D569E46B99ED4FE1B8CE003A711708F65B92F07A8CEEC1A6BAE78FE` (new) | `02F0383643AC792EB598032F5B018509C2DA8C0E1BFADF1B4440F77CBB96DBB52AE24467DA6AABEE6AF946869B1C58` (**identical**) | `021C6CA98EFEB2DDDB66E6A86E84AB5AA911C78D8AE6E9775E6390B0E15142F58934285BAC3A8A9BEBC46AEAB2A47011C091A5DBAE` (**identical**) |
| R3 migrated | NULL | NULL | NULL | `0293397594…07D38EAF` (**identical**) | `024AC10783…1475D7FA` (**identical**) | `025A86F4CB…F9818A2EE70DD` (**identical**) |

**UI read-back:** signed in as the owner, `GET /holders/8c146baf…` → 200. Each of the 9 values (`100 Calle Legacy`, `Apt 1L`, `787-555-0101`, `200 Calle Parcial`, `Apt 2E`, `787-555-0202`, `300 Calle Migrada`, `Apt 3M`, `787-555-0303`) occurs in the page HTML (count 2 each).

### 4.2 Memory characterization (3h-6)

Run from the pristine tree with a test config (a copy of the installed config with `db_name => 'flx_memtest'`; `flx_memtest` is a clone of `flx_install`). OS peak memory (RSS) was measured by a Python `getrusage` wrapper, because `/usr/bin/time` is not installed.

| Command | DB size on disk | memory_limit | PHP-reported peak | OS peak memory (RSS) | Dump output | Result |
|---|---|---|---|---|---|---|
| `php -d memory_limit=256M tools/dump_memory_check.php 100000 400` | 54.9 MB | 256M | 142.2 MB (after `databaseDump()` and after `DumpSeal::seal()`) | 172.8 MB | 43.5 MB gz, 43.5 MB sealed | `result: OK (completed within memory_limit)`, exit 0, 12.4 s |
| `php -d memory_limit=128M tools/dump_memory_check.php 50000 400` | 29.9 MB | 128M | 72.3 MB (both phases) | 105.1 MB | 21.8 MB gz, 21.8 MB sealed | `result: OK (completed within memory_limit)`, exit 0, 6.3 s |

The scratch table `zz_dump_mem` was dropped afterwards (0 remaining).

### 4.3 Shared-NAT login scenario

**Setup:**
- Instance `v1714_install`; owner `admin@qa.example` (Master Admin, no 2FA).
- IP A = 127.0.0.1, IP B = 127.0.0.2, using `curl --interface`. The app reads only `REMOTE_ADDR`; the `login_attempts` rows below confirm the two source addresses were seen.
- A fresh cookie jar and a fresh CSRF token for every attempt. `login_attempts` was emptied first.

| # | Action | From | Result |
|---|---|---|---|
| 0 | owner, correct password | IP A | HTTP 302 → `/`, then `GET /` 200 (signed in) |
| 1–8 | wrong password (8×) | IP A | HTTP 200, "Email or password is incorrect." (each time) |
| 9 | owner, correct password | IP A | HTTP 200, **"Too many sign-in attempts. Try again in 15 minutes."** (not signed in) |
| 10 | owner, correct password | IP B | HTTP 302 → `/`, then `GET /` 200 (**signed in**) |

`login_attempts` after step 10:
- 8 × `admin@qa.example|127.0.0.1`;
- 8 × `ip|127.0.0.1`;
- `known|admin@qa.example|127.0.0.1`;
- `known|admin@qa.example|127.0.0.2`;
- no `acct|` rows, because the sign-in from IP B cleared them.

The 8 `email|IP A` rows remain, so IP A stays blocked for this account for the rest of the 15-minute window.

---

## 5. Totals (only what I ran)

| Area | PASS | FAIL | Other |
|---|---|---|---|
| Setup and Step 1 (install, `schema_migrations`, CSRF ×2) | 5 | 0 | |
| Step 2 `runtime_check.sh` stages | 9 | **1 (3f)** | 1 not run (`harness.sh` not shipped) |
| Step 2 address dry-run | 1 (installed instance) | **1 (as instructed, base config)** | |
| Step 3 upgrade path | | | REQUIRES EXTERNAL RUNTIME VALIDATION |
| Steps 3b–3h and 4 (47 checks) | 40 | **6** (3d-2, 3d-6, 3e-2, 3e-3, 3g-1, 3h-3) | 1 STATICALLY REVIEWED (3g-8) |
| Reviewer items (§4.1–4.3) | Reported as numbers | | |

---

## 6. Surprising observations (none changed)

1. **Step 2 writes into the app tree.** It left 2 new empty files in the pristine tree: `app/storage/.notif-sweep-06fd865c-…` and `app/storage/.notif-sweep-e6dcc6e8-…`.
2. **Stage 3f reports PASS on work it didn't do.** With the prescribed `FLX_CONFIG` setup it prints PASS lines for crawls that reached 1 page (302 → `install.php`), and some probe PASSes are on 302 responses.
3. **`runtime_check.sh` banners are out of date.** It still says "FlxCoreHC v1.170.9" in its header. The checklist's Step 2 prerequisite still says the runners "need `app/config.php`", which conflicts with the `FLX_CONFIG` workflow.
4. **The installer assumes HTTPS.** It writes `app_url => 'https://<host>'` and `hsts => true` even with `app_env=dev` over http, so invite and iCal links are https on an http install. Share-link URLs shown in the UI are `http://`.
5. **GET `/admin/email` sends mail.** Opening that Console page flushes the outbox (`index.php:3664`); a queued row went to `sent`.
6. **"Known address" is recorded before 2FA.** A correct password alone marks the address as known for the lockout exemption, even if the 2FA step is never completed.
7. **Refusals look like successes.** Error flashes use the green "ok" toast style; `data-toast-type="ok"` is hard-coded.
8. **Read-only users cannot enrol in 2FA.** POST `/profile action=2fa_start` → "Your account has read-only access.", because the global read-only POST block runs first.
9. **Demo tenants skip the support-grant check.** `supportGrantError` returns null when `is_demo=1`, so 3d-3 is only meaningful against a non-demo tenant.
10. **Duplicate "Master Admin" and duplicate audit rows.**
    - Roles lists two "Master Admin" rows, one with 0 permissions ("View only") and one with 18.
    - The Team page shows the owner's role as the raw slug `tenant_owner` to a Manager.
    - "Role created" is logged twice for one creation.
11. **Audit package data quality.**
    - The "Open items" Requirement column shows the authority ("federal", "insurer") instead of a name.
    - "Requirement evidence" has 13 rows reading `Requirement · (blank) · not_started`.
    - The "Provider updated" audit entry lists all 24 field names even when nothing changed.
12. **The shipped CSV import template contains NPIs the app now rejects:** 1234567894, 1234567895 and 1990000000.
13. **PR CE cycles disagree.** Under PR, the Credentials checklist says CME "renews every 24 mo" while the CE panel totals 36 months.
14. **Other items:**
    - The Spanish credential-edit form keeps the verification sentence in English.
    - The calendar filter lists "Compliance deadline" twice.
    - Staff GETs create "Renewal tasks" audit rows.
    - `credential_documents.sha256` is NULL for every document, including a new upload.
15. **Documents live inside the web root.** `docs_path` defaults to `app/storage/docs`, protected only by `.htaccess` (or by the harness router in this test).
16. **No server errors.**
    - No 5xx and no PHP warnings, notices or deprecations in any server log or in the `error_log` table.
    - The only php -S error lines are the expected `smtp connect failed (smtp.gmail.com:587): Connection timed out` and the same for office365:465.
    - php -S does not access-log router-handled requests, so the runners recorded status codes in their scripts.

---

## 7. Test data and config I changed (on test instances only)

- **SQL fixture writes:**
  - 3 `addresses` rows (flx_install);
  - `UPDATE users SET holder_id=NULL` for one portal login (flx_qa_a);
  - 2 `email_outbox` rows (flx_qa_b).
- **Instance config:** `allow_open_registration=true` in `qa_B/app/config.php` for 4-1, then restored.
- **Records created through the UI:** 2FA enrolments, test users and roles, cases, tasks, share links, CE entries, providers, an uploaded PDF, and jurisdiction toggles (Coastal left on PR). Full lists are in the runner notes.
- **Throwaway databases used:** `flx_install`, `flx_qa_a`, `flx_qa_b`, `flx_memtest`, plus those the suites create (`flx_agency`, `flx_purge`, `flx_authz`, `flx_mig_*`, `flx_smoke`, `flx_security`).

**Attached files:**
- `runtime_check_output_v1.171.4.txt` (complete Step 2 output)
- `http_smoke_supplementary_v1.171.4.txt` (§2b run)
- screenshots in `screenshots_v1.171.4/` (62 files: `A_*` from runner A, `B_*` from runner B)

---

## Appendix A: Complete output of `bash tools/runtime_check.sh` (pristine tree, `FLX_CONFIG=/tmp/flx_base_config.php`)

```

== 1. PHP syntax (php -l) on all files ==
SYNTAX: PASS (all files)

== 2. Tenant isolation (tools/check_isolation.sh) ==
✓ isolation check passed — no raw DB access outside the repository layer (index.php: 34/34 baseline)

⚠ WARNING — raw SQL on tenant tables found in the controller/view layer:
index.php:499:        $__row = FlxCoreHC\Database::pdo()->prepare('SELECT file_path, original_name, mime_type FROM credential_documents WHERE id = ? AND tenant_id = ? LIMIT 1');
index.php:1363:                FlxCoreHC\Database::pdo()->prepare("UPDATE credential_holders SET template_id = NULL WHERE id = ? AND tenant_id = ?")
index.php:2625:        if (($_POST['confirm'] ?? '') !== 'PERMANENTLY DELETE') { flash('Type the confirmation phrase exactly.'); redirect('/credentials/trash'); }

== 3. Agency credentialing tests ==
PASS — case created in A
PASS — A: tenant B cannot READ tenant A case
PASS — A: tenant B cannot READ tenant A case requirement
PASS — B: tenant B cannot UPDATE tenant A case
PASS — B: tenant B cannot DELETE tenant A case
PASS — C: shared provider does not expose owner tenant cases
PASS — D: evidence from WRONG TENANT rejected
PASS — D: evidence from correct tenant but WRONG PROVIDER rejected
PASS — F: multiple targets rejected
PASS — D: valid same-provider evidence accepted
PASS — E: invalid holder rejected
PASS — E: invalid client group rejected
PASS — E: invalid payer rejected
PASS — E: assignee from another tenant rejected
PASS — F: invalid requirement code rejected
PASS — G: requirement mutation via service records case history
PASS — H: hard-stop fails CLOSED when compliance eval cannot complete
PASS — H: ready_for_submission blocked under compliance_check_error
PASS — I: applications insert works with case_id NULL
PASS — I: compliance_events record works without case_id (existing consumers unaffected)
PASS — J-A: credential_documents.credential_id remains NOT NULL
PASS — J-B: a valid credential-scoped document can still be created
PASS — J-C: credential_documents rejects a NULL credential_id (credential_id still required)
PASS — K: valid same-tenant assignment accepted
PASS — K: unassignment (null) accepted
PASS — K: nonexistent assignee rejected
PASS — K: assignee from another tenant rejected
PASS — L: Case A cannot delete Case B document (same tenant)
PASS — L: Case A cannot unlink Case B evidence (same tenant)
PASS — L: rightful case can delete its own document
PASS — L: rightful case can unlink its own evidence
PASS — M: populateRequirements seeds case requirements from the profession template
PASS — M: populate is idempotent (no duplicates)
PASS — N: reusable evidence marks an EXPIRED credential as not valid
PASS — N: reusable evidence marks a CURRENT credential as valid
PASS — N: existing evidence does NOT auto-satisfy a requirement
PASS — O: provider created
PASS — O: soft delete succeeds
PASS — O: soft-deleted provider not in active list
PASS — O: soft-deleted provider IS in trashed
PASS — O: trashed() lists the deleted provider
PASS — O: restore succeeds
PASS — O: restored provider is active again
PASS — O: restored provider no longer in trash
PASS — O: tenant B cannot restore tenant A record
PASS — O: tenant B cannot purge tenant A record
PASS — O: rightful tenant can permanently delete
PASS — O: purged provider is gone everywhere
PASS — P: platform admin cannot be moved to a portal-only (producer) role
PASS — P: platform admin role unchanged after blocked demotion

ALL PASS

== 3a. Healthcare-only checks (v1.170.7, no database needed) ==
PASS — H1 context vertical 'insurance' → healthcare
PASS — H1 context vertical 'custom' → healthcare
PASS — H1 context vertical 'platform' → healthcare
PASS — H1 context vertical '' → healthcare
PASS — H2 vocab holder = Provider for any vertical
PASS — H2 vocab partners = Payers for any vertical
PASS — H3 "Other (custom)" sign-up removed
PASS — H3 every sign-up shape is healthcare
PASS — H4 ruleset label is healthcare
PASS — H4 custom document type defaults to all healthcare provider types
PASS — H4 invalid applies_to values dropped
PASS — H4 applies_to column widened to 255 before the default (114 chars) is written
PASS — H4 holder role never falls back to insurance "agent"
PASS — H4 portal role label is Provider (self-service)
PASS — H5 no insurance phrases in views
PASS — H5b no insurance flash/audit/label strings in index.php, app/src, lang
PASS — H7 Master Admin grant is owner-only in create/invite/updateRole
PASS — H7 reserved owner slugs recognised
PASS — H7 need-to-know (SQL provider scope) enforced in CredentialRepository
PASS — H7 need-to-know (SQL provider scope) enforced in DocumentRepository
PASS — H7 need-to-know (SQL provider scope) enforced in RenewalTaskRepository
PASS — H7 need-to-know (SQL provider scope) enforced in CalendarRepository
PASS — H7 CSV exports pass through csv_safe_row
PASS — H7 2FA disable is rate-limited
PASS — H7 holder create/edit never store a posted NPN
PASS — H7 csv_safe_row neutralises formulas, keeps numbers
PASS — H6 migration 124 in installer and updater lists
PASS — H6 migrations scanned for bare SELECT

ALL PASS

== 3b. Purge + security tests (v1.170.6, isolated flx_purge DB) ==
PASS — Q1 setup: case opened
PASS — Q1 setup: evidence linked
PASS — Q1 purge blocked while a case is OPEN (Cannot permanently delete: 1 open credentialing cases still reference this record. Close or cancel them first.)
PASS — Q1 setup: case cancelled
PASS — Q1 purge succeeds once cases are closed (Record permanently deleted.)
PASS — Q1 holder row gone
PASS — Q1 credentials gone
PASS — Q1 credential_documents gone (FK child)
PASS — Q1 renewal_tasks gone (FK child)
PASS — Q1 credential_reminders gone (FK child)
PASS — Q1 producer_requirements gone
PASS — Q1 provider addresses gone
PASS — Q1 credentialing cases gone
PASS — Q1 case requirements gone
PASS — Q1 case evidence gone
PASS — Q1 case documents gone
PASS — Q1 stored files removed from disk
PASS — Q1 share links revoked
PASS — Q1 compliance timeline retained
PASS — Q2 purge refused for a live (not deleted) record
PASS — Q3 tenant B cannot purge tenant A record
PASS — Q4 soft-delete guard sees the active linked login
PASS — Q4 purge blocked by active linked login (Cannot permanently delete: A login account (u-24f355e7-243c-4b9e-9788-c54932bc8d33@purge.test, producer, active) is linked to this record. Suspend the portal account, or have a platform admin unlink it, first.)
PASS — Q4 nothing was removed when blocked
PASS — Q4 suspended STAFF login still blocks purge (only portal-only links are released)
PASS — Q5 purge succeeds once the only link is a suspended portal account (Record permanently deleted.)
PASS — Q5 suspended portal account unlinked (holder_id NULL)
PASS — Q6 unexpected FK → purge fails with rollback message
PASS — Q6 credential restored by rollback
PASS — Q6 credential document restored by rollback
PASS — Q6 renewal task restored by rollback
PASS — Q6 producer requirement restored by rollback
PASS — Q6 stored file NOT deleted when the transaction rolled back
PASS — Q7 credential purge succeeds (Credential permanently deleted.)
PASS — Q7 credential + FK children gone
PASS — Q7 credential file removed
PASS — Q7 provider untouched by credential purge
PASS — Q7 tenant B cannot purge tenant A credential
PASS — Q8 file outside the tenant storage dir is NOT deleted
PASS — Q9 platform-wide payer accepted on case open
PASS — Q9 unknown payer still rejected
PASS — Q9 another tenant's private payer rejected
PASS — Q9 case cannot be opened for a provider in Deleted records
PASS — Q10 suspended user cannot be assigned
PASS — Q10 active user can be assigned
PASS — Q11 share link resolves for a live provider
PASS — Q11 share link does NOT resolve once the provider is in Deleted records
PASS — Q11 share link works again after restore

ALL PASS

== 3c. Provider-scope static guards (v1.170.9, no database needed) ==
PASS — S1 unrestricted scope adds nothing (behaviour unchanged for owners/unrestricted users)
PASS — S1 strict scope = correlated IN-subquery on holder_assignments, tenant + user bound
PASS — S1 nullable scope lets tenant-wide (NULL provider) rows through
PASS — S1 bound params = [tenant, user] — no inlined ids
PASS — S1 COALESCE form accepted
PASS — S1 rejects hostile column expression: c.holder_id; DROP TABLE x
PASS — S1 rejects hostile column expression: 1=1 OR c.holder_id
PASS — S1 rejects hostile column expression: c.holder_id) OR (1=1
PASS — S1 rejects hostile column expression: c.`holder_id`
PASS — S1 credential-relative scope resolves through credentials → assignments
PASS — S1 restricted() flag
PASS — S2 no ntkFilter/ntkRow/ntk_holder post-filters remain in app/src
PASS — S2 no repository filters rows in PHP by visibleIds()
PASS — S3 HolderRepository declares provider scope ('id')
PASS — S3 CredentialRepository declares provider scope ('holder_id')
PASS — S3 RenewalTaskRepository declares provider scope ('holder_id')
PASS — S3 CalendarRepository declares provider scope ('holder_id')
PASS — S3 ApplicationRepository declares provider scope ('holder_id')
PASS — S3 AttestationRepository declares provider scope ('holder_id')
PASS — S3 CaseDocumentRepository declares provider scope ('holder_id')
PASS — S3 CeEntryRepository declares provider scope ('holder_id')
PASS — S3 CredentialingCaseRepository declares provider scope ('holder_id')
PASS — S3 HolderRegulatorRepository declares provider scope ('holder_id')
PASS — S3 ProducerRequirementRepository declares provider scope ('holder_id')
PASS — S3 ProviderContactRepository declares provider scope ('holder_id')
PASS — S3 ProviderEducationRepository declares provider scope ('holder_id')
PASS — S3 ProviderWorkHistoryRepository declares provider scope ('holder_id')
PASS — S3 ScreeningRepository declares provider scope ('holder_id')
PASS — S3 VerificationRepository declares provider scope ('holder_id')
PASS — S3 DocumentRepository scopes through its credential
PASS — S3 TenantRepository::find applies the provider scope
PASS — S3 TenantRepository::all applies the provider scope
PASS — S3 TenantRepository::count applies the provider scope
PASS — S3 TenantRepository::update applies the provider scope
PASS — S3 TenantRepository::delete applies the provider scope
PASS — S4 DashboardService applies ProviderScope
PASS — S4 AgencyWorkQueueService applies ProviderScope
PASS — S4 DocumentRequestService applies ProviderScope
PASS — S4 VerificationService applies ProviderScope
PASS — S4 ComplianceEventService applies ProviderScope
PASS — S4 DataExportService applies ProviderScope
PASS — S4 every dashboard credentials aggregate carries the scope
PASS — S4 dashboard reports scoped + queryFailures
PASS — S5 AssignmentService: missing user row / lookup failure → assigned + no ids (sees nothing)
PASS — S5 no catch block widens access to "all"
PASS — S5 dashboard failures are counted, not silently zero
PASS — S5 share-link viewer is an explicit system actor (not a user role)
PASS — S6 document verify/unverify requires documents.verify
PASS — S6 documents.manage still enforced separately from records.write
PASS — S6 no route confines providers by comparing with the single slug "producer" (all portal-only roles are confined)
PASS — S6 portal confinement uses RoleService::isPortalContext
PASS — S6 portal flag is resolved BEFORE the dashboard route (custom portal roles cannot reach the tenant dashboard)
PASS — S6 notification recipients exclude custom portal-only roles
PASS — S7 124_healthcare_only.sql registered in install.php and manifest.php
PASS — S7 125_healthcare_cleanup.sql registered in install.php and manifest.php
PASS — S7 HTTP smoke + agency runner present
PASS — S7 DB-backed authz suite + isolated runner present (REQUIRES EXTERNAL RUNTIME VALIDATION)

ALL PASS

== 3d. Authorization regression suite (v1.170.9, isolated flx_authz DB — cross-tenant, need-to-know, LIMIT, capability, portal, fail-closed) ==
fixture: A=b5365fd1-56f9-43f5-8737-cafb34220841 B=fbddc5ae-7baf-44b4-b945-fafbdcb0875c P1=2654468c-47c7-40af-a17b-14f4c71ce91d P2=4eca6382-faef-43f6-b43d-17ccf98f2263 restricted=f451a3b9-0b29-47cd-ab33-32af8191a374
PASS — X owner: B1 holder not readable
PASS — X owner: B1 credential not readable
PASS — X owner: B1 document not readable
PASS — X owner: B1 task not readable
PASS — X owner: B1 calendar item not readable
PASS — X owner: B1 case not readable
PASS — X owner: B1 credential not writable
PASS — X manager: B1 holder not readable
PASS — X manager: B1 credential not readable
PASS — X manager: B1 document not readable
PASS — X manager: B1 task not readable
PASS — X manager: B1 calendar item not readable
PASS — X manager: B1 case not readable
PASS — X manager: B1 credential not writable
PASS — X staff: B1 holder not readable
PASS — X staff: B1 credential not readable
PASS — X staff: B1 document not readable
PASS — X staff: B1 task not readable
PASS — X staff: B1 calendar item not readable
PASS — X staff: B1 case not readable
PASS — X staff: B1 credential not writable
PASS — X restricted: B1 holder not readable
PASS — X restricted: B1 credential not readable
PASS — X restricted: B1 document not readable
PASS — X restricted: B1 task not readable
PASS — X restricted: B1 calendar item not readable
PASS — X restricted: B1 case not readable
PASS — X restricted: B1 credential not writable
PASS — X readonly: B1 holder not readable
PASS — X readonly: B1 credential not readable
PASS — X readonly: B1 document not readable
PASS — X readonly: B1 task not readable
PASS — X readonly: B1 calendar item not readable
PASS — X readonly: B1 case not readable
PASS — X readonly: B1 credential not writable
PASS — X owner: B1 compliance timeline empty
PASS — N restricted mode resolved
PASS — N scope restricted only for the assigned user
PASS — N holders list = [P1] only
PASS — N holder find: P2 hidden, P1 visible
PASS — N holder COUNT = 1 (aggregate scoped)
PASS — N credentials list = [c1] only
PASS — N credential find by VALID id of P2 → null
PASS — N credential COUNT = 1
PASS — N statusCounts aggregate = 1 (not 3)
PASS — N withHolders/report scoped
PASS — N document by VALID id of P2 → null; P1 doc visible
PASS — N documents list = 1
PASS — N tasks: P1 + org-wide visible, P2 hidden
PASS — N task find by VALID id of P2 → null
PASS — N legacy task (NULL holder, P2 credential) hidden from restricted user
PASS — N legacy task hidden in the joined task list too
PASS — N legacy task visible to unrestricted staff
PASS — N calendar: P1 + org event visible, P2 hidden
PASS — N calendar dayCounts aggregate = 2 (P1 + org), not 3
PASS — N iCal feed scoped in SQL
PASS — N applications portfolio/KPI empty for restricted user with no assigned applications
PASS — N screening KPI/history scoped
PASS — W restricted cannot mark P2 calendar event done; can mark own P1 event
PASS — N case by VALID id of P2 → null
PASS — N work queue active_total = 1 (not 2)
PASS — N dashboard: providers=1, expired=0 (P2 expired credential hidden), scoped flag
PASS — N dashboard forecast counts only P1
PASS — N dashboard queue never names P2
PASS — N compliance timeline of P2 empty for restricted
PASS — N verifications of P2 empty for restricted
PASS — N unrestricted staff unchanged (3/3)
PASS — N assignment change reflected immediately (2 credentials)
PASS — N unassign reflected immediately
PASS — L LIMIT 10 for restricted user returns all 5 of P1 (scope applied BEFORE LIMIT), got 5
PASS — L COUNT = 5
PASS — M owner can('records.write') = true
PASS — M manager can('records.write') = true
PASS — M staff can('records.write') = true
PASS — M restricted can('records.write') = true
PASS — M readonly can('records.write') = false
PASS — M portal can('records.write') = false
PASS — M owner can('records.delete') = true
PASS — M manager can('records.delete') = true
PASS — M staff can('records.delete') = false
PASS — M restricted can('records.delete') = false
PASS — M readonly can('records.delete') = false
PASS — M portal can('records.delete') = false
PASS — M owner can('documents.manage') = true
PASS — M manager can('documents.manage') = true
PASS — M staff can('documents.manage') = true
PASS — M restricted can('documents.manage') = true
PASS — M readonly can('documents.manage') = false
PASS — M portal can('documents.manage') = false
PASS — M owner can('documents.verify') = true
PASS — M manager can('documents.verify') = true
PASS — M staff can('documents.verify') = true
PASS — M restricted can('documents.verify') = true
PASS — M readonly can('documents.verify') = false
PASS — M portal can('documents.verify') = false
PASS — M owner can('documents.view') = true
PASS — M manager can('documents.view') = true
PASS — M staff can('documents.view') = true
PASS — M restricted can('documents.view') = true
PASS — M readonly can('documents.view') = false
PASS — M portal can('documents.view') = true
PASS — M owner can('team.manage') = true
PASS — M manager can('team.manage') = true
PASS — M staff can('team.manage') = false
PASS — M restricted can('team.manage') = false
PASS — M readonly can('team.manage') = false
PASS — M portal can('team.manage') = false
PASS — M owner can('data.export') = true
PASS — M manager can('data.export') = true
PASS — M staff can('data.export') = false
PASS — M restricted can('data.export') = false
PASS — M readonly can('data.export') = false
PASS — M portal can('data.export') = false
PASS — M owner can('reports.view') = true
PASS — M manager can('reports.view') = true
PASS — M staff can('reports.view') = true
PASS — M restricted can('reports.view') = true
PASS — M readonly can('reports.view') = false
PASS — M portal can('reports.view') = false
PASS — M coordinator: records.write yes, documents.verify NO
PASS — M manager cannot grant tenant_owner
PASS — M manager cannot create a master_admin/tenant_owner (unknown slug degrades to a normal role; got false)
PASS — M manager cannot grant a tenant-local reserved-slug role (actorMayGrant)
PASS — M custom role named "Master Admin" never gets a reserved slug (got "master_admin_role")
PASS — W restricted cannot UPDATE P2 credential by id
PASS — W restricted cannot DELETE P2 credential by id
PASS — W restricted cannot UPDATE P2 holder
PASS — W restricted CAN update own assigned P1 credential
PASS — W P2 credential untouched in DB
PASS — P portal user sees own credential (provider_access=all, ownership enforced by routes)
PASS — P producer is portal-only
PASS — P cannot create a portal-only login without a provider record (Pick which provider this login belongs to.)
PASS — P custom portal-only role created
PASS — P custom portal-only role also requires a provider record (redirect-loop regression)
PASS — P suspended user iCal token does not resolve (route predicate)
PASS — F unknown user id → sees nothing
PASS — F unknown user COUNT = 0
PASS — F compliance evaluation runs (screening lookup healthy)
PASS — D (pre-125) custom master_admin slug resolves to ALL caps — the vulnerability 125 fixes
PASS — D migration 125 applies with children present (FK-safe)
PASS — D insurance compliance items removed
PASS — D custom reserved slug renamed (collision-safe, even though 'master_admin_role' already exists in this tenant): master_admin_role_32b3f6ee
PASS — D user moved to renamed slug (not orphaned)
PASS — D renamed role no longer all-caps
PASS — D credential rows intact after 125 (67)

ALL PASS

== 3e. Migration scenarios A-D for 124/125 (v1.170.9, throwaway flx_mig_* DBs) ==
PASS — A fresh install 001→125: zero migration errors (got 0)
PASS — A no insurance compliance items on a fresh install
PASS — A no insurance tenants
PASS — A migrations 124+125 are re-runnable (idempotent) on a migrated DB
PASS — B baseline (everything before 124) applied cleanly (got 0)
PASS — B 124 then 125 apply with legacy data present (errors: 0/0)
PASS — B no insurance/custom tenants remain
PASS — B no legacy org shapes remain
PASS — B all holders healthcare
PASS — B insurance compliance items removed (FK-safe with tenant_compliance children)
PASS — B healthcare credential intact
PASS — B healthcare provider intact
PASS — B custom role with reserved slug renamed
PASS — B user moved with the renamed role (not orphaned)
PASS — B tenants has the 7 healthcare entity columns
PASS — B credential_catalog.applies_to widened (len 255)
PASS — B portal role display name is not 'Producer'
PASS — C older baseline (before 072_prune_insurance_seed.sql) applied cleanly (got 0)
PASS — C every later migration applies on top of the older install (errors: 0)
PASS — C no insurance/custom tenants remain
PASS — C no legacy org shapes remain
PASS — C all holders healthcare
PASS — C insurance compliance items removed (FK-safe with tenant_compliance children)
PASS — C healthcare credential intact
PASS — C healthcare provider intact
PASS — C custom role with reserved slug renamed
PASS — C user moved with the renamed role (not orphaned)
PASS — C tenants has the 7 healthcare entity columns
PASS — C credential_catalog.applies_to widened (len 255)
PASS — C portal role display name is not 'Producer'

ALL PASS

== 3f. HTTP smoke + authorization probes + role crawl (v1.170.9, isolated flx_smoke DB, php -S) ==
setup ok
FAIL — coordinator cannot verify a document (documents.verify) status 302
PASS — staff can verify a document status 302
PASS — coordinator credential page has no Verify/Mark verified controls status 302
FAIL — staff credential page offers verification status 302
FAIL — restricted user cannot POST-edit P2 credential status 302
PASS — restricted dashboard never names P2 (Bravo) 
FAIL — owner dashboard shows both providers/expiries 
PASS — restricted credentials list hides P2 status 302
PASS — restricted holders list hides P2 status 302
PASS — restricted tasks list hides P2 status 302
PASS — restricted calendar list hides P2 status 302
PASS — restricted cases list hides P2 status 302
FAIL — custom portal role cannot edit another provider credential status 302
FAIL — custom portal role cannot read another provider document status 302
FAIL — custom portal role is redirected from the dashboard to /me status 302 install.php
PASS — manager cannot promote a user to tenant_owner status 302, role now staff
FAIL — S2 victim can use the app before suspension status 302
FAIL — S2 suspending a user ends their live session on the very next request status 302 install.php
FAIL — S2 a password reset ends the user's other sessions status 302
FAIL — S2 a role change applies immediately (manager -> read-only loses /team) before 302 after 302
PASS — S1 manager POST creating a role with a capability they lack is refused status 302, rows 0
PASS — B4 restricted user gets 403 on the organization-wide /audit log status 302
PASS — B4 restricted user cannot export the audit log status 302
FAIL — B4 restricted /notifications shows P1 notice and hides P2 notice status 302
FAIL — B1 editing a provider as PA keeps type=pa and saves specialty / CAQH / PTAN status 302 row physician|NULL|NULL|NULL
FAIL — S3 platform admin WITHOUT 2FA is sent to their profile, not the console status 302 install.php
FAIL — S3 once 2FA is on, the console opens status 302
FAIL — S3/K System page renders with the encryption-key backup panel + reminder (no PHP warnings) status 302
FAIL — K banner reminds the platform admin to back up the keys status 302
PASS — K key backup refuses a wrong current password status 302
PASS — K key backup refuses a weak passphrase status 302
FAIL — K key backup downloads as an attachment, hides the keys, and decrypts back to the installed keys status 302
FAIL — K after confirming, the reminder banner is gone status 302
PASS — D6 sealed dump refuses a weak passphrase status 302
PASS — D6 sealed dump refuses a wrong account password status 302
FAIL — D6 sealed dump downloads as an attachment with the FLXDUMP1 header status 302
FAIL — E HTTP upload stores the file sealed (header present, no plaintext on disk) 
FAIL — E HTTP download returns the original bytes status=0
FAIL — D-5 PR tenant: CE panel totals the last 36 months status=302
FAIL — D-5 non-PR tenant: CE panel totals the last 24 months; PR-only readiness items absent status=302 24m=False ocs_ctx=''
FAIL — B-8 staff menu does not list Groups / Locations / Applications / Regulators status=302 found=[]
FAIL — B-8 owner menu still lists them status=302
FAIL — S7 manager without 2FA is sent to /profile from / /billing /holders /team when the policy is on {'/': (302, 'install.php'), '/billing': (302, 'install.php'), '/holders': (302, 'install.php'), '/team': (302, 'install.php')}
FAIL — S7 the profile page (where they enrol) stays reachable status=302
FAIL — S7 staff (no privileged capability) is not gated status=302
FAIL — S7 policy off again: manager reaches the dashboard status=302
PASS — crawl as owner: pages=1 codes={302: 1}
PASS — crawl as manager: pages=1 codes={302: 1}
PASS — crawl as staff: pages=1 codes={302: 1}
PASS — crawl as coord: pages=1 codes={302: 1}
PASS — crawl as readonly: pages=1 codes={302: 1}
PASS — crawl as restricted: pages=1 codes={302: 1}
PASS — crawl as padmin: pages=1 codes={302: 1}

SOME FAILED
HTTP SMOKE: FAIL/SKIP (exit 1) — send the exact output to Claude; do not edit code or tests.

== 3g. v1.171.0 security suite (capability ceiling, session revalidation, support grants, scope, migrator, keys, encrypted documents; isolated flx_security DB) ==
PASS — S1 setup: there are capabilities a Manager does not hold (pii.view)
PASS — S1 Manager cannot create a role containing a capability they lack
PASS — S1 …and no such role row was created
PASS — S1 Manager CAN create a role within their own capabilities
PASS — S1 Manager cannot widen an existing role beyond their own capabilities
PASS — S1 Manager cannot edit a built-in role (capabilities unchanged)
PASS — S1 Owner can create an all-capability role
PASS — S1 Manager cannot edit (e.g. strip) a role more powerful than themselves
PASS — S1 Manager cannot assign a role that exceeds their own capabilities (self-escalation route closed)
PASS — S1 Manager cannot promote THEMSELVES to a bigger role
PASS — S1 Owner can assign any role
PASS — S1 Manager cannot grant their own role access to sensitive fields
PASS — S1 Owner can set field rules
PASS — B5 Manager's role picker omits owner-level and higher-than-self roles, keeps Staff
PASS — B5 picker no longer lists platform-global names this tenant does not have
PASS — B5 Owner's picker lists every tenant role
PASS — B5 unknown role is REJECTED with a message (previously silently saved as Staff)
PASS — S2 an untouched session stays valid
PASS — S2 a role change takes effect on the next request (session role refreshed from the database)
PASS — S2 suspending the user ends their session
PASS — S2 a password reset ends every existing session of that user
PASS — S2 changing your OWN password keeps the current session (other sessions would end)
PASS — S2 a suspended organization ends all of its sessions
PASS — S2 a deleted user ends their session
PASS — S2 a session created before this release adopts a fingerprint once (no forced mass logout)
PASS — S2 support-mode sessions are governed by the support grant, not this check
PASS — S3 platform admin CANNOT reset a customer owner's password without an approved support grant
PASS — S3 …cannot create an owner login inside a customer organization without a grant
PASS — S3 …cannot add a viewer inside a customer organization without a grant
PASS — S3 …cannot change an owner-level role without a grant
PASS — S3 demo organizations (no real PHI) are exempt
PASS — S3 with an APPROVED support grant the reset is allowed
PASS — S3 with a grant, creating a login is allowed
PASS — S3 a REVOKED grant stops working immediately
PASS — S3 cannot make a user a platform administrator unless they use 2FA
PASS — S3 a 2FA-enabled user can be made a platform administrator
PASS — B1 one provider-type list: 14 types = 11 individual + 3 organization
PASS — B1 every individual provider type survives an edit round-trip
PASS — B1 every organization type survives an edit round-trip
PASS — B1 an invalid type never wipes the stored type
PASS — B1 an organization type is refused on an individual record
PASS — B1 choosing "Not specified" clears the type on purpose
PASS — B1 the "Application data" block (specialty, CAQH, PTAN, taxonomy, languages, …) persists on edit
PASS — B1 a portal-style partial form does not blank the fields it did not submit
PASS — B1 a malformed NPI is rejected with a message
PASS — B2 provider list counts only LIVE credentials (deleted one excluded)
PASS — B2 a task no longer borrows the name/expiry of a deleted credential
PASS — B2 expiringWithin() skips credentials of a deleted provider
PASS — B2 cron sweep flips LIVE credentials to 'expiring' but leaves deleted ones alone (live=expiring deleted=active rc=0)
PASS — B3 "table missing / unknown column / no default / cannot create / constraint" are no longer treated as harmless
PASS — B3 genuine "already in place" codes are still tolerated
PASS — B3 an existing database with no update history is REFUSED (must be baselined), not replayed from migration 003
PASS — B3 re-running the last migrations is idempotent: all succeed and are recorded
PASS — B3 skipped statements are reported with their detail (16 skipped), never silent
PASS — B3 a second updater is refused while one is running (advisory lock)
PASS — B3 …and works again once the lock is released
PASS — B4 owner sees the organization-wide audit log
PASS — B4 need-to-know user gets NOTHING from the tenant-wide audit views (fail closed)
PASS — B4 notification list hides notices about providers the user cannot see (incl. stale ones from before un-assignment)
PASS — B4 the bell count equals exactly the notices the user can see (hidden one not counted)
PASS — B4 an unrestricted user still sees everything
PASS — B4 group member counts: owner 2, need-to-know user 1
PASS — B4 location provider counts: owner 2, need-to-know user 1
PASS — B6 fputcsv/str_getcsv wrappers raise no deprecation on this PHP (8.4.19)
PASS — B6 CSV round-trips quotes, commas and backslashes
PASS — B6 no raw fputcsv/str_getcsv calls remain outside the wrappers
PASS — S4 flx_json() emits no raw < or > yet decodes back to the exact value
PASS — S4 no view embeds raw json_encode() output
PASS — K backup opens with the right passphrase and returns both keys
PASS — K wrong passphrase → refused (authenticated encryption, no garbage output)
PASS — K a tampered file is refused
PASS — K a downgraded work factor is refused
PASS — K the file never contains the keys in clear
PASS — K passphrase rules: ≥12 chars and must match
PASS — K installer requires a passphrase, builds the sealed backup, and never e-mails keys
PASS — K restore tool is present and validates its input
PASS — E store() writes the file and removes the temp upload
PASS — E on-disk bytes carry the header and NO plaintext
PASS — E read() returns the exact original bytes
PASS — E send() streams decrypted bytes
PASS — E a blob moved to another filename does not decrypt (AAD-bound)
PASS — E a tampered file is refused, not served
PASS — E legacy plaintext files are still readable (backward compatible)
PASS — E in-place conversion round-trips and is idempotent
PASS — E migration CLI runs (dry-run)
PASS — E no raw readfile()/move_uploaded_file() left in index.php
PASS — E document backup archive holds sealed files, never plaintext
PASS — N known-good NPIs pass (incl. the CMS worked example 1234567893)
PASS — N wrong check digit / leading 0 / wrong length are rejected
PASS — N fix() keeps the first 9 digits and produces a valid NPI
PASS — N check digit is consistent for 200 random bases
PASS — N provider form rejects a 10-digit number with a bad check digit
PASS — N provider form accepts a valid NPI
PASS — N group NPI with a bad check digit is refused
PASS — N provider CSV import flags a bad NPI
PASS — J PR medical licence and CME apply to physicians only (not nurses)
PASS — J PR nursing licence exists and applies to nurses
PASS — J a new healthcare tenant gets the JLDM name, not "PR Board of Medical Examiners"
PASS — J the nursing board carries the PR nursing licence requirement (once)
PASS — J the negative-CDS certificate no longer names two conflicting agencies
PASS — J migration 126 renames the legacy regulator in place (one row, no duplicate)
PASS — J migration 126 back-fills the nursing requirement for existing tenants, idempotently
PASS — J migration 126 is re-runnable (no duplicates)
PASS — J readiness for a non-PR tenant shows none of the PR-only items
PASS — J readiness for a PR tenant shows the PR items
PASS — J a state controlled-substance registration does NOT satisfy the PR negative drug-test certificate
PASS — J OrgService::statesFor reads the tenant jurisdictions (none assumed for unknown tenants)
PASS — J CE look-back is 36 months for PR, 24 otherwise
PASS — J the PR CE window really spans ~36 months
PASS — J no jurisdiction is assumed (no silent FL fallback)
PASS — J tenants.operating_states has no 'FL' default (got '\'\'')
PASS — J demo build: every demo NPI passes the check-digit rule (37 NPIs in 6 demo orgs, 0 invalid)
PASS — J the five built demo organizations operate in PR (their data is all Puerto Rico)
PASS — S5 only mail-submission ports (25/465/587/2525) are allowed
PASS — S5 loopback / private / link-local / metadata / unresolvable / malformed SMTP hosts are refused
PASS — S5 a public mail host is accepted and pinned to its validated address
PASS — S5 saving SMTP settings: internal host refused, non-mail port refused, valid settings saved
PASS — S5 at SEND time a tenant-supplied internal address is refused too (defence against a row edited or DNS changed after save)
smtp connect failed (127.0.0.1:1): Connection refused
PASS — S5 connection failures return a generic message (no refused/timeout oracle); operator-configured SMTP is not blocked by the guard
PASS — S6 enrolment stores the TOTP secret encrypted (never the base32 secret)
PASS — S6 the secret round-trips; the enrolment code's time-step is burned
PASS — S6 REPLAY: the code used at enrolment is rejected
PASS — S6 a fresh (next time-step) code is accepted
PASS — S6 REPLAY: the same code cannot be used twice
PASS — S6 an older time-step than the last accepted one is rejected
PASS — S6 a wrong code is rejected
PASS — S6 a legacy plaintext secret still works and is upgraded to encrypted on first use
PASS — S6 disabling 2FA with an already-used code is refused (replay)
PASS — S6 a secret sealed for another user id does not open (AAD-bound)
PASS — S7 a CUSTOM role with team.manage or pii.view is held to the 2FA policy; one without is not
PASS — S7 owner and manager are privileged; staff and read-only are not
PASS — B7 isolation check passes on a clean tree
PASS — B7 a new file using raw Database::pdo() FAILS the check
PASS — B7 growth of raw DB calls in index.php beyond the baseline FAILS the check
PASS — B7 a stale allow-list entry FAILS the check
PASS — B7 the shipped index.php is within its recorded baseline
PASS — S8 owner signs in normally from their usual address
PASS — S8 the owner (known address) is NOT locked out by a stranger's failed attempts
PASS — S8 a never-seen address is still held by the per-account throttle (brute force stays blocked)
PASS — S8 production refuses the dev passthrough marker (a forged 0x00 value reads as null); dev still reads it
PASS — S8 the database holds only a hash of the invite token
PASS — S8 the emailed token resolves; a guess or the stored hash itself does not
PASS — S8 a legacy (raw-token) pending invite still resolves
PASS — S8 accepting an invite consumes it
PASS — S8 viewing /admin or /compliance no longer sends mail (cron/POST only)
PASS — S8 /settings is gated by the org.manage capability, not role names
PASS — S8 installer form carries a session CSRF token
PASS — D6 street line and phone are not stored in plaintext
PASS — D6 the repository returns the decrypted address
PASS — D6 editing re-encrypts and leaves no plaintext behind
PASS — D6 a legacy plaintext row still reads before the back-fill
PASS — D6 callers cannot write ciphertext columns directly
PASS — D6 the back-fill encrypts legacy rows and they still read
PASS — D6 sealed dump carries the FLXDUMP1 header, not a bare gzip
PASS — D6 the right passphrase restores the exact dump
PASS — D6 a wrong passphrase yields nothing
PASS — D6 a tampered sealed dump is rejected
PASS — D6 database/decrypt_dump.php restores the .sql.gz from the sealed file
PASS — D6 the console offers a sealed download, password-confirmed and rate-limited
PASS — V4 case A: a fully legacy row is encrypted, plaintext scrubbed, repository returns the originals
PASS — V4 case B: migrating line1 leaves the existing line2/phone ciphertext byte-for-byte unchanged and everything decrypts
PASS — V4 case C: re-running the back-fill on a migrated row changes nothing (idempotent)
PASS — V4 case D: all 27 plaintext/ciphertext/empty combinations — no ciphertext lost or altered, all values read back (lost=0, wrong=0)
PASS — V4 case D: a second run over all combinations changes nothing
PASS — V4 a valid invitation can be consumed once; the second acceptance fails and does not change the password
PASS — V4 an expired invitation cannot be accepted (conditional UPDATE, rowCount must be 1)
PASS — V4 the stored SHA-256 hash still cannot be replayed as a token
PASS — V4 (documented residual risk) the owner IS held for 15 min from the shared NAT address once the (email,IP) threshold is hit by someone behind it; from any other address they sign in normally
PASS — V4 no "fully compliant" overclaim remains in views, help (EN/ES) or level labels
PASS — V4 compliance level 5 is labelled as tracked-requirements status, not legal compliance
PASS — V4 help (EN and ES) states FlxCoreHC does not perform or certify primary-source verification
PASS — V4 help no longer claims what auditors or payers accept
PASS — V4 migration 099 comment corrected; its SQL is unchanged
PASS — V4 provider and credential pages title the panel "Verification records" with the disclaimer
PASS — V4 nursing board is "Junta Examinadora de Enfermería"; migration 132 renames legacy rows and is idempotent (ran twice)
PASS — V4 unconfirmed PR facts (CME hour breakdown, negative drug-test certificate) are labelled UNVERIFIED, not stated as universal requirements
PASS — V4 the readiness checklist does not present the drug-test certificate as an established requirement
PASS — V4 no seed file or view still uses the old nursing-board name (except the migration that renames it)

ALL PASS

== 4. Route smoke test (harness.sh full) ==
harness.sh not present in this checkout (dev-only) — skip if you don't have it

== RESULT ==
One or more stages FAILED — capture the exact output above and send it to Claude (do not fix-to-green).
```
