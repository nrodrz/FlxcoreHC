# FlxCoreHC v1.171.10 — final closeout validation (Claude Code independent execution)

**Date:** 2026-10-01 · **Role:** runner/reporter only — no app code, migrations, schema, tests or docs modified. **Evidence classification: Claude Code independent execution** (nothing below is taken from Claude.ai's own sandbox results).

**Environment:** PHP 8.4.19 CLI (pdo_mysql, sodium, openssl, **intl loaded**) · MariaDB 10.11.14 (throwaway) · Python 3.11.15 · Playwright + headless Chromium · `php -S`. For the no-intl checks: `php -n -d extension=mysqlnd -d extension=pdo -d extension=pdo_mysql -d extension=mbstring -d extension=fileinfo` (verified from inside the server process: `intl=no`, every other required extension present).

**Bundle:** script identical to v1.171.8's apart from the version; checklist in the bundle identical to `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` in the zip; only Steps 3m and 3n are new.

## Verdict

**Cycle closeout criteria met.** The complete automated suite is clean (exit 0, 689 PASS, 0 FAIL/SKIP, 220-page crawls, no stray files, pristine tree byte-identical), every new or re-checked checklist item passes except one doc-wording mismatch (3m-1, see below), and the adversarial probing found **no new material security or integrity defect**. It did find four low-severity validation/consistency residues (§5) that should go on the backlog, none of which crosses a tenant boundary, grants privilege, or loses data a real user would enter.

| Area | Result |
|---|---|
| `claude_code_validate_v1_171_10.sh` | **exit 0** · 689 PASS · "no FAIL/SKIP lines" · 7 crawls × **220 pages** · "STRAY FILES: PASS" |
| Step 1 (intl present) | PASS: CSRF refused; 89 tables; 132 migrations; `mail_from` no port; `pr_cme` UNVERIFIED |
| Step 3n (incl. optional no-intl parts) | **3 / 3 PASS** |
| Step 3m | **3 PASS · 1 FAIL (3m-1, literal doc example only)** |
| Step 3l regression | **4 / 4 PASS** |
| TextNorm adversarial, roles (tenant + global) | **no escape** |
| TextNorm adversarial, holder/tenant names | 10 listed codepoints + mixes + empty: all refused/cleaned (PASS); **4 residues outside the listed set** (§5) |
| Pristine tree | unchanged (513 files SHA-256); `app/config.php` never created |

## 1. Validation script
```
$ DB_USER=root DB_PASS= bash claude_code_validate_v1_171_10.sh flxcorehc-v1_171_10.zip   → SCRIPT EXIT=0
02_exit_code.txt: runtime_check exit code: 0
03_summary.txt:   PASS lines: 689 | no FAIL/SKIP lines | app/config.php present after run? → No such file or directory
PASS — crawl as owner: pages=220 codes={200: 219, 302: 1}
PASS — crawl as manager: pages=220 codes={200: 218, 302: 1, 403: 1}
PASS — crawl as staff: pages=220 codes={200: 208, 403: 5, 302: 7}
PASS — crawl as coord: pages=220 codes={200: 208, 403: 5, 302: 7}
PASS — crawl as readonly: pages=220 codes={200: 191, 403: 22, 302: 7}
PASS — crawl as restricted: pages=220 codes={200: 211, 403: 6, 302: 2, 404: 1}
PASS — crawl as padmin: pages=220 codes={200: 218, 302: 2}
STRAY FILES: PASS (this run created no .notif-sweep-* in app/storage; 0 pre-existing)
All automated stages PASS.
```
Full output: `script_output/01_runtime_check_output.txt`. Stage 2 still prints the non-failing raw-SQL warning noted in v1.171.8 (one false positive at `index.php:2641`).

## 2. Step 1 and Step 3n-1 (installer / intl) — run by me

| Check | Label | Evidence (`logs/3n1_installer_intl.txt`) |
|---|---|---|
| Installer refuses POST without `_csrf` | EXECUTED — PASS | "Your session expired or this request did not come from the installer page"; no config; 0 tables |
| Fresh install, intl present | EXECUTED — PASS | Requirements row `PHP extension: intl (Unicode name comparison (NFKC) — v1.171.10) | loaded`; "✓ Installation complete — created 89 tables…"; `schema_migrations` 132; `mail_from='no-reply@127.0.0.1'`; `pr_cme` has_10h=0 / UNVERIFIED=1 |
| 3n-1 installer **without** intl (optional) | EXECUTED — PASS | Requirements row shows `intl … missing` (every other extension `loaded`); valid POST → "Requirement not met: PHP extension: intl (Unicode name comparison (NFKC) — v1.171.10)"; `app/config.php` absent; 0 tables |
| 3n-1 production **without** intl (optional) | EXECUTED — PASS | Configured install with `app_env=production` (instance config, temporarily edited and restored), served by the no-intl PHP: `/`, `/login`, `/holders`, `/install.php` all **HTTP 503 text/plain "FlxCoreHC cannot start: PHP extension intl (class Normalizer) is required: FlxCoreHC compares names with Unicode NFKC. Enable ext-intl in PHP (cPanel → Select PHP Version → intl)."**; server log `[FlxCoreHC] prerequisite missing: …`. Control with intl: `/` → 302 `/login`, `/login` → 200. Guard: `app/bootstrap.php:180-185`. |

## 3. Steps 3n-2/3, 3m, 3l (runners C and D)

| # | Label | Evidence |
|---|---|---|
| 3n-2 Organization name | EXECUTED — PASS | `name=U+00A0` → red "An organization name is required.", unchanged. `"Acme"+U+00AD+U+3000+U+3000+"Health"` → stored HEX `41636D65204865616C7468` = "Acme Health". |
| 3n-2 Brand name / Entity legal name | EXECUTED — PASS (note) | Both store "Acme Health" cleaned. A value of only NBSP is **saved as NULL with a green toast** (optional fields are cleared, not refused) — the doc's "likewise" is ambiguous; the clean-storage half holds, the refusal exists only for Organization name. |
| 3n-3 Names still differ | EXECUTED — PASS | "Office Manager" and "Office Managers" both created; "O"+U+FB00+"ice Manager" → "A role with that name already exists." |
| **3m-1** Role create/rename | **EXECUTED — FAIL (literal example)** | Zero-width-only and "x"+ZWSP → "Enter a role name (2–80 characters)."; Cyrillic е, full-width, math-bold, braille, ideographic space → "already exists"; re-casing works; UI = raw POST. **Failing part:** the doc's own example "Rename­A" = `Rename`+**U+00AD**+`A` (soft hyphen, no space) is **accepted as a new role "RenameA"** (HEX `52656E616D6541`) beside "Rename A". `TextNorm::clean()` deletes the soft hyphen, giving "RenameA", which folds to `renamea` ≠ `rename a`. With a space present (`Rename`+U+00AD+` A`) it is refused. The rule is self-consistent (the two names render differently); it is the doc example that doesn't match the implemented rule. |
| 3m-2 Global roles | EXECUTED — PASS | "Manager"/"manager"/"Master Admin"/"Admin"/"Viewer" refused on create and rename; look-alike battery refused; two new names created clean; tenant Roles page lists each once with a working **View**. *(Also renders a Delete button for global-custom roles; POSTing it → "Role not found.", row intact — cosmetic, §5.)* |
| 3m-3 Provider/org create | EXECUTED — PASS | NBSP/ZWSP names → "First and last name are required."; org empty/ZWSP/U+3000 → "An organization name is required."; no rows. "Ana"+U+00AD → stored "Ana". |
| 3m-4 Rename-only keeps caps | EXECUTED — PASS | Raw name-only `action=update` → capabilities 3 → 3; form with `caps_form=1` and no boxes → 3 → 0. |
| 3l-1 | EXECUTED — PASS | "rename  a" / "Manager" refused on rename, name unchanged; "RENAME b" re-case works. |
| 3l-2 | EXECUTED — PASS | Bahia rename → "Organization updated."; `license_code` still `clia`, select shows it. |
| 3l-3 | EXECUTED — PASS | NBSP first name on edit → "First and last name are required.", row unchanged. |
| 3l-4 | EXECUTED — PASS | Stale session confirm → red "already on" on /profile, no setup form; B's secret works. |

## 4. TextNorm adversarial — escape list (codepoint · route · outcome)

**Roles (tenant `/organization/roles`, global `/admin/roles`, create + rename): no escape.** Refused by length: U+200B, U+2800, U+00AD, U+034F, U+3164, U+115F/1160, U+180E, U+2060, U+FEFF, U+200E/200F, U+3000, U+2003/202F, "x"+ZWSP, U+FE0F, U+0300, U+FFFC, "0". Refused as duplicates (fold): full-width, Cyrillic а/ѕ, math-bold (U+1D40C…), superscript ², enclosed ①, "Master"+U+3000+"Admin", trailing ZWSP, SHY inside a word. Stored clean: ZWSP/SHY/tab/newline/U+2003 inside names, lead/trail spaces trimmed.

**Holder and tenant names — the 10 requested codepoints, 7 mixes and `""`: all refused at all six entry points** (quick-add individual/org, onboarding individual/org, edit individual/org, Settings name) plus 22 further invisibles (U+1160, U+FFA0, U+2062/2063/206F, U+061C, U+200C/200D, U+E0001, U+FFF9, U+1D173, U+17B4, U+2028/2029, U+0085, U+000B, U+1680, U+0000…). Clean expectations held ("Ana"+SHY+"Maria" → "AnaMaria", U+3000 runs → one space, "Jo"+ZWSP+"hn" → "John").

**Residues found outside the requested set (none in roles):**

| Codepoint / input | Routes | Outcome |
|---|---|---|
| U+FE0F, U+FE00, U+E0100 (variation selectors), U+0300, U+0301 (combining marks), U+FFFC | `/holders` create (ind. first/last, org), `/holders/onboard`, `/holders/{id}` edit, `/settings` name + brand, `/entity` legal name | **accepted as the entire name**, stored (`EFB88F`, `CC80`, …); the providers list shows an empty-looking name cell. Cause: `TextNorm::clean()` strips `\p{Cf}`/`\p{Cc}` but not `\p{Mn}`. Roles are protected only by the 2-char length rule. |
| `"0"` (U+0030) | `/holders` create/onboard/edit (first, last, org_name), `/settings` brand, `/entity` legal | **success toast, stored NULL** — `HolderInput.php:62` ends in `?: null`, and PHP treats `"0"` as false. An org named "0" ends up with no name. (`/settings` name stores "0" correctly; roles refuse by length.) |
| any invisible/space in a provider name | `/holders` create → auto portal invite; `/team` invite | `users.full_name` stores the **raw, uncleaned** POST (U+00AD, U+200B, U+3000, U+00A0, U+1680, U+0000, U+FE0F persisted) while the holder row is cleaned. |
| e + U+0301 (decomposed) | roles | stored decomposed (`…65CC81`), fold matches the composed form — visible accent, consistency only. |

## 5. Surprising observations (nothing changed)
1. Tenant Roles page shows a **Delete** button on global-custom (View-only) roles; the POST is refused ("Role not found.") and the row survives — boundary intact, affordance wrong.
2. Optional fields (brand name, legal name) silently clear to NULL on invisible-only input instead of refusing.
3. Quick-add provider still creates the holder when the portal e-mail already belongs to another user ("Provider added.", no invite) — no duplicate-e-mail refusal at provider level.
4. `clean()` does not NFC-compose stored names.
5. Logs clean on all three instances: no PHP warnings/notices/deprecations/fatals, no 5xx, `error_log` 0 rows.

## 6. Harness and fixtures (test instances only)
- Browser runs used a test-only router outside the app (`router110_{C,D}.php`) emulating `.htaccess`; the 503 check used `router110_prod.php` rooted at my install.
- Instance config of `i110_intl` had `app_env` set to `production` for the 503 check and was restored to `dev` (verified).
- No SQL writes by any runner this round; all fixtures went through the app (≈50 probe holders and several probe roles remain in `flx_qa110_c/d` as evidence). Databases: `flx_i110_intl`, `flx_i110_nointl` (empty), `flx_qa110_c`, `flx_qa110_d`, plus the suites' own.
- A note on my own process: two of my early no-intl attempts were invalid (a stale server answered the port); they were discarded and re-run, and only the re-runs are reported above.

## Files
- `README.md` — this summary · `script_output/` — the script's 00–04 outputs + `driver_console.txt` · `logs/3n1_installer_intl.txt` — installer/503 evidence · `screenshots/` — 13 PNGs (`C_*` roles, `D_*` names/3n/3l).
