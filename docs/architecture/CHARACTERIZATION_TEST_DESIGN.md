# FlxCoreHC v1.166.0 — Characterization Test Design

Companion to `CHARACTERIZATION_TEST_BASELINE.md`. This document specifies **how** the four compliance
entry points are compared — the corrected model that measures genuine business-rule disagreement, not
incidental numeric differences. It is analysis only; no production code or schema is referenced for
change. All claims cite exact source locations in v1.166.0.

## 1. The error this design corrects
The original Phase 0 `capture.php` compared four values as if equivalent and flagged any numeric
difference as a "disagreement":
`ComplianceLevelService` level · `ChecklistService` done/total · `CatalogService` active-count ·
`flx_compliance_data` status. **These are four different business concepts.** A level (0–5 ladder) is
not a done-count; a coverage active-count is not a compliance percentage. Comparing them produces false
"disagreements" and hides real ones. The corrected model compares only values that the **source code
proves represent the same business decision.**

## 2. Four distinct layers (do not cross-compare)
| Layer | Question it answers | Engine(s) | Source |
|---|---|---|---|
| **A. Requirement coverage** | "Is required token X satisfied?" | `CatalogService::coverageDetail`; the per-row output of `ChecklistService::forProvider`; the per-token rows of `flx_compliance_data` | app/src/CatalogService.php:377; app/src/ChecklistService.php:27; index.php:4561 |
| **B. Overall compliance** | "What is the provider's overall standing?" | `ComplianceLevelService::evaluate` (ladder 0–5) **and, separately,** `flx_compliance_data` roll-up (%) | app/src/ComplianceLevelService.php:30; index.php ~4630 |
| **C. Checklist presentation** | "What do we show the user, and how many are done?" | `ChecklistService::forProvider` aggregate (done/total/pct) | app/src/ChecklistService.php:27 |
| **D. Legacy dashboard/report** | "Org-wide roll-up for dashboards" | `flx_compliance_data` producer/aggregate totals | index.php:4561 |

**Only Layer A is compared across engines** (per-token decisions). Layers B, C, D are captured
**separately** and never equated to each other.

## 3. Layer A — the requirement-coverage comparison model
Each engine is normalized to one decision per required token:
- **satisfied** = token status ∈ {active, expiring}
- **unsatisfied** = token status ∈ {missing, expired, absent-row}

Normalizers (in `capture.php`):
- `char_norm_coverage_from_detail($coverageDetail,$required)` — from `CatalogService::coverageDetail`
  (keyed token → {status, credential_id, credential_name, expiry_date}; absence ⇒ missing).
- `char_norm_coverage_from_checklist($checklist,$required)` — from `ChecklistService` rows
  (row.status per token; `is_extra` rows excluded).
- `char_norm_coverage_from_flx($flxRows,$required)` — from `flx_compliance_data` per-token rows.

Per token the design captures: **token/code · required? · coverage status · credential_id · credential
status · expiry date · checklist status · flx status (when the same token is identifiable)** — exactly
the fields this review requires.

Pairs compared: `CatalogService_vs_ChecklistService` and `CatalogService_vs_flx_compliance_data`.

**Proven structural fact (no runtime needed):** `flx_compliance_data` computes its coverage by calling
`CatalogService::coverageDetail` internally (index.php ~4618: `$cover = CatalogService::coverageDetail($creds)`).
So at Layer A, flx is **not independent** of CatalogService — they should AGREE unless flx fails to run
(see §6). ChecklistService also calls `coverageDetail` (app/src/ChecklistService.php), so Catalog vs
Checklist should agree at the decision level; any difference there is a REPRESENTATION_DIFFERENCE unless
the row-status mapping diverges.

## 4. Layer B — overall compliance: two formulas, documented, NOT equated
Both are captured verbatim; the design records their **inputs and derivation** so their business meaning
can be compared later. They are **not** declared equivalent because both contain "status"/"score".

### 4a. `ComplianceLevelService::evaluate` — a LADDER (0–5) with HARD STOPS
Source: app/src/ComplianceLevelService.php:30–92. Inputs: `$holder, $creds, $required, $coverage`
(coverage from `CatalogService::coverageTokens`), plus live reads of `exclusion_screenings`,
`EnrollmentService::payerRows/programs/ocs`.
- **Hard stop → level 0** if: no professional license on file (types: state_medical_license, license,
  state_license, professional_license); OR license expired (`expiry_date < today`); OR license
  `status='suspended'`; OR an `exclusion_screenings` row with result ∈ {match, excluded, hit, flag,
  flagged, possible_match}.
