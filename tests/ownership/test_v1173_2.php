<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.173.2 — Terms of Use acceptance (Ownership & White-Label Standard, element 10). Runs WITHOUT a database:  php tests/ownership/test_v1173_2.php
 * T1 the Terms files: same structure in English and Spanish, version in the English file, placeholders only the allowed ones
 * T2 the Terms name only the organization and the product; the owner is named only in the independent-developer term
 * T3 the gate: every signed-in request is stopped before the acceptance unless the path is /terms or /logout; the platform operator is exempt
 * T4 the permission terms.accept exists, belongs to the top administrator only, and the service honours it on the server
 * T5 acceptance rows are append-only and each acceptance is audited
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$rd = fn(string $f) => (string) file_get_contents("$ROOT/$f");
$en = require "$ROOT/app/terms/en.php"; $es = require "$ROOT/app/terms/es.php";

// ---- T1 ----
$check(is_string($en['version']) && $en['version'] !== '' && !isset($es['version']), 'T1 the version lives in the English file only');
$check(count($en['sections']) === count($es['sections']) && count($en['sections']) >= 8, 'T1 English and Spanish have the same sections (' . count($en['sections']) . ')');
$same = true; foreach ($en['sections'] as $i => [$h, $ps]) if (count($ps) !== count($es['sections'][$i][1])) $same = false;
$check($same, 'T1 every section has the same number of paragraphs in both languages');
$allText = json_encode([$en, $es], JSON_UNESCAPED_UNICODE);
preg_match_all('/\{(\w+)\}/', $allText, $m);
$check(!array_diff(array_unique($m[1]), ['organization', 'product', 'owner']), 'T1 placeholders are only {organization}, {product}, {owner}');
$check(str_contains($en['draft'], 'DRAFT') && str_contains($es['draft'], 'BORRADOR'), 'T1 both files are labelled as drafts for attorney review');

// ---- T2 ----
$enText = json_encode($en['sections'], JSON_UNESCAPED_UNICODE);
$check(substr_count($enText, '{owner}') === 1 && !str_contains($enText, 'Indisse'), 'T2 the owner is named exactly once, through {owner}, and no owner name is typed in the text');
$ownerSection = null; foreach ($en['sections'] as [$h, $ps]) if (str_contains(implode(' ', $ps), '{owner}')) $ownerSection = $h;
$check($ownerSection === 'Ownership and independent development', 'T2 ...and that one place is the independent-developer term');
$check(substr_count($enText, '{organization}') >= 1, 'T2 the organization is named by its own name');
$check(str_contains($rd('app/src/TermsService.php'), "Ownership::OWNER") && str_contains($rd('app/src/TermsService.php'), "Ownership::p()"), 'T2 {owner} and {product} come from the ownership file');

// ---- T3 ----
$idx = $rd('index.php');
$gate = strpos($idx, "if (!\$ctx->isPlatformAdmin && empty(\$_SESSION['auth']['impersonated_by']) && !in_array(\$seg[0] ?? '', ['terms', 'logout'], true)) {");
$login = strpos($idx, "if (!\$ctx) {\n    redirect('/login');\n}");
$route = strpos($idx, "if ((\$seg[0] ?? '') === 'terms' && (\$seg[1] ?? '') === 'accept') {");
$firstApp = strpos($idx, "\$GLOBALS['flx_features'] = PlanService::featuresFor");
$check($login !== false && $route > $login && $gate > $route && $gate < $firstApp, 'T3 the gate sits right after the sign-in check, before any application route');
$check(str_contains($idx, "redirect('/terms/accept')"), 'T3 a user who has not accepted is sent to /terms/accept');
$check(str_contains($idx, "\$_SESSION['terms_ok']") && str_contains($rd('app/src/TermsService.php'), "\$ctx->userId . '|' . \$v"), 'T3 the session remembers the acceptance per user AND per version (a new version asks again)');
$check(preg_match("/\(\\\$seg\[0\] \?\? ''\) === 'terms' && !isset\(\\\$seg\[1\]\)/", $idx) === 1, 'T3 only /terms itself is public; /terms/accept needs a sign-in');

// ---- T4 ----
$caps = $rd('app/src/Capabilities.php'); $roles = $rd('app/src/RoleService.php');
$check(str_contains($caps, "'terms.accept'") && !str_contains($roles, "'terms.accept'"), 'T4 terms.accept is in the Roles editor catalog and is a default of no role except the top administrator (*)');
$check(str_contains($rd('app/src/TermsService.php'), 'Authz::can($ctx, self::PERMISSION)'), 'T4 the organization acceptance is checked against terms.accept on the server');
// ---- T5 ----
$svc = $rd('app/src/TermsService.php'); $mig = $rd('database/migrations/136_terms_acceptances.sql');
$check(!preg_match('/\b(UPDATE|DELETE)\s+(FROM\s+)?terms_acceptances/i', $svc . $idx), 'T5 nothing updates or deletes an acceptance row');
$check(str_contains($svc, "audit_log(\$ctx, 'terms_accepted'") && str_contains($svc, "'version' => \$v, 'scope' => \$scope"), 'T5 every acceptance is an audit row with user, organization, version');
$check(str_contains($mig, 'terms_version') && str_contains($rd('install.php'), '136_terms_acceptances.sql') && str_contains($rd('database/manifest.php'), '136_terms_acceptances.sql'), 'T5 migration 136 is listed by install.php and the manifest');
echo $fail ? "OWNERSHIP 1.173.2: FAIL\n" : "OWNERSHIP 1.173.2: ALL PASS\n";
exit($fail ? 1 : 0);
