# FlxCoreHC — Changelog

*Current version: **1.175.0**. Newest first. Releases before v1.166 describe the retired insurance-agent edition; the product is healthcare-only since v1.170.7.*

---

### v1.175.0 — Licensing: signed license records and one entitlement check (migration 138)

Ownership & White-Label Standard, licensing (step 12) for the hosted service: the platform console is the License Hub (decision D7), the license is a record per organization.

- **Signed records:** Console → **Licenses**. A license has a serial (`LIC-YYYY-NNNN`), agreement number, licensee, workspace ID, kind (Paid / Free / Pilot), plan, modules, user limit, term start and end, notes, issue time and deployment (`hosted`). Those fields are signed with **Ed25519** (libsodium); the key id and signature are stored beside them. The private key is kept sealed in the platform settings; the public key is written to `app/config.php` (`license_public_key`) by the installer (an installation without one makes the pair on first use). Records are never edited: a renewal is a new record (the newest valid one wins), a revocation sets `revoked_at`. Issuing and revoking are audited.
- **One entitlement check** (`Entitlement::forTenant`), used by the plan-feature gate, the Reports menu, the user limit and About & ownership. States: **licensed** (valid record within its term → its modules and user limit) · **grace** (term ended less than 14 days ago) · **trial** (no usable license, within 30 days of the organization's creation → every module) · **core** (no usable license and the trial is over → no optional module; nothing is deleted) · **internal** (the operator's own organization and demo organizations → everything, no end). A record that is revoked, not yet started, past its grace, whose signature does not verify, or that was issued to another workspace is ignored (and the console flags "altered record").
- **The old fail-open is gone:** before, an organization with no plan got every feature. Now it gets its trial, then the core only.
- **Optional modules** a license can switch on: Document management, Scheduling, Analytics & reports, Provider network. The core (providers, credentials, tasks, calendar, compliance, team, audit) is always on.
- **About & ownership** shows License state, serial, days left, agreement number, term, plan, modules and user limit, and "No license record installed" when there is none.
- **Not built** (self-hosted installations are not offered): the signed license *file*, link codes and the daily check-in, signed update bundles, billing/charges and the overdue read-only rule (plan steps 13–14).
- Tests: `tests/license/` (database suite, stage 3d3, 48 checks incl. term edges, tamper, moved record, renewal, revocation; negative controls run: signature check neutered → 12 failures, workspace/tamper check removed → 2) and HTTP checks LI1–LI9.

---

### v1.174.0 — Database tables are named `fchc_<name>` (no migration; new installations)

- **Every table of a new installation carries the prefix `fchc_`** (`fchc_tenants`, `fchc_users`, `fchc_credentials`, … — 93 tables). The installer creates them that way and writes `db_prefix` to `app/config.php`; an installation without that key (made before this version) keeps its unprefixed tables and works as before.
- **How:** the application keeps writing logical names in its SQL. All SQL goes through one door (`Database::pdo()` → `PrefixedPdo`), which runs each statement through `SqlPrefixer`, a small tokenizer that puts the prefix only where SQL expects a table (after FROM / JOIN / INTO / UPDATE / TABLE / REFERENCES / RENAME … TO, in comma-joined FROM lists, and as the qualifier of a column) — never in strings, comments, column names or aliases. It also handles `information_schema … TABLE_NAME = 'x'` comparisons and dynamic DDL kept in a string for `PREPARE`. Statements are cached.
- **Prefix-aware places:** the installer (also: a re-install over a non-empty database now drops only `fchc_` tables, not every table in the database), backups and the demo-data remover (only `fchc_` tables), the Database page (shows the real table names, searchable by either name), the migrator's "is this an old install?" test, and three existence checks. `database/tables.php` lists the logical names and `database/tables_legacy.php` the retired tables that old migrations create or drop; `tools/db_tables.php --check` keeps both in step with the migrations.
- **Tests:** `tests/db/test_v1174_0.php` (stage 3a17; 26 rewriter cases and all 461 migration statements rewritten without touching anything but table names), `tools/db_tables.php --check` (stage 3a18); the database suites (agency, purge, authz, security, time zone), the HTTP smoke test, the browser suite and the installer now all run on prefixed tables, and the installer check verifies that a real installation has 93 `fchc_` tables and none without the prefix.
- Not done: converting an existing unprefixed database (there is none in production); a shared database holding several installations is still not supported.

---

### v1.173.4 — Documentation and names: a retired product name, a customer domain and a hosting provider removed (no migration)

- **The product's retired working name is gone from every file in the package, history included** (install guides — zip name, folder and database examples now `flxcorehc` —, cron comments, the installer placeholder, downloaded file names `flxcorehc-export-…zip`, `flxcorehc-db-….sql.gz`, `flxcorehc-docs-….tar.gz`, `flxcorehc.ics`, the calendar-feed event ids `…@flxcorehc`, an internal config variable `flxcorehc_config`, old changelog and hand-off wording). A customer's web address and database name that appeared in `PRODUCT.md` and `INSTALL.md` are replaced by generic examples. The ownership test (O4/O5) now searches every shipped file, changelog included, for these names, so they cannot come back.
- `PRODUCT.md` and `SPLIT.md` named constants that no longer exist; now `FLXCOREHC_EDITION`.
- **INSTALL_GUIDE.md brought up to date**: the `intl` extension is listed as required, the installer's platform time zone, key-backup passphrase and development-mode fields are explained, the four cron jobs and the System page that prints their lines, and the first things to set up (time zone, Terms of Use, sign-in policy). The hosting-provider name is removed from the install docs and the changelog.
- Downloaded file names and calendar-feed ids changed: nobody has subscribed or exported yet (fresh installation).

---

### v1.173.3 — Terms of Use editor, cron lines, time-zone confirmation (migration 137)

