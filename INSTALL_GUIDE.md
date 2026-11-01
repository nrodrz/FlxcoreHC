# FlxCoreHC Healthcare — Installation Guide

This guide covers a first installation on cPanel / HostDime shared hosting (the target environment):
database setup, uploading the app, running the web installer, post-install security, and optional
scheduled jobs. No Composer, shell access, or build step is required.

---

## 1. Requirements

The web installer checks all of these for you on its first screen and will not proceed until the
required ones pass.

| Requirement | Needed | Notes |
|---|---|---|
| PHP | 8.1 or newer (8.3 recommended) | Set in cPanel → *MultiPHP Manager* / *Select PHP Version* |
| PHP extension `pdo_mysql` | Required | MySQL/MariaDB access |
| PHP extension `mbstring` | Required | Text handling |
| PHP extension `json` | Required | Data encoding |
| PHP extension `fileinfo` | Required | Upload validation |
| Encryption backend | Required | `sodium` (preferred) **or** `openssl` (AES-256-GCM fallback). At least one. |
| MySQL / MariaDB | Required | MariaDB 10.4+ / MySQL 5.7+ |
| `app/` directory writable | Required | Installer writes `app/config.php` here |
| `app/storage/` writable | Recommended | Document uploads |

In cPanel → *Select PHP Version* → *Extensions*, make sure `pdo_mysql`, `mbstring`, `json`,
`fileinfo`, and `sodium` (or `openssl`) are enabled.

---

## 2. Create the database

In cPanel:

1. **MySQL® Databases** → *Create New Database*. Give it a name; cPanel prefixes it with your account
   (e.g. `cpaneluser_flxcred`). Note the full name.
2. **Add New User** — create a database user with a strong password (e.g. `cpaneluser_flx`). Note the
   full username and password.
3. **Add User To Database** — attach the user to the database and grant **ALL PRIVILEGES**.

You do **not** need to import any SQL by hand — the installer creates every table. (If you prefer to
create tables manually, the ordered migration files are in `database/migrations/` and the load order
is `database/manifest.php`; run them in that order. The installer is the supported path.)

---

## 3. Upload the app

1. Unzip the release (`flxcred-healthcare-vX_Y_Z.zip`) locally.
2. Upload the **contents** to the directory you want to serve:
   - Web root (`public_html`) to serve at `https://yourdomain.com`, **or**
   - a subfolder (e.g. `public_html/flxcred`) to serve at `https://yourdomain.com/flxcred` — the app
     detects its own base path, so no edits are needed either way.
3. Confirm the bundled `.htaccess` files came across (they may be hidden): one at the app root and one
   in `app/`. These route requests and block direct web access to server-side folders.

**File permissions:** directories `755`, files `644`. Ensure `app/` and `app/storage/` (and
`app/storage/docs/`) are writable by PHP (usually `755` is enough on cPanel; use `775` only if your
host runs PHP as a separate user).

---

## 4. Run the web installer

1. Visit `https://yourdomain.com/install.php` (or `.../flxcred/install.php`).
2. **Requirements screen** — resolve anything marked required (red) before continuing. Optional items
   (yellow) are safe to proceed past.
3. **Configuration form** — enter:
   - **Database host** — usually `localhost` on cPanel.
   - **Database name** — the full name from step 2 (e.g. `cpaneluser_flxcred`).
   - **Database user / password** — from step 2.
   - **Organization name** — the name of *your* SaaS operation. Its type is fixed as **SaaS platform
     (operator)**; you'll create client organizations (billing companies, medical groups, hospitals,
     solo practices) later from the console, each with its own type.
   - **Administrator name / email / password** — this becomes the first **Master Admin** and the
     platform administrator. Use a strong password.
   - **Force secure cookies** — leave on if the site is served over HTTPS (recommended).
4. Submit. The installer will:
   - generate fresh encryption keys (256-bit) for data-at-rest and blind-index fields,
   - write `app/config.php` with your database credentials and those keys,
   - create **all tables** (every migration in the manifest), and
   - create your administrator account and activate the first organization.

If the database credentials are wrong, the installer rolls back the partial config so you can correct
and retry cleanly.

---

## 5. Post-install security (important)

1. **Delete `install.php`** from the server. The installer refuses to run twice, but removing the file
   is the clean step and the final screen reminds you. (`app/config.php` also acts as an install lock.)
2. **Back up `app/config.php`** somewhere safe and keep it out of version control. It contains your
   database credentials **and your encryption keys** — if the keys are lost, encrypted fields
   (provider email/NPI) cannot be decrypted. Do not regenerate keys on an existing database.
