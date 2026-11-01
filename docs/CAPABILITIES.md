# FlxCoreHC — Master Capabilities & Features

*Healthcare Compliance Intelligence Platform — a credentials & compliance support system (not a credentialing authority).*

*Version 1.162.0 · September 2026 · The complete map of what the platform does. Companion documents:
`TEST_PLAN.md` (how to verify it), `DEMO_ACCOUNTS.md` (logins), `CROSS_TENANT_SHARING.md`,
`CODE_AUDIT_REPORT.md`, `INSTALL.md` (release history).*

---

## What FlxCoreHC is

A **Healthcare Provider Credentialing & Regulatory Compliance Platform** built for Puerto Rico and
the U.S. healthcare market. It tracks every license, certification, training, insurance, identifier,
enrollment and document a provider must keep current, turns their dates into a calendar and alerts,
scores compliance on a five-level ladder with hard stops, and keeps a permanent, auditable record of
everything that ever happened — across years and across organizations, without any organization
ever seeing another's data unless everyone consents.

**Who uses it:** medical billing companies (managing many client groups), solo practices, medical
groups, IPAs with multiple locations, specialty groups (e.g. anesthesiology), hospitals by department
— each as a separate, isolated organization (tenant) — plus the providers themselves through a
portal, and the platform operator through a console.

**Stack:** PHP 8 / MariaDB on shared cPanel hosting. No Composer, no shell required. Installs from a
zip with a browser installer; upgrades by replacing files and clicking *Update database*.

---

## 1. Providers

- **Provider types (14):** physician, NP, PA, nurse, dentist, pharmacist, therapist (PT/OT/SLP),
  behavioral health, chiropractor, technologist, other licensed professional, laboratory, clinic,
  medical group. Type drives which requirement template applies.
- **Identity:** NPI (looked up and validated against CMS NPPES), name, specialty, contact. NPI and
  email are **encrypted at rest** with blind indexes for matching.
- **Add one at a time** or **bulk import from CSV** (template provided; group and location columns
  matched by name or auto-created; row-by-row preview with plain-language errors; import the good
  rows while fixing the rest).
- **Groups** (client groups for billing companies, departments for hospitals) and **Locations**
  (practice sites with address and organizational NPI). A provider can belong to several of each,
  one marked primary. Lists, calendar and reports filter by both.
- **Provider page** — the hub: credentials, documents, payers & enrollment, compliance level,
  document requests, external shares, cross-organization links, history, and the **Profile packet**
  (print/PDF dossier for payer enrollment or audits: identity, every credential with number/issuer/
  dates, documents with verification, screening results, required items missing).
- **Telehealth flag** (Ley 8-2025: a current PR license suffices; no separate certification).
- **Addresses** — any number per organization and per provider (mailing, billing, correspondence,
  service, legal, home), labelled and dated, one primary; distinct from practice locations. A
  credential can cite the address on file with its issuer/payer.
- **Office contacts** — office manager, credentialing coordinator, billing contact per provider, with
  role, encrypted email, phone and preferred language, **no portal account**. Flags route document
  requests and expiry reminders to them (in their language).
- **Application data** — the identity and identifier fields applications ask for (middle name, suffix,
  prior names, gender, languages, specialties, taxonomy, CAQH ID, PTAN, Medicaid ID, hospital
  affiliations, accepting new patients), plus **education & training** and **work history** (with gap
  explanations) as repeating records.
- **Sensitive identity data with field-level permissions** — date of birth and **SSN last-4 only**
  (a pasted full SSN is truncated), encrypted at rest, revealed only to roles holding `pii.view`
  (Master Admin by default) and masked for everyone else, every view access-logged, and excluded by
  construction from packets, exports, tenant reports, emails, audit detail and all sharing.
- **Per-field rules by role** — in the role editor (built-in roles included) each protected field can be
  set to Inherit / Allow / Deny; Deny overrides the capability; Master Admin cannot be denied.
- **Sensitive custom fields** — a tenant-defined field marked Sensitive is encrypted, hidden (not just
  masked) from non-permitted users who also cannot change it, forced out of packets/lists/exports, and
  grantable per role like the built-ins.
- **Application readiness** — per-provider score and grouped checklist of everything CAQH, OCS/SICRO,
  Medicaid PEP, Medicare and payer applications require, each gap pointing to where to fix it.
