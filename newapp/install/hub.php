<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the installer of the MAIN installation, the License Hub (BT128). There is only one, run by Indisse-BTS: it
 * issues the licenses and update bundles of every other installation. Organizations use install/index.php.
 * Same steps as the regular installer, plus the License Hub setup: its public address, both keys (license and release)
 * with passphrase-encrypted backups to download, its own license, and the License Manager switched on in config.php.
 */
define('ITOPS_INSTALL_HUB', true);
require __DIR__ . '/index.php';
