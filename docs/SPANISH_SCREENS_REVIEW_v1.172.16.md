# Spanish screens review — FlxCoreHC v1.172.16

Reviewed 11/01/2026 by Claude Code. Read-only: nothing was changed.

## How it was checked
- Disposable database with the smoke-test fixture (two providers, credentials, tasks, events, cases, 19 users). Server: `php -S` with `tools/router.php`.
- Signed in as seven roles (owner, manager, staff, coordinator, read-only, portal, platform admin), switched to Spanish (`?setlang=es`), and followed every link up to 200 pages per role (≈1,200 page loads, 10,504 distinct visible texts: text nodes, placeholders, titles, aria-labels, button values).
- Each text was compared with `app/lang/es.php`: (a) an English text that **has** a catalog entry but is still shown in English, (b) English-looking text with no entry. Findings below were then re-checked one by one on the live page.
- **Not covered:** e-mails, PDFs (only the text the package PDF carries), JavaScript messages in `assets/app.js`, pages past the 200-page cap per role, the patient/provider portal beyond its landing page, the platform-admin console (operator only, English by design).

## Result: the Spanish interface is good on the everyday screens; five areas are still English

| # | Area | What the user sees in Spanish mode | Evidence | Size | Suggested fix |
|---|---|---|---|---|---|
| S1 | **Help Center** (`/help`, every topic) | The whole page frame is English: "Sign out", "Open menu", "Collapse menu", "Toggle theme", "Dark mode", "Your profile", "Organization settings", "Back up your encryption keys…". All of these have Spanish entries and show correctly on other pages. | `/help` as owner in Spanish: "Cerrar sesión" absent, "Sign out" present | 24 pages | The Help layout renders the shell without the locale; apply the same locale bootstrap as the main layout. Help *topic* text is English-only and needs translating (see S6). |
| S2 | **Compliance audit package / provider packet** (`/reports/audit/{id}`, `/holders/{id}/packet`, package PDF) | Table headers and labels English even though the words exist in the catalog: Requirement, Authority, State, Detail, Missing, Type, Name, Number, Expires, Status, "High risk", "Print / Save as PDF", "Back", plus the 10 security-checklist lines ("Enable MFA for admin accounts", "Force HTTPS / TLS for all access"…) and footers ("Prepared by … from records maintained in FlxCoreHC"). The PDF has English headings ("Requirement status", "STATUS"). | 71 catalog texts shown in English, nearly all on these pages | 3 views + 1 PDF | These views print literals without `t()`. Wrap them; these are the documents an owner hands to an auditor, so they are the most visible gap. |
| S3 | **Catalog data stored in the database** | Credential type names ("State Professional License", "National Provider Identifier (NPI)", "Organizational NPI (Type 2)", "Business License", "State Controlled-Substance Registration", "Group Malpractice Coverage"), regulator names and notes ("The Joint Commission", "HHS Office of Inspector General", PECOS / LEIE / ORCPS guidance paragraphs), template names and descriptions, requirement text ("Primary-source verification files", "PR medical license", "Florida medical license"), payer names. | Shown on credential forms, regulator pages, templates, compliance, cases | ~120 strings, on ~30 pages | Seed data is English and the translator only sees `t()` calls. Either run catalog names through the translator at display time (cheapest: `t($row['name'])` with entries in `es.php`) or add `name_es` columns. Proper names (Joint Commission, NPPES) stay as they are. |
| S4 | **Strings built from parts** | "Set up your groups", "Providers & org" (dashboard cards); "1 seats · 25 providers", "10 seats · ∞ providers", "documents, scheduling, analytics" (billing plans); "19 users", "0/4 active · score" (team, compliance); "Agenda groups open items by urgency…" (calendar hint); "← All training", "· updated 11/01/2026"; "Choose an icon for Dashboard/Billing/…" (organization → icons); the how-to lines on provider pages ("· Provider → Edit", "· Credentials → add DEA"…). | Re-verified live on `/`, `/billing`, `/team`, `/calendar` | ~60 strings | Convert to placeholder messages like the ones added in v1.172.13 (`t('{n} users', …)`), then add entries. |
| S5 | **Activity log text** | Action names and details English or half-translated: "role created", "provider updated", "provider packet viewed", "acciones · Today", detail lines such as "holder_id: … · credential_type: state_license · name: …". | `/audit` | ~30 strings | Map action codes to labels with `t()`; show detail fields with translated field names, not raw keys. |
| S6 | **Long-form content**: Help topics, Training pages (`/training/*`), regulatory guidance, "Verify this guidance straight from the authority…" | English only | `/training/hc_board_cert` etc. | ≈15 training pages + Help topics | Content translation (a writing task), best done with a Puerto Rico reviewer, as the catalog already says for regional wording. |

Also seen, **not** problems: fixture names ("Test read_only", "Bob Bravo", "P1 License"), table names on the platform-admin database page, audit-action codes on operator pages.

## What I did *not* find
- No broken or untranslated text on the sign-in, forgot/reset password, registration, invite and verify pages (fixed in 1.172.12–13).
- Calendar (grid, list, agenda), tasks, notifications, profile, organization settings, roles, team, and the new time-zone field are Spanish.
- No page crashed or produced a PHP warning in Spanish mode.

## Suggested order
1. S1 + S2 (frame and audit documents) — small code change, high visibility.
2. S4 and S5 — placeholder rewrites; extend the v1.172.13 guard test so a new literal without an entry fails the build.
3. S3 — decide display-time translation vs. columns (recommend display-time through `es.php`; no migration).
4. S6 — content translation with a native reviewer.
