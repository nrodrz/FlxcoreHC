<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
// Run once at setup to generate encryption keys for app/config.php:
//   php database/generate_keys.php
// Paste the output into config.php. Store config.php OUTSIDE the web root.
require dirname(__DIR__) . '/app/src/Crypto.php';
$k = FlxCoreHC\Crypto::generateKeys();
echo "Add these to app/config.php (keep them secret, back them up securely):\n\n";
echo "    'crypto_data_key'  => '{$k['crypto_data_key']}',\n";
echo "    'crypto_index_key' => '{$k['crypto_index_key']}',\n\n";
echo "WARNING: if you lose crypto_data_key, encrypted PHI is unrecoverable.\n";
echo "Changing crypto_index_key breaks blind-index lookups until reindexed.\n";
