# FlxCoreHC — Installation

FlxCoreHC deploys as a **single folder** with a built-in web installer. You create an
empty database; the installer checks your server, generates encryption keys, writes
the config, and creates all tables.

## Requirements
- PHP **8.1+** (8.3 recommended) with extensions: `pdo_mysql`, `mbstring`, **`intl`** (REQUIRED since v1.171.10 — Unicode NFKC name comparison; the installer refuses without it and a production install stops at start-up with a clear message), `json`, `fileinfo`
- An encryption backend: **`sodium`** (preferred) **or** **`openssl`** (AES-256-GCM fallback). Either works; if sodium is added later, existing data stays readable.
- MySQL 5.7+ / **MariaDB 10.4+**
- Apache with `.htaccess` / mod_rewrite (standard on cPanel)

## Install (cPanel, ~3 minutes)

1. **Create the database.** In cPanel → *MySQL® Databases*: create a database and a
   user, add the user to the database with **All Privileges**. Note the database name,
   user, and password (cPanel prefixes them with your account name).

2. **Upload & extract.** Upload `flxcorehc-vX_Y_Z.zip` into your web root
   (e.g. `public_html`) and **Extract** it there. The files land directly in the folder —
   there is no wrapper folder to move. You should see `index.php`, `install.php`,
   `app/`, `assets/`, `database/`, etc. at the top level.

3. **Run the installer.** Visit your domain in a browser (e.g. `https://youragency.com/`).
   Because the app isn't configured yet, you're sent to the installer automatically
   (or open `/install.php` directly). It shows a **server requirements** check, then a
   short form:
   - Database host (usually `localhost`)
   - Database name / user / password (from step 1)
   - *Force secure cookies* — leave **on** for any site with SSL
   - Leave *Development mode* **off** for a live site
   Below the database fields, set up your **administrator account** (organization
   name + type, your name, sign-in email, and a password of at least 10 characters).
   Click **Check & install**. The installer generates your encryption keys, writes
   `app/config.php`, creates every table, and creates your administrator account
   (the master sign-in, flagged as platform administrator).

4. **Delete `install.php`.** After it reports success, remove `install.php` from the
   server. (The installer also refuses to run again once configured, but deleting it is
   best practice.)

5. **Sign in.** Open the app and sign in with the administrator email and password
   you set during install. Done.

## Invitations & registration
Users are added by **invitation**: you enter a name, email, and role, and the person
gets a one-time link to set their own password. If SMTP is configured (`mail_enabled`),
the link is emailed; otherwise it is shown on-screen to copy and share. Public sign-up
is **closed by default** (`allow_open_registration = false`) — new organizations are
created by a platform administrator from the admin console (each new owner is invited
the same way). Set `allow_open_registration = true` for open self-service signup.

## Managing your team
Inside each organization, owners and managers see a **Team** section to add users,
set roles (Owner, Manager, Agent, Staff), reset passwords, and enable/disable accounts.
Each user signs in with their own email and password. Team management is strictly
scoped to that organization. "Owner" and "Manager" can manage the team; "Agent" and
"Staff" cannot.

## Branding & organization
Owners/managers can open **Settings** to edit organization info (name, contacts, website,
address), set a **brand color** (applied to buttons, links and highlights, with a live
preview), and upload a **logo** (PNG/JPG/GIF/WebP, max 1 MB) shown in the sidebar. Logos are
stored under the public `uploads/` folder (a hardened .htaccess blocks code execution there);
if that folder is not writable the logo is stored inline in the database instead. Branding is
per-tenant; each teammate still chooses light or dark independently.

## Interactive calendar
The calendar supports adding events (click a day or **+ Add event**), editing and deleting,
and **drag-and-drop** to move an event to another day. Bookings appear on the calendar and
are managed the same way — dragging a booking reschedules it, and complete/cancel act on the
booking. A **List** view with date and status filters complements the month grid.

## Appearance & themes
The UI uses a modern metallic-blue palette (deep navy dark theme, deeper light surfaces so
cards read as distinct areas), rounded fields, and subtle entrance/hover motion. Content is
left-aligned and uses wider multi-column layouts. Each user toggles light/dark; each tenant
sets its own brand color and logo.

## Appearance details
The interface uses a modern themeable design system. Each user can switch **light/dark**
from the top bar or profile menu (remembered per browser). Per-tenant brand color and
logo, plus an organization-settings area, arrive in the branding phase. The top bar holds
the profile menu and sign-out on the right; the sidebar collapses to icons (with tooltips)
and becomes a drawer on mobile.

