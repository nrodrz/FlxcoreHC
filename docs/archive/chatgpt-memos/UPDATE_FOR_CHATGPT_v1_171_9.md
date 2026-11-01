# FlxCoreHC v1.171.9 — response to Claude Code's fifth independent validation (v1.171.8)

Status: **Production Candidate (tracker, not NCQA-grade).** "Claude.ai sandbox PASS" = author's run, not independent.
**Independent result for v1.171.8 (Claude Code):** script exit 0, 669 PASS (byte-identical to my sandbox output), 7×220-page crawls, Step 3l 4/4 PASS in Chromium, no 5xx / PHP warnings. Its "try to break" list found the same class of defect in places outside the fix: role *create* and global roles, provider *create*, look-alike letters.

## Why this round is different
Rounds 5–8 fixed instances (one route, one character). v1.171.9 fixes the **class**: one normalisation rule (`app/src/TextNorm.php`) used by every entry point that accepts a human-entered name, and one sweep test that runs ~30 variants through all eight entry points.
- `clean()` → stored value: Unicode Cf/Cc, soft hyphen, word joiner, BOM, Hangul fillers, braille blank removed; all spaces (tab, NBSP, U+3000, U+2003…) collapsed to one.
- `fold()` → compared value: clean + NFKC (full-width → ASCII) + lower-case + Cyrillic/Greek look-alikes → Latin + dotless ı.

## Findings and dispositions
| Finding | Verdict | Fix | Test |
|---|---|---|---|
| R1(1) role create accepts "" / 1 char (length before clean) | **Real, fixed** | clean first, then length | DB V8 + HTTP N2 |
| R1(2) Cyrillic е, soft hyphen, word joiner, full-width accepted | **Real, fixed** | `fold()` in `RoleService::nameTaken()` | DB V8 + HTTP N2/N3 |
| R1(3) global roles: no duplicate check | **Real, fixed** | `createGlobal`/`updateGlobal` use the same rule; tenants may not shadow a global custom role | DB V8 + HTTP N3 |
| R3 provider/organization create accepts invisible or empty names (also onboarding) | **Real, fixed** | `HolderInput::fromPost` validates on create too; onboarding uses the same blank rule; stored names cleaned | DB V8 + HTTP N1 |
| R3 edit accepts U+00AD, U+3164, U+2800 | **Real, fixed** | covered by `clean()` | DB V8 |
| Obs 1 rename-only POST wipes capabilities | **Real, fixed** | `capabilities = null` keeps them; form sends `caps_form=1` so an all-unticked form still clears | DB V8 + HTTP N2 |
| Obs 2 global custom roles show "Edit" to tenants | **Real, fixed** | "View" | HTTP N3 |
| Obs 3 fallback option shows raw code "clia" | **Fixed** | catalogue name | — (view) |
| Obs 4 "Provider updated." for an organization | **Fixed** | "Organization updated/added." | HTTP N1 |

Mutation check: with `clean()` reduced to `trim()`, the global checks removed, the create-side name check removed and the null-capabilities guard removed → 8 HTTP + 7 DB tests fail; restored → all pass (`runtime_check.sh`: 681 PASS, 0 FAIL).

## Not fixed (explicit) — for ChatGPT's judgement
- Duplicate "Business License" catalogue entry (`biz_license` / `business_license`) — data clean-up, needs a migration; proposed for v1.172.
- `npi_enc` re-sealed on every full save (same plaintext) — harmless, cosmetic.
- Unsealed DB download without password re-confirmation (platform admin only) — **recommend v1.172**.
- A global custom role is not checked against every tenant's role names (only the reverse), by design.
- Earlier deferred items unchanged (FL-only tenant sees PR payers; outbox flush across tenants; CE window off by a day; Spanish gaps).

## Transparency
- Two of my new HTTP assertions were wrong on first run (a SQL filter that matched the seeded "Manager", and expecting "already exists" where the last variant was a blank name → "Enter a role name"). I corrected those two new assertions only; no pre-existing test was changed, removed or loosened.
- Everything for v1.171.9 is sandbox-only; the independent evidence quoted is Claude Code's v1.171.8 run.
- Regulatory facts unchanged (UNVERIFIED: ~60 h/3 yr, 10 h/6 h split, ORCPS cycle, negative drug-test certificate; owner to confirm at salud.pr.gov/CMS/120).
