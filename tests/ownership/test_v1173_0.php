<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.173.0 — Ownership & White-Label Standard, steps 1-4 and 8. Runs WITHOUT a database:  php tests/ownership/test_v1173_0.php
 * O1 the ownership constants and statements are exactly the standard's wording
 * O2 `about` returns the short view to everyone and the full view only behind license.view (decided on the server)
 * O3 the sign-in line + Terms link, Help button/footer, Settings link, org-name audit, workspace ID rules are in place
 * O4 sweep: no person, hosting provider or customer name anywhere in the shipped files. Patterns are built from pieces; people's names
 *    are NOT in the source — pass them as FLX_SWEEP_NAMES="name1,name2" (without it that one check is reported SKIPPED, not passed).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$rd = fn(string $f) => (string) file_get_contents("$ROOT/$f");

// ---- O1 ----
$src = $rd('app/src/Ownership.php');
define('FLXCOREHC_VERSION', 'test');
require_once "$ROOT/app/src/Ownership.php";
use FlxCoreHC\Ownership as O;
$W = "\u{2060}";   // the invisible word joiner that protects the product name in ownership statements (v1.173.1)
$n = fn(string $x) => str_replace($W, '', $x);
$check(O::OWNER === 'Indisse-BTS', 'O1 owner is Indisse-BTS');
$check(O::copyright() === '© 2026 Indisse-BTS. All rights reserved.', 'O1 copyright line');
$check(O::fileHeader() === '© 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.', 'O1 source-file header');
$check($n(O::poweredBy()) === 'Powered by FlxCoreHC · © 2026 Indisse-BTS. All rights reserved.', 'O1 sign-in "Powered by" line');
$check($n(O::developedBy()) === 'FlxCoreHC is designed, developed and owned by Indisse-BTS, drawing on 30 years managing IT operations for multiple companies in Puerto Rico and Florida.', 'O1 developed-by sentence');
$ind = $n(O::independentDeveloper());
$check(str_starts_with($ind, 'FlxCoreHC is an independent product. It was conceived, designed and developed by Indisse-BTS as an independent software developer, and not as an employee, contractor or consultant of any company or organization.')
    && str_ends_with($ind, 'gives no organization any ownership interest in it, in its updates, or in improvements made at its request.')
    && str_contains($ind, 'is not a work made for hire for, any organization that uses it'), 'O1 independent-developer statement');
$tRaw = O::licenseTerms(); $t = array_map($n, $tRaw);
$check(count($t) === 8, 'O1 eight license lines');
$check(str_starts_with($t[0], 'FlxCoreHC is proprietary software. It is licensed, not sold') && str_starts_with($t[3], 'Unless the agreement allows it in writing:')
    && str_starts_with($t[4], 'The organization owns its own data.') && str_starts_with($t[7], 'The owner is an independent software vendor'), 'O1 the lines are in the standard\'s order');
$check(!str_contains(implode(' ', $t), '<Product>'), 'O1 no unreplaced placeholder');
$check(str_contains(O::developedBy(), 'FlxCoreHC' . $W) && str_contains(O::poweredBy(), 'FlxCoreHC' . $W) && str_contains(O::independentDeveloper(), 'FlxCoreHC' . $W) && substr_count(implode(' ', $tRaw), 'FlxCoreHC' . $W) === substr_count(implode(' ', $t), 'FlxCoreHC'), 'O1 every mention of the product in the ownership statements is followed by U+2060 (product-name protection)');
$check(O::TERMS_CLOSING === 'The signed agreement of each organization is what applies.', 'O1 closing sentence');
$sp = O::spanish();
$check(count($sp['terms']) === 8 && str_contains($sp['independent'], 'work made for hire'), 'O1 Spanish courtesy translation has all eight lines');

