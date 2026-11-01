# FlxCoreHC v1.170.7 — Update for ChatGPT review (healthcare-only)

**From:** Claude (code owner) · **Base:** v1.170.6 · **Owner decision:** the product serves healthcare only.
**Status:** STATIC PASS (php -l all files, isolation check) · **RUNTIME PASS for `tests/healthcare/test_healthcare_only.php`**
(executed in Claude.ai; needs no database) · RUNTIME VALIDATION PENDING for migration 124 and the DB suites.
**Audit this release acts on:** `docs/architecture/INSURANCE_RESIDUE_AUDIT.md`.

## 1. Approach
1. **Make insurance unreachable at the root, not per screen.** `TenantContext::$vertical` is always
   `'healthcare'` (constructor normalizes it; no longer a promoted readonly param), `Vocab` always returns the
   healthcare column, `edition_shapes()` offers only healthcare + platform, `ORG_SHAPES['custom']` removed.
   Every `vertical !== 'healthcare'` branch is now dead even on an unmigrated database.
2. **Fix what healthcare users actually see** (panels, labels, public pages, PDFs).
3. **Fix defaults that silently changed behaviour** (jurisdiction, applies_to, fallbacks).
4. **Migration 124** carries the data side for upgraded installs; it is a no-op on fresh installs except the
   new entity columns.

## 2. Changes
| Area | Before | After |
|---|---|---|
| Compliance footer | "CMS CY2027 Final Rule (CMS-4208-F3) · PY2027 certification season · FL commercial lines" | Healthcare ruleset (NPPES/PECOS, DEA, CLIA, OIG/SAM, PR JLDM, FL BOM) |
| Provider page | NIPR insurance-licence panel + "I'd want this" (route writing `nipr_interest`) | Plain note: NPI verification ≠ licence verification; verify with the issuing board. Route removed |
| CE tab (provider + portal) | FL insurance CE: 24 h, Law & Ethics, F.S. 626.2815, NFIP flood, MyFloridaCFO | CME / CE: categories (AMA PRA Cat 1/2, nursing CE, ethics, controlled substances, patient safety), trailing-24-month totals, no asserted statutory target |
| Entity compliance | FL agency law (agent in charge, 91-day lapse), Agency licence #, Entity NPN | Healthcare entity profile: Group NPI (10-digit check), taxonomy, PTAN, Medicaid ID, CLIA (`NND#######` check), facility licence, accreditation; federal citations |
| Public verify page | "Producer", NPN, "not an appointment" | Provider, NPI (✓ if verified), specialty, "not a credentialing decision" |
| Audit package | "CMS Audit-Readiness", Agent/Agency, NPN | "Credentialing Audit-Readiness", Provider/Organization, NPI |
| Security page + PDF | producer/beneficiary data, carriers, FMOs, NPN | provider data, payers/hospitals/delegated auditors; encrypted fields listed correctly |
| Forms / lists | NPN field + "NPI / NPN" column, "Producer type", "MA agent" placeholder | NPI only, "Record type", healthcare placeholders |
| Calendar | "Client call", "Client name (encrypted)" (invites patient PHI) | Removed (existing events → appointment) |
| Wording | ~60 producer/agent/agency/carrier strings | provider/organization/payer; role "Provider (self-service)"; plans "Solo practice / Group practice / Organization" |
| Sign-up | Organizations defaulted to FL → PR rules hidden for PR tenants; "Other" path re-enabled insurance UI | "Where do your providers practice?" (PR preselected); "Other" removed |
| Custom document types | default `applies_to='agent'` → never required for any healthcare provider | defaults to all healthcare provider types; invalid values dropped |
| New case form | raw `holder_id`, `provider_groups.id`, payer id text boxes | Provider / group / payer pickers (need-to-know respected via `HolderRepository::all`) |
| Training Center | literal `—` shown | real characters |

## 3. Migration 124_healthcare_only.sql (25 statements, no bare SELECT)
Adds 7 entity columns · widens `credential_catalog.applies_to` VARCHAR(80)→255 (the healthcare default list is 114 chars; STRICT mode would raise 1406) · copies old agency licence into facility licence · tenants/holders `insurance|custom` →
healthcare, legacy org shapes mapped · portal role + plan display names · custom types applies_to fix ·
`training_library.vertical` default → healthcare · prunes the 9 insurance seed templates (detaches holders
first) · `client_call` events → appointment. Idempotent; registered in `install.php` and `database/manifest.php`.

## 3b. Independent pre-release review (separate reviewer, diff only) — found and fixed before packaging
1. **Blocking:** `applies_to` too long for VARCHAR(80) → would fail migration 124 on upgraded installs and every "add custom type" → column widened in 124; test H4 now asserts it.
2. Provider profile table had 4 headers / 5 cells after removing NPN → stray cell removed.
3. Provider edit would overwrite a stored NPN with NULL → `npn` removed from the update array.

## 4. Deliberately NOT changed (please challenge if you disagree)
- **Internal names** (`producer` role slug, `producer_requirements` table, `/compliance/producers` URL,
  `$producers` variables, `business_lines='medicare'` column value). They are not user-visible; renaming
  them is a risky schema/route change for no user benefit. Healthcare catalog rows use `line='all'`, so the
  leftover line value does not filter anything.
- **Legacy insurance tables** (policies, commissions, bookings…): left in place on upgraded installs;
  PurgeService still blocks on them if rows exist. A later drop migration can remove them.
- **Dead insurance branches** in a few templates/settings views remain as code but cannot render (context
  is always healthcare). The most visible ones were deleted.
- **Column default** `tenants.operating_states='FL'` kept; sign-up now sets the chosen jurisdiction.
  Platform-admin-created tenants still start at FL and can be changed in Settings. *Question:* default PR?

## 5. Questions for review
1. CME/CE: is a board-specific requirement engine (hours + mandatory topics per board and licence type,
   e.g. PR JLDM, FL BOM opioid/HIV/medical-errors topics) worth building next, or is the log sufficient?
2. Entity profile: any field missing for payer enrollment in PR (e.g. Medicaid PR provider number format,
   ASES ID)?
3. Should migration 125 drop the retired insurance tables outright?
