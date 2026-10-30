# 02 · Engineering Standard — how the code is written

Standards 1.1 · 10/27/2026

Every app of the owner is built the same way underneath, even where its screens and stack differ: the same shared core,
the same code rules, a manifest per module, and one conformance test every build must pass.

## 1. Platform

- PHP 8.1 or later; MySQL or MariaDB; deployable by uploading a package to shared cPanel hosting, with no shell and no
  build step on the server. A bridge that runs on another system keeps that system's PHP version.
- A request finishes within about 60 seconds (shared hosting); longer work is paged, cached or done by the scheduler.
- Scheduled jobs run from the command line by the host's scheduler; every job takes a lock and records its run.

## 2. Shared core

What every app must do identically lives in a `core/` folder that is the same, file for file, in every app. Each app
writes one adapter beside it with what is its own (its prefix, keys, paths, catalog).

| Module | Does | The app's adapter supplies |
| --- | --- | --- |
| License | Checks the signed license file; plan, modules, trial and grace | Public license key, file location, installation ID |
| License Hub | Issues licenses, link codes, daily check-in, signed revocations, installations view | Product name, plans and modules |
| Billing (hub only) | Price list, charges, payments, aging, CSV | Plans and modules |
| Updates | Checks a signed bundle; installs in two steps with a backup and a way back | Public release key; files never touched |
| Audit | Writes every change and refusal; reads by role | Table name; who is signed in |
| Secrets | Wraps and unwraps stored secrets; never returns them | Where the key lives |
| Database updates | Ordered, re-runnable steps, each recorded | The app's list of steps |
| Data dictionary | Tables, columns, grid, export, document | The app's catalog |
| Ownership and Terms | About & ownership, Terms of Use, acceptance | Product name and line |
| Deleted items | Keeps a deleted record with its children and file names, puts it back, deletes for good, daily clean-up, rights by role | The app's kinds |
| Permissions | Server checks, role editor contract, base permissions, the validator and the role matrix | The app's permission keys, their actions and bases |
| Safe export | CSV and Excel with formulas made harmless | — |
| Rate limit | Per-address limits for public pages | Limits |
| Dates | Time zone per installation, MM/DD/YYYY, 12-hour | The installation's time zone |

How the core stays the same:

1. The core uses neutral names (classes in one namespace, no app prefix), so one file works in every app. An app may
   keep its own older function names as thin wrappers while it moves over.
2. `core/VERSION` names the core version. No app edits a file in `core/`: a fix goes into the reference app first, gets
   a new core version, and is copied to the others.
3. The core travels inside each app's package; it is not installed by a package manager at run time.
4. Front-end code is not shared as files across different build tools. What is shared there is small helpers written
   as plain modules (date format, CSV cell, the three outcomes of a save) and the contracts in section 4.

The data dictionary is the model of the pattern: an engine file with no table names in `core/`, and a catalog file in
the app. Deleted items follows it: the engine (put, put back, delete for good, clean-up, rights) names no table; the app
lists its kinds.

## 3. Module manifest

Every module of an app declares itself in `modules/<id>/module.json`, so the License Hub's plans, the role editor, the
menu and the data dictionary read one list.

- `license_key` is what a plan or extra module switches on; `core: true` modules (sign-in, users, roles, settings, logs,
  help, About) always run.
- The tables a manifest lists must be the tables the catalog describes; the conformance test checks it.
- A module is added by adding its folder: manifest, routes, screens, database update steps, tests. Nothing outside the
  folder changes except the screen registry.
- An existing app adopts manifests gradually: first a manifest describing what exists, then each module's code moved
  into its folder when it is next changed.

## 4. Code rules

