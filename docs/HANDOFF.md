# FlxCoreHC — Engineering Handoff

*Read this first if you are an AI assistant (or a new developer) picking up this codebase. It captures
the operating context, conventions, and current state that the code alone does not make obvious. Pair
it with `INSTALL.md` (full release history), `docs/CAPABILITIES.md` (what the product does), and
`docs/CODE_AUDIT_REPORT.md` (security posture and residual risks).*

**Current version: 1.166.0** · Keep this line and the "Current state" section updated with each release.

---

## 1. What this product is

**FlxCoreHC — Healthcare Compliance Intelligence Platform.** A multi-tenant SaaS that is a
**credentials & compliance *support* system**, not a credentialing authority. It helps healthcare
organizations (billing companies, medical groups, practices) **track, organize, monitor and prove**
their providers' credentials and compliance obligations — expirations, requirements, documents,
deadlines, payer/program enrollment, and application readiness.

**Say "support / track / monitor / prove", never "perform credentialing" or "verify" in the regulated
sense.** The verification feature *records documented verification*; it does not act as the verifying
authority. This distinction is legal, not cosmetic — do not let the product overclaim.

Primary market: **Puerto Rico** (deeply modeled, source-verified) with **Florida** planned. The app is
bilingual **English/Spanish** with full parity (see §5).

The product had a different working name until v1.161–1.162; it is now "FlxCoreHC" everywhere. There is a separate, unrelated "FlxCore" project — do not
conflate them.

---

## 2. Stack & environment (hard constraints)

- **PHP 8.3, MariaDB, on cPanel shared hosting.** No Composer, no build step, no framework.
- **No dependency manager** — everything is hand-rolled; a custom `spl_autoload_register` in
  `app/bootstrap.php` maps `FlxCoreHC\Foo\Bar` → `app/src/Foo/Bar.php`.
- **Single front controller:** `index.php` is the router (~4,600 lines). Requests dispatch from there.
- **No JS framework.** `assets/app.js` is vanilla; `assets/app.css` is a themeable token system
  (light/dark). Inline scripts must use the CSP nonce (`nonce_attr()`).
- Outbound HTTP is available on real hosting (NPPES, SAM.gov, Microsoft Graph); it is **blocked in the
  dev sandbox**, so network-dependent code must degrade gracefully and be tested with that in mind.

Because it is shared hosting with no migration framework of its own, **every schema change is a numbered
SQL file** in `database/migrations/` (currently 120), applied in order by the installer and by
Console → System → *Update database*.

---

## 3. Architecture & conventions

**Layers:**
- `index.php` — routing, request handling, CSRF, auth gating.
- `app/src/*.php` — services (70): business logic (e.g. `TemplateService`, `VerificationService`,
  `CustomFieldService`, `SensitiveFields`, `ComplianceLevelService`).
- `app/src/Repository/*.php` — data layer (26). **All database access goes through repositories.**
- `app/views/**/*.php` — plain PHP templates rendered via `view()`.
- `app/lang/{en,es}.php` — translation strings. `app/help/{en,es}.php` — in-app help content.

**Non-negotiable patterns (violating these is how bugs and security holes get introduced):**

