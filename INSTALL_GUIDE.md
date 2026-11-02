# FlxCoreHC Healthcare — Installation Guide

This guide covers a first installation on cPanel shared hosting (the target environment):
database setup, uploading the app, running the web installer, post-install security, the first things to
set up inside the app, and the scheduled jobs. No Composer, shell access, or build step is required.

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
| PHP extension `intl` | Required | Unicode name comparison. A production install refuses to run without it — enable it in *Select PHP Version* → *Extensions* |
| Encryption backend | Required | `sodium` (preferred) **or** `openssl` (AES-256-GCM fallback). At least one. |
| MySQL / MariaDB | Required | MariaDB 10.4+ / MySQL 5.7+ |
| `app/` directory writable | Required | Installer writes `app/config.php` here |
| `app/storage/` writable | Recommended | Document uploads |

In cPanel → *Select PHP Version* → *Extensions*, make sure `pdo_mysql`, `mbstring`, `json`,
`fileinfo`, `intl`, and `sodium` (or `openssl`) are enabled.

---

## 2. Create the database

In cPanel:

1. **MySQL® Databases** → *Create New Database*. Give it a name; cPanel prefixes it with your account
   (e.g. `cpaneluser_flxcorehc`). Note the full name.
2. **Add New User** — create a database user with a strong password (e.g. `cpaneluser_flx`). Note the
   full username and password.
3. **Add User To Database** — attach the user to the database and grant **ALL PRIVILEGES**.

You do **not** need to import any SQL by hand — the installer creates every table, and names each one with the prefix `fchc_` (`fchc_users`, `fchc_credentials`, …; the installer writes `db_prefix` to `app/config.php`). Use an empty database; the prefix keeps FlxCoreHC's tables recognisable and lets backups and the Database page ignore any other tables in the same database. (If you prefer to
create tables manually, the ordered migration files are in `database/migrations/` and the load order
is `database/manifest.php`; run them in that order. The installer is the supported path.)

---

## 3. Upload the app

1. Unzip the release (`flxcorehc-vX_Y_Z.zip`) locally.
2. Upload the **contents** to the directory you want to serve:
   - Web root (`public_html`) to serve at `https://yourdomain.com`, **or**
   - a subfolder (e.g. `public_html/flxcorehc`) to serve at `https://yourdomain.com/flxcorehc` — the app
     detects its own base path, so no edits are needed either way.
3. Confirm the bundled `.htaccess` files came across (they may be hidden): one at the app root and one
   in `app/`. These route requests and block direct web access to server-side folders.

**File permissions:** directories `755`, files `644`. Ensure `app/` and `app/storage/` (and
`app/storage/docs/`) are writable by PHP (usually `755` is enough on cPanel; use `775` only if your
host runs PHP as a separate user).

---

## 4. Run the web installer

1. Visit `https://yourdomain.com/install.php` (or `.../flxcorehc/install.php`).
2. **Requirements screen** — resolve anything marked required (red) before continuing. Optional items
   (yellow) are safe to proceed past.
3. **Configuration form** — enter:
   - **Database host** — usually `localhost` on cPanel.
   - **Database name** — the full name from step 2 (e.g. `cpaneluser_flxcorehc`).
   - **Database user / password** — from step 2.
   - **Organization name** — the name of *your* SaaS operation. Its type is fixed as **SaaS platform
     (operator)**; you'll create client organizations (billing companies, medical groups, hospitals,
     solo practices) later from the console, each with its own type.
   - **Administrator name / email / password** — this becomes the first **Master Admin** and the
     platform administrator. Use a strong password.
   - **Platform time zone** — the default for every organization (Eastern if you leave it). Each organization
     can choose its own later under Settings, and you can change this one under Admin → System.
   - **Backup passphrase** (at least 12 characters, typed twice) — locks the encryption-key backup file the
     installer offers on its last screen. Keep the passphrase and the file in a password manager.
   - **Force secure cookies** — leave on if the site is served over HTTPS (recommended).
   - **Development mode** — leave OFF for anything real.
4. Submit. The installer will:
   - generate fresh encryption keys (256-bit) for data-at-rest and blind-index fields,
   - write `app/config.php` with your database credentials, those keys and the platform time zone,
   - show a passphrase-locked backup of the keys for you to download (shown once),
   - create **all tables** (every migration in the manifest), and
   - create your administrator account and activate the first organization.

If the database credentials are wrong, the installer rolls back the partial config so you can correct
and retry cleanly.

---

## 5. Post-install security (important)

1. **Delete `install.php`** from the server. The installer refuses to run twice, but removing the file
   is the clean step and the final screen reminds you. (`app/config.php` also acts as an install lock.)
2. **Download the key backup** on the installer's last screen (it is shown only once) and store it with its passphrase. Also **back up `app/config.php`** somewhere safe and keep it out of version control. It contains your
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

## 7. Scheduled jobs (cron)

Four maintenance scripts live in `cron/` (all CLI-only; they refuse to run over the web). After you sign
in, **Admin → System & updates → Scheduled jobs (cron)** lists the exact lines for *your* server — built
from its PHP binary and the folder the app is installed in — ready to paste into cPanel → *Cron Jobs*.
Typical schedules (server time):

```
0 1 * * *  <php> <app folder>/cron/sweep_expiring.php   # credential status, one organization at a time
0 2 * * *  <php> <app folder>/cron/screen_leie.php      # exclusion screening refresh
0 6 * * *  <php> <app folder>/cron/send_reminders.php   # reminder e-mails and notifications
0 7 * * *  <php> <app folder>/cron/send_digests.php     # activity digests (weekly ones on Mondays)
```

They are safe to run again and use each organization's own time zone for "today". The app works without
them, but expiry statuses, reminders and digests only update when they run.

---

## 8. First steps after signing in

1. You are the **Master Admin** of the first organization and the platform administrator.
2. Create your first real tenant organizations from the platform console (**/admin**), or start
   adding providers directly if this account is the operating organization.
3. Add **team members** (Team) and set each one's role and provider access (all vs. assigned).
4. Optionally define **Locations** (practice sites) and **Groups** (client groups / departments), then
   affiliate providers with them.
5. Add providers, attach credentials, and upload documents.

**Also set up as the platform administrator (Admin):**

- **System & updates** — check the platform time zone and add the cron lines.
- **Terms of Use** — replace the draft text with your own (English and Spanish). Every save publishes a
  new version that every user must accept again. Users accept the Terms at their first sign-in; you, as
  the platform administrator, are not asked.
- **Sign-in policy** — turn on two-factor for platform administrators before you add customers.

**As an organization owner (Settings):** pick the organization's time zone (Puerto Rico does not change
its clocks; Florida does) and review **About & ownership**.

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
