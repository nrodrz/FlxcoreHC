# FlxCoreHC — Feature Test & Validation Plan

*Version 1.162.0 · Rebuilt September 2026 to cover every feature shipped through v1.158. Use with
**docs/DEMO_ACCOUNTS.md** (all demo logins). Every case is written so a non-developer can run it.
Record **Pass / Fail / N-A** and a note for each ID.*

---

# Part 1 — Tenant application

## How to use this plan

1. **Build the demo first**: Console → System → *Build healthcare demo*. Five organizations with
   data already loaded — including payers, enrollments, addresses, office contacts, custom fields,
   education/work history and cross-org links. Re-run any time to reset.
2. Run **Suite A** first — it proves the install is alive. Then split the rest between testers.
3. Test on **both a desktop browser and a phone** where the case says 📱, in **dark mode** where 🌙,
   and in **Spanish** where 🌐 (switch language in the user menu).
4. Log defects with: test ID, who you were signed in as, what you expected, what happened, screenshot.

**Legend:** 🔒 security-critical (must pass before go-live) · 📱 phone · 🌙 dark theme · 🌐 both languages

---

## Suite A — Smoke (run first, ~10 min)

| ID | Steps | Expected |
|----|-------|----------|
| A1 | Open the app URL | Login page loads, styled |
| A2 | Sign in as `demo-admin@coastalcred.example` | Dashboard with KPIs |
| A3 | Open Providers, Credentials, Calendar, Reports, Team, Organization | All load, no error text |
| A4 | 🌙 Toggle dark theme (sun icon) | Whole app readable; no invisible text |
| A5 | Sign out, sign back in | Works |
| A6 | 📱 Repeat A2–A4 on a phone | Tables stack into cards; nothing cut off |
| A7 | Dashboard → **Management dashboard**: KPI tiles, compliance donut, 6-month expiry forecast, work queue | All render with real numbers; no warnings |
| A8 | Click a KPI tile / a donut level / a forecast month | Opens the matching filtered list / calendar month |
| A9 | Work queue → click **Renew/Fix/Review** on the first row | Opens the exact record; a **← Back to dashboard** bar appears at the top |
| A10 | Click **← Back to dashboard** | Returns to the dashboard; bar disappears; queue re-ranked |
| A11 | Work queue ordering | Expired / not-compliant first, then expiring ≤30d, then reviews, then re-verifications |

## Suite B — Navigation & hub panel

| ID | Steps | Expected |
|----|-------|----------|
| B1 | Open Organization → Profile | Right side panel lists groups: Setup, People & access, Compliance, Sharing & oversight, Billing |
| B2 | Click each group header | Only one group open at a time; chevron flips |
| B3 | Click **×** in the panel header | Panel closes; content widens; an edge tab (hamburger) appears mid-right |
| B4 | Click the edge tab | Panel reopens; state remembered when you navigate to another hub page |
| B5 | Visit Activity, Audit log, Billing, Email delivery, Addresses, Custom fields | Panel present on every one |
| B6 | Console (platform admin) → any page | Panel groups: Customers, Content & data, Operations, Reference |
| B7 | 🌙 Repeat B1–B4 in dark mode | Group labels and items readable |
| B8 | 📱 Open the hub on a phone | Edge tab opens a drawer with a dim backdrop; × or backdrop closes it |
| B9 | Left sidebar on hub pages | Unchanged — labels never disappear |

## Suite C — Organization setup