// ---- O2 ----
$i1 = strpos($src, "return ['view' => 'short'] + \$short;"); $i2 = strpos($src, '$org  = (new OrgService');
$check($i1 !== false && $i2 !== false && $i1 < $i2, 'O2 the short answer is returned BEFORE any full-view field is read');
$check(str_contains($src, "Authz::can(\$ctx, self::VIEW_PERMISSION)") && O::VIEW_PERMISSION === 'license.view', 'O2 the full view is decided by Authz::can(license.view) on the server');
foreach (['workspace_id', 'license_terms', 'plan', 'user_limit', 'users_in_use', 'name_shown'] as $f) {
    $shortPart = substr($src, strpos($src, '$short = ['), strpos($src, 'if (!Authz::can') - strpos($src, '$short = ['));
    $check(!str_contains($shortPart, "'$f'"), "O2 short view carries no \"$f\"");
}
$caps = $rd('app/src/Capabilities.php'); $roles = $rd('app/src/RoleService.php');
$check(str_contains($caps, "'license.view'"), 'O2 license.view is in the capability catalog (Roles editor)');
$check(substr_count($roles, "'license.view'") === 2, 'O2 license.view is a default of the manager and admin roles (Master Admin has *)');
$idx = $rd('index.php');
$check(preg_match("/if \(\\\$seg\[0\] === 'about' && \\\$method === 'GET'\)/", $idx) === 1 && strpos($idx, "\$seg[0] === 'about'") > strpos($idx, "if (!\$ctx) {\n    redirect('/login');"), 'O2 /about is behind the sign-in gate');
$check(preg_match("/\(\\\$seg\[0\] \?\? ''\) === 'terms'/", $idx) === 1 && strpos($idx, "=== 'terms'") < strpos($idx, "if (!\$ctx) {\n    redirect('/login');"), 'O2 /terms is public (before the sign-in gate)');

// ---- O3 ----
$login = $rd('app/views/auth/login.php');
$check(str_contains($login, 'Ownership::poweredBy()') && str_contains($login, '/terms') && str_contains($login, 'SIGNIN_STATEMENT'), 'O3 sign-in page: Powered-by line, "licensed, not sold" and the Terms link');
$help = $rd('app/views/help/index.php');
$check(str_contains($help, '/about') && str_contains($help, 'Ownership::copyright()'), 'O3 Help Center: About & ownership button and copyright footer');
$check(str_contains($rd('app/views/settings/index.php'), '/about'), 'O3 Administration (Settings) links to About & ownership');
$check(str_contains($idx, "'org_name_changed'") && str_contains($idx, "'org_display_name_changed'") && str_contains($idx, "'from' =>") , 'O3 organization name changes are audited old -> new');
$org = $rd('app/src/OrgService.php');
$check(str_contains($org, 'AND workspace_id IS NULL') && str_contains($org, "strtoupper(implode('-', str_split(bin2hex(random_bytes(8)), 4)))"), 'O3 workspace ID: random uppercase hex XXXX-XXXX-XXXX-XXXX, only ever fills a NULL');
$check(!str_contains($rd('app/views/settings/index.php'), 'name="workspace_id"') && !preg_match("/workspace_id/", substr($org, strpos($org, 'function updateInfo'), 3000)), 'O3 no form or update path can edit the workspace ID');
$check(str_contains($rd('database/manifest.php'), '135_workspace_id.sql') && str_contains($rd('install.php'), '135_workspace_id.sql'), 'O3 migration 135 is listed by install.php and the manifest');

// ---- O4 sweep ----
$pieces = [['Host','Gator'], ['Blue','host'], ['Go','Daddy'], ['Site','Ground'], ['Name','cheap'], ['In','Motion Hosting'], ['A2 ','Hosting']];
$banned = array_map(fn($p) => preg_quote(implode('', $p), '/'), $pieces);
$names = array_filter(array_map('trim', explode(',', (string) getenv('FLX_SWEEP_NAMES'))));
$all = array_merge($banned, array_map(fn($n) => preg_quote($n, '/'), $names));
$rx = '/\b(' . implode('|', $all) . ')\b/iu';
$hits = []; $files = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS),
    fn($f) => !in_array($f->getFilename(), ['.git', 'validation', 'releases', 'node_modules', 'uploads'], true)));
foreach ($it as $f) {
    if (!$f->isFile() || !in_array($f->getExtension(), ['php','js','jsx','mjs','sql','md','json','txt','html','css','py','sh'], true)) continue;
    $files++;
    if (preg_match($rx, (string) file_get_contents($f->getPathname()), $m)) $hits[] = substr($f->getPathname(), strlen($ROOT) + 1) . " ({$m[1]})";
}
$check($hits === [], "O4 sweep of $files files: no hosting-provider name" . ($names ? ' and none of the ' . count($names) . ' listed people' : '') . ($hits ? ' — ' . implode('; ', $hits) : ''));
if (!$names) echo "SKIPPED — O4 people-name sweep (set FLX_SWEEP_NAMES to the list kept outside the source)\n";
echo $fail ? "OWNERSHIP 1.173.0: FAIL\n" : "OWNERSHIP 1.173.0: ALL PASS\n";
exit($fail ? 1 : 0);
