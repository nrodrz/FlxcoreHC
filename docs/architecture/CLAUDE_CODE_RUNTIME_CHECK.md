# FlxCoreHC v1.172.2 — Claude Code Runtime Validation

**For: Claude Code (or a human) on a machine with PHP 8 + MySQL/MariaDB.**
Claude.ai verified v1.170.9 with `php -l` on every file, the isolation check, and an executed run of the two
DB-free suites: `tests/healthcare/test_healthcare_only.php` (28 checks) and
`tests/authz/test_provider_scope_static.php` (ALL PASS). Everything below needs a database and has NOT been executed.

## Ground rules
- Test/staging copy only, never production. Test suites create and drop `flx_char` / `flx_purge` / `flx_authz` / `flx_mig_a|b|c`.
- **Do not modify application code, schema, migrations or tests to make anything pass.** Report exact
  output back to Claude (Claude.ai). Claude Code = run + report, never fix-to-green.

## Step 1 — Fresh install (the most important check this release)
On an **empty** database run `/install.php` in the browser (or your usual install path). It must complete
with no "Migration failed" message. This exercises `124_healthcare_only.sql`, `125_healthcare_cleanup.sql` and the 122 fix.
Then confirm the new columns:
```
mysql <testdb> -e "SHOW COLUMNS FROM tenants LIKE 'group_npi'; SHOW COLUMNS FROM tenants LIKE 'clia_number';"
```
Expect one row each.
The installer form now has a **key-backup passphrase** field (≥12 characters, entered twice): it is required, and the installer
finishes by offering the `.flxkeys` download. Served over plain `http://` (dev) the installer writes an `http://` `app_url` and
`hsts = false`; served over HTTPS (or behind a proxy sending `X-Forwarded-Proto: https`) it writes `https://` and `hsts = true`.

## Step 2 — Everything automated
*Prerequisite: a config file for DB credentials — either `app/config.php` (created by `install.php`) **or** a file named by the `FLX_CONFIG` environment variable (then `app/config.php` may be absent: `index.php` and the runners honour `FLX_CONFIG`). The runners use isolated throw-away databases and never rewrite your config.*
```
bash tools/runtime_check.sh
```
Stages: 1 syntax · 2 isolation · 3 agency tests · 3a healthcare-only checks · 3b purge tests ·
**3c provider-scope static guards (new, no DB)** · **3d authorization suite `tests/authz/run.php` (new — cross-tenant, need-to-know
in SQL, LIMIT/COUNT correctness, capability matrix, scoped writes, portal, fail-closed, migration-125 data)** ·
**3e migration scenarios A–D `tests/migrations/run_scenarios.php` (new)** · **3g v1.171.0 security suite `tests/security/run.php` (new)** · **3f HTTP smoke `tools/http_smoke/run.sh` (new: logs in as 7 smoke users: owner, manager, staff, coordinator, read-only, restricted, platform admin, asserts authorization probes, crawls ~220 pages per role, fails on any 5xx / PHP warning)** · **3f2 real web installer over http and https `tools/http_smoke/installer_check.sh` (new v1.171.5)** · 4 route smoke test · 5 no stray `.notif-sweep-*` files in `app/storage` (new v1.171.5). The HTTP smoke now FAILS (not passes) when a crawl reaches fewer than 8 pages or mostly redirects. The characterization loader now prints `MIGRATION ERROR …` lines instead of hiding them —
any such line is a real failure to report.

## Step 3 — Upgrade path (optional, if you have a v1.170.6 test database with data)
Console → System → Database updates → apply. Expect `124_healthcare_only.sql` to apply cleanly. Then:
```
mysql <testdb> -e "SELECT vertical, COUNT(*) FROM tenants GROUP BY vertical;
                   SELECT name FROM roles WHERE slug='producer' LIMIT 3;
                   SELECT COUNT(*) FROM requirement_templates WHERE name LIKE 'FL Medicare Agent%';"
```
Expect only `healthcare` (and none of insurance/custom), "Provider (self-service)", and 0.

