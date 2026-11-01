# FlxCoreHC v1.171.3 — Update for ChatGPT (second-opinion review)

**Status label for this release: Production Candidate** (not "Production Ready"). Product positioning: **Tracker** — not NCQA-grade credentialing (NPDB, committee decisions, NCQA timeliness, PSV automation are plan-only: `docs/architecture/NCQA_CREDENTIALING_ROADMAP.md`). Please challenge any place where UI/docs imply more than that.

Working model: Claude.ai writes all code; Claude Code only runs tests and reports; you review and I report dispositions. Evidence labels: EXECUTED — PASS/FAIL · STATICALLY REVIEWED · REQUIRES EXTERNAL RUNTIME VALIDATION · REQUIRES BROWSER VALIDATION.

## 1. What changed in v1.171.3

| # | Item | Disposition | Evidence |
|---|---|---|---|
| S-8a | Per-account login throttle let a stranger lock the owner out. Now (email,IP) pairs that signed in within 30 days are exempt from the per-account counter; per-(email,IP) and per-IP limits remain | Implemented | EXECUTED — PASS (security suite S8) |
| S-8b | Dev-passthrough marker byte (0x00) is honoured only when `app_env = dev` | Implemented | EXECUTED — PASS (behavioural, flips env) |
| S-8c | GET side effects removed (`MailService::flush` on /admin, reminder sweep on /compliance); `?setlang=` persists only for same-origin/site/none `Sec-Fetch-Site`; `/settings` uses `org.manage`; installer session CSRF | Implemented | EXECUTED — PASS for behaviour where testable; **source-pattern checks only** for the index.php/installer items → STATICALLY REVIEWED. Installer CSRF REQUIRES BROWSER VALIDATION |
| S-8d | Invite tokens stored as SHA-256 (migration 130); legacy raw 40-char tokens still resolve; a stored hash can never be replayed as a token | Implemented (a replay bug in my first version was caught by my own test) | EXECUTED — PASS |
| D-6a | Address line1/line2/phone encrypted (migration 131, AAD tenant\|addresses\|col), plaintext scrubbed per column on write, legacy rows readable, back-fill CLI, callers cannot write `*_enc` directly | Implemented (my test caught a bug where editing any field erased still-unmigrated legacy plaintext; fixed to scrub per column) | EXECUTED — PASS |
| D-6b | Passphrase-sealed SQL dump (PBKDF2-SHA256 200k + AES-256-GCM, FLXDUMP1), password-confirmed, rate-limited; `database/decrypt_dump.php` | Implemented | EXECUTED — PASS (unit + HTTP) |
| T-1 | `FLX_CONFIG` env var; all runners + HTTP smoke no longer swap `app/config.php`; `tools/router.php` for `php -S` | Implemented | EXECUTED — PASS |
| B-8 | Cases list shows provider names; audit log humanized (ID in tooltip); calendar feed URL https on production | Implemented | HTTP smoke crawl PASS (no warnings/5xx); visual check REQUIRES BROWSER VALIDATION |
| Docs | History moved INSTALL.md → docs/CHANGELOG.md; PRODUCT.md/KICKOFF.md rewritten (tracker, no PSV); 13 memos archived to docs/archive/chatgpt-memos | Done | STATICALLY REVIEWED |

Full automated result (Claude.ai's own sandbox, PHP 8.4 / MariaDB 10.11): `bash tools/runtime_check.sh` → all stages PASS, 570 PASS lines, 0 failures (`runtime_check_output_v1.171.3.txt`). **Not yet run by Claude Code on a clean machine — that is the next step and is what "EXECUTED" will be judged on.**

## 2. Deliberately NOT done (challenge these)

1. **share_links and iCal tokens remain plaintext** — the UI re-displays them; hashing needs a "regenerate once" UX. Risk accepted for now: DB read access exposes live read-only links. Do you agree, or is hash+regenerate worth a release?
2. **Legacy empty-AAD ciphertext still decrypts** (old data). A migration to re-encrypt with AAD would close it.
3. **Account-lockout exemption trades security for availability**: an attacker who shares the owner's IP (same NAT/office) is not throttled per-account (still limited per email|IP = 8 and per IP = 30 per window). Is that acceptable?
4. **D-4 exclusion hardening, D-7 full Spanish / work-history gaps / attestation model** — multi-release.
5. **A-1…A-7 architecture programme** (split index.php ~4.9k lines, repository-only SQL [34 raw calls baselined], Composer/PHPUnit/PHPStan/CI, domain rename, pagination/FKs/relative paths) — multi-release, not started.
6. `audit.view` for restricted (need-to-know) users is still 403 — owner decision pending.
7. **Unverified regulatory facts** (migration 126): JLDM name, 60 h / 36-month CME window for PR physicians, nursing board name, ORCPS triennial recertification. I have not verified these against primary sources; please flag anything you know to be wrong rather than assuming they are right.

## 3. Where I want your scrutiny (highest value first)

1. `TeamService::inviteHash/legacyInvite/findInvite/acceptInvite` — any path where an attacker-supplied value could match a stored hash or bypass consumption?
2. `RateLimiter::seenWithin` + `AuthService::attemptLogin` ordering — can an attacker *create* a "known place" entry without a successful login? (`known|` is recorded only after success.)
3. `AddressRepository` (`noCipher` strips caller-supplied `*_enc`; `scrubPlain` per-column) — any path that leaves plaintext at rest or loses legacy data?
4. `DumpSeal` format/AAD, whole-buffer memory use on large DBs, and whether the sealed download leaks through logs/headers.
5. `flx_config_path()` — env var honoured by web requests too; confirm that is not an issue on shared hosting (attacker cannot set env vars).
6. `tools/router.php` path handling (test-only, but check traversal).
7. Tracker-vs-NCQA language anywhere in `app/views`, `app/help`, `docs/` that overclaims.

## 4. Files to attach for your review
`UPDATE_FOR_CHATGPT_v1_171_3.md` (this), `runtime_check_output_v1.171.3.txt`, the zip `flxcorehc-v1_171_3.zip`, and `docs/CHANGELOG.md` (top entry).
