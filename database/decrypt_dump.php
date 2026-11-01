<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Decrypt a passphrase-sealed database backup (.sql.gz.flxenc) back to a normal .sql.gz. CLI only.
 *   php database/decrypt_dump.php <in.flxenc> <out.sql.gz>
 * Then restore with:  gunzip -c out.sql.gz | mysql <db>   (encrypted columns still need the ORIGINAL crypto keys — see restore_keys.php)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/src/DumpSeal.php';
[$in, $out] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($in === '' || $out === '' || !is_file($in)) { fwrite(STDERR, "Usage: php database/decrypt_dump.php <in.flxenc> <out.sql.gz>\n"); exit(2); }
fwrite(STDERR, 'Backup passphrase: ');
$posix = function_exists('posix_isatty') && @posix_isatty(STDIN);
if ($posix) @system('stty -echo');
$pass = rtrim((string) fgets(STDIN), "\r\n");
if ($posix) { @system('stty echo'); fwrite(STDERR, "\n"); }
$plain = FlxCoreHC\DumpSeal::open((string) file_get_contents($in), $pass);
if ($plain === null) { fwrite(STDERR, "Could not open the backup: wrong passphrase or the file is damaged.\n"); exit(1); }
file_put_contents($out, $plain);
echo "Decrypted to $out (" . strlen($plain) . " bytes)\n";