| ID | Steps | Expected |
|----|-------|----------|
| C1 | Profile → change name, contact, **Default language** 🌐 | Saves; header updates; new users default to that language |
| C2 | Profile → choose a theme preset → **Save settings** | Saves (toast "settings saved"); theme applies after reload; no leave-page prompt |
| C3 | Profile → **Additional fields** (if any org custom fields) | Values save with the form |
| C4 | Operating jurisdictions → tick/untick a state | Catalog items for that state appear/disappear on credential forms |
| C5 | Tracked documents → add a custom type with a cycle | Selectable when adding a credential |
| C6 | Locations → add; Groups → add | Appear in provider pickers and filters |
| C7 | **Addresses** → add legal + billing, make one primary | Listed; exactly one primary; offered in credential address pickers as "Organization · …" |
| C8 | **Custom fields** → add a text field, a required choice list, a number (provider scope) | Listed with type/required; appear on provider edit form under Additional fields |
| C9 | Custom fields → add an organization-scope field | Appears on Organization → Profile |
| C10 | Custom fields → **Retire** a field | Hidden from forms; previously entered values still in exports |
| C11 | Email delivery → own SMTP with a preset, test send | Verified badge |
| C12 | Email delivery → **Connect Microsoft 365** (if platform enabled) | Sign-in round trip; connected chip; *Send test to myself* arrives |
| C13 | Menu icons → change an icon | Navigation updates |

## Suite D — Providers

| ID | Steps | Expected |
|----|-------|----------|
| D1 | Providers → Add (physician, with NPI) | Saves; in list |
| D2 | NPI lookup | Fills name/taxonomy from NPPES |
| D3 | Assign group + location, mark primary | Shown; filters work |
| D4 | Import CSV (1 good, 1 bad row) | Preview explains the bad row; good row imports |
| D5 | Edit → **Application data** → fill middle name, gender, languages, specialties, taxonomy, CAQH ID, PTAN, Medicaid ID, hospital affiliations | All persist on reopen |
| D6 | Provider page → **Application readiness** | Score + grouped checklist; each gap says where to fix; DOB/SSN shown as decision-pending (not scored) |
| D7 | Fill several gaps (address, contact, education, work) → reload | Score rises; those items ✓ |
| D8 | **Addresses** → add mailing + home; make home primary | Listed; one primary; validation rejects missing street |
| D9 | **Office contacts** → add "Carmen, Office manager", email, Spanish, receives requests + reminders | Listed; **no user account created** (Team unchanged) |
| D10 | **Education & work history** → add medical school, residency, two jobs with a gap explanation | Listed with dates; delete works |
| D11 | Provider page → **Profile packet** | Includes Additional information (custom fields), education, addresses |
| D12 | Delete a provider, check audit log | Soft-deleted; logged |

## Suite E — Credentials & documents 📱

| ID | Steps | Expected |
|----|-------|----------|
| E1 | Add credential with issue date on a cycled type, blank expiry | Expiry derived |
| E2 | Set expiry 20 days out / in the past | Expiring / Expired; on calendar; on dashboard |
| E3 | Credential → **Address on file with this issuer** → pick → save → reopen | Persists |
| E4 | Requirement rows show **⚖ Why?** with authority/regulation/source link | Present for linked types (license, DEA, Good Standing, CME) |
| E5 | Attach PDF; 📱 **Take photo**; 📱 **Scan (multi-page)** | All attach; kinds shown (Primary/Renewal/Supporting) |
| E6 | Mark a document **Verified** with a re-verify date | Badge; on calendar; in packet |
| E7 | 🔒 Upload a renamed `.exe` as `.pdf` | Rejected |
| E8 | 🔒 Re-download a phone photo | GPS/EXIF stripped |
| E9 | Import credentials CSV | Preview; deadlines on calendar |

## Suite F — Verification & compliance levels

