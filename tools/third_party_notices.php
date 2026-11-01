<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * Generates THIRD-PARTY-NOTICES.txt from tools/third_party.json (the lock list of shipped third-party components).
 *   php tools/third_party_notices.php            write the file
 *   php tools/third_party_notices.php --check    exit 1 if the file is out of date, or a shipped third-party file is not listed
 * Format: "name@version (license)" lines, a rule, then the full text of each license. LF line ends. Deterministic.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$lock = json_decode((string) file_get_contents("$root/tools/third_party.json"), true);
if (!is_array($lock) || empty($lock['components'])) { fwrite(STDERR, "tools/third_party.json is missing or empty\n"); exit(1); }
$comps = $lock['components'];
usort($comps, fn($a, $b) => strcmp($a['name'], $b['name']));

$rule = str_repeat('-', 78);
$out = "THIRD-PARTY NOTICES — FlxCoreHC\n\nFlxCoreHC includes the open-source components listed below. Each is licensed by its own authors under its own license,\n"
     . "reproduced in full after the list. These licenses apply to those components only.\n\n";
foreach ($comps as $c) $out .= $c['name'] . '@' . $c['version'] . ' (' . $c['license'] . ")\n";
$out .= "\n$rule\n";
$printed = [];
foreach ($comps as $c) {
    $txt = (string) file_get_contents("$root/" . $c['license_file']);
    $txt = str_replace(["\r\n", "\r"], "\n", $txt);
    $out .= "\n" . $c['name'] . '@' . $c['version'] . ' (' . $c['license'] . ")\n" . ($c['copyright'] ? $c['copyright'] . "\n" : '');
    if (isset($printed[$c['license_file']])) { $out .= "License text: see \"" . $printed[$c['license_file']] . "\" above.\n\n$rule\n"; continue; }
    $printed[$c['license_file']] = $c['name'];
    $out .= "\n" . rtrim($txt) . "\n\n$rule\n";
}
$target = "$root/THIRD-PARTY-NOTICES.txt";
if (in_array('--check', $argv, true)) {
    $ok = true;
    if (!is_file($target) || (string) file_get_contents($target) !== $out) { echo "FAIL — THIRD-PARTY-NOTICES.txt is missing or out of date (run: php tools/third_party_notices.php)\n"; $ok = false; }
    // every third-party file under assets/fonts and assets/vendor must be claimed by an entry
    $claimed = [];
    foreach ($comps as $c) foreach ($c['files'] as $g) foreach (glob("$root/$g") ?: [] as $f) $claimed[$f] = true;
    foreach (['assets/fonts', 'assets/vendor'] as $d) {
        foreach (glob("$root/$d/*") ?: [] as $f) {
            $b = basename($f);
            if (!is_file($f) || in_array($b, ['fonts.css', 'OFL.txt'], true) || isset($claimed[$f])) continue;
            echo "FAIL — $d/$b is not listed in tools/third_party.json\n"; $ok = false;
        }
    }
    foreach ($comps as $c) foreach ($c['files'] as $g) if (!glob("$root/$g")) { echo "FAIL — listed files not found: $g\n"; $ok = false; }
    if ($ok) echo "PASS — THIRD-PARTY-NOTICES.txt is current and lists every shipped third-party file (" . count($comps) . " components)\n";
    exit($ok ? 0 : 1);
}
file_put_contents($target, $out);
echo "wrote THIRD-PARTY-NOTICES.txt (" . count($comps) . " components)\n";
