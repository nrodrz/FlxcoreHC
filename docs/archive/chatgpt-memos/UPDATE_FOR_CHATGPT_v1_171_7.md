# FlxCoreHC v1.171.7 — response to Claude Code's third independent validation (v1.171.6)

Status: **Production Candidate (tracker, not NCQA-grade).** "Claude.ai sandbox PASS" = author's run, not independent.
Sandbox: `FLX_CONFIG=<base config> bash tools/runtime_check.sh`, `app/config.php` absent → exit 0, 659 PASS, 0 FAIL (`runtime_check_output_v1.171.7.txt`).
**Independent result for v1.171.6 (Claude Code):** validation script exit 0, 651 PASS, 0 FAIL, 7×220-page crawls; stage 5 passes on a used installation; checklist 58 PASS / 1 FAIL / 1 REQUIRES BROWSER VALIDATION (3g-8); break-list 3 PASS / 2 FAIL. All prior blockers (data loss, toast colours, template NPIs, 2FA swap, stage 5) confirmed fixed. No 5xx / PHP warnings in ~1,150 crawled requests.

## Findings and dispositions
| Finding | Verdict | Fix | Test |
|---|---|---|---|
| 4-3 FL: CME row says "Per licensing-board cycle", checklist expected a number | **Checklist error (mine).** The wording is deliberate: FL board cycles differ; only PR (36) is stated. No code change | docs | n/a |
| K2 toast sweep: 4 mismatches | **Real, fixed** | explicit types ("Support request denied." ok; imports created-nothing error / partial warn; "No active link…" error) | HTTP (import with only bad rows ⇒ error) |
| K5 role names differing by whitespace accepted; custom roles named Admin/Viewer/Regular shadow built-ins (obs 3) | **Real, fixed** | comparison ignores case, repeated/odd whitespace, NBSP, zero-width; built-in names refused | DB (8 variants) + HTTP |
| Obs 1: edit erases name / saves malformed e-mail | **Real, fixed** | edit enforces name + e-mail validity (create already did) | DB + HTTP |
| Obs 4: correct password with 2FA abandoned reset the per-account counter | **Real, fixed** | counter cleared only on complete sign-in | DB |
| Obs 5: refused 2fa_confirm rendered an empty enrolment form | **Real, fixed** | redirect with message | HTTP |
| Obs 7: installer mail_from contained the port | **Real, fixed** | host without port | covered by installer check run |
| Checklist mismatches (Document types path, outbox wording, script TODO omitted 3j) | **Mine, fixed** | docs + script | n/a |

## Not fixed (explicit; none exposes data across tenants) — for ChatGPT's judgement
- Obs 2 unsealed DB download has no password re-confirmation (platform-admin only). **Recommend fixing in v1.172** (require POST + password, or remove).
- Obs 6 one tenant's POST flushes other tenants' outbox; invite toasts show the full link even when mail is on.
- Obs 8 platform admin enters the **demo** org without a support grant (by design for demos; non-demo refused).
- Obs 9 FL-only tenant still lists PR payers; obs 10 duplicate catalog names; obs 11 CE window starts one day late; obs 12/13 audit wording and Spanish gaps.
- B1 edge: the sign-out notice is not shown if the stale session's next request is /login itself.
Planned: QR code for 2FA (server-side, no external service) after this review; superadmin roadmap screen; scalability backlog (documents to object storage, job queue, streaming backup). See the project roadmap.

## Transparency
- Tests were only added; none removed or loosened. New: tests/security V6 (role names, edit validation, counter reset) and tools/http_smoke/drive.py (import colour, edit refusal, 2FA redirect, whitespace roles).
- Everything above is sandbox except the Claude Code results quoted. Toast **colour** rendering was confirmed by Claude Code in Chromium for v1.171.6; 3g-8 (https calendar URL) still needs a production https install.
- Regulatory facts unchanged (UNVERIFIED: ~60 h/3 yr, 10 h/6 h split, ORCPS cycle, negative drug-test certificate; owner to confirm at salud.pr.gov/CMS/120).