| Area | Rule |
| --- | --- |
| Language | Code, screens, docs and changelogs in English unless another language is asked for at the time. |
| Files | Every file starts with the owner's header. Layout: `api/` (router, one file per route, `lib/`), `core/`, `modules/<id>/`, `sql/`, `install/`, public pages apart. No new single-file back end. |
| Database | Every table carries the app's prefix. Prepared statements only; table and column names in SQL only from the code or the database's own list, never from a request. |
| Database changes | Only through the core's database updates: ordered, re-runnable, recorded; data moved in a transaction together with its record; the same path on a new and an old installation. |
| Answers | JSON. Success: the data. Failure: `{"error": {"code", "message"}}` with the HTTP status. A database or server message never reaches the screen; it goes to the log. |
| Saving | Three outcomes on screen and in the Audit Log: saved, refused, not known. A change carries the value the page saw; a value changed meanwhile is refused (409). Several writes in one request: repeat the check right before the write it protects. |
| Audit | Every change and every refusal goes through the core Audit module, with before and after. Secrets never appear in it. |
| Permissions | Checked on the server for every action; the screen only hides what the server would refuse. Names: `<module>.view`, `.create`, `.edit`, `.delete`, `.all`. Every key the server checks has a Roles editor row offering exactly the actions checked; a sub-permission declares its base (01, section 15). |
| Deleting | Never erase from a route. Every delete goes through the core's Deleted items: the kind's children and files kept, links to set again written down, "still used by" refused before anything changes. Files leave the disk only when an entry is deleted for good. |
| Outbound calls | Every call to another server sends a User-Agent naming the product and build; an answer that is not the expected JSON is reported with the answering server's name and the start of its text, never as a bare HTTP code. |
| Secrets | Typed by an administrator in the app; stored wrapped; never returned to the browser, logged or packaged. |
| Security | Content-Security-Policy with no inline scripts on every page, public pages included; CSRF header on every change; rate limits on public pages; uploads checked by type and size with random names. |
| Screens | The app's own select control (no native drop-downs) and in-page panels (no pop-up dialogs). Dates MM/DD/YYYY, times 12-hour, in the installation's time zone. A long action locks its ways out; a late answer for another record is dropped; an edit page keeps the edits, never a copy of the record. |
| Names | No customer organization, person, domain or hosting provider in code, data or tests; the product name comes from settings. |
| Dependencies | MIT-licensed or equally permissive only, pinned to an exact version, listed in THIRD-PARTY-NOTICES.txt. |
| Tests | Each module ships its tests; a fix ships with a test that fails without it; test data is made up. |

## 5. Conformance test

One test, shipped with the core in `core/tests/`, run on every build of every app. A failure stops the build. It reads
the app's adapters for its prefix, keys and paths.

- `core/` matches the reference copy of its version, file by file.
- Headers on every file; the names sweep finds nothing.
- Security policy on every page; no inline script.
- Every table of a fresh installation has the prefix and is in the catalog; every manifest table exists.
- The same set of license files is accepted or refused the same way in every app.
- The same set of update bundles is accepted or refused the same way.
- A change and a refusal each write one Audit Log entry; no secret value in any answer, export or log.
- Database updates run twice in a row on a fresh installation with no change the second time.
- Every changing action refuses a request without the CSRF header and a user without the permission.
- Every delete of every declared kind lands in Deleted items with its children; put back restores the same numbers;
  delete for good removes the files; a record still used is refused; rights follow the role.
- The permission validator finds no defect and the role matrix passes (01, section 15).
- Every dependency pinned and listed with its license.
- Negative control: the test fails on a copy with one of these broken on purpose.

## 6. Adopting the standard (an existing or a new app)

A new app meets everything before its first installation. An existing app is never rewritten; it adopts in this order,
one step per build, and records each in its changelog:

1. Fill in the gap map: how the app does each thing today.
2. Headers, names sweep, security policy.
3. Secrets, Audit and Database updates from the core (a table prefix first, if the app has none).
4. Licensing with its own License Hub, Updates and Billing.
5. Data dictionary and module manifests.
6. Deleted items (kinds declared, every delete routed through it, earlier soft deletes adopted) and the permission
   validator and role matrix.
7. Answer shapes, three outcomes, screen rules.
8. Layout: one file per route and modules in folders, moved gradually as code is touched.

An app whose packages are built by another project receives each step as a kit (`03-DEVELOPMENT-PROCESS.md`).
