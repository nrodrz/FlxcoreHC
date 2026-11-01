<?php
/**
 * Recover the encryption keys from a FlxCoreHC key-backup file (.flxkeys) created by the installer or by
 * Console → System → "Download key backup".   CLI only.
 *
 *   php database/restore_keys.php /path/to/flxcorehc-keys.flxkeys
 *
 * You are asked for the backup passphrase (typed, not echoed where the terminal allows). The two config lines are
 * printed to the screen — paste them into app/config.php. Nothing is written to disk.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/src/KeyBackup.php';
$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) { fwrite(STDERR, "Usage: php database/restore_keys.php <backup-file>\n"); exit(2); }
fwrite(STDERR, 'Backup passphrase: ');
$posix = function_exists('posix_isatty') && @posix_isatty(STDIN);
if ($posix) @system('stty -echo');
$pass = rtrim((string) fgets(STDIN), "\r\n");
if ($posix) { @system('stty echo'); fwrite(STDERR, "\n"); }
$k = FlxCoreHC\KeyBackup::decrypt((string) file_get_contents($file), $pass);
if ($k === null) { fwrite(STDERR, "Could not open the backup: wrong passphrase or the file is damaged.\n"); exit(1); }
echo "Add these to app/config.php:\n\n";
echo "    'crypto_data_key'  => '{$k['crypto_data_key']}',\n";
echo "    'crypto_index_key' => '{$k['crypto_index_key']}',\n\n";
echo 'Key fingerprint: ' . FlxCoreHC\KeyBackup::fingerprint($k['crypto_data_key'], $k['crypto_index_key']) . "\n";
