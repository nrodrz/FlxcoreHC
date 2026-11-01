<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Auth;

/**
 * Dependency-free TOTP (RFC 6238 / RFC 4226) — works with Google Authenticator,
 * Authy, 1Password, Microsoft Authenticator. 30-second steps, 6 digits, SHA1
 * (what every authenticator app implements).
 *
 * No Composer on the target host, so base32 and HMAC-OTP are implemented here.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD   = 30;
    private const DIGITS   = 6;

    /** A fresh 160-bit secret, base32-encoded (the format apps expect). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * otpauth:// URI. SHA1 / 6 digits / 30s are the authenticator defaults, so they are
     * omitted — this keeps the URI short (the profile page shows a server-built QR code — see QrCode — plus the setup key and a tap-to-add link).
     * Long org names are trimmed for the label only; the secret is never touched.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $issuer  = mb_substr(trim($issuer) ?: FLXCOREHC_APP_NAME, 0, 20);
        $account = mb_substr(trim($account) ?: 'user', 0, 32);
        // Label is the account alone and the issuer travels as a parameter: shorter than the
        // "Issuer:Account" form, still spec-valid, and read correctly by every major app.
        return 'otpauth://totp/' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer);
    }

    /**
     * Verify a submitted code. $window allows ±N steps of clock drift between the
     * phone and the server (1 = ±30s, the usual tolerance).
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        return self::matchCounter($secret, $code, $window) !== null;
    }

    /** The time-step counter a submitted code matches, or null. Callers persist it to reject replays (S-6). */
    public static function matchCounter(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) return null;
        $counter = (int) floor(time() / self::PERIOD);
        $hit = null;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $counter + $i), $code)) $hit = $counter + $i;   // no early exit: constant work
        }
        return $hit;
    }

    /** The code for a given counter — HMAC-SHA1 + dynamic truncation (RFC 4226). */
    public static function codeAt(string $secret, int $counter): string
    {
        $key  = self::base32Decode($secret);
        $bin  = pack('N2', 0, $counter);           // 64-bit big-endian counter
        $hash = hash_hmac('sha1', $bin, $key, true);
        $off  = ord($hash[19]) & 0x0F;
        $part = ((ord($hash[$off]) & 0x7F) << 24)
              | ((ord($hash[$off + 1]) & 0xFF) << 16)
              | ((ord($hash[$off + 2]) & 0xFF) << 8)
              | (ord($hash[$off + 3]) & 0xFF);
        return str_pad((string) ($part % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Ten single-use recovery codes, shown once. Store only the hashes. */
    public static function backupCodes(int $n = 10): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        }
        return $out;
    }

    public static function hashCodes(array $codes): string
    {
        return json_encode(array_map(
            fn(string $c) => password_hash(strtoupper(trim($c)), PASSWORD_DEFAULT),
            $codes
        )) ?: '[]';
    }

    /**
     * Consume a backup code. Returns the remaining-hashes JSON on success, or null
     * if the code doesn't match — each code works exactly once.
     */
    public static function consumeBackupCode(string $json, string $submitted): ?string
    {
        $hashes = json_decode($json ?: '[]', true);
        if (!is_array($hashes)) return null;
        $submitted = strtoupper(trim($submitted));
        foreach ($hashes as $i => $h) {
            if (is_string($h) && password_verify($submitted, $h)) {
                unset($hashes[$i]);
                return json_encode(array_values($hashes)) ?: '[]';
            }
        }
        return null;
    }

    // ---- base32 (RFC 4648) --------------------------------------------------
    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32  = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $ch) {
            $pos = strpos(self::ALPHABET, $ch);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr((int) bindec($byte));
        }
        return $out;
    }
}
