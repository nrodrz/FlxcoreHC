# FlxCoreHC v1.170.9 — Direct SQL inventory (provider-related data)

Method: `grep` of every `FROM/JOIN/UPDATE/INSERT INTO` on provider-bearing tables across `index.php`, `app/src`, `cron/`,
then a read of each hit. Authorization model: **Tenant scope + Capability + Provider scope + Record ownership**.
"Provider scope" = need-to-know for users with `provider_access='assigned'` (`ProviderScope`, applied in SQL).
Status: STATICALLY REVIEWED (no database was available); behavioural proof is in `tests/authz/*` (REQUIRES EXTERNAL RUNTIME VALIDATION).

| File | Method / area | Tables | Tenant scoped | Provider scoped | Capability | Action / note |
|---|---|---|---|---|---|---|
| Repository/TenantRepository | find, all, count, update, delete | any (per repo) | yes | **yes — `scopeSql()` in SQL** for repos declaring `providerColumn()`/`credentialColumn()` | route-level | FIXED (was post-filter) |
| Repository/HolderRepository | find, all, withCredentialCounts, trashed | credential_holders (+credentials) | yes | yes (`id`) | route | FIXED: withCredentialCounts + trashed moved into SQL |
| Repository/CredentialRepository | find, all, withHolders, report, expiringWithin, statusCounts, trashed | credentials (+holders) | yes | yes (`holder_id`) | route | FIXED; statusCounts also stopped counting soft-deleted rows |
| Repository/DocumentRepository | find, all | credential_documents | yes | yes (via credential) | documents.view/manage | FIXED |
| Repository/RenewalTaskRepository | find, all, count, update, delete, openWithCredentials | renewal_tasks (+credentials, holders) | yes | yes (holder OR credential's holder; NULL/NULL = tenant-wide) | records.write | FIXED (legacy NULL-holder rows resolve through the credential) |
| Repository/CalendarRepository | find, all, between, upcoming, filtered, dayCounts, overdueCount, icsFeed, setStatus | calendar_events | yes | yes (`holder_id`, NULL = org-wide) | records.write | FIXED (setStatus added) |
| Repository/{Application,Attestation,CeEntry,CaseDocument,CredentialingCase,HolderRegulator,ProducerRequirement,ProviderContact,ProviderEducation,ProviderWorkHistory,Screening,Verification} | base CRUD + raw `forHolder`/`map`/KPIs | own tables | yes | yes (`holder_id`) | route | FIXED: `providerColumn()` declared; raw queries (`allWithNames`, `openCount`, `flaggedCount`, `map`, `allAssignees`, …) now scoped |
| DashboardService | build | credentials, credential_holders, document_requests, source_verifications, credential_documents, audit_logs | yes | **yes** (every aggregate, queue, forecast, throughput; audit_logs via entity ∈ visible credentials) | route | FIXED (HIGH) — query failures counted/logged/shown, not zero |
| AgencyWorkQueueService | build | credentialing_cases, credentialing_case_requirements, source_verifications, document_requests | yes | yes (cases by holder; children via case) | route | FIXED; failures counted (`query_failures`) and shown |
| DocumentRequestService | forHolder, open queue, counts | document_requests | yes | yes | documents.request | FIXED |
| VerificationService | forHolder, reverify queue | source_verifications | yes | yes | documents.verify | FIXED |
| ComplianceEventService | forHolder, feed, export | compliance_events | yes | yes | audit.view / route | FIXED |
| DataExportService | all exports | via repositories + membership tables | yes | yes (repos + explicit scope on membership export) | data.export | FIXED / verified; CSV formula guard kept |
| GroupService / LocationService | holderIdsIn / holderIdsAt | holder_groups, holder_locations | yes | **yes (v1.170.9)** — ids/counts of unassigned providers no longer disclosed | route | FIXED |
| ExternalShareService | all (oversight list) | external_shares | yes | **yes (v1.170.9)** | share.manage | FIXED; per-holder methods gated by scoped holder find in route |
| NotificationService | sweep, recipientsFor | credentials, credential_documents, calendar_events, notifications | yes | recipient-level: a notice is created only for users who may see that provider; **custom portal-only roles now excluded** | — | FIXED (portal roles) |
| ReminderService / cron sweeps | scan, claim | credentials, applications, holder_attestations, credential_reminders | yes | n/a — system context; recipients are provider contacts + owners (unrestricted) | — | failures now logged (were silent 0) |
| PurgeService | purgeHolder/purgeCredential | many | yes | route checks `AssignmentService::canSee`; records.delete | records.delete | reviewed, unchanged |
| ShareLinkService / ConsentService | per-holder | share_links, provider_consents | yes | route: `/holders/{id}` resolves via scoped HolderRepository first | share.manage | reviewed |
| TeamService | provisioning checks | credential_holders | yes | n/a (admin action, team.manage) | team.manage | reviewed |
| OnboardingService | checklist booleans | providers/credentials/calendar existence | yes | not applicable (boolean "has any", no identifiers) | — | reviewed; documented |
| CompliancePostureService | posture PDF | credential_holders counts | yes | not applicable — organization security posture, org.manage | org.manage | documented |
| TenantReportService / SuperadminService | platform reports | tenant-level | yes | not applicable — platform administrator, not tenant users | platform admin | failures counted + flagged in PDF |
| CrossTenantService | linksForTenant, per-holder link/share methods | provider_links, cross-org shares | yes | per-holder methods route-gated; **tenant-wide link list is NOT provider-scoped** | crossorg.manage | RESIDUAL RISK R2 |
| index.php | document download (external share) | credential_documents | yes | token-authorised system actor; document must be inside the share's own profile | share token | reviewed |
| index.php | iCal feed | calendar_events | yes | yes — runs as the token's active user, request-time | token | reviewed |
| index.php | audit / activity exports | audit_logs | yes | not provider-scoped (audit rows carry ids, not PHI) | audit.view | RESIDUAL RISK R1 |
