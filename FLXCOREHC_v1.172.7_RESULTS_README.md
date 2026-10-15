# FlxCoreHC v1.172.7 — independent validation (Claude Code independent execution)

**Date:** 2026-10-15 · **Role:** runner/reporter only — no app code, migrations, schema, tests or docs modified. All evidence below: **Claude Code independent execution**.

**Environment:** PHP 8.4.19 CLI (intl, openssl, pdo_mysql, sodium) · MariaDB 10.11.14 (throwaway) · Python 3.11.15 + Playwright 1.56 (Chromium) + OpenCV (installed for this run) · `php -S`. **No outbound DNS in this sandbox** (hostnames never resolve) — relevant to one finding.

**Bundle:** `flxcorehc-v1_172_7.zip` SHA-256 matches `LEEME.txt` (`46546e02…cf511`); checklist in bundle identical to `docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md` (its title still says v1.172.2); validation script identical to previous releases apart from the version.

## Verdict

| Area | Result |
|---|---|
| `claude_code_validate_v1_172_7.sh` | **exit 0 · 884 PASS · no FAIL/SKIP** · 7 crawls × 220 pages · STRAY FILES PASS · new stages 3a2/3a3/3a4 all PASS · pristine tree byte-identical (528 files) |
| `tools/browser_check/run.sh` (real Chromium) | **ALL PASS, exit 0, 14 checks** — QR decoded by OpenCV to an `otpauth://` URI whose secret equals the setup key (not NOT RUN) |
| Step 1 fresh install (http + https) | PASS — 91 tables, 133 migrations (`133_signin_options.sql`), intl row "loaded", CSRF refused, `mail_from` no port |
| Step 3q (new) — 7 items | **3 PASS · 3 FAIL · 1 REQUIRES BROWSER VALIDATION** (Graph) |
| Step 3o / 3p regression — 13 items | **13 PASS** |
| "Try to break" (dates, e-mail, platform audit, create org) | **1 FAIL — HTTP 500 on tenant SMTP save for any unresolvable host**; everything else held |

**Three findings I would call material for this release** (all reproduced and confirmed in code):
1. **3q-1 Dates — an all-day deadline moves back a day west of UTC-9.** `app/views/calendar/index.php:13` converts every event's `due_at` from UTC to the local day, including `all_day=1` events stored at `09:00 UTC`; in Pacific/Honolulu the 03/13/2027 expiry sits in the `data-date="2027-03-12"` cell and the "Next 30 days" panel shows "09/03/2026 11:00 PM" for a 09/04 deadline. The list/agenda rows (line 107, date part only) stay correct. The checklist's own rule — "ninguna fecha de solo-día retrocede un día" — is broken.
2. **3q-3 / SSRF guard — tenant Own-SMTP save crashes with HTTP 500 for any hostname that does not resolve.** `SmtpGuard.php:39` does `(array) @gethostbynamel($host)` → `[false]` on lookup failure, then `:56` passes that `false` to `isPublicIp(string)` → `TypeError`. 10 rows in `error_log`; in dev mode the page prints the stack trace with absolute paths. In production this hits every typo'd host name. (Private/loopback/link-local IPs and all disallowed ports are refused correctly; the connect-time re-check also holds.)
3. **3q-1 Dates — ISO and raw-UTC strings remain on the dashboard and provider page** although the checklist says none may show: 12 ISO dates in the dashboard/portal work queue (`ComplianceLevelService.php:42` concatenates the raw `expiry_date`), 51 ISO dates on the provider page (checklist "exp 2027-03-14", payers "Recredentialing … 2027-02-12", OCS "attested 2026-06-17", document requests "by 2026-10-29"), 13 raw 24-h UTC timestamps in the History tab (`created_at: 2026-10-15 19:59:32`), one "Mon D" in CME ("Hours from Oct 16, 2023").

**3q-6 is a doc/feature mismatch, not a defect:** Create organization does not *generate* a one-time temporary password; the admin types one (optional, ≥8). It is never echoed back, never in logs/audit/outbox, and the owner can sign in with it — but there is nothing "shown once" because nothing is generated (`SuperadminService.php:438-486`).