| ID | Steps | Expected |
|----|-------|----------|
| F1 | Provider → Primary-source verification → **Verify NPI (NPPES)** | Record with match/mismatch; name differences shown |
| F2 | **Check SAM.gov** (key set in Console → System) | Clear or flagged; a flag hard-stops the compliance level |
| F3 | **Record a verification done at the official page** (ORCPS) with reference + re-verify date | Listed; credential last-verified stamped |
| F4 | Set re-verify date in the past | Marked **due** |
| F5 | Provider → **Compliance level** | 0–5 with reasons for next level |
| F6 | 🔒 Set the PR license expiry in the past | Level **0 — hard stop** regardless of everything else |
| F7 | Payers & enrollment → Triple-S Participating, Medicaid enrolled (locations registered), OCS attested; NPI present | Climbs to **5 Fully compliant** |
| F8 | Uncheck "locations registered in PEP" | Warning "claims deny" |
| F9 | 🔒 Provider → Edit → **Sensitive identity data** (as Master Admin): set DOB and paste a full SSN | Saves; **only the last 4** are kept |
| F10 | 🔒 View the provider page as Master Admin | DOB and SSN last-4 shown; the view is written to the access log |
| F11 | 🔒 View the same provider as **Staff** | Panel hidden; values masked everywhere; the edit fields are not offered |
| F12 | 🔒 Generate the profile packet, data export, and (console) tenant report | **No DOB or SSN anywhere** in any of them |
| F13 | 🔒 Grant a custom role "View sensitive identity data", assign a user | That user now sees the values; revoke → masked again |
| F14 | 🔒 Organization → Roles → Manager → Sensitive fields → SSN = **Deny**, save | A Manager now sees SSN masked even though the role has "View sensitive identity data" |
| F15 | 🔒 Same screen → Staff → DOB = **Allow** | Staff sees DOB (only), SSN still masked; set back to Inherit → masked again |
| F16 | 🔒 Custom fields → add "Passport number", tick **Sensitive** | Packet/list boxes forced off; badge "sensitive" |
| F17 | 🔒 Enter a value as Master Admin; view/edit as Staff; export data | Staff: field hidden and cannot overwrite; export has no such column; per-field Allow on the custom field reveals it to Staff |

## Suite G — Payers, programs & OCS

| ID | Steps | Expected |
|----|-------|----------|
| G1 | Organization → Payers | PR payers pre-loaded; add a custom one |
| G2 | Provider → add payer status with recredentialing date | Row; date on calendar |
| G3 | Medicaid / Medicare enrollment with revalidation date | Saved; revalidation on calendar |
| G4 | OCS/SICRO attested with expiry | Saved; expiry on calendar |
| G5 | Organization → Regulations | Sourced citations with dates and links |
| G6 | Provider timeline after G2–G4 | payer / program / OCS events recorded |

## Suite H — Document requests 🌐📱

| ID | Steps | Expected |
|----|-------|----------|
| H1 | Provider (no email) with office contact Carmen (Spanish, receives requests) → **Request a document** | Nudge email goes to **Carmen, in Spanish**, link only (no attachment) |
| H2 | Sign in as `ana.rivera@coastalcred.example` → Document requests | Open request visible |
| H3 | 📱 Upload from phone | Status Submitted |
| H4 | Staff **Return** with note → provider resubmits → staff **Accept** | Accepted; document on credential |
| H5 | 🔒 Provider tries to edit/delete or resubmit after Accept | Not possible / refused |
| H6 | Flag Carmen for reminders; run reminder cron on an expiring credential | Carmen receives a copy |

## Suite I — External sharing 🔒

| ID | Steps | Expected |
|----|-------|----------|
| I1 | Share externally: payer, credentials + docs, 14 days, generated password | Link + password shown **once** |
| I2 | Reload | Password not shown again |
| I3 | Open link in incognito | Password prompt; recipient name only; no provider info |
| I4 | 5 wrong passwords | Locked 30 min; logged |
| I5 | Correct password | Read-only profile; document download works |
| I6 | Access log | Views, downloads, failures with IP |
| I7 | Revoke → reload | Denied |
| I8 | Duration 400 days | Capped at 180 |

## Suite J — Cross-organization sharing 🔒

