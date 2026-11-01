# FlxCoreHC v1.171.7 — final independent validation (Claude Code)

**Date:** 2026-10-01

**Role:** runner and reporter only. I did not modify any app code, migrations, schema or tests.

**Bundle:** `claude_code_bundle_v1_171_7.zip`. Its `CLAUDE_CODE_RUNTIME_CHECK.md` is identical to the copy inside the app zip.

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
| `claude_code_validate_v1_171_7.sh`, run as written | **exit 0**. 659 PASS lines, "no FAIL/SKIP lines". All 7 crawls reached **220 pages**. "STRAY FILES: PASS" |
| Checklist scope requested: Step 3k (new), Step 4.3 (corrected), regression pass over 3i and 3j, plus 3h-3 and Step 1 | **25 / 25 PASS** |
| "Try to break" list | **R3, R4 and the installer `mail_from` check PASS. R1, R2 and R5 FAIL on sub-parts not covered by the checklist** (role rename, organization edit, stale pending 2FA secret) |
| Regressions compared with v1.171.6 | **None found** |
| Pristine tree | Byte-identical after the run (500 files, SHA-256). `app/config.php` was never created |

## 1. Validation script

```
$ DB_USER=root DB_PASS= bash claude_code_validate_v1_171_7.sh flxcorehc-v1_171_7.zip      → SCRIPT EXIT=0 (~2 min)
00_environment.txt: app/config.php count: 0
02_exit_code.txt:   runtime_check exit code: 0
03_summary.txt:     PASS lines: 659 | no FAIL/SKIP lines | config.php present after run? → No such file or directory
PASS — crawl as owner: pages=220 codes={200: 219, 302: 1}
PASS — crawl as manager: pages=220 codes={200: 218, 302: 1, 403: 1}
PASS — crawl as staff: pages=220 codes={200: 210, 403: 5, 302: 5}
PASS — crawl as coord: pages=220 codes={200: 210, 403: 5, 302: 5}
PASS — crawl as readonly: pages=220 codes={200: 196, 403: 18, 302: 5, 404: 1}
PASS — crawl as restricted: pages=220 codes={200: 211, 403: 6, 302: 2, 404: 1}
PASS — crawl as padmin: pages=220 codes={200: 218, 302: 2}
STRAY FILES: PASS (this run created no .notif-sweep-* in app/storage; 0 pre-existing)
All automated stages PASS.
```

The script's own files are in `script_output/`. Stage 4 (`harness.sh`) is still reported as "not present (dev-only)".

## 2. Checklist

Who ran each item:
- **C** = runner instance `qa17_C`, port 8131
- **D** = runner instance `qa17_D`, port 8151
- **Me** = my own installs `i17_http` and `i17_https`

### Step 1 and 3k-6 (installer)

| # | Label | Evidence |
|---|---|---|
| Installer POST without `_csrf` | EXECUTED — PASS | (Me) "Your session expired or this request did not come from the installer page". No config written, 0 tables created. |
| Installer POST with an altered `_csrf` | EXECUTED — PASS | (Me) Same message. No config written, 0 tables created. |
| Fresh install | EXECUTED — PASS | (Me) "✓ Installation complete — created 89 tables and your administrator account." `schema_migrations` has 132 rows. `group_npi` and `clia_number` each return one row. `pr_cme` note: has_10h=0, UNVERIFIED=1. |
| **3k-6** `mail_from` has no port | EXECUTED — PASS | (Me) http install: `mail_from='no-reply@127.0.0.1'`, `app_url='http://127.0.0.1:8141'`, `hsts=false`. https install (`X-Forwarded-Proto: https`): `mail_from='no-reply@127.0.0.1'`, `app_url='https://…'`, `hsts=true`. Code: `install.php:192`. |

### Step 3k (new)

