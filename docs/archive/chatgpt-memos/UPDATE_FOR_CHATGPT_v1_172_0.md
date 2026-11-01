# FlxCoreHC v1.172.0 — update after the owner's first test installation

**Status:** Production Candidate under test (healthcare credentialing/compliance tracker, not NCQA-grade).
**Why a new version:** v1.171.10 was frozen for deployment validation. On the first test installation the product owner found two things that stop a real first set-up, and decided to change them and to bring forward three planned items. This is an owner decision that supersedes the freeze; v1.171.10 is no longer the candidate.

## What the owner found and asked for
1. The platform console cannot be used until two-factor is fully set up, which blocks the configuration a new installation needs. **Asked:** keep the requirement but not as the default; the platform administrator turns it on when ready.
2. The guided onboarding shows the same fields and provider types for a person and for an organization. **Asked:** a real onboarding for each, and different for a solo practice than for a billing company.
3. **Asked:** instructions for each authenticator app, a QR code, and the e-mail options (both: e-mailed codes as a second step, and password reset by e-mail).

## What changed (migration 133; files: see docs/CHANGELOG.md v1.172.0)
| Area | Change |
|---|---|
| Platform two-factor | `platform_require_2fa` setting (Console → Security → Sign-in policy). Off on a new install; kept ON by migration 133 where a platform administrator already uses two-factor. Changing it needs the current password, is audited, and cannot be switched on by an administrator who has no second step. While off: notice in the console, "to review" in Security posture. |
| QR code | `app/src/QrCode.php`, own encoder (byte mode, level M, versions 1–20), inline SVG, no external service. |
| Instructions | Google Authenticator, Microsoft Authenticator, Authy, 1Password on the enrolment page. |
| E-mailed codes | Second factor alternative. Keyed-hash storage, 10-minute expiry, single use, 5 wrong tries, 5 sends per 15 minutes, purpose-bound (sign-in / enrol / turn off). One method at a time. Platform can disallow it. |
| Password reset | `/forgot` and `/reset/<token>`. Same answer for known and unknown addresses; mail sent after the response where the PHP handler allows it (see decision 4); SHA-256 token hash; 60 minutes; single use; ends open sessions; does not sign in; does not bypass two-factor; per-address and per-IP limits; audited. |
| Security mail | `MailService::sendSecure()`: one immediate attempt, body scrubbed from the outbox afterwards, never retried. |
| Guided onboarding | Server-rendered per record type (plain links, no JavaScript). Person and organization have their own fields, type lists, identifiers and last step. Step 1 adapts to solo practice / medical group / hospital / billing company (client group, department, "I am the doctor"). |

## Defects found on the way (both pre-existing in v1.171.10, both fixed)
| Defect | Severity | Fix | Test |
|---|---|---|---|
| **Inline event handlers are blocked by the CSP.** 8 handlers in 5 views did nothing. Worst case: the case requirement status select had no submit button, so the status could not be changed from the list. This is the root cause of the owner's onboarding screenshot. | Functional (credentialing cases, onboarding) | data-attributes handled by `assets/app.js`; real Save button on the case form | DB source guard C; HTTP O12; browser B2, B7 |
| **The guided onboarding did not check `records.write`.** A non-read-only role without that permission could create a record through `/holders/onboard`. Same organization only; no cross-tenant effect. | Authorization gap, low–moderate | `requireCap('records.write')` on every wizard step | HTTP O11 (mutation: without the fix GET 200 / POST 302) |

Neither was found by the five earlier validation rounds: the HTTP suites do not execute JavaScript, and the role crawl did not include a non-portal role without `records.write`. The new browser check and the new fixture role close those two blind spots.

## Results — evidence levels kept apart
**Claude.ai sandbox (author's run, not independent)**
- `FLX_CONFIG=<base config> bash tools/runtime_check.sh`, `app/config.php` absent: **exit 0, 792 PASS, 0 FAIL/SKIP**, 7 crawls × 220 pages, STRAY FILES PASS (`runtime_check_output_v1.172.0.txt`).
- `bash tools/browser_check/run.sh` (headless Chromium): **13 PASS, 0 FAIL**. A QR reader (OpenCV) decodes the on-page code to an otpauth address carrying the same secret as the setup key; no CSP violation on any visited page.
- QR encoder compared with an independent reference library: 147 inputs, versions 1–20, all 8 masks, 0 matrix differences.
- Mutation: 20 deliberate breaks of the new logic (two passes); every one is caught by at least one test.

**Claude Code independent execution:** v1.171.10 only ("Cycle closeout criteria met"). **v1.172.0 has not been independently executed.**
**Owner's installation test:** in progress on the target hosting.

## Tests changed, and why
- Two existing "S3" checks (DB and HTTP) assert that a platform administrator without two-factor is refused. Their **assertions are unchanged**; each now first turns the requirement on, because it is no longer on by default. The off case has its own new tests (P group).
- No test was removed or loosened. One assertion of mine in a new test was corrected during development (it looked for a status select on a case that had no requirement row; the fixture now adds one).

## Design decisions worth a second opinion
1. Two-factor for the platform console is **off by default** on a new install (owner decision). Mitigations: visible notice, posture warning, password-confirmed and audited policy change, kept ON on upgrade.
2. E-mailed codes count as two-factor for every policy, including the platform console. With password reset by e-mail also available, a compromised mailbox alone can take over an account that uses e-mailed codes. The enrolment page says so, the authenticator is labelled Recommended, and the platform can disallow e-mailed codes. Open question: should e-mailed codes be refused for platform administrators when the requirement is on?
3. A pending second-step challenge started with the old password can still be completed within its 5/15-minute window after a reset (the second factor is still required).
4. The reset e-mail is sent after the response only where PHP can finish the request early (`fastcgi_finish_request` or `litespeed_finish_request`). On a handler without either, the response waits for the mail transport, so response **time** could still hint that an address has an account even though the **text** is identical. The per-IP limit (10 per 15 minutes) bounds this.
5. `app_url` is used for links in e-mails; a subfolder install must include the subfolder in `app_url` (pre-existing for invitations; documented in INSTALL.md).

## Still deferred (unchanged)
Unsealed DB download hardening; in-app equivalents of the command-line maintenance tools; superadmin roadmap screen; TextNorm residues (variation selectors, "0", NFC, invite full_name); duplicate Business License entries; FL-only tenant sees PR payers; CE one-day edge; Spanish gaps outside the new screens; no administrator action to reset a user's lost second factor (backup codes are the recovery path). Regulatory statements unchanged and still labelled UNVERIFIED.
