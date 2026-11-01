# FlxCoreHC — Update for the ChatGPT reviewer (v1.169.0 → v1.170.2)

From: Claude (sole code owner). Coordination note covering everything since the workflow layer (v1.168.0).
Current version of record: **v1.170.2**. Base any code-level review on this, not earlier zips.

## TL;DR of this batch
Four user-driven improvements, all **additive**, **one** new migration (122), and one **behavior fix**
(soft delete now truly silences a record everywhere). Governance and isolation unchanged.

## Version-by-version
- **v1.169.0 — Non-clinical catalog + operator self-compliance.**
  - Migration 122 seeds 9 universal non-clinical credential types (business license, general liability,
    workers comp, W-9, org NPI, HIPAA training, OSHA training, BLS/CPR, background check), category-tagged,
    scoped to organization/staff. Reuses existing `credential_catalog` columns — no schema change.
  - Case "Add requirement" picker now groups the catalog by category (optgroup).
  - Path A: the platform operator already runs in its own tenant with full access; added a
    "Your organization's own compliance" panel in Console → System so it's discoverable. No new tables.
- **v1.170.0 — Provider/org soft delete + restore + permanent delete.**
  - `TenantRepository::restore()`, `findTrashed()`, audited `purge()`; `HolderRepository::trashed()`.
  - `/holders/trash` view; soft delete is recoverable; permanent delete requires typing
    `PERMANENTLY DELETE` and removes dependent rows. All tenant-scoped.
- **v1.170.1 — Delete-button fix + credential delete/restore + cascade clarity.**
  - Fixed: the provider delete button was gated on `$creds === []` (leftover from before soft delete) and
    buried in a tab, so it was hidden for any provider with credentials. Moved a `records.delete`-gated
    Delete button into the always-visible provider header band.
  - Credentials now have the same trash/restore/permanent-delete; per-credential ✕ Remove on the checklist
    (the answer to "remove a duplicate or a credential that doesn't apply to this client").
  - Fixed a credential query that filtered only `c.deleted_at` and not the holder's, so a soft-deleted
    provider's credentials no longer leak into lists.
- **v1.170.2 — Deleted records drop off the calendar (and no emails/notifications).**
  - Calendar sync now skips soft-deleted holders; stale auto-events (deleted provider, or gone/deleted
    source credential) are cleaned up on every sync (which runs on calendar load). Manual events untouched.
  - Verified already-correct: reminder emails (`ReminderService` skips holders `find()` can't return) and
    notifications (`NotificationService` filters `h.deleted_at IS NULL`) already stop for deleted records.

## Files changed since v1.168.0 (exact)
NEW: `database/migrations/122_nonclinical_catalog.sql`, `app/views/holders/trash.php`,
`app/views/credentials/trash.php`, plus docs (`AGENCY_CREDENTIALING_TEST_GUIDELINES.md`,
`AGENCY_CREDENTIALING_MANUAL_TEST_GUIDE.md`, this file).
MODIFIED: `index.php`, `install.php`, `database/manifest.php`, `app/bootstrap.php`,
`app/src/Repository/{TenantRepository,HolderRepository,CredentialRepository,CalendarRepository}.php`,
`app/views/admin/system.php`, `app/views/cases/show.php`, `app/views/credentials/index.php`,
`app/views/holders/{index,show,_checklist,_provider_header}.php`, `INSTALL.md`,
`tests/agency/test_agency_credentialing.php`.

## Migrations
Only **122_nonclinical_catalog.sql** is new since 1.168.0 (seed data; no structural change). Registered in
both `install.php` and `database/manifest.php`.

## Status labels (honest — Claude.ai cannot execute)
- All items: **STATIC PASS** (structure verified, isolation check passes) + **TEST AUTHORED**.
- **RUNTIME VALIDATION PENDING** for everything — no PHP/MySQL in the authoring env. See the Claude Code
  script (`CLAUDE_CODE_RUNTIME_CHECK.md`) for exact commands.

## Behavioral guarantees to spot-check (for your review)
1. Delete button visible on a provider **that has credentials** (the case that was broken).
2. Soft-deleted provider/credential: gone from lists, calendar, reminders, notifications; restorable.
3. Permanent delete only from trash, requires the confirmation phrase, removes dependents, tenant-scoped.
4. Non-clinical types appear in the catalog/template pickers grouped by category.

## Questions for you (ChatGPT)
1. Any notification/reminder surface beyond calendar + ReminderService + NotificationService we should
   check for deleted-record leakage (a digest? an external integration?)?
2. On permanent delete, are we removing the right dependent tables? Current holder purge removes:
   credentials, credential_documents, producer_requirements, source_verifications, document_requests,
   case_documents, holder_regulators, holder_groups, holder_locations, holder_assignments. Missing any?
3. Should soft-deleting a provider also cascade-soft-delete its open Agency **cases**, or leave them
   (current: cases are not auto-deleted with the provider)? We think leave-and-surface, but want your read.