- **Custom fields** — each organization defines its own fields for providers or the organization
  (9 types, required flag, choice lists, packet/list visibility) with no schema change; rendered on
  forms, included in packets and exports; retirable without data loss.

## 2. Credentials & renewals

- **Catalog of 26 document types** across categories (license, certification, training, insurance,
  identifier…) including PR-specific: Good Standing (ORCPS/JLDM), Buena Conducta (police
  certificate), ASUME (child-support certificate), DEA, CAQH, CME, CLIA, malpractice. **Custom
  types** per organization with renewal cycle and jurisdiction.
- **Renewal cycles**: enter an issue date and the expiry is derived from the cycle; edit any time.
- **Status** derived from dates: Active / Expiring (configurable window, 90 days) / Expired.
- **Bulk credential import from CSV** (match by NPI or name; expiry derived from cycle when blank).
- **Requirement templates** per professional type (14 seeded, editable): what each profession must
  have on file. Applied to a provider, the compliance engine scores required items present /
  expiring / missing.
- **Regulators** per provider (boards, agencies) with their own requirements.

## 3. Documents, verification & mobile capture

- **Multiple documents per credential**, each with a kind (Primary, Renewal, Supporting,
  Correspondence, Other). A new primary/renewal supersedes the prior one — the chain is retained.
- **Verification record** per document (recorded by your organization; FlxCoreHC does not certify primary-source verification): method, verifier, date, note, and a **re-verification
  date** that lands on the calendar.
- **Source verification, matched to what each authority offers** — *automated* where a real public
  API exists (NPPES NPI re-check with mismatch detection; SAM.gov real-time exclusion check with a
  free key) and *assisted-manual* where none does (ORCPS Good Standing, OCS/SICRO, Medicaid PEP,
  Medicare PECOS: deep-link to the official page, confirm, record with evidence). A SAM match feeds
  the exclusion hard stop. Every verification is stamped and carries a re-verify cadence. OIG LEIE
  continues via the monthly-file import (OIG offers no API).
- **Mobile:** *Take photo* opens the camera; photos are enhanced, downscaled and HEIC-converted on
  the device; JPEG metadata (including GPS) is stripped server-side. *Scan (multi-page)* combines
  several captures into one PDF in the browser.
- Uploads: MIME-sniffed allowlist, 10 MB cap, random names outside the web root, served only through
  authorized routes; every download logged.

## 4. Payers, government programs & OCS (Puerto Rico credentialing)

- **Payer credentialing** per provider per payer: status ladder (not started → application pending →
  under review → credentialed → contracted → participating; denied / suspended / terminated /
  recredentialing / expired), application / approval / effective / recredentialing / termination
  dates, contract and payer provider IDs. **PR payers pre-loaded:** Triple-S, MCS, First Medical,
  Menonita, MMM, MAPFRE, Humana, Plan Vital (ASES), Medicare; organizations add their own.
- **Medicaid / Plan Vital (PEP)** and **Medicare (PECOS/PTAN)** enrollment: status, IDs, effective
  and **revalidation** dates (PEP: 5 years medical / 3 years non-medical since 2023; PECOS: 5 years),
  enrollment type (individual / group / ordering-referring), and the PEP **service-locations
  registered** flag (claims deny since 2024-05-15 otherwise).
- **OCS / SICRO** centralized credentialing profile (Ley 73-2023, compulsory): status, profile ID,
  last attestation and expiry, delegate/CVO, notarized affidavit (institutions, Phase II).
- All recredentialing, revalidation and attestation dates flow to the calendar and reminders.

## 5. Compliance engine
- **Management dashboard** — a process hub, not a display: billability %, revenue-at-risk, expired /
  expiring / due KPI tiles; a compliance-level donut and a 6-month expiry-forecast bar chart (pure SVG,
  no library); a prioritized **work queue** (expired → not-compliant → expiring ≤30d → document reviews →
  re-verifications) where every row opens the exact record; a **← Back to dashboard** bar that follows
  the user into any record opened from the queue; 30-day throughput; blocked-providers list. Every
  number drills into the filtered list behind it.

- **Compliance score** per provider and readiness roll-ups by group, location and organization.
- **Five compliance levels** with **hard stops**: 0 Not compliant (license missing/expired/suspended
  or unresolved exclusion flag — overrides everything) → 1 Licensed → 2 Credentialed (template items
  on file, none expired) → 3 Payer-credentialed → 4 Billable (NPI + Medicaid/Medicare or effective
  contract) → 5 Fully compliant (OCS attested, nothing lapsing within 30 days). Each provider page
  states exactly what is needed for the next level.
