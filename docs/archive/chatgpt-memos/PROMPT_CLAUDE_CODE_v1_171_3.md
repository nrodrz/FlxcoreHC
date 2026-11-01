# Prompt for Claude Code — runtime validation of FlxCoreHC v1.171.3 (paste as-is)

You are the **runner and reporter** for FlxCoreHC v1.171.3. You do **not** edit code, tests, SQL or docs, and you never "fix to green". If something fails, capture the exact output and stop that step. Report with these labels only: EXECUTED — PASS · EXECUTED — FAIL · STATICALLY REVIEWED · REQUIRES EXTERNAL RUNTIME VALIDATION · REQUIRES BROWSER VALIDATION. Call the release a "Production Candidate", never "Production Ready".

**Setup**
1. Unzip `flxcorehc-v1_171_3.zip` into an empty folder. Confirm `app/config.php` does NOT exist in the zip (`unzip -l … | grep -c app/config.php` → 0).
2. You need PHP 8.3+ with pdo_mysql, sodium or openssl, python3, and a MariaDB/MySQL server you can create databases in (the test runners create and drop `flx_*` throwaway databases; they never touch another database).
3. Create a config for the runners only: `export FLX_CONFIG=/tmp/flx_base_config.php` containing `<?php return ['db_host'=>'127.0.0.1','db_port'=>3306,'db_user'=>'root','db_pass'=>''];` (adjust credentials). The runners write their own temporary configs; `app/config.php` must remain absent/untouched.

**Step 1 — Fresh install** (follow `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` Step 1): run the web installer against an EMPTY database; confirm it shows the key-backup download, rejects a POST without the `_csrf` field, and that all migrations (through 131) are recorded in `schema_migrations`.

**Step 2 — Automated:** `bash tools/runtime_check.sh` — paste the complete output. Expected: every stage PASS (Claude.ai's own run: 570 PASS lines, 0 FAIL, PHP 8.4 / MariaDB 10.11). Also run `php database/encrypt_existing_addresses.php --dry-run` and `php database/encrypt_existing_files.php --dry-run` on the installed DB.

**Step 3 — Browser spot-checks:** do Steps 3b, 3c, 3d, 3e, 3f, **3g** and 4 in `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md`. Step 3g is new in this release (lockout exemption, invite link, installer CSRF, no mail on GET, address encryption, sealed database backup, cases/audit wording, https calendar feed, config not swapped). For each numbered check give a verdict and evidence (screenshot description, HTTP status, or SQL result).

**Step 4 — Extra probes (report only):**
- Try to log in as the demo owner from a second IP 13 times with a wrong password, then log in normally from the usual IP.
- Check in SQL that `addresses.line1/line2/phone` are NULL and `*_enc` are binary for a newly added address; that `users.invite_token` for pending invites has length 64.
- Seal a DB dump with a passphrase, decrypt it with `database/decrypt_dump.php`, `gunzip -t` the result, and attempt a wrong passphrase.

**Report format:** a table `check · label · evidence`, then the verbatim failing output for anything not PASS, then anything surprising you noticed but did not change. Include PHP and DB versions. Do not propose or apply code changes.