| ID | Steps | Expected |
|----|-------|----------|
| J1 | In Bayamón add a provider with an NPI Coastal also manages | "also managed by N organization(s)" — no names |
| J2 | Non-Master-Admin looks for the link button | Not available |
| J3 | Master Admin → **Request to link records** | Pending; other org shown as "Another organization" |
| J4 | Provider portal → **Linked organizations** → consent | Sees both names; approves |
| J5 | Other org Master Admin → Organization → Linked providers → approve | Active; **still nothing shared** |
| J6 | Owner shares one credential with documents | Recipient sees it read-only under *Shared with you* |
| J7 | 🔒 Un-shared credential | Not visible to recipient |
| J8 | Owner edits expiry | Recipient sees new date (live) |
| J9 | 🔒 Recipient tries to edit/delete | Not possible |
| J10 | Owner → Shared by you → Access log | Recipient views/downloads with user + IP |
| J11 | Provider **Withdraw all consent** | Everything revoked in both orgs |

## Suite K — Calendar, notifications, reports

| ID | Steps | Expected |
|----|-------|----------|
| K1 | Calendar Agenda / Month; filters; drag; manual event; mark done | All work, logged |
| K2 | Subscribe (iCal URL) | Loads in a calendar app |
| K3 | Notification bell | Badge; open → marks read |
| K4 | Reports → Compliance + **Export readiness (CSV)** | Roll-ups by group/location/provider |
| K5 | Settings → **Download all data (ZIP)** | CSVs incl. providers with custom-field columns |
| K6 | 🖥️ Month view with a day that has 3+ long-titled events (e.g. Oct 3) | Events truncate with "…" inside the cell — nothing spills past the grid's right edge |
| K7 | 📱 Month view on a phone | Collapses to a stacked day-list (only days with events); each event full-width and readable |
| K8 | Console → System → **App timezone** → set to Puerto Rico (AST) | Saves; "today" highlight and overdue use that zone |

## Suite L — Roles & permissions 🔒

| ID | Account | Verify doc | Export | Cross-org share | Delete |
|----|---------|-----------|--------|-----------------|--------|
| L1 | Master Admin | allow | allow | allow | allow |
| L2 | Manager | allow | allow | allow | allow |
| L3 | Staff | allow | **deny** | **deny** | **deny** |
| L4 | Coordinator | **deny** | **deny** | **deny** | **deny** |
| L5 | Read-only | **deny** | **deny** | **deny** | view only |
| L6 | Provider portal | own docs | **deny** | consent only | **deny** |
| L7 | Custom role with only "View compliance reports" | Reports only |
| L8 | Restricted user assigned one provider | Sees only that provider everywhere (lists, calendar, notifications, shares) |

## Suite M — Privacy & platform boundary 🔒 (go-live gate)

| ID | Steps | Expected |
|----|-------|----------|
| M1 | Platform admin → real tenant → enter | Requires Master Admin approval |
| M2 | Approved support session → Providers / Credentials / Documents / Calendar / Audit / Addresses / Contacts | All blocked |
| M3 | 🔒 Support session → Settings → Download all data / Linked providers / Tenant report | Refused |
| M4 | Master Admin revokes mid-session | Ends on next click |
| M5 | Demo tenant → **Open demo (full access)** | Direct entry |
| M6 | 🔒 Org A user tries an Org B record/document id in the URL | Not found |
| M7 | Console → tenant → **Report PDF** / **Report CSV** | Counts only; **no provider names** anywhere (portal logins excluded from Team) |
| M8 | Audit + access logs after M1–M7 | Every action attributable |

## Suite N — Platform console

| ID | Steps | Expected |
|----|-------|----------|
| N1 | Tenants → open one | Header (name, status, Report PDF/CSV, Open demo), Usage & health, two columns below |
| N2 | 🌙 Same page in dark mode | Readable; 12-week activity chart shows 12 slots |
| N3 | User list role dropdown | Styled (not native); labels include Coordinator; arrows/scroll keep it open |
| N4 | Security · Database (2 columns, notes) · Regulatory (edit, superseded) · Changelog (collapsed, 2 columns) · Capabilities · Test plan · Demo accounts | All render |
| N5 | System → Build demo → **Demo accounts** | Credentials sheet; every login works |
| N6 | Email → App registration → Connect Microsoft 365 (platform sender) → test | Round trip; arrives from connected mailbox |
| N7 | Translations | Grouped by area (not one list); edit a Spanish string → applies 🌐 |