- **Exclusion screening:** OIG LEIE, SAM.gov and state Medicaid lists imported by the operator;
  automated screening cron; flags create notifications, appear in the packet and hard-stop the level.
- **Regulatory sources:** the law / regulation / circular letter behind each requirement, with
  effective and superseded dates, review cadence and last review (seeded and source-verified for
  ORCPS recertification, Good Standing, OCS/SICRO, Medicaid PEP, Medicare PECOS, telehealth Ley
  8-2025, OIG LEIE, DEA). Visible to organizations; maintained in the console with "due for review"
  flags.

## 6. Calendar, reminders & notifications

- **Calendar** with Agenda (Overdue / this week / this month / later), Month and List views; filters
  by provider, group, location, type; drag to move; manual events; **iCalendar subscription** for
  Google/Outlook/Apple. Credential expiries, re-verifications, payer recredentialing, program
  revalidation and OCS attestation deadlines sync automatically.
- **Reminders** by email on a ladder (60/30/14/7 days by default; the `reminder_thresholds` setting in `app/config.php` changes it), in the organization's language.
- **Notification center** (bell): expiring/expired credentials, re-verifications, overdue items,
  screening flags, assignments, imports — respecting need-to-know.
- **Activity digest** email (daily or weekly) for owners/managers.

## 7. Provider portal & document requests

- Providers sign in to see their own credentials and status, download their CV, upload documents
  (camera/scan), and consent to cross-organization links.
- **Document requests:** the organization asks for a document; the provider gets an in-portal request
  and an email nudge (a link only — never attachments) and uploads directly; the organization
  Accepts (locks the document; provider keeps a read-only record) or Returns with a note.

## 8. Sharing — external and cross-organization

- **External sharing** (payers, state agencies, hospitals, others): a password-protected,
  time-limited (≤180 days), read-only profile link; password shown once and never emailed with the
  link; brute-force lock; every view/download/failed attempt logged with IP; revoke instantly.
- **Cross-organization sharing** (consent model): when another organization on FlxCoreHC manages the
  same provider (matched by NPI blind index — count only, no names), a link can be requested by a
  Master Admin, **consented by the provider**, and **approved by the other Master Admin**. Then the
  owner shares credentials **one at a time**, read-only and live (never a copy); un-shared items stay
  private; anyone can revoke; everything is logged in both organizations.

## 9. History, audit & activity

- **Permanent compliance timeline** per provider (append-only ledger, grouped by year): added,
  renewed (before → after), expired, verified, replaced, shared, payer/program/OCS status changes —
  with who and when. Export full history to CSV.
- **Master audit log** per organization: every action with user, IP and time; filter by date,
  person, record type, action, text; export.
- **Per-credential and per-document history** in place. **Activity dashboard** (today / 7 / 30 days:
  actions, people active, what happened, most active people, trend).
- Nothing is ever purged: soft deletes only.

## 10. Team, roles & privacy

- **17 capabilities** across records, documents, sharing, cross-org, export, reports, audit, team,
  billing. Built-in roles: Master Admin, Manager, Staff, Coordinator, Read-only, Provider portal;
  custom roles supported.
- **Need-to-know:** users see all providers or only those assigned to them — enforced in lists,
  calendar, notifications, document access and shares.
- **Platform support is blind:** if the operator needs to enter an organization, the Master Admin
  approves a 4-hour, logged window in which providers, credentials, documents, calendar, screenings,
  audit, notifications, requests, shares and data export are all inaccessible.
- **Tenant isolation:** all queries pass through a tenant-scoped repository layer; one allow-listed
  cross-organization reader with per-call re-verification; an automated isolation check runs in the
  release gate.

## 11. Reports & data ownership

- Compliance reports and risk ranking; **Readiness export (CSV)** by group / location / provider;
  Credential report with export; Profile packets; full history export.
- **Download all data (ZIP)**: providers, credentials, document index with checksums, groups,
  locations, memberships, team — the organization's data is never locked in.

## 12. Onboarding & email

- Dashboard **onboarding checklist** (team → locations → groups → import providers → import
  credentials → connect email → review calendar).
- **Per-organization email delivery** (own SMTP with Google / Microsoft / cPanel presets, test send,
  verified badge) or platform default. Invites and reminders are sent in the recipient's language.

