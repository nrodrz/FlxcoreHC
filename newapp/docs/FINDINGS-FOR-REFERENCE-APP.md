# Findings for the reference app

Problems found while building ITOps that also exist in the reference IT-operations app (build 0621-BT169). They are
passed on, not fixed there from here (03-DEVELOPMENT-PROCESS §2: only the developer of an app changes its code).

Names are the reference app's (`opsh_…`, `X-OpsHub-…`, `api/lib/…`). Line numbers are approximate: they come from the
ITOps copy, where the prefix was renamed in 0001-A002. Each entry: where, what breaks, suggested fix, and whether ITOps
has fixed it in its own code.

**Paste-ready version for Claude.ai:** `MESSAGE_FOR_CLAUDE_AI_reference-fixes.md` (same items; each number is its R-number); refresh it when items are added.

**How to use this list:** add a new finding at the end of its section with the next number, the ITOps build it was
found in, and the same four parts. When the reference app ships a fix, mark the entry *Fixed in <reference build>* —
do not delete it.

| Status | Meaning |
| --- | --- |
| Open | Not reported or not fixed in the reference app |
| Reported | Handed to the reference app's developer |
| Fixed in BTxxx | The reference app's build that fixed it |

---

## Bugs

### R-01 · The installer says "Installation Complete" when database updates failed
- **Found:** ITOps 0001-A002 · **Status:** Open · **ITOps:** a test checks every update applied (`dev/check-app.mjs`); the installer itself is not changed yet
- **Where:** `install/index.php`, finalize step. The first failed update is kept in `$_SESSION['install_mig_error']`, but the finish page neither shows it nor stops.
- **What breaks:** when one update fails, every later one is skipped and the page still says "Installation Complete". Seen: `2026_06_15_02_content_library` failed, 120 of 121 updates did not run; afterwards `auth/sessions` and `sent_emails` answered 500 and the security assessment and risky people asked for a database update.
- **Fix:** if any update fails, show the error on the last page and do not mark the installation as installed (no lock). Check: rows in `opsh_migrations` = `count(opsh_migrations_list())`.

### R-02 · The code folders can be requested from a browser
- **Found:** ITOps 0001-A002 · **Status:** Open · **ITOps:** fixed in 0001-A002
- **Where:** `api/.htaccess`.
- **What breaks:** under Apache, `/api/lib/Brand.php` runs the file (empty answer) and `/api/routes/users.php` answers 500. No source leaks, but these files must never be reached directly.
- **Fix:** right after `RewriteEngine On`: `RewriteRule ^(lib|routes|migrations)(/|$) - [F,L]`. Nothing loads these folders by URL (the Help visuals are included through PHP).

### R-03 · On a new installation the in-app scheduler cannot start the main job
- **Found:** ITOps 0001-A004 · **Status:** Open · **ITOps:** fixed in 0001-A004 (`itops_cron_token()` in `CronMonitor.php`)
- **Where:** `api/routes/tick.php`, `api/routes/jobs.php` (Run now).
- **What breaks:** the token the web call of `cron-sla.php` needs (setting `mail_cron_token`) is only made when someone saves the Email settings. Until then the tick logs "skipped (no cron token yet)", Run now answers 409, and scheduled offboarding and the other main-job steps never run unless a server cron is set up.
- **Fix:** make the token the first time it is needed: read `mail_cron_token`; if empty, `INSERT IGNORE` a random one (`bin2hex(random_bytes(24))`) and read it back.

### R-04 · The installer writes `config.php` readable by everyone on the server
- **Found:** ITOps 0001-A002 (Apache test) · **Status:** Open · **ITOps:** planned for 0001-A006
- **Where:** `install/index.php` (and `install/hub.php`), where `config.php` is written.
- **What breaks:** the file is created with mode 644. On shared hosting other accounts on the same server can read the database password and the keys.
- **Fix:** `chmod(…, 0640)` (or `0600`) right after writing it.

### R-05 · "Microsoft 365 is not connected" points to the wrong place
- **Found:** ITOps 0001-A003 · **Status:** Open · **ITOps:** fixed in 0001-A003 (points to its own Settings → Microsoft 365)
- **Where:** `api/lib/M365Hub.php`, about line 36.
- **What breaks:** the message says "Settings → Sign-in & AI → Microsoft 365 connection"; since BT125 the connection is under Settings → Company systems → Microsoft 365 → Set up.
- **Fix:** name the current place.