- **Level 1 (Licensed):** active license present, no hard stop.
- **Level 2 (Credentialed):** level 1 + every required token covered + **no** required credential
  expired (`expiry_date < today`).
- **Level 3 (Payer-credentialed):** level 2 + ≥1 payer with status ∈ `EnrollmentService::PAYER_ACTIVE`.
- **Level 4 (Billable):** level 3 + NPI on file + (Medicaid/Medicare program active OR a payer contract
  with `effective_date <= today`).
- **Level 5 (Fully compliant):** level 4 + OCS/SICRO `sicro_status='attested'` + no payer lapsed/stale +
  no program revalidation overdue + **nothing expiring within 30 days** (`expiry_date <= today+30d`).
- Output: `{level:int, label:string, reasons:string[], hard_stop:?string}`. **No numeric "score".**

### 4b. `flx_compliance_data` roll-up — a COVERAGE PERCENTAGE
Source: index.php ~4611–4635. Per provider it counts required tokens by coverage status and computes:
`pct = n > 0 ? round(pActive * 100 / n) : 100`, where `pActive` = tokens with status active and `n` =
total required tokens. Output per producer: `{total:n, active:pActive, pct, rows[...]}`; plus org-wide
`{active, expiring, expired, missing}` sums.
- This is a **ratio of active required tokens**, i.e. essentially Layer-A coverage aggregated — **not**
  a ladder and **not** hard-stop aware. It has no concept of payer/NPI/OCS gating.

### 4c. Why they must NOT be equated (the substantive finding)
A provider can be **100% in flx** yet **level 1** in ComplianceLevelService (all documents present but no
payer). Conversely a provider can be **level 0** (hard stop: license expired/exclusion) while flx still
shows a high `pct` (most other tokens active). The two answer different questions: *"what fraction of
documents are current?"* (flx) vs *"is this provider billable / where on the ladder?"*
(ComplianceLevelService). The design therefore records each on its own and marks any attempt to equate
level with pct as an **invalid comparison**, not a disagreement.

## 5. Verification (source_verifications vs credential_verifications)
Recorded as separate, unmerged, unchanged:
- `source_verifications` — written/read by `VerificationService` (recordManual/verifyNppes/verifySam;
  forHolder). Migration 111.
- `credential_verifications` — written/read by `VerificationRepository` (index.php, 4 sites). Migration 076.
- **Neither Layer-A nor Layer-B engine takes a verification argument** (verified signatures:
  `coverageDetail(array $creds)`; `evaluate($ctx,$holder,$creds,$required,$coverage)`). ⇒ Verification
  does **not** feed coverage or level directly. Whether any reader consults **both** stores for the same
  credential is **UNKNOWN** from static analysis; scenario E probes it at runtime. No inference is made.

## 6. Regulator repository (regulator_requirements)
- `flx_compliance_data` **requires** it: at its top it calls `$regulatorReqs->byRegulator()`
  (RegulatorRequirementRepository::byRegulator, app/src/Repository/RegulatorRequirementRepository.php:28),
  which runs `SELECT * FROM regulator_requirements` with **no try/catch**.
- `regulator_requirements` has **no CREATE TABLE** in schema.sql or any of the 120 migrations.
- ⇒ On a schema lacking that table, `flx_compliance_data` throws (PDOException) and **cannot execute** —
  classified **UNRESOLVED_RUNTIME_DEPENDENCY / RUNTIME_FAILURE**. Call sites: index.php 866/1468/1738/2367
  (+ roll-up 708). The table is not created and the repository is not repaired (per scope).

## 7. Classification (applied to each Layer-A comparison)
- **AGREEMENT** — same per-token satisfied/unsatisfied decision across engines.
- **REPRESENTATION_DIFFERENCE** — same decision, different raw status label or shape (e.g. `active` vs
  `satisfied`).
- **REAL_BUSINESS_DISAGREEMENT** — a token flips satisfied↔unsatisfied between engines.
- **RUNTIME_FAILURE** — an engine cannot execute (e.g. flx hitting the missing table).
- **UNKNOWN** — the relationship cannot be determined from source (e.g. cross-verification-store).

## 8. What this design can and cannot establish without a runtime
- **Establishable now (static):** the layer separation; that flx delegates coverage to CatalogService;
  the two overall-compliance formulas and why they differ; the regulator RUNTIME_FAILURE; that
  verification doesn't feed coverage/level by signature.
- **Requires PHP + MySQL to establish:** the actual per-token AGREEMENT/DISAGREEMENT counts for the
  fixture; whether flx throws in a freshly-migrated `flx_char`; whether any code reads both verification
  stores (scenario E). These remain PENDING in the baseline until `php tests/characterization/run.php`
  runs on a real host.
