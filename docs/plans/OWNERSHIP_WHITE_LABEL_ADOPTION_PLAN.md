# Ownership & White-Label Standard — adoption plan for FlxCoreHC

Standard reviewed: "Ownership & White-Label Standard" (Nov 1, 2026, @Nef). Baseline: FlxCoreHC v1.172.16 (this repository).
Status: **PLAN — nothing implemented. Waiting on the decisions in section 1.**

## 0. Summary
- The standard has 19 adoption steps. FlxCoreHC already has about a third of the raw material (audit log, roles/capabilities, branding, plans, platform console, consent-gated support access, migrator, key backup, a table list on the Database page, trash for credentials, per-organization time zone). It has none of the visible ownership pieces yet (no About page, no LICENSE.txt, no file headers, no Terms of Use, no installation ID).
- One **structural conflict** needs your decision before any code: the standard's hosted model is a *database per tenant*; FlxCoreHC is a *shared database with tenant_id isolation* running on cPanel hosting (section 1, D1).
- Proposed path: 6 releases for the "visible and legally important" steps (1–11, 17, 18), then the license/hub/dictionary/deleted-items work, with the hosted-only step 19 decided by D1.

## 1. Decisions and inputs I need from you
| # | Question | My recommendation |
|---|---|---|
| D1 | **Which deployment model does FlxCoreHC offer?** (a) Hosted by you, shared database, tenants separated by `tenant_id` (what it is today). (b) Hosted with one database per tenant (what the standard describes). (c) Also installed on a client's own server (one organization per install). | **(a) now**, written down in the adoption record as a *documented deviation* from "a database per tenant", with the entitlement check built with two sources (tenant record now, license file later) so (c) is possible without a rewrite. (b) only if a client contract requires physical separation: it means a front controller choosing the database per request, a tenant directory, a per-tenant migrator and scheduler, and creating databases programmatically — which the standard itself says shared cPanel hosting cannot do. |
| D2 | Placeholders: `<Product>` = **FlxCoreHC**; `<Product line>` = ? (e.g. "Healthcare Credentialing & License Tracking"); `<Owner>` = Indisse-BTS; `<Years>` = 2026; ownership file `app/src/Ownership.php`; license file `flxcorehc-license.php` (only if D1(c)); `<view permission>` = `license.view`. | Accept, and give me the product line in English and Spanish. |
| D3 | **Legal texts.** The standard says the ownership statement, eight license lines and Terms are "drafts for attorney review". I do not have the Terms of Use, the LICENSE.txt template or the Software License Agreement sections 4 and 14 it cites. | Send me the Terms text and LICENSE.txt template from the reference app (Product X), or tell me I should draft them for attorney review. Until then I ship the eight lines and the independent-developer statement exactly as written in the standard and label the Terms page "draft". |
| D4 | **Language of legal text.** The interface is bilingual. | Keep ownership and license text in English as the controlling text; show a Spanish courtesy translation marked "informational; the English text controls", pending attorney review. |
| D5 | **Naming rule vs. the product's subject matter.** The standard bans naming "standards bodies, regulators, frameworks". A credentialing product's data *is* regulators and payers (CMS, NPPES, DEA, ORCPS/JLDM, The Joint Commission, LEIE, payer names, official web links). | Propose to the standard: *"Public regulators, payers and registries that are the product's subject matter may be named in reference data; customer organizations, their people and domains never."* Until you agree, I leave reference data alone and sweep only for customer, person and hosting-provider names. |
| D6 | **Person named as owner.** `KICKOFF.md` names an individual ("Owner (…)"). The standard says no person is named as owner in any app. | I replace it with "the owner (Indisse-BTS)" and scrub personal names from docs and tests. |
| D7 | Is the **platform console** (Admin: tenants, plans, invoices, billing) to become the "License Hub"? | Yes: it is the hub for the hosted tenants. I would extend it rather than build another one. |
| D8 | Hosted-only items that need non-code work: subscription agreement, data processing addendum (HIPAA-adjacent: a BAA may also be needed), uptime/support/backup/retention terms. | Legal drafting is yours/attorney's; I implement the technical side (export, read-only on overdue, exit period). |