## 1. Automated suites
```
$ DB_USER=root DB_PASS= bash claude_code_validate_v1_172_7.sh flxcorehc-v1_172_7.zip   → SCRIPT EXIT=0
02_exit_code.txt: runtime_check exit code: 0 · 03_summary.txt: PASS lines: 884 | no FAIL/SKIP lines | app/config.php absent
PASS — crawl as owner/manager/staff/coord/readonly/restricted/padmin: pages=220 each
STRAY FILES: PASS (this run created no .notif-sweep-* in app/storage; 0 pre-existing)
$ FLX_CONFIG=/tmp/flx_base_config.php bash tools/browser_check/run.sh                  → ALL PASS, exit 0 (14 PASS)
PASS — B3 the enrolment page shows a QR code of usable size and the setup key box=200px key_len=32
PASS — B3 a QR reader decodes the code on the page to an otpauth:// address carrying the SAME secret as the setup key secret_matches=True
```
Full outputs: `script_output/01_runtime_check_output.txt`, `browser_check/browser_check_output.txt` (+ 8 screenshots). Stage 2 still prints its non-failing raw-SQL warning (false positive at the "PERMANENTLY DELETE" line, unchanged since 1.171.8).

## 2. Step 1 (me)
| Check | Label | Evidence |
|---|---|---|
| CSRF missing → refused | EXECUTED — PASS | "Your session expired or this request did not come from the installer page"; no config, 0 tables |
| Fresh install http | EXECUTED — PASS | "✓ Installation complete — created 91 tables…"; `schema_migrations` 133 (only `002_ma_cms.sql` and `098_encrypt_existing.php` unrecorded, as always); intl row `loaded`; `app_url=http://…`, `hsts=false`, `mail_from='no-reply@127.0.0.1'`; `pr_cme` UNVERIFIED |
| Fresh install with `X-Forwarded-Proto: https` | EXECUTED — PASS | `app_url=https://…`, `hsts=true` |

