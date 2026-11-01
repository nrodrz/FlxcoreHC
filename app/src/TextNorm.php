<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * v1.171.9 — the ONE rule for human-entered names (roles, providers, organizations).
 *
 * Four independent validation rounds found the same class of defect in different places: a name that *looks* blank
 * or *looks* like an existing one was accepted because each entry point had its own trim()/strtolower(). This class
 * replaces those with two operations every entry point must use:
 *
 *   clean($s) — what is STORED: invisible characters removed (format chars such as soft hyphen, zero-width joiners,
 *               BOM, word joiner; Hangul fillers; braille blank; combining grapheme joiner), every kind of space
 *               collapsed to one ASCII space, trimmed. A name that cleans to '' is blank.
 *   fold($s)  — what is COMPARED: clean() + Unicode NFKC (full-width "Ｍａｎａｇｅｒ" → "Manager", ligatures, etc.)
 *               + lower-case + dotless ı → i + common Cyrillic/Greek look-alikes mapped to Latin.
 *               Two names whose fold() is equal are the same name.
 */
final class TextNorm
{
    /** Characters that render as nothing (beyond the Unicode Cf class, which is removed wholesale). */
    private const INVISIBLE = '\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{2800}\x{3164}\x{FFA0}\x{00AD}';

    /** Lower-case Cyrillic / Greek letters that look like Latin ones. */
    private const CONFUSABLES = [
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ј' => 'j',
        'ѕ' => 's', 'һ' => 'h', 'ԛ' => 'q', 'ԝ' => 'w', 'ԁ' => 'd', 'ѵ' => 'v', 'ԍ' => 'g', 'ӏ' => 'l', 'ё' => 'e',
        'ո' => 'n', 'ս' => 'u', 'օ' => 'o',                       // Armenian
        'ο' => 'o', 'ν' => 'v', 'ι' => 'i', 'ρ' => 'p', 'κ' => 'k', 'τ' => 't', 'α' => 'a', 'ϲ' => 'c', 'υ' => 'u',   // Greek
        'ı' => 'i', 'ł' => 'l',
    ];

    public static function clean(string $s): string
    {
        if ($s === '') return '';
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = (string) preg_replace('/[\p{Z}\s]+/u', ' ', $s);                                   // every kind of space (incl. tab) → one space
        $s = (string) preg_replace('/[\p{Cf}\p{Cc}' . self::INVISIBLE . ']/u', '', $s);        // then remove what renders as nothing
        $s = (string) preg_replace('/ {2,}/u', ' ', $s);
        return trim($s);
    }

    public static function blank(string $s): bool
    {
        return self::clean($s) === '';
    }

    /**
     * v1.171.10: Unicode NFKC needs PHP ext-intl (\Normalizer). It is a PRODUCTION PREREQUISITE (checked by the
     * installer and at bootstrap) so that name comparison behaves identically on every host. Test hook: set
     * $assumeNormalizerMissing = true to exercise the "missing" path without unloading the extension.
     */
    public static bool $assumeNormalizerMissing = false;

    public static function normalizerAvailable(): bool
    {
        return !self::$assumeNormalizerMissing && class_exists(\Normalizer::class);
    }

    /** Human-readable prerequisite failure, or null when the host is fine. Used by install.php and bootstrap. */
    public static function prerequisiteError(): ?string
    {
        return self::normalizerAvailable() ? null
            : 'PHP extension intl (class Normalizer) is required: FlxCoreHC compares names with Unicode NFKC. Enable ext-intl in PHP (cPanel → Select PHP Version → intl).';
    }

    public static function fold(string $s): string
    {
        $s = self::clean($s);
        if ($s === '') return '';
        if (self::normalizerAvailable()) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_KC);
            if (is_string($n)) $s = $n;
        } elseif (function_exists('config') && config('app_env', 'production') === 'production') {
            // Never compare with a weaker algorithm in production (REQUIRED 1 of the v1.171.9 review).
            throw new \RuntimeException((string) self::prerequisiteError());
        } else {
            // dev/test only: full-width ASCII (U+FF01–U+FF5E) back to ASCII — NOT equivalent to NFKC.
            $s = (string) preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', static fn($m) => chr(mb_ord($m[0]) - 0xFF01 + 0x21), $s);
        }
        $s = mb_strtolower($s);
        $s = strtr($s, self::CONFUSABLES);
        return self::clean($s);
    }

    /** True when two names read as the same name. */
    public static function same(string $a, string $b): bool
    {
        return self::fold($a) === self::fold($b);
    }
}
