# FlxCoreHC v1.171.2 — Remaining findings from the Claude Code review of v1.170.9

Adds to v1.171.0/v1.171.1 (see UPDATE_FOR_CHATGPT_v1_171_1.md for the full table). Labels: EXECUTED — PASS = run by me in an isolated PHP 8.4 + MariaDB environment; independent re-run on a clean checkout still REQUIRES EXTERNAL RUNTIME VALIDATION; browser items REQUIRE BROWSER VALIDATION. **Production Candidate.**

Owner decision recorded: stay a **credentialing tracker** (not NCQA-grade). NPDB/committee/NCQA remain a plan only; sales copy must not imply them.

| ID | Result | Evidence |
|---|---|---|
| S-5 | `SmtpGuard`: public hosts only, ports 25/465/587/2525, every resolved address must be public, connection pinned to the validated IP (TLS peer name kept), generic connect error; applies only to tenant-supplied SMTP | DB tests incl. loopback, RFC1918, link-local/metadata, IPv6, mixed-DNS, rebinding-style answer, send-time refusal — EXECUTED PASS |
| S-6 | TOTP secret encrypted (`enc1:`, AAD = user id), single-use codes via compare-and-set on `totp_last_counter`, legacy plaintext upgraded lazily, enrolment code burned; migration 128 | replay / older step / cross-user / legacy tests — EXECUTED PASS |
| S-7 | 2FA policy on capabilities (`team.manage`, `org.manage`, `pii.view`, `data.export`), enforced before every route | DB + HTTP (/, /billing, /holders, /team redirect; staff unaffected) — EXECUTED PASS |
| B-7 | `check_isolation.sh` can fail: scans `app/`, stale allow-list entries fail, `index.php` raw-DB baseline (34) | tests copy the script into a temp tree and prove each failure mode — EXECUTED PASS |
| B-8 | Wording/UX items fixed (list in CHANGELOG); malpractice authority → `insurer` (migration 129) | smoke + runtime check |

## Still NOT implemented (reasons unchanged)
S-8 lows (account-lockout DoS, GET side effects, installer CSRF/one-time secret, hashing share/invite/iCal tokens, `Crypto::decrypt` accepting dev-plaintext in production); D-4 exclusion hardening; D-7 Spanish coverage / work-history gaps / attestation model; A-1…A-7 architecture programme; `FLX_CONFIG` + `tools/router.php`; SQL-dump passphrase export; residential-address encryption; remaining B-8 items (raw IDs in case list/audit log, iCal `https` on http installs, Coastal demo wording).

## ChatGPT: please challenge
1. Is hiding the SMTP test error detail (generic message) acceptable for tenant admins who then cannot diagnose failures? (Detail is in the server error log.)
2. The capability set that triggers the 2FA policy — missing anything (e.g. `documents.verify`, billing)?
3. Should S-8 (especially token hashing and the account-lockout DoS) precede any of the above for a pilot with real provider data?
