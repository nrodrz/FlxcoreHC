# FlxCoreHC — Full Code Audit Report

*Audit date: September 5, 2026 · Codebase: v1.141.0 → fixes shipped in v1.142.0 · Scope: entire
application (202 PHP files, all views, services, routes, cron, installer, assets).*

## 1. Method

Automated sweeps over the whole tree plus manual review of every state-changing route and every
surface added since the previous audit (v1.132): public pages, provider portal, cross-organization
sharing, external sharing, document requests, mobile capture.

| Sweep | Result |
|---|---|
| Syntax lint, every PHP file (202) | Clean |
| SQL built from `$_GET`/`$_POST` | None — parameterized throughout; ORDER BY whitelist |
| `eval`/`exec`/`system`/`unserialize` | None (one false positive: the word "system" in a label) |
| Header injection (`header()` with user input) | None |
| Unescaped user-controlled output in views | None — all user text passes through `e()` |
| Encrypted-at-rest columns referenced raw in views | None |
| CSRF on POST handlers | All handlers verify; 4 flagged lines were guards, not handlers |
| Route-level authorization on write routes | 42 `requireCap` + Master-Admin role checks (3 gaps found, fixed) |
| Session | `session_regenerate_id` on login; HttpOnly + SameSite cookies |
| Security headers | CSP with per-request nonces, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS (when HTTPS) |
| Sensitive directories | `app/`, `database/`, `cron/`, `tools/` denied by `.htaccess`; cron scripts refuse non-CLI |
| Secrets / debug leftovers | None; `display_errors` off outside CLI |
| Tenant isolation | Isolation checker passes; one allow-listed cross-tenant reader (`CrossTenantService`) |
| Installer | Fresh install verified from the packaged zip (71 tables) |

## 2. Findings fixed in this release

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 1 | **High** | **Permissions-Policy header disabled the camera** (`camera=()`), which would block the Take photo / Scan features on real devices. Not caught earlier because HTTP tests don't enforce Permissions-Policy. | `camera=(self)` — same-origin only. |
| 2 | Medium | **Document request could target a foreign credential.** `credential_id` was not validated to belong to the tenant + provider; a submitted document could land on another provider's credential. | `create()` now verifies the credential belongs to this tenant and this provider. |
| 3 | Medium | **Cross-org shared-document download not bound to the route's provider.** A user in the recipient org could fetch a shared document for a provider they could not otherwise see (need-to-know bypass; share ids are unguessable UUIDs, so exploitability was low). | `sharedDocument()` now requires and verifies the recipient's own holder id on the link. |
| 4 | Medium | **Public share password prompt had no per-IP rate limit** — the per-share lock could be spread across many tokens. | Per-IP limiter across all share links (20 attempts / 15 min → 429, logged as `locked`), cleared on success. |
| 5 | Medium | **Emailed links built from the `Host` header** (`invite_link`, `flx_public_base_safe`) — host-header poisoning could put an attacker's domain into invitation/notification emails. | Configured `app_url` (written by the installer) is now always used first; `Host` is a fallback only. |
| 6 | Low | **Cross-org routes lacked route-level gates** (`link-request`, `cross-share`, `cross-shares/revoke`). The service enforced them internally (verified), but defense-in-depth was missing. | Master Admin / `share.manage` checks added at the route. |
| 7 | Low | **Cross-org "view" logging on every page load** could flood `cross_share_access`. | Throttled to once per share per session-hour. |
| 8 | Low | **Audit coverage gaps** — 16 state changes not logged (provider update, tasks, calendar create/move/edit/done, event types, CE hours, attestations, verifications, compliance status). | All logged; `audit_log` call sites 70 → 86. |

Regression/verification after fixes: harness green, isolation check passes, every main page renders
with zero PHP warnings, calendar/CE logging confirmed, rate limiter confirmed at 21 attempts,
packaged installer completes.

## 3. Strengths confirmed (no action needed)