## 2. Where FlxCoreHC stands, step by step
| Step | Standard asks | Today | Gap |
|---|---|---|---|
| 1 | Ownership file + `about()` | none | build |
| 2 | `about` read + About & ownership page (short/full views) | none | build; permission `license.view` (new capability, default for tenant owner/manager) |
| 3 | Sign-in line "Powered by … · ©" + Terms link | "Powered by FlxCoreHC" only on the public verify page and PDF footer | add to sign-in and Help footer |
| 4 | Audited organization name, old → new | `OrgService::updateInfo` saves the name; no old → new audit | add audit row |
| 5 | Installation / workspace ID `XXXX-XXXX-XXXX-XXXX`, never editable | none | migration: workspace ID per tenant (hosted) + one installation ID |
| 6 | LICENSE.txt, THIRD-PARTY-NOTICES.txt generator, header script, bundle banner | none; fonts (IBM Plex, Archivo, SIL OFL) are shipped, `tools/fetch_fonts.php` exists | build; ~540 files get the header via script, with `--check` in `runtime_check.sh` |
| 7 | Help Center footer + "About & ownership" topic, guide cover/footer, `<Product>`+U+2060 protection | PDF package has "Powered by FlxCoreHC"; Help layout lacks locale (see Spanish review S1) | build |
| 8 | Sweep for banned names; made-up data | Found: a person's name in `KICKOFF.md` and a test; owner's own name/domain in migration comments and `docs/archive`; sample data uses made-up names (OK); regulator/payer names (D5) | scrub + sweep test with pattern built from pieces |
| 9 | Installation/tenant values in settings, not code | time zone ✔ (v1.172.16), branding ✔, cron lines in docs use placeholders, mail/SMTP per tenant ✔ | show computed cron lines on System page; confirm-on-change for time zone |
| 10 | Ownership test + UI smoke + negative control | n/a | build with each release |
| 11 | Terms of Use acceptance (org + each user, audited, versioned) | none | build; blocks everything until accepted at first sign-in |
| 12 | Licensing: signed fields, entitlement check, trial/grace/core-only | `PlanService` has plan features, **fail-open** (no plan = everything) | replace with one entitlement check; signed tenant license record (Ed25519 via libsodium, already available); trial 30 days then core only |
| 13 | Signed update bundles, two-step install, channels | updates are manual zip uploads + `Migrator`; key backup exists | needed only for self-hosted (D1c); useful subset for hosted: backup + maintenance mode before migrating |
| 14 | Clients & billing on the hub | platform console has tenants, plans, invoices, ledger, billing | audit what exists against the spec (charge numbers `BIL-YYYY-NNNN`, payments by hand, aging, overdue → read-only) and fill gaps |
| 15 | Data dictionary (Administration → Data) | Platform console → Database lists tables with purpose notes (read-only) | upgrade to the contract: filters, sort, group, Excel/CSV export, dictionary download, secrets hidden, editable ref lists with 409, audit events; test "no undescribed table on a fresh install" |
| 16 | Deleted items (central) | `deleted_at` columns on many tables; a trash for credentials | one engine + per-kind declarations; put back with children; scheduled purge; permission |
| 17 | Permission validator + role matrix | `Capabilities.php`, `RoleService`, `Authz::requireCap`; authz test suite (cross-tenant, need-to-know) | add static cross-check (every `requireCap` code exists in the Roles editor with exactly the actions checked) and a matrix test (one role per capability, every route tried) |
| 18 | Rebrand script | none | build |
| 19 | Hosted only: tenant directory, DB per tenant, … | tenant isolation by `tenant_id` + authz suite; consent-gated support access ✔; tenant creation in console ✔ | per D1 |