## SaaS administrator account
The **first account created by the installer is the SaaS Admin** (platform operator).
It administers the whole system and signs in to the **admin console** (not a customer
dashboard). The installer asks for the password twice to confirm it. You can invite
additional SaaS administrators from the admin console (**SaaS administrators** panel);
they receive an invite link to set their own password.

## Trials & read-only
New organizations you provision start a **free trial** (`trial_days`, default 14). When a
trial ends, or a subscription is canceled or stays **past due** beyond the grace window
(`grace_days`, default 7), the account becomes **read-only**: users can still sign in and
view data, but creating/editing is blocked until billing is resolved. A banner shows the
trial countdown or the action needed, and owners can always reach the Billing page.
Billing is only enforced when Stripe is configured. Checkout runs **in-app** (Stripe
embedded Checkout using your publishable key); the redirect flow remains as a fallback.

## Billing (Stripe)
To charge for plans, add `stripe_secret_key`, `stripe_publishable_key`, and
`stripe_webhook_secret` to `app/config.php`, set `app_url`, put each plan's **Stripe
Price ID** on the plan (Manage plans), and point a Stripe webhook at
`https://yourdomain/…/webhooks/stripe` (events: checkout.session.completed,
customer.subscription.updated/deleted, invoice.payment_failed). Owners then see a
**Billing** page to choose a plan (Stripe Checkout) or manage their subscription
(billing portal); the webhook keeps each tenant's plan and status in sync. Usage caps
(seats, holders, policies) are enforced per plan regardless of billing.

## Plans & features
The platform admin defines **plans** (Platform admin → Manage plans): each sets a seat
limit and which features are included (book of business, requirements, RTS, scheduling,
network, analytics). Assign a plan from an organization's page; unassigned orgs use the
default plan. Seat limits are enforced when inviting users, and features not in a plan
are hidden and blocked. Core areas (dashboard, calendar, holders, credentials, compliance,
team, profile) are always available.

## Timezones, reminders & calendar files
Each booking link has a **business timezone**; availability is set in that zone and
clients see open times in **their own** timezone (with a UTC fallback if JavaScript is
off). Confirmations and a downloadable **.ics** calendar file are offered on the manage
page; when email is enabled (`mail_enabled`, `mail_from`), invitees also get a
confirmation email with the .ics attached. Run the reminder sweep hourly from cron to
send ~24h and ~1h reminders:

    php /path/to/cron/send_booking_reminders.php

## Revenue overview
Platform admin → **Revenue** shows MRR/ARR, counts by subscription status (active,
trialing, past due, canceled), how many accounts are currently read-only, revenue by
plan, and an attention list of trials ending within 7 days and past-due accounts.

## Platform administration
The account created during install is the **platform administrator**. Sign in and open
**Platform admin** in the sidebar to manage every organization on the instance —
usage metrics, suspend/reactivate organizations, enable/disable user accounts, and
grant/revoke admin. It shows account-level data and counts only; it never exposes a
tenant's encrypted client records. You can promote additional admins from a user's
organization page.

## Security notes
- The installer writes `app/config.php` (DB credentials + encryption keys) and sets it
  to `0600`. `app/`, `database/`, `cron/`, and `tools/` are blocked from the web by the
  root `.htaccess` **and** a `Require all denied` file in each — a misconfigured docroot
  still won't expose them.