## 13. Language

- **Full Spanish interface**: dictionary plus an in-app editable layer; flash messages, emails and the
  help center included. **Per-organization default language**; per-user override. Console →
  Translations groups strings by screen and collects anything still untranslated.

## 14. Mobile

- Responsive throughout: tables stack into cards on phones, forms collapse, camera/scan capture.

## 15. Help

- In-app **Help center** (19 sections, English and Spanish, searchable) covering every feature.

## 16. Platform console (SaaS operator)

- **Tenants:** create organizations of each shape (billing company, solo practice, medical group,
  hospital) with everything seeded; invite Master Admins; plans, seats, suspend/reactivate, custom
  domains, approvals. **Per-tenant Usage & health dashboard** (counts and dates only — never record
  contents).
- **Security:** live posture checks (transport, environment, files, access control, data protection,
  operations) plus the code-audit report.
- **Database:** live data dictionary of every table with purpose, sizes, indexes, tenant scoping,
  operator notes; nine health checks (migrations, scoping, engine, charset, indexes, orphans, sizes,
  outbox, retention).
- **Regulatory:** maintain the regulatory-source citations with review cadence.
- **Platform data:** LEIE / SAM / Medicaid exclusion imports. **Email**, **Translations**, **Errors**,
  **System** (update database, backups, demo builder, demo accounts, test plan).
- **Demo:** five populated organizations (billing company, solo office, group practice, IPA,
  anesthesia group) with every feature exercised and logins for every role.

## 17. Security summary

Parameterized SQL; Argon2id passwords; encryption at rest for identifiers, email, credential numbers
and SMTP passwords; CSP with per-request nonces, X-Frame-Options DENY, HSTS, nosniff, Referrer-Policy,
Permissions-Policy; CSRF on every form; rate-limited authentication and share passwords; audit and
access logs never purged; documents outside the web root; support sessions blind to tenant data;
release gate = full route harness + isolation check + fresh-install test.

---

## Puerto Rico regulatory grounding (source-verified, September 2026)

**Regulator directory (v1.160):** every healthcare organization is seeded with the Puerto Rico
examining boards under ORCPS — nursing, pharmacy, dental, psychology, professional counselors, medical
technologists, physical therapy, occupational therapy, speech-language/audiology, nutrition, radiologic
technologists, EMTs, physician assistants, chiropractors, optometrists and health-services
administrators — plus the oversight agencies ORCPS, OCS, ASES (Plan Vital), ASSMCA and JLDM. Six of
these carry dated, source-cited regulatory entries; the rest are directory records you can attach
requirements to.

| Requirement | Authority / instrument | What the platform does |
|---|---|---|
| Professional license recertification every 3 years; package includes CE, Buena Conducta, ASUME | PR Departamento de Salud — ORCPS | License credential with cycle; catalog types for the package items; Good Standing as its own credential |
| Centralized credentialing portal (SICRO), compulsory; Phase II institutions with notarized affidavit | OCS — Ley 73-2023; CC-2025-2044-D | OCS profile per provider with attestation expiry on the calendar |
| Medicaid enrollment and revalidation (5y / 3y); service-location registration | PRMP — PEP; 42 CFR §455.414 | Program enrollment with revalidation on the calendar; locations-registered flag |
| Medicare enrollment (PECOS, PTAN, 5-year revalidation) | CMS | Program enrollment with revalidation on the calendar |
| Telehealth: license suffices, no separate certification | Ley 168-2018 as amended by Ley 8-2025 | Telehealth flag (not a credential) |
| Exclusion screening | HHS-OIG LEIE (monthly file — no API), SAM.gov (public API), state Medicaid | LEIE file import + SAM.gov real-time check + hard stop |
| NPI verification | CMS NPPES (free public API) | Automated re-check with name/taxonomy/state mismatch flags |
| PR license / OCS / PEP / PECOS verification | ORCPS, OCS, PRMP, CMS — no public API | Guided "verify at source" deep-links + recorded result with evidence + re-verify cadence |
| DEA registration (3-year) for prescribers | DEA | Credential with cycle, on prescriber templates |

---

## Roadmap (not yet built)

Address-consistency check across NPI / OCS / Medicaid / payers; disciplinary-actions table;
facility privileges (hospital credentialing); OAuth/Graph email transport; OCR prefill from photos
(needs a BAA-covered provider); automated regulatory monitoring; WhatsApp channel (paused).
