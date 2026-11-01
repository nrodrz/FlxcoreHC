# FlxCoreHC Healthcare Edition — Product & Feature Documentation (current — see FLXCOREHC_VERSION)

**Audience:** the AI assistant (or developer) continuing this app's development in its own chat, alongside `KICKOFF.md` (working rules) and the release zip. The kickoff says *how we work*; this document says *what the app is*.

---

## 1. Purpose

A multi-tenant SaaS for **healthcare credentialing and license tracking**, sold to **billing/credentialing companies, medical groups, and solo practices** that manage compliance for doctors, nurses, laboratories, clinics, and medical groups across the US **and Puerto Rico**.

The founding client is a real billing company that tracks licenses for many providers. Their pain: credential expirations scattered across spreadsheets, no single source of truth, providers chased by email, nothing audit-ready.

**Scope boundary (deliberate):** this app is a **tracker** — it tracks and manages, it does not submit, and it is **not NCQA-grade credentialing**. There is no CAQH, PECOS, or payer application integration, no NPDB query, no committee-decision record, no NCQA timeliness enforcement, and no primary-source verification (PSV) automation beyond the NPPES NPI lookup and OIG/SAM exclusion screening (see `docs/architecture/NCQA_CREDENTIALING_ROADMAP.md` for the plan-only path). Marketing and UI must not imply otherwise. Those stay in the client's existing workflow; this app is the system of record for *what each provider holds, what's expiring, and what's missing*. Do not add submission or PSV features unless Nef explicitly asks (PSV/NIPR-style verification is additionally gated on an FCRA legal review).

## 2. The core loop

1. A tenant (billing company) onboards its **providers** (individuals and organizations).
2. Each provider accrues **credentials** — typed records (license, DEA, CLIA, malpractice…) with issuer, number (encrypted), dates, and uploaded **documents** as evidence.
3. **Templates** define what a provider of a given type must hold; applying one generates **requirements**, and compliance posture is computed from requirements + credential statuses (active / expiring / expired).
4. **Reminders** fire automatically at thresholds, and staff can send a per-provider summary nudge on demand.
5. Staff pull **reports** (Excel-like grid + CSV) and **CV/credential-summary PDFs**; providers self-serve through their own portal.

## 3. Users, roles, capabilities

Two orthogonal axes:

- **Platform admin** (`users.platform_admin=1`): the SaaS operator (Nef). Sees `/admin` — tenants list with industry chips, system & updates, error log, demo builders, impersonation, backups.
- **Tenant roles** (`RoleService::SYSTEM_ROLES`): `tenant_owner`, `manager`, `staff`, `producer` — plus tenant-defined custom roles built from **capabilities** (`Capabilities.php`): `records.write/delete`, `documents.view/manage`, `org.manage`, `team.manage`, `billing.view/manage`, `audit.view`, `share.manage`, `npi.lookup`, plus insurance-only ones (`soa.write`, `bob.write`) that are inert here.
- The `producer` role is the **provider portal** login: locked by a route guard to `me, training, logout, documents, account, interactions, beneficiaries, soa-forms, policies` (the last three are insurance leftovers, unreachable in practice). Providers see only their own record.

**Vocabulary:** `Vocab.php` maps UI terms per vertical — here holders are "Providers", partners are "Payers", etc. Always use `vocab()` in views rather than hardcoding.

## 4. Domain model (main tables)

| Concept | Table | Notes |
|---|---|---|
| Tenant | `tenants` | `vertical='healthcare'`, `org_shape` ∈ billing_company/medical_group/solo_practice, `operating_states` (e.g. `FL,PR`), `is_demo`, plan/billing fields |
| Provider | `credential_holders` | `holder_type` individual/organization, `provider_type` ∈ physician/nurse/lab/clinic/group, encrypted `npi_enc`/`email_enc` (+ blind indexes), plaintext names, `birth_month`, `role_title`, `license_code`, `template_id` |
| Credential | `credentials` | `credential_type` (catalog code), `name`, `issuing_body`, encrypted `credential_number_enc`, `issued_date`, `expiry_date`, `status` active/expiring/expired, `category` |
| Catalog | `credential_catalog` | Global rows (`tenant_id NULL`) + tenant custom rows; `vertical` column; healthcare set: npi, state_medical_license, dea, state_cds, board_cert, malpractice, caqh, cme, clia, npi_type2, ein, medicare_ptan, lab-specific, and 5 Puerto Rico rows (cited to 20 LPRA / PR boards) |
| Template | `requirement_templates` | `applies_to` ∈ physician/nurse/lab/clinic/group (validated per-vertical via `Vocab::appliesToOptions`), `line` = care setting (all/outpatient/facility), optional `state`, itemized template items |
| Requirement | `producer_requirements` (+ `carrier_requirements`, insurance-legacy) | Per-holder required items with statuses; drives compliance % and gaps |
| Document | `credential_documents` | Uploaded evidence; content-sniffed MIME, UUID filenames, tenant-scoped dir, 10 MB cap |
| Reminder | `email_outbox` + reminder state | Threshold reminders (config `reminder_days`), plus staff "Send reminder" per-provider summary |
| Share link | share links (`/verify/...`) | Tokenized read-only roster/summary verification for outside parties |
| Audit | `audit_logs` | Writes on exports, CV generation, nudges, onboarding, impersonation, etc. |
| Error log | `error_log` | `flx_log_error()` wired into exception/shutdown handlers; admin Errors tab |

**Encryption (Crypto.php):** XChaCha20-Poly1305 (sodium) or AES-GCM with keys in config, bound to tenant+table+column. Encrypted: `credential_holders.npi/npn/email` (+ `_bidx` blind indexes), `credentials.credential_number`, `addresses.line1/line2/phone`, TOTP secrets, and uploaded documents (`EncryptedFile`). Names are plaintext by design. Keys are recoverable only from a passphrase-sealed key backup (`KeyBackup`, `database/restore_keys.php`).