3. Confirm `https://yourdomain.com/app/config.php` returns **403/Forbidden** (the `.htaccess` in `app/`
   blocks it). If it does not, your host may not honor `.htaccess` — move `app/` outside the web root
   or contact the host.
4. Serve the site over **HTTPS** and keep `force_secure_cookies` / HSTS on.

Then sign in at `https://yourdomain.com/` with the administrator email and password you set.

---

## 6. `app/config.php` reference

The installer writes this file; you can edit it afterward. Key settings:

| Key | Default | Purpose |
|---|---|---|
| `db_host`, `db_name`, `db_user`, `db_pass` | from installer | Database connection |
| `crypto_data_key`, `crypto_index_key` | generated | **Encryption keys — never change on a live DB** |
| `app_env` | `production` | `production` hardens error output |
| `app_url` | your domain | Absolute links in emails |
| `force_secure_cookies`, `hsts` | on | HTTPS hardening |
| `mail_enabled` | `false` | Turn on to send invite/reminder email (configure your mail transport) |
| `mail_from` | `no-reply@yourdomain` | From address for outbound mail |
| `allow_open_registration` | `false` | Keep off unless you want public self-signup |
| `reminder_thresholds` | `[60, 30, 14, 7]` | Days before expiry at which reminders go out (optional; this is the default) |
| `expiring_window_days` | `90` | "Expiring soon" window |
| `trial_days`, `grace_days` | `14`, `7` | Billing trial / grace |
| `stripe_*` | empty | Optional Stripe billing keys |
| `producer_seats_billable` | `true` | Set `false` to make provider-portal logins free |
| `fonts` | `self` | `self` (bundled) / `system` / `google` |

Email is **off by default** so a fresh install never fails trying to send mail. Two layers exist:
the platform sender above (used as the default), and **per-tenant delivery** — each organization's
Master Admin can configure their own mailbox under **Organization → Email delivery** (Google
Workspace, Microsoft 365, or any SMTP provider, with a test-send verification). Tenant-configured
delivery works even while platform mail is off; unconfigured tenants use the platform sender when
`mail_enabled` is on.

---

## 7. Optional: scheduled jobs (cron)

Three maintenance scripts live in `cron/` (all CLI-only; they refuse to run over the web). Add them in
cPanel → *Cron Jobs* using your account's PHP binary. Typical schedules:

```
# Exclusion screening sweep (OIG LEIE / SAM.gov / state Medicaid) — daily 2:00 AM
0 2 * * *  /usr/local/bin/php /home/cpaneluser/public_html/cron/screen_leie.php

# Expiry reminder emails — daily 6:00 AM
0 6 * * *  /usr/local/bin/php /home/cpaneluser/public_html/cron/send_reminders.php

# Expiring-credential status sweep — daily 1:00 AM
0 1 * * *  /usr/local/bin/php /home/cpaneluser/public_html/cron/sweep_expiring.php
```

Adjust the PHP path and app path to your account. (Find the PHP CLI path in cPanel → *Select PHP
Version*, or ask your host.) These are optional — the app works without them; they just automate
recurring screening and reminders.

---

## 8. First steps after signing in

1. You are the **Master Admin** of the first organization and the platform administrator.
2. Create your first real tenant organizations from the platform console (**/admin**), or start
   adding providers directly if this account is the operating organization.
3. Add **team members** (Team) and set each one's role and provider access (all vs. assigned).
4. Optionally define **Locations** (practice sites) and **Groups** (client groups / departments), then
   affiliate providers with them.
5. Add providers, attach credentials, and upload documents.

---

## 9. Troubleshooting

- **Installer shows a required item red** — enable the missing PHP extension or raise the PHP version
  in cPanel, then reload.
- **"Could not write app/config.php"** — make the `app/` directory writable (`755`) and retry.
- **Database connection failed** — re-check host (`localhost`), the full prefixed DB name, and that
  the user is attached to the database with ALL PRIVILEGES.
- **Pages 404 except the home page** — `mod_rewrite` isn't active or `.htaccess` wasn't uploaded;
  confirm the root `.htaccess` is present and that the host allows `AllowOverride`.
- **`app/config.php` is publicly readable** — your host isn't honoring `.htaccess`; move `app/` out of
  the web root or contact the host.
- **Uploads fail** — make `app/storage/docs/` writable.

For per-release changes and the feature changelog, see `INSTALL.md`.
