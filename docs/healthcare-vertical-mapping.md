# FlxCoreHC → Healthcare Credentialing: Schema Mapping

**Purpose.** You have a billing-company client that manages and tracks licenses and certifications
for doctors, medical groups, laboratories, and clinics across the US and Puerto Rico. They do *not*
submit enrollments (no PECOS/CAQH integration) — they manage and track the documentation. This
document maps that use case against FlxCoreHC's existing schema so the size of the build is a fact, not
a guess.

**Headline finding.** The large majority of what a billing company needs, FlxCoreHC already has. The
work concentrates in **one** structural change (a tenant-extensible catalog) plus **content**
(a healthcare starter set and Puerto Rico research). No new architecture, no submission workflow.

---

## The billing company is the same shape as an FMO

Before the table-by-table, the structural insight that makes this fit: a billing company managing
credentials for many providers is the same shape as an FMO managing many agents.

| Insurance world (built) | Healthcare world (target) |
|---|---|
| FMO / agency (tenant) | Billing company (tenant) |
| Agent / downline producer (holder) | Provider — physician, nurse, lab, clinic (holder) |
| Carrier | Payer (tracked as a label, not integrated) |
| License, AHIP, E&O, appointment (credentials) | State license, DEA, board cert, malpractice, CLIA (credentials) |
| Statute-cited catalog (FL, GA) | Provider credentialing catalog (national + PR) |
| Roster verification link | Provider roster for an audit or payer |

The tenant → holder → credentials → documents spine is identical. Only the vocabulary and the
catalog content change.

---

## Table-by-table: carries over / extends / net-new

### `tenants` — **carries over as-is**
Already has a `vertical` column, and `healthcare` is already a permitted value (the CHECK constraint
was widened in migration 018 to `healthcare, insurance, custom, platform`). A billing-company tenant
is simply `vertical = 'healthcare'`. The `org_shape` and operating-states columns already exist. No
change.

### `credential_holders` — **carries over, minor extension**
Already supports both `individual` and `organization` holder types, already has `npi`, `org_name`,
`first_name`, and a per-holder `vertical`. A physician is an `individual`; a lab or clinic is an
`organization`. The one honest gap: providers come in **types** (physician, nurse practitioner, lab,
clinic, group) and each pulls a different default document set. Today the differentiation is
`holder_type` (individual/organization) — enough to start, but a `provider_type` value (or reuse of
an existing sub-type field) is the natural home for "physician vs lab vs clinic." Small, additive.

> Note: `first_name`, `last_name`, `npi`, `npn` are **encrypted columns**. A provider's SSN, if ever
> stored, must follow the same pattern. Do not add plaintext PII columns.

### `credentials` — **carries over as-is**
This is the heart, and it already fits. `credential_type`, `name`, `issuing_body`,
`credential_number`, `issued_date`, `expiry_date`, `status`, `category`, `last_verified_at`, and the
expiry index are exactly what a credentialing file needs. A DEA registration, a state license, a
board certification, a malpractice policy, a CLIA certificate — each is a row here, no differently
than an agent's AHIP or E&O. **No schema change.**

### `credential_documents` — **carries over as-is**
Stores the evidence PDF per credential: file path, original name, MIME, uploaded-by, tenant-scoped.
A provider's license scan or malpractice declaration page lands here exactly like an agent's
certificate. The producer-upload work already lets a holder attach their own evidence. **No change.**

### `credential_reminders` / expiry flagging — **carries over as-is**
The renewal/expiration engine is credential-agnostic — it works off `expiry_date`, not credential
meaning. A DEA registration expiring, a malpractice policy renewing, a CLIA certificate lapsing all
flow through the same reminder machinery that flags an expiring license today. **No change.**

### Roster / share-verification links — **carries over as-is**
The roster link (one URL verifying a whole downline, optionally scoped) maps directly to "show a
payer or auditor the status of all our providers." Same privacy-narrow design applies. **No change.**

### `requirement_templates` + `requirement_template_items` — **carries over, this is the mechanism**
This is how a bundle of required credentials gets applied to a holder. It already has `applies_to`,
a `state` column (added in migration 036), an `is_seed` flag distinguishing shipped-vs-custom, and
per-tenant scoping. **This is exactly the machine that makes "Dr vs lab vs clinic track different
things" work**: a physician template, a lab template, and a clinic template are three different
bundles of catalog items. The machinery exists; you author three bundles instead of one.

### `credential_catalog` — **THE ONE STRUCTURAL CHANGE**
This is the pivot point of the entire effort, so it gets its own section below.

---

## The catalog: the single change everything depends on

**Current state.** `credential_catalog` is **global**, not tenant-scoped. Its primary key is `code`
(no `tenant_id`), and `CatalogService::all()` reads every row with no tenant filter. It is seeded
**only by migrations** — meaning today, only you (Nef), by shipping a migration, can add a credential
type. The FL and GA entries are code-seeded rows.

**Why that blocks the requirement.** You decided: *seed a starter set, let them extend it.* A billing
company must be able to add "we also track X for this provider" themselves. With a global,
migration-only catalog, they can't — every new document type would require you to ship code. That
breaks the whole value proposition of "the templates area absorbs new documents to track."

**The change.** Make the catalog tenant-extensible. Concretely, two moves:

1. Add an optional `tenant_id` to catalog entries (NULL = global/seeded, non-NULL = this tenant's
   custom type). Seeded rows stay global and shared; custom rows belong to one tenant.
2. Change the catalog read path so a tenant sees **global rows + their own custom rows**, never
   another tenant's. `CatalogService::all()` becomes tenant-aware. This also means the catalog read
   becomes a place that touches multiple tenants' data, so it joins the isolation allowlist the same
   way DomainService and ErrorLogService did.

