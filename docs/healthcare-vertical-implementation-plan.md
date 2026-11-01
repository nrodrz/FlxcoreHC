# FlxCoreHC Healthcare Vertical — Implementation Plan

**Scope.** Enable a billing-company tenant to manage and track licenses and certifications for
providers (physicians, groups, labs, clinics) across the US and Puerto Rico, with a starter catalog
they can extend themselves. **Out of scope, deliberately:** any PECOS/CAQH submission, enrollment, or
primary-source-verification workflow. Tracking a CAQH *attestation date* is in; *doing* the
attestation is not.

**Companion document.** `healthcare-vertical-mapping.md` — the table-by-table analysis this plan is
built on. Read it first if you haven't.

**Guiding principle.** Ship in phases that each stand alone and each end in something testable and
usable. The billing company should be able to start getting value after Phase 2, before the whole
plan is done. Nothing here changes the insurance vertical's behavior — every change is gated by
`vertical = 'healthcare'` or is additive.

---

## What we already have (so the plan doesn't re-invent it)

Confirmed against the current codebase:

- `tenants.vertical` already permits `healthcare`; the vertical-branching pattern already exists in
  views (`credentials/form.php` even has a hardcoded healthcare credential list today).
- The tenant → holder → credential → document spine, the expiry/reminder engine, roster verification
  links, and the requirement-template machinery all work regardless of credential meaning.
- `CatalogService::all()` is static and stateless, called in ~14 places — a threadable change, not a
  rewrite.

The plan concentrates effort where the real gap is: making the catalog tenant-extensible, then
seeding and templating verified healthcare content.

---

## Phase 0 — Discovery & sign-off (no code)

**Goal.** Convert the billing company from "a client who might use this" into "a design partner whose
document list we're building." This de-risks every later phase by replacing guesses with their
reality.

- Walk the mapping document with them.
- Capture their **actual** tracked-document list per provider type (physician, NP, lab, clinic,
  group). This becomes the seed catalog — theirs, not the literature's.
- Confirm the US/PR split in their book and which PR-specific items they track.
- Confirm they'd run a pilot with a subset of their providers after Phase 2.

**Exit criteria.** A written list of document types by provider type, and a yes to piloting.
**Effort.** Conversation + write-up. No engineering.
**Risk if skipped.** You build a catalog from the literature that doesn't match their workflow, and
the pilot stalls on "that's not how we track it."

---

## Phase 1 — Tenant-extensible catalog (the keystone)

**Goal.** Let seeded catalog rows stay global while a tenant adds and manages their own custom
credential types. Nothing downstream can be seeded or templated until this exists, so it goes first.

**The change, concretely:**