- Parameterized SQL everywhere; blind-index matching for encrypted NPI/NPN; Argon2id passwords with
  rehash-on-login; encrypted-at-rest NPI, email, SMTP password, credential number.
- Uploads: content-sniffed MIME allowlist, 10 MB cap, plan quota, random filenames outside the web
  root, JPEG metadata (GPS) stripped, served only through authorized routes.
- Soft deletes only; permanent `compliance_events` ledger; before→after on credential edits;
  document supersede chain; nothing purged.
- External and cross-org sharing: two-factor (token + Argon2id password) external links with
  per-link expiry ≤180 days, one-time password reveal, brute-force lock, revocation, full access
  logging; three-party consent for cross-org, count-only detection, live read-only shares, cascade
  revocation, logging in both organizations.
- Need-to-know enforced in lists, calendar, notifications and shared-document access.

## 4. Recommendations (not changed in this release)

| Priority | Recommendation | Why / how |
|---|---|---|
| Medium | **Set `app_url` explicitly on every deployment** (Console → verify config). | Fix #5 relies on it; the installer writes it, but a moved domain must update it. |
| Medium | **OAuth/Graph email transport** for Microsoft 365 / Google. | Basic SMTP AUTH is being retired by Microsoft; the `transport` column already reserves the slot. |
| Medium | **CSP `style-src`**: the app uses inline `style=` attributes widely, so `'unsafe-inline'` remains for styles. | Moving to class-based styling would allow a strict style-src. Low risk today (script-src is strict with nonces). |
| Medium | **Old-schema gate in the harness**: load key pages against a DB one migration behind. | Two past incidents (translation `context`, digest column) came from code assuming an unrun migration. Most code now degrades gracefully, but a test would catch regressions. |
| Low | **Scope screenings in external shares.** The public profile always includes the screening summary; add a scope flag if some recipients should not see it. | Currently intentional (payers want it). |
| Low | **Per-recipient max-views on cross-org shares** and an owner-side "last viewed" column. | Parity with external shares. |
| Low | **Tier-3 OCR** (license number / expiry prefill from photos). | Needs a BAA-covered provider; paused by decision. |
| Low | **Automated dependency/PHP-version check** in Console → System (PHP ≥ 8.1, extensions). | The installer checks once; a periodic check would flag host changes. |
| Info | **Rate limiter storage**: file/DB-backed per install; fine for single-host cPanel. | If ever scaled to multiple web nodes, move to a shared store. |

## 5. Help center coverage audit

The help center (`/help`, EN + ES) was compared against every shipped feature. Five features added
after the help was written had no coverage; sections were added for each, in both languages:

- Documents: upload, photo & scan (multi-document, kinds, camera, multi-page PDF)
- Document requests (provider portal flow, accept/return, ownership rules)
- Sharing with insurers, agencies & others (external links: password, expiry, logging, revoke)
- Linking with other organizations (three-party consent, per-credential live sharing, revocation)
- Activity, audit log & digests (dashboard, master log, compliance timeline, email digest)

Result: **18 sections, identical keys in English and Spanish**, verified rendering in both languages.
"Getting started" now cross-links the newer features.

## 6. Verification record

- `harness.sh full` (install + all routes): pass
- `tools/check_isolation.sh`: pass
- Lint: 202/202 files clean
- Security headers observed on a live response (CSP nonce, DENY, nosniff, Referrer-Policy, Permissions-Policy with `camera=(self)`)
- Packaged zip: fresh install completes (71 tables); no config, router or storage artifacts in the package


---

## 7. Follow-up audit (v1.143.0) — platform-operator boundary