| # | Label | Evidence |
|---|---|---|
| 3k-1 | EXECUTED — PASS | (C, colours computed in the browser) "Support request denied." after the owner denies → `toast ok` rgb(31,157,99). "0 provider(s) imported, 1 with errors." → `toast error` rgb(221,79,93). Mixed import "1 provider(s) imported, 1 with errors." → `toast warn` rgb(213,131,31). "No active link with that organization for this provider." → `toast error`. *The all-bad import and the "No active link" message can only be reached by tampering with the page (§4-1).* |
| 3k-2 | EXECUTED — PASS | (C) "Front Desk Review" is created. These are all refused with "A role with that name already exists." (red): "Front␣␣Desk Review", "Front⇥Desk Review", "Front<NBSP>Desk Review", "Admin", "Viewer", "Manager". |
| 3k-3 | EXECUTED — PASS | (C) Clearing first and last name → "First and last name are required." (red), record unchanged in UI and SQL. "not-an-email" → "Enter a valid e-mail address." (red), unchanged (the browser's own field validation blocks it first; I bypassed it with `form.submit()`). Changing only the role title → "Provider updated.", and only `role_title` changes. |
| 3k-4 | EXECUTED — PASS | (C) With 2FA on and nothing pending, a refused `2fa_confirm` (wrong code or the current valid code) → `/profile`, red toast "Two-factor authentication is already on. Turn it off first…". No setup key, no `otpauth://` link, and the status chip reads "On". *See R5 for the case where an enrolment is still pending.* |
| 3k-5 | EXECUTED — PASS | (C) See R4. |
| 3k-6 | EXECUTED — PASS | (Me) See above. |

### Step 4.3 (corrected text), 3h-3, and the 3i / 3j regression pass (runner D)

| # | Label | Evidence |
|---|---|---|
| 4.3 FL tenant | EXECUTED — PASS | CE chip "0 → 2.5 hrs · last 24 months". Checklist row "renews every 24 mo". Readiness row "Per licensing-board cycle". No NIPR, "I'd want this", NPN or Producer. "CME — AMA PRA Category 1" is present. |
| 4.3 PR tenant | EXECUTED — PASS | CE chip "0 → 2.5 hrs · last 36 months". Checklist row "renews every 36 mo". Readiness row "PR recertification cycle: 36 months". |
| 3h-3 (new path) | EXECUTED — PASS | `/credentials/requirements`: the PR CE note says UNVERIFIED and does not contain "10 hours" or "6 hours". Enfermería appears on `/regulators`. The drug-test item says "Applicability UNVERIFIED". |
| 3i-1 | EXECUTED — PASS | After a suspend, the next request lands on `/login` showing "You have been signed out…" once. |
| 3i-2 | EXECUTED — PASS | Refusals are red: promote, wrong password, invalid e-mail, and a support-reset refusal on a non-demo tenant. Successes are green: role created, provider added, outbox flushed. |
| 3i-3 | EXECUTED — PASS | GET requests leave the 6 outbox rows `queued` with 0 attempts. The POST flush sends them (6 sent). |
| 3i-4 | EXECUTED — PASS | A read-only user can enable 2FA. They see the setup key and a tap-to-add link; no QR is shown (owner's decision). Writes are refused. |
| 3i-5 | EXECUTED — PASS | If the 2FA step is abandoned, no `known|` row is written. A complete sign-in writes one. |
| 3i-6 | EXECUTED — PASS | FL provider with an OCS row: no OCS panel. PR provider: the panel shows. |
| 3i-7 | EXECUTED — PASS | JLDM is listed under "State board" and Enfermería under "Licensing board". |
| 3i-8 | EXECUTED — PASS | After a new PR sign-up, the PR CE note still says UNVERIFIED. |
| 3i-9 | EXECUTED — PASS | "Master Admin" appears once. Creating one role writes 1 `role_created` row. `tenant_owner` never appears. |
| 3i-10 | EXECUTED — PASS | 9 evidence rows, none blank. |
| 3i-11 | EXECUTED — PASS | Importing the template in a clean tenant: "4 Will be created, 0 Skipped, 0 Errors". |
| 3j-1 | EXECUTED — PASS | Changing a single field leaves name, NPI and e-mail unchanged. `/whatever` returns 404 and changes nothing. |
| 3j-2 | EXECUTED — PASS | Same results as 3i-2. |
| 3j-3 | EXECUTED — PASS | `2fa_start` is refused with a red message. A `2fa_enabled` audit row is written. |
| 3j-4 | EXECUTED — PASS | The 4 template NPIs are distinct and valid. |
| 3j-5 | EXECUTED — PASS | "front desk review" is refused. |

## 3. "Try to break" list

| # | Item | Label | Evidence |
|---|---|---|---|
| **R1** | Role names | **EXECUTED — FAIL (renaming)** | **Creating** a role is correctly protected: double space, tab, NBSP, zero-width space, leading/trailing spaces, "Admin", "Viewer", "Manager", "Master Admin", "Provider (self-service)", "Staff", "Read-only", "Coordinator" and "FRONT DESK REVIEW" are all refused, and a different name still works. **Renaming an existing role (Edit role → Save) has no collision check at all.** Verbatim below the table. |
| **R2** | Provider edit | **EXECUTED — FAIL (sub-part d, plus invisible-character names)** | (a) Clearing first and last name is refused, record intact. (b) "not-an-email" is refused. (c) Changing only the role title works. (e) Saving the full form works. **(d) Editing an organization's `org_name` silently erases `license_code`**, and **a name made only of an NBSP or a zero-width space is accepted**. Verbatim below the table. |
| R3 | CSV import toasts | EXECUTED — PASS | **Providers:** all bad → error rgb(221,79,93); mixed → warn rgb(213,131,31); "2 provider(s) imported." → ok rgb(31,157,99). **Credentials:** all bad → error; mixed → warn; all good → ok. |
| R4 | Abandoned 2FA does not reset `acct|` | EXECUTED — PASS | Value of `acct|readonly@…` at each step: 10 failures → **10**. Correct password then abandon 2FA from an unknown address → **10**. Correct password then wrong 2FA code → **10**. Correct password then abandon from a known address → **10**. Two more failures → **12**, so a never-seen address now gets "Too many sign-in attempts…". A complete sign-in from a known address → **row deleted**. After that, the never-seen address can reach `/login/verify` again. |
| **R5** | Refused `2fa_confirm` lands on Profile with no form | **EXECUTED — FAIL (stale pending secret)** | Normal case is correct (3k-4). **But when an enrolment was started in one session and 2FA was then enabled from another session, a refused confirm in the first session still renders "Setup key", the `otpauth://…secret=` link and the confirm form.** The status chip says "On". No secret is swapped and the stored secret is unchanged. The refusal is shown as `.alert.danger`, which has no CSS rule, so it renders as plain dark text rather than red. Verbatim below the table. |
| Installer `mail_from` | No port | EXECUTED — PASS | See 3k-6. |
| QR | — | Not reported | Owner's decision: planned for v1.172. |

**R1 verbatim** (as owner, Edit role → rename → Save):
```
"Front Desk Review 2" -> "Front Desk Review"          → toast ok "Role updated."
"Regular"             -> "Admin"                      → toast ok "Role updated."
"Admin"               -> "Master Admin"               → toast ok "Role updated."
"Master Admin"        -> "Front  Desk<NBSP>Review "   → toast ok "Role updated."
roles after (slug | name | hex):
front_desk_review    Front Desk Review       46726F6E74204465736B20526576696577
front_desk_review_2  Front Desk Review       46726F6E74204465736B20526576696577
regular              Front  Desk<NBSP>Review 46726F6E7420204465736BC2A0526576696577
front_desk_rev_ew    Front Desk Revıew
```
- The Roles page now lists "Front Desk Review" three times.
- Code: `app/src/RoleService.php:296` (`update()`) trims the name and checks its length, but does not run the duplicate check that `create()` has at lines 268–274.
- Also accepted on create: "Front Desk Revıew" (dotless ı) and "Regular" (the built-in role is named "Regular User").

**R2(d) verbatim** (organization "Bahia Clinical Laboratory", only `org_name` changed):
```
toast ok "Provider updated."
SQL diff: org_name "Bahia Clinical Laboratory" → "Bahia Clinical Laboratory QA17";  license_code "clia" → NULL
```
- Cause: the "Licence held" `<select>` in `app/views/holders/form.php:40-43` does not offer `clia`. Saving any lab record therefore posts an empty `license_code`, which clears it.

**R2 invisible-character names:**
- First name set to a single NBSP → "Provider updated.". Hex of `first_name`: `C2A0`.
- Raw POST with `first_name=<U+200B>` (zero-width space) → "Provider updated.". Hex of `first_name`: `E2808B`.
- Ordinary whitespace-only names ("   ", tab) are correctly refused.

**R2, other raw POSTs** (reported as observed, without a pass/fail verdict):
- `npi=` (empty) → "Provider updated." and the NPI is cleared.
- `email=` (empty) → "Provider updated." and the e-mail is cleared.
- `npi=<spaces>` or `email=<spaces>` → nothing changes.
- An invalid NPI → refused with the check-digit message.

**R5 verbatim:**
1. Session A (readonly@, 127.0.0.1) starts an enrolment and does not confirm it.
2. Session B (readonly@, 127.0.0.2) enrols fully; `totp_enabled` = 1.
3. Session A then submits a wrong code:
```
url: /profile  toast: []
.alert.danger: "Two-factor authentication is already on. Turn it off first ..." color rgb(20,26,36) bg rgba(0,0,0,0)
has "Setup key": true | otpauth://: true | secret= : true | 2fa_confirm form: true | chip: On
```
- Submitting A's valid code gives the same result, with no swap.
- The pending secret stays in session A until it signs out.
- The plain-text (non-red) message also appears with 2FA off: a wrong code shows "That code did not match…" in rgb(20,26,36).

## 4. Surprising observations (not in the checklist; nothing changed)

1. **Two toasts can only be reached by tampering with the page.**
   - The all-bad provider import preview shows "Nothing to import…" and has no commit button, so the "all bad" toast needs an injected form.
   - The credential import shows a disabled "Import 0" button.
   - "No active link…" needs a tampered `link_id`.
   - Related: a commit where every row is a duplicate ("0 provider(s) imported, 1 skipped.") is shown **red** even though nothing in the file was wrong.
2. **Global roles skip the duplicate check.** In the platform admin's global roles page (`/admin/roles`), "Admin" was created a second time (slug `admin_2`) and "Front␣␣Desk Review" was accepted. Both were deleted through the app afterwards.
3. **Platform-admin entry into a demo organization changes the owner's sign-in throttle.**
   - Entering demo Coastal from 127.0.0.40 deleted demo-admin's `acct|` counter.
   - It also created `known|demo-admin@coastalcred.example|127.0.0.40`, so the admin's address becomes a "known place" for the owner.
   - From reading the code, entering through a support session calls the same function. I did not run that path.
4. **Demo organizations skip the support-grant check.** On demo Coastal, the platform admin resets a password without a support session ("Password updated.", green). On non-demo organizations this is correctly refused. This appears intentional.
5. **"Provider (self-service)" appears twice** in the tenant's Roles list (global `g-provider` and tenant `producer`). The global "Admin" and "Viewer" roles are also listed.
6. **PR-only tenant details.**
   - The license checklist row says "renews every 24 mo" while CME says 36.
   - `/regulators` also lists "Florida Board of Medicine (FL)".
   - An FL-only tenant's catalog does not show the PR CE row at all, so 3i-8 can only be checked from a PR organization.
7. **Every full-form save re-encrypts NPI and e-mail.** The ciphertext changes but the decrypted values stay the same.
8. **A wrong 2FA code counts under `2fa|<user>`, not `acct|`.**
9. **No server errors.**
   - No 5xx responses.
   - No PHP warnings, notices, deprecations or fatals.
   - `error_log` has 0 rows on every instance.
   - Note: with a router script, `php -S` logs only static files, so status codes were checked from the client side.

## 5. Test harness and fixtures (test instances only)

- **Router.** `php -S` ignores `.htaccess`, so the browser runners used a router outside the app (`router17_{C,D}.php`) that does the same job:
  - real files are served directly;
  - `/app`, `/database`, `/cron`, `/tools`, `/tests` and `/docs` return 403;
  - everything else goes to `index.php`.
- **SQL writes** (runner D only; runner C made none):
  - 1 row in `ocs_profiles`;
  - deleted one `known|` row from `login_attempts` (for 3i-5).
- **Instance config:** runner D turned `allow_open_registration` on for two sign-ups, then restored it; the file is identical to the original.
- **Mail:** a local SMTP sink on 127.0.0.1 (runner D).
- **Evidence left in place on purpose** (runner C):
  - the renamed duplicate roles;
  - Bahia's `license_code` cleared to NULL.
- **Databases:**
  - created for this run: `flx_i17_http`, `flx_i17_https`, `flx_qa17_c`, `flx_qa17_d`;
  - plus the databases the test suites create and drop themselves.

## Files

- `README.md` — this summary.
- `script_output/` — output files 00–04 written by the validation script, plus `driver_console.txt`.
- `logs/installer_config_http_https.txt` — `app_url`, `hsts` and `mail_from` written by each installer run.
- `screenshots/` — 32 PNGs:
  - `C_*`: 3k and R1–R5;
  - `D_*`: 4.3, 3i and 3j.
