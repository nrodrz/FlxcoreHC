<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — secrets at rest (BT14).
 *
 * One mechanism for every stored integration secret: the existing vault (AES-256-GCM with
 * VAULT_ENCRYPTION_KEY from config.php). Values stored "in place" (inside a settings value, a JSON
 * blob or a table column) carry the prefix "enc:v1:" so readers can tell ciphertext from legacy
 * plaintext during the upgrade window: unwrap() returns legacy plaintext unchanged, so nothing
 * breaks before db-update.php has run.
 *
 * Covered: guardian_whm_token, guardian_imap_pass (settings) · vTiger access_key / bridge_secret /
 * bridge_secret_pending / db pass (JSON blobs) · webhook signing secrets (itops_webhooks.secret).
 * The Microsoft 365 app secret uses the existing encrypted key o365_client_secret_enc (see Graph.php).
 * Already encrypted before BT14: mail/SMTP passwords, AI keys, Credentials vault, TOTP secrets.
 */
if (!defined('ITOPS_SECRET_PREFIX')) define('ITOPS_SECRET_PREFIX', 'enc:v1:');
if (!function_exists('itops_secret_wrap')) {

    /** Settings keys whose value is stored wrapped. */
    function itops_secret_setting_keys(): array { return ['guardian_whm_token', 'guardian_imap_pass']; }

    function itops_secret_is_wrapped($v): bool { return is_string($v) && strncmp($v, ITOPS_SECRET_PREFIX, strlen(ITOPS_SECRET_PREFIX)) === 0; }

    function itops_secret_wrap(string $plain): string {
        if ($plain === '' || itops_secret_is_wrapped($plain)) return $plain;
        return ITOPS_SECRET_PREFIX . vault_encrypt($plain);
    }

    /** Ciphertext → plaintext; legacy plaintext is returned as is. Throws only if a wrapped value can't be decrypted. */
    function itops_secret_unwrap($v): string {
        $v = (string)$v;
        if (!itops_secret_is_wrapped($v)) return $v;
        return vault_decrypt(substr($v, strlen(ITOPS_SECRET_PREFIX)));
    }

    /** Wrap and prove the round trip before trusting it (used by the migration). */
    function itops_secret_wrap_checked(string $plain): ?string {
        if ($plain === '' || itops_secret_is_wrapped($plain)) return null;
        try { $w = itops_secret_wrap($plain); return itops_secret_unwrap($w) === $plain ? $w : null; } catch (\Throwable $e) { return null; }
    }

    /** Is the vault key usable at all? (Missing/invalid VAULT_ENCRYPTION_KEY → never touch secrets.) */
    function itops_vault_ok(): bool {
        if (!defined('VAULT_ENCRYPTION_KEY') || !function_exists('vault_encrypt')) return false;
        $k = @hex2bin((string)VAULT_ENCRYPTION_KEY);
        if ($k === false || strlen($k) !== 32) return false;
        try { return vault_decrypt(vault_encrypt('itops-check')) === 'itops-check'; } catch (\Throwable $e) { return false; }
    }

    /** vTiger JSON blobs: which fields are secrets. */
    function itops_secret_vtiger_fields(): array { return ['prov' => ['access_key', 'bridge_secret', 'bridge_secret_pending'], 'db' => ['pass']]; }

}