## 5. Feature tour (healthcare tenant's nav)

**Dashboard** — compliance posture tiles, expiring/expired list, onboarding checklist partial.

**Providers** (`/holders`) — list with vocab-aware columns; **two creation paths**: quick "Add" form (auto-provisions portal login for individuals) and the **Guided onboarding wizard** (`/holders/onboard`): Who → Identifiers → Requirements (template suggestions matched by `provider_type` via `CatalogService::holderRole`) → Portal access (explicit invite choice). Provider page: credentials, documents, CE, requirement checklist, **CV (PDF)** and **Credential package (PDF)** buttons, share links, reminder nudge.

**Credentials** (`/credentials`) — cross-provider credential list; create/edit with vertical-filtered catalog picker (custom types addable; retiring a custom type keeps its label on existing records via `labelsFor`).

**Compliance** (`/compliance`, `/compliance/producers`) — per-provider posture, graded score, gaps; **Send reminder** button (credential-gated) emails one summary of everything expiring/expired; on-demand reminder sweep.

**Entity compliance** (`/entity`) — organization-level items (the org's own EIN, licenses…).

**Templates** (`/templates`) — grouped by provider type ("Physician", "Laboratory"…); headings "Setting" / "Anchoring credential"; create validates `applies_to`/`line` against the healthcare sets.

**Training** (`/training`) — healthcare reference library (9 seeded articles: credentialing vs enrollment, NPI Type 1/2, state licenses & PR, DEA/CDS, board cert & CME, malpractice, CAQH ProView, CLIA, expiry watch-list strategy), each linking to authoritative sources; "Ask" search; tenants add their own SOPs/resources. Insurance articles exist in the table but are vertical-filtered out and route-guarded.

**Reports** (`/reports`, `/reports/credentials`) — the **Excel-like credential report**: server filters (status/type/provider/window/evidence/provider-type) narrow the dataset + CSV export (formula-escaped, audited); client grid adds click-sort, free-text search, per-column filters, column show/hide (issuer/number/issued hidden by default), sticky header, striping, live count, pagination.

**Provider portal** (`/me`) — own compliance, credentials, documents upload, **My profile** self-service intake (NPI, birth month, provider type, role — staff fields read-only), **My CV (PDF)**, healthcare Training Center.

**Organization** (`/organization/*`) — profile, **Operating jurisdictions** (states incl. PR/VI/GU; the insurance "lines of business" section is hidden), documents-tracked (catalog management), branding/theme, domain.

**Team** (`/team`) — members, invites, custom roles from capabilities.

**Settings / Billing** — plan tiers, Stripe checkout (config keys), trial/grace windows, seat logic (`producer_seats_billable`).

**Admin (platform)** (`/admin`) — tenants (industry chip), system & updates (version, migrations, **Demonstration data — healthcare**: one-click build/teardown of **Coastal Credentialing Partners**, a complete demo billing company: FL+PR, 5 providers of 4 types, 25 credentials across statuses, owner + 2 provider portal logins, roster share link), error log, backups, impersonation (audited).

**Calendar / Scheduling / Documents / Audit** — booking with reminders cron, document library, audit trail.

## 6. Edition lock (why no MA content appears)

`FLXCRED_EDITION='healthcare'` (bootstrap) + `FLX_INSTALL_EDITION` (installer) restrict org shapes via `edition_shapes()` at registration, install, and `AuthService::registerTenant()`; the insurance demo builder is hidden and its action blocked. Since insurance tenants can't exist, per-tenant vertical gating (nav, training, templates, vocab) renders only healthcare UI. **Insurance/MA code in the tree (AEP, RTS, SOA, carriers, beneficiaries, book/commissions, CMS sync cron, DemoDataService…) is inert** — prune incrementally, only when asked. See `SPLIT.md`.

## 7. Operations

- **Install:** upload zip, browse `install.php` (writes config with fresh crypto keys, runs the migrations in `database/manifest.php` order, creates first org — healthcare shapes only). Delete `install.php` after.
- **Config flags** (`app/config.php`): mail_enabled, allow_open_registration, app_url, Stripe keys, trial_days/grace_days, reminder_days `[180,90,30]`, expiring_window_days 90, crypto keys, session timeouts, force_secure_cookies, docs_path.
- **Cron:** `send_reminders.php` (expiry reminders), `sweep_expiring.php` (status transitions), `send_booking_reminders.php`; `cms_sync.php` is insurance-only (inert). Reminders and queued mail are sent by cron and POST actions only (no GET side effects).
- **Backups:** admin-triggered SQL dump via `BackupService` (plain, or sealed with a passphrase — open with `database/decrypt_dump.php`), document archive, and the passphrase-sealed key backup.
- **Prod (pre-split, shared app):** `hub.employu.info/flxcred`, DB `employu_flxcred`, PHP 8.3 via cPanel MultiPHP. The split apps will get their own paths/DBs — never share a DB between editions.

## 8. Known gaps & deferred (do not build unprompted)

- Phase 0 discovery with the real billing-company client = highest-value next step (show demo org, report, CVs; collect their actual document list).
- Pruning inert insurance code; compliance PDF needs Nef's read-through; PSV/CAQH/NIPR/NPDB/committee workflow deliberately out of scope. Test suites: `bash tools/runtime_check.sh` (security, authz, agency, purge, migrations, characterization, HTTP smoke).

---
*Companion files: `KICKOFF.md` (working rules), `SPLIT.md`, `docs/CHANGELOG.md` (release history), `docs/HANDOFF.md`.*
