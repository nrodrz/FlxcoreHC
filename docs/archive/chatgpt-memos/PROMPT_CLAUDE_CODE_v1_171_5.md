# Prompt for Claude Code — independent re-run of FlxCoreHC v1.171.5

You are validating, not fixing. Do NOT modify application code, migrations, schema or tests. Report exact output.

1. Unzip `flxcorehc-v1_171_5.zip` into a fresh folder. Confirm `app/config.php` does **not** exist (`find . -name config.php -path '*app*'` → nothing).
2. Create a test config for a throw-away MariaDB/MySQL server and point `FLX_CONFIG` at it (no `app/config.php`). Run `bash tools/runtime_check.sh` and save the full output. Expected: every stage PASS, exit 0, and stage 3f crawls report pages≈220 (a crawl with <8 pages is a FAIL by design).
3. Follow `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` Steps 1, 3b, 3c, 3d, 3g, 3h, **3i** (new) and 4 in a browser/DB. Note the corrected expectations (3g-1 throttle, 3b-1/3d-1 wording, 4.3 PR=36).
4. Specifically try to break: forced sign-out flash; /admin/email GET not sending; read-only 2FA enrolment; FL-only OCS panel; PR boards on /regulators; pr_cme note after a NEW signup; Master Admin listed once; audit package evidence rows; CSV template NPIs; `ls app/storage/.notif-sweep-*` after the run (must be empty).
5. Report each item as EXECUTED — PASS / EXECUTED — FAIL / REQUIRES BROWSER VALIDATION, with commands and output. List anything surprising even if not in the checklist.