1. **Schema.** Add `tenant_id CHAR(36) NULL` to `credential_catalog` (NULL = global/seeded, non-NULL
   = a tenant's custom type). Change the primary key from `code` to a surrogate `id` with a
   `UNIQUE (tenant_id, code)` constraint, so two tenants can each define a custom code without
   colliding, and a tenant custom code can shadow/extend the global set predictably. Add an
   `is_custom`/`created_by` marker mirroring the `is_seed` pattern already used on
   `requirement_templates`.
2. **Read path.** Make `CatalogService::all()` (and the resolve-by-code helpers `typeForCode`,
   `codeFor`) tenant-aware: return global rows + the current tenant's custom rows, never another
   tenant's. Thread the tenant through the ~14 call sites (greppable, mechanical).
3. **Isolation.** The catalog read now touches multiple tenants' rows (global + own), so add the
   read path to `tools/check_isolation.sh` allowlist, exactly as `DomainService` and
   `ErrorLogService` were.
4. **Custom-type CRUD.** A tenant-facing screen to add/edit/retire a custom credential type: name,
   category, renewal cadence, whether required, optional jurisdiction. Retire (soft-hide) rather than
   hard-delete, since credentials may reference it. Audit-log every change.

**Design decisions to lock before coding (from the mapping doc):**
- PK moves to surrogate `id`; `(tenant_id, code)` unique. Decide whether a tenant custom code may
  shadow a global code (recommendation: no — reject a custom code that collides with a global one, to
  avoid ambiguity).
- The resolve-by-bare-code helpers must never resolve across tenants.

**Exit criteria.** On a `healthcare` tenant, a user adds "X-ray Supervisor Cert," it appears in their
catalog and nowhere else; an `insurance` tenant is completely unaffected; isolation check passes;
re-running migrations is idempotent.
**Effort.** The single largest piece. One migration, `CatalogService` changes, ~14 call-site edits,
one new CRUD screen + route, isolation allowlist.
**Test focus.** Tenant isolation of custom rows (the thing that must not leak); insurance-vertical
regression (must be byte-identical behavior); idempotent migration on older MariaDB (per the recent
installer lessons).

---

## Phase 2 — Healthcare starter catalog + provider types

**Goal.** A `healthcare` tenant starts with a verified, seeded national catalog and can classify
providers by type. After this phase the billing company can actually track real providers — this is
the pilot-ready milestone.

1. **Seed the national catalog** (global rows, `state = NULL`), verified and cited like the FL/GA
   work: NPI, state professional license, DEA, state controlled-substance registration, board
   certification, malpractice/liability, CAQH attestation (date-tracked), CME, plus organizational
   entries (Type II NPI, EIN/CP-575, business license, group malpractice, Medicare PTAN) and the
   **CLIA certificate** for labs. Replace the hardcoded healthcare list in `credentials/form.php`
   with catalog-driven values.
2. **Provider type on the holder.** Add a `provider_type` value (physician, NP, RN, lab, clinic,
   group) — a small additive column on `credential_holders`, defaulting sensibly for existing rows.
   Individual vs organization already exists; this refines it for template selection.
3. **Watch the `notes` 400-char limit** — the FL/GA seeds hit it before; measure each seed string.

**Exit criteria.** A new healthcare tenant sees the national catalog on day one; a provider can be
typed; a lab shows CLIA, a physician shows DEA; the insurance vertical is untouched; fresh install
seeds cleanly.
**Effort.** Mostly content + seeding (research already substantially done in the mapping doc), one
small holder column, one view swap.
**Test focus.** Seed integrity (lengths, idempotency), catalog-driven form rendering, install on an
empty DB.

---

## Phase 3 — Provider-type templates

**Goal.** Different provider types default to different tracked-document bundles, built on the
existing `requirement_templates` machinery (no new mechanism).

- Seed a **physician**, **nurse**, **lab**, and **clinic** template as `is_seed` bundles of catalog
  items — the healthcare analog of the shipped insurance templates.
- Applying a template to a provider stamps their required set, exactly as it does for agents today.
- Templates remain editable; the tenant can clone and adjust per their workflow.

**Exit criteria.** Applying the "Laboratory" template to a lab holder populates CLIA + org NPI + the
lab set; applying "Physician" populates license + DEA + board cert + malpractice; both editable.
**Effort.** Content/seeding on existing machinery. Low structural risk.
**Test focus.** Template application produces the right required set per type; seeded templates are
idempotent.

---

## Phase 4 — Puerto Rico jurisdiction content

**Goal.** PR providers get their correct credential set. Same shape as adding Georgia was.

- **Reuse unchanged:** the seeded federal entries (NPI, DEA, CLIA — CLIA law names PR explicitly,
  Medicare participation is the same). These already apply.
- **Add PR-specific, verified & cited entries** (`state = 'PR'`): the Puerto Rico Board of Medical
  Licensure and Discipline license, certificate of good conduct (PR Police), and the PR-specific
  supporting certifications. Its own research pass, mirroring the GA effort.
- **Verify the `state` plumbing accepts `PR`** — it's a valid 2-letter code and the column is
  `VARCHAR(2)`, but check anywhere a US-50 list is hardcoded or validated against.

**Exit criteria.** A PR provider shows federal + PR-board credentials; a mainland provider is
unaffected; `PR` flows through operating-states, catalog filtering, and templates without special-casing.
**Effort.** Research + seeding + a plumbing audit. No new architecture.
**Test focus.** `PR` behaves as a first-class jurisdiction end to end; no US-50 assumption breaks it.

---

## Phase 5 — Vertical-aware vocabulary & polish

**Goal.** A healthcare tenant reads in healthcare language, not insurance language. Cosmetic but it's
what makes the buyer feel the product is *for them*.

- Vertical-driven labels: "provider" not "agent," "payer" not "carrier," "credentialing file" framing.
  Driven off the existing `vertical` flag and the branching pattern already in the views.
- Healthcare-appropriate empty states, help text, and the roster link's public wording.
- Confirm dashboards/exports (compliance PDF, roster page) read correctly for a provider roster.

**Exit criteria.** A walkthrough as a healthcare tenant shows no insurance-only vocabulary in the
primary flows; a walkthrough as an insurance tenant is unchanged.
**Effort.** Label/vocabulary layer + a review pass. Low risk, high perceived polish.

---

## Sequencing & why this order

Phase 1 is the keystone — literally nothing else can be seeded or templated until the catalog is
tenant-extensible, so it's first despite being the biggest. Phase 2 is the **pilot gate**: after it,
the billing company can track real providers and you can put the product in front of them. Phases 3–5
deepen and polish while the pilot runs and feeds back. Phase 4 (PR) is independent of 3 and can move
in parallel or swap order with it depending on how much of your client's book is PR.

**Recommended cadence:** 0 → 1 → 2 → (pilot starts) → 3 and 4 in either order → 5.

---

## Cross-cutting guardrails (apply to every phase)

- **The insurance vertical must not change behavior.** Every phase ends with an insurance-vertical
  regression check. If an insurance tenant sees any difference, that's a bug.
- **Tenant isolation is the highest-stakes property.** Custom catalog rows, provider data, documents
  — none may cross tenants. Every phase that touches shared reads updates the isolation allowlist and
  is tested for leakage explicitly.
- **Migrations stay idempotent and older-MariaDB-safe.** Per recent installer lessons: no
  `DROP CONSTRAINT IF EXISTS` (needs 10.5+), watch the `notes` 400-char limit, test fresh install on
  an empty DB and re-runs.
- **Verified content only.** Every seeded credential and jurisdiction rule is web-verified and cited,
  exactly as FL and GA were. No uncited numbers in a compliance product.
- **Encrypted-column discipline.** Any new PII follows the existing encrypted-column pattern; no
  plaintext PII columns, no querying encrypted columns directly in SQL.
- **Each phase ships as its own version** with a changelog entry, packaged and testable, not one
  giant merge.

---

## Honest risk register

- **Catalog PK migration (Phase 1)** is the one change that touches a shared, foundational table.
  Mitigation: surrogate id + unique constraint, thorough call-site sweep, heavy isolation + insurance
  regression testing before it ships.
- **Content accuracy (Phases 2–4)** — healthcare credentialing rules vary by state and issuer.
  Mitigation: seed the *common* national set confidently; treat state-by-state specifics the way GA
  was treated (verified, cited, added deliberately) rather than guessing 50 states at once.
- **Scope creep toward submission** — the gravitational pull of "while we're here, could we also
  submit to CAQH?" Mitigation: the scope boundary is explicit and defended; submission is a different
  product decision, not a phase.
- **Pilot feedback reshapes the catalog** — likely, and good. Mitigation: Phase 1's whole point is
  that they can extend the catalog themselves, so pilot-driven additions don't require your code.

---

## What I'd do first

Phase 0, then Phase 1. Phase 0 costs you a conversation and makes everything after it sharper. Phase 1
is the keystone and the biggest single piece, so starting it early leaves the most runway. Everything
from Phase 2 on is largely verified content on machinery you already own — the hard part is the
catalog, and the valuable part is your client telling you what belongs in it.