### R-06 · Business hours can only be saved with the SLA permission
- **Found:** ITOps 0001-A003 · **Status:** Open (owner to decide) · **ITOps:** moved to Settings · General in 0001-A003
- **Where:** `api/lib/SettingsAreas.php` maps `business_hours` to `sla_cfg`; the editor is on the SLA & Hours tab.
- **What breaks:** the same setting decides when Microsoft 365 pages are read ahead of time, so an administrator with Settings · General but not SLA cannot set it.
- **Fix:** accept it, or also allow it under Settings · General.

### R-07 · The in-app scheduler switch says less than it does
- **Found:** ITOps 0001-A003 · **Status:** Open · **ITOps:** text fixed in 0001-A003
- **Where:** Settings → Email → Schedule (setting `mail_auto_tick`).
- **What breaks:** the text says it "checks the mailbox and SLA timers"; it turns the whole in-app scheduler on or off (scheduled offboarding, approvals, clean-up). Someone switching it off to stop email intake stops those too.
- **Fix:** describe what it does, or split email intake from the rest of the scheduler.

## Smaller items

### R-08 · The top search does not escape `%` and `_`
- **Found:** ITOps 0001-A003 · **Status:** Open · **ITOps:** fixed in 0001-A003 (its own search)
- **Where:** `api/routes/search.php`.
- **What breaks:** searching `50%` or `a_b` matches far more than meant.
- **Fix:** escape `\`, `%` and `_` before building the `LIKE` pattern.

### R-09 · The content-library update stores its SQL base64-encoded
- **Found:** ITOps 0001-A002 · **Status:** Open (suggestion)
- **Where:** `api/migrations/content_v1.php`.
- **What breaks:** table names inside it do not show up in a search, so a search for a table or a scripted rename misses it (this is how R-01 showed up).
- **Fix:** store the statements as plain text, or keep the source next to the encoded file.

### R-10 · READ-ME-FIRST.txt uses old names
- **Found:** ITOps 0001-A002 · **Status:** Open
- **Where:** `READ-ME-FIRST.txt`, `INSTALL-FIRST.txt`.
- **What breaks:** they say "Connection → Maintenance / General → Version"; the app says Settings → Maintenance / General. The cron examples assume an `/itops/` subfolder.
- **Fix:** use the app's current names.

### R-13 · A hosting provider is named in the host check
- **Found:** ITOps 0001-A004 · **Status:** Open · **ITOps:** fixed in 0001-A004
- **Where:** `opsh-hostcheck.php`, header comment ("In Docker (… LAMP)").
- **What breaks:** the owner's rule is no customer, person, domain or hosting provider in code; the comment names one.
- **Fix:** "In Docker:".

### R-14 · The Super Admin-only mail settings check misses the SMTP security key
- **Found:** ITOps 0001-A004 · **Status:** Open · **ITOps:** fixed in 0001-A004
- **Where:** `api/routes/mail.php`, PUT, the CORE-2 check: `preg_match('/^mail_(…|smtp_secure|smtp_encryption|…)$/')`.
- **What breaks:** the setting is saved as `mail_smtp_security`, which the pattern does not name, so someone with Settings · Email · Edit who is not a Super Admin can change the SMTP security (for example from TLS to none). When an SMTP password is saved the H-5 check (password must be typed again) stops it; when none is saved it goes through.
- **Fix:** name `smtp_security` in the pattern (and drop the two names that do not exist).

### R-15 · The AI settings card asks for Tickets · View
- **Found:** ITOps 0001-A004 · **Status:** Open · **ITOps:** fixed in 0001-A004 (Settings · View)
- **Where:** `api/routes/ai.php`, `GET /api/ai`: `opsh_require($currentUser, 'tickets', 'view')`.
- **What breaks:** the card lives in Settings → Sign-in & AI, but an administrator with Settings and without Tickets cannot load it (403); in an installation without the Service Desk module nobody can, Super Admin included.
- **Fix:** `opsh_require($currentUser, 'settings', 'view')` for the settings read (the ticket assistant keeps its own Tickets check).

## Already in the ITOps spec (step 4), still open in the reference app

### R-11 · Pages answer HTTP 500 when Microsoft 365 is not connected
- **Status:** Open · **ITOps:** planned for 0001-A006
- **Where:** `m365hub/m365-defender`, `m365-service-health`, `m365-storage`.
- **Fix:** answer 409 with the message, and show a "connect Microsoft 365" panel on the page.

### R-12 · Email sending and mailbox reading get a new Microsoft token on every run
- **Status:** Open · **ITOps:** planned for 0001-A006
- **Where:** `api/lib/O365.php`.
- **Fix:** reuse the cached token, as `Graph.php` does.