## Step 3b — Security spot-checks (v1.170.8) — use the demo org (Console → System → Build healthcare demo)
1. Sign in as **manager@coastalcred.example / FlxDemo2026!** → Team → try to change your own role (or add a user) to
   Master Admin → must be refused. The app says: "You can only assign roles that do not exceed your own permissions (Master Admin roles: Master Admin only)." (shown as a red/error toast).
2. As **demo-admin@coastalcred.example / coastal-demo-2026** (owner passwords differ per demo org; see docs/DEMO_ACCOUNTS.md) set `staff@coastalcred.example` to "assigned providers only" (Team → provider access) and assign
   ONE provider. Sign in as staff → **Credentials** list shows only that provider's credentials; opening another
   provider's credential URL → Not found; **Tasks** and **Calendar** show only that provider; Reports → credential
   export contains only that provider.
3. As **staff@…** open a provider page → loads (no 500).
4. As any user: Profile → Two-factor → try 9 wrong codes to disable → "Too many attempts".
5. Reports → Audit-Readiness → "Reporting year 2026" (calendar year, not 2027).

## Step 3c — Need-to-know & portal spot-checks (v1.170.9) — demo org
1. Staff set to "assigned providers only" with ONE provider assigned (see 3b-2). Sign in as that staff user:
   - **Dashboard**: provider count, expiring/expired counts, forecast, work queue and the 30-day throughput tiles reflect ONLY that
     provider; a notice says figures are limited to assigned providers; no other provider's name appears anywhere.
   - **Groups → any group / Locations → any location**: member lists and counts include only the assigned provider.
   - **Cases → work queue KPIs** count only the assigned provider's cases. Applications and screening KPIs likewise.
   - Paste another provider's credential id / document id into `/credentials/{id}/edit` and `/documents/{id}` → Not found.
   - Assign a second provider (Team) → refresh → appears immediately; unassign → disappears immediately.
2. Coordinator role: Credential edit page shows **no** "Verify"/"Mark verified" buttons (verification needs `documents.verify`).
3. Custom portal role (Team → Roles → new role, tick "Provider self-service login", grant only "View documents"):
   create a login on it linked to provider A. Sign in: lands on `/me` (never the tenant dashboard);
   `/documents/{id}` of provider **B**'s document → Not found; `/credentials/new` cannot pick another provider.
   A portal-role login **without** a linked provider → 403 message (no redirect loop).
4. iCal: copy a calendar subscription URL for the restricted user; open it → only assigned provider's events.
   Suspend that user → the same URL now returns 404.
5. Team: change a user's role → Audit log shows `user_role_changed` with from/to; granting Master Admin shows
   `owner_level_role_granted`; revoking a share link shows `share_link_revoked`.

## Step 3d — v1.171.0 spot-checks (browser)
1. **Roles ceiling**: as manager → Organization → Roles → create a role ticking any capability the manager does not hold → refused
   ("You cannot grant capabilities you do not hold yourself: …"). Editing a built-in role as manager → refused ("This role holds capabilities you do not have, so you cannot edit it." or the built-in/locked message).
2. **Session revalidation**: sign in as staff in one browser; as owner reset that user's password or suspend them → the next click in the
   staff browser lands on /login **with the flash "You have been signed out. Please sign in again."** (v1.171.5; shown once).
3. **Support access**: as platform admin (since v1.172.0 two-factor is required for the console only when Console → Security → Sign-in policy says so; with the requirement on, an administrator without it is redirected to Profile) → resetting a tenant owner's password
   without an active support grant → refused.
4. **Provider edit**: edit a PA/NP provider, save → provider type, specialty, CAQH, PTAN unchanged.
5. **Key backup**: console → System → "Encryption keys — backup" → download `.flxkeys` with a ≥12-char passphrase + your password → banner
   disappears after "I stored it". `php database/restore_keys.php <file>` prompts for the passphrase and prints the keys.
6. **Encrypted documents**: upload a PDF to a credential; open it (opens normally). On disk the file under docs_path starts with
   `FLXENC1` and contains no readable text. Legacy files: `php database/encrypt_existing_files.php --dry-run`, then without the flag.