1. **Tenant isolation is the #1 invariant.** Every query is scoped to the current tenant. This is
   enforced structurally: `TenantRepository` is the base class and `TenantContext` carries the tenant
   id (it is the app's replacement for Postgres row-level security). **Never write raw SQL outside the
   repository layer.** A checker, `tools/check_isolation.sh`, enforces this and has a reviewed
   allow-list (~66 entries) of the few cross-tenant readers. It must pass before every release.

2. **Sensitive data is encrypted at rest.** `app/src/Crypto.php` does authenticated encryption
   (XChaCha20-Poly1305 via libsodium, AES-256-GCM fallback). Encrypted columns are `*_enc` VARBINARY;
   searchable ones also have a `*_bidx` blind index. The base repository handles encrypt-on-write /
   decrypt-on-read via `encryptedColumns()` / `blindIndexColumns()`.
   **CRITICAL:** the encryption **AAD is `tenantId | table | column`** (see `TenantRepository::aadFor`).
   It does **not** reference the app name or any constant — which is why the product rename
   was safe. If you ever change how the AAD is built, you will make all existing encrypted data
   undecryptable. Don't. If you must, provide a migration and re-verify decryption end-to-end.
   Encryption keys live in `app/config.php` (see §6).

3. **Field-level security** (`app/src/SensitiveFields.php`): DOB and SSN-**last-4-only** (full SSN is
   never stored — a pasted 9-digit value is truncated). Resolution order for visibility: explicit
   per-role grant (allow/deny) → `pii.view` capability → default deny. Master Admin cannot be denied.
   Non-permitted users see a mask AND cannot write the field. These fields are **excluded by
   construction** from packets, exports, tenant reports, emails, audit detail, and all sharing — there
   is a `SensitiveFields::strip()` helper and the exclusion is asserted by tests. Custom fields can also
   be marked sensitive (encrypted, masked, excluded). Adding a new protected field = one entry in
   `SensitiveFields::FIELDS`, no new plumbing.

4. **Capabilities & roles:** authorization is capability-based (`app/src/Capabilities.php`,
   `Authz::can()` / `Authz::requireCap()`). Master Admin = `*`. Roles are customizable per tenant.

5. **Support sessions are blind to provider data.** When a platform operator enters a tenant with
   consent, `SupportAccess::active()` is true and provider-linked repositories return nothing. 19 of 26
   repositories carry this guard — every one that holds provider-linked data. If you add a repository
   holding PHI-adjacent data, add the guard.

6. **CSRF on every POST.** `CsrfGuard::verify()` in handlers, `CsrfGuard::field()` in forms. **Never
   nest `<form>` tags** — HTML forbids it and it silently breaks submits (this caused a real bug).

7. **Output escaping:** always `e()` user-controlled values in views. The only raw echoes are
   pre-escaped HTML builders (e.g. `CustomFieldService::render`).

8. **Migration-safety:** code must not assume a migration has run. Detect columns/tables when a feature
   depends on a recent migration and degrade cleanly (see `CustomFieldService::hasSensitiveColumns()`).
   A `DROP CONSTRAINT` that throws on a DB lacking it once aborted a whole seed — seed statements are
   now individually fault-tolerant. Learn from this: non-idempotent DDL must be defensive.

---

## 4. The release gate (run before EVERY release — no exceptions)

The dev harness is `/home/claude/harness.sh` with two targets:
- `./harness.sh install` — resets the dev DB and installs fresh (note: it changes the working
  directory and re-seeds, so re-`cd` and re-login after running it).
- `./harness.sh full` — install + route smoke test. Must print `ALL_ROUTES_PASS`.

**Ship checklist (all must pass):**
1. `./harness.sh full` → `ALL_ROUTES_PASS`
2. `bash tools/check_isolation.sh` → isolation passes
3. `php -l` clean on every changed file (ideally all 232)
4. If crypto/repository touched: **round-trip encrypt+decrypt of NPI, SSN, DOB** verified
5. Spanish parity: 100% coverage (see §5)
6. Bump `FLXCOREHC_VERSION` in `app/bootstrap.php`
7. Add a changelog entry to `INSTALL.md` (newest first) and regenerate `docs/CHANGELOG.md`
8. **Update this file's version line and "Current state" section**
9. Package (see §7), do a fresh-install test from the zip, then present the zip

**Verify visually, not just structurally.** UI changes must be checked by rendering (the sandbox has
Playwright/chromium). Several bugs shipped because element-exists checks passed while the page looked
wrong (a CSS-variable mismatch made dark-mode text invisible; a grid-column panel rendered full-width).
Render it and look.

---

## 5. Bilingual parity (English/Spanish)

Every user-facing string goes through `t('...')`. `app/lang/es.php` must have a key for every English
`t()` source string — target 100% coverage. After any change that adds or edits `t()` strings, re-run
the coverage check (grep all `t('...')` sources across views/services/index/bootstrap, compare against
es.php keys, add any missing). Help content lives in `app/help/{en,es}.php` and must stay in sync too.
When you rename a string that appears inside a `t()` call, the es.php **key** must change with it or the
translation silently stops matching.

---

## 6. Config, secrets & data safety

- `app/config.php` holds DB credentials **and the encryption keys**. It is **git-ignored and stripped
  from every package** — it must never travel in a zip or be shown to an AI tool. A fresh install
  generates its own.
- **Consequence:** a new install has new keys and cannot read data encrypted under a different install's
  keys. Fine for a fresh/staging DB; a landmine if you point a new install at an old encrypted DB.
- **Before any deploy or migration on production: back up the database AND `app/config.php`** somewhere
  off-server. Losing `config.php` means losing the ability to decrypt SSNs/DOBs. Keep `config.php` at
  permission 600 and out of any unencrypted backup.

---

## 7. Packaging (how a shippable zip is built)

From the working tree:
1. Clear `storage/` transient dirs; keep `.keep` files.
2. Copy the tree; from the copy remove: `app/config.php`, `rt.php`, `config.php`, `_router.php`,
   `.git/`, `storage/`. (These are dev-only or secret.)
3. `zip -rqX flxcorehc-vX_Y_Z.zip . -x ".git/*" -x "storage/*"`
4. Confirm no leftovers (`app/config.php`, dev routers, `storage/docs/<uuid>` files) are in the zip.
5. Fresh-install the zip into a scratch DB and confirm it completes and seeds.
6. `present_files` the zip; delete the previous version's zip from outputs.

The dev routers (`_router.php`, `rt.php`) exist only for the sandbox's built-in server; production uses
`index.php` directly, so they are excluded.

---

## 8. Deployment model (as the owner runs it)

Staging → production promotion:
- **Staging** = the current install (has test/demo data; disposable/re-seedable). Deploy every new zip
  here first and run the test plan.
- **Production** = a separate instance, **separate database, separate `app/config.php` (separate
  keys), separate subdomain**. Never shares config/keys with staging.
- Promote the **exact tested zip** — not a rebuild — to production.
- **Upgrade (keep data):** keep production's `app/config.php`, replace all other files, run *Update
  database* if the release added a migration, users re-login once if the session cookie changed.
- **Fresh install (blank slate):** drop DB, run installer from the zip; it seeds the 14 provider-type
  compliance templates, ~22 PR regulators, calendar types, and (optionally) demo data.

---

## 9. Current state (v1.162.0) — keep this updated

**Shipped and working:**
- Multi-tenant core: providers, credentials, documents (upload/photo/scan, checksums), calendar with
  iCal, notifications, audit log, activity pulse/digest, data export.
- **14 provider-type compliance templates** (Physician, NP, PA, Dentist, Nurse, Pharmacist, Lab, etc.),
  auto-suggested by provider type; "licence held" only re-orders suggestions.
- **Guided onboarding** wizard with back-navigation and clickable steps.
- **Compliance levels 0–5** with hard stops (expired/missing license or exclusion flag → L0).
- **Payers / program enrollment / OCS-SICRO** with PR specifics (Medicaid PEP location rule, revalidation).
- **Verification records (tracker — FlxCoreHC does not certify primary-source verification):** Tier A public-registry lookup (NPPES re-check, SAM.gov real-time) + Tier B organization-recorded (deep-link + record) for sources with no API. SAM match feeds the compliance hard stop.
- **Regulatory grounding:** requirements cite their dated legal source inline ("Why?"). PR sources
  verified (ORCPS 3-yr recert, CE via OA 542/544 — CE is NOT waived, OCS Ley 73-2023, Medicaid PEP,
  telehealth Ley 8-2025, Ley 102-2025 universal recognition — marked evolving).
- **PR regulator directory:** ~20 examining boards under ORCPS + oversight agencies (OCS, ASES, ASSMCA);
  6 with dated source citations.
- **Addresses** (multiple per tenant/provider, distinct from locations; citable on credentials) and
  **office contacts** (no portal account; receive document requests / reminders in their language).
- **Application data** (identity/identifier fields for CAQH/OCS/PEP/855) + **education & work history**;
  **Application Readiness** score and checklist.
- **Custom fields** (definitions + values, 9 types, no schema change; can be marked sensitive).
- **Sensitive fields** (DOB, SSN last-4) with **field-level security** (per-role allow/deny, `pii.view`,
  masked/excluded everywhere outbound).
- **External sharing** (tokenized, password, expiry, access log) and **cross-organization sharing**
  (3-party consent, live read-only per-credential shares).
- **Tenant report** (PDF + CSV bundle) — counts/account data only, never provider identities.
- **Microsoft 365 modern auth** (one Azure app; platform + per-tenant OAuth; Graph sendMail).
- **Platform console:** tenants, approvals, users, invoices, platform data (LEIE/SAM/Medicaid imports),
  regulatory sources editor, translations editor, security posture, database doctor, changelog,
  capabilities, test plan, demo accounts, email/M365 setup, "Sync templates & regulators".
- **Credential checklist is the core screen** (`ChecklistService` + `app/views/holders/_checklist.php`):
  ONE unified list per provider — each row is a required credential showing status (on file / expiring /
  expired / needed) + expiry + a single action (View / Renew / Add document). Selecting a **profession
  auto-applies its requirement template** (`TemplateService::applyByProviderType`, fired on create,
  quick-add, edit, import, onboarding). Do NOT reintroduce a separate "requirements" vs "credentials"
  split — they are deliberately merged. "Add document" carries `?return=holder` so saving lands back on
  the checklist with the row satisfied.
- **Management dashboard** (`DashboardService` + `app/views/dashboard.php`): drillable KPIs, SVG donut/bars,
  prioritized work queue, and a session-based `← Back to dashboard` return bar (layout.php, keyed on
  `?from=dashboard`). The dashboard is the process hub — extend the queue there, not with new pages.
- Grouped right-side hub navigation (collapsible, closable edge-tab toggle, dark-mode correct, mobile drawer).
- Security: two full audits (see `docs/CODE_AUDIT_REPORT.md`), `docs/.htaccess` protects docs from web.

**Queued / not yet built:**
- **AI knowledge-layer agent** — the reason "Intelligence" is in the tagline. Recommended design: a
  dedicated `knowledge_entries` store fed FROM the training library + `requirement_sources` + catalog
  rules (retrieval-augmented, always cite, never free-associate), answering only from verified content
  with a visible "not legal advice" boundary. This is what will *earn* the word "Intelligence" — build
  it to genuinely analyze (flag patterns, predict lapses, recommend fixes), not just chat. Decide the
  agent's scope and the tagline together.
- **Florida** regulators/sources — deferred, and must be **source-verified first** (do not bulk-load
  names; the PR discipline of verifying against primary board publications applies).
- Address-consistency check across NPI/OCS/Medicaid/payers (addresses are now citable on credentials —
  the groundwork is in).
- Deeper source citations for the PR boards that are currently directory-only records.

**Known process lessons (do not relearn the hard way):**
- Don't fold a rename or other cross-cutting change into a feature batch — do it isolated, with the gate.
- The dev harness re-installs the DB between runs; a holder created in one command block is gone in the
  next if you re-run `install` — keep verification self-contained.
- `platform_settings` is a key-value table (`skey`/`sval`, text); encrypted values there are base64'd.
- Verify against the *right* table — provider requirements live in `producer_requirements`, not
  `holder_requirements` (a mis-check once looked like a bug that wasn't).

---

## 10. Working style the owner expects

- Be honest and direct; flag risks and overclaims rather than agreeing by default (e.g. the "Intelligence"
  tagline was pushed back on twice before the owner confirmed the call).
- Investigate before changing — read the actual code/schema, don't assume.
- Verify end-to-end and say what was tested. Distinguish "I confirmed X" from "X should work."
- When something is the owner's business/brand/legal call, lay out tradeoffs and let them decide.
- Ship complete: code + tests + docs + changelog + this file, gate green, fresh-install verified.
