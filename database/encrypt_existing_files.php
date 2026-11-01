<?php
declare(strict_types=1);
/**
 * CLI: encrypt legacy plaintext documents in docs_path in place.
 *   php database/encrypt_existing_files.php [--dry-run]
 * Idempotent (already-encrypted files are skipped). Take a backup first. Requires crypto keys.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$dry = in_array('--dry-run', $argv, true);
if (!FlxCoreHC\Crypto::isConfigured()) { fwrite(STDERR, "Crypto keys not configured; aborting.\n"); exit(1); }
$base = rtrim((string) config('docs_path'), '/');
if ($base === '' || !is_dir($base)) { fwrite(STDERR, "docs_path missing.\n"); exit(1); }
$conv = $skip = $fail = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (!$f->isFile() || str_ends_with($f->getFilename(), '.tmp') || str_starts_with($f->getFilename(), '.')) continue;   // v1.171.5: .keep/.htaccess placeholders are not documents
    $p = $f->getPathname();
    if (FlxCoreHC\EncryptedFile::isEncrypted($p)) { $skip++; continue; }
    if ($dry) { $conv++; continue; }
    if (FlxCoreHC\EncryptedFile::encryptInPlace($p)) $conv++; else { $fail++; fwrite(STDERR, "FAILED: $p\n"); }
}
echo ($dry ? "[dry-run] would convert" : "converted") . ": $conv, already encrypted: $skip, failed: $fail\n";
exit($fail ? 2 : 0);