## Step 3e — v1.171.1 spot-checks (browser)
1. **NPI**: add a provider with NPI `1234567890` → refused ("not a valid NPI"); `1234567893` → accepted. Same on Group NPI (Entity compliance) and CSV import.
2. **PR jurisdiction**: Organization → Lines & states → PR only. Provider page → CE panel says "last 36 months"; OCS/SICRO panel and Buena Conducta/ASUME readiness items appear. Switch to FL only → they disappear, CE says 24 months.
3. **Regulators** (PR tenant): "Junta de Licenciamiento y Disciplina Médica (JLDM)" and "Junta Examinadora de Enfermería" with a "PR nursing license" item; no "PR Board of Medical Examiners".
4. **Menu**: sign in as staff → no Groups / Locations / Applications / Regulators entries.

## Step 3f — v1.171.2 spot-checks (browser)
1. **SMTP**: Organization → Email → Own SMTP with host `127.0.0.1` or port `22` → refused with an explanation; a real provider (smtp.gmail.com:587) saves; "Send test" failures show one generic message.
2. **2FA policy**: Organization → Security → require 2FA for admins ON. Sign in as a manager without 2FA → every page redirects to Profile until enrolled; a custom role with "Manage team" is held to the same rule; Staff is not.
3. **TOTP**: enrol, then sign out and sign in: use a code; immediately sign out and try the SAME code again → rejected, wait for the next code → accepted. After enrolling, the page shows "On" (not "Off").
4. **Wording**: edit a provider → "Provider updated."; Audit-readiness package shows malpractice authority "insurer".

## Step 3g — v1.171.3 spot-checks (browser)
1. **Sign-in throttling — intended behaviour (decided v1.171.5)**: three layers, each per 15 minutes: per (e-mail, address) 8 failures; per address 30; per e-mail 12 **counted only while the attempt comes from an address that is not a "known place"**. An address becomes a known place only after a **complete** sign-in (password **and** 2FA), and stays valid for 30 days.
   (a) 13 wrong passwords for the owner's e-mail from ONE address → from the 9th that address is refused ("Too many sign-in attempts…"); those refused attempts do not add to the per-e-mail counter, so **one address can make at most 8 guesses per 15 minutes**. The correct password from a *different, never-seen* address is then accepted (this is by design — a single address cannot lock the owner out of the whole account).
   (b) Failures **spread across many addresses** (≥12 total for one e-mail from unknown addresses) → the per-e-mail limit trips and a never-seen address is throttled even with the correct password.
   (c) Shared NAT (documented residual risk): if someone behind the office NAT makes 8 wrong attempts, the owner is held for 15 minutes **from that address only**; from any other address they sign in normally.
2. **Invite link**: Team → invite a new user (mail can be off; copy the link if shown, or read it from the e-mail outbox). Open the link logged out → set a password → account works. In the database, `users.invite_token` for a pending invite is 64 hex characters, never the 40-character token in the link.
3. **Installer CSRF** (fresh install only): the installer form contains a hidden `_csrf` field; submitting with it removed or altered is refused.
4. **No mail on GET**: queue a notification/e-mail, then only open /admin and /compliance in the browser → the outbox row stays unsent until cron (`php cron/send_reminders.php`) or a POST action runs.
5. **Address encryption**: add a provider address (Home) → it displays normally on the provider page; in the database `addresses.line1/line2/phone` are NULL and `line1_enc` is binary. `php database/encrypt_existing_addresses.php --dry-run` reports 0 rows.
6. **Sealed database backup**: Console → System → "Download sealed database" with a 12+ character passphrase and your password → file `*.sql.gz.flxenc` (not readable as gzip). `printf '%s' '<passphrase>' | php database/decrypt_dump.php file out.sql.gz` → `gunzip -t out.sql.gz` succeeds; a wrong passphrase fails without output. A wrong account password or short/mismatched passphrase is refused.
7. **Cases / audit wording**: Cases → "All cases" shows provider names (not hex). Audit log shows "Create"/"Update"-style actions and readable record types (hover shows the ID).
8. **Calendar feed**: Calendar → Subscribe on a production install shows an `https://` URL.
9. **HTTP smoke without swapping config**: `git status`/file listing shows `app/config.php` was not renamed or rewritten by `bash tools/http_smoke/run.sh` (it uses `FLX_CONFIG`).

