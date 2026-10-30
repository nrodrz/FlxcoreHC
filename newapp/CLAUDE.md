# CLAUDE.md — working rules for this app

This app is a **Microsoft-services-only** product of Indisse-BTS. It was made on 10/30/2026 by `dev/make-ms-app.py` from
the install package of the owner's reference IT-operations app (build 0621-BT169). It keeps that app's whole platform
(sign-in, users, roles, settings, Audit Log, licensing, updates, data dictionary, Deleted items, Terms of Use, About &
ownership, API keys, scheduled jobs, Help) and only its Microsoft modules. Read `docs/NEW-APP-SPEC.md` before the first
change and `STARTER-REPORT.md` for what the script removed and what is left to prune. The order of work is in `docs/DEVELOPMENT-PLAN.md`.

Product name: **ITOps** (owner decision, build 0001-A002; set in `api/lib/Brand.php` → `ITOPS_PRODUCT`; screens use the
app-name helpers). Owner: **Indisse-BTS**. The owner's development standards are in `docs/standards/`
and are binding.

## Rules that always apply

- **Owner and names.** Owner is Indisse-BTS; never a person. No customer organization, person, domain or hosting
  provider in code, data, tests or docs (made-up data only: example.com, "Ada Admin"). Never hard-code the product
  name in screens: use the app-name helpers (`itops_app_name()` on the server, `appName()` in the screens).
- **Secrets.** API keys, client secrets and passwords are typed by an administrator in the app's configuration screens.
  Never ask for, accept or write a secret in chat, code, tests or docs. Stored secrets are wrapped, never returned to
  the browser, never logged.
- **Audit Log.** Every change and every refusal writes an Audit Log entry (before / after where it applies).
- **Screens.** The app's own select control, never a native `<select>`; in-page panels, never modal pop-ups; dates
  MM/DD/YYYY, times 12-hour, in the installation's time zone. Responsive from phone to wide screens.
- **Security.** No inline scripts on any page (Content-Security-Policy); CSRF header on every change; prepared
  statements only; server checks every permission (the screen only hides).
- **Language.** Code, screens, docs and changelogs in English.
- **Configuration.** Never ship or edit a real installation's `config.php`, license file or keys. The install package
  holds the app only (no docs, no guides, no `dev/`).
- **Results.** Report test results as they came out; never say something passed that did not run. A failing test is
  investigated to its cause (test or code) and said which.
- **Findings for the reference app.** A problem found here that the reference app also has goes into
  `docs/FINDINGS-FOR-REFERENCE-APP.md` (next R-number: where, what breaks, fix, whether ITOps fixed it). It is passed on,
  never fixed in the reference app from here.
- **Microsoft only.** New features use Microsoft services (Graph, Exchange Online admin API, Intune, Defender, Entra,
  Teams, Azure Resource Manager for billing). No other vendor's integration is added without the owner's decision.

## Layout

| Path | What |
| --- | --- |
| `api/index.php` | Router: sessions, CSRF, 2FA and Terms gates, module gate (`itops_route_module` → 404 when off), then `api/routes/<resource>.php` |
| `api/routes/` | One file per resource (`$method`, `$segments`, `$currentUser`, `json_response()`, `error_response()`) |
| `api/lib/` | Libraries. Microsoft: `Graph.php` (token, paging), `M365Hub.php` (`ItopsM365` class: accounts, groups, licenses…), `M365Permissions.php` (the Graph permission catalog shown in the app — keep it current), `ExchangeAdmin.php`, `MsBilling.php`, `SecAssess.php`/`SaProof.php` (posture), `RiskUsers.php`, `CaOps.php`/`CaDetail.php` (Conditional Access), `ObTasks.php` (onboarding/offboarding engine), `TeamsApp.php`, `Approvals.php` (second-person approval) |
| `api/lib/Modules.php` | Module catalog, plans, **`itops_product_modules()`** (the modules this product has; everything else is always off) |
| `api/lib/Brand.php` | Product constants, ownership texts, license record |
| `api/lib/License.php`, `Updates.php` | Public key lines of **this app's own** License Hub (empty until its hub is installed — spec step 5) |
| `src/app.jsx` | All screens (React, one file); `src/help.jsx`; compiled by `src/build.js` into `app.js` / `help.js` |
| `guide.js` | Help Center content (JSON in `const CONTENT = …`) |
| `sql/schema.sql`, `seed.sql`, `sample.sql`, `content-library.sql` | Fresh-install database; `api/lib/Migrations.php` / `db-update.php` for updates |
| `install/index.php`, `install/hub.php` | Installer for every organization; installer of this app's own License Hub |
| `cron-sla.php` | The scheduled job (command line, or the in-app scheduler over loopback); steps of an off module are skipped |
| `dev/` | Not shipped: `make-ms-app.py`, `fresh-install.sh`, `check-app.mjs`, `router.php`, `package.sh`, `unused.js` (unused top-level declarations of `app.jsx`: list or `--remove`), `php-unused.php` (the same for the PHP code), `php-undefined.php` (calls of functions defined nowhere — run after removing code), `cut-actions.php` (removes route action blocks by name), `prune-guide.js` (0001-A003, run once) |
| `docs/` | Not shipped: spec, development plan, findings for the reference app, standards, reference documents |