A second pass focused on the question "can a SaaS user reach tenant data?" found and fixed a real
gap, and hardened the boundary considerably.

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 9 | **High** | **A platform support session could run the tenant's full data export.** `organization` is on the support route allowlist and `/organization/export` sits under it. Provider/credential/document CSVs came back empty (those repositories were already guarded) but the export still produced the **team roster with names, emails and roles**, and the action should not exist in a support session at all. | New `SupportAccess::denyIfSupport()` hard-stop applied to the export route and to `DataExportService::build()` itself; `/organization/links` (cross-org, shows provider linkage) blocked too. |
| 10 | Medium | **Support-mode blindness covered only three repositories** (holders, credentials, documents). Calendar, screenings, CE entries, verifications, applications, attestations, compliance, renewal tasks, requirements, holder-regulators and the audit log were unguarded — reachable if any allowlisted page or future route read them. | Guard added to all 11; plus `ComplianceEventService`, `DocumentRequestService`, `ExternalShareService`, `CrossTenantService` and `NotificationService` now return empty in support mode. |
| 11 | Low | **Capabilities were coarse** — `share.manage` governed both external sharing *and* cross-organization linking; no separate capability for document verification, bulk export, imports or document requests; `read_only` had no capabilities at all (could not even view documents). | Capability catalog expanded 11 → 17: `records.import`, `documents.verify`, `documents.request`, `crossorg.manage`, `data.export`, `reports.view`. Enforced at their routes. Built-in roles updated (Manager keeps everything; Staff verifies but cannot export or link organizations; the former "Agent" is now **Coordinator** — data entry without compliance attestations; Read-only can view documents and reports). |

**Verified after fixes:** in an active support session every data surface returns empty
(providers, credentials, documents, calendar, screenings, audit, compliance events, notifications,
document requests, external shares, cross-org) and the export is refused.

### New platform-operator tooling (v1.143.0)

- **Console → Security**: live posture of the installation (transport, environment, file
  protections, access control, data protection, operations) plus the shipped code-audit report,
  rendered in-app. Reads no tenant data.
- **Console → tenant page → Usage & health**: adoption, engagement, storage, compliance health,
  feature take-up, a 12-week activity sparkline and top actions — **counts and dates only, never
  record contents** (verified: no provider names appear on the page).


---

## 8. Full-codebase security audit — September 7, 2026 (v1.158.0)

**Scope:** the entire application as of v1.158 — 232 PHP files (70 services, 26 repositories, 112
views, 119 migrations, `index.php` at 4,624 lines), the installer, cron scripts and static assets.
Method: automated sweeps over the whole tree plus manual review of every flagged hit and of every
surface added since the previous audit (v1.142): payers/enrollment, verification, M365 OAuth, hub
navigation, tenant reports, addresses, contacts, custom fields, application data, sensitive fields
with field-level security.

### 8.1 Sweep results

