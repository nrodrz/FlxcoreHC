# FlxCoreHC v1.170.6 — Insurance-Vertical Residue Audit (all screens)

**Scope:** every view under `app/views/` (tenant + platform console), every route in `index.php`,
navigation, signup, roles, cron, seed data (`database/`), services (`app/src/`), language/help files.
**Method:** static source review (3 parallel reviews + spot verification). READ-ONLY — no code changed.
**Rule applied:** the product is healthcare-only. Insurance content a user can see = defect.

## Headline
- **No insurance module is reachable.** Carriers, policies, commissions, SOA, beneficiaries, bookings,
  NIPR, CMS sync, AHIP: no route handlers remain. Insurance seed data (42 catalog rows, 12 training
  articles, 15 requirement defs) is deleted by migrations 072/082–084 on a fresh install.
- **What leaks is copy and a few whole panels inside healthcare screens**, plus old insurance defaults
  (Florida, "medicare" line, `'insurance'` fallback) that quietly steer logic.
- **The public verification page** (seen by payers/hospitals) still says "Producer" and shows NPN.

## A. Whole panels/pages that are insurance-only (highest impact)
| Where | What the user sees | Fix |
|---|---|---|
| `views/holders/_ce.php` (provider page tab + `/me` portal) | Florida insurance CE: 24 h / 5 h Law & Ethics, F.S. 626.2815, "Flood (NFIP)", MyFloridaCFO agent link, "producer's birth month" | Replace with healthcare CME/CE tracker (hours per licensing board, cycle from license expiry) or hide until built |
| `views/holders/show.php:97-118` + route `/organization/nipr-interest` (`index.php:2232`) | "State licence verification (NIPR)… state insurance licence… NPN" + "I'd want this" button | Remove panel + route; replace with state board / primary-source verification note |
| `/entity` → `views/organization/index.php:24-66,153` (in everyone's nav, `layout.php:103`) | FL insurance agency law (DFS agent in charge, F.S. 626.0428/626.112, 91-day lapse), "Agency licence number", "Entity NPN", "Apply the Agency or FMO template" | Rewrite as healthcare entity profile: Group NPI (Type 2), taxonomy, Medicaid ID/PTAN, CLIA, facility licence, accreditation |
| `views/credentials/requirements.php:2,11,21` | "MA credential requirements" / "Medicare Advantage requirements", "Add a producer (agent, agency, or FMO)", chips "Insurance", "Appointment" | "Credential requirements"; chips "Malpractice / liability", "Payer enrollment" |
| `views/reports/audit_package.php:9,32-34,94-96` | "CMS Audit-Readiness Package", "(Agency)/(Agent)", NPN | "Credentialing Audit-Readiness Package", Organization/Provider, NPI |
| `views/organization/compliance.php:11-13` + PDF `CompliancePostureService.php:39,50,68,130,133,176` | Security posture page and **payer-facing PDF**: "producer and beneficiary data", "Carriers, FMOs… agencies", "Producer NPI, NPN", "producers CMS-verified" | Rewrite: provider data, payers/health systems, NPI only |
| `views/verify/show.php:5,108-116,125,140` (**public**) | "Producer", NPN, ".carrier" suffix, "does not constitute an appointment" | Provider, NPI + specialty + licence state, payer, "not a credentialing decision" |
| `views/holders/show.php:516-519` + `ShareLinkService.php:7` | "Carrier verification links… with an upline or carrier… your agency" | "Verification links… with a payer, hospital or credentialing committee" |
| `CatalogService::RULESET_LABEL` (`compliance/index.php:10`, `admin/system.php:26`) | "CMS CY2027 Final Rule (CMS-4208-F3) · PY2027 certification season · FL commercial lines" | Healthcare ruleset label (e.g. "HC2026.1 · NPPES/PECOS 42 CFR 424.515 · DEA · CLIA · PR JLDM/ORCPS · FL BOM") |

## B. Insurance fields on healthcare forms/lists
- `holders/form.php:40-41` "NPN (insurance)" field (ungated; saved only for non-HC) · `:8,10` "Producer type", "Individual (agent…)" · `:46` "anchors the FL CE cycle" · `:55` placeholder "MA agent…"
- `holders/index.php:58,64` column "NPI / NPN" · `holders/show.php:141,145` NPN column in profile table
- `calendar/index.php:218,226`, `calendar/form.php:17,21` "Agent (optional)", event type "Client call", **"Client name (encrypted)"** (insurance CRM leftover; invites patient PHI)
- `organization/icons.php:8` + `IconService.php:44-50` slots "Producers", "Book", "Clients", "Schedule"

## C. Wording: producer / agent / agency (≈35 spots, same fix: `vocab('holder')`/"provider")
compliance/index.php:16-17,67,73 · compliance/_tabs.php:4 ("Producers" tab) · compliance/producers.php:2,20,26 ·
credentials/index.php:15,19 · credentials/form.php:36-60 · holders/show.php:31,38,43,47,84,96,454 · holders/form.php:2 ·
me/index.php:7,112 · templates/form.php:27,40 ("FL Medicare Agent (2-15)") · templates/index.php:26 ·
org/role_form.php:42 ("own book & SOAs") · org/roles.php:11 ("field rep… own book") · reports/index.php:39 ·
billing.php:24,49 ("Producer logins") · tasks/index.php:16,34 · team/index.php:35,116 · training/index.php:90,98 ·
flash messages in `index.php:774,1260,1284,1292,1294,4323` · `TaskSyncService.php:37,56` · `app/lang/es.php:506,552,583` keys ·
**Role name "Producer (self-service)"** `RoleService.php:39`, `TeamService.php:28` (seeded into every tenant's `roles` table; also `admin/tenant.php:281`) → "Provider (self-service)", slug may stay · `PlanService.php:19` "Production analytics" → "Analytics & reports" · default plan named **"Agency"** (`014_plans.sql:21`)

## D. Hidden defaults that steer logic (not visible, but wrong)
| Where | Problem | Fix |
|---|---|---|
| `CatalogService.php:85-86`, `ReminderService.php:352` | Unknown vertical falls back to `'insurance'` (and org_shape `'agency'`) | Fall back to `'healthcare'` |
| `036_states_and_entity.sql:21`, `CatalogService.php:249` | Every tenant defaults to `operating_states='FL'`; **PR catalog rows (070) are excluded from PR tenants' requirements** until an admin edits jurisdictions | Ask state at signup / default PR; fix fallback |
| `029_commercial_lines.sql:9`, `OrgService.php:60`, `CatalogService.php:269-275`, `index.php:359,688` | Healthcare tenants stored with `business_lines='medicare'` feeding catalog filter | Skip line filter for healthcare |
| `CatalogService.php:186` | Custom document type saved without `applies_to` gets `'agent'` → never required for any healthcare provider | Default `'all'` |
| `index.php:1287`, `TeamService.php:161,174`, `DemoHealthcareService.php:352` | Provider portal logins created as role `producer`; `TeamService::ROLES` lacks the `provider` role RoleService defines | Keep slug, fix label (or migrate to `provider`) |
| `071_training_vertical.sql:9` | `training_library.vertical` defaults `'insurance'` → new articles silently hidden | Default `'healthcare'` |
| `TrainingLibraryService.php:71-72` | Authority label "State (FL DFS)", "Carrier" | "State board", "Payer" |
| `035/037` seed templates | Upgraded installs keep 7 insurance templates ("FL Medicare Agent (2-15)", "Flood (NFIP)") | Prune migration (fresh installs are clean) |

## E. The "Other (custom)" signup path
`AuthService.php:20` / `bootstrap.php:27` — an "Other" tenant gets vertical `custom`, and every `!$isHc` branch then
shows insurance UI (Lines of business "Medicare Advantage / Commercial P&C", NPN in onboarding/profile).
Fix: invert those checks to `=== 'insurance'` (then delete), or treat `custom` as healthcare. Remove the
`'insurance'` entry in `auth/register.php:20` and the `'both'/'insurance'` edition branches.

## F. Healthcare gaps (missing, not just mislabeled)
1. **CME/CE tracking** for providers by licensing board (only the FL insurance CE engine exists).
2. **Healthcare entity profile** (Group NPI, taxonomy, Medicaid/PTAN, CLIA, facility licence, accreditation, OIG status of the entity).
3. **Ruleset** — no healthcare ruleset label/version per jurisdiction.
4. **Jurisdiction onboarding** — PR vs FL not chosen at signup (drives which catalog rows apply).
5. **Public verify page** lacks NPI, specialty, licence state.
6. **Regulators** — FL has only Board of Medicine (no Nursing, Pharmacy, Dental, AHCA); no other states.
7. **Training Center** — 15 starter articles, none with verified sources; no HIPAA, OIG/SAM, CAQH, malpractice, Joint Commission guides.
8. **Plans** — plan names/features are insurance-era; no healthcare tiers.
9. `templates/form.php:101-114` prints empty regulator headings (`CARRIER_SCOPED = []`).
10. `cases/index.php:27` asks the user to type a raw `provider_groups.id` (needs a dropdown).

## G. Non-insurance bugs found along the way
- `training/index.php:13,17,44` shows literal `—` / `“` (PHP needs `\u{2014}` or the real character).
- `PurgeService.php:37-43` blocker labels name tables retired by migration 082 (harmless; tidy up).

## Clean (verified)
No insurance capabilities (`Capabilities.php`), admin console nav has no insurance tools, cron jobs contain no
CMS/AHIP/SOA logic, email/notification/help/translation seeds are healthcare, training + checklist + catalog
queries filter by vertical, NPN save/export is gated to non-healthcare.