## 3. Step 3q
| # | Label | Evidence (runner C unless noted) |
|---|---|---|
| **3q-1 Dates & times** | **EXECUTED — FAIL** | **PASS parts:** America/New_York — Audit log (220 dates), Notifications, Calendar agenda/month/list, Billing, Org → Sources, credentials, cases, tasks, reports, team: MM/DD/YYYY, 12-hour, no "UTC"; expiry 03/13/2027 shows as 03/13/2027 in credentials list, history, CV, packet, tasks, calendar list in NY, Honolulu and UTC and "03/12/2027" never appears as text; times move with the zone (fixture row 2026-10-16 02:00 UTC → NY "10/15/2026 10:00 PM", HI "10/15/2026 4:00 PM", UTC "10/16/2026 2:00 AM"); CSVs stay ISO/UTC (`"When (UTC)"` header, `2026-10-16 02:00:00`; credentials export "Expires 2026-09-04"). **FAIL parts:** (a) Honolulu month grid puts the 03/13/2027 expiry in the 2027-03-12 cell; "Next 30 days" shows all-day deadlines a day early ("09/03/2026 11:00 PM PR Professional License expires — Luis Colon"; NY shows "09/04/2026 5:00 AM", UTC "09/04/2026 9:00 AM" — a clock time on an all-day item); (b) ISO/UTC strings listed in the verdict above; (c) profile package PDF "[verified 2026-10-15 …]". |
| 3q-2 E-mail options | EXECUTED — PASS (D) | Org → Email: transport select = Platform default / Own SMTP only; presets `['cpanel']`; separate Microsoft 365 panel. Console → Email Quick fill = `['cPanel / own domain']`. grep views/lang/assets: Google/Gmail only as fonts, "Google Authenticator", Google Calendar help; `TenantMailService::PRESETS` = cpanel only. |
| 3q-3 SMTP client | EXECUTED — PASS (D) | Local sinks 8171 (plain) / 8172 (STARTTLS self-signed). **Test connection only** → "Connection test: worked … (nothing was sent)", sink saw `EHLO, AUTH PLAIN, QUIT`, 0 messages. **Send test email** with "Send email" off → delivered (`250 OK queued`). Wrong password → "The server rejected the login. Server said: 535 … Use the full e-mail address as the username…". Closed port / unroutable 10.255.255.1 / unresolvable host → "Could not connect to the SMTP server at host:port (check host, port and security mode)…" (OS text only in server log). Cert mismatch with verify ticked → "The secure (TLS) handshake failed…"; unticked → delivered over TLS. Password: `value=""` placeholder "unchanged", 0 occurrences in HTML; blank re-save and `********` re-save keep the encrypted `enc1:…` value; 0 hits for any password in php -S log, HTML, outbox, audit, error_log, settings. |
| 3q-4 Microsoft Graph | REQUIRES BROWSER VALIDATION (D) | No Entra app. Executed without credentials: panel texts/redirect URI/permissions correct; secret input has no value, stored encrypted (96-char base64), blank re-save keeps it, audit `m365_app_saved` has NULL details; expiry notices: +90d none, +45d blue "expires in 45 days", +10d red, −3d red "expired on …"; Connect → 302 to `login.microsoftonline.com/common/oauth2/v2.0/authorize?…state=…`; bad state → "Sign-in session expired or invalid"; `invalid_grant` → reconnect wording; wrong-secret mapping (`AADSTS7000215/7000222`) statically read. Real connect/test not executed. |
| **3q-5 Platform audit log** | EXECUTED — PASS | `/admin/audit` 200 for platform admin; filters tenant/action/from/to/q/staff work; `q=<script>` → 0 rows, no reflection; CSV `attachment; filename="platform-audit-2026-10-15.csv"` with `"When (UTC)"` header. Org owner/manager/staff → **403 "Forbidden — platform administrator access required."** on page and CSV; a global role with all 18 capabilities → still 403 (console gate is the `users.platform_admin` flag). Secret sweep over page + 414 CSV rows + full `mysqldump` for 9 known passwords/passphrases and keywords (password_hash, temp_password, totp_secret, smtp_pass, client_secret, backup_codes, token=, secret=) → **none**. |
| **3q-6 Create organization** | **EXECUTED — FAIL (as written)** | Form: `owner_password` "Temporary password (optional, 8+ characters…)" — admin-supplied, never generated. With it set: flash "Organization "QA27C Temp Org" created. Owner … can sign in now with the temporary password you set." (password not in body; reload → no flash); owner signs in with it (no forced change). Blank → invite link shown once. `short` → "must be at least 8 characters." Password `Qa27cTempPw!9x` absent from php -S log, full DB dump, storage. **Nothing is "shown once" because nothing is generated** — doc/feature mismatch. Minimum is 8 while the app elsewhere requires ≥10. |
| **3q-7 Spanish** | **EXECUTED — FAIL (untranslated labels)** | `<html lang="es">`, nav/headings/tables/forms translated. Untranslated, verbatim: dashboard & portal work queue ("Not compliant — Professional license expired on …", "PR Professional License expired", "DEA Registration expired"; chart `<title>`s); provider page ("Birth month set (renewal-cycle timing) →", "Every required credential recorded and current →", "Evidence attached to every satisfied requirement →", "Manage regulators →", "Match this provider against the loaded monthly OIG LEIE list", "Clear — OIG LEIE, …", "If billing Medicare", "0 hrs · last 36 months", "0 of 7 credentials verified, no attestation on file", "5 of 9 met, 3 need attention", "Matches the template exactly.", all history/audit action labels); "Edit provider" h1; credentials filter pills "Active (34)/Expired (2)/Expiring (11)"; credentials/new "· New", "Primary-source verification files", catalog names; calendar "Due this month · 10", "Later (next 120 days) · 7", event titles; team "Provider record: …"; roles "N of 18 permissions"; settings "(PNG, JPG, GIF or WebP · max 1 MB)"; reports "Reporting year 2026", "Search all columns…"; user menu "Open menu / Sign out / Your profile"; **all 24 notification texts** ("DEA Registration expires in 27 day(s) — …", "Due 11/04/2026. Start the renewal now."); audit action names and details; tasks; Org → Sources; entity/templates; regulators; onboarding ("Create & continue →", "— No client group yet —"); import ("…or paste rows"). **The login page has no Spanish at all** (`/login`, `?setlang=es`, `Accept-Language: es`, after signing out of a Spanish session → `<html lang="en">` "Email / Password / Sign in / Forgot your password?"). |