## Step 3h — v1.171.4 spot-checks (browser)
1. **Wording**: a provider page → the verification panel is titled "Verification records" and says FlxCoreHC does not independently perform or certify primary-source verification. Help → "Verification records" says the same (switch language to Spanish and check). The credential form panel has the same sentence.
2. **No "Fully compliant"**: Dashboard levels donut/legend, Compliance page KPI ("Providers with all tracked requirements current"), empty state, and a provider portal with no open items ("All your tracked requirements are current") — no screen says "fully compliant".
3. **PR wording**: a PR tenant → Regulators lists "Junta Examinadora de Enfermería" (not "…Enfermeras y Enfermeros"). Compliance → Catalog (`/credentials/requirements`) → "PR Continuing Education — physicians": the note says UNVERIFIED and does not state "10 hours / 6 hours". Application readiness → the drug-test certificate item says "Applicability UNVERIFIED".
4. **Invite**: invite a user, accept the emailed link once (works); open the same link again (refused).
5. **Address back-fill (SQL)**: create three address rows by SQL — all-legacy, line1-legacy with line2/phone ciphertext present, already-migrated — run `php database/encrypt_existing_addresses.php`; verify existing ciphertext bytes did not change and all fields read back in the UI.
6. **Memory characterization (optional)**: `FLX_CONFIG=<test config for a flx_* database> php -d memory_limit=256M tools/dump_memory_check.php 100000 400` — report database size, memory_limit, peak memory, result.

