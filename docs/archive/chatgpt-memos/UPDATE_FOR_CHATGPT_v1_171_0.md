# FlxCoreHC v1.171.0 — Disposition of the independent v1.170.9 validation (Claude Code) and ChatGPT review

Status labels: EXECUTED — PASS = run by me in the isolated test environment (PHP 8.4, MariaDB). Independent re-run by Claude Code on a clean checkout is still REQUIRED EXTERNAL RUNTIME VALIDATION. Browser items = REQUIRES BROWSER VALIDATION. Classification: **Production Candidate**, not "Production Ready".

## Implemented (each with behavioural DB and/or HTTP tests, not source-grep)
| # | Finding | Result | Evidence |
|---|---|---|---|
| 1 | S-1 capability ceiling | Role create/update/team grants capped to actor; system roles owner-level only; assignable() limited | tests/security S1 — EXECUTED PASS |
| 2 | S-2 session revalidation | Per-request user/tenant/password-fingerprint check, fail-closed. **Deviation:** no `session_version` column (no migration); a sha256 password fingerprint is used. Role change is picked up by re-reading role each request | S2 DB + HTTP — EXECUTED PASS |
| 3 | S-3 support grant + 2FA | Grant required for owner password reset, owner creation, owner-role changes; platform admin needs TOTP to enter /admin and to be promoted | S3 — EXECUTED PASS |
| 4 | B-1 provider form | `HolderInput` single-source list/parser; full round-trip | B1 — EXECUTED PASS |
| 5 | B-2 deleted_at | 7 joins + sweep_expiring fixed. Audit history views intentionally unfiltered | B2 — EXECUTED PASS |
| 6 | B-3 migrator | BENIGN narrowed to duplicate/exists/drop-missing codes; GET_LOCK; refuses untracked non-empty DB (baseline first); skipped counts + failing statement shown | B3 + tests/migrations — EXECUTED PASS |
| 7 | B-4 need-to-know | Notifications, bell count, group/location counts, posture counts, audit (403 for restricted) | B4 DB + HTTP — EXECUTED PASS |
| 8 | Encrypt documents/backups at rest | `EncryptedFile` (sodium/AES-GCM, AAD = file name, tamper/rename refused). All 2 upload + 4 download sites. Legacy plaintext readable until `database/encrypt_existing_files.php`. Document backup archive holds sealed files. **Not done:** the SQL dump is not passphrase-wrapped (PHI columns are already field-encrypted; other columns are plain) | E DB + HTTP upload/download round-trip — EXECUTED PASS |
| 9 | B-5, B-6, S-4 | Role dropdown; `flx_fputcsv/flx_str_getcsv`; `flx_json()` everywhere | B5/B6/S4 — EXECUTED PASS |
| — | Keys backup (user-approved B+D) | Installer passphrase-sealed `.flxkeys` + console banner until confirmed; never e-mailed; restore CLI | K — EXECUTED PASS |

Totals: full `tools/runtime_check.sh` incl. new stage 3g = ALL PASS (security suite 87 checks; HTTP smoke incl. 7-user crawl).

## Deferred / not implemented (and why)
- NPI Luhn / NPI-2 / DEA / taxonomy validation: would reject the demo NPIs; needs demo data regeneration first.
- PR seed corrections (JLDM, nursing board, 36-month CME, jurisdiction-aware readiness, FL fallback): domain content needing reviewed sources.
- Market position (tracker vs NCQA-grade: NPDB, committee, 180-day gate, PSV model): product decision for the owner.
- Exclusion hardening (LEIE automation, fuzzy match, Opt-Out, DMF), full Spanish, Composer/PHPUnit/PHPStan/CI, index.php split, domain rename, pagination/FKs, `FLX_CONFIG` env var and `tools/router.php`: multi-release scope; change control forbids adding them unapproved.
- S-5 to S-7 (SMTP restrictions, encrypted TOTP + replay protection, capability-based 2FA policy): not in this release.
- Doc cleanup: runtime-check doc fixed (Step numbering, users, passwords, installer prerequisite); PRODUCT.md and ChatGPT-memo archival not done.

## Open questions
1. Should restricted users ever hold `audit.view`? (Currently /audit is 403 for them.)
2. Migrate SQL dump to passphrase-sealed export as well?
3. Retire the legacy insurance tables?

## Note
The validation report I received was truncated (sections 1–6 not visible; only the P0–P2 list and the suggested message). Findings were implemented from that message; anything only in the missing sections is not addressed.