- **Terms of Use can be edited in the app** (owner's request): Platform console → **Terms of Use**, English and Spanish side by side, in a plain-text format (`# Heading`, paragraphs separated by a blank line; `{organization}`, `{product}` and `{owner}` are filled in). Only platform administrators can open it (organization owners get 403). **Every save publishes a new version** (`YYYY-MM-DD.N`, table `terms_versions`, append-only, never edited or deleted, audited as `terms_published`) and **everyone accepts it again** at their next sign-in; a confirmation tick is required. A banner field (empty = final) controls the "DRAFT" notice per language. Text with a heading but no paragraph, or with an unknown placeholder, is refused. With no published version the Terms shipped in `app/terms/` apply. *Deviation from the standard* (which says the text cannot be edited from a screen): approved by the owner; limited to the platform operator, every version kept.
- **Scheduled jobs (cron):** System & updates lists the four cron lines built at run time from this server's PHP binary and this installation's folder (no account path in code).
- **Time-zone change asks for confirmation** (platform and organization pickers): the page asks only when the selected zone really changed.
- Not built: the "minor change → notice" path (every save is a material change).
- Tests: `tests/ownership/test_v1173_3.php` (stage 3a16) and HTTP checks TE1–TE10 (run last in the smoke test).

---

### v1.173.2 — Terms of Use acceptance (migration 136)

Ownership & White-Label Standard, element 10 / step 11. The Terms **text is a draft written by Claude Code** (the owner did not supply one); it is marked "DRAFT — for attorney review" everywhere it appears and is replaced by editing `app/terms/en.php` / `es.php` (nothing can edit it from a screen).

- **Every user accepts at their first sign-in.** Until they do, every page except `/terms` and sign-out redirects to `/terms/accept`, which shows the full Terms with **I agree** / **Sign out**. The platform operator (the owner) is exempt. Provider-portal users accept too.
- **Versioned.** `version` in `app/terms/en.php` ("1.0-draft"). Changing it (a material change) makes everyone accept again at their next sign-in. The session remembers the acceptance per user and per version.
- **Accepting for the organization** is its own permission, `terms.accept` ("Terms of Use · accept for the organization"), held by the top administrator (Master Admin) only by default; it can be given to a role in the Roles editor. The person ticks "I have authority to accept these Terms for <organization>" and selects I agree; the server ignores the organization fields from anyone without the permission.
- **Every acceptance** is a row in `terms_acceptances` (append-only: nothing updates or deletes it) and an audit row `terms_accepted` with user, organization, version and scope.
- **Who has accepted:** Team page has a "Terms of Use" column (accepted date or "Not accepted"); About & ownership (full view) shows the version and whether, and by whom, the organization accepted.
- **Text rules of the standard:** the Terms name only the organization (by its own name, taken from its settings) and the product; the owner is named once, in the independent-developer term, through the ownership file. Effective date = the start of the workspace. English controls; in Spanish a courtesy translation follows (draft).
- **Not built yet (open items in the adoption record):** the "minor change → notice, use after 30 days is acceptance" path (only the material-change path exists), and the subscription terms and data processing addendum for hosted tenants (legal documents not yet drafted).
- Test fixtures: every smoke-test user has accepted the current version, except `terms@smoke.test`, created for the acceptance tests.
- Tests: `tests/ownership/test_v1173_2.php` (stage 3a15; negative controls run: gate removed, permission check removed) and HTTP checks TA1–TA15.

---

### v1.173.1 — Ownership standard: LICENSE.txt, third-party notices, file headers, product-name protection, PDF cover/footer (no migration)

- **LICENSE.txt** in the package root (draft for attorney review; LF line ends): the nine sections of the standard (OWNERSHIP, INDEPENDENT DEVELOPMENT, LICENSED, NOT SOLD, RESTRICTIONS, THE LICENSEE'S DATA, OTHER NAMES, THIRD-PARTY COMPONENTS, WARRANTY). A test checks that the eight license lines the product shows appear in it word for word. The **WARRANTY** section is my draft (the standard gives no wording) — for the attorney.
- **THIRD-PARTY-NOTICES.txt**, generated by `tools/third_party_notices.php` from the lock list `tools/third_party.json` (IBM Plex Sans/Mono and Archivo under SIL OFL 1.1, Tabler Icons under MIT). `--check` fails if the file is out of date or a third-party file in `assets/fonts` or `assets/vendor` is not listed. FlxCoreHC has no Composer/npm lock, so the JSON list is the lock.
- **Ownership header** (`© 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.`) in the first comment of all 458 program files (PHP, JS, CSS, SQL, Python, shell), added by `tools/ownership_headers.php`; `--check` lists any file without it and is part of the runtime check. Output of every page is unchanged (a template that starts with HTML gets a one-line PHP comment whose closing tag swallows its own newline). No bundle banner: there are no minified bundles.
- **Product-name protection:** every mention of the product in an ownership statement, the sign-in line and the Terms page is followed by an invisible U+2060, so a rebrand/pack script or a name substitution can skip it.
- **About & ownership (full view)** now shows LICENSE.txt and the third-party notices in scrolling boxes; the short view carries neither.
- **PDFs:** the credential package has the product and product line on its cover band and the copyright line in every footer; the credential summary / CV PDF footer has "Powered by … · copyright".
- Removed a stray compiled Python file from the package and ignored `__pycache__`.
- Tests: `tests/ownership/test_v1173_1.php` (stage 3a14; negative controls run: altered license line, header removed, notices edited, unlisted font) and HTTP checks OW13–OW15.

---

### v1.173.0 — Ownership & White-Label Standard: steps 1–5, 7 (part), 8 (migration 135)

Source: the owner's "Ownership & White-Label Standard" (Nov 1, 2026) and the adoption plan `docs/plans/OWNERSHIP_WHITE_LABEL_ADOPTION_PLAN.md`; decisions D1–D6 taken as recommended. Adoption record: `docs/OWNERSHIP_ADOPTION_RECORD.md`.

- **Ownership file** `app/src/Ownership.php`: owner (Indisse-BTS), copyright line, developed-by sentence, independent-developer statement and the eight license lines, in the standard's exact wording. Nothing in the app can change them.
- **About & ownership** (`/about`, also `?format=json`): short view for everyone signed in; the full view (this workspace: owner, licensed to, agreement number — "No license record installed" until the licensing release —, deployment model, plan and modules, user limit and users in use, name shown, workspace ID, the eight license lines) only with the new permission **`license.view`** (Master Admin, Manager and Admin by default; can be given to any role). The server decides; the short answer carries no full-view field. In Spanish the English legal text stays and a courtesy translation (informational, draft for attorney review) is shown under it.
- **Sign-in page:** "Powered by FlxCoreHC · © 2026 Indisse-BTS. All rights reserved.", "Proprietary software, licensed, not sold." and a **Terms of Use** link to a public `/terms` page (draft notice until the Terms text is supplied).
- **Help Center:** About & ownership button at the top and the copyright line in the footer; Settings has a link too.
- **Organization name** changes are audited old → new (`org_name_changed`, `org_display_name_changed`); saving without a change writes nothing.
- **Workspace ID** (migration 135, `tenants.workspace_id`): `XXXX-XXXX-XXXX-XXXX` uppercase hex, random, made once on first need, never editable, unique.
- **Sweep:** a personal name removed from `KICKOFF.md`, a test and a form example; the owner's admin e-mail removed from archived memos; new sweep test (names passed in through `FLX_SWEEP_NAMES`, never kept in the source).
- Tests: `tests/ownership/test_v1173_0.php` (no database, stage 3a13, negative controls run) and HTTP checks OW1–OW12 in `tools/http_smoke/drive.py`.

---

### v1.172.17 — Spanish screens: Help frame, audit package, joined messages, catalog names (no migration)

Source: the Spanish screens review of v1.172.16 (`docs/SPANISH_SCREENS_REVIEW_v1.172.16.md`): seven roles, ≈1,200 pages, 10,504 distinct texts.

- **Help Center:** the page frame (menu, "Sign out", theme, profile, back-up reminder) is now translated; before, the whole page skipped the translator. The topics themselves are per-language files and are kept as they are (new `<!--flx-notranslate-->` region understood by the translator).
- **Audit-readiness package** (`/reports/audit/…`) is printed without the normal page renderer and was never translated: headers, the ten security-checklist lines and the footers are now Spanish.
- **Joined messages rewritten with placeholders** so they translate: "N of M permissions", "Full access (all N permissions)", "N of M users with 2FA", "Recommended next steps (N)", "N entries recorded", "N user(s) have not enrolled in two-factor…", "Generated …" (and the hard-coded "ET" suffix is gone — the time zone abbreviation of the organization is shown), "N credential types tracked", Active/Expiring/Expired (N), "Choose an icon for …", "N users", "N defined", "N seats · N providers", "N/M done", "N% overall", "Credentials (N)", "Set up your groups", "N gaps", "N missing", "N/M active · score". The calendar hint no longer says "one groups".
- **Month and weekday names** follow the language (calendar title, dashboard forecast labels, weekday headers).
- **Catalog names and notes** stored in the database (credential types, requirement names, regulator notes, provider-type names) have Spanish entries (≈170 added to `es.php`); the translator already applies them on every screen. Proper names (The Joint Commission, NPPES, payers) stay as they are.
- Not translated yet: Training articles (≈15) and some Help-adjacent long-form text; platform-admin pages stay English.
- Tests: `tests/i18n/test_v1172_17.php` (no database, stage 3a12).

---

### v1.172.16 — Time zone per organization (migration 134)

Why: Puerto Rico stays on AST (UTC−4) all year while Florida switches between EST and EDT, so one zone for the whole installation is wrong as soon as organizations in both places share it. Timestamps are, and stay, stored in **UTC**; the zone only decides which clock time and calendar day are shown and used.

- **New column `tenants.timezone`** (migration 134). NULL means "use the platform zone", so every existing organization behaves as before. Allowed zones: Puerto Rico, Eastern, Central, Mountain, Pacific, Alaska, Hawaii, UTC (one list, `flx_timezones()`).
- **Settings → Organization → Time zone** (organization owner) picks the organization's zone, or "Platform default". Admin → System now labels its zone "Platform time zone" (the default for organizations without their own).
- **Installer** asks for the platform zone (default Eastern) and writes it to `config.php`. `config.php` is now used whenever the operator has not set a platform zone (before, only when the setting happened to be Eastern).
- **Every request** uses the signed-in organization's zone for dates, clock times, "today", overdue tests, calendar, reports, PDFs and e-mails; sign-in pages and platform pages use the platform zone. `@flx_today` follows the organization chosen.
- **Nightly jobs work one organization at a time**: `cron/sweep_expiring.php` (credential expiring/expired), `ReminderService::runAll` (reminder e-mails and notifications) and the activity digests use each organization's own day.
- Tests: `tests/tz/` (database suite, stage 3d2, also run with the clock set to 06:00 UTC when `faketime` is installed — then Puerto Rico and Eastern are on the next day and Honolulu is not; reverting the sweep to one global day fails it) and `tests/dates/test_v1172_16.php` (source guards, stage 3a11).
- Not changed: a user's personal zone (all users of an organization see its zone); booking pages keep their own zone (migration 017).

---

### v1.172.15 — Daylight-saving edge cases; overdue marked per item (no migration)

Source: the "not changed" list of v1.172.12–13 (owner asked to work through the date edge cases).

- **A time that does not exist is refused, not changed.** Typing 02:30 on the spring-forward day (e.g. 03/14/2027 in New York) used to be saved silently as 03:30. Creating, editing or dragging an event onto such a time now shows "That time does not exist on that date (clocks move forward). Choose another time." and saves nothing. All-day items are unaffected.
- **Re-saving the second 01:30 of a clocks-back night keeps it.** Editing an event without touching its time keeps the stored instant; before, the second 01:30 turned into the first on every save. Typing 01:30 on that night for a new event still means the first occurrence.
- **Month grid:** a day is no longer tinted red as a whole when any item on it is overdue. Each overdue item is marked itself (red, bold, "— overdue" in its tooltip); other items on the same day stay normal.
- Unchanged by design: events saved before v1.172.11 keep their typed-as-UTC value (see the note in the 1.172.11 entry; needs an owner decision), and the other two edge cases are now closed.
- Tests: `tests/dates/test_v1172_14.php` (no database, stage 3a10): 26 checks. Verified live on PHP 8.3.6: create at 02:30 (spring) refused, edit of the second 01:30 kept, month cell not tinted, only the early item marked.

---

### v1.172.14 — Subfolder detection no longer trusts a non-script SCRIPT_NAME (no migration)

Source: first run of v1.172.13 on PHP 8.3.6 (960 passed, 1 failed: `B4 restricted user cannot export the audit log` returned 404).

- **Cause:** PHP's built-in server (8.3) sets `SCRIPT_NAME` to the request path itself for a URL that has a file extension, so `/audit/export.csv` made `index.php` and `bootstrap.php` compute the subfolder base as `/audit`, strip it, and route `/export.csv` → 404 for **every** user. Apache/cPanel reports `/index.php`, so production was not affected; the fault was in the test server only, but the code trusted a value it should not.
- **Fix:** the web base path is taken from `SCRIPT_NAME` only when it ends in `.php` (`index.php`, `bootstrap.php`). Subfolder installs are unchanged.
- First release developed in this repository (Claude Code); `KICKOFF.md` roles updated.

---

### v1.172.13 — Spanish flash messages, installer secure cookies (no migration)

Source: Claude Code's independent run of v1.172.12 (951 checks passed; 3 Spanish leftovers).

- **Three English messages in Spanish sessions fixed**: "Your account is ready — please sign in.", "Public sign-up is disabled…", and the `/verify/<bad>` body.
- **Every complete flash/service message now has a Spanish entry** (about 250 added). Messages built by joining English text with a name/amount/status were rewritten as placeholder messages (`t('Assigned to {name}.', [...])`) so they translate too.
- **New guard test** (`tests/i18n/test_v1172_13.php`) lists every `flash('…')` / service `return [bool,'…']` literal missing from `es.php`. 22 operator/console joined-English messages are frozen in an allow-list; any new one fails.
- **Installer**: on an HTTPS install (including behind `X-Forwarded-Proto: https`) `force_secure_cookies` is now set to true and its checkbox is pre-ticked.
- Unchanged by design: the date edge cases (whole-day overdue on the month grid, DST fall-back/spring-forward times, events saved before 1.172.11) and registration with an e-mail already used in another tenant (owner decision pending).

---

### v1.172.12 — Nightly sweep, month-end events, agenda rule (no migration)

Source: Claude Code's independent run of v1.172.11 (941 checks passed; 3 material findings + Spanish leftovers).

- **The nightly cron sweep undid the v1.172.11 fix.** `cron/sweep_expiring.php` still compared with `CURDATE()` (the database's UTC day) and flipped a credential expiring TODAY (organization day) to `expired` a day early. It now uses `@flx_today`. The v1.172.11 check "no CURDATE() left" only scanned `app/` and `index.php`; the new check scans app/, cron/, tools/, index.php and install.php.
- **A month-end evening event vanished from its own month.** The calendar window compared the organization's local month bounds with UTC values, so Honolulu 12/31 23:30 (stored 01/01 09:30 UTC) appeared in January and New York 10/31 23:30 in November. The window now converts the local day bounds to UTC for timed events (all-day items are compared as their own day) in the grid, list and per-day counts.
- **Agenda and banner disagreed for a timed event that already passed today.** The agenda now uses the same rule as the banner and the list: an all-day item is overdue once its day has passed, a timed one at its instant.
- Reminder e-mails and the reminder digest show MM/DD/YYYY ("expires on 10/15/2026 (today)").
- Spanish: sign-in tagline, "Email or password is incorrect.", registration messages and "What are you?", the two-step code message, the rate-limit message, and the state names that were still English (New York, New Jersey, the Carolinas, the Dakotas, West Virginia, ...).
- **Not changed:** the Month grid highlights a whole day as overdue when any item that day is overdue (design); a second 01:30 on the DST-fall-back day re-saves as the first occurrence, and a non-existent spring-forward time (02:30) is stored as 03:30 (rare; documented); events saved before v1.172.11 keep their typed-as-UTC value.
- Tests: `tests/dates/test_v1172_12.php` (no database, stage 3a8) and `tests/ui/test_v1172_12_ui.py` (cron run against the demo database, month ends in Honolulu and New York, agenda/banner, Spanish). 9 deliberate breakages, all caught.

---

### v1.172.11 — "Today" is the organization's day; typed times are stored in UTC (no migration)

Source: Claude Code's independent run of v1.172.10 (922 checks passed; 2 date failures, English leftovers on the sign-in pages, one PDF date).

- **A credential expiring today was marked expired a day early.** "Today" was the server's UTC day in `credential_status_for()`, in the calendar agenda / overdue banner and in every database-side date comparison (`CURDATE()`, which was UTC because the connection runs in UTC). All of them now use the organization's calendar day: new `today_local_dt()`, and the connection sets `@flx_today` (used where `CURDATE()` was).
- **Calendar agenda and banner** agree with the month grid: an all-day item due today is "Due this week", not "Overdue", and the banner counts an all-day item as overdue only after its day has passed in the organization's zone. A timed event is placed in the agenda by its local day.
- **A timed event entered at 23:30 in Honolulu showed as 1:30 PM.** Create, edit and drag-move now treat the typed time as the organization's wall-clock time and store UTC (`local_to_utc()`); the edit window shows the same time back. Events saved by earlier versions were stored as typed and are not rewritten.
- Package PDF evidence index: "[verified 10/16/2026, primary source]" instead of an ISO date.
- Sign-in pages: the last English strings are translated (sign-in "New here?", registration intro and fields, invitation page, organization picker, the "(Team → Set password)" hint).
- **Not changed:** the platform-administrator "create user" length rule could not be exercised by the validator (it needs a support session) - it shares the 10-character rule of v1.172.10. The OAuth callback for a tenant user without a pending OAuth session still redirects to the (403) e-mail page.
- Tests: `tests/dates/test_v1172_11.php` (no database, stage 3a7) and `tests/ui/test_v1172_11_ui.py` (browser + demo database, runs in whichever of UTC-11 / UTC+14 has a different calendar day than UTC). 10 deliberate breakages, all caught.

---

### v1.172.10 — Fixes from the independent v1.172.7 validation (no migration)

Source: Claude Code's independent run of v1.172.7 (884 checks passed; 3 material findings, one doc mismatch, one Spanish gap, several observations). Items already fixed in v1.172.8/9 were re-checked against this tree and are not repeated here.

- **Own-SMTP save crashed (HTTP 500) for any host name that does not resolve.** `gethostbynamel()` returns `false`; cast to an array that became `[false]` and reached a `string` parameter as a TypeError. Resolver results are now filtered to real address strings; an unresolvable host shows "The SMTP host name could not be resolved." (also guarded for the test hook).
- **All-day deadlines moved back a day west of UTC-9.** An all-day item is a calendar day, not an instant; its stored 09:00 UTC is only a placeholder. The month grid, list, agenda and "Next 30 days" now use the stored day, show a date (no clock time), and count as overdue only after that day has passed in the organization's zone.
- **Raw ISO dates on the provider page.** Remediation target date, OCS attestation expiry, document-request "by" date and the History tab (which printed `issued_date: 2025-09-08` and UTC timestamps) now show MM/DD/YYYY and local 12-hour time. Display only: the stored audit text is unchanged (`fmt_iso_in_text()`).
- **Sign-in pages had no Spanish.** Login, forgot/reset password, register, invitation and the two-factor step now follow ?lang=es|en, then a small functional cookie (`flx_lang`, 1 year), then the browser's Accept-Language (Spanish only). They carry an English | Español switch, and signing out keeps the language you were using.
- **Temporary password minimum is 10**, like every other password in the app (was 8 on Create organization and Create platform admin).
- **Microsoft sign-in callback** no longer shows text taken from the link's query string; it shows a fixed message and records the error code in the audit log.
- Onboarding "No client group yet" / "the client this record belongs to" are translated.
- **Not changed, by design:** Create organization lets the admin TYPE a temporary password (nothing is generated and "shown once"); the Step 3q checklist wording should say so. The platform-console SMTP is operator-level and intentionally not guarded (reported for the owner's decision). The audit trail text stays as recorded (English).
- Tests: `tests/i18n/test_v1172_10.php` (no database, stage 3a6) and `tests/ui/test_v1172_10_ui.py` (browser, sandbox demo). 16 deliberate breakages, all caught.

---

### v1.172.9 — New-event types, stale e-mail hint, and the Spanish gaps (no migration)

- **New event → Type** now lists the event types your organization has configured (Calendar → Event types), not a fixed Task / Appointment / Note. Before, a custom type could not be chosen, and "Task" was not a real type, so an event saved as "Task" was stored as "Meeting". Editing an event keeps its saved type even if that type was later switched off.
- **Document requests**: the hint and the Help article said "nothing is emailed". A request does e-mail the provider and every office contact flagged for requests — a link to the portal only, never documents. The wording now says so.
- **Spanish.** The translator skipped any text longer than 200 characters, so every long description and hint stayed English (requirement and entity descriptions, calendar subscribe page, tracked-documents, templates, reports, security notes). Long text now translates on an exact match, also when the paragraph is wrapped over source lines; long text that has no translation is never written to the missing-strings log (it may be a user's own note).
- Sentences with a name or number inside were built by joining pieces of English and could not be translated; they now use placeholders: "Add a credential for {name}", compliance hard-stop reasons on the dashboard, readiness details on the provider page ("4 of 7 met, 2 need attention"), the CE-hours hint, the plan seats line, the security checklist, group labels ("Añadir Grupo cliente" instead of "Añadir Client group"), "{n} min read", "{n} min ago".
- **Notifications and auto-generated calendar items** are stored in English by the sweep; they are now translated when shown (stored text unchanged). A manual event is never rewritten.
- **Public share page** (`/s/…`): the recipient is not a user, so it now follows the browser language (Spanish only; anything else stays English), `?lang=es|en` overrides it, and a small English | Español switch is shown on the password, profile and "link unavailable" pages.
- 219 Spanish entries added (requirement catalogue, hints, checklist items, console create-organization form).
- Still English on purpose: the audit trail (history is shown as it was recorded), provider and organization names, file names, demo data.
- Tests: `tests/i18n/test_v1172_9.php` (no database; in `tools/runtime_check.sh` stage 3a5) and `tests/ui/test_v1172_9_ui.py` (browser, needs a running demo server; not part of runtime_check). 8 and 11 deliberate breakages respectively were each caught.

---

### v1.172.8 — Date sweep finished: dashboard, provider, credential, cases, applications (no migration)

- A wider crawl (home dashboard, provider and credential pages, cases, applications, trash lists) found dates still shown as 2026-09-02 or cut from a UTC timestamp. All now show MM/DD/YYYY, local: the dashboard work queue and its "expired on …" reasons, the compliance-level stop reason, exclusion-screening and readiness detail text, credential documents and verification dates, cases and applications dates, calendar agenda day, trash lists, expiry hint while editing a credential, "re-verify by", "attested".
- "Today" in services (compliance level, task sync, digests, screening and verification records, invoices, LEIE, case service, regulatory sources) and in the audit-pulse ranges now follows the app timezone (`today_local()`), not PHP's default (UTC).
- Guard tests G6 (no `date('Y-m-d')` in services / index.php except download file names) and G7 (no `substr()` of a UTC timestamp in views) added to `tests/dates/test_v1172_6.php`.
- Still English-only, stored as typed: dates inside free text saved before this version are converted only where `fmt_iso_text()` is applied (audit details, notifications, regulatory sources, readiness and screening detail, provider activity).

---

### v1.172.7 — Sources screen shows dates as MM/DD/YYYY (no migration)

- The legal citation and summary text on Organization → Sources (for example "Ley 168-2018 as amended by Ley 8-2025 (2025-04-11)") now shows its dates as MM/DD/YYYY; the stored text is unchanged. Guard test G5 added to `tests/dates/test_v1172_6.php`.

---

### v1.172.6 — Dates as MM/DD/YYYY in local time; Google and Microsoft password-SMTP options removed (no migration)

- **Dates and times.** Every date shown on screen is now MM/DD/YYYY and every time is local (the app time zone, default America/New_York) in 12-hour form, not UTC. The "(UTC)" headers and "UTC" suffixes are gone (audit logs, share logs, support-access notices, e-mail outbox, audit package, PDF report). Date-only fields (expiry, due, issued, re-verify, effective, reviewed) are shown without any time-zone shift. Free text saved in audit details and notifications is converted when displayed. "Today" comparisons use the app time zone (`today_local()`) instead of PHP's default, so evenings no longer count as tomorrow. New helpers: `today_local()`, `fmt_iso_text()`.
- **Removed** the "Google Workspace / Gmail" and "Microsoft 365 / Outlook" SMTP presets and their wording: both providers reject account passwords over SMTP in real tests. Microsoft 365 is sent through **Connect Microsoft 365** (Graph); any other mailbox uses SMTP on the organization's own domain.
- **Unchanged on purpose:** CSV exports keep ISO dates and UTC (data interchange); `<input type=date>` fields keep ISO values (an HTML requirement; the browser shows them in the user's locale).
- Tests: `tests/dates/test_v1172_6.php` (helpers against the real bootstrap code; guards against UTC labels, `date('M j')` and `date('Y-m-d')` in views). Mutation-checked.

---

### v1.172.5 — Platform audit log; Microsoft secret expiry warning (no migration)

- **Platform audit log** (Console → Operations → Audit log, `/admin/audit`, platform administrators only). Until now the audit log existed only inside each organization, so operator actions (settings, tests, support access, sign-ins) had no screen. It lists actions across all organizations with the organization name, person, action and time; filters by date, organization, action, text and "platform administrators only"; paginates; exports CSV (the export is itself audited). **Privacy boundary:** the details (`changes`) of a row are shown only when the actor is a platform administrator; for ordinary customer users only who/what kind of action/when is shown, and the text search does not look inside details, so the screen cannot be used to read or probe customers' record data. Tests AU1–AU5 (mutation-checked).
- **Microsoft 365 client-secret expiry warning.** Console → Email → App registration has a new "Client secret expires on" date. From 60 days before, every console page shows a notice; from 14 days before and after expiry it turns into an error notice. Pasting a new secret without a date clears the old date. Tests MX1–MX3.
- Not done: an e-mail or calendar reminder for the expiry (the banner needs someone to open the console); the audit list has no database index on date alone, so it can be slow on a very large log (use the date filter).

---

### v1.172.4 — Outbound e-mail rebuilt: clear errors, working login methods, test tools (no migration)

Owner report: none of the e-mail methods worked and the only message was "authentication failed". Findings and fixes:

- **The Microsoft 365 connect panel was invisible.** In both the platform page (Console → Email) and the organization page, its markup sat inside another condition (database-update notice / "last test failed"), so it only appeared in error states. Moved out; always shown now.
- **Re-saving the platform e-mail settings overwrote the SMTP password with `********`.** The form pre-filled the mask and the save handler stored it. The form no longer pre-fills it and the handler ignores a masked or blank value.
- **New `SmtpClient`** replaces the socket code in `MailService`: chooses AUTH PLAIN or LOGIN from what the server advertises (it assumed LOGIN), proper EHLO host name (it sent a placeholder name), TLS 1.2+ with certificate verification, base64 bodies, header-injection stripping, and refuses to send a password without encryption to a remote host.
- **Errors say what happened:** stage (connect / TLS / login / From / recipient / message), the server's own reply with secrets redacted, and advice (Gmail App password, Microsoft 365 basic-auth disabled, "use the full e-mail address", port/mode mismatch). Tenant-supplied hosts still get a generic connect message (no network oracle).
- **Test tools:** Console and organization pages now have **Test connection only** (logs in, sends nothing) and a test e-mail that works while "Send email" is off and shows the exact result on the page, not "check the outbox". **Retry failed** re-queues messages that gave up (never security codes).
- **Platform SMTP password is stored encrypted** (older plain values still read). New "Verify the server's certificate" switch for the platform sender only (some cPanel hosts present a certificate for the server name, not `mail.<domain>`). Presets (Google / Microsoft / cPanel) on the platform page too.
- **Server mail (PHP mail())** now sets the envelope sender (`-f`), which cPanel/Exim needs, and strips line breaks from the subject.
- Tests: `tests/mail/test_v1172_4.php` (16 checks against a real local SMTP server, incl. self-signed SSL and STARTTLS; mutation-checked) and ML1–ML5 in `tests/security/test_v1171.php`. The existing S5 SSRF test still passes unchanged.
- **Microsoft 365 / Graph hardened.** The refresh token Microsoft returns on every use is now saved (before, the connection stopped working about 90 days after it was made even if used daily). Failures give plain reasons (sign-in revoked, client secret wrong or expired, consent/permission missing, no Exchange mailbox, no outbound HTTPS) instead of raw Graph text. Tests MG1–MG6 use fake Microsoft endpoints through a transport hook (`MicrosoftOAuth::$transport`).
- ASCII message bodies (codes, links) are sent as readable 7bit text; only non-ASCII or very long lines use base64.
- **Not tested:** real Gmail, Microsoft or cPanel servers (the sandbox has no outbound mail). Microsoft Graph sending is unchanged.

---

### v1.172.3 — Spanish interface completed; reminder thresholds are a real setting (no migration)

- **Create organization now has an optional Temporary password field** (Console → Organizations). The backend already supported it but the form did not show it, so a new Master Admin could only sign in through an e-mailed invite, which fails when outbound mail is not set up. Blank = invite link (unchanged). Tests OP1–OP3 (mutation-checked: renaming the field fails OP1).

- **Spanish dictionary.** About 1,030 interface strings that were still shown in English are now in `app/lang/es.php`: onboarding and Getting-started panel, profile and two-factor, compliance and screening screens, case screens, trash screens, e-mail delivery, organization and platform-console screens, month and day names. They were collected from the app's own "seen but untranslated" log after a full walk-through in Spanish, so they are texts that really appeared on screen. Puerto Rico wording follows the existing dictionary (Planes médicos, Adiestramiento, Administrador Maestro). Tenant data, seeded legal content and technical identifiers are deliberately left as they are. The platform administrator can still correct any wording in Console → Translations without a release.
- **Guard for the future:** a new test fails if any literal `t('…')` in the views or services has no Spanish entry, if a `{placeholder}` is lost in a translation, if a value is empty, or if a key of this block appears twice. (Six older keys are duplicated with different wording; the later one wins, as before — left alone.)
- **Reminder thresholds.** The days before expiry at which reminder e-mails go out were fixed in code (60, 30, 14 and 7) while `config.sample.php` and the installer wrote a `reminder_days` setting (180, 90, 30) that nothing read. Now `ReminderService::thresholds()` reads **`reminder_thresholds`** from `app/config.php` (whole numbers 1–365, at most 8, largest first); without it the default stays **60, 30, 14, 7**, so no installation changes behaviour. The unused `reminder_days` key is ignored and no longer written; new installations write `'reminder_thresholds' => [60, 30, 14, 7]`. The Compliance screen shows the days that are really in force. Tests: R1–R3 and D1–D5 (no database) and RM1–RM3 (real scan and outbox on the isolated security database); mutation-checked.
- Documentation: the Administrator Guide and the UAT plan said 180/90/30; the correct statement is 60/30/14/7 unless `reminder_thresholds` is set.

### v1.172.2 — Database health false warning; light logo area in the light theme (owner reports)

- **Database health no longer warns "No tenant_id on: auth_email_codes, requirement_sources".** Both tables are global on purpose: `auth_email_codes` is keyed by user (a platform administrator has no tenant) and `requirement_sources` is the global catalogue of legal citations (it already warned in v1.171.x). They are now in the list of intentionally tenant-less tables and described in the data dictionary. `password_resets` keeps its `tenant_id` and stays checked. Test: group H; mutation-checked.
- **Light theme: the logo area at the top of the side panel is light** (white, with a thin line, matching the top bar); the navigation below keeps the dark metallic blue. The dark theme is unchanged. Real-browser check B8 (computed colours in both themes).

### v1.172.1 — "Add provider" is now two real forms, like the guided onboarding (owner report after v1.172.0)

The owner reported that **Add provider** (`/holders/new`) still showed the same fields and the same type list for a person and for an organization; only the guided onboarding had been fixed in v1.172.0. The two entry points now behave the same way.

- **`/holders/new?type=individual|organization`** (plain links, no JavaScript): a person gets first/last name, a profession list, birth month, role/title and the portal-login e-mail; an organization gets the organization name, the organization types only (laboratory, clinic/facility, medical group), an organizational NPI and an optional contact e-mail. The same applies to the edit page of an existing record (its own kind decides what it shows). Person-only data (middle name, gender, languages, date of birth, SSN last 4, accepting new patients) is not offered for organizations.
- **The server enforces it too** (`HolderInput::fromPost`): an organization record never stores first/last name, birth month, role/title or person-only application fields, and a person record never stores an organization name, whatever the request contains. A profession is not accepted as an organization type and the reverse (this already held for the type; now it holds for the fields).
- Tests: HTTP O13–O17 (both create forms, what is stored for each kind with hostile extra fields, edit of an organization) and two DB-level checks in group O; mutation-checked (removing either filter fails them).

### v1.172.0 — Sign-in options and a real guided onboarding (owner decisions after the first test installation; migration 133)

The owner's first test installation of v1.171.10 found that the platform console could not be used until two-factor was fully set up, and that the guided onboarding showed the same fields for a person and for an organization. This release answers both and brings forward three planned items.

- **Platform console two-factor is a setting.** Console → Security → *Sign-in policy*: "Require two-factor authentication to use the platform console". It is **off on a new installation**, so the first set-up is not blocked, and the platform administrator can turn it on at any moment (their own account needs two-factor first; changing the policy asks for the current password and is audited). An installation upgraded from a release where it was mandatory keeps it **on** (migration 133). While it is off the console shows a reminder and Security posture reports it as "to review". Promotion to platform administrator requires two-factor only while the requirement is on.
- **QR code for authenticator apps.** The enrolment page shows a QR code built by the server (`app/src/QrCode.php`, inline SVG, no external service, nothing leaves the server). The setup key and the tap-to-add link remain. Verified bit for bit against an independent reference encoder for versions 1–20.
- **Step-by-step instructions** on the enrolment page for Google Authenticator, Microsoft Authenticator, Authy and 1Password.
- **E-mailed codes as a second step.** An account can use a 6-digit code sent to its own address instead of an authenticator app (offered only when outbound e-mail works; the platform can disallow it). Codes are stored as keyed hashes, expire in 10 minutes, work once, die after 5 wrong tries, and at most 5 are sent per 15 minutes. One method at a time: switching needs "turn off" with a current code. Backup codes work for both methods.
- **Password reset by e-mail.** "Forgot your password?" on the sign-in page. The answer is the same whether or not the address has an account; the link is single-use, expires in 60 minutes, is stored only as a hash, ends the account's open sessions, never signs anyone in and never bypasses two-factor. One address in several organizations gets one link per account. Requests are rate-limited and audited.
- **Security messages leave nothing behind.** Codes and reset links are sent once, immediately; the outbox keeps who/when/subject/result but the content is removed after the delivery attempt, and a failed one is never retried.
- **Guided onboarding has two real paths**, chosen with plain links (no JavaScript): *a person* (Who → Identifiers → Requirements → Portal access) and *an organization* (Organization → Identifiers → Requirements → Review & finish). Each path shows only its own fields and type list; identifiers differ (person: specialty, birth month, CAQH…; organization: organization NPI, taxonomy, PTAN, Medicaid ID). The first step adapts to who is using the app: a billing company chooses the client group, a hospital the department, a solo practice can add the doctor with "I am the doctor". Step 1 of an existing record can be reopened and corrected. A refused form comes back filled in.
- **Fixed: inline event handlers were dead under the Content-Security-Policy.** Eight `onclick=`/`onchange=` handlers in five views did nothing because the CSP (correctly) blocks inline handlers: the onboarding type switch, the **case requirement status** select (it had no submit button, so the status could not be changed from the list), "Select all" and Print on the packet pages, and Copy / click-to-select on the calendar feed page. They are replaced by data-attributes handled by `assets/app.js`; the case status form also has a real Save button.
- **Fixed: the guided onboarding did not check `records.write`.** A role without that permission could create a record through the wizard. Every wizard step now requires it.
- Spanish catalogue: 88 new entries for the new sign-in screens and e-mails.
- Tests: `tests/security/test_v1172.php` (groups P, Q, E, R, O, C — 40 checks), `tools/http_smoke` (P1–P8, E1–E8, A1–A3, R1–R9, O1–O12, with a local SMTP sink so the tests read what the application really e-mails), and a new optional real-browser check `tools/browser_check/run.sh` (Playwright: the QR really scans, scripts really run under the CSP).

Changed test preconditions (assertions unchanged): the two "S3" checks that a platform administrator without two-factor is refused now first turn the requirement on, because it is no longer on by default.

---

### v1.171.10 — Closeout of the v1.171.x review cycle (the two REQUIRED items of the second-opinion review; no features, no migrations)

- **ext-intl is a production prerequisite.** `TextNorm::fold()` compares names with Unicode NFKC, which needs PHP `Normalizer`. The installer now lists `intl` as a hard requirement; a production installation without it stops at start-up with a clear message (HTTP 503) instead of silently comparing with a weaker rule; dev/test keep the documented full-width-only fallback. `INSTALL.md` updated. Tests: DB V9 (real NFKC proven with ﬀ, ①, Ⅸ, combining accents; missing path throws in production), installer check (intl row present and satisfied).
- **Tenant identity names use the same rule.** Organization name, brand name (`OrgService::updateInfo`) and legal name (`updateEntity`) are stored through `TextNorm::clean()`; a name that is blank after cleaning is refused on the Settings form (`OrgService::nameError`) and never replaces the stored one. No uniqueness across tenants (two organizations may share a name). Tests: DB V9 sweep, HTTP O1.

Deferred unchanged (v1.172 backlog): unsealed DB download re-confirmation, duplicate "Business License" catalogue entries, FL-only tenant sees PR payers, CE window edge, Spanish gaps, platform-wide global-vs-tenant role-name warning.

---

### v1.171.9 — One rule for human-entered names (closes the class found across four validation rounds; no new features, no migrations)

Claude Code's run of v1.171.8: exit 0, 669 PASS, Step 3l 4/4; but role **create** still accepted empty/1-character names (length checked before cleaning), look-alike letters (Cyrillic е, soft hyphen, word joiner, full-width) passed everywhere, global roles had no duplicate check, and provider/organization **create** accepted invisible or empty names.

- **`TextNorm`** (new, `app/src/TextNorm.php`): `clean()` = what is stored (Unicode format/control characters, Hangul fillers, braille blank, soft hyphen removed; every kind of space collapsed); `fold()` = what is compared (clean + NFKC + lower-case + Cyrillic/Greek look-alikes → Latin + dotless ı). Used by every name entry point: tenant role create/rename, global role create/rename (`RoleService::nameTaken()`), provider/organization create, edit and onboarding (`HolderInput`).
- Roles: clean first, then the 2–80 length check, then the shared duplicate rule; a tenant role may not shadow a global custom role.
- Providers/organizations: names are required on create (both first and last for individuals) and refused when invisible, on create, edit and onboarding; stored names are cleaned.
- A role update that carries no capability list (rename-only POST) keeps the capabilities; the form always sends `caps_form=1`, so an all-unticked form still clears them.
- Tenants see global custom roles as *View*, not *Edit*. "Licence held" fallback option shows the catalogue name. Saving/adding an organization says "Organization", not "Provider".
- Tests: `tests/security` V8 (DB) and `tools/http_smoke` N1–N3 (HTTP) sweep ~30 variants across all eight entry points; each mutation of the rule makes them fail.

Not changed: duplicate "Business License" catalogue entry; `npi_enc` re-sealed on every full save (same value); unsealed DB download (v1.172).

---

### v1.171.8 — Small fix round from the fourth independent run (no new features, no migrations)

Claude Code's run of v1.171.7: script exit 0, 659 PASS, 7×220-page crawls, 25/25 requested checks, no regressions. Four small defects found; all fixed with behavioural tests (DB + HTTP) and mutation-checked.

- **R1 Role rename:** renaming a role now follows the same duplicate rule as creating one (case, odd whitespace, NBSP, zero-width, dotless ı ignored; built-in names refused; the role's own name never conflicts with itself). One shared rule, `RoleService::nameConflict()`.
- **R2 Organization edit:** saving an organization no longer clears its licence code (e.g. CLIA) — a stored code the list does not offer is kept as the selected option. Provider/organization names made only of NBSP / zero-width / BOM characters are now refused on edit like blank ones.
- **R5 Two-factor:** a pending enrolment secret left in another session cannot be confirmed (or shown) once 2FA is on; it is discarded and the user is redirected with the red message. The inline refusal on the enrolment form is now actually red (the old class had no style).

Not changed (see memo): global-roles page has no duplicate check; platform admin entering a demo organization resets that owner's per-account counter; unsealed DB download has no password re-confirmation (planned v1.172).

---

### v1.171.7 — Remediation of the third independent validation (final small round; no new features, no new migrations)

Claude Code's run of v1.171.6: 651 PASS, 0 FAIL in the automated stages; checklist 58/60 PASS; 3 small defects found plus observations.

- **Toast sweep (K2):** four more mismatches fixed ("Support request denied." now ok; an import that created nothing is error, partly good is warn, for providers and credentials; "No active link…" error).
- **Role names (K5):** compared the way a person reads them — repeated spaces, tabs, NBSP, zero-width characters ignored — and may not reuse a built-in role name (Admin, Viewer, Manager…). Tests: DB and HTTP.
- **Provider edit can no longer erase the identity or save a malformed e-mail** (edit now enforces what create enforces: name required, valid e-mail). Tests: DB and HTTP.
- **Per-account throttle** is reset only by a COMPLETE sign-in (after 2FA), not by a correct password alone. Test: DB.
- **Refused `2fa_confirm` with 2FA on** redirects with the message instead of rendering an empty enrolment form. HTTP test.
- **Installer `mail_from`** no longer includes the port.
- **Checklist corrections:** the FL CME row says "Per licensing-board cycle" on purpose; built-in notes live under Compliance → Catalog; exact outbox wording; new Step 3k; the validation script's TODO file lists Step 3j/3k.

### v1.171.6 — Remediation of the second independent runtime validation (no new features, no new migrations)

Claude Code's run of v1.171.5: automated stages all PASS; 4 browser/checklist FAILs and one data-loss bug outside the checklist.

- **Data loss (blocker).** `POST /holders/{id}` blanked first/last name, e-mail, NPI and other fields the request did not send (the edit handler called the shared parser in "creating" mode), and any **unknown sub-path** fell through to that handler. Now only the bare provider URL updates, and only with fields the request carries. Tests: HTTP unknown sub-path → 404 and record unchanged; partial POST changes only its field.
- **3i-2 toast colours.** The keyword guess was wrong both ways ("…required credentials…" red, "…failed/queued" red, several refusals green). Every literal `flash()` now states its type; results of `[$ok, $msg]` services use `$ok`; composed messages are typed at the call site; the keyword guess remains only as a fallback for any future untyped call. Tests: HTTP (validation refusal = error; provider add = ok), source guard (no untyped literal `flash()` in index.php).
- **3i-11 CSV template.** The template repeated NPI 1234567893 (the importer skipped a row). Four different valid NPIs; test runs the REAL importer preview (4 creates, 0 skips) and checks uniqueness over HTTP.
- **3i-4 QR code.** The application has never rendered a QR image (it shows the setup key and a tap-to-add link); the v1.171.5 checklist wrongly claimed one. Checklist and a misleading comment in `Totp.php` corrected. Adding a QR image would be a feature — not done (owner decision).
- **3i-7 JLDM category.** JLDM is stored as "State board", the nursing board as "Licensing board"; both are visible. Checklist corrected; no data change.
- **2FA could be swapped without the current code** (`2fa_start` + `2fa_confirm` replaced an active secret). `confirmTotp` now refuses when 2FA is already on (and `2fa_start` says so); enabling writes a `2fa_enabled` audit row. Tests: DB + HTTP.
- **Stage 5 false-failed on used installations.** It now compares `.notif-sweep-*` before/after the run and fails only on files the run created.
- **Role names are unique per organization** (a replayed create made "Name" and "Name 2"); DB test. **Docs:** DEMO_ACCOUNTS.md now says Staff can export the (scoped) credentials report, not organization-wide data.
- **Decision (owner): no QR code** — the setup key + tap-to-add link stay the enrolment method.
- Smoke: a dedicated `twofa@smoke.test` user; the vacuous-crawl guard caught the side effect of enrolling a shared user.

### v1.171.5 — Remediation of Claude Code's independent runtime validation of v1.171.4 (no new features, no new migrations)

Each item has a behavioural regression test (real database or HTTP), listed in UPDATE_FOR_CHATGPT_v1_171_5.md. No existing test was changed to make it pass.

- **A. `FLX_CONFIG` honoured by `index.php`** (it redirected every request to install.php when `app/config.php` was absent). The HTTP smoke now has a preflight and **fails** when a crawl reaches fewer than 8 pages or mostly redirects (no more vacuous PASS).
- **B. Forced sign-out explains itself.** Password reset / suspension → /login shows "You have been signed out…" once.
- **C. `encrypt_existing_files.php`** skips dot-files (`.keep`); a fresh install exits 0.
- **D. OCS/SICRO panel** only for tenants operating in PR (an existing OCS row no longer forces it).
- **E. Regulators page** lists boards in category `licensing` (and insurance / medicaid / behavioral_health); unknown stored categories fall into "Other" instead of vanishing.
- **F. Tenant signup no longer re-applies global catalog updates.** `seedTemplatesFor` skips statements that write `credential_catalog` / `requirement_sources`; migration 132's UNVERIFIED wording survives new signups.
- **G. Sign-in throttle — intended behaviour documented and tested.** One address gets at most 8 guesses per 15 minutes (refused attempts do not feed the per-account counter, so a single address cannot lock the owner out everywhere); failures spread over many unknown addresses still trip the per-account limit (12); a known place exempts the owner from the per-account counter only. Shared-NAT trade-off: the owner is held 15 minutes from that address only.
- **H.** `GET /admin/email` no longer sends queued mail (cron or the explicit POST only).
- **I.** An address becomes a "known place" only after a **complete** sign-in (after 2FA), not after the first factor.
- **J.** Refusals/errors use the red toast (type derived from the message unless the caller passes one; the forced sign-out uses the warning style).
- **K.** Read-only users can enrol in their own 2FA (`2fa_start/confirm/disable` exempt from the read-only write block; data writes stay refused).
- **L.** The installer writes `http://` and `hsts=false` over plain http; `https://` and `hsts=true` over HTTPS / `X-Forwarded-Proto`. New `tools/http_smoke/installer_check.sh` runs the real installer over both.
- **M.** Roles: "Master Admin" listed once; "role_created/updated/deleted" logged once; Managers never see the raw slug `tenant_owner` (`TeamService::roleLabel`).
- **N.** Audit-readiness package: requirement names (not the authority word) and real evidence rows (credential, expiry, documents, or "No credential on file").
- **O.** CSV import template uses valid NPIs (1234567893, 1245319599, 1003000126).
- **P.** PR checklist shows the same 36-month CME cycle as the CE panel (FL 24).
- **Q.** `.notif-sweep-*` stamp files go next to `docs_path` (or temp) — test runs leave nothing in `app/storage`; `runtime_check.sh` stage 5 verifies.
- **R.** `runtime_check.sh` header/version and new stages (3f2 installer, 5 stray files); checklist corrected (Step 1 key-backup passphrase field, Step 2 prerequisite, 3b-1 / 3d-1 wording, 3g-1 throttle expectations, 4.3 cycle, new Step 3i).

---

### v1.171.4 — Remediation of the independent review of v1.171.3 (no new features)

- **R-1 (blocker) Address back-fill could overwrite valid ciphertext.** `database/encrypt_existing_addresses.php` now migrates **each column independently** (a column is written only when it has plaintext and no ciphertext; existing ciphertext is never touched; guarded UPDATE). Regression tests cover a fully legacy row, the partially migrated row, a fully migrated row, and all 27 plaintext/ciphertext/empty combinations (and a mutation run proved the tests fail against the old script).
- **R-2 Invite acceptance is atomic.** The final UPDATE requires the same user, `status = 'invited'`, not expired and the token/hash still matching, and success requires `rowCount() === 1`. Stored-hash replay prevention and legacy 40-character tokens unchanged.
- **R-3 Backup memory.** The database dump is buffered, not streamed; comments and the console text now say so. Measured: peak memory ≈ 2.8 × database size (128M fails near 50 MB of database). `docs/architecture/BACKUP_MEMORY.md`, `tools/dump_memory_check.php`. No backup-engine change; a streaming redesign is proposed separately if ever needed.
- **R-4/R-5 Verification wording.** The provider/credential panels are "Verification records" with the statement that FlxCoreHC records the organization's verification activity and evidence and does not independently perform or certify primary-source verification (EN and ES help, UI, report header, migration comments 076/099/111 — SQL unchanged, repository/service doc-comments, docs). Claims about what auditors or payers accept were removed.
- **R-6 "Fully compliant" removed.** Level 5 is "All tracked requirements current"; dashboard, compliance, portal and help text updated (EN/ES). The calculation is unchanged.
- **R-7 Puerto Rico nursing board** is "Junta Examinadora de Enfermería" (PR Department of Health site). Seeds 112/120/126 updated for new installs; migration 132 renames existing rows idempotently.
- **R-8 Unverified PR facts** are labelled UNVERIFIED instead of stated as requirements: the CME hour breakdown (10 h prevention / 6 h bioethics) and the negative controlled-substances certificate (catalog notes and readiness checklist; migration 132). See the REGULATORY FACTS table in UPDATE_FOR_CHATGPT_v1_171_4.md.
- **R-9 Login throttle on a shared NAT** is now covered by a test documenting the residual trade-off: once someone behind the same IP trips the (email, IP) threshold, the owner is also held for 15 minutes from that IP (not from other addresses). Protection was not weakened.

---

### v1.171.3 — Low-severity hardening (S-8), address encryption, sealed SQL dumps, test-harness config

- **Account lockout DoS.** The per-account login throttle no longer locks out the owner when a stranger guesses their e-mail: an (e-mail, IP) pair that signed in successfully in the last 30 days is exempt from the per-account counter. The per-(e-mail, IP) and per-IP limits still apply, and an address never seen before is still held by all three.
- **Dev passthrough marker.** A stored value starting with the development marker byte reads as nothing unless `app_env = dev`.
- **No side effects on GET.** Opening /admin or /compliance no longer sends queued mail or runs the reminder sweep (cron and POST actions only). `?setlang=` persists to the profile only for same-origin/same-site/direct navigations. `/settings` is gated by `org.manage`, not role names. The installer form has a session CSRF token.
- **Invite tokens hashed at rest** (migration 130). The database keeps SHA-256 of the token; emailed links are unchanged; pending legacy (raw) invitations still resolve (only exact 40-character tokens, so a stored hash can never be replayed as a token).
- **Address encryption (D-6)** (migration 131). Address line 1, line 2 and phone are encrypted with the same per-tenant keys as NPI/e-mail; legacy rows still read until `php database/encrypt_existing_addresses.php` back-fills them.
- **Passphrase-sealed SQL dump.** Console → System → "Download sealed database": PBKDF2-SHA256 (200k) + AES-256-GCM, password-confirmed and rate-limited; open with `php database/decrypt_dump.php <in> <out.sql.gz>`. The plain download remains.
- **`FLX_CONFIG`.** The configuration file can be pointed at with this environment variable; every test runner and the HTTP smoke now use a temporary config instead of swapping `app/config.php`. `tools/router.php` is the router for `php -S` (mirrors `.htaccess`).
- **B-8:** case list shows provider names (not ID prefixes); audit log humanizes actions and record types (full ID in a tooltip); the calendar feed URL is https on production installs.
- **Docs:** release history moved from INSTALL.md to this file; PRODUCT.md and KICKOFF.md rewritten for the current tracker scope; earlier ChatGPT memos archived under docs/archive/.
- **Deliberately not changed:** share-link and calendar-feed tokens stay readable in the database because the UI re-displays them (hashing needs a "regenerate once" flow); legacy empty-AAD ciphertext still decrypts.

---

### v1.171.2 — Remaining security findings from the v1.170.9 review (S-5, S-6, S-7, B-7, B-8)

- **S-5 SMTP SSRF guard.** A tenant's own SMTP settings must be a public mail server on port 25/465/587/2525; loopback, private, link-local and cloud-metadata addresses are refused at save **and** at send time, the connection goes to the validated address (no re-resolution), and connection failures return one generic message (no port-scan oracle). Operator-configured platform SMTP is not restricted. *Upgrade note:* a tenant that saved SMTP on a non-standard port must switch to one of the four ports.
- **S-6 TOTP.** Secrets are stored encrypted (`enc1:`; legacy plaintext secrets keep working and are upgraded on next use) and each code is single-use (last accepted time-step stored, compare-and-set in SQL). Migration 128.
- **S-7 2FA policy by capability.** "Require 2FA for admins" now applies to anyone holding `team.manage`, `org.manage`, `pii.view` or `data.export` (custom roles included) and runs before every route, billing and dashboard included.
- **B-7 isolation check** can fail: scans `app/`, rejects stale allow-list entries, and fails if `index.php` gains raw `Database::pdo()` calls beyond `tools/isolation_baseline.txt` (34 today — known debt).
- **B-8 wording/UX:** "Provider updated.", CSV header, installer placeholder, compliance page points tenants to Organization → Email, malpractice authority is "insurer" not "federal" (migration 129), the 2FA page no longer shows "Off" right after enrolment, and the Getting-started checklist is shown only to people who can act on it.

---

### v1.171.1 — NPI check digit, Puerto Rico seed corrections, jurisdiction awareness

- **D-3 NPI validation.** `Npi` (CMS Luhn check digit over the 80840 prefix, first digit 1/2) is enforced on provider create/edit/self-profile/onboarding, group and location forms, entity (group NPI) and both CSV imports. Demo and seed NPIs are regenerated with valid check digits. Type-1 vs Type-2 is an NPPES property and is still checked at "Verify NPI (NPPES)" time.
- **D-5 PR corrections** (migration 126, also applied to new signups): physician board is the **JLDM** (Junta de Licenciamiento y Disciplina Médica); PR licence and CME items apply to physicians only; new **PR nursing licence** item under the Junta Examinadora de Enfermeras y Enfermeros; the negative controlled-substances certificate no longer names two conflicting agencies and is no longer satisfied by a state CDS registration.
- **Jurisdiction awareness:** CE/CME total uses a **36-month** window for Puerto Rico tenants (24 otherwise); PR-only items (OCS/SICRO panel and readiness item, Buena Conducta, ASUME, ORCPS/OCS verification sources, the OCS requirement for compliance Level 5) are shown only to tenants that operate in PR. **No silent FL fallback**: `statesOf()` returns none when unconfigured; `tenants.operating_states` default removed (migration 127); demo tenants are PR.
- **B-8** The menu no longer lists Groups / Locations / Applications / Regulators to users who would get 403.
- Plan only (not implemented): `docs/architecture/NCQA_CREDENTIALING_ROADMAP.md` (NPDB, committee decisions, 180-day / 36-month rules, unified PSV, full SSN).

---

### v1.171.0 — Security hardening from the independent v1.170.9 validation

- **S-1** Role create/update and team grants are capped to the actor's own capabilities; system roles are owner-level only; `assignable()` lists only roles within the actor's ceiling.
- **S-2** Per-request session revalidation (user active, tenant not suspended/closed, password fingerprint); role/holder/2FA flags refreshed from the DB; fails closed.
- **S-3** Platform-admin password reset, owner creation and owner-role changes require an active support grant (demo tenants exempt); platform admins must have 2FA to enter /admin.
- **S-4** All JSON embedded in `<script>` goes through `flx_json()` (HEX flags).
- **B-1** Single provider-type list (`HolderInput`); every provider form field round-trips. **B-2** `deleted_at IS NULL` on the joins and the expiry sweep. **B-3** Migrator BENIGN list narrowed, advisory lock, refuses an untracked non-empty DB, skipped counts reported. **B-4** Notifications, group/location counts, posture counts and audit respect provider scope. **B-5** Team role dropdown only real roles. **B-6** PHP 8.4 CSV `$escape` wrappers.
- **Keys backup** (installer + console): passphrase-sealed `.flxkeys` (PBKDF2-SHA256 200k + AES-256-GCM), console reminder banner until confirmed; never e-mailed. `database/restore_keys.php`.
- **Documents encrypted at rest** (`EncryptedFile`, AEAD bound to file name); legacy plaintext still readable; `database/encrypt_existing_files.php` converts in place; document backups archive sealed files.
- Tests: `tests/security` (87 DB-level checks) + extended HTTP smoke; wired as stage 3g.

---

### v1.170.9 — Need-to-know moved into SQL (ProviderScope), fail-closed hardening, portal-role confinement

No new features; no schema change (no new migration).

- **Provider scope in the query, not after it.** New `ProviderScope` builds the need-to-know predicate
  (`holder_id IN (SELECT holder_id FROM holder_assignments WHERE tenant_id=? AND user_id=?)`) and every provider-bearing
  repository and aggregate applies it BEFORE `ORDER BY` / `LIMIT` / `COUNT` / `GROUP BY`. Restricted users now get correct
  lists (no "LIMIT 500 then filter"), correct counts (dashboard, work queue, calendar, applications, screening KPIs) and
  nothing — not even a number — about unassigned providers. Writes (`update`/`delete`) are scoped too. Unrestricted users
  (owners, "all providers" users, cron, share-link viewer) get no extra SQL: behaviour unchanged. The v1.170.8 PHP
  post-filters (`ntkFilter`/`ntkRow`) are gone.
- **Dashboard / work queue** show only assigned providers for restricted users; query failures are counted, logged and
  shown as "figures incomplete" instead of silently reading as zero/compliant. Same for the tenant report PDF.
- **Fail-closed** need-to-know resolution: unknown user row or lookup failure ⇒ sees nothing (was: full access).
- **Security fixes:** custom portal-only roles could open the tenant dashboard and read any provider's document
  (confinement was keyed to the single slug `producer`) — now every portal-only role is confined; `Verify document`
  now requires `documents.verify` (Coordinators have `records.write` but must not attest verification; buttons hidden);
  notification recipients exclude custom portal-only roles; group/location member lists honour need-to-know; the public
  share viewer is an explicit system actor rather than a fake `viewer` role.
- **Audit log:** role changes record from/to; `owner_level_role_granted`; `share_link_revoked`; user create/invite record role.
- **Runtime defects found by executing the suites on MariaDB 10.11 (all pre-existing, all fixed):** the case detail page called
  `ComplianceEventService::forCase()`, which did not exist (fatal on every `/cases/{id}`); a need-to-know-restricted user opening
  `/entity` crashed AND silently created another organization record on every visit; the provider page carried a broken PHP block
  (stray code text, undefined variables); a missing `errors/404` view turned "case not found" into a fatal; migration 125's role
  rename could collide with an existing `master_admin_role` slug (now collision-safe); the agency test file had never been
  runnable on its own (added `tests/agency/run.php`, fixed the fixtures that had never executed).
- **Tests:** `tests/authz/test_provider_scope_static.php` (DB-free, executed), `tests/authz/run.php` (isolated DB: cross-tenant,
  need-to-know, LIMIT, capability matrix, scoped writes, portal, fail-closed, migration-125 data), `tests/migrations/run_scenarios.php`
  (migration scenarios A–D). DB-backed suites are AUTHORED and REQUIRE EXTERNAL RUNTIME VALIDATION.

---

### v1.170.8 — Security & correctness audit (three independent reviews + verification), insurance residue sweep

**Security (verified, fixed)**
- **Privilege escalation (P1):** a Manager (team.manage) could add, invite, or change any user — including
  themselves — to Master Admin; a custom role named "Master Admin" got every capability. Granting an owner-level
  role now requires being a Master Admin; reserved slugs can't be minted; migration 125 renames any existing ones.
- **Need-to-know bypass (P1):** users limited to "assigned providers only" could still list every credential,
  open any credential, download any document, export the credential report, see all tasks and the whole calendar
  (incl. the iCal feed). Enforced in the data layer now (`ntkFilter`/`ntkRow` on the credential, document, task
  and calendar repositories); the iCal feed runs as the real user and dies with a suspended user/tenant.
- **Ownership binding (P2):** education / work-history / address / contact ids in a provider URL must belong to
  that provider (and organization addresses to the organization) — previously only the tenant was checked.
- **Capability checks (P2):** provider create/edit/onboarding, credential create/edit, apply-template, task and
  calendar create now require `records.write` (custom roles without it were able to write).
- **Portal confinement (P2):** any portal-only role (not just the built-in slug) is confined to the portal; a
  portal-only login must be linked to a provider; no redirect loop when it isn't.
- CSV formula injection guarded on every export (`csv_safe_row`); 2FA disable is rate-limited and audited;
  open-redirect via backslash closed; provider pages no longer 500 for Staff/Coordinator/Read-only (TeamService
  constructor threw); a non-manager adding a provider no longer errors after the insert.

**Code errors (verified, fixed)**
- Dashboard work-queue "Document submitted — review" and throughput counters used columns/tables that don't
  exist (`document_requests.credential_type/created_at/updated_at`, `audit_log`) → always empty. Fixed.
- Organization report PDF/CSV: `domains`, `invoices.issued_at`, `impersonation_requests.approved_at` wrong →
  sections silently empty. Fixed.
- Exclusion-screening in-app alert never fired (`potential_match` missing from the filter). Fixed.
- Reminder emails went out for soft-deleted credentials; the sweep cron pre-queued reminder rows that blocked
  the 30-day email from ever sending. Fixed (sweep no longer queues; ReminderService claims stale rows).
- Cron-generated notification links carried a filesystem path as prefix. Fixed (CLI base path from `app_url`).
- Purge: dead insurance blocker list replaced by "open credentialing cases block purge" (test updated).

**Insurance residue (second sweep)** — "Lines of business saved" flash + audit action, `producer_user_*`
audit actions, "appointment" empty-state, custom-type category labels, "Reporting year" June rollover (Medicare
plan-year logic) → calendar year, team.csv role labels, installer placeholder, dead NPN branches removed,
`database/seed_demo.php` (insurance seeder) deleted, CHANGELOG header updated. **Migration 125** deletes the two
insurance `compliance_items` seeded by 004 (FK-safe), renames reserved custom slugs, renames audit actions.

**Tests:** `tests/healthcare/test_healthcare_only.php` extended (H5b string scan of index.php/app/src/lang;
H7 security regression guards) — **executed: ALL PASS**. Migrations 124/125 and DB suites: RUNTIME VALIDATION PENDING.

---

### v1.170.7 — Healthcare-only: insurance-era screens, wording and defaults removed

FlxCoreHC serves healthcare only. A screen-by-screen audit (docs/architecture/INSURANCE_RESIDUE_AUDIT.md)
found no insurance modules left, but insurance panels, wording and defaults inside healthcare screens.

- **Every request is healthcare.** `TenantContext` normalizes the vertical to `healthcare`; `Vocab` always
  returns healthcare terms; the "Other (custom)" sign-up is gone. Insurance-only branches can no longer render.
- **Removed:** NIPR "state insurance licence" panel and its `/organization/nipr-interest` route; NPN fields
  and columns (provider form, list, profile, onboarding, portal, public verify page, audit package);
  calendar "Client call" type and "Client name" field; Florida insurance CE rules.
- **Rebuilt:** CME / CE tab (hours by category over the trailing 24 months; no invented statutory target);
  Entity compliance page as a healthcare entity profile (Group NPI, taxonomy, Medicare PTAN, Medicaid ID,
  CLIA, facility licence, accreditation); public verification page (Provider, NPI, specialty); audit package
  ("Credentialing Audit-Readiness"); security page + payer-facing PDF; ruleset label.
- **Wording:** ~60 "producer / agent / agency / carrier" strings → provider / organization / payer; role
  "Provider (self-service)"; plan names; icon slots; Training Center `\u2014` rendering bug fixed.
- **Defaults:** sign-up asks where providers practice (PR preselected) so PR rules apply; `'insurance'`
  fallbacks → healthcare; custom document types apply to all healthcare provider types (was `'agent'`,
  matching nobody); New case form uses pickers instead of raw database ids.
- **Migration 124_healthcare_only.sql:** entity profile columns; widens `credential_catalog.applies_to` to 255; converts insurance/custom tenants and
  holders to healthcare; renames the portal role and plans; fixes custom types; prunes the 9 insurance seed
  templates on upgraded installs; training default vertical → healthcare; retires "client_call" events.
- **Test:** `tests/healthcare/test_healthcare_only.php` (no database) — **executed in Claude.ai: ALL PASS**
  (context, vocab, sign-up shapes, catalog defaults, banned-phrase scan of every view, migration lists).
  Migration 124 and the DB-backed suites remain RUNTIME VALIDATION PENDING.

---

### v1.170.6 — Security/logic fix pass: transactional permanent delete + share exposure of trashed providers

Static review of v1.170.5 found that permanent delete could partially destroy data. Fixed:

- **New `app/src/PurgeService.php`** — the only code path for permanent deletes. One transaction
  (all-or-nothing); FK-safe order (renewal_tasks / credential_documents / credential_reminders before
  credentials; holder_requirements etc. before the holder); covers every holder-, credential- and
  case-owned table (cases, requirements, evidence, case documents, attestations, education, work history,
  contacts, consents, calendar events, screenings, enrollments, addresses, custom field values, reminders,
  notifications). Stored files are removed from disk only after commit and only inside the tenant's
  storage folder. Cross-org links and share links are revoked, not deleted. The compliance timeline,
  audit logs and access logs are retained.
- **Blockers** (nothing is touched): a usable login account linked to the provider; insurance records
  (policies, commissions, bookings, SOA forms, interactions). A suspended portal-only account is unlinked.
- **Soft delete** of a provider is refused while an active login account is linked to it.
- **Trashed providers are no longer exposed** through external share links, verification share links or
  cross-organization shares (previously still visible while in Deleted records).
- Deleted-records lists, restore and purge now honour "assigned providers only" access.
- Cases: platform-wide payers accepted; cases can't be opened on trashed providers; only active staff
  users can be assigned; the assign route reports failures; evidence must belong to the case in the URL.
- **Web exposure hardening (install-time):** `tests/` was reachable from a browser (its runners swap
  `app/config.php` and create/drop databases). Now blocked three ways: root rewrite rule
  (`tests|docs` added), `tests/.htaccess` (Require all denied), and a CLI-only guard in every test script.
  Root `.md`, `.sh` and `.txt` files are no longer served. Removed a stray dev cookie file (`cjg.txt`).
- **Fresh-install fix:** migration `122_nonclinical_catalog.sql` (neutralized in v1.170.4) contained a bare
  `SELECT 1;`. The installer runs statements with `exec()`, which leaves that result set open, so the next
  statement failed with `SQLSTATE[HY000] 2014 … unbuffered queries are active`. 122 is now comments-only
  (zero statements). The installer and the in-app Migrator now drain any result-returning statement, and
  the test harness loader reports real migration errors instead of swallowing them (which is why the
  runtime check had not caught this).
- New isolated test suite `tests/purge/run.php` (own throwaway DB `flx_purge`); added to
  `tools/runtime_check.sh`. STATIC PASS (php -l, isolation) · RUNTIME VALIDATION PENDING.

---

### v1.170.5 — Fix: Restore / Permanently-delete gave "Not found" (routes were unreachable)

The restore and permanent-delete (purge) actions for providers and credentials returned "Not found." Root
cause (a real routing bug): both routes were placed INSIDE the `if ($id !== null && ($row =
$holders->find($id)))` block (and the credentials equivalent). `find()` returns only NON-deleted records,
so for a soft-deleted record the guard was false, the block was skipped, and the request fell through to
"Not found" — meaning restore/purge could never run on the exact records they exist for.

- Moved the holder **restore** and **purge** routes to sit right after `GET /holders/trash`, before the
  `find()`-guarded block, so they operate on deleted ids (via `findTrashed()`). Same fix for the
  credential restore/purge routes (before the credentials `find()` block).
- No behavior change to the logic itself — restore still clears `deleted_at`; purge still requires the
  `PERMANENTLY DELETE` confirmation and removes dependent rows. Only the routing placement was wrong.

Verified: whole-file structure identical to v1.170.4 (routes relocated, nothing else changed); isolation
passes. No schema change. **Runtime re-validation pending** — confirm Restore and Permanently-delete now
work from `/holders/trash` and `/credentials/trash`.

---

### v1.170.4 — Fixes from runtime validation (case page down, seed bug, test harness)

Three findings from the first Claude Code runtime run against a live PHP 8 + MySQL host, all fixed.

- **Finding A (high — flagship feature down):** opening any credentialing case threw *Illegal ORDER BY
  clause*. `CredentialingCaseRequirementRepository::forCase()` passed a `FIELD(status,'…')` ordering
  through the guarded `all()` helper, whose `assertOrderBy()` SQL-injection backstop rejects any ORDER BY
  containing a single quote. Rewrote `forCase()` as a raw **tenant-scoped prepared statement** with the
  FIELD() ordering — the same established pattern used by ApplicationRepository / DocumentRequestService.
  Tenant scoping preserved. (This was the only FIELD()-through-`all()` in the agency code.)
- **Finding B (medium — seed bug):** migration 122 omitted the `id` column (and used a wrong column list),
  so under `INSERT IGNORE` only one empty-id row seeded and the other 8 collided on the empty primary key.
  Neutralized 122 to a no-op and added **migration 123** which deletes any empty-id row and seeds all 9
  non-clinical catalog types idempotently via `INSERT … SELECT UUID() … WHERE NOT EXISTS` (matching the
  068 template / standing rule #22).
- **Finding C (test harness):** the agency test's `tenant()` helper passed a one-word owner name (`"O$slug"`),
  which `registerTenant` correctly rejects (needs a real first+last name), fataling the suite at startup.
  Fixed the test to pass a valid two-part name. (App behavior was correct; test-only change.)

No schema change (123 is seed data). **Runtime re-validation pending** — re-run `php -l`, migration-123
seed check (expect 9 rows), and the agency suite (should now pass A + P groups) on a PHP 8 + MySQL host.

---

### v1.170.3 — Guard: a platform admin can\'t be demoted into a portal-only role (lockout fix)

Fixes the footgun where changing a platform administrator\'s role to Producer (a portal-only,
self-service role) hid the platform console and the full tenant workspace — locking the operator out of
administration even though their `platform_admin` flag was still set.

- **`TeamService::updateRole`** now blocks moving a `platform_admin` user into any portal-only role
  (Producer or a custom portal role): "This is a platform administrator. Remove platform-admin access
  first if you intend to move them to a self-service role." (`find()` now also reads `platform_admin`.)
- **`SuperadminService::setUserRole`** blocks lowering a `platform_admin` user below owner for the same
  reason.
- **Not a code regression from prior work** — auth/nav/context were unchanged; this adds a missing guard.
  Role and `platform_admin` remain independent concepts; clearing the flag (a deliberate, separate action
  via `setPlatformAdmin`, which already protects the last admin and self) is still the way to intentionally
  demote a platform admin.

**Recovery if you already hit this:** set the role back to Master Admin (tenant_owner) via the user-manage
UI, or:
`UPDATE users SET role='tenant_owner' WHERE email='<admin>' AND tenant_id='<platform tenant id>';`
then log out and back in to rebuild the session.

No schema change. **Runtime validation pending** — `php -l`, tests, and gate on a PHP 8 + MySQL host.

---

### v1.170.2 — Deleted providers/credentials drop off the calendar (and never re-appear)

Closes the gap where soft-deleting a provider or credential stopped nothing on the calendar.

- **Calendar sync now skips soft-deleted holders.** Both the credential-expiry and verification-expiry
  sync queries now require `h.deleted_at IS NULL`, so a deleted provider's deadlines are no longer
  regenerated.
- **Stale auto-events are cleaned up on every sync** (which runs on calendar load): any non-manual event
  whose provider is soft-deleted, or whose source credential is gone/soft-deleted, is itself soft-deleted
  — so those events disappear from the calendar and stay gone. Manual events are never touched.
- **Confirmed already-correct (documented for clarity):** reminder emails (`ReminderService` skips holders
  that `find()` can't return, i.e. soft-deleted) and in-app notifications (`NotificationService` filters
  `h.deleted_at IS NULL`) already stop for soft-deleted providers/credentials. So: **soft-delete or delete
  a provider → no calendar entries, no reminder emails, no notifications.**

No schema change. **Runtime validation pending** — run `php -l`, the tests, and the gate on a PHP 8 +
MySQL host; verify a deleted provider's events vanish from the calendar after it loads (sync runs on load).

---

### v1.170.1 — Fix delete button visibility + credential delete/restore + delete cascade clarity

Fixes the delete workflow surfaced in testing and extends recover-then-purge to credentials.

- **Delete button now visible.** The "Remove producer" button was gated on `$creds === []` (a leftover
  from before soft delete) and buried in a tab — so it was hidden for any provider that had credentials.
  Removed that stale gate and moved a **Delete** action into the always-visible provider header band,
  gated by `records.delete`.
- **Credential delete/restore/permanent-delete (Q2/Q3).** Removing a duplicate or a credential that does
  not apply to a client is now one click: an **✕ Remove** on each on-file credential in the checklist
  (returns to the provider). Credentials get the same **Deleted credentials** trash: restore, or
  permanent delete (confirm `PERMANENTLY DELETE`; also removes that credential's documents, evidence
  links, and verification records). Linked from the credentials list.
- **Soft-delete cascade (Q4).** When a provider is soft-deleted, its credentials are hidden everywhere:
  fixed a credential query that previously filtered only the credential's own `deleted_at` and not the
  holder's, so a soft-deleted provider's credentials no longer leak into any list. Restore brings the
  provider and its credentials back together; permanent delete of a provider removes its dependent rows.
- **Repository:** `CredentialRepository::trashed()` added (mirrors the holder trash). All tenant-scoped.

No schema change (reuses `deleted_at`). **Runtime validation pending** — run `php -l`, the tests, and the
gate on a PHP 8 + MySQL host.

---

### v1.170.0 — Soft delete + restore + permanent delete for providers/organizations

Adds the recovery-safe delete workflow SuperAdmin/managers were missing. No schema change (reuses the
existing `deleted_at` soft-delete columns).

- **Soft delete (recoverable):** deleting a provider/organization now moves it to **Deleted records**
  instead of being blocked when it has credentials. Its credentials and links stay attached and return
  intact on restore. Gated by `records.delete`; audited (`provider_deleted`).
- **Deleted records (trash) view:** `/holders/trash` lists soft-deleted records (names decrypted like the
  active list), with a "Deleted records" link on the providers page (shown to `records.delete` users).
- **Restore:** one click brings a record back exactly as it was (`provider_restored`, audited).
- **Permanent delete (deliberate, irreversible):** from Deleted records only, requires typing
  `PERMANENTLY DELETE` to confirm; removes the record and its dependent rows (credentials, documents,
  requirements, verifications, requests, case documents, regulator/group/location/assignment links);
  audited (`provider_purged`). A record must be soft-deleted first — you cannot purge an active record.
- **Repository additions:** `TenantRepository::restore()`, `findTrashed()`, audited `purge()`;
  `HolderRepository::trashed()`. All tenant-scoped — one tenant can never restore/purge another's records.
- **Tests:** +8 assertions (O) covering soft delete → trash → restore → cross-tenant rejection → purge.

No migration. **Runtime validation pending** — run `php -l`, the test suite, and the gate on a PHP 8 +
MySQL host.

---

### v1.169.0 — Non-clinical credential types + operator self-compliance (Option C + Path A)

Broadens the catalog beyond clinical items and makes the platform operator\'s OWN compliance
first-class — both by REUSING the existing engine (no new architecture).

- **Non-clinical catalog types (migration 122):** seeds a small, universal set every healthcare
  organization objectively has — Business License, General Liability, Workers Comp, W-9, Organization
  NPI (Type 2), HIPAA Training, OSHA Training, BLS/CPR, Background Check — each tagged with an existing
  category (license/insurance/identifier/training/certification/background) and scoped to organization
  or staff. Built-in and visible to every healthcare tenant; extend further per-tenant via the existing
  custom-type UI. No schema change.
- **Grouped requirement picker:** the case "Add requirement" selector now groups the catalog by
  category (optgroup) so clinical and non-clinical types are easy to find as the list grows.
- **Operator self-compliance (Path A):** the platform operator already runs in its own tenant
  (org_shape=platform, vertical=healthcare — migration 100) with full access to providers/catalog/
  templates/cases, and organization-type holders already exist. This release makes it discoverable: a
  "Your organization\'s own compliance" panel in Console → System links the operator to add its own
  organization record and track business license, insurance, W-9, HIPAA/OSHA training, etc. using the
  same catalog and case workflow the tenants use. No new tables, no parallel engine.

Migration added: 122_nonclinical_catalog.sql. **Runtime validation pending** — run migrations, `php -l`,
and the gate on a PHP 8 + MySQL host.

---

### v1.168.0 — Agency Credentialing: end-to-end workflow layer

Builds the operational workflow on the accepted core. Reuses existing infrastructure throughout; NO
schema change (migration 121 unchanged).

- **Template-driven requirement population (§4):** `CredentialingCaseService::populateRequirements()`
  seeds a case's requirements from the provider's profession template (reuses `requirement_templates`
  + `credential_catalog`; no parallel engine). Runs automatically on case creation and via a "Populate
  from profession template" button; idempotent per (case, code).
- **Existing-evidence reuse (§8):** `reusableEvidenceFor()` surfaces the provider's credentials per
  requirement code with validity (expired = not valid). Presented for human selection in the link form;
  never auto-satisfies.
- **Case workspace (§11–§13):** prominent progress summary (satisfied/total, % bar, needs-evidence and
  hard-stop counts), a hard-stop indicator, an Applications section (case-linked payer submissions), and
  the existing requirements/evidence/documents/history — one screen to run a case.
- **Work-queue dashboard (§15):** expanded buckets — hard stops, needs-attention, submitted, target ≤14
  days, unassigned, overdue requirements, active total — surfaced as KPI tiles on /cases.
- **Provider integration (§18):** the provider page shows that provider's credentialing cases with a
  one-click "New case" (pre-fills the provider).
- **Case types (§3):** initial credentialing, recredentialing, payer enrollment, payer revalidation,
  provider update, other.
- **Tests (§21):** 37 assertions — adds template population + idempotency (M) and evidence-reuse validity
  + no-auto-satisfy (N) to the existing integrity suite.

No migration. Governance unchanged (single-authority transitions, hard-stop enforcement, tenant/case
ownership on every mutation). **Runtime validation pending** (authored without PHP/MySQL): run `php -l`,
the test suite, and `harness.sh full` on a PHP 8 + MySQL host.

---

### v1.167.2 — Agency Credentialing: final static remediation (assignment + cross-case integrity)

Code-only (no schema change). Closes two P0 same-tenant integrity gaps and fixes a non-failing test.

- **P0 §1 — Case assignment ownership.** `CredentialingCaseService::assign()` now validates that a
  non-null assignee is a user in the current tenant (null = unassignment, still valid); on failure it
  makes no update and writes no success event.
- **P0 §2 — Cross-case mutation.** Deleting a case document and unlinking evidence are now service
  methods (`deleteCaseDocument`, `unlinkEvidence`) that verify the target belongs to the **URL case**,
  not merely the tenant — so within one tenant, Case A can no longer delete Case B's document or unlink
  Case B's evidence. Routes call the service; relationship validation no longer lives only in index.php.
- **P1 §3 — Test J rewritten.** The old assertion set `$jOk=true` on both success and exception and
  could never fail. Replaced with real checks: (A) `credential_documents.credential_id` is still
  NOT NULL (information_schema); (B) a valid credential-scoped document still inserts; (C) a NULL
  credential_id insert is rejected (proving Agency Credentialing did not make it optional).
- **§8 tests** expanded to 32 assertions (adds K: assignment valid/unassign/nonexistent/cross-tenant;
  L: cross-case delete/unlink rejected + rightful case allowed).

No migration. **Runtime validation pending** — authored without PHP/MySQL; run `php -l`, the test suite,
and `harness.sh full` on a PHP 8 + MySQL host to convert TEST AUTHORED → RUNTIME PASS.

---

### v1.167.1 — Agency Credentialing remediation (P0 integrity + workflow completion)

Code-only remediation of v1.167.0 (no schema change; migration 121 unchanged). Fixes three P0 integrity
defects and completes the case workflow.

- **P0 §1 — Evidence ownership chain.** `CaseRequirementEvidenceRepository::link()` now resolves the
  case requirement → case → case provider and verifies the evidence target belongs to that provider
  (credential/attestation/case_document/verification by holder_id; credential_document via its credential;
  case_document/verification also rejected if bound to a different case). Tenant equality is no longer
  sufficient. Exactly-one-target preserved.
- **P0 §2 — Case-creation FK validation.** `CredentialingCaseService::open()` validates holder, client
  group, payer, location, and assignee all exist in the tenant before creating anything; returns a
  controlled error and creates nothing on failure.
- **P0 §3 — Hard stop fails CLOSED.** `submissionBlockers()` no longer swallows a compliance-eval failure
  on the protected gate: under strict evaluation it returns a `compliance_check_error` blocker and logs
  via `flx_log_error()`, blocking `ready_for_submission`. Display callers pass `strict=false` for graceful
  degradation.
- **P1 §4 — Requirement mutation moved to the service** (`updateRequirementStatus()`: ownership checks,
  status validation, completed_at, domain event). The route calls the service, not the repo.
- **P1 §5 — Requirement code validated** against the active `credential_catalog` (`CatalogService::labelsFor`)
  before adding; idempotent duplicate behavior preserved.
- **P1 §6 — Evidence UI**: view/link/unlink per requirement, CSRF + capability gated, domain events.
- **P1 §7 — Case document lifecycle**: upload/download/delete for `case_documents`, reusing the existing
  storage/security convention (size, plan quota, MIME allowlist, metadata stripping, per-tenant path,
  checksum); tenant-scoped access — a shared provider never exposes another tenant's case documents;
  `credential_documents` semantics untouched.
- **§10 — Work-queue query failures are logged** (`flx_log_error`), no longer silently indistinguishable
  from a legitimate zero. SQL errors never reach the user.
- **§8 — Tests expanded** to 22 assertions covering A–J (read/update/delete isolation, shared-provider
  non-leak, evidence wrong-tenant/wrong-provider/wrong-case, invalid FK creation, invalid code, service
  mutation history, fail-closed hard stop, backward compat, credential_documents unchanged).

No migration. **Runtime validation pending** — authored in an environment without PHP/MySQL; `php -l`,
the test suite, and `harness.sh full` must be run on a PHP 8 + MySQL host.

---

### v1.167.0 — Agency Credentialing (orchestration layer)

Migration 121. Adds a case-oriented orchestration layer over the existing model WITHOUT replacing any
provider, credential, requirement, document, verification, application, or compliance structure.

- **New tables:** `credentialing_cases` (the operational unit; one provider → many cases),
  `credentialing_case_requirements` (case-specific checklist bound to `credential_catalog.code`),
  `case_documents` (evidence NOT tied to a credential — `credential_documents.credential_id` left
  NOT NULL/untouched), `case_requirement_evidence` (typed nullable FKs — no polymorphic design; exactly
  one target per row, enforced in the repository with cross-tenant validation).
- **Extended (nullable, additive):** `applications`, `source_verifications`, `document_requests`,
  `compliance_events` each get a nullable `case_id` (+ index). Existing rows and code paths keep working
  with `case_id = NULL`; `ComplianceEventService::record()` now persists `case_id` from `$opts`.
- **New code:** `CredentialingCaseRepository`, `CredentialingCaseRequirementRepository`,
  `CaseDocumentRepository`, `CaseRequirementEvidenceRepository`, `CredentialingCaseService` (the single
  authority for lifecycle transitions + hard-stop enforcement — reuses `ComplianceLevelService` signals),
  `AgencyWorkQueueService` (derived queries, no `work_queue` table). Routes at `/cases` (+ views).
- **Decisions honored:** provider master = `credential_holders`; client group = `provider_groups`
  (nullable `client_group_id`, no `client_organizations` table); authority = `payers` (no `regulators`
  table created); requirement identity = active catalog (dormant `requirement_defs`/`holder_requirements`
  untouched); work queue derived; hard stops enforced in the service, not the UI.
- Isolation check passes; all new tenant-owned repos extend `TenantRepository`.

Migration added: `121_agency_credentialing.sql`.

**IMPORTANT — runtime validation pending:** this was authored in an environment WITHOUT PHP/MySQL, so
`php -l`, migrations, and the §18 test suite (`tests/agency/test_agency_credentialing.php`) were NOT
executed. Run them on a PHP 8 + MySQL host before treating this as verified.

---

### v1.166.2 — Guard the regulator-requirement WRITE path (companion to v1.166.1 read guard)

v1.166.1 guarded the READ methods of `RegulatorRequirementRepository` against the missing
`regulator_requirements` table. This release closes the matching gap on the WRITE side:

- `RegulatorRequirementRepository::tableReady()` — a public probe over the existing `hasTable()` check.
- The "add regulator requirement" route (index.php, /regulators) now checks `tableReady()` before
  inserting; if the table is absent it shows a clean message ("Regulator-defined requirements are not
  available on this installation.") instead of a fatal PDOException. Where the table exists, this is a
  no-op and the insert proceeds unchanged.

Considered but NOT shipped: a SchemaDoctor "expected-but-missing tables" health check. It would have
produced false positives, because `SchemaDoctor::PURPOSE` uses short/legacy key names
(`verifications`, `attestations`, `template_items`, `regulators`, `schema_migrations`) that don't match
actual table names — flagging 5 present tables as "missing" alongside the one real gap. Without a runtime
to verify the true name mapping, shipping it would mislead operators, so it was reverted. Left as a
recommendation instead (see docs/architecture/RESPONSE_TO_CHATGPT_3.md).

No migration. No schema change. Compliance business rules unchanged.

---

### v1.166.1 — Fix: guard flx_compliance_data's dependency on the missing regulator_requirements table

The architecture audit (P1-4) found that `RegulatorRequirementRepository` queries a `regulator_requirements`
table that **no migration creates**, and that `flx_compliance_data()` calls it **unconditionally** (mapById
+ byRegulator) with **no error handling** — so on any database lacking that table, every compliance view,
report, and the providers/producers tabs would throw a PDOException.

- Added a cached `hasTable()` check to `RegulatorRequirementRepository`; the three read methods
  (`forRegulator`, `byRegulator`, `mapById`) now return an empty result when the table is absent instead
  of throwing. Where the table **does** exist, behavior is byte-for-byte unchanged (the check returns true
  and the same SELECTs run).
- No table was created (the audit could not prove the feature is intended vs abandoned — creating it
  might be wrong); no other code changed; writes are left as-is so a genuine misconfiguration still surfaces.
- This is a defensive correctness fix, not a consolidation. Compliance business rules are unchanged.

No migration.

---

### v1.166.0 — Persistent provider header band + curated packet generator

Two professional-polish upgrades modelled on established credentialing platforms.

- **Persistent provider header band.** Every provider screen now opens with a header band (dark, matching
  the app chrome): avatar, name + status + provider type + specialty + compliance level, action buttons,
  and a two-column **Provider information / Organization** panel (NPI + verified check, email, DOB & SSN
  — field-gated and masked — CAQH ID, languages; org name, Tax ID, Medicaid ID, PTAN, live compliance
  %). It stays visible across all tabs, so context is never lost. (`_provider_header.php`.)
- **Curated credential packet.** "Credential package" now opens a **selection screen** (`?pick=1`):
  a checkbox table of the provider's credentials with status, expiry and document count, a purpose/scope
  selector, and Select-all. Check exactly what to include and generate a single PDF with only those —
  the payer/hospital/audit packet flow. The generator honours an explicit `ids=` list.

No migration.

### v1.165.1 — Fix: provider-page tabs (were not switching) + professional 7-tab layout

The provider page tabs only controlled the top four sections — everything else (compliance level,
readiness, history, addresses, contacts, verification, enrollment, cross-org, requests, shares,
timeline, audit) rendered OUTSIDE the tab container, so it was always visible regardless of the tab:
the "one long list" effect. Introduced during the v1.165 checklist merge.

- **Tabs now actually switch.** Rebuilt the page into seven self-contained tabs — Overview, Credentials,
  Profile & history, Payers & enrollment, Sharing, Continuing education, Activity — each showing only
  its own content. Verified in a browser: clicking a tab reveals exactly that pane and nothing else.
- **Professional layout** modelled on standard credentialing dashboards (per the references): a clean
  provider header (name, breadcrumb, Profile packet / Edit / Add credential), the onboarding checklist,
  then the tab bar with a live "done/total" badge on Credentials.
- Fixed a stray duplicated `<div>` on the enrollment panel.
- **Demo now populates every provider's checklist** — a global post-build template sweep covers all
  five demo orgs (previously only two), so any provider you open shows a real credential checklist.

No migration.

### v1.165.0 — Merged credential checklist + automatic profession→requirements

The provider screen is rebuilt around the workflow you actually run: **pick a profession → its required
credentials appear → work the list → add extras as needed.**

- **Selecting a profession now auto-loads its requirements.** Previously a template was only applied if
  you manually picked it in onboarding step 3 — so quick-added and imported providers had an empty
  requirements list while credentials piled up unmatched (the root of "it just shows info"). New
  `TemplateService::applyByProviderType()` fires automatically on **create, quick-add, edit, import and
  onboarding**. The onboarding profession picker now offers **all 11 professions** (was only Physician
  and Nurse), each noting that choosing it loads the credentials.
- **Requirements and Credentials are merged into ONE checklist.** The two separate tabs — and the
  "How this works" paragraph that had to explain the split — are gone. The new "Credentials &
  requirements" tab shows a single list: a progress bar, then one row per required credential with its
  status (on file / expiring / expired / needed), expiry date, "Why?" citation, and a single action
  (View / Renew / **Add document**). Problems float to the top; a provider's extra credentials list
  after. `+ Add another requirement` covers ad-hoc additions.
- **Adding a document satisfies the row in place.** "Add document" opens the credential form carrying
  `return=holder`; saving it (with its expiry date) returns to the checklist with the item checked off —
  no separate screen to reconcile. Verified end-to-end.
- Demo providers now arrive with their checklists populated. New `ChecklistService`.

No migration.

### v1.164.1 — Calendar: fixed event overflow, mobile layout, and timezone

- **Fixed the month-grid overflow** (the Saturday column clipping in the screenshot): long event
  titles were spilling past the grid's right edge instead of truncating. Cells now use
  `minmax(0,1fr)` + `min-width:0` + `overflow:hidden`, and events are block-level with a hard
  `max-width:100%`, so every title ellipsis-truncates inside its cell on any column.
- **Mobile calendar rebuilt**: below 560px the 7-column month grid (unreadable on a phone) collapses
  to a **stacked day-list** showing only days that have events, each event full-width and legible.
  Between 560–900px the grid tightens but stays a grid.
- **Timezone made correct and settable.** The calendar computed "today" and overdue in **UTC**, which
  could shift a day for Eastern/Atlantic users. It now uses the app timezone throughout (event day
  placement, today highlight, overdue). `app_tz()` reads an operator setting first, so **Console →
  System → App timezone** lets you choose Puerto Rico (AST), Eastern, etc. without editing config —
  defaults to Eastern. Verified: setting persists and the calendar honors it.

No migration.

### v1.164.0 — Management dashboard (process hub) + return-to-dashboard workflow

The dashboard is now a **management-level process hub** rather than a static summary, built for a
finance/operations manager running the credentials & compliance process:

- **KPI tiles that drill**: Billable % (providers at level 4–5), **Revenue at risk** (not billable),
  Expired, Expiring ≤30 days, Due ≤90 days, Providers — each opens the filtered list behind it.
- **Compliance exposure donut** (levels 0–5; click a level to open that cohort) and a **6-month expiry
  forecast** bar chart (click a month to open it on the calendar). Pure SVG — no charting library,
  consistent with the app's no-dependency rule.
- **Work queue** — the actual process: a prioritized list (expired → not-compliant → expiring ≤30d →
  submitted documents to review → re-verifications due), most urgent first, with a one-click Renew /
  Fix / Review / Re-verify action that opens the exact record.
- **← Back to dashboard bar** — when a user drills into any record from the dashboard or the queue, a
  persistent bar at the top offers the way back (and "Next item"), so nobody has to reach for the side
  panel to return. Session-scoped, clears on return, expires after 30 minutes.
- **30-day throughput** (verifications, documents, requests accepted, credentials updated) and a
  **blocked providers** list with the reason each is stuck.
- New `DashboardService` computes all of it in one pass. Verified end-to-end: dashboard renders with
  zero warnings; click-through → return bar → back works. Test plan gains A7–A11.

Also investigated a reported calendar issue: the calendar renders correctly for tenants with data (all
11 October events for the reported tenant place on the right days); an empty calendar simply means
that organization has no credentials with expiry dates yet. No calendar change was needed.

No migration.

### v1.163.1 — Fix: provider page warnings (duplicate readiness variable) + carrier notice

The provider page emitted a stream of `Undefined array key` warnings ("score", "ok", "total",
"groups") and a `foreach(): null given` error. Cause: the page has **two** readiness panels — the
six-sub-score "Provider readiness" (ReadinessService, shape `overall/summary/scores`) and the newer
"Application readiness" data audit (ApplicationReadinessService, shape `score/ok/total/groups`) — but
both were passed to the view under the same `readiness` key, so the second silently overwrote the
first and the surviving panel read keys that weren't there. Each panel now has its own variable
(`legacyReadiness` vs `readiness`), wired to the matching service. Also fixed an `Undefined array key
"carrier"` notice in `TaskSyncService`. Verified: the provider page renders with zero warnings and both
panels show correctly.

No migration.

### v1.163.0 — Engineering handoff doc (docs/HANDOFF.md)

Added `docs/HANDOFF.md`: the operating context an AI assistant or new developer needs to pick up the
codebase and continue safely — stack constraints, the non-negotiable patterns (tenant isolation,
encryption + the tenant|table|column AAD, field-level security, CSRF, migration-safety), the release
gate, bilingual parity, config/secret handling, the packaging steps, the staging→production model, a
current-state map, and the working style. It ships in every package alongside the other docs and its
version line is kept in lockstep with `FLXCOREHC_VERSION`.

No migration.

### v1.162.0 — Deep rename to FlxCoreHC (complete)

The rename is now total — the previous working name is gone from the entire product (code, data, docs, assets),
done now while the system is clean of client data so there is zero risk to encrypted records.

- **PHP namespace** the old namespace → `FlxCoreHC\` across all 100 classes and 1,121 references; the
  autoloader prefix updated to match.
- **Constants** the old prefix → `FLXCOREHC_*` (version, root, app, edition, app-name, tagline, cron hint).
- **Session cookie** renamed to `FLXCOREHCSESSID` (users re-login once).
- **Seed data** `verified_by` stamps → `'FlxCoreHC'` on all regulatory-source rows and in the seed
  migrations; config sample db name/user → `flxcorehc`.
- **Comments, docs, CSS/JS headers, installer, help (EN/ES), emails** all swept. Only the historical
  changelog entries keep the old name, as a dated record.

**Safety verified:** the encryption AAD is built from `tenant|table|column` and never referenced the
brand name, so the rename could not affect encrypted data — confirmed by an end-to-end round-trip of
NPI, SSN (last-4) and DOB after the rename. Full gate green, isolation clean, 232/232 files lint,
Spanish 579/579, fresh install to 85 tables.

Because this renames the session cookie and the config sample's default DB name, on an **existing**
deployment: keep your current `app/config.php` (its `db_name`/`db_user` are unchanged — only the sample
changed), replace all other files, and everyone signs in again once. No schema migration.

No migration.

### v1.161.0 — Rebrand to FlxCoreHC (display) + repositioning

The product is now presented as **FlxCoreHC — Healthcare Compliance Intelligence Platform**. This is a
**display-only** rename: everything a user sees changes; the internal PHP namespace (`FlxCoreHC\`) and
identifiers are unchanged and will be migrated in a separate, dedicated pass so the encrypted data and
every class reference stay safe.

- **Product name** → FlxCoreHC everywhere users see it: page titles, sidebar/brand, login, emails
  (sender name), PDF footers and headers, data-export README, TOTP issuer, invoices, and all docs.
  Routed through a single app-name constant so the eventual namespace rename is a no-op here.
- **Tagline** → "Healthcare Compliance Intelligence Platform" (login and docs), via a single tagline constant.
- **Positioning corrected** — the app now describes itself as a **credentials & compliance support
  system**, not a credentialing authority: the profile packet's "Credentialing template" is now
  "Compliance template", and the capabilities doc states plainly it is not a credentialing authority.
  Verification wording already described *recording documented verification*, not performing it.
- 77 display strings updated across 39 files; all 674 namespace references and the old constants left intact; Spanish re-synced (566/566).

Deferred to its own pass (post-testing): the deep namespace rename (the namespace, the constants, the session cookie, and the `verified_by` seed stamps), handled with the crypto and
cookie explicitly protected and decryption re-verified.

No migration.

### v1.160.0 — Puerto Rico regulator & source expansion

Migration 120. Verified against PR government sources (salud.pr.gov, orcps.salud.pr.gov, ocs.pr.gov,
ases.pr.gov, assmca.pr.gov), September 2026.

- **20 Puerto Rico regulators added** for every healthcare organization: the profession-specific
  examining boards under ORCPS — nursing, pharmacy, dental, psychology, professional counselors,
  medical technologists, physical therapy, occupational therapy, speech-language/audiology, nutrition,
  radiologic technologists, EMTs, physician assistants, chiropractors, optometrists, health-services
  administrators — plus the oversight agencies **ORCPS, OCS, ASES (Plan Vital)** and **ASSMCA**. Each
  carries its jurisdiction (PR). Seeded per healthcare tenant, idempotent, and correctly scoped so
  non-healthcare tenants get none.
- **6 source-cited regulatory entries added**: `orcps_recert` (3-year recertification package),
  `nursing_lic`, `pharmacy_lic`, `dental_lic`, `behavioral_lic` (ASSMCA facility certification) and
  `ases_vital` (Plan Vital administration) — each with authority, legal citation, official URL and a
  reviewed-on date, joining the existing PR sources (ORCPS recert, CE, OCS/SICRO, Medicaid PEP,
  telehealth, universal recognition).
- Existing organizations pick these up via **Console → System → Sync templates & regulators** (or
  *Update database*). Florida is intentionally deferred — it will get the same source-verified
  treatment in a later pass.

Migration added: `120_pr_regulators_expansion.sql`.

### v1.159.0 — Fix: templates not seeded for existing tenants; onboarding go-back; clearer "Licence held"

- **Compliance templates now reliably seed for every healthcare organization.** A non-idempotent
  statement in the seed batch (a `DROP CONSTRAINT` from migration 079) threw on databases that didn't
  have that constraint, which **aborted the whole seed** — so some organizations ended up with zero
  templates and regulators (the "No templates yet" screen). Each seed statement is now fault-tolerant;
  a single failing DDL no longer stops the inserts. New signups get the 14 provider-type templates, the
  standard regulators and calendar types immediately.
- **Sync templates & regulators** button added (Console → System) to backfill any existing organization
  that is missing them. Idempotent — never duplicates or overwrites edited templates. (Running *Update
  database* also backfills, as before.)
- **Guided onboarding can go back.** Every completed step is now clickable in the progress bar, and each
  step has an explicit **← Back** button. The record is created at step 1 and refined as you go, so
  moving back and forth never loses data.
- **"Licence held" clarified.** It was labelled "drives the template" on two different fields. The
  provider **type** selects the template; the specific licence only re-orders the suggestions so the
  closest match is first. Labels and help text now say so.

No migration.

### v1.158.1 — Fix: custom-field reads crash on installs that have not run migration 119

The profile packet (and any page reading custom fields) threw `Unknown column 'v.value_enc'` on a
database where migration 119 had not yet run — the Level-3 code assumed the new columns existed. This
is the "code ahead of an unrun migration" class flagged in the audit. `CustomFieldService` now detects
whether the sensitive-field columns exist and degrades cleanly when they don't: reads, saves, the
packet, exports and the field builder all work on both pre- and post-119 databases. Running *Update
database* restores full Level-3 behaviour. No new migration.

### v1.158.0 — Field-level security levels 2 & 3, full-codebase audit

Migration 119. Completes the field-level security model and records a full security audit.

- **Level 2 — per-field rules by role.** Organization → Roles → any role → *Sensitive fields*: each
  protected field is Inherit / Allow / Deny. Deny overrides the `pii.view` capability; Master Admin can
  never be denied; a user without access can't set the field either. Works on built-in roles too
  (their capabilities stay locked, but per-field rules are tenant-scoped) — e.g. "Managers see DOB but
  not SSN". Stored in `role_field_grants`.
- **Level 3 — sensitive custom fields.** Ticking *Sensitive* on a custom-field definition encrypts its
  values at rest (`value_enc`), hides the field entirely from users without permission (who also
  cannot change it), forces it out of packets, lists and exports, and makes it grantable per role like
  the built-ins. Resolution order everywhere: per-role grant → `pii.view` → default deny.
- **Full-codebase security audit** appended to docs/CODE_AUDIT_REPORT.md (§8): all 232 PHP files swept
  and every hit reviewed. Two findings fixed — **`docs/` was web-readable** (contained demo passwords
  and the audit report; now denied via `docs/.htaccess`, and Console → Security checks it), and
  `RequirementRepository` gained the support-mode guard (now 19/26 repositories guarded, every one
  holding provider-linked data). Zero shell execution, zero SQL from superglobals, zero unescaped
  user output confirmed. Key-management and breach-posture recommendations recorded now that SSN
  last-4 is stored.
- All documents regenerated: capabilities, changelog, help (EN/ES), demo accounts, and the test plan
  (F14–F17 for levels 2 & 3). Spanish 566/566.

Migration added: `119_field_level_security.sql`.

### v1.157.0 — Sensitive identity data (DOB, SSN last-4) with field-level permissions

Migration 118. Answers "we need DOB and SSN" with data minimisation and a general field-level
access-control mechanism rather than just another encrypted column.

- **Date of birth** and **SSN — last 4 digits only** on the provider (Edit → Sensitive identity data).
  A pasted full SSN is truncated to the last four; the full number is never stored. Both encrypted at
  rest with the same protection as NPI.
- **Field-level access control** (new `pii.view` capability, Master Admin by default): permitted users
  see the values, everyone else sees a mask (`••••-••-••`, `•••-••-1234`) and the panel is hidden;
  users without the capability cannot set or change the fields either. Every permitted view is written
  to the access log. Grant `pii.view` to any custom role for an enrollment specialist.
- **Excluded by construction** from profile packets, the data export, tenant reports, emails, audit
  detail, external shares and cross-organization shares — verified, plus a `SensitiveFields::strip()`
  helper for any future outbound surface.
- The mechanism generalises: `SensitiveFields::FIELDS` is one entry per protected field, so future
  sensitive fields need no new plumbing.
- Application readiness now scores DOB and SSN as real items; help section (EN/ES); test plan gains
  F9–F13.

Migration added: `118_pii_fields.sql`.

### v1.156.0 — Custom fields, application data & readiness audit, rebuilt test plan

Migrations 116 and 117.

- **Custom fields** (Organization → Custom fields): each organization defines its own fields for
  providers or for the organization — text, long text, number, date, choice list, yes/no, email,
  phone, web address — required flag, help text, order, packet/list visibility. **No schema change per
  field** (definitions + values). Rendered on the provider form (Additional fields) and Organization →
  Profile; validated on save; included in profile packets and the data export (one column per field);
  retirable without losing values. Verified: validation, rendering, packet, export.
- **Application data** on the provider (Edit → Application data): middle name, suffix, prior names,
  gender, languages, primary/secondary specialty, taxonomy code, CAQH ID, Medicare PTAN, Medicaid ID,
  UPIN, hospital affiliations, accepting new patients. **Education & training** and **work history**
  (with gap explanations) as repeating records on the provider page. **DOB and SSN deliberately not
  collected** — flagged as a pending privacy decision.
- **Application readiness** panel per provider: a score and grouped checklist (Identity, Identifiers,
  Licensure & standing, Insurance & practice, History, Enrollment status) mapping CAQH / OCS / PEP /
  Medicare / payer requirements against what is on file, each gap pointing to where to fix it. Verified
  from 6% (bare) to 91% (filled); decision-pending items shown but not scored.
- **Test plan rebuilt from scratch** to cover everything through this release: Part 1 (tenant app)
  15 suites / 122 cases including navigation & hub panel, dark theme, custom fields, readiness,
  addresses, contacts, verification, compliance levels, M365; Part 2 (SaaS platform) unchanged, 68
  cases. 190 cases total; go-live gates A, I, J, L, M (tenant) and P, Q, S, V (platform).
- Demo seeds custom fields, application data, education and work history; help section (EN/ES);
  capabilities doc updated.

Migrations added: `116_custom_fields.sql`, `117_provider_application_data.sql`.

### v1.155.0 — Addresses and office contacts

Migration 115.

- **Addresses** as first-class records: any number per **organization** (Organization → Addresses)
  and per **provider** (provider page → Addresses), each with a kind (mailing, billing,
  correspondence, service, legal, home, other), optional label and effective dates, one primary
  (the first becomes primary automatically). Distinct from practice locations. Soft-deleted, audited.
- **Address on a credential** — a credential can cite the address on file with its issuer/payer
  (picker offers the provider's addresses then the organization's). This is the foundation for the
  address-consistency check across NPI / OCS / Medicaid / payers.
- **Office contacts** per provider — office manager, credentialing coordinator, billing contact —
  with role, phone, preferred language and an **encrypted email**, and **no portal account**. Flags
  decide whether each receives **document-request nudges** and/or **expiry reminders**; requests now
  go to flagged contacts (in their language) even when the provider has no email on file, and
  reminders copy flagged contacts. Verified end-to-end.
- Demo seeds addresses and contacts; help section added (EN/ES); test plan gains C8–C13.

Migration added: `115_addresses_contacts.sql`.

### v1.154.0 — Appearance save fixed, dropdown fixes, edge-tab toggle, console overview rebuilt, tenant reports

**Fixed**
- **Appearance / organization settings did not save.** The *Download all data* form added in v1.128 sat
  inside the settings form; HTML cannot nest forms, so the browser closed the outer one early and the
  Save button fell outside it — nothing submitted, and the leave-page warning fired. Moved out; verified
  in a real browser that the theme now persists. Audited every view for the same bug class and fixed the
  one other case (the role editor's Delete form).
- **Styled dropdowns closed while navigating.** They closed on any scroll event (including inside their
  own list) and had no keyboard navigation. Now: stay open on scroll (re-anchor instead), arrow keys
  highlight, Enter/Space select, Home/End, hover and keyboard share one cursor.
- **Role picker in the console user list** showed the browser's native list (it was flagged
  no-enhance) and still said "Agent" — now styled and labelled **Coordinator** consistently.
- **Right-panel toggle** is now an edge tab flowing out of the right edge (mid-height, rounded, same
  hamburger glyph as the left toggle, no text, larger).
- **Platform console tenant overview rebuilt.** The Usage & health panel had been injected inside the
  header's flex row (between the status chip and the Open-demo button), scattering the layout. Proper
  header now (name + subtitle | status + actions), full-width Usage & health, and the 12-week activity
  chart always renders 12 slots so sparse data reads as a timeline instead of one solid bar.
- **Email delivery** page was missing the hub panel; added, and it now appears in the Setup group.

**New — Tenant report (PDF and CSV bundle)** from the console tenant page: account, plan, domains, email
delivery, usage & health, **compliance-level distribution**, team (staff only), locations/groups, payers,
programs, OCS, verifications, invoices, and the platform-support consent log. **Counts and account data
only** — provider identities, credential contents and documents are never included (portal logins are
excluded from the team list; verified). Exports are audit-logged. Test plan gains L11–L14.

No migration.

### v1.153.0 — Hub panel: dark theme, collapsible groups, closable, and present on every hub page

Five fixes to the right side panel:

- **Dark theme fixed.** The panel styled itself with `--text` / `--muted`, which do not exist in this
  app (the real variables are `--ink` / `--ink-3`), so it fell back to light-mode colours and the
  group items were invisible in dark mode. Now uses the app's actual theme variables throughout.
- **Groups collapse, one open at a time.** Each group is a `<details>` accordion; the group containing
  the current page opens automatically and opening another closes the rest.
- **The panel closes.** An × in the panel header collapses it; the content reflows to full width and a
  **☰ Menu** toggle appears at the top right to reopen. The choice is remembered across pages
  (sessionStorage), and Escape closes it.
- **The top of the list is no longer cut off** — the panel starts below the top bar with a sticky
  header and its own scroll area.
- **Panel now appears on every hub-linked page.** Activity, Audit log and Billing were rendering
  without it (they never included the tabs partial), so the panel vanished when you navigated there.
  All 17 hub pages verified.
- **Mobile drawer updated** to match: slide-in with a dimming scrim, collapsible groups, no reserved
  content padding.

Verified by rendering the Organization pages in dark and light, open and closed, on desktop and mobile.

No migration.

### v1.152.0 — Fix: right-side hub panel rendered full-width and collapsed the left menu

The v1.151 hub panel was styled as a third grid column (`grid-column: 3`) but the partial is emitted
inside `<main>`, so it rendered full-width on top of the page content instead of as a right rail; and
a `hub-mode` class collapsed the left sidebar via JS after paint, so the left menu's labels vanished
until a refresh.

Fixed: the hub panel is now `position: fixed` to the viewport's right edge (240px), with `main` given
right padding on hub pages via a `has-hub` body class; the left sidebar is left completely untouched.
Mobile is a slide-in drawer opened by the ☰ Menu button. Verified by rendering the Organization and
console pages on desktop and mobile.

No migration.

### v1.151.0 — Microsoft 365 modern auth, right-side hub navigation, two-column tenant page

- **Microsoft 365 modern authentication** (migration 114). Microsoft is retiring basic SMTP AUTH.
  The operator registers **one** Azure app (Console → Email → App registration; the redirect URI is
  shown there); then the **platform sender** and **each tenant** can *Connect Microsoft 365* by
  signing in with their sending mailbox. OAuth 2.0 authorization-code flow with state bound to the
  session; refresh tokens stored encrypted; access tokens minted on demand; messages sent via Graph
  `sendMail` (also lands in the mailbox's Sent Items). Tenant test-send goes through Graph. Disconnect
  falls back to SMTP / platform sender. Test plan gains M7–M12.
- **Hub navigation as a right side panel.** On Organization and Platform-console pages the left
  sidebar collapses to its icon rail and a right panel lists every group and option; on phones it is a
  drawer opened by a Menu button. All groups and options are preserved.
- **Tenant page in two columns** (Usage & health, Lifecycle, Domains | Profile, Plan, Users, Add
  user), single column under 1000px.

Migration added: `114_microsoft_oauth.sql`.

### v1.150.0 — Regulatory grounding Level 1 + Puerto Rico laws in the Training Center

- **Requirements now cite their legal source inline.** Migration 112 links each credential/requirement
  type to a dated regulatory source (migration 110's `requirement_sources`). A provider's compliance
  requirements now show "&#9878; Why? — authority, regulation, source ↗" so a compliance officer or
  auditor can see the legal basis for every tracked item.
- **CE content corrected (verified against PR government sources).** An earlier note oversimplified
  "OA 542 removed CE." Verified: OA 542 (2022) broadly suspended CE, **OA 544 restored** CE on
  LGBTTQ+ cultural competence, and the boards still run **3-year, CE-based recertification** (hours per
  trienio). A new `ce_recert` source records this accurately — **CE is not waived.** This correction
  is the direct value of verifying sources before wiring rules.
- **Ley 102-2025 (universal license recognition)** added as a source, marked "implementing regulation
  evolving" — an intake pathway for reciprocity/provisional licenses, not a settled rule.
- **Puerto Rico laws added to the Training Center** (6 source-verified entries): 3-year license
  recertification, CE (OA 542/544), Ley 102-2025 universal recognition, OCS/SICRO (Ley 73-2023),
  Medicaid PEP (with the location-registration claim-denial rule), and telehealth (Ley 8-2025). Each
  carries the authority and an official link.

Migrations added: `112_requirement_source_links.sql`, `113_pr_training_content.sql`.

### v1.149.0 — Grouped navigation, two-column reference pages, translation grouping fixed, 100% Spanish

- **Organization and Platform console navigation regrouped.** Both had grown to 13–14 tabs in one
  wrapping row that broke on mobile. They are now logical groups rendered as a compact rail on
  desktop — Organization: *Setup / People & access / Compliance / Sharing & oversight / Billing*;
  Console: *Customers / Content & data / Operations / Reference* — and on phones a single native
  select with optgroups instead of tab soup. The console's Reference group also exposes Capabilities,
  Test plan and Demo accounts, which previously were only reachable from the System page.
- **Data dictionary and changelog now two columns** (single column under 900px), and every changelog
  entry is **collapsed by default**.
- **Translation editor grouping fixed.** Strings collected before the `context` column existed had
  NULL context and all fell into one "general" list. The editor now falls back to classifying the
  string itself into 13 areas (Providers, Credentials, Documents & verification, Payers & enrollment,
  Calendar, Sharing, Reports, Team & access, Audit, Email, Settings, Buttons, Other), so grouping
  works regardless of when a row was collected.
- **Spanish coverage is now 100%** — every one of the 433 translatable strings, including the 52 that
  had no entry, plus the new navigation and editor group labels.

No migration.

### v1.148.1 — Changelog in the console

The release history is now readable in-app at **Console → System → Changelog** (and its own tab):
every release newest-first, the current version highlighted, each entry collapsible, with a reminder
to run *Update database* when an entry mentions a migration. Generated into **docs/CHANGELOG.md**
(86 releases), which ships in the package. No migration.

### v1.148.0 — Primary-source verification (automated where an API exists, assisted where it doesn't)

Source-verified against what each authority actually offers, so nothing pretends to verify but can't.
Migration 111.

- **Tier A — automated.** *Verify NPI (NPPES)* re-pulls the live CMS record and flags name/taxonomy/
  state mismatches (free public API, already in use). *Check SAM.gov* runs a real-time federal
  exclusion check (free SAM.gov API key, set once in Console → System, stored encrypted); a match is
  also written as an exclusion screening, so it **hard-stops the compliance level**.
- **Tier B — assisted manual.** ORCPS Good Standing, OCS/SICRO, Medicaid PEP and Medicare PECOS have
  **no public API** (confirmed: OIG states plainly it will not offer a LEIE API; ORCPS is web-only).
  So the app deep-links to the official page and records the result with a screenshot as evidence —
  exactly what a payer or auditor accepts.
- Every verification is stamped (who / when / source / outcome), tied optionally to a credential and
  evidence document, feeds the permanent timeline, stamps the credential's last-verified date on a
  clean check, and carries a **re-verify date** so checks recur. OIG LEIE continues via the monthly
  file import.
- Demo seeds sample verifications; help section and Spanish added; capabilities doc updated.

Also surfaced during source research (flagged for the regulatory-sources review, not yet applied):
**Ley 102-2025** (universal license recognition) and **OA 542** (removed a continuing-education
requirement for some PR health professionals).

Migration added: `111_source_verifications.sql`.

### v1.147.0 — Payer credentialing, Medicaid/Medicare enrollment, OCS/SICRO, compliance levels, regulatory sources

From license tracker to **credentialing & regulatory compliance platform** (Puerto Rico), grounded in
source-verified regulation. Migration 110.

- **Payer credentialing** per provider per payer: status ladder, application / effective /
  recredentialing dates, IDs. PR payers pre-loaded (Triple-S, MCS, First Medical, Menonita, MMM,
  MAPFRE, Humana, Plan Vital, Medicare); organizations add their own (Organization → Payers).
- **Medicaid / Plan Vital (PEP)** and **Medicare (PECOS/PTAN)** enrollment with revalidation
  (5y medical / 3y non-medical; 42 CFR §455.414), enrollment type, and the PEP locations-registered
  flag (claims deny since 2024-05-15).
- **OCS / SICRO** profile (Ley 73-2023, compulsory): status, attestation and expiry, delegate/CVO,
  Phase II affidavit.
- **Compliance levels 0–5 with hard stops**: an expired/missing PR license or an unresolved exclusion
  flag is level 0 regardless; Licensed → Credentialed → Payer-credentialed → Billable → Fully
  compliant, with the exact gap to the next level shown. Verified through the whole ladder.
- **Regulatory sources** table seeded with verified citations (ORCPS, OCS, PEP, PECOS, telehealth
  Ley 8-2025, LEIE, DEA); Organization → Regulations (read) and Console → Regulatory (edit, review
  cadence, "due for review").
- **Telehealth** tracked as a flag (Ley 8-2025 removed the certification requirement). Catalog gains
  Good Standing, Buena Conducta and ASUME (the ORCPS recertification package).
- Payer recredentialing, program revalidation and OCS attestation deadlines sync to the calendar and
  reminders; all status changes are permanent history. Demo seeded across every level. Help section
  added (EN/ES). **Master capabilities document: docs/CAPABILITIES.md** (also Console → System →
  Capabilities). Test plan gains Suite X.

Migration added: `110_payers_enrollment_ocs_sources.sql`.

### v1.146.1 — Fix: "Undefined array key 0" warning on the home page

Reported from a live install (`index.php` line 430). At the site root the URL produces no path
segments, so `$seg` was an empty array; the external-share route (`$seg[0] === 's'`) was the first of
45 places comparing `$seg[0]` directly, and it warned before the request redirected to the login page.
Visible on the root URL when signed out.

`$seg[0]` is now guaranteed to exist (as `''`) right where the segments are parsed, so every route
comparison is safe rather than depending on each one to guard itself; the dashboard's root check was
updated to match. Verified: root signed out, root signed in, the `/index.php` no-rewrite fallback, and
the main routes all render with **zero PHP warnings**.

No migration.

### v1.146.0 — SaaS platform & installation test suites; database-health fixes

**Test plan Part 2 — the SaaS side.** The plan previously covered the tenant application; it now also
covers running the platform itself. Eight new suites, **191 cases total across 23 suites**:

- **P Fresh installation** 🔒 — requirements screen, failure paths (bad DB credentials, read-only
  directory, single-word admin name, existing tables), re-run lock, protected directories, first sign-in.
- **Q Upgrade** 🔒 — upgrading over an existing install, running Update database, data intact,
  idempotent re-run, and browsing *before* migrations run.
- **R Tenant lifecycle** — create each org shape, invite Master Admin, plans and seat overrides,
  suspend/reactivate, approvals, custom domains — all without entering tenant data.
- **S Backups & restore** 🔒 — database and document downloads plus an actual **restore drill** with a
  measured recovery time.
- **T Cron jobs** — all four jobs, CLI-only enforcement, idempotency.
- **U Platform operations** — LEIE/SAM/Medicaid imports, plans, invoices, platform email, outbox
  flush, error log, translations, database notes.
- **V Platform security** 🔒 — cross-organization URL probing, platform admin cannot open tenant
  records, full support-boundary re-run, attributable logs.
- **W Performance** — bulk import, large calendar and audit log, baseline timings.

Go-live gate for the platform: **P, Q, S and V**.

**Fixed while validating those tests:** the Database health page queried a `migrations` table that
doesn't exist (the real one is `schema_migrations`), so the migration check always warned; and the
"tenant scoping" and "orphaned rows" checks raised false alarms on platform-wide tables and on
NULL-tenant global defaults. All 9 health checks now pass cleanly on a fresh install, so a warning
there means something real.

No migration.

### v1.145.0 — Feature test & validation plan

A complete, hand-to-a-tester test plan: **docs/TEST_PLAN.md** — **123 numbered cases across 15
suites** (smoke, setup, providers, credentials, documents & mobile capture, document requests,
external sharing, cross-organization sharing, calendar/notifications/reports, roles & permissions,
privacy & platform boundary, platform console, email, language & help, edge cases), each written as
steps + expected result with Pass/Fail columns, phone 📱 / bilingual 🌐 / security 🔒 markers, a
role-permission matrix, and a sign-off sheet. Suites A, G, H, J and K are marked as the **go-live
gate** because they cover the security and privacy promises the product is sold on.

Readable in-app at **Console → System → Test plan** (alongside *Demo accounts & passwords*), so QA
can work from the console. Also fixed a stale demo credential hint on the System page (it still
advertised the old provider-portal password) and aligned the `PORTAL` constant with the current
shared demo password.

No migration.

### v1.144.0 — Demo accounts for testers, data dictionary & database health

**Demo logins for every role.** The demo previously created only organization owners, so the provider
portal and the new granular roles could not be tested. It now also seeds, in Coastal Credentialing
Partners, a **Manager, Staff, Coordinator and Read-only** user plus **provider-portal logins** for
Dr. Ana Rivera and Dr. Luis Colón (and Ana's record in Bayamón, for the cross-organization view).
All demo logins share the password `FlxDemo2026!`; the five organization owners keep their own.
Full credential sheet with what to test where: **docs/DEMO_ACCOUNTS.md**, also rendered in-app at
**Console → System → Demo accounts & passwords**.

**Console → Database** (new): a live **data dictionary** — every table with its purpose, row count,
columns, indexes, size, engine, collation and tenant-scoping status, searchable, each with an
**operator notes** field saved in a new `schema_notes` table (migration 109). Plus **database
health**: migration drift, tenant-scoping gaps, storage engine and charset consistency, tables
without indexes, orphaned rows left by removed tenants, oversized tables, stuck email outbox, and
retention. Structure and counts only — no tenant record contents are read.

Migration added: `109_table_notes.sql`.

### v1.143.0 — Platform-operator boundary hardened, Security console, tenant usage dashboards, granular roles, demo refreshed

**Security (highest-value fix):** a platform **support session could run the tenant's full data
export** — provider data was already blanked, but the team roster (names, emails, roles) came
through, and the action should not exist in support mode. Fixed with a hard `denyIfSupport()` stop on
the export route and inside `DataExportService`, plus `/organization/links`. Support-mode blindness
previously covered only 3 repositories; it now covers **11 repositories and 5 services** (calendar,
screenings, CE, verifications, applications, attestations, compliance, tasks, requirements,
holder-regulators, audit log, compliance events, document requests, external shares, cross-org,
notifications). Verified: every surface returns empty in a support session and export is refused.

**Console → Security:** live security posture of the installation (transport, environment, file
protections, access control, data protection, operations — 24 checks) plus the shipped code-audit
report rendered in-app. No tenant data.

**Console → tenant page → Usage & health:** per-tenant adoption (providers, credentials, documents,
storage, users, groups/locations, feature take-up), engagement (last login, last activity, actions
7/30d, active users), compliance health (expiring/expired, documents verified), a 12-week activity
sparkline and top actions — **counts and dates only**, verified to show no record contents.

**Roles are more granular:** capability catalog 11 → 17 (`records.import`, `documents.verify`,
`documents.request`, `crossorg.manage`, `data.export`, `reports.view`), enforced at their routes.
External sharing and cross-organization sharing are now separate permissions. "Agent" renamed
**Coordinator** (data entry without compliance attestations); Read-only can now view documents and
reports.

**Demo refreshed** with the newer features so they can be tested immediately: document requests (one
waiting, one accepted), an external share link (password `DemoShare2026`), a fully consented
cross-organization link with a credential shared, multi-kind documents (renewal / supporting), and
populated notifications.

No migration.

### v1.142.0 — Full code audit: 8 fixes, exhaustive audit coverage, help center completed

Whole-codebase audit (202 files). Full report with findings, fixes and recommendations:
**docs/CODE_AUDIT_REPORT.md** (in this package).

**Fixed:** (1) **Permissions-Policy header disabled the camera** — would have blocked Take photo /
Scan on real devices; now `camera=(self)`. (2) Document request could target a credential of another
provider — now validated. (3) Cross-org shared-document download not bound to the route's provider
(need-to-know) — now bound. (4) No per-IP rate limit on the public share password prompt — added
(20/15 min). (5) Emailed links could be built from the client Host header — configured `app_url`
now always wins. (6) Route-level Master-Admin / share.manage gates on cross-org routes (service
already enforced; defense in depth). (7) Cross-org view logging throttled. (8) Sixteen previously
unlogged state changes now audit-logged (86 call sites).

**Help center:** five missing feature guides added in English and Spanish (documents & mobile
capture, document requests, external sharing, linking with other organizations, activity & audit);
18 sections total.

No migration.

### v1.141.0 — Cross-organization sharing (consent model)

The same real-world provider managed by several organizations can now have their records **linked**,
so a credential (with documents) maintained by one organization is **seen live, read-only** by another
— without merging records, without anyone editing anyone else's data, and without anything being
shared until all three parties consent. Full process & security reference: **docs/CROSS_TENANT_SHARING.md**;
training diagram: **docs/cross-tenant-flow.svg / .png** (both included in this package).

- **Blind detection:** matching uses the NPI blind index only; a requester learns just a *count* of
  other organizations, never names or data.
- **Three-party consent:** requesting Master Admin → provider (portal, identity checked against their
  NPI blind index) → owning Master Admin. Any party can decline. Statuses: pending_provider →
  pending_owner → active | declined | revoked.
- **Per-credential, directional, live:** linking shares nothing by itself. The owner shares chosen
  credentials (± documents) to a linked organization; the recipient sees the owner's live rows
  read-only (no copy — verified 0 rows created in the recipient), sees renewals immediately, and can
  view/download but never edit or delete. Un-shared credentials stay private (verified).
- **Revocation cascades:** owner withdraws a share; either Master Admin revokes a link (all its shares
  withdrawn); the provider withdraws all consent (every link and share on their identity). Verified:
  recipient sees nothing and downloads are blocked immediately after.
- **Logged in both organizations:** audit_logs, permanent compliance_events in each tenant's timeline,
  and `cross_share_access` (every view/download with org, user, IP) with a per-share Access log.
- **Isolation:** `CrossTenantService` is the only class permitted to read across tenants; every
  recipient read re-verifies share + active link + recipient identity on each call.
- Screens: provider page → *Other organizations* (detect, request, share, shared-with-you, logs);
  Organization → *Linked providers* (Master Admin approve/decline/revoke); provider portal →
  *Linked organizations* (consent / decline / withdraw all). Bilingual.

Migration added: `108_cross_tenant_sharing.sql`.

### v1.140.0 — Multi-document credentials, document requests (provider portal), external sharing

Three related features, designed together, each verified end-to-end. Nothing sensitive is ever
emailed — emails are nudges that link into the app; every access is logged.

**1. Multi-document per credential** (migration 105). Every credential / managed item holds 0..n
documents, each with a `kind` (primary / renewal / supporting / correspondence / other) and its own
verification and history. A new primary or renewal supersedes the prior primary (chain retained);
supporting documents coexist. Kind selector on upload; badges in the list.

**2. Document requests — the tenant asks, the provider/office uploads in their portal**
(migration 106). From a provider's page: Request a document (kind, optional target credential, note,
needed-by date). The provider gets an in-portal request plus one email nudge ("upload it in your
portal — do not reply with attachments"). In the portal's new **Document requests** screen they
upload directly (camera / scan supported). The tenant then **Accepts** (document locks to the tenant;
the provider keeps a read-only "you provided this" record and can never edit or delete) or
**Returns** with a note (reopens). While open/returned the provider may submit a replacement; after
acceptance a resubmit is refused. Cancel available to the tenant. Every transition is permanent
history (requested → submitted → accepted / returned).

**3. External sharing — payers, state agencies, hospitals, others** (migration 107). From a provider's
page: Share externally → recipient type + name, which credentials, include documents, **active for N
days (max 180 — share again if more time is needed)**, password (set or auto-generated), optional max
views. Two factors: the URL token (stored only as a hash) **and** a password (Argon2id). The password
is shown **once** and is **never emailed with the link** — the sharer delivers it separately. The
recipient (no login) gets a password prompt that reveals nothing but the recipient's own name, then a
branded read-only profile within scope with document downloads. **Logged:** creation (audit +
compliance event), every view, every download, every failed password, locks, and expired attempts —
with IP and browser — viewable per share (Access log) and exportable via the audit log. Brute-force
lock after 5 wrong passwords (30 min; correct password refused while locked); revoke any time (410
immediately); expired links blocked. Master-Admin/Manager only (share.manage).

Verified: full request lifecycle including refusal after accept; share created with capped expiry,
password shown once, prompt leaks nothing, wrong/correct password paths, download streams, access log
with IPs, revoke blocks instantly, lock after 5 failures, expired link blocked.

Migrations added: `105_document_kinds.sql`, `106_document_requests.sql`, `107_external_shares.sql`.

### v1.139.0 — Spanish depth: flash messages, emails, and per-tenant default language

Closes the last gaps where server-generated text stayed English in Spanish mode.

- **Flash messages** (the "Saved / Removed / Added" confirmations) now translate: they render through
  t() at display time and ~88 common ones were added to the Spanish dictionary. Login/invite pages
  translate too (they pass through the same output translator).
- **Emails composed in the recipient's / tenant's language.** A new `I18n::tl($locale, …)` builds text
  for a specific locale regardless of the request. **Invite emails** use the recipient's saved locale
  (falling back to the tenant default); **expiry-reminder emails** use the tenant's default language.
  Both fully translated (subject + body).
- **Per-tenant default language** (migration 104: `tenants.default_locale`). Set in Organization
  settings. New users see the app in it until they pick their own, session locale falls back to it,
  and reminder emails use it. `<html lang>` now reflects the active locale.

Verified: a flash rendered in Spanish; invite and reminder emails generated fully in Spanish; a new
user with no personal locale in an es-default tenant landed in a Spanish UI; the settings selector
persists the choice.

Migration added: `104_tenant_default_locale.sql`.

### v1.138.0 — Demo tenants: open directly, no impersonation approval

Demo organizations carry no real PHI, so the consent-gated support flow was pure friction there.

- On a **demo** tenant's console page the platform admin now sees **“Open demo (full access)”** — one
  click enters the org as its owner with a normal full session (not the PHI-blind, 4-hour support
  window). “Return to console” works as before.
- **Real tenants are unchanged**: entry still requires the tenant Master Admin's approval, the session
  stays PHI-blind (settings/training only), and a direct attempt without a grant is still refused —
  verified. The demo exemption keys strictly on `tenants.is_demo`.
- Demo entry is access-logged as `demo_impersonate`.

No migration.

### v1.137.0 — Activity-pulse email digest + five-organization demo dataset

**Activity digest email.** Owners/managers/admins can opt into a **daily or weekly** email summary of
their organization's activity (Profile → Activity digest email; migration 103 adds the preference).
The digest — total actions, people active, records touched, a "what happened" breakdown, and the most
active people, with a link to the pulse — is built by `ActivityDigestService` and sent through the
normal (per-tenant) mail pipeline via a new `cron/send_digests.php` (daily; weekly goes out Mondays).
Sent only on days with activity, so it never nags. A cron-safe `flx_public_base_safe()` builds links
outside a web request.

**Richer demo.** The healthcare demo now builds **five distinct organizations** so every dashboard
fills with realistic, varied data:
- **Coastal Credentialing Partners** — billing company, 9 providers across 2 client groups + 2
  locations, many types.
- **Dr. Ramirez Family Practice** — solo office where the owner/office-manager (Dr's spouse) plus a
  front-desk MA manage the doctor's and a nurse's credentials.
- **Bayamon Medical Group** — self-managed group practice, 6 providers.
- **Isla IPA** — 12 providers (physicians, NPs, nurses, PA, therapist, behavioral, pharmacist) across
  3 locations and 2 divisions, some affiliated to multiple sites.
- **Anesthesia Associates PR** — 5 anesthesiologists + 2 CRNAs in one department.

Each provider gets a realistic credential set (license, NPI, malpractice, DEA for prescribers, CAQH,
CME) spread across active/expiring/expired, with **verified document stand-ins**, exclusion
screenings, group/location affiliations, and permanent history events — so the calendar, compliance,
reports, audit and activity-pulse dashboards all show meaningful data. Totals: ~37 providers, ~198
credentials, ~67 verified documents. Build is idempotent and the admin "remove demo" now clears all
five (teardownAll). Verified: every dashboard renders populated with zero errors.

Migration added: `103_digest_pref.sql`.

### v1.136.0 — Tenant-wide activity dashboard (daily pulse)

An at-a-glance pulse of what's happening across all staff and groups, at **/audit/pulse** (Organization
→ Activity; owner/manager).

- **Today / 7 days / 30 days** switch. Top KPIs: total actions, people active, records touched.
- **What happened** — friendly counts grouped from the raw audit actions: providers added,
  credentials added / updated / removed, documents verified, screenings run, reminders sent, reports
  exported, team & role changes. Each card links into the filtered audit log for that record type and
  window.
- **Most active people** — per-person action counts with a bar and last-active time.
- **30-day trend** — a daily activity sparkline.
- **Latest activity** — the most recent entries, with a link to the full log.
- Also logged the quick provider-create action (`provider_created`) so "Providers added" is accurate.

Built on the audit log (never purged), so the pulse is correct across years. Bilingual. Verified
end-to-end: a burst of activity produced correct KPIs (11 actions, 1 person, 9 records), accurate
highlight counts (2 providers, 2 credentials), the people table, 30 trend bars, and the range switch.

No migration.

### v1.135.0 — Master audit log per tenant + per-credential/document history + wider coverage

Turns the audit trail into the complete, drill-down picture of what's happening at every level.

- **Master audit log** (/audit, owner/manager) rebuilt: server-side filters by **date range, person,
  record type, action, and free-text**, with pagination over the full history (no longer capped at
  200) and **CSV export** of the filtered set. This is the "every day, every minute, who did what"
  view, scopeable to a person or record.
- **Per-credential history** on the credential page: audit actions and permanent compliance events
  merged into one time-sorted panel — everything that happened to that credential and its documents,
  in one place.
- **Per-document history**: each document row expands to its own action history (uploaded, verified,
  replaced), with who and when.
- **Wider coverage**: added audit logging to ~10 previously-unlogged state changes (provider delete,
  team invite, role create/update/delete, share-link create, application delete, regulator
  requirement add, template save/delete, calendar event delete/done, CE entry delete, screening
  record, training-resource delete) — closing the main gaps so state changes are recorded.

Verified end-to-end: varied activity filtered by record type and action, exported to CSV, and the
per-credential/document history panels rendering with actor and timestamp.

No migration.

### v1.134.0 — Permanent compliance history: keep everything, across years, with who & when

Audit-first review confirmed deletes were already soft (nothing physically removed) and a per-provider
audit History panel existed, but three things were being lost for a long-term client record: credential
edits overwrote old values, there was no point-in-time record of *when* a requirement became met or a
credential expired, and document renewals had no visible chain. All fixed.

- **Append-only compliance ledger** (migration 102: `compliance_events`). Every meaningful moment is
  recorded permanently and never updated or deleted: credential added / renewed / edited / removed /
  expired, document uploaded / replaced / verified, each stamped with **who** (denormalized name, so
  history survives user deletion) and **when**, plus the effective date. Renewals are detected
  automatically (a later expiry than before) and distinguished from plain edits.
- **Before→after on every credential edit** is now preserved in the audit trail (previously only the
  new value was kept), and the change is written to the ledger — so "what was the expiry last year"
  is answerable.
- **Document renewal chain** (`supersedes_id`): uploading a new document on a credential links it to
  the one it replaces; the old file is **retained** (soft-deleted, never purged), so the multi-year
  document history is a visible timeline.
- **Automatic expiry events:** the daily sweep records a permanent `credential_expired` event the
  moment a credential lapses, even with no user action.
- **Provider page — Compliance timeline** panel (grouped by year) and **Export full history (CSV)**
  for audits. Nothing is capped away; the export is the complete record.

Verified across a simulated multi-year lifecycle: add → verify → renew (expiry 2025→2026 kept as
before→after) → document replaced (2024 doc retained, 2025 linked as superseding), all attributed to
the actor; and through the live app, creating then extending a credential recorded add + renewal.

Migration added: `102_history_ledger.sql`.

### v1.133.0 — Mobile document capture (Tier 1): photo upload, HEIC, downscale, EXIF strip

Documents can now be photographed from a phone. On the credential page the attach control gains a
**Take photo** button (opens the rear camera on mobile) alongside Choose file.

- **On-device processing** (assets/docscan.js, no external calls): iPhone **HEIC/HEIF is converted to
  JPEG**, large photos are **downscaled** to ≤2200px and re-encoded (~an 8 MB photo becomes
  ~0.5–0.9 MB — faster on cellular, lighter on the storage quota), with a light scan-enhance
  (grayscale + contrast) for photos of paper and a preview before attaching. Falls back gracefully to
  a normal file pick if the browser can't process an image.
- **Server privacy:** JPEG uploads are stripped of all metadata — including GPS — before storage, via
  a dependency-free JPEG segment rewriter (no GD/Imagick needed, since shared hosting usually lacks
  them). The image is preserved losslessly; malformed input is left untouched rather than risk
  corruption. HEIC is also accepted server-side as a fallback.
- Works for staff and, through the same endpoint, for providers uploading their own documents from the
  portal. Bilingual.

**Tier 2 — in-browser scanner** (assets/docscanner.js). A **Scan (multi-page)** button opens a modal
where you photograph or choose several page images, enhance each for legibility, remove/reorder, and
**Save as PDF** — the pages are combined into a single PDF entirely in the browser (a dependency-free
PDF writer embeds each JPEG as a page) and attached to the credential. No server changes; the produced
PDF is standard and was validated (multi-page, opens in readers, accepted by the upload endpoint).

Verified end-to-end: the camera UI renders, an uploaded JPEG with GPS is stored with the GPS removed
and the image intact; the client-generated multi-page PDF is valid and accepted.

No migration.

### v1.132.0 — Mobile-ready tables, bilingual Help center, security audit

- **Mobile:** a global responsive layer. Every table is auto-wrapped for horizontal scroll, and
  below 640px tables with column headers stack into cards with the column name shown as a label
  (injected from the header) — no per-page edits, no overflowing lists. Forms collapse to one column,
  inputs are 16px (prevents iOS zoom), toolbars wrap, KPIs go two-up. Print/layout tables (profile
  packet) opt out. Assets are version-busted so clients pick up the new CSS/JS immediately.
- **Help center** at /help (user menu → Help): 13 task-oriented guides covering every feature —
  getting started, providers & import, credentials & renewals, document verification, templates,
  groups & locations, calendar, notifications, reports & exports, screening, team/roles/privacy,
  email delivery, language, and the platform console — fully written in **English and Spanish**
  (follows the user's language), with search and a section index.
- **Security & code audit** of the whole codebase. Findings: parameterized SQL everywhere with an
  ORDER BY whitelist; Argon2id password hashing with rehash; HttpOnly + SameSite cookies; CSP with
  per-request nonces, X-Frame-Options DENY, HSTS, nosniff, Referrer-Policy; uploads content-sniffed
  against a MIME allowlist, 10 MB cap, stored under random names; all user text HTML-escaped; CSRF
  on every state-changing route; errors hidden in production. **One real issue fixed:** an open
  redirect (a form's `back` value was followed verbatim). `redirect()` is now hardened at the source
  so no call site can ever send a user off-site (absolute URLs, `//host`, header injection all
  blocked) — verified. Whole tree lints clean; all 22 main pages render with zero PHP warnings.

No migration.

### v1.131.1 — Fix: translations editor crashed before migration 101 was applied

v1.131.0's editor queried the new `context` column, but migrations don't run automatically — they're
applied from Console → System → "Update database". Any install that updated the app files before
running migrations hit `PDOException: Unknown column 'context'` on /admin/translations.

- The editor and the string collector now detect whether the `context` column exists and adapt:
  with it, strings group by screen; without it, everything shows in one "General" group and
  collection still works. No crash either way. Once "Update database" runs migration 101, grouping by
  screen appears automatically.

If you saw this error: upload v1.131.1, then run Console → System → "Update database" to apply
migration 101 and get the per-screen grouping.

No new migration.

### v1.131.0 — Translation editor: grouped & collapsible, save fixed, bulk Spanish

- **Fixed the blank screen on save.** The editor page is enormous, and the output translator was
  running on it (translating its own chrome and the English source column) — after a save the redirect
  landed on that heavy page and could fail to paint. The editor now opts out of output translation,
  and each screen-group is its own small form, so a save posts far fewer fields (also avoids PHP's
  max_input_vars truncation) and the redirect renders cleanly with a "saved" confirmation. Saving is
  verified to persist and return a full page.
- **Grouped by screen, collapsed by default** (migration 101 adds a `context` column; strings are
  tagged with the screen they were first seen on — Dashboard, Providers, Credentials, Calendar, Team,
  Organization & settings, Reports, etc.). Each group shows its untranslated count and a
  "complete" badge, and has its own Save button. Groups auto-open when you search or filter to
  untranslated-only.
- **Bulk Spanish added** (~140 entries in app/lang/es.php: all US states/territories, months,
  common credential and regulator names, and frequent UI phrases), clearing well over half of the
  previously-empty strings. Anything that reads wrong is editable in the grouped editor.

Migration added: `101_ui_translation_context.sql`.

### v1.130.0 — Fix: the SaaS operator's own account couldn't use the product

Reported: features appeared on tenant organizations but not on the top-level SaaS account. Cause: the
operator's own tenant was created with `vertical = 'platform'`, but ~29 feature gates check
`vertical === 'healthcare'` — so providers, groups, locations, templates, calendar and notifications
were all hidden from the operator's workspace, and it received none of the healthcare seed data.

- **New installs:** the platform tenant now adopts the edition vertical (`healthcare`) while keeping
  `org_shape = 'platform'`, so the operator gets a fully usable healthcare workspace *and* the admin
  console. Console access is driven by the `platform_admin` flag, not the vertical, so nothing about
  the console changes. Verified on a fresh install: the operator account seeds 14 templates, 10
  regulators, 7 calendar types and passes every healthcare gate, and `/admin` still works.
- **Existing installs:** migration 100 flips any `org_shape='platform'` tenant from `platform` to
  `healthcare` (and its seeded provider record). The admin **Update database** action now also
  backfills templates, regulators and calendar types for every healthcare tenant that's missing them,
  so the operator account fills in the first time it's run. Both proven in test.
- Group/department labels treat the platform account like a billing company ("Client groups").

Migration added: `100_platform_tenant_vertical.sql`. **After updating: sign in as the platform
administrator, open Console → System, and click "Update database"** — that applies migration 100 and
backfills the starter templates/regulators for your top-level account.

### v1.129.0 — Notification center

A full in-app notification center (migration 099: `notifications`) so staff learn what needs
attention without going to look for it.

- **Bell with unread badge** in the top bar; **/notifications** page with All / Unread filters,
  per-item Open (marks read and jumps to the provider/calendar) and Mark read, and Mark all read.
  Read state is per user.
- **Generated from what matters:** credentials expiring within 30 days (a second notice inside 7 days)
  and already expired; document verifications due for re-check within 30 days; overdue open calendar
  items; exclusion-screening flags. Plus event hooks: a provider **assigned to you**, and a credential
  **import finished**.
- **Need-to-know enforced at generation:** users in "assigned only" mode are notified solely about
  their assigned providers; owners and all-access users about everyone. Verified: a restricted user
  received the assignment notice and their provider's expiry, and nothing about an unassigned
  provider.
- **Idempotent and cheap:** each fact notifies once per user (dedupe key), the sweep is throttled to
  once per 5 minutes per tenant and runs opportunistically on dashboard / notifications loads and
  from the reminder cron, so notifications exist even if nobody opens the app.
- Provider-portal users are excluded (no bell). Bilingual.

Migration added: `099_notifications.sql`.

### v1.128.0 — Provider history, tenant data export, readiness report export

Three trust-and-audit features from the improvement list.

- **Provider history** (Manager+ / audit.view): a History panel on every provider page showing
  everything that happened to that provider's file — actions on the provider, on any of their
  credentials, and on documents attached to those credentials — with who did it and when. To make
  the history complete, **credential create / update / delete are now audit-logged** (they weren't).
- **Download all data (ZIP)** on Organization settings (org.manage): providers, credentials, a
  document index (name, type, size, SHA-256, verification), groups, locations, group and location
  memberships, and team (no passwords) as UTF-8 CSVs the organization owns. Built with a small
  dependency-free ZIP writer because shared cPanel hosts commonly lack ZipArchive; validated with
  `unzip -t`. Audit- and access-logged.
- **Export readiness (CSV)** on Compliance reports (audit.view): organization total, then readiness
  by client group / department and by location (member count, required items, on file, %), then one
  row per provider — the report a billing company hands to a client or keeps for an audit.

No migration.

### v1.127.0 — Provider profile packet: discoverable, with exclusion screening

An audit-first pass on three suggested features found all three were already built: **document
primary-source verification** (verified-at/by, method, note, re-verification date, verify/clear
actions), the **onboarding checklist** on the dashboard (team → locations → groups → import providers
→ import credentials → connect email → review calendar, with dismiss), and the **provider profile
packet**. What was actually missing was smaller and fixed here:

- **The packet was unreachable** — a complete print-ready dossier existed at
  `/holders/{id}/packet` but nothing linked to it. A **Profile packet** button now sits on every
  provider page.
- **Exclusion screening added to the packet** — the "not excluded as of [date]" line payers look for
  first. Shows the latest result per source (OIG LEIE / SAM / state Medicaid) with date and
  reference, flagged results highlighted, and a note that screening is a review signal.
- Removed a duplicate packet route introduced during the audit.

Verified end-to-end: provider page shows the button; the packet renders identity + NPI, credential
with number and issuer, group and location, a primary-source-verified document, screening "No match",
and the print control — no errors.

No migration.

### v1.126.0 — Credential bulk import + full-UI Spanish with an in-app translation editor

**Credential bulk import (second half of onboarding).** `/credentials/import` with a downloadable CSV
template: one row per credential, provider matched by NPI or by first + last name (case-insensitive),
`credential_type` validated against the catalog (built-in + the tenant's custom types), dates
validated, and — when `expiry_date` is blank on a type that renews on a cycle — the expiry derived
from the issue date, so the calendar and reminders have a date immediately. Preview shows every row
with a clear reason for any error; good rows can be imported while bad ones are fixed. Credential
numbers are encrypted at rest like hand-entered ones; a jurisdiction is folded into the issuer
("FL — Board of Medicine"). Verified: 4 credentials imported by NPI and by name, expiry auto-derived,
3 bad rows explained, all 4 appeared as calendar deadlines.

**Full-UI Spanish + editable front-end text.** Previously only 22 of 83 views used t() (159 strings),
so switching to Spanish left most pages English. Rather than hand-wrap every string, the app now
translates the whole rendered page when the user's language isn't English: any text node or
placeholder/title/aria-label whose entire text exactly matches a dictionary entry is replaced (the
same rule t() uses, applied everywhere; script/style blocks untouched; dynamic data like names left
alone). The dictionary = `app/lang/es.php` (expanded with ~110 base UI terms: navigation, buttons,
statuses, calendar buckets, roles) overlaid by **editable rows in a new `ui_translations` table**
(migration 098).

- **Console → Translations**: every English string seen in Spanish mode that has no translation is
  collected automatically ("untranslated"); the editor lists them (untranslated first, searchable)
  with an inline Spanish field. Edits apply immediately for all users and override the file
  dictionary; blank falls back to English. So the workflow is: switch to Spanish, browse, then
  finish the wording in-app — the final front-end text is yours to edit.
- Collector ignores data-looking strings (Title Case name sequences, emails, URLs, codes) and
  already-translated values, so the list stays about UI text.
- Verified: pages render in Spanish without errors, 128 strings collected from a short browse, an
  editor save renders on every page that shows it.

Migration added: `098_ui_translations.sql`.

### v1.125.0 — Onboarding: profession templates, structured bulk import, console fixes

Makes onboarding easy whether a client adds five doctors or five hundred, and finishes the
insurance→healthcare cleanup in the operator console.

- **Professional types expanded from 5 to 14** (single source of truth in Vocab, so the provider
  picker, import validation and templates never drift): Physician, Nurse practitioner, Physician
  assistant, Nurse, Dentist, Pharmacist, Therapist (PT/OT/SLP), Behavioral health, Chiropractor,
  Technologist, Other licensed professional, plus Laboratory / Clinic / Medical group.
- **Pre-built requirement templates by profession** (migration 097; seeded into every healthcare
  tenant, existing and new): nine new bundles reflecting real credentialing sets — prescribers (NP,
  PA, Dentist) carry DEA; non-prescribers (Therapist, Behavioral health, Chiropractor, Pharmacist)
  don't; all carry state licensure, NPI, malpractice, CAQH where enrolled, CE/CME, identity and CV.
  Editable like any template.
- **Bulk import lands a roster with its structure.** The downloadable CSV template now has `group`
  (client group / department) and `location` (practice site) columns — matched by name
  (case-insensitive) or created on the fly, so one spreadsheet places hundreds of providers into
  their groups and sites. `department` is still accepted as a legacy alias. Sample rows show the new
  professional types. Invalid types are rejected with the full list of valid values.
- **Console onboarding fixed:** the Create-organization form was only in an orphaned view no route
  rendered, so the console had no way to create a client; it's now live on the Tenants page with
  edition-aware types only (no more "Insurance agency" / "FMO"), a safe healthcare default shape,
  and **console-created organizations now get the same starter templates, regulators and calendar
  types as signup-created ones** (previously they got none). Added a **Hospital / health system**
  organization type (groups label as Departments). "Producers" relabeled "Providers" in the console.
- The template builder itself was already healthcare-native ("Care setting", "Payer enrollments");
  no change needed there.

Verified: a mixed CSV created 5 providers across 4 new types, auto-created 2 groups + 2 locations
(merging a lowercase duplicate), and rejected an invalid type; creating a Hospital from the console
seeded 6 roles, 14 templates and 10 regulators with its Master Admin invited.

Migration added: `097_healthcare_templates_by_profession.sql`.

### v1.124.3 — Email delivery: provider picks show only for Own SMTP

The provider quick-pick buttons (Google / Microsoft 365 / cPanel) were always visible, even when
"Platform default" was selected. They now live inside the SMTP section so they appear only when
transport is **Own SMTP** — hidden for the platform default — and the whole section toggles with the
Transport dropdown. Initial state matches the saved transport, so it's correct before JavaScript runs.

No migration.

### v1.124.2 — Fix email settings blocked by Content-Security-Policy (and hardened)

The email delivery buttons and Save did nothing because the panel's JavaScript was an inline
`<script>` without the per-request CSP nonce, so the browser's Content-Security-Policy blocked it
("Executing inline script violates … script-src 'self' 'nonce-…'"). Every other view authorises
inline script with `nonce_attr()`; this panel (and three others) omitted it.

- **Added the CSP nonce** to the four views that were missing it: Email delivery, the credential form
  (renewal auto-suggest), the credentials report, and the registration page — so their scripts run.
- **Refactored Email delivery to not depend on JavaScript at all:** the SMTP fields now render visible
  by default, so From email, host, port, security, username and password are always fillable and
  Save always works even if scripts are blocked. JavaScript now only *enhances* — hiding the fields
  when "Platform default" is selected, and pre-filling host/port/security when a provider button is
  clicked. Server-side validation already ignores SMTP fields when the platform default is chosen.
- Verified end-to-end: script carries a nonce, all six fields present and reachable, provider presets
  work, and save → platform → back-to-SMTP (blank password keeps the stored one) all persist.

No migration.

### v1.124.1 — Email settings: provider choice now visible up front

Fixes the reported issue that selecting Google or Microsoft 365 showed no fields to edit.

- The Google / Microsoft 365 / cPanel provider buttons were nested inside the SMTP fields block, which
  starts hidden until the transport dropdown is switched — so the provider choice itself was invisible.
  The provider buttons now sit **above** the fields and are always visible ("Quick setup — pick your
  provider"); clicking one switches the transport to Own SMTP, reveals the fields, and pre-fills
  host/port/security.
- Field visibility now syncs on page load and degrades gracefully without JavaScript (a noscript
  fallback shows the fields), so the settings are always reachable.

No migration.

### v1.124.0 — Calendar at scale: Agenda default, filters, and density

Reworks the calendar for billing companies with hundreds of providers and thousands of items, keeping
it simple to read and act on.

- **Agenda is the new default view** — open items grouped into urgency buckets (Overdue / Due this
  week / Due this month / Later) with a count on each, so a large roster is scannable at a glance and
  you act on what's due first. Month and List remain one click away.
- **Filters across all views** — narrow by provider, client group / department, location, and event
  type. Filters persist as you switch views and as you page months, with one-click Clear.
- **Month grid density** — busy days show an accurate "+N more" (from a per-day COUNT, not the capped
  render) that drills into that day's list, instead of an unreadable stack.
- **Deadlines are now filterable by who owns them** — credential-expiry events now carry their
  provider, so group/location/provider filters apply to the auto-generated deadlines that make up
  most of a billing company's calendar (previously they had no owner and were unfilterable).
- Verified at scale: 120 providers / 360 deadline events sync in ~60 ms; agenda and filtered queries
  return in 1–2 ms; group and single-provider filters return correct subsets.

No migration (uses existing columns and the group/location membership tables).

### v1.123.0 — Calendar audit: auto-sync deadlines + full action verification

The calendar is the core of the app, so every action was tested end-to-end and the deadline flow
tightened.

- **Compliance deadlines now appear automatically.** Opening the calendar opportunistically syncs
  credential expiries into deadline events (idempotent, best-effort) — previously they only appeared
  after a manual "Sync" click, so a newly added or edited credential could be missing from the
  calendar. A credential created through the app now shows as a dated deadline the moment the calendar
  is opened, with priority scaled to urgency (≤30 days high, ≤90 normal).
- **Verified working end-to-end:** month view, list view, create/edit/delete manual events,
  drag-and-drop move (returns JSON), mark-done, compliance sync (idempotent — re-sync doesn't
  duplicate), event-type add/toggle/delete, and the ICS subscription feed (valid iCalendar for
  Google/Microsoft 365/Apple "add by URL").
- **Test-harness fix:** the dev server now routes through a small front-controller shim so
  dotted-extension URLs like `/ical/{token}.ics` are exercised exactly as Apache routes them in
  production (the production `.htaccess` already handled this correctly; the harness did not, which
  had masked accurate calendar-feed testing).

No migration.

### v1.122.0 — Credentials crash fix, renewal→calendar link, regulator jurisdiction picker

Field-test fixes plus the renewal-cycle wiring.

- **Fixed the Credentials page crash** ("Undefined array key 1"): a producer-guard condition read
  `$seg[1]` directly on the `/credentials` list URL, which has no second segment. Now null-safe; swept
  the router for any other unguarded segment access (none remained).
- **"Renews every" now drives the calendar and reminders.** When a credential is saved with an issue
  date but no expiry, and its type defines a renewal cycle, the expiry is auto-derived
  (issued + cycle) — so the credential produces a calendar deadline and expiry reminders without a
  hand-entered date. The credential form also live-suggests the expiry when you pick a type and issue
  date (editable; an explicit expiry is never overridden). Verified end-to-end: cycle → expiry →
  calendar deadline → queued reminders.
- **Regulator jurisdiction is now a dropdown** (National + all states/territories incl. PR), on both
  the add and edit forms, with server-side validation — consistent, reportable values instead of free
  text.
- **Installer hardening:** the administrator name now requires a first and last name and is validated
  *before* any tables are created, so a single-word name fails cleanly instead of leaving a built
  database with no user (the earlier "tables but no user" symptom).
- **Email delivery is easier to find:** added a direct link on the Organization settings page (the
  per-tenant Google / Microsoft 365 / SMTP panel already lived under Organization → Email delivery).

Compliance-logic audit (no code change): confirmed all 24 built-in credential types across 7
categories are trackable with 9 categories available for custom types; credential status derivation
(active / expiring within 90 days / expired / never-expires) and the graded engine score
(active = 1, expiring = ½, expired/missing = 0) verified.

No migration.

### v1.121.0 — Field-test fixes: insurance leftovers, role view 404, document-type editing

Three fixes from live testing of the healthcare edition.

- **"Lines of business" removed from the healthcare Organization area.** The settings hub hint still
  used the insurance term unconditionally; it now says (and links) "Operating jurisdictions" for
  healthcare. The jurisdictions page itself was already vertical-aware.
- **Built-in roles open read-only instead of "Role not found."** The roles list merges the shared
  built-in roles, but opening one hit the tenant-scoped lookup and 404'd. They now open as a locked,
  read-only view with an explanation (create a new role to customize), and their list button says
  View, not Edit.
- **Document types: structured inputs + editing.** "Renews every" is now a cycle picker (does not
  renew / 6 mo / annual / 2–10 years) and "Jurisdiction" a proper state/territory picker (National,
  PR, FL, all states) — consistent values instead of free text, so reports can group on them. Custom
  types now have an **Edit** action (name, category, renewal cycle, jurisdiction, required flag);
  the short code stays fixed so existing records keep pointing at it. Built-ins remain non-editable.

No migration.

### v1.120.1 — Installer fix: SaaS administrator could not be created

Fixes a first-install failure reported from a real cPanel deployment.

- **Bug:** the installer sends `org_shape=platform` for the SaaS operator tenant, but `registerTenant`
  rejected `'platform'` (a guard meant to stop *public* sign-up from self-registering a platform
  account). Tables were created, then "the admin account could not be made: Choose an organization
  type" — leaving zero users. Fixed with an explicit `allowPlatform` flag that only the installer
  passes; public sign-up remains unable to create a platform account.
- **Clarity:** the installer now states plainly that this first account is the *SaaS platform
  (operator)* type, and that client organizations (billing companies, medical groups, hospitals, solo
  practices) are created afterwards from the console with their own types — replacing a silent hidden
  field that looked like a missing selector.
- Verified by running the real `install.php` against an empty database: 62 tables, administrator and
  platform tenant created and active. Note: if a *required* requirement fails (e.g. `app/` not
  writable), the installer stops before creating any tables and reports it on the same screen — check
  that message first if a database comes up empty.

No migration.

### v1.120.0 — Per-tenant email delivery (Google / Microsoft 365 / own SMTP)

Email is no longer platform-only: each organization can send invites, reminders, and notifications
through ITS OWN mailbox, independent of the SaaS's mail — so tenant mail comes from the tenant's
domain and reputation.

- **Organization → Email delivery** (migration 096: `tenant_mail_settings`) — the Master Admin picks
  Platform default or **Own SMTP**, with one-click presets for **Google Workspace** (smtp.gmail.com:587
  + app password), **Microsoft 365** (smtp.office365.com:587, authenticated SMTP), and cPanel/own
  domain. One SMTP transport covers all providers, including transactional relays.
- **Security:** the SMTP password is encrypted at rest (tenant-scoped AAD, VARBINARY like other
  encrypted fields), write-only in the UI (blank keeps the stored one), and never returned to the
  browser. Saves and tests are audit-logged without secrets.
- **Test send + Verified stamp** so delivery is proven before it's relied on; last error surfaced.
- **Delivery resolution in the outbox flush:** each queued message uses its tenant's transport when
  configured — even when platform mail is disabled — otherwise the platform default when enabled;
  unconfigured tenants with platform mail off stay queued without burning attempts.
- Verified with live SMTP conversations against local test servers: two tenants routed to two
  different servers with their own From addresses while platform mail was off; fallback preserved.
- OAuth/Graph transports deliberately deferred; the `transport` column leaves room without schema
  change. Bilingual.

Migration added: `096_tenant_mail_settings.sql`.

### v1.119.0 — First-install release: verified installer + installation guide

Marks the first installation-ready build. No feature changes; this release confirms the install path
end-to-end and ships an installation guide (INSTALL_GUIDE.md) covering database setup and app
configuration for cPanel shared hosting.

Verified for this release: fresh full install applies all 94 migrations cleanly; the web installer
checks requirements, generates encryption keys, writes app/config.php, and locks itself after
completion; defense-in-depth .htaccess protects app/, database/, cron/, tools/, config, and raw SQL.

**Roadmap / planned next tasks (tracked, not yet built):**
- **Calendar at scale** — a clean way to view events when a billing company has hundreds of providers
  and thousands of events (grouping, filtering by group/location/provider/event-type, month roll-ups
  with counts, list/agenda density controls, and lazy loading rather than rendering every event).
- **Reports** — compliance reports (readiness/gaps by group, location, provider type, expiring
  windows) and provider profile reports generated from templates.
- **Readiness roll-ups by group/location** — unit-level compliance scores (follows the v1.118 filter).
- **Cross-tenant Provider ↔ multi-org** — on the approved consent-ledger model (no shared identity).
- Deepen Spanish coverage; localize server-side flash messages + emails; per-tenant default language.

### v1.118.0 — Filter the provider list by group and location

Makes the Groups (v1.117) and Locations (v1.116) structure operational: the provider list can now be
narrowed to a single client group / department or a single practice site — the everyday question
"show me everyone in Grupo Médico A" or "everyone at the San Juan clinic."

- Group and location dropdowns on the provider list (shown only when any exist); both filters compose
  (intersection), with a live count, active-filter labels, and a one-click clear.
- **Security-correct layering:** filtering runs AFTER the repository's need-to-know and support-mode
  rules, so a restricted user who filters by a group still sees only their assigned providers within
  it — verified by test. Filters are org-shape-labelled and bilingual.

No migration; route + view only.

### v1.117.0 — Provider groups (client groups / departments)

Adds the intermediate grouping layer inside a tenant, so a billing company can organize providers by
the medical groups it serves, and a hospital by department. Together with Locations (v1.116) this lets
all four target client shapes be modeled cleanly. Stays within one tenant — not the cross-tenant
multi-org problem.

- **Groups** (migration 095: `provider_groups`, `holder_groups`) — a tenant-scoped list managed at
  **/groups** (gated on org.manage), labelled by organization type: **Client group** for billing
  companies, **Department** for hospitals, **Group** otherwise. Each group page lists its members.
- **Many-to-many membership** — a physician can cover several groups, a nurse can work for several;
  one membership may be marked **primary**, with an optional role-in-group. Managed from a
  label-aware panel on each provider page. Audit-logged (join / leave / primary).
- The nav link is hidden for solo practices and labelled per org type. Tenant isolation verified; all
  new UI bilingual.

Migration added: `095_provider_groups.sql`.

### v1.116.0 — Practice locations (in-tenant multi-site foundation)

First slice of the multi-organization work: locations *within* a tenant. A billing company, group,
lab, or clinic that manages providers across several physical sites can now model those sites and
affiliate providers with them. Purely additive — existing providers simply have no affiliations until
one is added, and nothing about current behavior changes.

- **Locations** (migration 094: `locations`, `holder_locations`) — a tenant-scoped list of practice
  sites (name, optional Type-2 NPI, address, phone, status), managed at **/locations** (gated on
  org.manage). Each location page lists the providers affiliated with it.
- **Provider ↔ location affiliation** — a "Practice locations" panel on each provider page affiliates
  them with one or more sites, with an optional role-at-site and an exclusive **primary** flag.
  Affiliations are tenant-scoped and audit-logged (affiliate / unaffiliate / primary).
- Tenant isolation verified: one organization's locations are never visible to another. All new UI is
  bilingual.

**Deliberately deferred (needs scoping): cross-tenant Provider ↔ multi-org.** Linking the *same
real-world provider* across *different tenants* is a separate, larger problem with irreversible
privacy modeling decisions (identity dedup vs. fully independent records linked only through the
consent ledger). That is not built here and should be scoped before implementation.

Migration added: `094_locations.sql`.

### v1.115.0 — “Master Admin” terminology + role seeding for every tenant

- **The tenant's top role now displays as “Master Admin”** everywhere (team page, console user
  directory, new-organization form, role labels), since the “owner” of an account is normally the
  organization itself. The underlying slug stays `tenant_owner` — no data migration, and it remains
  all-capabilities. Spanish: “Administrador Maestro”.
- **Every tenant is now seeded with its role set at creation.** Console-created organizations
  (createOrganization) previously got no local role rows and leaned entirely on the built-in
  fallback; they now seed the full set like signup-created tenants do, so a new org's Master Admin can
  manage the team immediately. The standard signup/installer path already seeded roles, so the
  platform/SaaS tenant and its admins were always covered — SaaS-side users hold the same all-
  capability top role, shown as Master Admin.

No migration; labels + provisioning only.

### v1.114.0 — Console user directory + add-user-as-Viewer (Slice 3)

Completes the superadmin role/user spec: see every user across all tenants, and add a user to any
organization without ever assigning their working role.

- **All users by organization** on /admin/users — the existing cross-tenant user search now also lists
  every user grouped by tenant (name, email, role, status), with a per-org open link. Search still
  narrows across name, email, and organization.
- **Add a user to any tenant as Viewer.** The superadmin picks an organization, enters name + email,
  and the person is created as **Viewer** (status invited) and emailed an invite. The tenant's Master
  Admin then assigns the real role — so role assignment stays strictly a tenant decision. Duplicate
  emails are refused; the action is logged to both the tenant audit trail and the platform log.

No migration; service + console view only.

### v1.113.0 — Master roles locked to a least-privilege baseline (role safety)

Hardens the global role model from v1.112 per the safety principle that a role every tenant relies on
must be stable and predictable.

- **The six built-in master roles are now immutable** — their capabilities can't be edited or deleted
  by anyone, including the superadmin, so no single change can alter access across all tenants at once.
  The console shows them as “Locked”.
- **Superadmin can still create NEW global roles** (applied to all tenants) and edit/delete those —
  additive changes are safe because they never silently change an existing role's meaning. Tenants
  continue to create their own roles for anything custom.
- **Tightened the master ladder to least privilege** (migration 093): Manager becomes an operations
  role (loses user/role administration and billing visibility; keeps records incl. delete, sharing,
  audit view, NPI); Viewer becomes strictly read-only (loses audit view). Net lines: account/user/org
  administration is Admin+, deletion is Manager+, audit trail is Manager+.

Final master capability sets — Master Admin: all; Admin: all except billing.manage; Manager:
records.write/delete, documents.manage/view, share.manage, audit.view, npi.lookup; Regular User:
records.write, documents.manage/view, npi.lookup; Viewer: documents.view; Provider: documents.view
(portal). Migration added: `093_tighten_master_role_caps.sql`.

### v1.112.0 — Global master roles (superadmin-owned, applied to all tenants)

Establishes a single master role set the platform owns, replacing the model where each tenant only had
private seeded copies. Part of the robust HIPAA-grade role model.

- **Global roles = rows with tenant_id NULL** (migration 092 makes roles/role_capabilities tenant_id
  nullable, adds is_global). Seeded master set: **Master Admin** (full app + role management + support
  approval), **Admin**, **Manager**, **Regular User**, **Viewer**, and the portal-only **Provider**.
- **Superadmin manages them at /admin/roles** — edit any master role's capabilities or create a brand
  new global role; changes apply to every tenant immediately. Built-in masters can't be deleted;
  Master Admin is always all-capabilities so no tenant can be locked out.
- **Resolution is tenant-local-first, then global.** `capabilitiesForSlug` and the role list return a
  tenant's own role when it has one of that slug, otherwise the global master. Tenants see globals
  **read-only** and still create their own roles for anything custom — they cannot edit or delete a
  global (enforced by tenant-scoped lookups; verified).
- Every global-role change is logged to the platform access log.

Migration added: `092_global_master_roles.sql`.

### v1.111.0 — Plan limits: document storage quota (joins users + providers caps)

Completes the three plan limits: max users and max providers already existed and are enforced at user
create/invite and provider create/CSV-import; this adds the third — a per-plan document storage quota.

- `plans.max_storage_mb` (blank = unlimited), editable on Manage plans.
- `credential_documents.size_bytes` recorded on every new upload; usage = sum of live documents
  (legacy rows without a size count as 0 until re-uploaded).
- Enforced at upload with a clear message showing used/limit; compliance-critical actions (screening,
  reminders, audit) are never blocked by quota — only new uploads are.
- `PlanService::usage()` now reports seats, providers, and storage together for plan pages.

Migration added: `091_plan_storage_quota.sql`.

### v1.110.0 — Consent-gated platform support access (impersonation lockdown)

Closes a real exposure: platform admins could previously open any tenant as its owner with full
powers. Support access is now authorization-first, time-boxed, PHI-blind, and dual-logged.

- **Approval required.** A platform admin requests access with a required reason; the tenant's Master
  Admin sees the request on their dashboard and approves or denies. No approval → no entry.
- **4-hour window, bound to one admin.** Approval opens exactly 4 hours for the specific requesting
  admin only (another platform admin cannot ride the grant). More time = a new approval. The Master
  Admin can **revoke mid-session** — the session is ejected on its very next click; expiry is enforced
  the same way.
- **Support mode is PHI-blind.** Approved sessions cover tenant settings and training only. Everything
  else is 403 at the route layer, and the provider / credential / document repositories additionally
  refuse all reads in support mode, so a missed route cannot leak. Support sessions cannot assign
  roles or touch clients, credentials, documents, or certifications — verified by test.
- **Dual, complete logging.** Request, approval (with the approved admin's identity, window start and
  expiration), denial, entry, exit, revoke, and forced ejection are written to BOTH the tenant's audit
  trail and the platform access log.

Migration added: `090_impersonation_consent.sql` (`impersonation_requests`). Tenant-facing approval
UI is bilingual.

### v1.109.0 — Privacy architecture: need-to-know access + provider consent ledger

Establishes the access-security model ahead of multi-organization work. Three layers, from strongest
guarantee outward:

**1. Tenant isolation stays absolute (unchanged, restated as policy).** Tenants never share data at
the database level; two organizations tracking the same real-world provider hold fully independent
encrypted records, and no cross-tenant linkage or correlation exists — not even by NPI.

**2. Need-to-know inside each tenant.** Providers are explicitly assigned to team members
(`holder_assignments`), and each user has a provider-access mode: **Sees all providers** or
**Assigned providers only** (set per user on the Team page; owners always see all). The 'assigned'
restriction is enforced inside `HolderRepository` — the same layer as tenant scoping — so it covers
the provider list, direct URLs (unassigned providers 404), and every surface that resolves providers,
and cannot be bypassed by a route. Background jobs (screening sweeps, reminders) run at owner level
and are unaffected. Provider pages show an **Assigned staff** panel; managers assign/unassign there.
All changes audit-logged (`provider_assign`, `provider_unassign`, `provider_access_mode`). Default
mode is 'all', so existing installs behave identically until an owner restricts someone.

**3. Cross-organization "security clearance" = provider consent.** When information must cross the
tenant boundary, the provider authorizes it. The new **Sharing clearance** panel records each
consent — granted to whom, what scope (summary vs full file), how captured (written / portal / verbal
documented), valid until when — revocable and audit-logged (`consent_record`, `consent_revoke`).
**Sharing the full credential file is hard-blocked without an active consent on record**; the created
share link is tied to the authorizing consent (`share_links.consent_id`), so every disclosure answers
"on whose authority?". Routine summary verification links remain friction-free (and attach to a
consent when one exists).

All new UI is translated (Spanish catalog extended). Migration added:
`089_provider_assignments_and_consents.sql` (holder_assignments, provider_consents,
users.provider_access, share_links.consent_id).



Refactors the i18n catalog from an in-code array to per-language data files, so translations can be
edited without touching code and new languages drop in cleanly. No behavior change for users; the
Spanish already shipped now loads from a file.

- **`app/lang/{locale}.php`** — each returns a plain array keyed by the English source string. The
  Spanish catalog moved to `app/lang/es.php` (English is the source, so `en.php` is an optional
  override file, normally empty). Catalogs are loaded once per request and cached.
- **Puerto Rico wording applied** where it differs from textbook Spanish — Training → **Adiestramiento**
  (not Capacitación), Payers → **Planes médicos** — with a header comment in `es.php` flagging the
  terms that commonly vary, for local review. Editing a value there takes effect immediately.
- **Adding a language** is now two steps: add it to `I18n::LOCALES`, drop in `app/lang/{code}.php`. The
  switcher and lookup pick it up automatically.
- **Interpolation** with named placeholders so translations can reorder words:
  `t('{n} providers', ['n' => $count])`. Existing `t('...')` calls are unchanged.

No schema change; the `t()` API is backward-compatible.



Extends Spanish coverage from navigation to the screens a Puerto Rico operator uses most, using the
`t()` mechanism from phase 1 (no architectural change).

- **Providers list** — headings, actions (Guided onboarding, Import CSV, Add credential), column
  headers, and status chips (expired / expiring) now localize.
- **Compliance dashboard** — the heading, all KPI labels (Compliance score, Requirements fully active,
  Active credentials, …), the "Providers needing attention" table and its columns (Role, Coverage,
  Gaps) now localize.
- **Exclusion screening admin** — page heading and the current-list labels (Vintage, Exclusion rows,
  Imported, Load / update the list) now localize.
- Catalog extended by ~40 strings. Untranslated strings still fall back to English, so the rollout
  stays incremental and safe.

No schema change; views + catalog only.



First phase of Spanish-first localization for the Puerto Rico market: the translation mechanism plus
the highest-traffic surface (navigation) in Spanish, switchable per user.

- **`I18n` + `t()`.** A keyed catalog translates English UI strings to the active locale, falling back
  to English when there's no entry — so nothing renders blank and untranslated strings simply stay in
  English while the catalog grows. Locale resolves from an explicit session choice → the user's saved
  preference → English.
- **Per-user language preference** (migration 088 adds `users.locale`), persisted when switched.
- **Language switcher** in the user menu — one click toggles English ⇄ Español and saves the choice.
- **Navigation fully translated** (Panel, Agenda, Calendario, Clientes, Proveedores, Credenciales,
  Solicitudes, Cumplimiento, Informes, …) plus common actions and status words seeded in the catalog.

Phase 1 deliberately covers the mechanism + nav; remaining screens localize incrementally by wrapping
their strings in `t()` and extending the catalog, with no further architectural change. (Pairs with
v1.105.0, whose PR Medicaid importer already accepts Spanish CSV headers.)

Migration added: `088_user_locale.sql`.



Adds state/territory Medicaid exclusion lists as a third screening source, starting with Puerto Rico —
important because PR (and many states) bar providers from Medicaid separately from the federal OIG LEIE
and SAM.gov lists.

- **`MedicaidExclusionService`** ingests a Medicaid exclusion CSV tagged by jurisdiction into
  platform-global `medicaid_exclusions` / `medicaid_imports` (migration 087), keyed by (jurisdiction,
  vintage). Jurisdiction-parameterized by design: PR works now; Florida, Texas, and others drop in with
  no new code.
- **Bilingual CSV headers.** The parser recognizes English *and* Spanish column names (apellido,
  nombre, entidad, motivo, fecha, …) — fitting for PR source files. Matches by exact NPI when present,
  else by normalized name (the same normalization LEIE/SAM use).
- **Now a three-source sweep.** The scheduled/bulk sweep and one-click provider screen check OIG LEIE +
  SAM.gov + every loaded Medicaid jurisdiction in one pass, recording one review signal per source,
  each stamped with its vintage (e.g. "PR 2026-08").
- **Admin.** The Exclusion screening page gained a Medicaid panel: pick a jurisdiction, paste the CSV;
  loaded jurisdictions and their vintages are listed.

Migration added: `087_medicaid_exclusions.sql`.



Adds SAM.gov (the government-wide debarment/suspension list, successor to EPLS) as a second screening
source alongside OIG LEIE, and makes all screening multi-source.

- **`SamService`** ingests a SAM.gov exclusions CSV into platform-global `sam_exclusions` /
  `sam_imports` (migration 086), tracking vintage. The parser is header-aware with flexible column
  aliases (Firm / Individual / Vessel / Special Entity Designation records). SAM records carry no NPI,
  so matching is name-based — always a review signal, confirmed at sam.gov.
- **Multi-source sweep.** The scheduled/bulk sweep and the one-click provider screen now check **every
  loaded list** (LEIE + SAM) in a single pass, recording one result per source, each stamped with that
  source's vintage. A provider can therefore be flagged by NPI on LEIE and by name on SAM in the same
  run. Per-provider-per-source dedup uses distinct vintage markers.
- **Admin.** The screening admin page (`/admin/leie`, now titled "Exclusion screening") gained a
  SAM.gov panel showing the loaded vintage and a paste-CSV import. The public extract/API needs a free
  SAM.gov API key; the paste path needs none.
- Provider-page button and owner-alert copy updated to cover both sources.

Migration added: `086_sam_exclusions.sql`.



Onboard many providers at once instead of one at a time — the difference between a demo and something
a billing company with hundreds of providers can actually adopt.

- **Two-phase import.** Upload a `.csv` (or paste rows) → **preview** shows exactly what will happen
  per row (create / skip / error, with the reason) → **commit** inserts only the valid rows. Nothing
  is created from a file without the preview.
- **Recognized columns** (header row, case-insensitive, extras ignored): `holder_type, provider_type,
  first_name, last_name, org_name, npi, email, department, role_title`. A downloadable template is
  provided at `/holders/import/template`.
- **De-duplication.** Rows whose NPI already belongs to a provider in the tenant are skipped, as are
  duplicate NPIs within the same file. (NPI is the reliable key; rows without one are matched by name
  only within a single file.)
- **Validation** per row: individuals need a name, organizations need an org_name, NPI must be 10
  digits, email must be valid, provider_type must be a known value. Invalid rows are flagged and never
  imported.
- **Respects plan limits** — the import stops at the tenant's holder cap and says so. Upload capped at
  2 MB. Audit-logged (`provider_import`). Reachable from an "Import CSV" button on the Providers page.

New service `ProviderImportService`; no schema change.



Reports was scoring every provider 100% / low-risk. The risk engine evaluated against the legacy
`requirement_defs` set, which is empty in the healthcare edition — so it always found "nothing wrong."
A report that says everyone is perfect is worse than none; this repoints it at the live compliance
engine.

- **`RiskScoreService` now derives from `flx_compliance_data`** — the same engine that powers the
  Compliance view and readiness panels. Each provider's score is the engine's graded score (active
  credential = 1, expiring = ½, expired/missing = 0, over the provider's applicable requirements), and
  the gaps are the concrete non-active rows. Reports now agrees with the rest of the app.
- **Honest "not assessed" state.** A provider with no applicable requirements yet (no regulators
  assigned, no catalog rows) shows "—" and a *Not assessed* band instead of a misleading 100. The
  rollup reports how many are not-yet-assessable and excludes them from the average.
- **Bands unchanged** (≥85 low · 65–84 medium · <65 high; any expired row floors at medium), so the
  scale is stable — only the inputs are now real.
- Updated the Reports index and the printable audit package to the compliance status vocabulary
  (active / expiring / expired / missing) and healthcare wording (providers, not agents).

No schema change. The old `RequirementRepository::evaluateHolder` path is no longer used by Reports.



Turns the LEIE feature from a manual per-provider click into a recurring compliance control, which is
how exclusion screening is meant to work — a monthly obligation, not a one-time check.

- **Tenant-wide sweep.** `LeieService::sweepTenant()` screens every active provider in a tenant against
  the current LEIE list; `sweepAll()` does it across every active, non-demo tenant. Each provider is
  screened **once per list vintage** (deduped through the `entity_reminders` ledger), so running it
  repeatedly is safe: work happens only when a new monthly list has been imported.
- **Owner alerts.** When a sweep flags potential matches, the tenant owner gets one summary email —
  review signals only, with the standing instruction to confirm identity at oig.hhs.gov before acting.
- **Cron entry** `cron/screen_leie.php` for continuous screening from cPanel cron (daily is fine; it
  no-ops until a new list is loaded). Optional — providers can still be screened on demand.
- **Admin control.** `/admin/leie` gains a "Screen all providers against this list now" button and shows
  the cron command for scheduling.

Scheduled results are recorded exactly like on-demand ones — `potential_match` or `clear`, stamped with
the list vintage, attributed to "Scheduled sweep". No schema change (reuses `entity_reminders`).



Adds the first external-data screening pipeline. Until now the exclusion module only stored
hand-keyed results; providers can now be screened automatically against the OIG List of Excluded
Individuals/Entities.

- **LEIE dataset ingest.** The OIG publishes the exclusion list as a monthly downloadable CSV (there
  is no OIG API), so `LeieService` ingests that file into a platform-global `leie_exclusions` table,
  tracking each import's vintage (file month) in `leie_imports`. Re-importing a month replaces it.
  Parses the documented 18-column layout; names are normalized for matching; blank/zero NPIs in the
  file are handled (those rows match on name only).
- **Admin management** at `/admin/leie`: shows the current list's vintage and row count, and loads a
  new list either by downloading `UPDATED.csv` directly from OIG (needs outbound HTTPS on the server)
  or by pasting the CSV.
- **Automated provider screen.** A one-click "Screen against OIG LEIE now" on the provider page matches
  the provider by exact NPI and normalized name and records the outcome into the existing exclusion-
  screening module. Results are **review signals**, never verdicts: a hit is recorded as
  `potential_match` (never `excluded`), stamped with the list vintage, with a note directing staff to
  confirm identity at oig.hhs.gov before acting. A clean scan records `clear` against the vintage.
- Screening runs are audit-logged (`leie_screen`, `leie_import`).

Design note: the feature is inert until an admin loads a list — screening before any import reports
that the list is unavailable rather than guessing. On real hosting the "Download from OIG" button
populates it in one click.

Migration added: `085_leie_exclusions.sql`.



Finishes the insurance sweep: removes the remaining insurance-only features, dead credential-catalog
vocabulary, and inert seed data left after the table prune. Every removal was audited against live
code first; anything a live feature depended on was kept.

- **Beneficiary interaction register removed** (CMS Medicare sales-conduct feature: Scope of Appointment,
  TPMO disclaimer, Permission to Contact, recording/enrolment clocks). Removed the route, `InteractionRepository`,
  `InteractionRules`, both views, and the producer-allowlist entry. It had no nav link. Its backing table
  `beneficiary_interactions` is dropped (migration 084) now that nothing references it.
- **Dead `/requirements` sub-pieces removed.** The orphaned `requirements/index.php` and `requirements/show.php`
  views (rendered by no route), the unused `$requirements` repository instance, the `use` import, and the
  dead `requirements` feature-gate entry. `RequirementRepository` itself is **kept** — `RiskScoreService`
  (the live Reports feature) uses it via `evaluateHolder()`.
- **Orphaned `verify/roster.php`** deleted (no route rendered it; share-link verification uses `verify/show`).
- **Insurance credential-catalog vocabulary emptied** in `CatalogService`: the insurance type `SYNONYMS`
  (carrier_certification, eo_coverage, …), `SATISFIES` (nabip→ahip), and the `CARRIER_SCOPED` code list.
  The coverage engine and per-regulator scoping mechanism are unchanged; only the insurance values are gone.
- **Inert insurance `requirement_defs` purged** (migration 083): 15 global `vertical='insurance'` rows that
  never load in the healthcare edition.
- **Insurance training categories** replaced with healthcare ones; new-entry default fixed from `carrier`.
- **Labels corrected** across ~10 views (AHIP/E&O/carrier examples → DEA/malpractice/NPI; "MA requirements"
  → "Requirements catalog"; reports "Plan year" → "Reporting year"), and stale SOA/interaction comments removed.

**Bonus fix:** the credential form's payer/regulator selector was locked behind an insurance-only branch,
so healthcare credentials had no way to attach a payer/regulator. It's now exposed for healthcare (pointing
to /regulators; the insurance plan-year field dropped), verified end-to-end.

Migrations added: `083_prune_insurance_requirement_defs.sql`, `084_prune_beneficiary_interactions.sql`.



Removes the last insurance-era vestige tables, columns, and dead code left over from the split.
Every table below was audited against the entire live codebase before removal; anything with a
live reference was either kept or had its references removed first.

- **13 tables dropped** (migration 082): `bookings`, `booking_types` (retired with scheduling in
  v1.96), `soa_forms`, `carrier_interactions`, `carrier_cert_schedule`, `cms_sync_log`,
  `cms_sync_source`, `platform_carriers`, `platform_carrier_aliases`, `platform_carrier_plans`,
  `network_invites`, `policies`, and `commissions`. Drops are idempotent (`IF EXISTS`) so the
  migration is safe on installs where some tables were already absent.
- **`plans.max_policies` column dropped.** The per-plan policy cap no longer exists.
- **Billing / admin rework.** The book-of-business concept is gone from the surfaces that showed
  it: the "Policies" usage row on billing, the "Max policies" field in the plan editor, and the
  "Policies" KPI + per-tenant column on the platform dashboard. Plan create/update no longer read
  or write `max_policies`.
- **Dead code removed.** Repository classes `PolicyRepository`, `CommissionRepository`,
  `SoaRepository`, `CarrierInteractionRepository` (none instantiated in any route), the
  `policyInput()` helper, their imports, and the `policies` / `commissions` / `rts` feature-gate
  entries. Also swept unused `nav.policies` / `nav.carriers` / `nav.rts` icon-map entries.
- **Migration 041** (`041_seed_cert_schedule.sql`, an insurance-only seed) deleted from the tree,
  manifest, and install order.

Kept deliberately (audited as live): `beneficiary_interactions`, `nipr_interest`,
`calendar_events`, `requirement_templates`, `requirement_template_items`. Registration seeding is
unchanged — a new tenant still receives its healthcare templates, regulators, and calendar event
types. No functional loss for healthcare tenants.

Migration added: `082_prune_insurance_vestiges.sql`. Migration removed: `041`.



Surfaces the newer compliance modules where staff already look, and makes them proactive
rather than passive.

- **Owner dashboard signals.** The main dashboard gains three KPI tiles beyond credential
  counts: **Applications in flight** (open enrollment/credentialing applications → /applications),
  **Screening flags** (providers with a potential-match or excluded screening result, shown red
  when > 0 → /holders), and **Avg readiness · N low** (organisation-wide average of the
  compliance-graded readiness score, with a count of providers below 70% → /compliance). The
  roll-up is one compliance-engine pass, so it adds no meaningful load. Tiles colour by threshold
  (readiness ok/warn/danger at 90/70) and show "—" when not yet applicable.
- **Reminders extended to the new entities.** `ReminderService` previously scanned only credential
  expiry. It now also reminds on: **application due dates** (open applications approaching `due_on`,
  at the existing 60/30/14/7-day thresholds, to the provider or owner), **exclusion-screening
  cadence** (active providers never screened or last screened over 12 months ago → owner), and
  **attestation cadence** (never attested or over 12 months ago → provider or owner). A generic
  `entity_reminders` ledger (migration 081) dedups each reminder so it never fires twice for the
  same trigger, mirroring how `credential_reminders` works for expiry. Runs on the same
  opportunistic page-load path — no new cron required.
- **Wording.** Removed a residual insurance phrase ("ready to sell") from reminder copy.

Migration added: `081_entity_reminders.sql`. No breaking changes.



Reshapes the calendar into a pure event system that syncs out to any calendar app.

- **Booking / scheduling retired.** Removed the `/scheduling` admin route, the public `/book` and
  `/manage` pages, `BookingService`, the booking reminder cron, the plan feature, and all
  booking hooks in the Calendar. Meetings are handled as calendar events instead. (The now-orphan
  `bookings` table is left for the structural-pruning pass.)
- **Configurable event types (migration 079).** Replaces the old hardcoded, insurance-flavoured
  kind list (which still carried SOA/Carrier) with a tenant-editable set: healthcare defaults
  (Meeting, Appointment, Call, Renewal, Site visit, Note) plus the system Compliance-deadline type,
  and an Event Types manager to add your own with a color. Also dropped the old `kind` CHECK
  constraint and removed the insurance CMS-season auto-events from calendar sync.
- **External calendar sync — one-way ICS feed (migration 080).** Each user can generate a private,
  read-only iCalendar feed URL (Calendar → Subscribe / sync) and subscribe to it from Google
  Calendar, Microsoft 365, Apple Calendar, or any app that adds calendars by URL. The feed carries
  manual events and auto compliance deadlines and refreshes automatically; the link is rotatable.
  Two-way (write-back) sync remains a possible future phase.

### v1.95.0 — Exclusion / sanctions screening

Adds provider screening against exclusion lists (strategy doc §5, §6, §17 Phase 3) — the check a
billing company must clear before submitting claims.

- **Migration 078** adds `exclusion_screenings` (provider, source, screened-on, result, reference,
  who) with a `ScreeningRepository`. Sources: OIG LEIE, SAM.gov, state Medicaid, PR Medicaid, other.
  Results: Clear / Potential match / Excluded.
- **Provider page** gains a Screening block: latest result as a status chip (an "Excluded" result
  is flagged in red as a hard stop), the full screening history, and a record form. Official lists
  stay the source of truth; this is the check-and-evidence layer.
- **Readiness** gains a seventh dimension — Exclusion screening — where Clear = 100, Potential
  match = 40, Excluded = 0, and never-screened shows N/A with a prompt to screen.

### v1.94.0 — Readiness sub-scores

Replaces the single compliance percentage on a provider with the six named readiness lenses from
the product strategy (§7), each a management-oriented view of data the app already holds — never a
legal-compliance claim.

- **New `ReadinessService`** computes, per provider: **Credential readiness** (graded status over
  required credentials), **Payer readiness** (graded over regulator/payer-scoped requirements),
  **Enrollment readiness** (approved share of live applications), **Documentation** (held
  credentials with evidence attached), **Renewal risk** (readiness against near-term expiries), and
  **Audit evidence** (verified credentials + attestation on file). A dimension shows N/A rather than
  a misleading 0 when it doesn't apply (e.g. no applications yet).
- **Provider overview** gains a Readiness panel: an overall score, a one-line plain-language summary
  in the strategy doc's style ("0 of 1 approved, 1 still open; 2 of 7 met, 5 need attention"), and a
  bar for each of the six dimensions with its explanation.
- Built directly on the existing compliance engine plus the verification, attestation, and
  application data from the previous slices — no schema changes.

### v1.93.0 — Application / Enrollment tracking

The strategy doc's Enrollment Center and Credentialing Workspace: tracking a submission *in flight*
— a PECOS/Medicare, PR Medicaid (PRMP), or payer credentialing/recredentialing application moving
through its status lifecycle — not just the credential once held.

- **Migration 077** adds `applications` (provider, regulator/payer, kind, title, reference, status,
  submitted/decision/due dates, notes) and `application_status_history` (append-only trail), with
  an `ApplicationRepository`.
- **New Applications workspace** (nav → Applications): a portfolio list of every submission ordered
  by how much attention it needs (under review / submitted first), create/edit, and a per-application
  page to advance status through Not started → In progress → Submitted → Under review → Approved /
  Denied / Withdrawn. Advancing to Submitted stamps the submit date; a decision stamps the decision
  date; every change appends to the status history with who and when.
- **Applications tie into the Regulator model** — each is optionally scoped to a payer/agency
  (e.g. "Dr. Rivera's Medicare enrollment", "Dr. Chen's Aetna credentialing").
- **Provider page** gains an Applications & enrollments summary with a one-click New button.
- Official portals remain the system of record; this is the workflow-and-evidence layer around them,
  exactly as the strategy blueprint recommends.

### v1.92.0 — Verification & Attestation records

The two records that turn an expiry tracker into a credentialing system, from the product strategy's
Verification Center and attestation workflow.

- **Migration 076** adds `credential_verifications` (primary-source-verification trail: method,
  source, verified-on, verifier, result, notes) and `holder_attestations` (statement, method,
  attester, timestamp, IP), with `VerificationRepository` and `AttestationRepository`.
- **Primary-source verification** replaces the old one-click "verified" stamp: on a credential you
  now record *how* it was verified (primary source, copy on file, database check, provider
  attestation), against which source, on what date, and the result (verified / discrepancy / unable
  / expired). History accumulates and the latest "verified" still updates the credential's
  last-verified date. Producers can't self-verify — it's a staff act.
- **Attestations**: a provider can attest from their portal (one-click "I attest") that their
  information is accurate and current, and staff can record an attestation on a provider's behalf
  with the method noted. History is shown on the provider page.
- **Audit-readiness package** now includes a verification trail and the provider's latest
  attestation, alongside the existing requirement and credential sections. Fixed the package's
  remaining insurance labels (title and the requirement table's regulator column).

Completes the multi-authority credentialing model: define regulators → attach requirements →
assign to providers → record credentials → **verify them and capture attestations** → export an
audit package. (App remains English; a Spanish layer is planned as its own phase.)

### v1.91.0 — assign regulators to providers

Regulators can now be assigned to individual providers, and their requirements flow automatically
into that provider's compliance view — completing the multi-authority model (slices 1–3).

- **Migration 075** adds `holder_regulators` (provider ↔ regulator), with a `HolderRegulatorRepository`.
- **Provider page → Compliance requirements** gains a "Regulators this provider answers to" block:
  tick each payer, agency, framework, accreditor or board (grouped by category, jurisdiction shown).
- **Compliance engine** expands each assigned regulator into its requirements for that provider:
  credential requirements resolve against the catalog and a held credential satisfies them
  (an active NPI covers "CMS requires NPI" regardless of scope); document requirements (HIPAA
  training, OSHA certificates…) surface as their own line items. Everything displays grouped by
  regulator in the Compliance → Providers view — e.g. a provider now shows a "Centers for Medicare
  & Medicaid Services requirements" group and an "OSHA requirements" group.
- Fixed a rename leftover where per-provider compliance grouped on the old (now-empty) carrier key
  instead of the regulator, and the last stale `/carriers` links on the provider page.

### v1.90.0 — citation-checked regulator seed (federal + Puerto Rico)

Every healthcare tenant — existing (migration 074) and new signups (registration seeding) — now
starts with a verified set of 10 regulators and their 19 requirements, each carrying its legal
citation:

- **Government:** CMS (NPI — 45 CFR 162; Medicare PTAN revalidation — 42 CFR 424.515; CLIA —
  42 CFR 493), DEA (triennial registration — 21 CFR 1301.13), FDA (establishment registration —
  21 CFR 607/807), CDC, and the PR Department of Health (facility licensing — 24 LPRA; PR lab
  personnel — 20 LPRA ch. 111).
- **Regulations:** HIPAA (workforce training and BAAs — 45 CFR 164) and OSHA (bloodborne pathogens
  annual training and exposure control plan — 29 CFR 1910.1030).
- **Accreditation:** The Joint Commission (accreditation cycle, primary-source verification files).
- **State boards:** Florida Board of Medicine (s. 458.319 Fla. Stat.; CME per 64B8-13.005 F.A.C.)
  and the PR Board of Medical Examiners (license, triennial CME, good-conduct and ASSMCA
  certificates — 20 LPRA § 131 et seq.).

Credential requirements link to the existing verified catalog (npi, dea, clia, medicare_ptan,
state and PR license rows); document requirements (HIPAA training, BAAs, OSHA certificates…) are
free-named. All rows are tenant-editable starters, idempotent on re-run. Ruleset version bumped to
HC2026.1 and the last insurance-era ruleset notes replaced.

### v1.89.0 — Regulators: the multi-authority compliance model

Providers and facilities answer to many authorities, not one. This release generalises the payer
("carrier") concept into a first-class **Regulator** — any entity that imposes credential or
document obligations:

- **Five categories:** Payer / Insurance company · Government agency (CMS, FDA, CDC) ·
  Regulation / Framework (HIPAA, OSHA) · Accreditation body (The Joint Commission) · State board —
  each with a jurisdiction (national, a state, or Puerto Rico).
- **Migration 073** renames `carriers` → `regulators` (adding `category` + `jurisdiction`) and
  `carrier_requirements` → `regulator_requirements`, which now holds BOTH credential requirements
  (linked to the catalog) and free-named document requirements (HIPAA attestation, OSHA training
  certificate, COI…). `carrier_id` is renamed on the kept healthcare tables; requirement tokens
  keep their existing grammar so stored assignments are untouched.
- **New Regulators screen** (nav → Regulators): category-grouped list, create/edit with category
  and jurisdiction, and per-regulator requirement management for credentials and documents.
- **Templates link to a regulator:** a template can now belong to CMS, HIPAA, a payer, or a board;
  the templates list shows the regulator on each template. Compliance, provider portal, share
  links, credentials, and training all speak "regulator"/"payer" instead of insurance "carrier".
- Removed dead insurance leftovers this exposed: the production/sales analytics report and the
  unused roster share-link builders.

Next: the citation-checked seed catalog of federal + Puerto Rico regulators, and per-provider
regulator assignment.

### v1.88.0 — insurance code removed (healthcare-only codebase)

Completes the split begun at v1.87.1: the inert insurance/Medicare code is now removed, leaving a
clean healthcare-only tree. No change to healthcare behaviour — everything pruned was already
unreachable for a healthcare tenant.

- **Fixed:** the Entity compliance page (`/entity`) 500'd on a fresh tenant because the auto-created
  organization holder didn't set the required `vertical` column. It now does. Also fixed a latent
  bug where a `billing_company` never got its org-level holder at registration (the condition still
  keyed on the removed insurance `agency` shape).
- **Removed routes/services:** Scope of Appointment, Ready-to-Sell, Carriers, AEP readiness, Cert
  schedule, Beneficiary files, Book of business, Commissions, Network (FMO), the plan-year
  Requirements engine, the public SOA signing page, and the roster-verification branch that borrowed
  the RTS matrix. Eleven insurance services and the `cms_sync` cron are deleted; the admin console's
  Carriers, Carrier plans, and CMS-sync sections are gone.
- **Removed config/content:** insurance organization shapes (agency/fmo/solo_agent), insurance
  capabilities (`soa.write`, `bob.write`), insurance plan features (book/rts/network/requirements),
  and the insurance columns in `Vocab`. New migration **072_prune_insurance_seed.sql** removes the
  42 insurance global catalog rows and the insurance training articles + sources. A fresh install now
  carries only healthcare content (23 catalog rows, 9 training articles).
- Nav, dashboard, admin tabs, and the platform console are healthcare-only.
- **Deliberately left in place (inert):** the `carriers`/`carrier_requirements` tables and their use
  inside the shared compliance engine — removing that is a compliance-engine refactor, not a clean
  deletion, and it never renders for a healthcare tenant. Structural pruning of the remaining
  insurance-only tables is a later, separate step.

259 files, 72 migrations.

### v1.87.2 — bundled product docs

No code change. Adds two companion documents to the package so a new development chat can
self-orient: **KICKOFF.md** (how we work — paste as the first message of a new chat) and
**PRODUCT.md** (detailed purpose, domain model, and feature tour of this edition). SPLIT.md and
INSTALL.md remain as before.

### v1.87.1 — Healthcare Edition (single-vertical split)

No migration required. This app is now the **Healthcare Edition** of FlxCoreHC — see SPLIT.md.
Registration and the installer only offer healthcare organization shapes; insurance tenants
cannot be created here, and the insurance demo builder is removed from the admin panel. The
sibling edition is a separate app developed independently from this point on.

### v1.87.0 — healthcare tenants stop seeing Medicare-agent menus & training

Adds migration **071_training_vertical.sql** (safe to re-run).

A healthcare tenant was still seeing insurance/Medicare-agent items in the sidebar, and the Training
Center was Medicare-agent material. Both are now vertical-specific.

- **Navigation.** For healthcare tenants, these Medicare-agent-only items are hidden: AEP readiness,
  Cert schedule, Beneficiary records, Beneficiary files, Scope of Appointment, Renewal tasks (the MA
  cert-cycle model), plus Ready-to-Sell and Carriers (already insurance-gated). Compliance, Entity
  compliance, Templates, Credentials, Reports, Calendar, and Training remain — they apply to both.
  Producers on a healthcare tenant no longer see the insurance "My book" link.
- **Training is now healthcare training.** The reference library gained a `vertical` column; all
  existing articles are tagged insurance, and a starter healthcare library is seeded: credentialing
  vs. enrollment, NPI (Type 1/2), state licenses, DEA & state CDS, board certification & CME,
  malpractice, CAQH ProView, CLIA for laboratories, and building an expiry watch list — each linking
  to the authoritative source (NPPES, CMS, DEA, ABMS, CAQH…). The library, the "Ask" search, and the
  intro copy all follow the tenant's vertical, and opening another vertical's article by direct link
  is redirected away.

Insurance tenants are unchanged: the full Medicare-agent menu and the AHIP / ready-to-sell / SOA
training library remain exactly as before.

### v1.86.0 — industry at sign-up + guided onboarding wizard

No migration required.

**Industry is now the first choice when creating an organization.** The sign-up form asks Industry
first (Insurance / Healthcare / Other) and then shows only that industry's organization shapes —
instead of one mixed list. The industry also shows as a chip on the platform admin's tenants list.
Under the hood nothing moved: the vertical is still derived from the organization shape, so there is
exactly one source of truth.

**Guided onboarding — a step-by-step way to add providers, doctors, producers, and organizations.**
Next to the existing quick "Add" button there is now **Guided onboarding**, a four-step wizard:

1. **Who** — individual or organization, provider type (healthcare), name, email.
2. **Identifiers** — NPI (and NPN + licence for insurance), birth month, role. Skippable.
3. **Requirements** — the best-matching compliance templates for this type, best first; apply one
   or choose "no template".
4. **Portal access** — send the portal invite now, or finish without inviting.

The record is created at step 1 and refined at each step, so exiting midway still leaves a usable
record and the profile checklist shows what's missing. **The quick form is unchanged** — teams can
onboard with the wizard or create records directly, whichever suits the moment. In the wizard the
portal invite is an explicit choice at the end rather than automatic.

Also fixed along the way: template suggestions for healthcare holders now match their provider type
(physician / nurse / lab / clinic / group). Previously the matcher only knew insurance roles, so
healthcare holders were never offered their own templates.

### v1.85.0 — vertical separation + Excel-like credential report

No migration required.

**Verticals now differ in both wording and requirement logic.** MA-agent specs and doctor / medical-group
specs were sharing insurance-only values and labels; they're now fully separated:

- **Requirement templates** validate "applies to" and the care/line axis against *this vertical's* allowed
  values. A healthcare org can now create Physician, Nurse, Laboratory, Clinic, and Medical-group templates
  (previously any new template was silently forced to "agent"). Insurance keeps agent / agency / FMO and
  Medicare / Commercial exactly as before.
- **The templates list** groups by the vertical's own types. Healthcare templates that were *completely
  invisible* on this page (it only ever rendered agent/agency/fmo groups) now show correctly.
- **The template form** shows the right choices and wording per vertical — "Care setting" and
  "Anchoring credential" for healthcare vs "Lines of business" and "Anchoring licence" for insurance.
- **The organization tab** reads "Operating jurisdictions" for healthcare (states only) and "Lines of
  business" for insurance (Medicare / Commercial P&C plus states), since lines of business are an
  insurance concept.

**The credential report is now Excel-like.** The Reports → Credentials grid gained:

- **Click-to-sort** on every column (ascending/descending, numeric-aware for Days left and Docs).
- **Free-text search** across all columns, and a **per-column filter** row.
- **More columns** — issuing body, credential number, issued date — with a **Columns** menu to show or
  hide any column (issuer, number, and issued are hidden by default to keep it clean).
- **Sticky header and filter row**, zebra striping, a live **result count** ("N rows · showing X–Y"), and
  **pagination** (25 / 50 / 100 / All per page). The existing server-side filters still narrow the dataset
  and drive the CSV export.

### v1.84.0 — one-click demo billing company (healthcare)

No migration required.

The healthcare vertical now has a self-contained demo organization that installs and removes with one
click from **System & updates → Demonstration data — healthcare**, exactly like the insurance demo.

- **Coastal Credentialing Partners** — a billing company operating in Florida and Puerto Rico, in its
  own tenant. Building it creates the organization, an owner login, two working provider portal
  logins, five providers of different types (a physician, a Puerto Rico physician, a nurse
  practitioner, a clinical laboratory, and a medical group), and twenty-five credentials spread across
  active, expiring, and expired states — so every dashboard, report, and CV has something real to show.
- **Sign-in:** owner `demo-admin@coastalcred.example` / `coastal-demo-2026`; provider portal
  `ana.rivera@coastalcred.example` / `provider-demo-2026`.
- Its own tenant means **removing it is clean** and never touches your real data. It coexists with the
  insurance demo — you can have both, and removing one leaves the other untouched.

Previously the healthcare demo data was only available as a command-line seeder that populated an
existing tenant; this makes it a proper build/teardown org like the agency demo, with real user
logins included.

### v1.83.0 — providers can download their own CV

No migration required. Small follow-up to v1.82.0.

- **Self-service CV.** The CV / Credential Summary added in v1.82.0 was reachable only by staff. A
  provider viewing their own portal can now download their own CV from **My workspace → My CV (PDF)**.
  It's the same document staff generate, scoped to the provider's own record — a provider can only
  ever pull their own. The staff route is unchanged; both now share one code path so the output is
  identical.

### v1.82.0 — reports, CVs, self-service intake, provider reminders

No migration required. Four features closing the gap with the established credentialing platforms.

- **Credential report** (Reports → Credentials). A filterable report over every credential on file:
  status, document type, holder, expiry window (30/60/90/180 days), evidence present/missing, and —
  for healthcare — provider type. **Export CSV** downloads exactly the filtered rows, with
  spreadsheet-formula escaping and an audit entry per export.
- **CV / Credential Summary (PDF).** One click on any record ("CV (PDF)" next to the credential
  package) produces a professional credential CV assembled from the data on file — identity,
  identifiers, and every credential grouped by section (Licensure, Certifications, Insurance,
  Continuing Education, Background…) with issuer, number, dates, evidence count, and a clear
  ACTIVE / EXPIRING / EXPIRED flag. Built for dropping into credentialing packets.
- **Self-service intake** (My workspace → My profile). Invited producers/providers complete their
  own record — NPI (NPN for insurance), birth month, role, provider type — feeding straight into
  the same record staff manage. Only self-service fields are writable; name, login, and status stay
  with the office. The onboarding checklist now points producers here (its previous links led to
  staff-only pages) and asks for the right identifier per vertical (NPI for healthcare, NPN for
  insurance).
- **Send reminder** (Compliance → by producer). A per-record button that emails that person one
  summary of everything they have expiring or expired. Deliberate and repeatable — unlike the
  automatic threshold reminders — and each send is audited. The button appears only where something
  actually needs attention.

### v1.81.0 — healthcare vocabulary + audit fix (Phase 5)

No migration required. Styling/wording and one correctness fix.

**Healthcare vocabulary (Phase 5).** A healthcare organization now reads in healthcare language:
"providers" instead of "producers", with healthcare-flavored headings and help text, driven off the
organization's vertical. Insurance organizations are unchanged — they still read "producers",
"carriers", and so on. The wording is presentation only; it never changes routes, permissions, data,
or behavior. This completes the healthcare vertical plan (Phases 1-5).

**Audit fix — retired custom types keep their label.** Previously, retiring a custom document type
removed its name everywhere, so an existing credential of that type could display its internal code
instead of its name. Retiring now correctly stops only *new* assignment: existing records keep the
proper name, while the type is gone from the picker. Restoring brings it back to selection.

This release also follows a security and logic audit of the codebase. The review confirmed the core
protections are sound — parameterized queries throughout, tenant scoping enforced at the data layer,
Argon2id password hashing with timing-equalized login, CSRF protection that fails closed,
content-sniffed file uploads with server-generated names, secure session cookies with timeouts, a
strict Content-Security-Policy, and cryptographically-random tokens. The retired-label issue above
was the one behavioral fix arising from it.

### v1.80.0 — Puerto Rico jurisdiction + demo data (healthcare Phase 4)

Run **System & updates → Update database** for migration 070.

Puerto Rico is now a first-class jurisdiction for healthcare organizations, and there's a demo-data
tool for standing up a realistic billing-company tenant.

- **Puerto Rico added to the jurisdiction list** (alongside the U.S. Virgin Islands and Guam), so a
  healthcare organization operating in PR can select it as an operating jurisdiction.
- **PR-specific credential types** (verified & cited): PR Medical License (PR Board of Medical
  Licensure and Discipline, triennial), PR triennial continuing education (60 hours, incl. disease
  prevention and bioethics hours), Certificate of Good Conduct (PR Police), negative
  controlled-substances certificate, and PR laboratory personnel license. Federal credentials
  (NPI, DEA, CLIA, Medicare) already applied unchanged, since PR participates in Medicare/Medicaid
  and CLIA law names it explicitly — so a PR provider tracks "federal + PR-board," not "federal + state."
- These surface only for organizations operating in Puerto Rico, exactly as the Florida and Georgia
  content is scoped by operating state. Insurance organizations are unaffected.
- **Demo-data tool** (`tools/seed_demo_healthcare.php`): populates a healthcare tenant with five
  providers of different types (physician, PR physician, nurse, laboratory, medical group), each with
  a realistic credential set at varied expiry states, so demos and screenshots have something to show.
  Run it from the app root after installing as a billing company: `php tools/seed_demo_healthcare.php`.
  It's idempotent and refuses to touch a non-healthcare tenant.

This is Phase 4 of the healthcare vertical plan. Phase 5 (healthcare vocabulary — "provider" not
"agent") remains.

### v1.79.0 — healthcare starter catalog & provider types (healthcare Phase 2)

Run **System & updates → Update database** for migrations 068 and 069.

A healthcare organization now starts with the standard national credentialing document set already
seeded, and classifies each provider by type so the right template applies. This is Phase 2 of the
healthcare vertical plan; it builds on the tenant-extensible catalog from v1.78.0.

- **New org shape "Billing / credentialing company"** — for a company that manages credentials for
  many providers (the healthcare analog of an FMO). It runs in the healthcare vertical.
- **Seeded national catalog** (verified, cited): NPI, state professional license, DEA, state
  controlled-substance registration, board certification, malpractice, CAQH attestation, CME, plus
  organizational entries (Type 2 NPI, EIN, business license, group malpractice, Medicare PTAN) and,
  for laboratories, the CLIA certificate, state lab license, and director qualifications. These are
  documents to **track**, not applications to submit.
- **Provider types** (physician, nurse/advanced practice, laboratory, clinic/facility, medical group)
  on each provider, driving which seeded template applies — a lab tracks CLIA and organizational NPI;
  a physician tracks license, DEA, board certification and malpractice.
- **Vertical-scoped catalog.** Seeded types are now tagged by vertical, so a healthcare organization's
  picker shows only healthcare types and an insurance organization's shows only insurance types.
  Custom types you add yourself continue to appear for your organization regardless.
- The insurance experience is unchanged: verified end to end that an insurance organization sees no
  healthcare types, no provider-type selector, and none of the healthcare templates.

### v1.78.0 — tenant-extensible document catalog (healthcare Phase 1)

Run **System & updates → Update database** for migration 067.

The credential catalog is now two-tier. The built-in, statute-cited document types remain global and
shipped by release, exactly as before. On top of that, each organization can now define its **own**
document types under **Organization → Tracked documents** — name, category, renewal cadence, optional
jurisdiction — and they immediately become selectable when recording a credential. Custom types are
visible only to the organization that created them.

- A custom type's short code is derived from its name; it may not collide with a built-in code (no
  shadowing — "which x_license?" must never be ambiguous), while two different organizations may each
  use the same custom code without conflict.
- Retiring a type hides it from new use; existing credentials referencing it are untouched, and it
  can be restored. All changes are audit-logged.
- Under the hood the catalog's primary key moved from `code` to a surrogate id with a unique
  (organization, code) constraint — the structural change the healthcare vertical's "track anything"
  requirement rests on. Existing seeded rows are preserved unchanged; the insurance experience is
  identical to before, with custom types simply appearing after the built-in set.
- This is Phase 1 of the healthcare vertical plan (see docs/healthcare-vertical-implementation-plan.md).
  Phase 2 seeds the verified national provider catalog (NPI, DEA, board certification, malpractice,
  CLIA…) so a healthcare organization starts with the standards, not a blank list.

### v1.77.4 — clean re-install over a partial database

Bug fix, follow-up to v1.77.3. After the older-MariaDB fix, a **second** install attempt could fail
with `Duplicate key name 'uq_holder_tenant'`. The cause was leftovers: the first (failed) attempt had
already created some tables and keys, so re-running the installer tried to add things that were now
already there.

When you tick **"the database is empty / safe to (re)create"**, the installer now drops any existing
tables in the target database first, then installs cleanly from scratch. This is deliberately safer
than installing around the leftovers — a partial install can leave tables on an older shape that a
later step then can't alter — so dropping first guarantees the finished schema is exactly this build's.
Dropping only happens when you tick that box, so it never touches a database you didn't mark as
disposable. An install into a genuinely empty database is unchanged.

**If you are stuck on this error:** re-upload this build and run the installer again with that box
ticked; it will clear the half-made tables and complete. (Dropping and recreating the database in
cPanel also works and is equivalent.)

### v1.77.3 — installer compatibility with older MariaDB

Bug fix. A fresh install could fail at migration 018 with a syntax error near
`IF EXISTS chk_tenant_vertical` on hosts running **MariaDB older than 10.5**, because a handful of
migrations used `ALTER TABLE ... DROP CONSTRAINT IF EXISTS`, and the `IF EXISTS` form of that clause
only exists from MariaDB 10.5.2 onward. (The installer reported the *previous* migration, 017, as the
point of failure; the offending statement was actually in 018.)

The five affected statements now use the portable `DROP CONSTRAINT` form, which every version accepts.
On a fresh database, dropping a constraint that isn't there yet raises a harmless "can't drop; check
that it exists" error (1091); the installer now recognises that specific case for constraint drops and
continues, while still stopping on any genuine error. The in-app "Update database" path already
tolerated this, so only the first-time installer was affected.

No action needed beyond installing this build. If a previous attempt left a half-created database,
drop it and re-run the installer against an empty one.

### v1.77.2 — logo-area line alignment

Styling only — no migration, no config change. Upload and hard-refresh once (Ctrl+Shift+R).

Follow-up to v1.77.1: the separator line under the logo area now sits on exactly the same baseline
as the header line, in both the expanded and collapsed sidebar states. The logo area mirrors the
header's box model precisely (identical padding and inner height), so the two lines stay level
regardless of which is showing. When collapsed, the logo fills the strip's height and widens rather
than growing taller, which keeps it aligned with the header.

### v1.77.1 — sidebar logo area

No migration and no config change — a styling-only release. Upload and hard-refresh once
(Ctrl+Shift+R) so the new stylesheet is picked up.

- The logo area at the top of the sidebar now carries the same separator line as the header, so a
  single unbroken line runs across the top of the screen, dividing the logo and title strip from the
  menu and page below it.
- The logo is centered in its area.
- When the sidebar is collapsed, the logo is shown larger.

### v1.77 — error log

Run **System & updates → Update database** for migration 066.

**Production errors now reach a screen instead of a phone call.** Uncaught exceptions and fatal
errors already went to PHP's error_log(), which on shared cPanel hosting is effectively invisible.
They are now also recorded in the application, under a new **Errors** tab in the platform console. The
tab carries a badge showing how many are unresolved, so you notice breakage without going looking.

- Each entry shows the error class and message, the file and line, and the request path and method.
  Stack traces and request bodies are deliberately **not** stored, and the query string is stripped
  from the path, so nothing personal is captured.
- Recording an error can never break the request: if the log write fails (database down, table not
  yet migrated), the failure is swallowed and the original error still goes to PHP's log. Visitors
  continue to see the same safe "something went wrong" message, never a stack trace.
- Mark entries handled individually or all at once; purge anything older than 90 days. Only a
  platform administrator can see the log.

### v1.76 — roster verification links

Run **System & updates → Update database** for migration 065.

**One link that verifies a whole downline.** On Ready to Sell there is now a *Roster verification link*
panel. Instead of sending a carrier or upline a separate link per producer, you share one URL listing
every producer with their licence status, E&O status and carrier readiness. It reuses the existing
share-link machinery, so expiry, revocation, view counting and token secrecy all behave exactly as
they do for a single producer.

- **Scope it to one carrier** and the page reports only that carrier's readiness — a carrier has no
  business seeing which of its competitors your agents are appointed with, and a scoped link does not
  even name them.
- **Deliberately narrow.** The page shows names, NPN, licence and E&O status, and carrier readiness.
  No dates of birth, addresses, contact details or document contents: anyone holding the link can open
  it, so it carries only what verification actually requires.
- Readiness is computed from the same RTS matrix the agency sees internally, so both parties read
  identical numbers. The footer states plainly that this reflects agency records and is **not** an
  independent verification against a state department or NIPR.
- Revoking is immediate, expiry is enforced on resolve, and every view is logged.

**Also fixed:** the public verification page still pulled its fonts from Google, which had been missed
when fonts were brought in-house in v1.74. It now uses the bundled files, so no page in the
application makes a third-party request.

### v1.75 — Georgia becomes the second statute-sourced state

Run **System & updates → Update database** for migrations 063 and 064.

**Georgia regulatory content.** Eleven Georgia-specific catalog entries now sit alongside Florida's,
each carrying its citation: the Life/Accident & Sickness, Accident & Sickness, Property & Casualty and
Personal Lines licences (biennial, due the last day of the licensee's birth month); continuing
education (24 hours with 3 in ethics under 20 years of service, dropping to 20 hours from 2026 for
those over 20, carry-over capped at 50%); long-term care, annuity and flood training; the certificate
of authority required from each insurer represented; the electronic fingerprint background check; and
the **15-day appointment filing rule** under O.C.G.A. § 33-8-1, which is the most commonly missed
Georgia obligation — the clock runs from the agency contract date, not the appointment.

Sources: Georgia OCI agent licensing and continuing-education pages, and Georgia Administrative Code
Rule 120-2-3-.07. Prelicensing hour counts are deliberately **not** encoded: published sources
conflict between a 20-hour and an 8-hour per-line model, and it is a one-time pre-appointment matter
rather than an ongoing obligation. Confirm with OCI if you need it.

Add GA under **Organization → Lines of business → operating states** and the Georgia items appear
wherever credentials are chosen.

**NIPR interest signal.** The producer page now states plainly that CMS/NPPES verification confirms an
**NPI** — a healthcare provider identifier — and is *not* a check of a state insurance licence, which
lives in NIPR's producer database keyed on NPN. A one-click "I'd want this" records demand for real
NIPR reconciliation. It makes no external call and sends no data anywhere; it exists so the decision
to take on NIPR's FCRA obligations and per-transaction pricing can be made on evidence.

### v1.74 — self-hosted fonts, admin layout fix

No migration in this release.

**Fonts now ship with the application.** IBM Plex Sans, IBM Plex Mono, and Archivo are bundled as
woff2 files in `assets/fonts` (168 KB, latin subset, both families under the SIL Open Font License —
see `assets/fonts/OFL.txt`). Nothing is fetched from Google any more, and both Google origins are gone
from the Content-Security-Policy. There is no longer any need to run `tools/fetch_fonts.php`; it
remains only for anyone who wants to refresh the files themselves.

> **Upgrading from v1.67–v1.73:** your `app/config.php` has `'fonts' => 'google'` written into it,
> which overrides the new default. Change that line to `'fonts' => 'self'` to serve the bundled files
> and drop the third-party origins. Set `'system'` instead if you would rather use the OS font stack
> and load no web fonts at all. Fresh installs already default to `'self'`.

**Fixed:** a two-column grid on the platform tenant console was never closed, so every panel below it
— Domains, users, activity — was being pulled into the two-column layout instead of rendering at full
width. The wrapper is now closed and the markup balances.

### v1.73 — bulk credential renewal, producer evidence upload

No migration in this release.

**Bulk renewal (Ready to Sell → Renew…)**

Select any number of producers and renew one credential type across all of them in a single pass —
the twice-a-year job that makes AEP painful. Choose the credential, the new plan year, and the issued
and expiry dates; optionally scope it to a carrier.

- Renewal **adds** a credential for the new period and leaves the previous one in place. Compliance
  work has to be reconstructable after the fact, and overwriting an expiry date destroys the evidence
  that a producer was covered during the prior period.
- Identifying details (credential number, issuing body) carry forward, so a renewal is not a blank
  record. Only the dates change.
- Re-running is safe. Anyone already holding the credential for the target period is skipped rather
  than duplicated, so a double submit or an overlapping selection cannot inflate the record.
- The result message separates renewed, newly created (no prior credential on file), and skipped.
  Every run is audit-logged.

**Producer evidence upload**

A producer can now open their own credential from the portal and attach the certificate or licence
PDF to it, closing the loop on self-service — previously they could file the record but had to wait
for staff to upload the proof.

- Strictly limited to their own record: opening or uploading to another producer's credential is
  refused, the full credentials list stays closed, and deleting a credential remains staff-only.

### v1.72 — domain-to-organization mapping

Run **System & updates → Update database** for migration 062.

- **Each agency can have its own domain.** In the platform console, a tenant now has a **Domains** panel
  where you map hostnames to that organization. Anyone arriving on a mapped domain sees that agency's
  logo, name, and colours on the sign-in page — on their very first visit, before any login.
- Point the domain at this server first (an A record or CNAME), then add it. Hostnames are normalised on
  save: case, port, trailing dot, and a leading `www.` are all stripped, so the apex and www forms resolve
  to the same organization. A hostname can belong to only one organization, and attempting to claim
  another's returns a clear message rather than silently reassigning it.
- **Domain matching drives branding only.** The Host header comes from the client and can be forged, so it
  never influences access: sessions and tenant scoping remain the sole authority. A request on someone
  else's domain is still redirected to sign in, and a signed-in user stays in their own organization
  regardless of the hostname they arrive on.
- Resolution order for signed-out pages is now: mapped hostname, then the organization this browser last
  signed into, then the only organization if the install has just one, and otherwise the standard theme.

### v1.71 — Menu icons tab, branded sign-in page

No migration in this release.

- **Menu icons now has its own tab** under Organization. It previously existed but was only reachable
  through a small link on the Appearance panel, which made it effectively invisible.
- **The sign-in page carries your branding.** Login, registration, and invite-acceptance pages render
  before anyone is signed in, so they always showed stock colours. They now pick up your palette, colour
  scheme, logo, and organization name.
  - On a single-agency install the branding is applied automatically.
  - On a shared install the browser remembers which organization it last signed into, so returning users
    see their own branding. The hint is presentation only — it grants no access, is re-validated on every
    request, and an unrecognised value simply falls back to the standard theme.
  - A browser with no history on a shared install sees the neutral FlxCoreHC theme rather than a guess, so
    one agency's branding is never shown to another's staff.

### v1.70 — customizable menu icons

Run **System & updates → Update database** for migration 061.

- **Menu icons are now yours to choose.** Organization → **Menu icons** lets you reassign the icon on any
  of the 19 navigation groups and items from a searchable catalogue of 128 icons, so the menu can match
  how your agency talks about its business. Changes apply to everyone in the workspace and are audit-logged.
- Only the entries you actually change are stored, so defaults can improve over time without overwriting
  your choices, and "Reset all to default" clears them in one step.
- Icon geometry stays embedded — no CDN, no external files, no build step.

### v1.69 — palette reaches the dark theme, producer tabs, layout fixes

No migration in this release.

- **Dark theme now follows your palette.** Previously a preset only reached buttons and the sidebar,
  leaving the page itself the stock navy. Page background, panels, and borders are now tinted with your
  brand hue in dark mode (and given a matching whisper of it in light mode). The dark accent is also
  rebuilt properly: raising lightness alone turned saturated brand colours into pastel, so it now targets
  an explicit lightness and restores saturation, with a contrast check against the dark surface.
- **Navigation group icons are visible again.** They were previously shown only when the sidebar was
  collapsed.
- **The collapse toggle moved out of the logo area into the header**, next to the page title.
- **Producer page is now tabbed** — Overview, Credentials (with a count), Compliance requirements,
  Carrier verification links, and Continuing education — instead of one long scroll. Existing deep links
  still work: a link to a section opens the tab that contains it.
- **Two-column layouts** on Your profile and the credential edit page. The new-credential form stays
  single column, since its side panels only exist once a credential has been saved.

### v1.68 — compliance overview, settings tabs, Tabler icons, theme fixes

No migration in this release.

**Security & compliance area (new)** — Organization → **Compliance**. Describes the safeguards behind
your data: encryption at rest and in transit, access control and 2FA, application-security defences,
and the audit trail. Configuration-dependent items are reported live from your actual settings rather
than asserted, so the page never overstates your posture, and it lists any recommended steps still
outstanding. **Download overview (PDF)** produces a document you can hand to a carrier, FMO, or
compliance officer; exports are audit-logged. The document states plainly that it describes software
controls and is not a certification — policies, training, risk analysis, and BAAs remain the agency's.

**Settings reorganised**
- **Profile** now holds only Organization, Appearance, Logo, and Preview.
- **Lines of business** is a new tab holding lines and operating states.
- Two-factor policy moved to the **Security** tab, next to the enrolment status it governs.
- Saving one tab can no longer blank fields belonging to another — organization updates are now
  partial, writing only the fields a form actually rendered.

**Fixes**
- **Light/dark toggle.** Assets are now version-stamped (`app.js?v=…`), so a browser can't keep running
  a cached older script against newer markup — which is what broke the toggle after the last upgrade.
  Clearing your browser cache once will also fix it on any build.
- **Live palette preview.** Choosing a preset or colour now restyles the entire workspace immediately —
  sidebar, buttons, chips, focus rings, corner radius, and the dark-mode variants — instead of only the
  preview card. The preview is generated by the same logic as the server, so what you see is exactly
  what saves. Nothing persists until you press Save settings, and leaving with unsaved changes warns you.

**Interface** — icons replaced with **Tabler Icons** (MIT). Geometry is embedded directly, so there's no
CDN request, external file, or build step. License text ships in `assets/vendor/`.

### v1.67 — CSP hardening, security headers, tenant theming

Run **System & updates → Update database** for migration 060 (tenant theme columns).

**Security**

- **Content-Security-Policy no longer allows inline script.** Every script tag now carries a
  per-request nonce, so `'unsafe-inline'` is gone from `script-src`. Without that change CSP could
  not actually stop an injected `<script>` from running, which is the main thing a policy is for.
  Inline `onclick`/`onchange` handlers were replaced with a delegated `data-action` layer, since
  inline handlers cannot run under a nonce policy. (`style-src` intentionally still permits inline
  styles — the app uses inline `style=` attributes heavily and inline CSS is a far weaker vector.)
- **New headers:** `Strict-Transport-Security` (HTTPS only, so it can't lock out a plain-HTTP host),
  `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `object-src 'none'`.
  Set `'hsts' => false` in app/config.php to suppress HSTS.
- **Fonts are configurable** via `'fonts'` in app/config.php: `google` (default), `system` (OS font
  stack, no third-party request at all), or `self`. For `self`, run `php tools/fetch_fonts.php` once
  on the server to download the font files into assets/fonts, then switch the setting.

**Tenant theming (white-label)**

- Organization settings gains an **Appearance** panel: accent colour, sidebar colour, corner style
  (sharp / soft / rounded), default colour scheme (light / dark / match the device), and six ready-made
  palettes. Everything previews live before saving; individual users can still switch light/dark.
- Text contrast is computed, not guessed. If a pale brand colour is chosen, button text flips to dark
  automatically, and the settings panel shows the WCAG contrast ratio with a warning below 4.5:1 — so a
  customer's palette can't produce unreadable buttons.
- Derived shades adjust lightness in HSL rather than mixing toward white, so a brand colour keeps its
  hue instead of washing out to grey.
- Colour input is strictly validated, so theming cannot be used to inject CSS.
- A tenant that never opens the panel is completely unaffected — no override CSS is emitted at all.

### v1.66 — openable requirements, producer self-service, RTS bulk actions

No migration in this release.

- **Requirement cards are now actionable.** On a producer's page, each requirement in the compliance
  checklist shows an **Open** button (jumps straight to the credential that satisfies it, to edit it or
  manage its documents) when covered, or an **Add** button (opens a pre-filled credential form) when
  it's assigned but not yet on file. This closes the gap where a satisfied requirement had no way to
  reach the underlying credential.
- **Producer portal self-service.** Producers can now resolve their own gaps: every "needs your action"
  item on their portal is a one-click Add/Update that opens a credential form scoped to their own
  record. Producers can only file against themselves — they can't see the full credential list or touch
  another producer's record.
- **Ready-to-Sell bulk actions.** Select multiple agents in the "Get agents ready" section and, in one
  step, **apply a compliance template** (add-to-existing or replace) or **send portal invites** to those
  without a login. Both bulk actions are audit-logged. Agents lacking a portal login are flagged inline.

### v1.65 — producer-seat pricing + ready-to-sell workflow

Run **System & updates → Update database** for migration 059 (producer pricing columns).

- **Producer-seat pricing as a plan lever.** Each plan can now set a monthly price per producer login
  and a free allowance (e.g. first 10 free, then $4 each). Staff seats and producer logins are billed
  as separate dimensions, so producers never eat into staff-seat limits. The billing page shows the
  producer count, the free allowance, and the resulting monthly charge; the Plans list shows each
  plan's producer price.
- **Ready-to-Sell workflow.** The RTS page now leads with a readiness roll-up (X of Y agents ready for
  the plan year) and a "Get agents ready to sell" section: every blocking item for every agent is a
  one-click button that opens a pre-filled Add-credential form — general items (license, AHIP, E&O)
  grouped once, carrier-specific items (certification, appointment) grouped per carrier. This turns the
  read-only matrix into an actionable checklist, matching the single-gate model used by AgentSync/SureLC.

### v1.64 — NPI-verified profiles, per-tenant seats, delete auditing

Run **System & updates → Update database** for migration 058 (NPI snapshot fields + per-tenant seat override).

- **NPI-verified producer identity.** Verifying a producer's NPI now saves a read-only snapshot of the
  CMS-owned fields (legal name, entity type, taxonomy/specialty, practice city/state, credential),
  stamped with who verified it and when, and shows a "✓ NPI verified" badge (also in the producers
  list). These fields are refreshed only by re-verification against CMS; agency-owned fields stay
  separately editable, so a re-verify never overwrites what a manager typed. Every verify is audit-logged.
- **Per-tenant seat override.** A specific customer can be granted a custom seat count from their console
  page, overriding the plan limit — a sales lever for bespoke deals. (Plan-level seat limits remain
  editable under Plans.)
- **Deleting a carrier plan is now audit-logged** (the reported gap), recording which plan was removed.
- **Progress spinners** on the NPI lookup, NPI verification, and CMS "Sync now" actions.

### v1.63 — NPI fix + producers as portal users

Run **System & updates → Update database** for migration 057 (clears any poisoned NPI cache).

- **NPI lookup fixed.** Earlier builds cached a "not found" result for 30 days, so one transient
  failure could make a valid NPI (e.g. 1841094455) keep reporting no record. Negatives are no longer
  cached at all, malformed/blocked responses are surfaced as retryable rather than "not found," the
  lookup follows redirects, and migration 057 clears any already-poisoned rows. If a lookup still
  fails in production, the message now distinguishes "no record in the registry" from "couldn't reach
  the registry" (the latter means the host needs outbound HTTPS from PHP).
- **Every producer is an app user.** Adding an individual producer now requires an email and
  automatically creates their portal login (invited state) linked to their producer record, sending
  the invite. Organizations (agencies/FMOs) are unaffected. Each producer page has a **Portal access**
  panel showing their login status, with an **Invite to portal** button for producers added before this
  change. Producer logins default to non-billable so seat limits never block provisioning (flip
  `producer_seats_billable` in config to charge per producer seat).

### v1.62 — carrier mapping, audit fix, sync report

Run **System & updates → Update database** after upload to apply migration 056 (carrier aliases +
sync-run detail). Highlights:

- **Duplicate plans fixed.** CMS files name plans by legal organization ("AETNA LIFE INSURANCE
  COMPANY") and contract id (H5521); the import now maps those to your master carrier brand via a
  seeded alias table (contract-id first, then a punctuation-insensitive org match, then a brand
  fallback), so imported plans attach to the right carrier instead of creating org-named duplicates.
  A **Remove placeholder plans** button clears the original hand-entered seed guesses once CMS is loaded.
- **Audit trail fix.** CMS syncs and NPI lookups now write to the same table the Audit view reads,
  so they show up. The Audit view gained search, an action filter, and sortable columns, and all
  timestamps render in Eastern time, 12-hour, MM/DD/YYYY.
- **Sync report.** Each run links to a per-run report (added / updated / deactivated / removed, and a
  by-carrier breakdown).
- **Carrier-plan table** gained a search box, sortable columns, and a carrier filter that reflects the
  carriers actually present.
- **Credentials.** Each requirement row now has an Add / Renew / Update button that opens a
  pre-filled credential form; Cancel returns to the producer you came from.
