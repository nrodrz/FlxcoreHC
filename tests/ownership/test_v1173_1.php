<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.173.1 — Ownership & White-Label Standard, steps 6 and 7: LICENSE.txt, THIRD-PARTY-NOTICES.txt, file headers, product-name
 * protection, PDF cover/footer. Runs WITHOUT a database:  php tests/ownership/test_v1173_1.php
 * P1 LICENSE.txt: the nine sections in order, LF line ends, the eight license lines say exactly what the product shows
 * P2 THIRD-PARTY-NOTICES.txt: current (generated from tools/third_party.json), "name@version (license)" lines, LF, every shipped third-party file listed
 * P3 every program file has the ownership header (tools/ownership_headers.php --check)
 * P4 PDFs carry the product line and the copyright line; the About full view carries both texts
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$rd = fn(string $f) => (string) file_get_contents("$ROOT/$f");
define('FLXCOREHC_VERSION', 'test');
require_once "$ROOT/app/src/Ownership.php";
use FlxCoreHC\Ownership as O;
$norm = fn(string $s) => trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{2060}", '', $s)));

// ---- P1 ----
$lic = $rd('LICENSE.txt');
$check($lic !== '' && !str_contains($lic, "\r"), 'P1 LICENSE.txt is in the package root with LF line ends');
$pos = -1; $okOrder = true;
foreach (['OWNERSHIP', 'INDEPENDENT DEVELOPMENT', 'LICENSED, NOT SOLD', 'RESTRICTIONS', "THE LICENSEE'S DATA", 'OTHER NAMES', 'THIRD-PARTY COMPONENTS', 'WARRANTY'] as $h) {
    $p = strpos($lic, "\n$h\n"); if ($p === false || $p < $pos) { $okOrder = false; echo "   missing or out of order: $h\n"; } else $pos = $p;
}
$check($okOrder, 'P1 every standard section is there, in order (OWNERSHIP … WARRANTY)');
$check(str_contains($lic, 'DRAFT FOR ATTORNEY REVIEW') && str_contains($lic, '© 2026 Indisse-BTS. All rights reserved.'), 'P1 marked as a draft for attorney review; copyright line present');
$nl = $norm($lic); $allIn = true;
foreach (O::licenseTerms() as $i => $line) if (!str_contains($nl, $norm($line))) { $allIn = false; echo '   license line ' . ($i + 1) . " not found word for word\n"; }
$check($allIn, 'P1 all eight license lines of the product appear in LICENSE.txt word for word');
$check(str_contains($nl, $norm(O::developedBy())) && str_contains($nl, $norm(O::independentDeveloper())), 'P1 the developed-by sentence and the independent-developer statement appear word for word');

// ---- P2 ----
exec('php ' . escapeshellarg("$ROOT/tools/third_party_notices.php") . ' --check 2>&1', $o2, $rc2);
$check($rc2 === 0, 'P2 notices are current and complete: ' . implode(' | ', $o2));
$tp = $rd('THIRD-PARTY-NOTICES.txt');
$check($tp !== '' && !str_contains($tp, "\r") && preg_match('/^[^\n]+@[^\n]+ \([^)\n]+\)$/m', $tp) === 1 && str_contains($tp, str_repeat('-', 78)), 'P2 "name@version (license)" lines, a rule, LF line ends');
$check(str_contains($tp, 'SIL OPEN FONT LICENSE') && str_contains($tp, 'MIT License'), 'P2 the full license texts (SIL OFL, MIT) are included');

// ---- P3 ----
exec('php ' . escapeshellarg("$ROOT/tools/ownership_headers.php") . ' --check 2>&1', $o3, $rc3);
$check($rc3 === 0, 'P3 ' . end($o3));

// ---- P4 ----
$pkg = $rd('app/src/CredentialPackageService.php'); $cv = $rd('app/src/CvService.php');
$check(str_contains($pkg, 'Ownership::PRODUCT_LINE') && str_contains($pkg, 'Ownership::copyright()'), 'P4 the credential package PDF has the product line on the cover and the copyright line in the footer');
$check(str_contains($cv, 'Ownership::copyright()'), 'P4 the CV / credential summary PDF footer has the copyright line');
$own = $rd('app/src/Ownership.php');
$check(str_contains($own, "'license_text'") && str_contains($own, "'third_party_notices'") && strpos($own, "'license_text'") > strpos($own, "return ['view' => 'short'] + \$short;"), 'P4 the full About view (only) carries LICENSE.txt and the third-party notices');
echo $fail ? "OWNERSHIP 1.173.1: FAIL\n" : "OWNERSHIP 1.173.1: ALL PASS\n";
exit($fail ? 1 : 0);
