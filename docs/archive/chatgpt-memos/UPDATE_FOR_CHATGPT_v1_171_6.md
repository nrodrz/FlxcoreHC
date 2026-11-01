# FlxCoreHC v1.171.6 — response to Claude Code's independent validation of v1.171.5

Status: **Production Candidate (tracker, not NCQA-grade) — pending Claude Code's independent re-run.**
"Claude.ai sandbox PASS" = author's run, **not** independent. Sandbox: `FLX_CONFIG=<base config> bash tools/runtime_check.sh`, `app/config.php` absent → exit 0, 651 PASS lines, 0 FAIL (`runtime_check_output_v1.171.6.txt`).
Independent result for v1.171.5 (Claude Code): automated stages PASS (639 lines, 7×220-page crawls); checklist 49 PASS / 4 FAIL / 1 REQUIRES BROWSER VALIDATION; break-list 7 PASS / 3 FAIL; 1 data-loss bug found outside the checklist.

## Dispositions
| Finding | Verdict | Fix | Test (mutation-proven where noted) |
|---|---|---|---|
| **Data loss**: `POST /holders/{id}` blanked name/NPI/e-mail for fields not sent; unknown sub-paths fell through to it | **Real defect, fixed** | `index.php`: update only on the bare URL, `fromPost(creating=false)` | HTTP: unknown sub-path → 404, record unchanged; partial POST changes only its field (**mutation: FAIL on old code**) |
| 3i-2 toast colours (keyword guess wrong both ways) | **Real defect, fixed** | every literal `flash()` typed explicitly (156), `$ok`-based typing for service results (~60 sites), composed messages typed at call site; keyword guess kept only as fallback | HTTP (validation refusal = error, provider add = ok, earlier J cases) + source guard: 0 untyped literal `flash()` |
| 3i-4 / B3 no QR code | **Checklist error (mine)** — the app never rendered a QR; it shows setup key + tap-to-add link. Corrected the checklist and a misleading comment in `Totp.php`. A QR image would be a feature → **owner decision**, not done | docs only | n/a |
| 3i-7 / B5 JLDM under "State board" | **Checklist error (mine)** — JLDM is seeded `state_board`, nursing board `licensing`; both visible (the E finding was visibility). Corrected; no data change | docs only | existing E test |
| 3i-11 / B9 template repeats NPI 1234567893 | **Real defect (mine), fixed** — my v1.171.5 test checked Luhn but not uniqueness | 4 distinct valid NPIs (1234567893, 1679576722, 1245319599, 1003000126) | DB: template previews as 4 creates/0 skips through the real `ProviderImportService`; HTTP: uniqueness |
| Stage 5 false-fails on used installs | **Real defect in my check, fixed** | compares before/after; fails only on files this run created | logic verified; full run PASS |
| §6-3 2FA can be re-enrolled without the current code | **Real security gap, fixed** | `AuthService::confirmTotp` refuses when 2FA is on; `2fa_start` says so; `2fa_enabled` audited | DB + HTTP (**mutation: FAIL on old code**) |

## Owner decisions (v1.171.6)
- **No QR code** for 2FA enrolment (setup key + tap-to-add link stay). Closed.
- Observations reviewed and dispositioned below; cycle to be closed after this validation.

## Also fixed in this revision
- §6-11 replayed role-create made a duplicate role → role names are unique per organization (DB test).
- §6-8 Staff CSV export: behaviour is correct and scoped (Staff holds `reports.view`); the **documentation** was wrong → corrected (DEMO_ACCOUNTS.md). No capability change.

## Observations NOT fixed (none is a data-exposure defect; proposals, your call)
- §6-4 an invite/approval POST flushes the whole outbox (POST-only; kept: users expect the invite mail immediately).
- §6-5 the unsealed `.sql.gz` backup remains downloadable next to the sealed one (kept; owner may want to remove it).
- §6-6 platform SMTP accepts 127.0.0.1 while tenant SMTP refuses private hosts (platform operator is trusted; flag).
- §6-7 a known-place sign-in clears the per-e-mail counter (by design for owner recovery).
- §6-9 FL-only tenants still see PR text outside the OCS panel (ORCPS "Why?" lines, PR payers, PR boards on /regulators).
- §6-10 forced-sign-out notice uses the warn style (renders blue); §6-12 raw slugs/UUIDs in the audit UI (incl. "to: tenant_owner" visible to a Manager); §6-13 Spanish gaps; §6-14 read-only users see buttons that are refused; §6-15 minor items; B1 edge case (notice not shown if the stale session's next request is `/login` itself).

## Transparency
- New tests were **added** (tests/security V6 block, tools/http_smoke/drive.py). No existing test was changed. One earlier v1.171.5 test of mine (the keyword classifier) is unchanged and still passes; the durable guarantee is now the explicit typing + the HTTP tests.
- The smoke gained a dedicated `twofa@smoke.test` user; enrolling a shared user had turned its crawl into a vacuous one, which the v1.171.5 guard caught.
- Not independently verified: everything above is sandbox. Toast **colour** rendering remains REQUIRES BROWSER VALIDATION (tests check `data-toast-type`). 3g-8 (https calendar URL) still needs a production https install.
- Regulatory facts unchanged (UNVERIFIED: ~60 h/3 yr, 10 h/6 h split, ORCPS cycle, negative drug-test certificate; owner to confirm at salud.pr.gov/CMS/120).
