# Development Standards — Indisse-BTS

Version 1.1 · 10/27/2026 · Owner: Indisse-BTS

The rules every app of the owner follows, present and future. They describe how an app is owned, licensed, updated,
documented and built, so any app can be maintained the same way. They name no app on purpose: an app's own documents
say how it meets them.

## What is in this pack

| File | What it covers | Read it when |
| --- | --- | --- |
| `01-PRODUCT-STANDARD.md` | What every app does and shows: ownership, white label, naming, per-installation values, licensing with the app's own License Hub, updates, billing, data dictionary, Terms of Use | Planning a feature that users or administrators see; starting a new app |
| `02-ENGINEERING-STANDARD.md` | How the code is written: shared core, adapters, module manifest, code rules, conformance test, adoption steps | Writing or reviewing code |
| `03-DEVELOPMENT-PROCESS.md` | How work moves: builds, changelog, database updates, testing, packaging, documentation, hand-offs between projects | Making, testing or delivering a build |

(The full pack also has `templates/`, `CHANGELOG.md` and `VERSION.txt`; ask the owner for them when a template is needed.)

## How the standards are used

1. **Each app is its own ecosystem.** Its own main installation (License Hub), its own keys, clients and licensed
   installations. Apps share rules and code, never data, and no app depends on another at run time.
2. **One version for the whole pack.** Each app's changelog says which version of these standards it meets
   ("Standards 1.1"). A change to any file here makes a new version.
3. **A rule that only lives in writing drifts.** Every rule is backed by shared code, a test, or both (see the
   conformance test in `02-ENGINEERING-STANDARD.md`).
4. **One reference implementation.** Shared code is changed in one app first (the reference app, named in that app's
   own documents), tested there, and then copied to the others. Nothing here is changed by editing an app's copy.
5. **Adding to this pack.** New topics are added as new numbered files (`04-…`).

## Words used in these standards

| Word | Meaning |
| --- | --- |
| App | One product of the owner, with its own name, code and License Hub |
| Installation | One copy of an app running for one organization, with its own database and files |
| Main installation / License Hub | The one installation of an app run by the owner that issues that app's licenses and updates |
| Organization | The licensee: the customer that runs an installation |
| `<Product>` | The app's product name, as set in the app |
| `<prefix>` | The app's table and code prefix (for example `abc_`) |
| Core | The shared modules that are the same in every app |
| Adapter | The small file each app writes to give a core module what is its own |
| Kit | A change sent to a project that builds another app's packages, written against that project's source |
