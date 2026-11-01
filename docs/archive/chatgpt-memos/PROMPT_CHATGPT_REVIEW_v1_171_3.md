# Prompt for ChatGPT — review of FlxCoreHC v1.171.3 (paste as-is, attach the files listed at the end)

You are an independent senior reviewer (application security + PHP + healthcare-credentialing compliance). I am sending you release v1.171.3 of FlxCoreHC, a multi-tenant PHP 8.3 / MariaDB SaaS (no framework, cPanel hosting) for healthcare credentialing **tracking**.

**Product positioning (fixed decision):** it is a *tracker*, not NCQA-grade credentialing. NPDB queries, credentialing-committee decisions, NCQA timeliness rules and primary-source verification automation are intentionally NOT implemented (plan only in `docs/architecture/NCQA_CREDENTIALING_ROADMAP.md`). Do not recommend building them as defects; DO flag any UI/doc text that implies they exist.

**How we work:** Claude (claude.ai) is the only one who writes code. Claude Code only runs tests. You review. I will filter your advice and report dispositions, so be specific and honest about confidence.

**What I need from you**
1. Read `UPDATE_FOR_CHATGPT_v1_171_3.md` first, then the code it points to. Challenge every item in its "Deliberately NOT done" list and every claim marked EXECUTED.
2. Find real defects. For each finding give: severity (Critical/High/Medium/Low), exact file + function, a concrete failing scenario (inputs → wrong outcome), and a minimal suggested fix. No generic best-practice lists.
3. Separate clearly: (a) bugs/security issues in code that exists, (b) missing tests, (c) documentation/positioning overclaims, (d) things you could not verify without running code.
4. Do not invent facts about Puerto Rico / Florida regulations. If you are not sure a regulatory statement in migration 126 is correct, say "unverified" and cite the primary source you would check.
5. Do not suggest new features. If something is out of scope for a tracker, say so.
6. Finish with a table: finding · severity · confidence (high/med/low) · "needs runtime proof?" (yes/no).

**Focus areas (highest value first)**
- Invite-token hashing/legacy fallback and consumption (`TeamService`, `SuperadminService`).
- Login throttle exemption (`RateLimiter::seenWithin`, `AuthService::attemptLogin`).
- Address encryption (`AddressRepository`, migration 131, `database/encrypt_existing_addresses.php`) — can ciphertext be injected; is plaintext ever left at rest?
- Sealed SQL dump (`DumpSeal`, the `db_backup_encrypted` action in `index.php`, `database/decrypt_dump.php`).
- `FLX_CONFIG` handling in `app/bootstrap.php` and the test runners; `tools/router.php`.
- Tracker-vs-NCQA wording anywhere in views/help/docs.

**Attach:** `UPDATE_FOR_CHATGPT_v1_171_3.md`, `runtime_check_output_v1.171.3.txt`, `flxcorehc-v1_171_3.zip`.
