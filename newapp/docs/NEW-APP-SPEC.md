# ITOps — specification and plan (Microsoft services only)

Owner: Indisse-BTS · Started 10/30/2026 from the reference app's build 0621-BT169 · Product name: ITOps (decided 10/30/2026)

## 1. What this app is

A white-label app for an organization's IT team to run its **Microsoft 365 tenant without going to Microsoft's portals**:
people (create, change, onboard, offboard), email, devices, licenses and subscriptions, security, the tenant's security
posture, risky people, collaboration and activity — with the platform every app of the owner shares (licensing through
its own License Hub, signed updates, roles and permissions, Audit Log, data dictionary, Deleted items, Terms of Use,
About & ownership, the Teams app). Sold per organization (own installation and database), never as shared SaaS.

## 2. What the starter has

**Platform (always on):** sign-in (local, Microsoft sign-in, two-factor), users and roles, settings and branding,
Audit Log, Event Log, scheduled jobs, Help Center, About & ownership, Terms of Use, licensing (trial, plans, modules,
License Hub link and check-in), updates (signed bundles, backup and way back), data dictionary, Deleted items, API keys,
notifications (bell, email).

**Modules of this product** (`opsh_product_modules()` in `api/lib/Modules.php`):

| Module | Pages and tabs |
| --- | --- |
| Microsoft 365 (`m365`) | Home (needs attention, overview, service health, rollout, diagnose) · People (accounts, groups, guests, MFA, at risk, history) · Email (mailboxes, distribution lists, message trace, email security) · Devices (inventory, compliance, apps, configuration, update rings, device policies, wipe & retire) · Licenses (licenses, subscriptions & contracts, reclaim, reconcile, coverage) · Security (alerts & actions, Secure Score, access & sign-in, app secrets, enterprise apps, Conditional Access, admin roles, recommendations) · Collaboration & activity (Teams, storage, Copilot, audit log) |
| Onboarding & offboarding (`onboarding`) | Templates; onboard (account, manager, licenses, groups); offboard (block, revoke, licenses, groups, auto-reply, forwarding, OneDrive handover, scheduled for the last day) |
| Security assessment (`security_assessment`) | Posture: findings (57 rules, three baselines), plan and decisions, drift, executive report, house baseline, Microsoft's recommended actions, history; Risky people (own score, Microsoft's list with Entra ID P2, follow-up, approvals) |
| Teams app (`teams_app`) | The app as a Teams tab with automatic sign-in; notifications to the Teams activity feed |

Plans: **Complete** (all four) and **Essentials** (without the security assessment). Change them in `opsh_plans()`.

**Removed** (always off, API routes and public pages deleted): Service Desk, Cases & Projects, Calendar, Scorecard,
Standards & Audits, Announcements, Knowledge Base, SOPs & Job Descriptions, Equipment, Credentials, Contracts & Vendors,
vTiger, Company systems, Servers, IT Budget, Integration Audit. Their screens, libraries and tables are still in the code
as dead weight (STARTER-REPORT.md lists where); step 2 and 3 remove them.

Microsoft permissions: the app lists every Graph / Exchange / Azure permission it uses, why and whether it is optional,
in Settings → Company systems → Microsoft 365 → Permissions (`api/lib/M365Permissions.php`). Keep it current with every
feature. Entra ID P2 is needed for Microsoft's risky-users list, risk-based Conditional Access and eligible admin roles;
the app says so where it applies.

## 3. Steps (one build each, in this order)

**Step 0 — run it.** Build the screens, `dev/fresh-install.sh`, `dev/check-app.mjs` (13 checks pass on the starter as
delivered). Open it, connect a test tenant in Settings (the administrator types the app registration's secret in the
app), and walk through each Microsoft 365 page.

**Step 1 — identity (owner decisions first).**
- Product name and product line: `OPSH_PRODUCT`, `OPSH_PRODUCT_LINE` (Brand.php), page titles, installer defaults, the
  `<Product>-LicenseHub/<build>` User-Agent, LICENSE.txt and the About texts (they use the constant). The developed-by
  sentence names IT operations experience; the owner may want a Microsoft-administration wording.
- Table / code prefix: keep `opsh_` (easier to port fixes from the reference app, allowed by the standards) or rename
  now, before any installation exists (a scripted rename of tables, settings keys, functions, headers; no data to move).
  Recommendation: keep it until the app has diverged; decide before the first real installation either way.
- Build series: `0001-A001` …

