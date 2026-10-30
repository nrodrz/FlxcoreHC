# 03 · Development Process — builds, tests, packages, hand-offs

Standards 1.0 · 10/25/2026

How work on any app moves from a request to an installed build.

## 1. Requests and decisions

- A request is restated in a line before work starts. When it is clear or cheap to redo, work starts; when it is costly
  to redo and open to more than one reading, the open choices are asked first, with a recommended option.
- Choices that change data, structure or licensing are the owner's: the developer proposes, the owner decides, and the
  decision is recorded in the app's audit reference document with its date.
- Changes to the database structure follow "report first, decide, then one database update" (section 4).

## 2. Builds

- Every build has an ID shown under the app and in the browser console (for example `<series>-BT<number>`), set in one
  place on the server and one in the screens, changed with every build.
- Only the developer of the app changes its code. Fixes found elsewhere (reviews, other projects) are passed on, not
  applied by someone else.
- A build never contains a real installation's configuration file, license file, keys or data.

## 3. Changelog

Every build has an entry, newest first:

```
<build> — <what changed, in one line>
          [DB update: yes / no (what to run). Press Ctrl+F5 after uploading. Bridge: unchanged / <version>.]
  - What changed, for the people who use it, in plain words.
  - What was fixed, and what was found along the way.
```

The app's audit reference document gets the technical entry (files, functions, routes, tests and their counts).

## 4. Database updates

1. One update step per change, named `<date>_<n>_<build>_<what>`, appended to the app's list; steps run in date order.
2. A step works the same on a new installation and an old one. If the first installation steps still create an older
   shape, the step moves it, so every installation takes the same path.
3. A step that moves data records its progress in the same transaction as the move, so it can be run again after an
   interruption without moving anything twice.
4. A step that removes something removes only what is proven unused (no code names it; the report shows it).
5. Code that may run before the update has run reads the old place as a fallback, or says the update is pending.
6. Tested three ways before delivery: an old installation with data (every record keeps its links), an interrupted run
   run again, and a fresh installation compared with the previous build's.
7. What the step did is written to the Audit Log, on installations that already have a history.

## 5. Testing before delivery

- Every build passes: the app's full regression suites, the feature suites of recent builds, a crawl of every page with
  no script errors, and a clean install from the package itself.
- A new feature ships with its own tests (server and screens), including refusals and the Audit Log entries.
- A failing test is investigated to its cause. A failure caused by the test is fixed in the test and said so; a failure
  caused by the code is fixed in the code. Results are reported as they came out; nothing is reported as passed that
  did not run.
- Test databases and servers are started only for the work and stopped after; a run that ended early is said so.
- A build that touches roles, permissions, menus, routes or modules also runs the permission validator and the role
  matrix (01, section 15).
- A build that carries the License Hub's public keys runs the older suites (written for a build without keys) on a twin
  of the tree with only the key lines removed (checked by a file comparison), plus the key tests on the real tree.
- Tests that compare dates use the app's own date calculation, not the shell's: "+7 days" in a shell crosses a daylight
  saving change differently.
- A change that only affects how an installation talks to its hub is also tested against a stand-in hub and a stand-in
  host filter (one that refuses requests without a User-Agent).

## 6. Packages

Each app ships separate packages, never mixed:

| Package | Holds | Does not hold |
| --- | --- | --- |
| Install package (`<Product>-<build>-Full-Install.zip`) | The app only: code, built assets, SQL, installers, LICENSE.txt, THIRD-PARTY-NOTICES.txt, CHANGELOG | Guides, graphics, dictionaries, standards, configuration, keys, data |
| Support pack (`<Product>-Support-Files-<date>-<build>.zip`) | Guides, quick starts, data dictionary, infographics, README listing each file and who it is for | Code |
| Standards pack | The owner's development standards and templates | Anything of one app |

- Package size is watched; nothing is added "just in case".
- The install package is checked by installing it fresh from the zip before it is sent.
- The support pack keeps file names stable between versions so links keep working.

## 7. Documentation

- Living documents (the owner's editable docs) are the source; project copies are kept in step with them.
- An app keeps: an audit reference (architecture, data model, every build's technical entry), its changelog, its help
  content inside the app, and its support pack.
- Dates in documents MM/DD/YYYY; times 12-hour. English unless asked otherwise.
- Documents name no customer organization, no person and no hosting provider; standards name no app.

## 8. Keys, secrets and access

- API keys and secrets are typed by an administrator in the app's configuration screens. They are never asked for or
  accepted in chat, email or tickets, and never written in documents.
- Only public key lines (license and release keys) may be shared, to be built into the packages.
- Key backups and their passphrase are kept in two separate safe places by the owner.

## 9. Hand-offs between projects (kits)

When an app's packages are built by another project, changes travel as a kit, never as patched packages:

- One zip, `<app>-kit-<short-name>-base-<baseline>.zip`, with `KIT.md` on top: baseline and proposed version, what it
  adds, new files at their real paths, change blocks for existing files (never whole files), contract (endpoints,
  permissions, audit actions, outside permissions), schema, docs, checks after building (with and without the live
  system), and what differs from the reference app and why.
- A kit is sent only after the matching change is checked live in the reference app.
- The receiving project answers item by item (as sent, with changes, not implemented, found along the way); those
  answers are recorded so the next kit does not repeat them.
- Every build of the reference app ends with one line saying whether a companion app is affected.