## Suite O — Language, help, dropdowns, edge cases

| ID | Steps | Expected |
|----|-------|----------|
| O1 | 🌐 Switch to Spanish; save something; open Help | Interface, flash message and help in Spanish (22 help sections) |
| O2 | Any styled dropdown: open, scroll page, ↓ ↓, Enter | Stays open while scrolling; highlights; selects |
| O3 | Required field empty / invalid NPI / >10 MB file | Clear messages |
| O4 | Back button after save; sign out then Back | No duplicate; protected pages hidden |
| O5 | Idle past timeout | Re-login required |
| O6 | 📱 Rotate phone on busiest lists | Layout adapts |

---

# Part 2 — SaaS platform & installation

*These suites test the product **as a SaaS operator**: installing it, upgrading it, running the
platform, and onboarding/offboarding client organizations. Run **P** on a scratch environment (a
spare subdomain or database), not on production.*

## Suite P — Fresh installation 🔒

| ID | Steps | Expected |
|----|-------|----------|
| P1 | Upload the release zip to an empty directory on the host and extract it | Files in place; `install.php` present |
| P2 | Open `/install.php` in a browser | Requirements screen: PHP 8.1+, pdo_mysql, mbstring, json, fileinfo, sodium/openssl, writable `app/` and documents path — each pass/fail |
| P3 | Deliberately fail one requirement (e.g. make `app/` read-only) and reload | Install is blocked with a clear explanation, not a crash |
| P4 | Fix it; enter database credentials that are **wrong** | Clear "cannot connect" message; nothing written |
| P5 | Enter correct database credentials, organization name, admin name (**two words**), email, password | Install completes: "created N tables and your administrator account" |
| P6 | Enter a single-word admin name | Rejected **before** any tables are created |
| P7 | Point the installer at a database that already has FlxCoreHC tables | Refuses to clobber existing data |
| P8 | 🔒 After install, open `/install.php` again | Refuses — already installed (locked) |
| P9 | 🔒 Check `app/config.php` | Contains generated crypto keys; not readable from the web (try `/app/config.php` in a browser → denied) |
| P10 | 🔒 Try `/database/`, `/cron/`, `/tools/` in a browser | All denied |
| P11 | Sign in as the administrator you created | Platform console available; your own organization works as a normal healthcare workspace |
| P12 | Console → Database | Table count matches the install message; migrations show as current |
| P13 | Console → Security | Posture checks; `app_url` set to your real https URL, environment = production |

## Suite Q — Upgrade an existing installation 🔒

| ID | Steps | Expected |
|----|-------|----------|
| Q1 | On an install with data, note the version (footer/Console) and take a backup (Suite S) | Baseline recorded |
| Q2 | Upload a newer release over the top, **keeping** `app/config.php` | Site still loads; existing sign-ins work |
| Q3 | Console → System → **Update database** | Migrations apply; success message; no errors |
| Q4 | Re-check Console → Database | Migrations current; new tables present |
| Q5 | 🔒 Before running Q3, browse the app briefly | No fatal errors from code expecting an unrun migration (features may show reduced detail, which is acceptable) |
| Q6 | Spot-check existing data after upgrade | Providers, credentials, documents, history all intact |
| Q7 | Run **Update database** a second time | Idempotent — no errors, nothing duplicated |

## Suite R — Tenant lifecycle (client organizations)