| Area | Result |
|---|---|
| Syntax, all 232 files | Clean |
| SQL built from superglobals | **0** |
| Concatenated SQL (heuristic hits: 4) | All false positives — `information_schema` queries use `$pdo->quote()`; the AuthService hit concatenates a bound placeholder |
| `exec`/`shell_exec`/`system`/`eval`/`unserialize` (hits: 15) | **All are `$pdo->exec()`** (PDO). Zero shell execution anywhere in the codebase |
| Variable `include`/`require` (hits: 5) | Paths built from constants + validated names (`$viewFile`, help/lang files); not user-controlled |
| `header()` / file ops with user input | **0** |
| Unescaped user-controlled output in views | **0** (the two raw echoes are pre-escaped custom-field HTML from `CustomFieldService::render`, verified) |
| CSRF on POST handlers | All handlers verify; the 4 flagged lines are guard conditions, not handlers. Every `<form method="post">` in views carries the token |
| Tenant isolation checker | Pass. All new services that read across tenants are allow-listed and reviewed |
| Support-mode blindness | 19 of 26 repositories guarded (all that hold provider-linked data, including this week's Address, ProviderContact, ProviderEducation, ProviderWorkHistory, and Requirement — see 8.2). The 6 unguarded hold no PHI (event types, groups, locations, regulators, regulator requirements, training resources) |
| Auth & session | Argon2id, `session_regenerate_id` on login, HttpOnly + SameSite=Lax cookies, rate-limited login, 2FA present |
| Security headers | CSP with nonces, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy (`camera=(self)`) |
| **Sensitive fields (DOB / SSN last-4 / sensitive custom fields)** | Referenced by **none** of the outbound surfaces (data export, tenant report, packet, mail, reminders, document requests, external share, cross-org share, digest). No `audit_log` call can carry them. Sensitive custom fields excluded from export and packet by code |

### 8.2 Findings fixed in this release

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 12 | **High** | **`docs/` was web-readable.** It contains `DEMO_ACCOUNTS.md` (demo passwords), `CODE_AUDIT_REPORT.md` (internals and past findings) and the test plan. Any of these could be fetched at `/docs/…` on a live install. | `docs/.htaccess` (deny all) added and shipped; Console → Security now checks `docs/` alongside `app/`, `database/`, `cron/`, `tools/`. |
| 13 | Low | `RequirementRepository` (`holder_requirements`, provider-linked) lacked the support-mode guard. | Guard added; now 19/26 repositories guarded — every one holding provider-linked data. |

### 8.3 Field-level security review (new in v1.157–1.158)

Reviewed as a security control, not just a feature:
- **Data minimisation:** SSN is stored as last-4 only; a pasted 9-digit number is truncated on input, never stored. Verified.
- **Encryption at rest:** `dob_enc`, `ssn_last4_enc` and sensitive custom-field `value_enc` use the same authenticated encryption as NPI; plaintext never in the DB (verified by inspecting raw columns).
- **Authorization:** resolution order is explicit per-role grant (allow/deny) → capability (`pii.view`) → default deny. Master Admin cannot be denied. A user without access cannot **set** the field either (write path is gated, not only display). Verified for built-in and custom fields, including a non-permitted user attempting to overwrite a sensitive custom field (unchanged).
- **Masking:** non-permitted viewers get a mask (`••••-••-••`, `•••-••-1234`, `••••••`); on forms the field is **hidden**, not merely masked, so a masked value can never be re-submitted and clobber the stored one (a re-submitted mask is also ignored server-side).
- **Accounting:** every permitted view and every write of a sensitive field goes to the access log.
- **Outbound exclusion:** enforced by construction (explicit column lists) plus `SensitiveFields::strip()`; sensitive custom fields force `show_in_packet`/`show_in_list` off on save.

### 8.4 Residual risks & recommendations

| Priority | Item |
|---|---|
| High | **Key management.** All encryption keys live in `app/config.php`. With SSN last-4 now stored, this file — and any backup containing it — is the crown jewel. Keep `config.php` out of every backup that is not itself encrypted, restrict file permissions (600), and document a key-rotation procedure. |
| Medium | **Breach-notification posture.** SSN last-4 plus DOB plus name is PII in most frameworks. Confirm your incident-response plan and hosting agreement reflect that, even though full SSNs are never stored. |
| Medium | **`pii.view` hygiene.** It defaults to Master Admin only. Periodically review which custom roles have been granted it or per-field allows (Organization → Roles). Consider surfacing "who can see sensitive data" in Console → Security per tenant. |
| Medium | Old-schema harness gate (still recommended from §4) — several recent incidents were code assuming an unrun migration. |
| Low | CSP `style-src 'unsafe-inline'` remains because of inline styles; script-src is strict. |
| Low | The custom-select enhancer and hub panel add client-side state; neither handles secrets, but keep them in the CSP-nonced inline scripts as they are. |

### 8.5 Verification record (v1.158.0)
`harness.sh full` pass · isolation check pass · 232/232 lint · field-level security tested for
built-in and custom fields across Master Admin / Manager / Staff · exclusion verified for export,
tenant report (PDF+CSV) and packet · packaged installer completes (85 tables).
