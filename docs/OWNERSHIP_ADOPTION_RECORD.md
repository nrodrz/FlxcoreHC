# Ownership & White-Label Standard — adoption record for FlxCoreHC

Standard: "Ownership & White-Label Standard" (Nov 1, 2026). This file holds the app-specific facts; the rules live in the standard.

## Values for the placeholders
| Placeholder | Value |
| --- | --- |
| `<Product>` | FlxCoreHC |
| `<Product line>` | Healthcare Compliance Intelligence Platform (the existing product tagline) |
| `<Owner>` | Indisse-BTS |
| `<Years>` | 2026 |
| `<ownership file>` | `app/src/Ownership.php` |
| `<license file>` | not applicable while hosted-only (see Deployment models) |
| `<view permission>` | `license.view` (default for Master Admin, Manager, Admin) |

## Deployment models offered
**Hosted service, shared database** (tenants separated by `tenant_id`, every query through `TenantRepository`, covered by `tests/authz`). *Documented deviation:* the standard's hosted model is a database per tenant; the owner accepted the shared-database model for now (decision D1(a), recorded 11/01/2026). The entitlement check will be built with two sources so a self-hosted installation can be added later. Self-hosted installation: not offered yet.

## Steps
| # | Step | Status | Where | Build | Checked | Lessons |
|---|---|---|---|---|---|---|
| 1 | Ownership file + `about()` | Done | `app/src/Ownership.php` (`Ownership::about`) | 1.173.0 | `tests/ownership/test_v1173_0.php` O1–O2, negative control (owner changed → 6 failures) | |
| 2 | `about` read + About & ownership page, short and full view | Done | `GET /about` (HTML) and `GET /about?format=json`; `app/views/about/index.php`; button in Help, link in Settings | 1.173.0 | HTTP OW4–OW9: short view has no full-view field for read-only/staff/coordinator | Spanish courtesy translation is shown under the English text, marked informational |
| 3 | Sign-in line + Terms link | Done (Terms text is a draft page) | `app/views/auth/login.php`, `GET /terms` (public) | 1.173.0 | HTTP OW1–OW3; negative control (line removed → fails) | Terms of Use text still to come (step 11) |
| 4 | Audited organization name | Done | `index.php` Settings POST → `org_name_changed`, `org_display_name_changed` (old → new) | 1.173.0 | HTTP OW11–OW12 | |
| 5 | Installation ID / workspace ID | Workspace ID done; installation ID not applicable (hosted only) | `tenants.workspace_id` (migration 135), `OrgService::workspaceId()` | 1.173.0 | HTTP OW4–OW6: format, stable, unique; test O3: no edit path | Made on first need with `UPDATE … WHERE workspace_id IS NULL`, so it can never change |
| 6 | LICENSE.txt, notices generator, header script, bundle banner | Done (bundle banner not applicable: no bundles) | `LICENSE.txt`, `THIRD-PARTY-NOTICES.txt`, `tools/third_party.json`, `tools/third_party_notices.php`, `tools/ownership_headers.php` | 1.173.1 | `tests/ownership/test_v1173_1.php` P1–P3 + negative controls; HTTP OW13–OW14 | No package lock exists, so `tools/third_party.json` is the lock list. LICENSE.txt WARRANTY section is a draft of mine |
| 7 | Help footer + topic, guide cover/footer, product-name protection | Done | Help: `app/views/help/index.php`; PDFs: `CredentialPackageService`, `CvService`; U+2060: `Ownership::p()` | 1.173.0 + 1.173.1 | HTTP OW10, OW15; test O1 (U+2060 after every mention) | The generated PDFs are the only "guides"; the PDF font cannot show U+2060, so PDFs use the plain name |
| 8 | Sweep, made-up data | Done for people and hosting names | `tests/ownership/test_v1173_0.php` O4 (names via `FLX_SWEEP_NAMES`, kept outside the source) | 1.173.0 | sweep of all shipped files | Personal name removed from `KICKOFF.md`, a test and a form example; owner admin e-mail removed from archived memos. Regulators and payers kept (decision D5, pending) |
| 9 | Values in settings / private directory | Done | time zone per organization (1.172.16) with change confirmation; branding; mail; cron lines built from `PHP_BINARY` and `FLXCOREHC_ROOT` on System & updates | 1.173.3 | `tests/ownership/test_v1173_3.php` E3–E4; HTTP TE10 | |
| 10 | Ownership test + UI smoke, negative control | Done for steps 1–5, 7 (part), 8 | `tests/ownership/`, `tools/http_smoke/drive.py` (OW1–OW12), stage 3a13 | 1.173.0 | | |
| 11 | Terms of Use acceptance | Done for the material-change path; text is a draft; **editable by the platform operator** (owner-approved deviation from "cannot be edited from a screen", 11/02/2026: platform administrators only, every save a new append-only version, audited) | `app/src/TermsService.php`, `app/terms/en.php` + `es.php` (defaults), Console → Terms of Use (`terms_versions`, migration 137), `/terms` (public), `/terms/accept`, gate in `index.php`, permission `terms.accept`, `terms_acceptances` (migration 136), Team column, About row | 1.173.2 | `tests/ownership/test_v1173_2.php` T1–T5 + negative controls; HTTP TA1–TA15 | Smoke fixtures must pre-accept (one user left out on purpose); the platform operator is exempt; Terms text is Claude Code's draft |
| 12–19 | | Not started | | see `docs/plans/OWNERSHIP_WHITE_LABEL_ADOPTION_PLAN.md` | | |

## Open items
- Terms of Use: replace the draft text (`app/terms/en.php`, `es.php`) with the owner's/attorney's; build the minor-change notice path (30-day deemed acceptance); subscription terms and data processing addendum for hosted tenants.
- Terms of Use text and LICENSE.txt template from the owner (D3); attorney review of every legal text; Spanish courtesy translations are drafts.
- Decision D5 (regulators and payers as reference data) before any change to catalog names.
- D7: the platform console as the License Hub (licensing release).
- Hosted-only legal documents (subscription agreement, data processing addendum, business-associate terms): owner/attorney.