| ID | Steps | Expected |
|----|-------|----------|
| R1 | Console → Tenants → **Create organization** (billing company) and invite its Master Admin | Created with roles, templates, regulators and calendar types seeded |
| R2 | Accept the invitation from the emailed link | Master Admin sets a password and lands in their new organization |
| R3 | Create one of each shape: solo practice, medical group, hospital | Each seeds correctly; group/department labels match the shape |
| R4 | Console → assign a **plan** to a tenant | Plan and seat limits apply; over-limit actions are blocked with a clear message |
| R5 | Set a **seat override** for a tenant | Override respected |
| R6 | **Suspend** a tenant | Their users are locked out with an explanatory message; data untouched |
| R7 | **Reactivate** the tenant | Access restored, data intact |
| R8 | Console → Users → add a user to a tenant; reset a user's password | Both work; recorded in the audit trail |
| R9 | Add a **custom domain** to a tenant, then remove it | Added/removed cleanly |
| R10 | Approve / deny a pending registration (Console → Approvals) | Approved tenant becomes active; denied one cannot sign in |
| R11 | 🔒 Confirm you can do all of the above **without** entering tenant data | Lifecycle management needs no access to their records |

## Suite S — Backups & restore 🔒

| ID | Steps | Expected |
|----|-------|----------|
| S1 | Console → System → **Download database** | SQL dump downloads and is non-trivial in size |
| S2 | Console → System → **Download documents** | Archive of stored documents downloads |
| S3 | 🔒 **Restore drill:** on a scratch database, import the S1 dump and point a copy of the app at it | App runs; tenants, users and records present |
| S4 | Sign in on the restored copy and open a provider with documents | Records present; documents resolve if the S2 archive was restored to the documents path |
| S5 | Note how long S1–S4 took and record it | You have a real, measured recovery procedure |
| S6 | Confirm your host-level (cPanel) backup schedule also covers the documents path | Both database and files are covered |

## Suite T — Scheduled jobs (cron)

| ID | Steps | Expected |
|----|-------|----------|
| T1 | Configure the four cron jobs (`send_reminders`, `sweep_expiring`, `send_digests`, `screen_leie`) | Accepted by cPanel |
| T2 | Run `sweep_expiring.php` from the shell/cron | Credentials past expiry flip to **Expired**; a permanent "expired" event is recorded |
| T3 | Run `send_reminders.php` | Reminder emails queued/sent for upcoming expiries; notifications appear |
| T4 | Run `send_digests.php` (with a user opted in) | Activity digest email sent; only on days with activity |
| T5 | Run `screen_leie.php` after importing the LEIE file | Screenings recorded; any match creates a notification |
| T6 | 🔒 Open any cron script directly in a browser | Refused — CLI only |
| T7 | Run each job twice in a row | Idempotent — no duplicate emails or duplicate events |

## Suite U — Platform data & operations

| ID | Steps | Expected |
|----|-------|----------|
| U1 | Console → Platform data → import the **OIG LEIE** file | Import completes with a row count |
| U2 | Import **SAM** and **state Medicaid** exclusion files | Both complete |
| U3 | Run a screening for a provider against the imported data | Result recorded (clear or flagged) |
| U4 | Console → Plans → create/edit a plan and its limits | Saved; visible when assigning to a tenant |
| U5 | Console → Invoices → create, send, record payment, void | Each state change works and is logged |
| U6 | Console → **Email** → configure platform sender, send a test | Test arrives; outbox shows sent |
| U7 | Console → Email → **flush outbox** | Queued messages send; failures show a reason |
| U8 | Console → **Errors** → trigger a harmless error, then view the list | Captured with context; can mark handled; can purge >90 days |
| U9 | Console → **Translations** → edit a Spanish string 🌐 | Applies app-wide immediately |
| U10 | Console → **Database** → add operator notes to a table | Saved and shown |

## Suite V — Platform security & multi-tenancy 🔒 (go-live gate)

| ID | Steps | Expected |
|----|-------|----------|
| V1 | Create two client organizations, each with a provider | Both work independently |
| V2 | 🔒 As Org A's user, take a record id from Org B and try it in the URL | Not found / forbidden — never another organization's data |
| V3 | 🔒 Repeat V2 for a **document** id | Denied |
| V4 | 🔒 As a platform admin (not in a support session), try to open a tenant's provider page directly by URL | Denied — the console is not a data door |
| V5 | 🔒 Run the whole of **Suite K** (support-session boundary) | All pass |
| V6 | 🔒 Confirm platform admins cannot read tenant data from the console anywhere | Usage dashboards show counts only |
| V7 | Console → Security | No red items; warnings understood and accepted |
| V8 | 🔒 Sign in as a platform admin from an unexpected network/browser | Normal auth applies; entry is logged in access logs |
| V9 | Check audit + access logs after V1–V8 | Every platform action attributable to a person, with time and IP |

