# Roadmap — NPDB, committee decisions and NCQA timeliness (D-1, D-2)

**Status: PLAN ONLY — nothing here is implemented in v1.171.1.** It needs an owner decision first (see "Decision gate").

## Decision gate (owner)
What is FlxCoreHC? Two legitimate positions:
1. **Credentialing tracker / payer-enrollment tool** (today). The customer's credentialing committee or CVO makes the decisions; FlxCoreHC tracks documents, expirations, PSV evidence, exclusions, payer status. Sold as "audit-ready files", not as "NCQA-certified".
2. **NCQA-grade credentialing system** (phases 1–4 below). Required to sell to health plans, hospitals or a CVO that must pass an NCQA audit.
Nothing below should be built, or marketed, until one is chosen. Under (1) the product copy must say it does **not** query the NPDB or record committee decisions.

## Phase 1 — NPDB query tracking (prerequisite for any credentialing decision)
- Table `npdb_queries`: holder_id, query_type (initial / continuous-query enrollment), queried_at, queried_by, result (no reports / reports on file), report_ref (encrypted file via `EncryptedFile`), next_due.
- NPDB itself is queried by the credentialing entity through NPDB's own portal; FlxCoreHC records the outcome and the evidence, and does **not** scrape or call NPDB.
- Reminder rule: initial query at credentialing; re-query at recredentialing unless enrolled in Continuous Query.
- Access: dedicated capability `npdb.view`, every view written to the access log (NPDB data is restricted by law).

## Phase 2 — Committee decision (clean-file vs committee)
- Case statuses today end at `submitted → completed → closed` (payer flow). Add a **decision** step: `clean_file_approved | committee_review | approved | approved_with_conditions | denied | deferred`.
- Table `credentialing_decisions`: case_id, decision, decided_by (committee/medical director), decided_at, rationale, conditions, next_recredential_due.
- Clean-file criteria are configurable per tenant (no adverse items: all PSV in date, no NPDB reports, no sanctions, no gaps > 6 months).
- Separation of duties: the user who verified cannot be the sole approver (configurable).

## Phase 3 — Timeliness rules
- **Verification age:** every PSV element must be ≤ 180 days old at decision time (NCQA standard for most elements); the case cannot reach `approved` otherwise (hard stop, like the existing exclusion hard stop).
- **Recredentialing:** `recredentialing_due = decided_at + 36 months`; replace today's free-text `recredentialing_date`; reminders at 180/90/30 days.
- **Work-history gaps:** compute gaps > 6 months (CAQH rule), > 1 year needs written explanation.

## Phase 4 — One PSV model
Today PSV lives in three places: `credential_verifications`, `source_verifications` and verification columns on documents. Unify into one `psv_records` ledger (element, source, method, outcome, verified_by, verified_at, evidence, expires_at) with a catalog of **acceptable sources per element** (e.g. state license → the board's primary source; board certification → ABMS; malpractice history → NPDB/carrier). Migrate and drop the two legacy stores in a dedicated release.

## Data protection prerequisites (D-2, D-6)
- **Full SSN** (needed by CMS-855I, CAQH, PEP, NPDB): store encrypted with `Crypto` (AAD bound to tenant/table/column), gated by `pii.view` + a new `ssn.full.view`, every reveal access-logged, never exported by default.
- Encrypt **residential addresses**; add retention schedule and legal hold. (Uploaded documents are already encrypted at rest as of v1.171.0.)

## Sizing (rough, for planning only)
Phase 1: 1 release · Phase 2: 1–2 · Phase 3: 1 · Phase 4: 1–2 · SSN/address encryption: 1. Each with behavioural tests and a migration scenario; no phase ships without the owner's go-ahead.
