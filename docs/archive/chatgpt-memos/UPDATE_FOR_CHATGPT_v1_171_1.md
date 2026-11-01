# FlxCoreHC v1.171.1 — Disposition of the COMPLETE Claude Code review of v1.170.9

Supersedes the disposition in UPDATE_FOR_CHATGPT_v1_171_0.md (written while the review's §1–6 were not yet visible; that gap is now closed). Labels: EXECUTED — PASS = run by me in an isolated PHP 8.4 + MariaDB environment; an independent re-run on a clean checkout is still REQUIRES EXTERNAL RUNTIME VALIDATION; browser items REQUIRE BROWSER VALIDATION. Classification: **Production Candidate**.

## Implemented (v1.171.0 + v1.171.1) — all with behavioural DB/HTTP tests
| ID | Finding | Result |
|---|---|---|
| S-1 | Manager self-grants capabilities | Capability ceiling on role create/update and team grants; system roles owner-level only |
| S-2 | Sessions survive suspend/reset/demotion | Per-request revalidation (status, tenant state, password fingerprint). **Deviation:** password fingerprint instead of a `session_version` column (no migration) |
| S-3 | Platform admin bypasses consent | Support grant required for owner reset/creation/owner-role; TOTP required to use /admin |
| S-4 | JSON in `<script>` | `flx_json()` everywhere |
| B-1 | Provider edit loses data | `HolderInput` single source; full round-trip |
| B-2 | Soft-deleted counted | `deleted_at IS NULL` on 7 joins + cron |
| B-3 | Migrator records failures as applied | Narrow BENIGN list, lock, skipped counts, refuses untracked DB |
| B-4 | Need-to-know leaks | Notifications, bell, counts, audit scoped (/audit 403 for restricted) |
| B-5, B-6 | Role dropdown; PHP 8.4 CSV | Fixed |
| B-8 (part) | Menu shows pages that return 403 | Menu gated (HTTP-tested). Other B-8 wording items NOT done (see below) |
| §4.3 D-6 (part) | Documents/backups plaintext | Documents encrypted at rest (`EncryptedFile`); document backups hold sealed files. **Not done:** SQL dump passphrase-wrap, residential addresses |
| D-3 | NPI length-only | Luhn (80840) + leading digit on every input path; demo/seed NPIs regenerated; NPI-2 for groups remains an NPPES-time check (needs network) |
| D-5 | PR seed errors | JLDM name; nursing licence item and board; physician-only PR licence/CME; CDS certificate neutral + no `state_cds` shortcut; **36-month** CE window for PR; PR-only items (OCS/SICRO, Buena Conducta, ASUME, ORCPS/OCS sources, Level-5 OCS rule) only for tenants operating in PR; **FL fallback removed** (migration 127) |
| §2 tooling | Runtime-check doc drift | Step numbering, user list, owner passwords, installer prerequisite fixed; security suite wired as stage 3g |
| — | Keys backup (owner-approved B+D) | Installer + console, never e-mailed |

Evidence: `tools/runtime_check.sh` incl. stage 3g = ALL PASS; security suite 113 behavioural checks; HTTP smoke incl. upload/download round trip, PR/non-PR pages, menu, 7-user crawl.

## Plan only (needs owner decision first)
- D-1/D-2 NPDB, committee decisions, 180-day/36-month gates, unified PSV, full SSN → `docs/architecture/NCQA_CREDENTIALING_ROADMAP.md`. Decision gate: stay a tracker, or become NCQA-grade. Until decided, copy must not imply NPDB/committee support.

## NOT implemented, with reason (none silently dropped)
| ID | Why not |
|---|---|
| S-5 SMTP host/port restrictions; S-6 encrypted TOTP + replay; S-7 capability-based 2FA policy; S-8 lows | Valid P1 items, outside the owner's requested scope for this release; change control → proposed for v1.171.2 **on approval** |
| B-7 isolation checker false confidence | Making it fail for real requires an allow-list of ~33 raw queries in `index.php`; proposed together with the index.php split |
| B-8 remaining wording items ("Holder updated.", raw IDs in case list, "federal federal", iCal https on http, etc.) | Cosmetic batch, proposed for v1.171.2 |
| D-4 exclusion hardening; D-7 full Spanish / work-history gaps / attestation model | Feature work |
| A-1…A-7 (index.php split, repository-only SQL, Composer/PHPUnit/PHPStan/CI, domain rename, pagination/FKs/relative paths, i18n engine, doc cleanup) | Multi-release engineering programme; not started |
| `FLX_CONFIG` env var, `tools/router.php` | Not started; runners still swap `app/config.php` (they restore it) |

## ChatGPT: please challenge
1. Is the fingerprint-based session revalidation an acceptable substitute for `session_version`?
2. Is hiding PR-only items for non-PR tenants the right default, or should PR be the assumed jurisdiction for this market?
3. Any regulatory statement in migration 126 (JLDM name, 60 h / 36 months CME, nursing board name, ORCPS triennial recertification) you can contradict with a primary source? I did not verify them against the statutes in this session.

## Open questions for the owner
Tracker vs NCQA-grade; approve v1.171.2 scope (S-5..S-7 + B-8 wording + B-7)?; restricted users and `audit.view`; SQL-dump passphrase export.
