# Agency Credentialing — Implementation Notes (v1.167.0)

An **orchestration layer** over existing FlxCoreHC entities. It never duplicates provider, credential,
requirement, document, verification, application, payer, compliance, or audit domains.

## New tables (migration 121)
- `credentialing_cases` — operational unit; `holder_id`→provider master, nullable `client_group_id`
  (provider_groups), `payer_id` (payers), `location_id`; status/type as VARCHAR; soft-delete.
- `credentialing_case_requirements` — case checklist; `requirement_code` = `credential_catalog.code`;
  unique `(tenant_id, case_id, requirement_code)`; `is_hard_stop` per row.
- `case_documents` — non-credential evidence (CV, W-9, forms). `credential_documents` unchanged.
- `case_requirement_evidence` — typed nullable FKs (credential / credential_document / case_document /
  verification / attestation). Exactly one target per row (repo-enforced) + cross-tenant validation.

## Extended tables (nullable case_id, additive)
`applications`, `source_verifications`, `document_requests`, `compliance_events`. Backward compatible.

## Code
- Repositories: `CredentialingCaseRepository`, `CredentialingCaseRequirementRepository`,
  `CaseDocumentRepository`, `CaseRequirementEvidenceRepository` (all extend `TenantRepository`).
- `CredentialingCaseService` — the ONLY place case status transitions happen; enforces the state machine
  and hard stops (unresolved required hard-stop case requirements + provider-level signals from
  `ComplianceLevelService`). UI never enforces this alone.
- `AgencyWorkQueueService` — derived buckets (unassigned, overdue reqs, ready, submitted, approaching).
- `ComplianceEventService` — extended to persist `case_id` and expose `forCase()` for case history.
- Routes: `/cases` (list + queue), `/cases/{id}` (detail: requirements, lifecycle, evidence, history).
  Capability-gated with `records.write`; CSRF on all POSTs.

## Tenant isolation
Cases and all case-owned rows are tenant-local (own `tenant_id`, scoped by `TenantRepository`). A shared
provider (`provider_consents`/`provider_links`) does NOT expose the owning tenant's cases. `provider_links`
(no `tenant_id`) is never joined as a tenant-scoped table.

## Ongoing compliance vs case work
`holder_requirements` (ongoing, provider-level) is untouched and semantically distinct from
`credentialing_case_requirements` (case-specific). The Case references existing evidence; it copies nothing.

## Migration order / rollback
schema.sql + migrations 001–120 first; then 121 (new tables before their nullable case_id references —
all case_id columns are nullable so ordering is forgiving and no backfill is required; existing rows get
`case_id = NULL`). Rollback = drop the 4 new tables + the 4 case_id columns/indexes.

## Runtime validation still required (no PHP/MySQL in the build env)
- `php -l` on all new/changed files.
- Apply migration 121 to a scratch DB; confirm 89 tables + the 4 case_id columns.
- Run `tests/agency/test_agency_credentialing.php` (tenant isolation, cardinality, uniqueness, hard stops,
  evidence isolation/single-target, backward compat, event compat).
- `harness.sh full` + `tools/check_isolation.sh` (isolation already passes statically).

## v1.167.1 remediation
- Evidence links validate the full case→provider ownership chain (not just tenant).
- Case creation validates every supplied FK in-tenant before inserting.
- Hard-stop gate fails CLOSED on compliance-eval failure (compliance_check_error + logged).
- Requirement status mutation is a service method (ownership + validation + event).
- Requirement codes validated against credential_catalog; invalid codes rejected.
- Evidence view/link/unlink and case-document upload/download/delete are wired into /cases,
  CSRF + capability gated, reusing existing storage/security helpers.
- Work-queue query failures are logged. No schema change; migration 121 unchanged.

## v1.167.2 remediation
- assign() validates assignee tenant ownership (null = unassign).
- Case-document delete and evidence unlink are service methods that verify the target belongs to the
  URL case (not just the tenant) — blocks same-tenant cross-case mutation.
- Test J rewritten to real assertions (credential_id still NOT NULL; valid insert works; NULL rejected).
- Tests: 32 assertions. No schema change.

## v1.168.0 — workflow layer
- populateRequirements(): case requirements seeded from the profession template (reuses templates +
  catalog); auto on create + manual button; idempotent.
- reusableEvidenceFor(): eligible existing evidence per code with validity; human-selected, never auto-satisfies.
- Case workspace: progress summary, hard-stop indicator, applications section.
- Work queue: expanded buckets (hard_stops, needs_attention, active_total, …) on /cases.
- Provider page shows the provider's cases + "New case".
- Case types: initial_credentialing / recredentialing / payer_enrollment / payer_revalidation / provider_update / other.
- No schema change. Tests: 37 assertions.
