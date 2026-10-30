# ITOps — development plan

Owner: Indisse-BTS · Written 10/30/2026 at build 0001-A002 · Companion to `NEW-APP-SPEC.md` (the spec says *what*;
this plan says *in which order*, with the suggestions found while building 0001-A001 and 0001-A002).

Sizes: **S** = one short build, **M** = one full build, **L** = several builds. Every build follows CLAUDE.md "Each
build" (build id, CHANGELOG, tests, package, fresh install from the zip at a web root).

## Where the app stands (measured at 0001-A002)

| Area | Today |
| --- | --- |
| Screens | `src/app.jsx`: one file, 20,331 lines, 2.9 MB. 16 removed modules still in it (about 45 page switches, hidden and refused) |
| Server | 132 library files (39,759 lines), 47 routes. 15 library files nothing loads (STARTER-REPORT.md) |
| Database | 154 tables after a fresh install; about **68 belong to removed modules**. 121 update steps, run in full on every fresh install |
| Microsoft connection | One tenant: settings `o365_tenant_id`, `o365_client_id`, encrypted secret. 14 files read them. The token cache is already keyed by tenant + app (`Graph.php`) |
| Tests | `dev/check-app.mjs`: 15 smoke checks. Nothing tests a Microsoft 365 page without a real tenant |
| Package | `dev/package.sh` → full install zip, extracted at the web root; tested under Apache with `.htaccess` |

## Phase A — Clean foundation (before any feature)

Goal: the code holds only this product, so every later change is smaller and safer.

| Build | Work | Size | Done when |
| --- | --- | --- | --- |
| A003 ✅ | **Prune the screens** (spec step 2) — *done 10/30/2026: `app.jsx` 20,331 → 10,815 lines, 31 pages removed, Help Center 233 → 97 topics, check-app 27 checks*: page switches, menu groups, titles, emoji, Settings cards of removed modules (help-desk mailbox, SLA, vTiger, servers…), onboarding fields for other systems, Help Center topics, the top search's placeholder. The Roles editor lists only this product's permissions | L | `check-app` passes; a search for each removed name finds nothing in `src/`; the Roles editor shows only product permissions |
| A004 ✅ | **Prune the server** (spec step 3) — *done 10/30/2026: 183 → 134 server files, ~51,000 → ~31,500 lines, one scheduled job, Microsoft-only onboarding engine; the libraries only the old database updates load move to A005*: the 15 unused library files, email templates and cron steps of removed modules, the content library (KB articles and SOPs); **carried over from A003:** the onboarding engine's vTiger task types (`ObTasks.php`), the vTiger match on a person and the Reconcile columns (`M365Hub.php`), the Help visual files (`api/lib/visuals/`), the email-intake cron (`cron-mail.php`), comments the reference app left beside code that is now removed | M | `php -l`, `check-app`, every cron job runs; a search for each removed function finds nothing |
| A005 | **Baseline database (suggestion).** Also deletes the libraries only the old updates still load (Categories, DbInsight, ErrLogs, IntegrationAudit, JotformForms, Mon360, NetWatch, PbxCdr, Signatures, StandardsSetup and what they load) and trims DataDictCatalog, SampleData, RecycleBin and Modules of the removed modules. No installation exists, so replace the 121 update steps with one clean `schema.sql` holding only ITOps tables (about 86), and start the update list empty at `2026_11_..._0001-A005_baseline`. It removes the 68 dead tables, the encoded content-library step that broke the prefix rename, and most install time | M | Fresh install = baseline; `db-update.php` reports nothing to do; data dictionary lists only ITOps tables |
| A006 | **Known items and hardening:** (1) Microsoft 365 not connected → 409 plus a "Connect Microsoft 365" panel instead of 500 (`m365-defender`, `m365-service-health`, `m365-storage`); (2) `O365.php` (email sending and mailbox reading) uses the cached token like `Graph.php`; (3) **the installer must stop and show the error when a database update fails**. In 0001-A002 testing it printed "Installation Complete" with 120 of 121 steps not applied; (4) installer writes `config.php` with mode 640 (today 644); (5) installer option "force HTTPS" that turns on the rule already in `.htaccess`; (6) rewrite `READ-ME-FIRST.txt` for ITOps (no vTiger, no reference-app texts) | M | A test per item: 409 + panel, token reuse, a failing update stops the installer, file mode, HTTPS redirect |

## Phase B — Make change safe (tooling)

| Build | Work | Size | Done when |
| --- | --- | --- | --- |
| B1 | **Fake Microsoft Graph for tests (suggestion, high value).** A small dev-only server (`dev/fake-graph/`) that answers the Graph, Exchange admin and login calls the app makes with recorded, made-up data (example.com). The app points to it with a dev-only setting. Then every Microsoft 365 page, onboarding and offboarding and the security assessment can be tested with no tenant and no secret | M | `check-app` connects to the fake tenant and opens every Microsoft 365 page with data, no 4xx/5xx |
| B2 | **Feature tests:** one test file per area (accounts, licenses, onboarding run, offboarding, posture findings, approvals): server answers, refusals for each role, Audit Log entries with before/after | M, then grows | Each area has tests; counts recorded in APP-AUDIT.md |
| B3 | **Continuous checks on GitHub:** on every push, `php -l`, the build is reproducible (rebuilt `app.js` equals the committed one), `check-app` with a MariaDB service, the package builds | S | Every push shows a green or red check |
| B4 | **Split `app.jsx` (after A003):** one file per area under `src/pages/` that `build.js` joins in a fixed order. No bundler, same output. Smaller diffs, easier reviews | M | Byte-identical `app.js` before and after the split |