**Why this is bounded, not scary.** It's the same pattern you already use everywhere else:
tenant-scoped rows with a shared/global tier. The `is_seed` flag on requirement_templates already
proves the "shipped vs custom, both editable" concept exists in the codebase. The catalog just needs
the same treatment. This is the one genuinely new piece of structure — and it's a known pattern, not
an invention.

**Second-order effects to plan for (not blockers):**
- The catalog PK is currently `code`. If two tenants both add a custom code "x_ray_cert", the PK
  collides. The key needs to become `(tenant_id, code)` or custom rows need a surrogate id. Minor,
  but must be decided up front.
- A few call sites resolve a catalog entry by bare `code` assuming global uniqueness
  (`typeForCode`, `codeFor`). Those need to become tenant-aware. Bounded, greppable.

---

## Genuinely new work, in priority order

1. **Tenant-extensible catalog** (the structural change above). Everything else depends on it.
2. **Healthcare starter catalog** — a verified national seed set (below), the healthcare equivalent
   of the Florida research.
3. **Provider-type templates** — physician / nurse / lab / clinic bundles built on the existing
   requirement_templates machinery.
4. **Puerto Rico credential content** — its own jurisdiction research (below), same shape as GA.
5. **Vertical-aware vocabulary** — "provider" not "agent," "payer" not "carrier," healthcare-flavored
   labels driven off the existing `vertical` flag. Cosmetic but important for not confusing the buyer.

**Explicitly NOT on the list** (you scoped these out, correctly): PECOS/CAQH submission, enrollment
workflow, primary-source verification automation, hospital privileging workflow. Tracking the CAQH
*attestation date* as a credential is in scope; *doing* the attestation is not.

---

## Healthcare starter catalog (national) — verified document set

From current credentialing sources, the standard provider credentialing file contains the following.
These become the seeded, global catalog entries a healthcare tenant starts with. (Renewal cadences
vary by issuer/state; the catalog already supports `renewal_months` NULL for non-fixed cycles.)

**Every provider (individual):**
- **NPI** (National Provider Identifier) — non-expiring identifier
- **State professional license** (medical, nursing, etc.) — periodic renewal, jurisdiction-specific
- **DEA registration** — for prescribers; ~36-month cycle
- **State controlled-substance registration** — where the state requires one separately from DEA
- **Board certification** — specialty board, with recertification dates
- **Malpractice / professional liability insurance** — policy with declaration page, renews annually
- **CAQH attestation** — track the profile and its re-attestation date (every ~90–120 days)
- **CME / continuing education** — hours per license/board cycle
- **Government ID**, **CV / work history**, **immunization/health records** — supporting documents

**Organizations (groups, clinics):**
- **Type II (organizational) NPI**, **Tax ID / EIN (CP-575)**, **business license**
- **Group-level malpractice**, **Medicare PTAN** (tracked as an identifier)

**Laboratories (specifically):**
- **CLIA certificate** — federal, required for any facility testing human specimens; renewal cycle
  tied to certificate type. Note: CLIA law explicitly includes Puerto Rico as a "State."
- **State laboratory license** — where the state has its own lab licensing on top of CLIA
- **Laboratory director qualifications** — for non-waived testing

These are the *documents to track*, not applications to submit — squarely inside your engine.

---

## Puerto Rico — its own jurisdiction, like Georgia was

PR is a real jurisdiction with its own content, not a footnote — and since you serve both US and PR
from day one, it's in scope now.

**What's the same as the states:** PR participates in Medicare/Medicaid, so PR providers carry the
**same federal credentials** — NPI, DEA, CLIA (federal law names PR explicitly), Medicare enrollment.
Those seeded national entries apply unchanged.

**What's PR-specific** (needs its own verified research pass, mirroring the GA effort):
- **PR medical license** — issued by the *Puerto Rico Board of Medical Licensure and Discipline*
  (Junta de Licenciamiento y Disciplina Médica), a distinct board under the PR Department of Health,
  with its own license categories (regular, reciprocity-based, National Board diplomate).
- **Certificate of good conduct** (Puerto Rico Police) — a PR-specific credentialing input.
- **PR-specific supporting certifications** (education sent directly to the PR Board, internship
  certification, etc.).

Architecturally this is identical to how you added Georgia: a jurisdiction value (`PR`) on
catalog/template rows, a set of verified, cited entries. The catalog's `state` column is `VARCHAR(2)`
— `PR` fits. The research is new; the mechanism is not.

> One thing to confirm during the build: the `state` column and its callers assume 2-letter USPS-style
> codes. `PR` is a valid 2-letter code, so it fits, but double-check any place that hardcodes a US-50
> list or validates against one.

---

## Honest summary of the lift

**Carries over untouched:** multi-tenancy, tenant/holder/credential/document spine, expiry & reminder
engine, roster verification links, requirement-template machinery, security model, error log,
producer(→provider) self-upload. This is most of the application.

**One structural change:** make `credential_catalog` tenant-extensible (global seed + per-tenant
custom rows, tenant-aware reads, PK adjustment, isolation-allowlist the read path). This is the piece
the whole "they extend it themselves" requirement rests on.

**Content work (research + seeding, no new architecture):** national healthcare starter catalog;
physician / nurse / lab / clinic templates; Puerto Rico jurisdiction entries; healthcare vocabulary.

**Deliberately out of scope:** any submission, enrollment, or primary-source-verification workflow.

The reason this is an *extension* and not a second product is that you scoped it to "manage and
track," which is precisely what the engine already does. The billing company doesn't need a new
machine — it needs the existing machine pointed at a new catalog it can extend.
