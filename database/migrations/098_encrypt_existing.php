<?php
declare(strict_types=1);
/**
 * One-time data migration: encrypt existing PLAINTEXT PHI/PII into the *_enc
 * columns and populate blind indexes. Safe to re-run (skips already-encrypted
 * rows). Run AFTER 003 (which adds the columns) and BEFORE 099 (which drops the
 * plaintext columns).
 *
 *   php database/migrations/098_encrypt_existing.php
 *
 * Requires crypto keys configured in app/config.php. Refuses to run in
 * passthrough mode so you never "encrypt" into a no-op.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use FlxCoreHC\Database;
use FlxCoreHC\Crypto;

if (!Crypto::isConfigured()) {
    fwrite(STDERR, "ABORT: crypto keys not configured. Set crypto_data_key / crypto_index_key in app/config.php first.\n");
    exit(1);
}

$pdo = Database::pdo();
$total = 0;

// --- credential_holders: npi, npn, email ---
$rows = $pdo->query(
    "SELECT id, tenant_id, npi, npn, email FROM credential_holders
     WHERE (npi IS NOT NULL AND npi_enc IS NULL)
        OR (npn IS NOT NULL AND npn_enc IS NULL)
        OR (email IS NOT NULL AND email_enc IS NULL)"
)->fetchAll();
$upd = $pdo->prepare(
    "UPDATE credential_holders
     SET npi_enc = ?, npi_bidx = ?, npn_enc = ?, npn_bidx = ?, email_enc = ?,
         npi = NULL, npn = NULL, email = NULL
     WHERE id = ?"
);
foreach ($rows as $r) {
    $upd->execute([
        Crypto::encrypt($r['npi'], $r['tenant_id'] . '|credential_holders|npi_enc'),   Crypto::blindIndex($r['npi']),
        Crypto::encrypt($r['npn'], $r['tenant_id'] . '|credential_holders|npn_enc'),   Crypto::blindIndex($r['npn']),
        Crypto::encrypt($r['email'], $r['tenant_id'] . '|credential_holders|email_enc'),
        $r['id'],
    ]);
    $total++;
}
echo "credential_holders encrypted: " . count($rows) . "\n";

// --- credentials: credential_number ---
$rows = $pdo->query(
    "SELECT id, tenant_id, credential_number FROM credentials
     WHERE credential_number IS NOT NULL AND credential_number_enc IS NULL"
)->fetchAll();
$upd = $pdo->prepare(
    "UPDATE credentials SET credential_number_enc = ?, credential_number = NULL WHERE id = ?"
);
foreach ($rows as $r) {
    $upd->execute([Crypto::encrypt($r['credential_number'], $r['tenant_id'] . '|credentials|credential_number_enc'), $r['id']]);
    $total++;
}
echo "credentials encrypted: " . count($rows) . "\n";
echo "TOTAL rows migrated: {$total}\n";
echo "Next: verify the app reads values correctly, then run 099_drop_plaintext.sql\n";
