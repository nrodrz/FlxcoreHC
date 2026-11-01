# Kickoff — FlxCoreHC (healthcare credentialing **tracker**)

Read this first, then `PRODUCT.md` (what the app is) and `docs/CHANGELOG.md` (history). Current version: see `FLXCOREHC_VERSION` in `app/bootstrap.php`.

## Product positioning (decision on record)
FlxCoreHC is a **tracker**: it records who holds which licences/credentials, what is expiring, what is missing, and keeps evidence and an audit trail. It is **not** NCQA-grade credentialing: no NPDB query, no credentialing-committee decision record, no NCQA timeliness rules (180-day / 36-month), no primary-source verification automation, and it does **not** submit to CAQH/PECOS/payers. Those are a *plan only* (`docs/architecture/NCQA_CREDENTIALING_ROADMAP.md`). Marketing and UI must not imply them. Use the label **"Production Candidate"**, never "Production Ready".

## Hard constraints
- PHP 8.3 (tested on 8.4) + MariaDB on cPanel shared hosting; no framework, no Composer runtime dependency.
- Multi-tenant shared DB. Tenant data goes through `TenantRepository` (forces `tenant_id`, provider need-to-know in SQL, column encryption). `tools/check_isolation.sh` must pass (raw `Database::pdo()` in `index.php` may not grow beyond `tools/isolation_baseline.txt`).
- Encrypted at rest (per-tenant AAD): provider NPI/NPN/e-mail, credential numbers, address line1/line2/phone, TOTP secrets, uploaded documents. Keys live in `app/config.php`; `KeyBackup` + `database/restore_keys.php` recover them.
- Regulatory content must be verified against primary sources. Puerto Rico is a first-class jurisdiction. The Puerto Rico facts in migration 126 are **unverified** until confirmed by the owner or counsel.

## Roles (who does what)
- **Claude Code** — develops in this repository (code, tests, docs) and ships each release as a zip whose root is the app root (upload to the server's root folder). Also runs the runtime and browser checks and reports evidence.
- **Claude.ai** — optional second reviewer; no longer the sole code owner (changed by the owner at v1.172.13).
- **ChatGPT** — second-opinion reviewer; its advice is filtered and dispositions reported.
- **Owner (Neftali Rodriguez)** — approves scope. No new features without approval.

## Status labels (use exactly)
`EXECUTED — PASS` · `EXECUTED — FAIL` · `STATICALLY REVIEWED` · `REQUIRES EXTERNAL RUNTIME VALIDATION` · `REQUIRES BROWSER VALIDATION`.

## Release gate
Follow `docs/HANDOFF.md` §4 and `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` (`bash tools/runtime_check.sh`, then the browser spot-checks).
