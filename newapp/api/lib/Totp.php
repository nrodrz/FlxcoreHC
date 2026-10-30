<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — TOTP (RFC 6238) helpers. No external dependencies.
 * Secrets are base32 (RFC 4648). Store them vault-encrypted at rest.
 */

function totp_base32_encode(string $bytes): string {
    if ($bytes === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) $bits .= str_pad(decbin(strpos($alphabet, $b32[$i])), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_secret(): string {
    return totp_base32_encode(random_bytes(20)); // 160-bit, 32 base32 chars
}

function totp_code(string $secret, ?int $ts = null, int $step = 30, int $digits = 6): string {
    $ts = $ts ?? time();
    $counter = (int) floor($ts / $step);
    // 8-byte big-endian counter
    $bin = pack('N', 0) . pack('N', $counter);
    $key = totp_base32_decode($secret);
    if ($key === '') return str_repeat('0', $digits);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $off = ord($hash[strlen($hash) - 1]) & 0x0f;
    $val = ((ord($hash[$off]) & 0x7f) << 24)
         | ((ord($hash[$off + 1]) & 0xff) << 16)
         | ((ord($hash[$off + 2]) & 0xff) << 8)
         | (ord($hash[$off + 3]) & 0xff);
    $code = $val % (10 ** $digits);
    return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
}

/** BT90: the time step (counter) the code belongs to, or null — so a code can be accepted once (itops_totp_once). */
function totp_match_step(string $secret, string $code, int $window = 1): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $ts = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $ts + $i * 30), $code)) return (int)floor(($ts + $i * 30) / 30);
    }
    return null;
}


function totp_uri(string $secret, string $label, string $issuer = 'ITOps'): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}