## 4. "Try to break" results
| Target | Label | Evidence |
|---|---|---|
| Dates: Honolulu / UTC moves times; no "UTC", ISO or "Mon D"; CSV ISO/UTC; day-only never shifts | EXECUTED — FAIL | Times move correctly and CSVs stay ISO/UTC; but ISO/UTC/"Mon D" strings remain (list above) and the all-day shift exists (verdict #1). |
| E-mail header injection (From name / subject with CR, LF, `%0d%0a`) | EXECUTED — PASS (D) | Stored verbatim (HEX shows 0D0A) but sink raw headers show `From: "QA Bcc: evil@x.test" <…>` on one line; RCPT list only the recipient; no injected header. `SmtpClient::hdr()` strips CR/LF. Tenant host with CRLF → refused. |
| Ports outside 25/465/587/2525 — tenant | EXECUTED — PASS (D) | 22, 80, 8080, 2526, 8171 → "SMTP port must be one of 25, 465, 587, 2525." |
| Private/loopback/link-local hosts — tenant | EXECUTED — PASS (D) | 127.0.0.1, 10.0.0.1, 169.254.169.254, ::1, 0.0.0.0, 192.168.1.5, 172.16.0.1, fd00::1, ::ffff:127.0.0.1 → "must be a public mail server"; localhost, [::1], 0, 2130706433, 0x7f000001, 127.1 → "must be a public server name"; resolver hook with private results → refused; **send-time re-resolution** refuses a rebind (`connect_to` pinned IP); SQL-forced private row → test/send refused, sink empty. |
| **Valid-looking hostname — tenant** | **EXECUTED — FAIL** | `smtp.example.com`, `mail.coastalcred.example` → **HTTP 500 TypeError** (`SmtpGuard.php:26/56`), 10 `error_log` rows, stack trace with absolute paths on the dev page. Saved body `logs/3q3_tenant_smtp_500.html`, screenshot `D_tenant_save_500.png`. |
| Ports/hosts — Console (platform) SMTP | observed, not labelled | Accepts any port (22/80/8080 stored; 0→587; 70000→65535) and any host incl. 127.0.0.1 / 169.254.169.254 / [::1]. `SmtpGuard` docblock: operator-level SMTP is intentionally not guarded. Reported for the owner's decision. |
| Connect-failure message reveals system detail? | EXECUTED — PASS (D) | Only the user-typed host:port is echoed; errno/OpenSSL text goes to the server log only. |
| Password on screen / logs / outbox | EXECUTED — PASS (D) | 0 hits anywhere; tenant `smtp_pass_enc` varbinary (54 B), platform `enc1:…`. |
| Google/Gmail/Microsoft-by-password option | EXECUTED — PASS (D) | None in UI; `SmtpClient::explain()` keeps Gmail/M365 *hint text* for auth failures (code only). |
| Platform audit 403 for org users / roles without capability; no secrets | EXECUTED — PASS | See 3q-5. |
| Create org temp password shown once / not in logs | EXECUTED — PASS on secrecy, FAIL on "shown once" | See 3q-6. |

## 5. Step 3o / 3p regression (all PASS)
3o-1 console opens without 2FA with the blue notice; Console → Security lists administrators and status · 3o-2 policy refuses without own 2FA ("Turn on two-factor for your own account first…") and with wrong password; saves after enrolling; second admin without 2FA → `/profile` "Platform administrators must enable two-factor…"; untick saves; audit `signin_policy_set` from/to · 3o-3 upgrade: v1.171.10 DB with a 2FA admin + v1.172.7 code → "Database updated — 1 migration(s) applied.", `platform_require_2fa=1`, checkbox checked, notice gone · 3o-4 QR inline `<svg>`, no external request, 4 `<details>` (Google Authenticator, Microsoft Authenticator, Authy, 1Password); decoded secret == setup key; TOTP from it enables 2FA · 3o-5 e-mailed codes: "Not available yet" with mail off; with mail on: code mailed, wrong refused, right → on (`mfa_method=email`), backup codes shown; sign-in asks "We e-mailed a 6-digit code to m***@…"; resend invalidates old; backup code works once; outbox rows `security`, body replaced by "[security message — content removed after the delivery attempt]" · 3o-6 forgot password: identical message for unknown/known; link only to known; `Cache-Control: no-store`, `Referrer-Policy: no-referrer`; mismatch refused; not signed in after save; old fails, new works; pre-existing session ended; link single-use; 2FA still asked · 3o-7 guided onboarding (person vs organization, billing-company client group, solo-practice "I am the doctor") · 3o-8 CSP: status auto-submit, packet Print / Select all, calendar Copy all work; **browser console empty** · 3o-9 role without `records.write` → 403 on GET and POST `/holders/onboard` (read-only role: POST intercepted earlier → 302 with "read-only" flash) · 3p-1..4 person/organization forms show only their fields; cross-type fields in the request are not stored.

## 6. Surprising observations (nothing changed)
1. The platform admin's "temporary password" minimum is 8 characters (app elsewhere: 10); the owner is not asked to change it.
2. OAuth callback echoes `error_description` from the query string into the admin flash (HTML-escaped) — attacker-chosen text via a crafted link.
3. With a valid state but a bogus code the message says "Microsoft no longer accepts the **saved** sign-in…" although nothing was saved; the token POST really reached `login.microsoftonline.com`.
4. Platform SMTP in "None" mode sends AUTH PLAIN in clear (operator setting; tenants are refused this).
5. `/holders/new` returns 200 for the read-only role (form visible) while `/holders/onboard` returns 403.
6. The dashboard chart's SVG `<title>` tooltips stay English in Spanish mode.
7. The shipped `tools/router.php` logs every request as `[200]` in the php -S log even for 403/404 — the built-in server log is not a reliable status source.
8. Demo build queues 3 reminder e-mails that stay `queued` until cron/flush.
9. No PHP warnings/notices/deprecations anywhere; **the only server errors in the whole run are the 10 `TypeError` rows from finding #2**; no other 5xx.

## 7. Harness and fixtures (test instances only)
- Browser runs used the **shipped** `tools/router.php` (it blocks `/app`, `/database`, `/tools`, …; `/app/config.php` → 403). Instances: `i27_http`/`i27_https` (me), `qa27_C` (8131), `qa27_D` (8151), `qa27_up_*` (8153, v1.171.10 → 1.172.7 upgrade), `bc1727` (browser suite, 8097). SMTP sinks on 8126 (suite), 8171/8172 (D).
- SQL writes (disclosed): C — one `audit_logs` probe row (2026-10-16 02:00 UTC), one extra platform-admin user, temporary role swaps on coordinator (restored); D — `tenant_mail_settings` forced to private host/ports to exercise the connect-time guard (later reset through the UI). Timezone set NY → Honolulu → UTC → NY. Demo users' passwords changed by the reset flow (staff/manager/readonly in `flx_qa27_d`).
- Databases: `flx_i27_http`, `flx_i27_https`, `flx_qa27_c`, `flx_qa27_d`, `flx_qa27_up`, plus the suites' own (`flx_smoke`, `flx_browser`, …).

## Files
`README.md` · `script_output/` (00–04 + driver console) · `browser_check/` (output + 8 screenshots) · `logs/step1_installer_config.txt`, `logs/3q3_tenant_smtp_500.html` · `screenshots/` (45 PNGs: `C_*` dates/audit/org/Spanish/3o/3p, `D_*` e-mail/SMTP/OAuth/CSP).
