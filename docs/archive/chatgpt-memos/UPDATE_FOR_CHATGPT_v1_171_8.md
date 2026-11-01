# FlxCoreHC v1.171.8 — response to Claude Code's fourth independent validation (v1.171.7)

Status: **Production Candidate (tracker, not NCQA-grade).** "Claude.ai sandbox PASS" = author's run, not independent.
**Independent result for v1.171.7 (Claude Code):** script exit 0, 659 PASS, 0 FAIL, 7×220-page crawls, 25/25 requested checklist items, no regressions, no 5xx/PHP warnings.
Sandbox for v1.171.8: `FLX_CONFIG=<base config> bash tools/runtime_check.sh`, `app/config.php` absent (see runtime_check_output_v1.171.8.txt).

## Findings and dispositions
| Finding | Verdict | Fix | Test |
|---|---|---|---|
| R1 role rename skips the duplicate check (also dotless ı) | **Real, fixed** | one shared `nameConflict()` for create and rename; own name never conflicts; ı folded | DB (V7) + HTTP |
| R2d editing an organization clears `license_code` (e.g. CLIA) | **Real, fixed** | form keeps a stored code the list lacks as selected option | HTTP (form offers it; save keeps it) |
| R2 names of only NBSP/zero-width/BOM accepted on edit | **Real, fixed** | `HolderInput::blank()` | DB + HTTP |
| R5 stale pending 2FA secret in another session re-renders enrolment form after 2FA is on | **Real, fixed** | confirm path discards pending secret and redirects with error; inline refusal now red (class had no CSS) | HTTP (two sessions; stored secret unchanged) |

Mutation check: each of the four fixes removed in turn → the matching new tests fail (HTTP 4 FAIL, DB 2 FAIL); restored → all pass.

## Not fixed (explicit; none exposes data across tenants) — for ChatGPT's judgement
- Unsealed DB download has no password re-confirmation (platform-admin only). **Recommend v1.172.**
- Global-roles page has no duplicate-name check; "Regular" accepted next to built-in "Regular User" (not an exact duplicate).
- Platform admin entering a **demo** org deletes that owner's per-account counter and creates a known-place row.
- All-duplicate import toast is red; explicit empty `npi=`/`email=` clears values; outbox flush crosses tenants; FL-only tenant lists PR payers; duplicate catalog names; CE window off by a day; audit wording; Spanish gaps.
Planned: QR for 2FA (server-side, no external service); superadmin roadmap screen; scalability backlog.

## Transparency
- One of my new HTTP tests first failed because of a typo in its own fixture (wrong test user in the last assertion); I corrected that new test only — no pre-existing test was changed, removed or loosened.
- Everything for v1.171.8 is sandbox-only; Claude Code's v1.171.7 results are the only independent evidence quoted. 3g-8 (https calendar URL) still needs a production https install.
- Regulatory facts unchanged (UNVERIFIED: ~60 h/3 yr, 10 h/6 h split, ORCPS cycle, negative drug-test certificate; owner to confirm at salud.pr.gov/CMS/120).
