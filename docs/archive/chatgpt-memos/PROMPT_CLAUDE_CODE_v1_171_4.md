# Prompt for Claude Code — independent validation of FlxCoreHC v1.171.4 (paste as-is)

You are the **independent runner and reporter**. You do **not** edit code, tests, SQL or docs, and you never fix to green. Label every result with exactly one of: EXECUTED — PASS · EXECUTED — FAIL · STATICALLY REVIEWED · REQUIRES EXTERNAL RUNTIME VALIDATION · REQUIRES BROWSER VALIDATION. The release is a "Production Candidate". Claude.ai's own sandbox result (589 PASS, 0 FAIL) is **not** your result — report only what you ran.

**Setup**
1. Unzip `flxcorehc-v1_171_4.zip` into an empty folder; confirm `app/config.php` is absent (`unzip -l … | grep -c app/config.php` → 0).
2. Requirements: PHP 8.3+ (pdo_mysql, sodium or openssl), python3, mysql client, a MariaDB/MySQL server where you may create/drop `flx_*` databases. State PHP and DB versions.
3. `export FLX_CONFIG=/tmp/flx_base_config.php` containing `<?php return ['db_host'=>'127.0.0.1','db_port'=>3306,'db_user'=>'root','db_pass'=>''];` (your credentials). `app/config.php` must stay absent/untouched.

**Step 1 — fresh install** per `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` Step 1 (web installer, empty DB). Confirm migrations through **132** are recorded in `schema_migrations`, and that the installer rejects a POST without `_csrf`.

**Step 2 — automated:** `bash tools/runtime_check.sh`; paste the complete output. Expected: all stages PASS. Also `php database/encrypt_existing_addresses.php --dry-run`.

**Step 3 — browser/DB checks:** Steps 3b, 3c, 3d, 3e, 3f, 3g and the new **3h** in `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md`, plus Step 4. Give a verdict and evidence for each numbered check.

**Step 4 — specifically requested by the reviewer (report numbers, don't interpret):**
- Address back-fill on partially migrated rows (3h-5): show before/after HEX of the ciphertext columns.
- Memory characterization (3h-6): `FLX_CONFIG=<test config for a flx_* database> php -d memory_limit=256M tools/dump_memory_check.php 100000 400` and once at `128M` with `50000` rows; report database size, memory_limit, peak memory, result.
- Shared-NAT login scenario: owner signs in from IP A; 8 wrong attempts from IP A; owner's correct password from IP A, then from IP B. Report exactly what happens.

**Report format:** table `check · label · evidence`; verbatim output for anything not PASS; anything surprising noticed but not changed; PHP/DB versions. No code changes proposed or applied.
