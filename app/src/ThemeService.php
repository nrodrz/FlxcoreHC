<?php

declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Tenant theming.
 *
 * The stylesheet is built on CSS custom properties, so a tenant's palette is a short list of
 * token overrides rather than a second stylesheet. That keeps white-labelling cheap: no build
 * step, no duplicated CSS, and a tenant that never opens theme settings gets the stock design.
 *
 * Contrast is computed rather than guessed. If an agency picks a pale brand colour, the text
 * drawn on top of it flips to dark automatically, so a bad palette can't produce unreadable
 * buttons — a real risk when customers choose their own colours.
 */
final class ThemeService
{
    /** Corner-radius presets, keyed by the value stored in tenants.brand_radius. */
    private const RADII = [
        'sharp' => ['r' => '6px',  'sm' => '4px',  'lg' => '10px'],
        'soft'  => ['r' => '16px', 'sm' => '12px', 'lg' => '22px'],   // product default
        'round' => ['r' => '24px', 'sm' => '18px', 'lg' => '32px'],
    ];

    /** Ready-made palettes so an agency can get a decent result without colour theory. */
    public const PRESETS = [
        'flx'       => ['label' => 'FlxCoreHC blue',  'accent' => '#2f6fd6', 'nav' => '#102138'],
        'evergreen' => ['label' => 'Evergreen',     'accent' => '#1f9d63', 'nav' => '#0f2a1e'],
        'garnet'    => ['label' => 'Garnet',        'accent' => '#c2405a', 'nav' => '#2a1119'],
        'indigo'    => ['label' => 'Indigo',        'accent' => '#5b53d6', 'nav' => '#171636'],
        'copper'    => ['label' => 'Copper',        'accent' => '#c2703a', 'nav' => '#2c1a10'],
        'slate'     => ['label' => 'Graphite',      'accent' => '#4a5568', 'nav' => '#14181f'],
    ];