## Step 3i — v1.171.5 spot-checks (browser) — each item has an automated HTTP/DB regression test; these confirm by eye
1. **Forced sign-out**: as owner suspend (or reset the password of) a signed-in user → their next click lands on /login showing "You have been signed out…" once.
2. **Toast colour**: a refusal (manager promoting someone to Master Admin, wrong current password, "A valid email is required…", resetting a tenant user's password without a support session) shows a red toast; successes ("Role created.", "Provider added…", "Outbox flushed — N sent, 0 failed") stay green.
3. **No mail on GET**: queue an e-mail; open /admin/email as platform admin → the outbox row stays `queued`, attempts 0. "Send queued now" (POST) still sends.
4. **Read-only 2FA**: sign in as a read-only user → Profile → Two-factor → Enable works (since v1.172.0 a QR code is shown, with the setup key and a tap-to-add link). Data writes are still refused.
5. **Known place**: sign in with the correct password but abandon the 2FA prompt → that address is NOT recorded as known (`login_attempts` has no `known|…` identifier row for it until the 2FA step completes).
6. **OCS/SICRO**: FL-only tenant, provider with an OCS row → no "OCS / SICRO centralized credentialing" panel. PR tenant → panel shows.
7. **Regulators**: PR tenant → /regulators lists the PR boards: the JLDM under "State board" and "Junta Examinadora de Enfermería" under "Licensing board" (both visible; the two categories differ in the seed data, cosmetic).
8. **PR CME note after signup**: create a second organization (sign up), then Compliance → Catalog (`/credentials/requirements`) → "PR Continuing Education — physicians" still says UNVERIFIED (tenant signup no longer re-applies global catalog updates).
9. **Roles page**: "Master Admin" appears once; creating a role writes one "role_created" audit row; a Manager viewing Team sees "Master Admin" (never `tenant_owner`).
10. **Audit package** (Reports → Audit-Readiness → a provider): the "Requirement evidence" table lists each requirement by name, the regulator/authority, state, and the credential/expiry (or "No credential on file") — no blank rows.
11. **CSV import template**: Providers → Import → "Download template" → paste/upload it unchanged: preview shows 4 creates, 0 skips (valid, non-repeating NPIs).
12. **Legacy file encryption**: on a fresh install `php database/encrypt_existing_files.php --dry-run` exits 0 (the `.keep` placeholder is skipped).
13. **Installer**: over http the written config has `app_url` `http://…` and `hsts` false; behind `X-Forwarded-Proto: https` it is `https://…` and true.

## Step 3j — v1.171.6 spot-checks (browser) — each has an automated HTTP/DB regression test
1. **Provider edit never blanks fields**: open a provider → Edit → change only the role title → Save → name, NPI, e-mail unchanged. (HTTP: a POST with a single field, and a POST to an unknown sub-path such as `/holders/{id}/whatever`, leave the record intact; the latter is 404.)
2. **Toast colours**: refusals red, successes green (see 3i-2 list; includes adding a provider — "required credentials" in the text no longer turns it red).
3. **2FA cannot be swapped**: with 2FA on, Profile → Two-factor → no "Enable" path replaces the authenticator; posting `2fa_start` is refused ("already on… turn it off first") and the audit log shows `2fa_enabled` when you first enrol.
4. **CSV template**: 4 rows, 4 different valid NPIs; preview = 4 creates.
5. **Role names**: Organization → Roles → create "Front Desk Review", then "front desk review" → refused ("A role with that name already exists.").
6. **Stage 5**: `bash tools/runtime_check.sh` on an installation that has been used no longer false-fails on existing `.notif-sweep-*` files — it fails only on files the run itself created.

## Step 3k — v1.171.7 spot-checks (browser) — each has an automated test
1. **Toast colours (sweep items)**: "Support request denied." (after YOU denied it) green; importing a CSV where every row is bad ("0 provider(s) imported, 1 with errors.") red; partly good import amber; "No active link with that organization…" red.
2. **Role names**: "Front Desk Review" then "Front  Desk Review" (two spaces) / with a tab / "Admin" / "Viewer" / "Manager" → all refused ("A role with that name already exists.").
3. **Provider edit**: clearing first+last name and saving → "First and last name are required." (record unchanged); an invalid e-mail → "Enter a valid e-mail address."; changing only the role title still works.
4. **2FA**: with 2FA on, a refused confirm lands on Profile with the red message — no empty setup-key form.
5. **Per-account throttle**: abandoning 2FA after a correct password no longer resets the per-account failure counter (it resets only on a complete sign-in).
6. **Installer**: `mail_from` has no port (e.g. `no-reply@127.0.0.1`).

## Step 3l — v1.171.8 spot-checks (browser) — each has an automated test
1. **Role rename**: create "Rename A" and "Rename B"; edit B and rename it "rename  a" (two spaces) or "Manager" → refused ("A role with that name already exists."), name unchanged. Re-casing B's own name works.
2. **Organization edit**: open an organization with a licence code (e.g. CLIA), change only its name, save → the licence code is still there.
3. **Invisible names**: edit a provider and paste only a non-breaking space as the first name → "First and last name are required."
4. **2FA stale session**: start 2FA enrolment in browser A (do not finish), finish it in browser B, then press Confirm in A → red "already on" message on Profile, no setup-key form; the secret set in B still works.

## Step 3m — v1.171.9 spot-checks (browser) — each has an automated test (DB V8, HTTP N1–N3)
Rule under test: a name is stored *cleaned* (invisible characters removed, spacing collapsed) and compared *folded* (case, spacing, full-width, Cyrillic/Greek look-alikes, dotless ı ignored). Same rule at every entry point.
1. **Role create**: Organization → Roles → New: name of only zero-width spaces, or "x" + zero-width → "Enter a role name (2–80 characters)." "Renamе A" (Cyrillic е), "Ｒｅｎａｍｅ Ａ", "Rename­A" (soft hyphen) when "Rename A" exists → "A role with that name already exists." Same on rename.
2. **Global roles** (platform admin, /admin/roles): creating "Manager", "manager", "Master Admin", "Admin", "Viewer" or renaming to them → refused; two genuinely new names → created; tenant Roles page lists each name once and shows *View* for global custom roles.
3. **Provider / organization create** (Add provider and Onboarding): first name NBSP, last name zero-width, organization name empty or zero-width → refused, no row. "Ana" + soft hyphen saves as "Ana".
4. **Rename only**: a raw `action=update` POST with only `name` leaves the role's capabilities intact; the normal form with every box unticked clears them.

## Step 3n — v1.171.10 spot-checks — each has an automated test (DB V9, HTTP O1, installer check)
1. **Installer** (fresh DB): the "Server requirements" table lists "PHP extension: intl" as a requirement with OK. Optional, if you can run a PHP without intl: the installer refuses ("Requirement not met: PHP extension: intl…") and, on an already-configured production install (`app_env` = production), every page answers 503 "FlxCoreHC cannot start: PHP extension intl…".
2. **Settings → Organization name**: set it to only a non-breaking space → "An organization name is required.", name unchanged. Set it to "Acme" + soft hyphen + two ideographic spaces + "Health" → stored "Acme Health". Brand name likewise. Organization → Entity → Legal name likewise.
3. **Names still differ when they should**: roles "Office Manager" and "Office Managers" both allowed; "Oﬀice Manager" (ﬀ ligature) refused as a duplicate of "Office Manager".

## Step 3o — v1.172.0 (sign-in options, guided onboarding) — each has an automated test (DB groups P/Q/E/R/O/C, HTTP P/E/A/R/O)
Run the real-browser suite too if Playwright is installed: `FLX_CONFIG=<base config> bash tools/browser_check/run.sh` (isolated database `flx_browser`; exit 3 = not run).
1. **Console on a new install**: fresh install, sign in as the installer's administrator **without** two-factor → the platform console opens; a blue notice says two-factor is not required yet. Console → Security shows *Sign-in policy* with the administrators and their two-factor status.
2. **Policy**: ticking "Require two-factor…" while your own account has none → refused ("Turn on two-factor for your own account first…"); wrong current password → refused. After enabling two-factor on your account → the policy saves; another platform administrator without two-factor is then sent to their profile when opening the console. Untick → saves (audit entry `signin_policy_set`).
3. **Upgrade**: a v1.171.10 database with a platform administrator who uses two-factor, after "Update database" → the requirement is ON.
4. **QR code**: Profile → *Set up with an app* → a QR code, the setup key and four collapsible instruction blocks (Google Authenticator, Microsoft Authenticator, Authy, 1Password). Scan the QR with a real authenticator app: the code it shows turns two-factor on. View source: the QR is an inline `<svg>`; no request leaves the page for it.
5. **E-mailed codes**: with outbound e-mail off the option says "Not available yet"; with it on: *Use e-mail codes* → a 6-digit code arrives → enter it → two-factor on (method e-mail), backup codes shown. Sign out and in: the verification page says "We e-mailed a 6-digit code to x***@…"; a wrong code is refused; *Send a new code* invalidates the previous one; the newest code signs in; a backup code also works. Console → Email → Outbox: the rows are type `security`, status sent, with no code in the stored body.
6. **Forgot password**: sign-in page → *Forgot your password?* → an unknown address and a known address give the same message; only the known one receives a link; the link opens a new-password form; mismatched passwords are refused; after saving you are at the sign-in page (not signed in), the old password fails, the new one works, a session that was open before is ended, the link cannot be reused, and an account with two-factor is still asked for the second step.
7. **Guided onboarding**: Providers → guided onboarding → *A person* shows first/last name and person types; *An organization* shows organization name and only Laboratory / Clinic / Medical group — no first/last name. Create one of each and follow the four steps: the organization gets organization identifiers and a review step; the person gets specialty, birth month, CAQH and the portal step. In a billing-company organization the first step asks for the client group; in a solo practice "I am the doctor" fills in the owner's name and e-mail.
8. **CSP class**: on a case, change a requirement's status in the list → it saves (auto-submit or the Save button). Packet page: Print and "Select all" work. Calendar → subscribe: Copy works. The browser console shows no "Refused to execute inline event handler".
9. **Permission**: a role without `records.write` gets 403 on `/holders/onboard` (GET and POST).

## Step 4 — Browser spot-checks
1. **Sign up** (if open registration is on): no "Other" option; "Where do your providers practice?" shows
   Puerto Rico preselected. After sign-up, Organization → Operating jurisdictions shows PR ticked.
2. **Compliance**: footer reads the healthcare ruleset (no CMS-4208, no "FL commercial lines"); tab says
   "Providers".
3. **Provider page**: no NIPR box and no "I'd want this" button; tab "CME / CE" shows categories such as
   "CME — AMA PRA Category 1"; add an entry and see the total update (CE panel window: **24 months** FL, **36 months** PR. The provider checklist row shows "renews every 24/36 mo"; the Application-readiness CME row shows "PR recertification cycle: 36 months" for PR and, for other states, "Per licensing-board cycle" — deliberately not a number, because FL boards differ); no NPN anywhere.
4. **Entity compliance** (menu): fields Group NPI, taxonomy, PTAN, Medicaid ID, CLIA, facility licence,
   accreditation. Save with Group NPI `123` → error "must be 10 digits"; CLIA `40D1234567` → saves.
5. **Share a verification link** → open it logged out: shows "Provider", NPI, specialty; no "Producer"/NPN.
6. **Reports → Audit-Readiness package**: titled "Credentialing Audit-Readiness Package", "(Provider)".
7. **Cases → New case**: provider, client group and payer are dropdowns (no id text boxes).
8. **Calendar → new event**: no "Client call" type, no "Client name" field.

## Report back
Full output of `bash tools/runtime_check.sh` (especially stages 3d and 3e), the install result from Step 1, and each browser check result (Steps 3b, 3c, 3d, 3g, 3h, 3i, 3j, 3k, 3l, 3m, 3n, 3o, 3p, 3q, 4). Report each as EXECUTED — PASS / EXECUTED — FAIL / REQUIRES BROWSER VALIDATION.

## Step 3p — v1.172.1 ("Add provider" for a person and for an organization) — automated as HTTP O13–O17 and two DB checks
1. Providers → Add: "A person" tab shows First/Last name, Profession (physicians, nurses…), Birth month, Role/title, portal-login e-mail. No organization name, no laboratory/clinic/group types.
2. Providers → Add → "An organization" tab: Organization name, Organization type (Laboratory, Clinic / Facility, Medical group), Organizational NPI, optional contact e-mail. No first/last name, birth month, role/title, no professions.
3. Save one of each; open Edit on both: each shows only its own fields. Organizations have no "Date of birth / SSN" block.
4. Try to break it: submit an organization with first_name/last_name/birth_month in the request (and a person with org_name / provider_type=lab) — nothing of the other kind may be stored.
5. Database health (Console): "Tenant scoping" must be OK. Light theme: the logo area of the side panel is light; dark theme unchanged.

## Step 3q — v1.172.3 to v1.172.10 (e-mail rebuild, platform audit log, dates in local time) — automated by `tests/mail/test_v1172_4.php`, `tests/i18n/test_v1172_3.php`, `tests/dates/test_v1172_6.php` and test groups RM/OP/ML/MG/AU/MX in `tests/security/test_v1171.php`
1. **Dates and times.** With App timezone = America/New_York, open Organization → Audit log, Notifications, Calendar, Billing, a provider page and Organization → Sources. Every date is MM/DD/YYYY, every time is 12-hour local, and the word "UTC" appears nowhere (the System page's timezone list is the only exception). A credential whose expiry is 03/13/2027 never shows 03/12/2027. Try to break it: set the App timezone to Pacific/Honolulu and to UTC and confirm the displayed times move accordingly. CSV exports must still be ISO/UTC.
2. **E-mail options.** Organization → Email delivery offers only Platform default and Own SMTP (plus the separate Connect Microsoft 365 panel); Console → Email Quick fill offers only cPanel. No Google/Gmail option anywhere.
3. **SMTP client.** Console → Email: **Test connection only** (nothing is sent), **Send test email** (works while "Send email" is off), wrong password → plain-language failure at the login stage, wrong host/port → generic connect message (no OS error text), certificate mismatch with "Verify the server's security certificate" ticked → failure. The saved password is never pre-filled and re-saving with the field blank keeps it. Try to break it: header injection in From name / subject (CR LF), a port outside 25/465/587/2525, a private-network host.
4. **Microsoft Graph** (needs a real Entra app; otherwise report REQUIRES BROWSER VALIDATION): connect, test, wrong secret message, secret-expiry notice (info ≤ 60 days, error ≤ 14 days or expired).
5. **Platform audit log** (`/admin/audit`): platform administrators only (403 for organization users), filters, CSV export; secrets and passwords never appear in the details.
6. **Create organization**: the administrator TYPES an optional temporary password (10+ characters; nothing is generated). It is never shown again, never logged, and the owner signs in with it; left blank, an invite link is shown once instead. A 9-character password is refused.
7. **Spanish.** Switch to Spanish and visit the main pages; report untranslated labels.