## Suite W — Performance & scale (optional but recommended)

| ID | Steps | Expected |
|----|-------|----------|
| W1 | Build the demo (5 orgs, ~37 providers, ~200 credentials) | Completes in seconds |
| W2 | Import a CSV of 200+ providers | Completes without timeout; preview stays responsive |
| W3 | Open the calendar for the largest organization | Agenda loads quickly; filters respond |
| W4 | Open the master audit log with 10k+ rows and page through | Pagination is quick; export completes |
| W5 | Note page-load times on the busiest screens | Recorded as a baseline for future comparison |

---

## Sign-off — Part 2 (SaaS platform)

| Suite | Owner | Date | Pass | Fail | Notes |
|-------|-------|------|------|------|-------|
| P Fresh install 🔒 | | | | | |
| Q Upgrade 🔒 | | | | | |
| R Tenant lifecycle | | | | | |
| S Backups & restore 🔒 | | | | | |
| T Cron jobs | | | | | |
| U Platform operations | | | | | |
| V Platform security 🔒 | | | | | |
| W Performance | | | | | |

**Go-live gate (platform):** **P, Q, S and V** must be green. P and Q prove you can install and
upgrade safely, S proves you can recover from a failure, and V proves organizations cannot see each
other and that you as the operator cannot see their data.

## Suite X — Payers, enrollment & compliance levels

| ID | Steps | Expected |
|----|-------|----------|
| X1 | Provider page → *Compliance level* | A level 0–5 with a chip ladder; "to reach the next level" lists concrete gaps |
| X2 | 🔒 Set a provider's PR license expiry in the past | Level drops to **0 — hard stop** regardless of everything else |
| X3 | Record a screening match for a provider | Level 0 — hard stop, with the reason |
| X4 | *Payers & enrollment* → add Triple-S as Participating with a recredentialing date | Row appears; level rises; recredentialing appears on the calendar |
| X5 | Add Medicaid enrollment (enrolled, revalidation date, locations registered) | Level 4 Billable (with NPI); revalidation on the calendar |
| X6 | Uncheck "locations registered in PEP" | Warning "claims deny" shown |
| X7 | Set OCS/SICRO to Attested with an expiry | Level 5; attestation expiry on the calendar |
| X8 | Organization → Payers → add a custom payer | Selectable on provider pages |
| X9 | Organization → Regulations | Sourced citations with effective dates and links |
| X10 | Console → Regulatory → edit a source, set a superseded date | Saved; shows as superseded |
| X11 | Toggle the telehealth flag | Saved; note cites Ley 8-2025 |
| X12 | Provider timeline after X4–X7 | payer / program / OCS status events recorded |

---

## Sign-off — Part 1 (tenant application)

| Suite | Owner | Date | Pass | Fail | Notes |
|-------|-------|------|------|------|-------|
| A Smoke | | | | | |
| B Navigation & hub panel | | | | | |
| C Organization setup | | | | | |
| D Providers | | | | | |
| E Credentials & documents 📱 | | | | | |
| F Verification & compliance levels | | | | | |
| G Payers, programs & OCS | | | | | |
| H Document requests | | | | | |
| I External sharing 🔒 | | | | | |
| J Cross-organization 🔒 | | | | | |
| K Calendar & reports | | | | | |
| L Roles 🔒 | | | | | |
| M Privacy boundary 🔒 | | | | | |
| N Platform console | | | | | |
| O Language, help, edge cases | | | | | |

**Go-live gate (tenant app):** **A, I, J, L, M** must be fully green.