Code identifiers use the `itops_` prefix (functions, tables, settings; `ITOPS_` constants; `Itops` classes; headers such
as `X-ITOps-CSRF`). The reference app's `opsh_` / `OpsHub` names were renamed in build 0001-A002 (owner decision). A
fix ported from the reference app must be translated to these names. `dev/make-ms-app.py` still works on the reference
app's package and produces `opsh_` names.

## Build, run, test

```bash
# once: the compiler for the screens (pinned)
npm install --prefix dev @babel/standalone@7.24.7 playwright@1.48.2 && npx --prefix dev playwright install chromium

# build the screens after any change to src/*.jsx (also updates the ?v= hashes in the pages)
NODE_PATH=dev/node_modules node src/build.js

# every PHP file parses
find . -name '*.php' -not -path './vendor/*' -not -path './dev/node_modules/*' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'

# fresh local install (MariaDB/MySQL with root over the socket; PHP 8.1+) and the starter check (26 checks)
DB_USER=appdev DB_PASS=appdevpw dev/fresh-install.sh /tmp/app-run 8301 app_dev
PLAYWRIGHT=$PWD/dev/node_modules/playwright node dev/check-app.mjs   # or the system Playwright: $(npm root -g)/playwright
```

`dev/check-app.mjs` installs a fresh copy, signs in with the made-up admin, and checks: only the product modules are on;
About & ownership shows the product and the owner; the removed modules' APIs answer 404; product and platform APIs
answer; the Modules screen lists the product modules and plans; the menu has no removed module; every page opens with
no script error and no server error; every scheduled job runs; every table uses the `itops_` prefix; the installer
applied every database update; the Roles lists and presets, the dashboard, every Settings tab, the search, a user record, the
Help Center categories and old links hold only this product. Extend it as features are added; add a test per feature
(server answers, refusals, Audit Log entries, screens).

## Each build

1. Change the build id in **both** `api/lib/Build.php` (`ITOPS_BUILD`) and `src/app.jsx` (`APP_BUILD`): `0001-A005`, …
2. `CHANGELOG.txt` entry (format in `docs/standards/03-DEVELOPMENT-PROCESS.md`), the Help Center (`guide.js`) and
   `docs/APP-AUDIT.md` (the technical entry: files, functions, routes, tests and counts) updated with the change.
3. Database changes only as a new update step (never edit an installation's data by hand); a fresh install and an
   update must reach the same structure.
4. Build, php -l, `NODE_PATH=dev/node_modules node dev/unused.js` and `php dev/php-unused.php` (should list nothing new), `dev/check-app.mjs` and the
   feature tests; then package:
   `zip -qr -X ../ITOps-<build>-Full-Install.zip . -x 'dev/*' 'docs/*' 'CLAUDE.md' 'STARTER-REPORT.md' 'config.php' '*-license.php' '.gitignore'`
   (`dev/package.sh` does this and names the zip), and install that zip fresh once before delivering it. The zip is
   extracted at the root of the web folder (see INSTALL-FIRST.txt).