## Phase C — ITOps's own ecosystem (spec steps 5–7)

| Build | Work | Size |
| --- | --- | --- |
| C1 | **License Hub** for ITOps on the owner's domain (`install/hub.php`); its public keys into `License.php` / `Updates.php`; price list for Complete and Essentials | M |
| C2 | **Teams app identity:** ITOps's own Teams app id, names and icons (none shared with the reference app) | S |
| C3 | **Documents:** `docs/APP-AUDIT.md`, Help Center for this product only (incl. new quick starts — the three inherited ones describe tickets), administrator guide, Microsoft 365 / Azure setup with the permission list from `M365Permissions.php` | M |
| C4 | **First installation** from the package; link, license, check-in, connect a test tenant, walk every page | S |

## Phase D — Several tenants (owner decision 10/30/2026: wanted)

Why now: no installation exists, so the tenant can be put into the data model **before** features and data pile up.
Doing it after the first installations means moving their data. Recommended right after Phase A (and B1, so it can be
tested with two fake tenants).

**Design proposal (for the owner to confirm before D1):**

- `itops_tenants`: name, Microsoft tenant id, app (client) id, encrypted secret, status, colour/label, default flag. The
  current single connection becomes tenant 1 on update.
- One way in: `itops_graph_cfg($tenant)` / `itops_graph_token($tenant)`. The 14 files that read the settings today go
  through it. The token cache is already keyed per tenant + app, so it needs no change.
- Every Microsoft-derived record gets `tenant_id`: snapshots, findings and decisions, onboarding and offboarding runs and
  templates, risky-people follow-ups, approvals, scheduled jobs, notifications. Audit Log entries name the tenant.
- Access: a user ↔ tenant table; Super Admin sees all. The server checks the tenant on every request (header or session
  value), never the screen alone.
- Screens: a tenant picker in the top bar, an "All tenants" home with "needs attention" per tenant, the tenant shown on
  every page that changes something.
- Scheduled jobs loop over the active tenants, one failing tenant never stops the others.

| Build | Work | Size |
| --- | --- | --- |
| D1 | Data model + tenant 1 from the current connection; every read and write takes the tenant (still one in use). No visible change | L |
| D2 | Add / connect / disconnect tenants (admin consent link, permission check per tenant); tenant picker; per-tenant access | M |
| D3 | All Microsoft 365 pages, onboarding/offboarding, security assessment and risky people per tenant; jobs per tenant | L |
| D4 | "All tenants" overview, cross-tenant reports (licenses, Secure Score, risky people, needs attention) | M |
| D5 | Licensing per tenant (see decision 2) | S–M |

## Phase E — Features (Microsoft only; order is the owner's)

Suggested order, highest value to an IT team first:

1. **Connect-tenant wizard and permission checker:** admin consent link, then compare the permissions Microsoft
   granted with the catalog in `M365Permissions.php` and show what is missing and which pages it affects. Needed by D2
   anyway, and the most common support question
2. **License savings report:** unused and duplicate licenses, cost per month, reclaim with approval (builds on
   Licenses → reclaim / reconcile)
3. **SharePoint / OneDrive external sharing:** who shares what with whom, remove links, with Audit Log entries
4. **Scheduled reports** by email and to a Teams channel (posture, licenses, risky people), per tenant
5. **Copilot** as its own area: license holders, usage, reclaim
6. **Exchange gaps** (full access / send-as, forwarding, conversions) — needs decision 3
7. **Entra ID P2 features** when the tenant has them: risk policies, access reviews, eligible roles
8. **Bookings, Planner, Teams call quality, Intune apps and Autopilot**

## Decisions for the owner

| # | Decision | Recommendation |
| --- | --- | --- |
| 1 | Replace the 121 update steps with one clean baseline (A005) | Yes — no installation exists yet |
| 2 | Licensing with several tenants: per installation, per tenant, or per managed user | Per tenant (simple to count and to explain) |
| 3 | How tenants are connected: an app registration per tenant (what exists), one multi-tenant app with admin consent, or Microsoft partner access (GDAP) for MSPs | Per-tenant registration first; multi-tenant app with consent in D2; GDAP later if sold to MSPs |
| 4 | A PowerShell runner for the Exchange tasks Graph cannot do | Decide before Phase E item 6 |
| 5 | Order of Phase E | As listed |

## Order at a glance

A003 → A004 → A005 → A006 → B1 → B3 → **D1 → D2 → D3** → C1 → C2 → B2 (ongoing) → B4 → D4 → D5 → C3 → C4 →
Phase E. C1 can move earlier if a first customer installation is planned sooner.
