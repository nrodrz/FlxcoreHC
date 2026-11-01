#!/usr/bin/env bash
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
# FlxCoreHC v1.173.2 — runtime validation for Claude Code / a PHP+MySQL host.
# Runs: PHP lint (all files) → isolation → agency, purge, authz, migration, HTTP smoke, installer and security suites → harness. Reports PASS/FAIL per stage.
# Does NOT modify code. Run from the app root on a TEST environment (never production).
set -u
root="$(cd "$(dirname "$0")/.." && pwd)"; cd "$root" || exit 1
fail=0
# v1.171.6: remember which .notif-sweep-* stamp files already exist (a used installation legitimately has them); stage 5 fails only on files THIS run created.
stray_before="$(ls -A app/storage 2>/dev/null | grep '^\.notif-sweep-' | sort)"
line() { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

line "1. PHP syntax (php -l) on all files"
if command -v php >/dev/null 2>&1; then
  bad=$(find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "No syntax errors detected")
  if [ -n "$bad" ]; then echo "$bad"; echo "SYNTAX: FAIL"; fail=1; else echo "SYNTAX: PASS (all files)"; fi
else
  echo "SYNTAX: SKIPPED — php not found on PATH"; fail=1
fi

line "2. Tenant isolation (tools/check_isolation.sh)"
if [ -f tools/check_isolation.sh ]; then bash tools/check_isolation.sh; else echo "check_isolation.sh missing"; fail=1; fi

line "3. Agency credentialing tests"
if command -v php >/dev/null 2>&1; then
  php tests/agency/run.php; rc=$?   # isolated flx_agency DB (the bare test file needs this bootstrap)
  [ $rc -ne 0 ] && { echo "AGENCY TESTS: FAIL (exit $rc) — if it broke at STARTUP, that's the flx_char bootstrap, not the feature; send the error to Claude, do not edit tests."; fail=1; }
else echo "AGENCY TESTS: SKIPPED — php not found"; fail=1; fi

line "3a. Healthcare-only checks (v1.170.7, no database needed)"
if command -v php >/dev/null 2>&1; then
  php tests/healthcare/test_healthcare_only.php || { echo "HEALTHCARE CHECKS: FAIL — send the output to Claude."; fail=1; }
else echo "HEALTHCARE CHECKS: SKIPPED — php not found"; fail=1; fi

line "3a2. Reminder thresholds + Spanish dictionary checks (v1.172.3, no database needed)"
if command -v php >/dev/null 2>&1; then
  php tests/i18n/test_v1172_3.php || { echo "I18N/REMINDER CHECKS: FAIL — send the output to Claude."; fail=1; }
else echo "I18N/REMINDER CHECKS: SKIPPED — php not found"; fail=1; fi

line "3a3. Outbound e-mail client against a real local SMTP conversation (v1.172.4, no database needed)"
if command -v php >/dev/null 2>&1 && command -v openssl >/dev/null 2>&1; then
  php tests/mail/test_v1172_4.php || { echo "MAIL CHECKS: FAIL — send the output to Claude."; fail=1; }
else echo "MAIL CHECKS: SKIPPED — php or openssl not found"; fail=1; fi

line "3a4. Dates: MM/DD/YYYY in local time, no UTC labels (v1.172.8, no database needed)"
php tests/dates/test_v1172_6.php || { echo "DATE CHECKS: FAIL — send the output to Claude."; fail=1; }

line "3a5. Spanish gaps, notification/calendar texts, hints (v1.172.9, no database needed)"
php tests/i18n/test_v1172_9.php || { echo "I18N 1.172.9 CHECKS: FAIL — send the output to Claude."; fail=1; }

line "3a6. Validation findings: SMTP host guard, ISO dates, sign-in language, password rules (v1.172.10, no database needed)"
php tests/i18n/test_v1172_10.php || { echo "VALIDATION-FINDINGS 1.172.10 CHECKS: FAIL — send the output to Claude."; fail=1; }

line "3a7. \"Today\" is the organization's day; typed times are stored in UTC (v1.172.11, no database needed)"
php tests/dates/test_v1172_11.php || { echo "DATE 1.172.11 CHECKS: FAIL — send the output to Claude."; fail=1; }

line "3a8. No database-side UTC \"today\" in runnable code (incl. cron); calendar month window; agenda overdue rule (v1.172.12, no database needed)"
php tests/dates/test_v1172_12.php || { echo "DATE 1.172.12 CHECKS: FAIL — send the output to Claude."; fail=1; }
line "3a9. Every flash/service message has a Spanish entry; placeholder messages translate; installer secure-cookie default (v1.172.13, no database needed)"
php tests/i18n/test_v1172_13.php || { echo "I18N 1.172.13 CHECKS: FAIL — send the output to Claude."; fail=1; }
line "3a10. Daylight-saving edge cases (gap refused, second 01:30 kept) and per-item overdue marking (v1.172.14, no database needed)"
php tests/dates/test_v1172_14.php || { echo "DATE 1.172.14 CHECKS: FAIL — send the output to Claude."; fail=1; }
line "3a11. Per-organization time zone source guards (v1.172.16, no database needed)"
php tests/dates/test_v1172_16.php || { echo "DATE 1.172.16 CHECKS: FAIL — send the output to Claude."; fail=1; }
line "3a12. Spanish screens: Help frame, audit package, placeholder messages, month/weekday names (v1.172.17, no database needed)"
php tests/i18n/test_v1172_17.php || { echo "I18N 1.172.17 CHECKS: FAIL — send the output to Claude."; fail=1; }
line "3a13. Ownership & White-Label Standard steps 1-4, 8: statements, about view split, sign-in line, sweep (v1.173.0, no database needed)"
php tests/ownership/test_v1173_0.php || { echo "OWNERSHIP 1.173.0 CHECKS: FAIL — send the output to Claude."; fail=1; }
line "3a14. LICENSE.txt, THIRD-PARTY-NOTICES.txt, ownership headers on every program file, PDF cover/footer (v1.173.1, no database needed)"
php tests/ownership/test_v1173_1.php || { echo "OWNERSHIP 1.173.1 CHECKS: FAIL — send the output to Claude."; fail=1; }
line "3a15. Terms of Use acceptance: text, gate, permission, audit (v1.173.2, no database needed)"
php tests/ownership/test_v1173_2.php || { echo "OWNERSHIP 1.173.2 CHECKS: FAIL — send the output to Claude."; fail=1; }

line "3b. Purge + security tests (v1.170.6, isolated flx_purge DB)"
if command -v php >/dev/null 2>&1; then
  php tests/purge/run.php; rc=$?
  [ $rc -ne 0 ] && { echo "PURGE TESTS: FAIL/SKIP (exit $rc) — send the exact output to Claude; do not edit code or tests."; fail=1; }
else echo "PURGE TESTS: SKIPPED — php not found"; fail=1; fi

line "3c. Provider-scope static guards (v1.170.9, no database needed)"
if command -v php >/dev/null 2>&1; then
  php tests/authz/test_provider_scope_static.php || { echo "PROVIDER-SCOPE STATIC: FAIL — send the output to Claude."; fail=1; }
else echo "PROVIDER-SCOPE STATIC: SKIPPED — php not found"; fail=1; fi

line "3d. Authorization regression suite (v1.170.9, isolated flx_authz DB — cross-tenant, need-to-know, LIMIT, capability, portal, fail-closed)"
if command -v php >/dev/null 2>&1; then
  php tests/authz/run.php; rc=$?
  [ $rc -ne 0 ] && { echo "AUTHZ SUITE: FAIL/SKIP (exit $rc) — send the exact output to Claude; do not edit code or tests."; fail=1; }
else echo "AUTHZ SUITE: SKIPPED — php not found"; fail=1; fi

line "3d2. Per-organization time zone (v1.172.16, isolated flx_tz DB — zone saved, clock/day per organization, sweep per organization's own day, @flx_today)"
if command -v php >/dev/null 2>&1; then
  php tests/tz/run.php; rc=$?
  [ $rc -ne 0 ] && { echo "TIME-ZONE SUITE: FAIL/SKIP (exit $rc) — send the exact output to Claude; do not edit code or tests."; fail=1; }
  if command -v faketime >/dev/null 2>&1; then   # 06:00 UTC: Puerto Rico / Eastern are already on the next day, Honolulu is not
    faketime '2026-11-02 06:00:00' php tests/tz/run.php >/tmp/flx_tz_fake.out 2>&1; rc=$?
    grep -E '^(PASS|FAIL)' /tmp/flx_tz_fake.out | sed 's/^/[clock 06:00 UTC] /'
    [ $rc -ne 0 ] && { echo "TIME-ZONE SUITE (clock 06:00 UTC): FAIL (exit $rc)"; fail=1; }
  else echo "NOTE: faketime not installed — the 06:00 UTC run (days differ between zones) was skipped; the normal run still checks every zone's own day."; fi
else echo "TIME-ZONE SUITE: SKIPPED — php not found"; fail=1; fi

line "3e. Migration scenarios A-D for 124/125 (v1.170.9, throwaway flx_mig_* DBs)"
if command -v php >/dev/null 2>&1; then
  php tests/migrations/run_scenarios.php; rc=$?
  [ $rc -ne 0 ] && { echo "MIGRATION SCENARIOS: FAIL/SKIP (exit $rc) — send the exact output to Claude; do not edit migrations or tests."; fail=1; }
else echo "MIGRATION SCENARIOS: SKIPPED — php not found"; fail=1; fi

line "3f. HTTP smoke + authorization probes + role crawl (v1.170.9, isolated flx_smoke DB, php -S)"
if command -v php >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1 && command -v mysql >/dev/null 2>&1; then
  bash tools/http_smoke/run.sh; rc=$?
  [ $rc -ne 0 ] && { echo "HTTP SMOKE: FAIL/SKIP (exit $rc) — send the exact output to Claude; do not edit code or tests."; fail=1; }
else echo "HTTP SMOKE: SKIPPED — needs php, python3 and the mysql client"; fail=1; fi

line "3f2. Real web installer over http and https (v1.171.5, isolated flx_install_chk DB, php -S, temp FLX_CONFIG)"
if command -v php >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1 && command -v mysql >/dev/null 2>&1; then
  bash tools/http_smoke/installer_check.sh; rc=$?
  [ $rc -ne 0 ] && { echo "INSTALLER CHECK: FAIL/SKIP (exit $rc) — send the exact output to Claude; do not edit code or tests."; fail=1; }
else echo "INSTALLER CHECK: SKIPPED — needs php, python3 and the mysql client"; fail=1; fi

line "3g. v1.171.0 security suite (capability ceiling, session revalidation, support grants, scope, migrator, keys, encrypted documents; isolated flx_security DB)"
if command -v php >/dev/null 2>&1; then
  php tests/security/run.php; rc=$?
  [ $rc -eq 3 ] && { echo "SECURITY SUITE: SKIPPED (exit 3) — needs MySQL and a config (app/config.php or FLX_CONFIG)."; fail=1; }
  [ $rc -ne 0 ] && [ $rc -ne 3 ] && { echo "SECURITY SUITE: FAIL (exit $rc) — send the exact output to Claude; do not edit code or tests."; fail=1; }
else echo "SECURITY SUITE: SKIPPED — php not found"; fail=1; fi

line "4. Route smoke test (harness.sh full)"
if [ -f ./harness.sh ]; then ./harness.sh full; else echo "harness.sh not present in this checkout (dev-only) — skip if you don't have it"; fi

line "5. Nothing left behind in the application tree (v1.171.5)"
stray_after="$(ls -A app/storage 2>/dev/null | grep '^\.notif-sweep-' | sort)"
stray="$(comm -13 <(printf '%s\n' "$stray_before") <(printf '%s\n' "$stray_after") | grep -v '^$' | head -5)"
if [ -n "$stray" ]; then echo "STRAY FILES: FAIL — this run left files in app/storage:"; echo "$stray"; fail=1; else echo "STRAY FILES: PASS (this run created no .notif-sweep-* in app/storage; $(printf '%s\n' "$stray_before" | grep -c . ) pre-existing)"; fi

line "RESULT"
[ $fail -eq 0 ] && echo "All automated stages PASS. Now do the browser spot-checks in docs/architecture/CLAUDE_CODE_RUNTIME_CHECK.md Steps 3b–3o and 4 (and, where Playwright is available, bash tools/browser_check/run.sh)." || echo "One or more stages FAILED — capture the exact output above and send it to Claude (do not fix-to-green)."
exit $fail
