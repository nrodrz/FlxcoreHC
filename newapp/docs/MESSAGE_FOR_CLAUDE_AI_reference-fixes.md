Findings from Claude Code for the next OpsHub build (found on 0621-BT169 while building another app from its package). Proposals only: no OpsHub code was changed. Please verify each one, implement, test, and ship with a CHANGELOG entry. Names are OpsHub's (opsh_, X-OpsHub-); line numbers are approximate.

FIX FIRST
1. Installer says "Installation Complete" when database updates failed (install/index.php, finalize step).
   - Breaks: the first failed update is stored in $_SESSION['install_mig_error'] but the finish page neither shows it nor stops; every later update is skipped. Seen: 2026_06_15_02_content_library failed, 120 of 121 updates did not run, the page still said complete; afterwards auth/sessions and sent_emails answered 500, Security assessment and Risky people asked for a database update.
   - Fix: when any update fails, show the error on the last page and do not mark the installation as installed (no lock).
   - Check: make one update throw on a fresh install → the installer shows it and stays unlocked. Normal install → rows in opsh_migrations = count(opsh_migrations_list()).

2. The code folders answer over the web (api/.htaccess).
   - Breaks: under Apache, /api/lib/Brand.php runs the file (empty 200) and /api/routes/users.php answers 500. No source leaks, but they must not be reachable.
   - Fix: right after "RewriteEngine On" add: RewriteRule ^(lib|routes|migrations)(/|$) - [F,L]
   - Check: those URLs answer 403; /api/health and the app still work (nothing loads these folders by URL; Help visuals are included through PHP).

3. On a new installation the in-app scheduler cannot start the main job (api/routes/tick.php; api/routes/jobs.php "Run now").
   - Breaks: the token for the web call of cron-sla.php (setting mail_cron_token) is only made when someone saves the Email settings. Until then the tick logs "skipped (no cron token yet)", Run now answers 409, and scheduled offboarding and the other main-job steps never run without a server cron.
   - Fix: one helper, used by both: read mail_cron_token; if empty, INSERT IGNORE bin2hex(random_bytes(24)) and read it back (another request may have made it first).
   - Check: fresh install, never open Email settings, keep the app open → Scheduled jobs shows a main-scheduler run via the in-app scheduler; Run now works.

4. config.php is written world-readable (install/index.php and install/hub.php).
   - Breaks: mode 644; on shared hosting other accounts on the server can read the database password and keys.
   - Fix: chmod 0640 (or 0600) right after writing it.
   - Check: ls -l config.php after a fresh install.

ALSO
5. "Microsoft 365 is not connected" points to the wrong place (api/lib/M365Hub.php ~line 36): it says Settings → Sign-in & AI → Microsoft 365 connection; since BT125 it is Settings → Company systems → Microsoft 365 → Set up.

6. Business hours need the SLA permission (api/lib/SettingsAreas.php maps business_hours to sla_cfg; editor on SLA & Hours). The same setting decides when Microsoft 365 pages are read ahead of time, so an admin with Settings · General but not SLA cannot set it. Owner to decide: keep, or also allow under Settings · General.

7. The in-app scheduler switch (Settings → Email → Schedule, mail_auto_tick) says it "checks the mailbox and SLA timers"; it turns the whole in-app scheduler on/off (scheduled offboarding, approvals, clean-up). Reword, or split email intake from the rest.

8. Top search does not escape % and _ (api/routes/search.php): "50%" or "a_b" match far more than meant. Escape \ % _ before building the LIKE pattern.

9. api/migrations/content_v1.php stores its SQL base64-encoded, so table names in it never show up in a search (that hid #1). Store plain text, or keep the source beside it.

10. READ-ME-FIRST.txt / INSTALL-FIRST.txt use old names ("Connection → Maintenance / General → Version"; the app says Settings → …) and assume an /itops/ subfolder in the cron examples.

ALREADY KNOWN, STILL OPEN
11. With Microsoft 365 not connected, m365hub/m365-defender, m365-service-health and m365-storage answer 500 → answer 409 with the message, and show a "connect Microsoft 365" panel on the page.
12. api/lib/O365.php (email sending, mailbox reading) gets a new token every run → reuse the cached token like Graph.php.

Please reply with what you accept, what you change, and the build that carries each fix.
