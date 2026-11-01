# FlxCoreHC v1.171.10 — closeout release (answers the two REQUIRED items of the v1.171.9 second-opinion review)

Status: **Production Candidate (tracker, not NCQA-grade).** Scope kept to the review's "very small closeout release": TextNorm prerequisite, OrgService identity names, tests, install docs. Nothing else changed (no index.php split, no catalogue migration, no encryption/role/authz redesign, no features, no regulatory text).

## REQUIRED 1 — NFKC prerequisite (ext-intl)
- **Disposition:** ext-intl / `Normalizer` is now an explicit production prerequisite; no home-grown NFKC.
- **Files changed:** `app/src/TextNorm.php` (`normalizerAvailable()`, `prerequisiteError()`, `fold()` throws `RuntimeException` in production when Normalizer is missing; dev/test keep the documented full-width-only fallback; test hook `$assumeNormalizerMissing`), `app/bootstrap.php` (web request on a production install without Normalizer → HTTP 503 "FlxCoreHC cannot start: PHP extension intl…", logged), `install.php` (`intl` added to the hard requirements → install refused without it), `INSTALL.md` (requirements + upgrade note).
- **How enforced:** installer (hard requirement) + bootstrap (production, web SAPI) + `fold()` itself (defence in depth).
- **Tests added:** `tests/security` V9: (a) Normalizer available on the host; (b) real NFKC proven with compatibility characters the fallback cannot handle — ﬀ ligature (U+FB00), circled ① (U+2460), Roman numeral Ⅸ (U+2168), full-width, combining acute vs precomposed é — and "Office Manager" ≠ "Office Managers"; (c) missing path: production `fold()` throws naming intl, `prerequisiteError()` names it, dev falls back (ﬀ ≠ ff there — documented weaker). `tools/http_smoke/installer_check.py`: requirements table lists "PHP extension: intl" with OK.
- **Result:** PASS (sandbox). Mutation: removing the production throw and the NFKC call → V9 (b) and (c) FAIL; removing `intl` from the installer list → installer check FAIL.

## REQUIRED 2 — Tenant organization identity names
- **Disposition:** `TextNorm::clean()` before storage; blank-after-clean refused where the form sends the field; **no** cross-tenant uniqueness (two tenants may share a name), as the review asked.
- **Exact fields:** `tenants.name`, `tenants.brand_name` (`OrgService::updateInfo`), `tenants.legal_name` (`OrgService::updateEntity`). Not touched (per the review): notes, descriptions, document/credential names, regulatory text, payers, tracked-document types.
- **Files/functions:** `app/src/OrgService.php` (`updateInfo`, `updateEntity`, new static `nameError()`), `index.php` Settings POST (`nameError` → error flash, redirect; name unchanged).
- **Sweep variants tested (DB V9 + HTTP O1):** ordinary name, leading/trailing whitespace, U+3000 and multiple Unicode spaces, soft hyphen, word joiner, BOM, Hangul filler U+3164, braille blank U+2800, zero-width joiners, and the 13 invisible-only inputs from the v1.171.9 list (each refused; stored value unchanged). Full-width/look-alike comparison is not applicable (no uniqueness rule for tenant names) — stored values are cleaned, not folded.
- **Result:** PASS (sandbox). Mutation: `clean()`→`trim()` in OrgService and `nameError` on raw trim → V9 (d) and O1 FAIL.

## Full regression (REQUIRED 4)
`FLX_CONFIG=<base config> bash tools/runtime_check.sh`, `app/config.php` absent: **689 PASS, 0 FAIL, exit 0**, 7×220-page crawls, STRAY FILES PASS, no PHP warnings (`runtime_check_output_v1.171.10.txt`). No existing test removed, weakened or modified; only additions (V9, O1, installer intl row). One new assertion of mine (installer intl row regex) was corrected after its first run — test-side typo, no code change.

## Evidence classification
- **Claude.ai sandbox execution:** everything in this document for v1.171.10.
- **Claude Code independent execution:** v1.171.8 (exit 0, 669 PASS, Step 3l 4/4, Chromium). **v1.171.9 and v1.171.10 not yet independently executed** — the bundle for the final run (REQUIRED 5) accompanies this release.
- **Browser validation:** Steps 3m/3n pending (Claude Code's Chromium run).
- **External production/cPanel validation:** none yet; 3g-8 (https calendar URL) still needs a production https install.

## Deferred — confirmed unchanged
O1 unsealed DB download (v1.172: re-confirm password or retire), O2 global-vs-tenant role-name warning, O3 duplicate "Business License" catalogue entries (migration, v1.172), O4 NPI ciphertext re-sealing (not a defect), O5 FL-only tenant sees PR payers, O6 CE window edge, O7 Spanish gaps. Regulatory statements unchanged and still labelled UNVERIFIED (~60 h/3 yr, 10 h/6 h split, ORCPS cycle, negative drug-test certificate).

## Stop condition
Per the review: once Claude Code's independent run of v1.171.10 is clean (no new material security/data-integrity defect), the v1.171.x cycle is closed; remaining OPTIONAL items move to v1.172 and the project proceeds to production-like deployment validation.
