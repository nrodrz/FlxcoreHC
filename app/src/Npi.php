<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * NPI format rules (CMS): 10 digits, first digit 1 or 2, and a Luhn check digit computed over the
 * number prefixed with the ISO issuer code 80840. Single source of truth for every input path.
 * (Type-1 vs Type-2 — individual vs organization — is a property of the NPPES record, not of the
 * number; it is checked at "Verify NPI (NPPES)" time, not here.)
 */
final class Npi
{
    public static function normalize(?string $v): string
    {
        return preg_replace('/[\s\-]+/', '', trim((string) $v)) ?? '';
    }

    private static function luhnOk(string $digits): bool
    {
        $sum = 0; $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($alt) { $d *= 2; if ($d > 9) $d -= 9; }
            $sum += $d; $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    public static function isValid(string $npi): bool
    {
        return (bool) preg_match('/^[12]\d{9}$/', $npi) && self::luhnOk('80840' . $npi);
    }

    /** Check digit for a 9-digit base. */
    public static function checkDigit(string $base9): string
    {
        for ($c = 0; $c <= 9; $c++) {
            if (self::luhnOk('80840' . $base9 . $c)) return (string) $c;
        }
        return '0'; // unreachable
    }

    /** Replace the last digit of a 10-digit number with the correct check digit (demo/seed data only). */
    public static function fix(string $npi10): string
    {
        $base = substr($npi10, 0, 9);
        return $base . self::checkDigit($base);
    }

    /** null = OK (or empty); otherwise a user-facing message. */
    public static function error(?string $raw, string $label = 'NPI'): ?string
    {
        $n = self::normalize($raw);
        if ($n === '') return null;
        if (!preg_match('/^\d{10}$/', $n)) return "$label must be exactly 10 digits.";
        if (!self::isValid($n)) return "$label is not a valid NPI (check digit does not match). Re-check the number.";
        return null;
    }
}