## 3. Proposed releases
Each release: code + Spanish strings + tests with a negative control (break it, see the test fail) + changelog + an entry in the adoption record + full `runtime_check.sh` and browser check, delivered as a zip.

| Release | Steps | Content | Size |
|---|---|---|---|
| **v1.172.17** (parallel, small) | — | Spanish fixes S1–S2 from the Spanish review (Help frame, audit/packet documents), extend the translation guard to views | S |
| **v1.173.0 Ownership foundation** | 1, 2, 3, 4, 7 (part), 8 | `Ownership.php`; `about` read and the About & ownership page (short view for all, full view for `license.view`); sign-in line and Help footer; org-name audit; name/people scrub; adoption record `docs/OWNERSHIP_ADOPTION_RECORD.md`; ownership test + UI smoke | M |
| **v1.173.1 Legal files and headers** | 5, 6, 7 | Installation/workspace IDs (migration 135); LICENSE.txt; THIRD-PARTY-NOTICES generator; header script with `--check` in the runtime check; PDF cover/footer; U+2060 protection; sweep test | M |
| **v1.173.2 Terms of Use** | 11 | Terms page, org acceptance permission, per-user first-sign-in gate, versioning, audit rows, "who has accepted" for user managers | M |
| **v1.173.3 Settings & cron** | 9 | Computed cron lines; time-zone change confirmation; remove any value that belongs to an installation | S |
| **v1.174.0 Entitlement and license record** | 12 | One entitlement check, signed tenant license record, trial/grace/core rules, Licenses tab in the platform console; PlanService fail-open removed | L |
| **v1.174.1 Billing audit** | 14 | Compare the console against the spec; close gaps; overdue → read-only | M–L |
| **v1.175.0 Data dictionary** | 15 | Engine, catalog, routes, screen, test | L |
| **v1.175.1 Deleted items** | 16 | Engine, kinds, migration of archived records | L |
| **v1.175.2 Permission validator + role matrix** | 17 | Static validator + route matrix in the runtime check | M |
| **v1.176.0 Rebrand script, adoption closing** | 18, 10 | Script + test that the old owner appears nowhere after a rebrand | S |
| Later / by D1 | 13, 19 | Signed bundles & channels (self-hosted); tenant export/import, exit period, DB-per-tenant (if chosen) | L each |

Order logic: 1–4 and 8 are visible and low-risk; 11 (Terms) must come before onboarding the first clients; 12 changes who can use what, so it comes after the Terms; 15–17 are about quality and can be moved earlier if you prefer.

## 4. Risks and things to watch
- **Headers on ~540 files** touch everything: do it in one mechanical commit, with `--check` in CI and the full suite after; confirm nothing hashes source files (nothing found so far) and that SQL migrations keep working with a leading comment.
- **Terms gate** blocks all pages for users until accepted: test sign-out, portal users, 2FA flow, API-like endpoints (ICS feed, verify links) so they are not caught by the gate.
- **Entitlement without fail-open** can lock an organization out if a record is wrong: ship with a 30-day trial default for existing tenants and a console "grant" action, test the downgrade path ("fewer modules → menu items go, data kept").
- **D5**: if regulators/payers must go, large parts of the catalog (the product's value) would be affected; resolve before the sweep.
- **Shared DB hosted model** (D1a) is a deviation from the standard's tenant-isolation wording; mitigated by the existing authz suite (cross-tenant on every read/write/export). The standard's check "signed in to tenant A, every read/export/search with tenant B's ids is refused" is already how `tests/authz` works — I would extend it to cover the new About, license and Terms endpoints.
- **Legal text** is not mine to author; I copy it exactly and mark drafts.

## 5. What I will not do without your OK
- Change license wording, ownership statements or Terms text.
- Remove or rename regulator/payer reference data.
- Introduce a database-per-tenant architecture.
- Publish anything outside this repository.

## 6. Next step
Answer D1–D8 (even "accept the recommendation"). With D1–D6 settled I start **v1.173.0**.