    /** Normalise user input to #rrggbb, or null when it isn't a usable colour. */
    public static function hex(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if ($v[0] !== '#') $v = '#' . $v;
        // Expand shorthand (#abc -> #aabbcc).
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $v, $m)) {
            $v = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : null;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }

    /** Relative luminance per WCAG 2.x. */
    public static function luminance(string $hex): float
    {
        $out = [];
        foreach (self::rgb($hex) as $c) {
            $c = $c / 255;
            $out[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
    }

    /** Contrast ratio between two colours (1.0 – 21.0). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a); $lb = self::luminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Pick black or white text for a background, whichever is more readable. */
    public static function inkFor(string $bg): string
    {
        return self::contrast($bg, '#ffffff') >= self::contrast($bg, '#111111') ? '#ffffff' : '#111111';
    }

    /**
     * Shift a colour's lightness while keeping its hue and saturation, so a brand colour still
     * looks like itself after adjustment. $delta is in lightness points (-100..100); mixing
     * toward white in RGB would wash the colour out to grey instead.
     */
    public static function shade(string $hex, float $delta): string
    {
        [$r, $g, $b] = array_map(fn($c) => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $s = $d == 0.0 ? 0.0 : $d / (1 - abs(2 * $l - 1));
        $h = 0.0;
        if ($d != 0.0) {
            if ($max === $r)      $h = 60 * fmod((($g - $b) / $d), 6);
            elseif ($max === $g)  $h = 60 * ((($b - $r) / $d) + 2);
            else                  $h = 60 * ((($r - $g) / $d) + 4);
        }
        if ($h < 0) $h += 360;

        $l = max(0.0, min(1.0, $l + $delta / 100));

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r2, $g2, $b2] = match (true) {
            $h < 60  => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default  => [$c, 0.0, $x],
        };
        return sprintf('#%02x%02x%02x',
            (int) round(($r2 + $m) * 255), (int) round(($g2 + $m) * 255), (int) round(($b2 + $m) * 255));
    }

    /** Rebuild a colour at an explicit lightness, optionally damping saturation. */
    public static function tint(string $hex, float $lightness, float $satScale = 1.0): string
    {
        [$h, $sat, ] = self::toHsl($hex);
        return self::fromHsl($h, max(0.0, min(1.0, $sat * $satScale)), max(0.0, min(1.0, $lightness)));
    }

    /** @return array{0:float,1:float,2:float} hue(0-360), saturation(0-1), lightness(0-1) */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn($c) => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $l = ($max + $min) / 2; $d = $max - $min;
        $s = $d == 0.0 ? 0.0 : $d / (1 - abs(2 * $l - 1));
        $h = 0.0;
        if ($d != 0.0) {
            if ($max === $r)     $h = 60 * fmod((($g - $b) / $d), 6);
            elseif ($max === $g) $h = 60 * ((($b - $r) / $d) + 2);
            else                 $h = 60 * ((($r - $g) / $d) + 4);
        }
        if ($h < 0) $h += 360;
        return [$h, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0.0], $h < 120 => [$x, $c, 0.0], $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c], $h < 300 => [$x, 0.0, $c], default => [$c, 0.0, $x],
        };
        return sprintf('#%02x%02x%02x',
            (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    /**
     * Build the CSS that overrides the design tokens for one tenant.
     * Returns '' when the tenant has customised nothing, so the stock theme is served as-is.
     */
    public static function css(array $tenant): string
    {
        $accent = self::hex($tenant['brand_accent'] ?? null);
        $nav    = self::hex($tenant['brand_nav'] ?? null);
        $radius = (string) ($tenant['brand_radius'] ?? '');
        $rules  = [];      // applies to both colour schemes
        $dark   = [];      // dark-mode-only corrections

        if ($accent !== null) {
            $rules[] = '--accent:' . $accent;
            $rules[] = '--accent-2:' . self::shade($accent, -8);
            // Contrast-checked so pale brand colours still produce readable buttons.
            $rules[] = '--accent-ink:' . self::inkFor($accent);
            $rules[] = '--accent-soft:color-mix(in srgb,' . $accent . ' 12%,#ffffff)';
            $rules[] = '--ring:0 0 0 3px color-mix(in srgb,' . $accent . ' 28%,transparent)';

            // Dark mode needs a lighter accent, but simply raising lightness washes a saturated
            // brand colour out to pastel. Set an explicit target lightness and push saturation
            // back up instead, so the colour stays vivid against a dark surface.
            $lift = self::tint($accent, 0.62, 1.15);
            if (self::contrast($lift, '#0f1a2b') < 4.5) $lift = self::tint($accent, 0.70, 1.15);
            $dark[] = '--accent:' . $lift;
            $dark[] = '--accent-2:' . self::tint($accent, 0.70, 1.15);
            $dark[] = '--accent-ink:' . self::inkFor($lift);
            $dark[] = '--accent-soft:' . self::tint($accent, 0.20, 0.55);
            $dark[] = '--ring:0 0 0 3px color-mix(in srgb,' . $lift . ' 34%,transparent)';
        }

        // Surfaces carry the brand hue in dark mode. Without this the palette only reached buttons
        // and the sidebar, leaving the page itself the stock navy no matter which preset was chosen.
        $tintBase = $nav ?? $accent;
        if ($tintBase !== null) {
            $dark[] = '--bg:'        . self::tint($tintBase, 0.055, 0.55);
            $dark[] = '--surface:'   . self::tint($tintBase, 0.105, 0.42);
            $dark[] = '--surface-2:' . self::tint($tintBase, 0.145, 0.40);
            $dark[] = '--surface-3:' . self::tint($tintBase, 0.195, 0.38);
            $dark[] = '--panel:var(--surface)';
            $dark[] = '--panel-2:var(--surface-2)';
            $dark[] = '--line:'      . self::tint($tintBase, 0.245, 0.34);
            $dark[] = '--line-2:'    . self::tint($tintBase, 0.185, 0.32);
            // A matching whisper of the hue in light mode keeps the two schemes related.
            $rules[] = '--bg:'        . self::tint($tintBase, 0.925, 0.30);
            $rules[] = '--surface-2:' . self::tint($tintBase, 0.965, 0.26);
            $rules[] = '--surface-3:' . self::tint($tintBase, 0.925, 0.24);
            $rules[] = '--line:'      . self::tint($tintBase, 0.865, 0.22);
            $rules[] = '--line-2:'    . self::tint($tintBase, 0.925, 0.20);
        }

        if ($nav !== null) {
            $rules[] = '--nav:' . $nav;
            $rules[] = '--nav-grad-a:' . self::shade($nav, 6);
            $rules[] = '--nav-grad-b:' . self::shade($nav, -4);
            $rules[] = '--nav-2:' . self::shade($nav, 11);
            $rules[] = '--nav-3:' . self::shade($nav, 18);
            // Nav text follows the nav colour's brightness so a light nav stays readable.
            $navInk = self::inkFor($nav);
            $rules[] = '--nav-ink:' . ($navInk === '#ffffff' ? 'rgba(255,255,255,.78)' : 'rgba(0,0,0,.72)');
            $rules[] = '--nav-ink-2:' . ($navInk === '#ffffff' ? 'rgba(255,255,255,.52)' : 'rgba(0,0,0,.5)');
        }

        if (isset(self::RADII[$radius])) {
            $r = self::RADII[$radius];
            $rules[] = '--r:' . $r['r'];
            $rules[] = '--r-sm:' . $r['sm'];
            $rules[] = '--r-lg:' . $r['lg'];
        }

        if ($rules === [] && $dark === []) return '';
        $css = '';
        if ($rules !== []) $css .= ':root{' . implode(';', $rules) . '}';
        if ($dark !== [])  $css .= '[data-theme="dark"]{' . implode(';', $dark) . '}';
        return $css;
    }

    /** The tenant's default colour scheme: light | dark | auto. */
    public static function defaultMode(array $tenant): string
    {
        $m = (string) ($tenant['brand_theme'] ?? '');
        return in_array($m, ['light', 'dark', 'auto'], true) ? $m : 'light';
    }

    /** Human-readable contrast verdict, shown in the settings preview. */
    public static function verdict(string $accent): array
    {
        $ratio = self::contrast($accent, self::inkFor($accent));
        return [
            'ratio' => round($ratio, 1),
            'pass'  => $ratio >= 4.5,
            'note'  => $ratio >= 4.5
                ? 'Readable — meets WCAG AA for button text.'
                : 'Low contrast. Button text may be hard to read; try a deeper shade.',
        ];
    }
}