**Step 2 — prune the screens.** `src/app.jsx` still holds the screens of the removed modules (each already hidden and
refused). Remove them component by component with `STARTER-REPORT.md` as the list: the page switches, the menu groups
and items (NAVGROUPS), titles (TITLES), emoji, page aliases, dashboard tiles of other modules, the top search's
placeholder ("Search tickets, KB, SOPs…" → what this app searches), Settings tabs and cards that configure removed
modules (help-desk mailbox, ticket email, SLA & hours, vTiger, company systems, server monitoring…), the onboarding
template fields for vTiger and other systems, and the Help Center topics (`guide.js`) of removed modules. After each
removal: build, `check-app.mjs`, and a search for the removed names. The Roles editor must list only this product's
permissions (01-PRODUCT-STANDARD §15).

**Step 3 — prune the server and the database.** Delete the library files only removed modules use (start with the list
in STARTER-REPORT.md; check each with a search for its functions), the email templates of removed modules, the cron
steps of removed modules (`cron-sla.php`, `cron-uptime.php`: keep the Microsoft and platform steps such as scheduled
offboarding, approvals lapse, Deleted items clean-up). Because no installation exists yet, the tables of removed modules
can be taken out of `sql/schema.sql`, `seed.sql`, `sample.sql`, `content-library.sql` and the data-dictionary catalog
directly; keep the migration list consistent so a fresh install and `db-update.php` reach the same structure. Sample
data: only Microsoft-related made-up records.

**Step 4 — known items from the reference app.**
- With Microsoft 365 not connected, some reads answer HTTP 500 with "Microsoft 365 is not connected…"
  (`m365hub/m365-defender`, `m365-service-health`, `m365-storage`). They should answer 409 with that message, and the
  pages should show a "connect Microsoft 365" panel. (Reported back to the reference app as well.)
- Email sending and mailbox reading through Microsoft 365 (`api/lib/O365.php`) get a new token per run; give them the
  cached token like `Graph.php`.

**Step 5 — this app's own License Hub.** Install the package on the owner's domain with `install/hub.php` (HTTPS address,
organization Indisse-BTS, backup passphrase). Keep both key backups and the passphrase in two safe places. Put the two
public key lines it shows into `api/lib/License.php` and `api/lib/Updates.php` (the reference app's lines were removed
on purpose: each app is its own ecosystem). Set the price list for Complete and Essentials. From then on, follow the
keyless-twin rule for tests (03 §5).

**Step 6 — documents.** `docs/APP-AUDIT.md` (architecture, data model, every build's technical entry), the Help Center
for this product only, and the support pack (Administrator guide, User guide, Microsoft 365 / Azure setup with the
permission list, Teams app, Intune & Defender rollout — `docs/reference/` has the reference app's notes to start from).

**Step 7 — first installation.** Fresh install from the package, link to the License Hub with a link code, issue the
license, check in, connect the tenant, walk through every page.

## 4. Feature roadmap after the starter (Microsoft only; owner decides the order)

- **Collaboration and sharing:** SharePoint / OneDrive external sharing (who shares what with whom, remove links),
  Teams owners and guests, unified audit log beyond 180 days.
- **Bookings, Planner, Teams call quality** (headset, network), Intune apps and Autopilot.
- **Exchange:** what the preview admin API cannot do (full access / send-as, forwarding changes, conversion, list
  membership) — needs a decision on a PowerShell runner.
- **Defender for Office 365** preset policies; **Entra ID P2** features when the tenant has it: risk policies, access
  reviews, eligible roles everywhere.
- **Multi-tenant:** one installation managing several tenants (owner decision: it changes licensing and data
  separation).
- **Copilot:** license holders, usage and reclaim (in Collaboration today) as its own area.

## 5. Keeping in step with the reference app

The Microsoft code started as a copy of the reference app's. A fix there that touches the same files is ported here by
hand (never by copying whole files), noted in CHANGELOG.txt and APP-AUDIT.md with the reference build it came from. To
start over from a newer reference build instead: `python3 dev/make-ms-app.py --src <Full-Install.zip> --out <folder>`
and carry this app's own changes forward (only sensible before step 2).

## 6. Decisions for the owner

| # | Decision | Recommendation |
| --- | --- | --- |
| 1 | Product name and line | **Decided:** ITOps; product line stays "Microsoft 365 Administration" (0001-A002) |
| 2 | Keep the `opsh_` prefix or rename before the first installation | **Decided:** renamed to `itops_` (0001-A002) |
| 3 | Plans and prices (Complete, Essentials, per-user pricing) | **Decided:** leave as is for now (Complete and Essentials) |
| 4 | Keep announcements to Teams (the reference app's Announcements module) in this product | **Decided:** left out |
| 5 | Multi-tenant management | **Decided:** wanted. Needs its own design (tenant table, per-tenant Microsoft connection and tokens, tenant picker, data separation, permissions per tenant, licensing per tenant) before it is built |
