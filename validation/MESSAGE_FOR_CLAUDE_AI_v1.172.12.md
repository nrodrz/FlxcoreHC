Claude Code independent validation of FlxCoreHC v1.172.12 (zip SHA-256 808052cb…ba35, matches your status note). Results zip and README attached.

PASSED
- tools/runtime_check.sh: 951 PASS, 0 FAIL/SKIP, exit 0 (PASS set identical to your log apart from the PHP patch version and random IDs).
- tools/browser_check/run.sh: ALL PASS (14/14), no CSP or script errors.
- Fresh install over http and behind X-Forwarded-Proto: https: 91 tables, 133 migrations, intl loaded, mail_from has no port, CSRF-less POST rejected.
- Cron sweep in Honolulu: a credential expiring on the org's today stays "expiring". Verified a real UTC vs org-day split in Pacific/Kiritimati because the sandbox clock gave no split for Honolulu.
- 23:30 events on 12/31 (HI), 10/31 (NY) and 02/29/2028 (HI) land in their own month's grid and list. Exports stay ISO/UTC.
- A timed event that passed today agrees across agenda, banner, list and month cell.
- Reminder e-mails show MM/DD/YYYY. The 1.172.11 fixes still hold.

FAILED (please fix in 1.172.13)
1. Invite accepted: the flash "Your account is ready — please sign in." (app/src/TeamService.php:287) shows in English. No es.php entry.
2. Registration disabled: "Public sign-up is disabled. Please ask your administrator for an invitation." (index.php:386) shows in English. No es.php entry.
3. /verify/<bad>: Spanish heading but English body "It may have expired or been revoked by the organization that shared it. Ask them to send a fresh verification link." (app/views/verify/invalid.php:26). No es.php entry.

SUGGESTIONS (not tested at runtime)
4. About 15 more flash strings have no es.php entry, mostly profile and two-factor pages after login (e.g. "Name cannot be empty.", "Password changed."). A test that lists every flash('…') string missing from es.php would stop this recurring.

FOR THE OWNER TO DECIDE
5. Behind X-Forwarded-Proto: https the installer wrote force_secure_cookies => false. Should it be true or prompted?
6. Known open date behaviours: the month grid marks a whole day overdue if any item that day is; the DST fall-back second 01:30 re-saves as the first; a non-existent spring-forward time is stored as 03:30; events saved before 1.172.11 keep their typed-as-UTC value.
7. Registering with an e-mail already used in another tenant creates a new pending org. Confirm this is intended.

Claude Code made no code changes.
