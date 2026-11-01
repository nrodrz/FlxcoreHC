# FlxCoreHC v1.170.8 — Update for ChatGPT review (security & correctness audit)

**From:** Claude (code owner) · **Base:** v1.170.7 · **Method:** three independent static reviews (security /
runtime errors / insurance residue) → every claim re-verified against source → fixes → an independent diff-only
review of the fixes (found 2 blocking defects in my own changes, both fixed) → tests.
**Status:** STATIC PASS (php -l all files, isolation) · **RUNTIME PASS** for `tests/healthcare/test_healthcare_only.php`
(executed here, 28 checks) · RUNTIME VALIDATION PENDING: migrations 124/125, purge/agency suites, browser checks.

## 1. Security findings → fixes
| # | Sev | Finding (verified) | Fix |
|---|---|---|---|
| S1 | P1 | `TeamService::create/invite/updateRole` never checked the *granted* role → a Manager could make anyone (incl. self) `tenant_owner`; custom role named "Master Admin" → slug `master_admin` → `Capabilities::all()` | `actorMayGrant()`: owner-level slugs only grantable by a Master Admin; `RoleService::isReservedOwnerSlug`; `uniqueSlug` never mints reserved slugs; migration 125 renames pre-existing ones (and moves their users) |
| S2 | P1 | Need-to-know (`provider_access='assigned'`) enforced only on `HolderRepository` → `/credentials` list, `/credentials/{id}/edit`, `/documents/{id}`, report export, tasks, calendar, iCal feed exposed every provider | `TenantRepository::ntkFilter/ntkRow` applied in Credential/Document/RenewalTask/Calendar repos (find, all, withHolders, report, expiringWithin, between, filtered, upcoming, icsFeed); iCal runs as the token's user, requires active user + active/pending tenant |
| S3 | P2 | Sub-record ids (education, work history, address, contact) verified for tenant only → could re-parent/delete another provider's rows; org addresses page could edit provider addresses | Route loads the row and requires `holder_id === $id` (or `owner_type/owner_id` for addresses) → 404 otherwise |
| S4 | P2 | Holder create/edit/onboarding, credential create/edit, apply-template, task/calendar create had no capability check (custom role without `records.write` could write) | `Authz::requireCap($ctx,'records.write')` (producer self-service branch exempt) |
| S5 | P2 | Portal guard checked only the `producer` slug; custom `portal_only` roles reached staff routes; users could be created on a portal role with no holder | `$__isPortalRole` = producer OR `RoleService::isPortalOnly`; `/me` 403s (no loop) when unlinked; create/invite require a holder for any portal-only role |
| S6 | P2 | `new TeamService($ctx)` throws for non-managers → `GET /holders/{id}` 500 for Staff/Coordinator/Read-only; holder create threw *after* insert | `TeamService::directory()` / `portalUserFor()` (read-only, no team.manage); provisioning guarded by `canManage` |
| S7 | P3 | CSV formula injection on history, readiness, audit, data export, org report | `csv_safe_row()` on every data row (numbers preserved) |
| S8 | P3 | 2FA disable unlimited code guesses | RateLimiter 8/15 min, audit `2fa_disabled` |
| S9 | P3 | `redirect()` allowed `/\evil.com` | backslash rejected |

## 2. Runtime/logic defects → fixes
| # | Finding (verified) | Fix |
|---|---|---|
| R1 | `DashboardService`: `document_requests.credential_type/created_at/updated_at`, table `audit_log` don't exist → review queue + throughput always empty (exceptions swallowed) | `requested_kind`, `submitted_at/requested_at`, `accepted_at`, `audit_logs` |
| R2 | `TenantReportService`: `domains`, `invoices.issued_at`, `impersonation_requests.approved_at` → sections empty | `tenant_domains.host/is_primary`, `issue_date/created_at`, `decided_at` |
| R3 | `NotificationService` screening filter lacked `potential_match` (the value the app writes) → no alert | added |
| R4 | `ReminderService` scanned soft-deleted credentials (no `c.deleted_at IS NULL`) | added |
| R5 | `cron/sweep_expiring.php` pre-queued `credential_reminders` rows (sent_at NULL) that `claim()` (INSERT IGNORE) read as "already sent" → 30-day email never sent | pre-queue removed; `claim()` claims an unsent row via UPDATE |
| R6 | CLI `BASE_PATH` = filesystem dir → notification links from cron were `/home/…/cron/holders/x` | CLI base path derived from `app_url` |
| R7 | `CredentialingCaseService::populateRequirements` useless `find('')` per token | removed |

## 3. Insurance residue (second sweep)
Fixed: "Lines of business saved." flash (+ES) and `lines_of_business_set` audit action → `operating_jurisdictions_set`;
`producer_user_linked/invited` → `provider_user_*` (125 renames history); "appointment" in credentials empty-state;
custom document-type category labels (`CatalogService::categoryLabel`); **"Reporting year"** was the Medicare
plan-year June rollover → calendar year; team.csv role slugs → display names; installer placeholder; dead NPN
branches and export field deleted; `database/seed_demo.php` (insurance-agency seeder, would fail on `carriers`) deleted;
`docs/CHANGELOG.md` header updated. **Migration 125** removes the two `compliance_items` insurance rows from 004
(the only insurance seed rows surviving a fresh install) — FK-safe (deletes `tenant_compliance` children first).

Left as internal identifiers (not user-visible): `producer` role slug, `producer_requirements`, `/compliance/producers`,
`business_lines` column, `agency` plan code, legacy category codes `insurance`/`appointment` (relabelled).

## 4. Independent diff review of THIS release caught (and I fixed before packaging)
1. **Blocking:** custom portal-only user without a holder → `/` ↔ `/me` redirect loop (my new guard). Fixed in `/me` + TeamService.
2. **Blocking:** migration 125 `DELETE FROM compliance_items` violated `fk_tcomp_item`. Child rows deleted first.
3. Task NTK filter ignored the credential's holder for legacy rows. Uses `COALESCE(t.holder_id, c.holder_id)`.
4. CLI `BASE_PATH=''` lost subfolder prefix. Now from `app_url`.
5. Pre-existing custom roles with reserved slugs kept all-caps. Migration 125 renames them and their users.

## 5. Questions for review
1. S2 filters after the query (`array_filter`), so a restricted user with a `LIMIT 500` list could see fewer than
   500 rows. Acceptable, or should NTK move into the SQL (`holder_id IN (...)`)? I chose post-filter to keep
   raw-SQL methods unchanged and provably scoped; happy to push it into SQL if you disagree.
2. Should `records.write` also gate document *upload* for staff (currently `documents.manage`)? Separate caps seem right.
3. Anything else you'd add to `RESERVED_OWNER_SLUGS`?