- **Production is fail-closed:** with `app_env=production` (the installer's default) the
  app refuses to store sensitive fields unless encryption keys are present, and never
  prints stack traces to the browser.
- For maximum isolation you *may* point the docroot at a subfolder and keep `app/` above
  it, but it isn't required — the flat layout is safe on Apache/cPanel as shipped.

## Optional
- **Demo data:** from a shell (or cron "one-off"), run
  `php database/seed_demo.php` to load sample tenants. Skip for a clean production install.
- **Cron (reminders / expiry sweep):** point cron at
  `php /home/USER/public_html/cron/sweep_expiring.php` and `.../cron/send_reminders.php`.

## Manual install (no web installer)
Copy `app/config.sample.php` to `app/config.php`, fill in DB creds, generate keys with
`php database/generate_keys.php` (paste them into the config), then import in this order
via phpMyAdmin: `database/schema.sql`, then `migrations/003, 004, 099, 005, 006, 007,
008, 009, 010, 011`. (`002` is folded into `schema.sql`; `098` is an upgrade-only,
CLI-only step and a no-op on fresh installs.)

## Upgrading an existing (pre-encryption) install
Generate keys, add them to `app/config.php`, then run `php database/migrations/098_encrypt_existing.php`
(encrypts existing plaintext PHI) **before** `099_drop_plaintext.sql`.

### Upgrade notes by release

Every release note lives in **docs/CHANGELOG.md** (newest first). What an operator must *do* when upgrading:

- **v1.172.0** — apply the updater (migration 133). The platform console's two-factor requirement becomes a setting (Console → Security → Sign-in policy): an installation that already had a platform administrator using two-factor keeps it ON; a new one starts OFF. To offer e-mailed sign-in codes and "Forgot your password?", outbound e-mail must work (Console → Email). If the app is installed in a subfolder, make sure `app_url` in `app/config.php` includes the subfolder: the links in e-mails are built from it.
- **v1.171.10** — apply the updater (no new migration). **Check that PHP `intl` is enabled** (cPanel → Select PHP Version → intl); a production install without it now refuses to start.
- **v1.171.9** — apply the updater (no new migration). Nothing to run by hand.
- **v1.171.8** — apply the updater (no new migration). Nothing to run by hand.
- **v1.171.7** — apply the updater (no new migration). Nothing to run by hand.
- **v1.171.6** — apply the updater (no new migration). If you edited provider records between v1.171.0 and v1.171.5 through an unusual route (scripts posting to sub-URLs), check them — the old handler could blank name/NPI/e-mail.
- **v1.171.5** — apply the updater (no new migration). Nothing to run by hand. If you have installed since v1.171.4, the global PR catalog notes are already corrected by migration 132 and will no longer be reverted by new signups.
- **v1.171.4** — apply the updater (migration 132). If you ran `encrypt_existing_addresses.php` from v1.171.3 on rows that had only some address fields migrated, re-check those rows against your backup (the v1.171.4 script cannot overwrite ciphertext, but the old one could). Re-run it without the flag.
- **v1.171.3** — apply the updater (migrations 130 and 131). Then run `php database/encrypt_existing_addresses.php --dry-run` and again without the flag (take a backup first). Pending team invitations keep working; new ones are stored hashed.
- **v1.171.2** — apply the updater (migrations 128 and 129). Tenants with custom SMTP on a port other than 25/465/587/2525 must change it.
- **v1.171.1** — apply the updater (migrations 126 and 127). A tenant with no `operating_states` sees only national items until a jurisdiction is chosen under Organization → Lines & states.
- **v1.171.0** — apply the updater; run `php database/encrypt_existing_files.php --dry-run` and again without the flag to seal existing uploads (take a backup first); download the key backup from Console → System.
- **Earlier** — see docs/CHANGELOG.md.

## CMS data sync (optional)
### How a sync changes the table (reconciliation)

A sync never blindly wipes the catalog. Each run **upserts** — it updates plans already on file
and adds newly-downloaded ones (matched on carrier + plan name + year). What happens to plans CMS
has *dropped* from the file is set per source:

- **Reconcile (default):** dropped plans (same year + state, CMS-loaded only) are marked inactive
  so they leave the agent picker, but stay for history.
- **Replace:** dropped plans are hard-deleted, giving a clean mirror of the latest file.
- **Append:** nothing is ever removed.

In every mode, only rows that were loaded *from CMS* are affected — plans you added by hand or that
came from the seed/CSV import are never touched — and reconciliation is scoped to the exact year +
state you synced, so it can't disturb other years. It runs only after a file parses successfully, so
a failed download can't corrupt the table.


FlxCoreHC can pull carrier plan names directly from CMS Medicare Advantage / Part D **Landscape
source files** into the plan catalog.

1. In the app: **Admin → CMS sync → Add source**. Set the plan year and paste the https:// URL of
   the CMS landscape CSV/ZIP (from cms.gov → Medicare → Prescription Drug Coverage → downloads).
2. Click **Sync now** to load it immediately, or automate with a cPanel cron job:

       /usr/local/bin/php /home/CPANELUSER/flxcorehc/cron/cms_sync.php

   Schedule weekly (or daily during Sept–Dec). The job upserts, so re-running never duplicates,
   and it picks up a new plan year as soon as you add that year's source.

**NPI lookup:** the holder form's "Look up" button validates an NPI against the CMS NPPES registry
and caches results. **Requirements:** the server must allow outbound HTTPS (cURL or
allow_url_fopen) to `cms.gov` and `npiregistry.cms.hhs.gov`. On most cPanel hosts this is on by
default; if lookups fail, ask your host to allow outbound HTTPS from PHP.


## Third-party assets

Interface icons are from **Tabler Icons** by Paweł Kuna, used under the MIT license.
The full license text ships in `assets/vendor/tabler-icons-LICENSE.txt`.
Icon geometry is embedded directly in the interface; there is no external request,
CDN dependency, or build step involved.
