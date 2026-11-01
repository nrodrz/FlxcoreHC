<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * CLI: encrypt legacy plaintext address fields (line1, line2, phone) into the *_enc columns and clear the
 * plaintext — each column INDEPENDENTLY (v1.171.4): a column is only written when it has plaintext and no
 * ciphertext yet; an existing ciphertext is never overwritten. Idempotent. Take a backup first. Requires crypto keys.
 *   php database/encrypt_existing_addresses.php [--dry-run]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use FlxCoreHC\Crypto; use FlxCoreHC\Database;
$dry = in_array('--dry-run', $argv, true);
if (!Crypto::isConfigured()) { fwrite(STDERR, "Crypto keys not configured; aborting.\n"); exit(1); }
$pdo = Database::pdo(); $rowsTouched = 0; $cols = 0;
$rows = $pdo->query("SELECT id, tenant_id, line1, line2, phone, line1_enc, line2_enc, phone_enc FROM addresses
                      WHERE (line1 IS NOT NULL AND line1_enc IS NULL) OR (line2 IS NOT NULL AND line2_enc IS NULL) OR (phone IS NOT NULL AND phone_enc IS NULL)")->fetchAll();
foreach ($rows as $r) {
    $set = []; $params = [];
    foreach (['line1', 'line2', 'phone'] as $c) {
        if ($r[$c] === null || $r[$c . '_enc'] !== null) continue;   // nothing to migrate, or ciphertext already present: leave untouched
        $set[] = "`{$c}_enc` = ?, `{$c}` = NULL";
        $params[] = Crypto::encrypt((string) $r[$c], $r['tenant_id'] . '|addresses|' . $c . '_enc');
        $cols++;
    }
    if ($set === []) continue;
    $rowsTouched++;
    if ($dry) continue;
    // Guarded write: each column's ciphertext must still be absent at write time (safe against a concurrent edit).
    $guard = [];
    foreach (['line1', 'line2', 'phone'] as $c) if ($r[$c] !== null && $r[$c . '_enc'] === null) $guard[] = "`{$c}_enc` IS NULL";
    $pdo->prepare('UPDATE addresses SET ' . implode(', ', $set) . ' WHERE id = ? AND tenant_id = ? AND ' . implode(' AND ', $guard))
        ->execute([...$params, $r['id'], $r['tenant_id']]);
}
echo ($dry ? "[dry-run] would encrypt" : "encrypted") . ": $cols column value(s) in $rowsTouched address row(s)\n";
