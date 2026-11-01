<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Lightweight interface translation for the Puerto Rico market (Spanish-first), extensible to any
 * language.
 *
 * Catalogs live in app/lang/{locale}.php — each returns a plain array keyed by the exact English
 * source string (e.g. 'Providers' => 'Proveedores'). Views call t('Providers'); the active locale's
 * catalog is consulted and the English source is returned unchanged when there's no entry, so nothing
 * ever renders blank and untranslated strings simply stay in English while a catalog grows. English is
 * the source language, so en.php is normally empty.
 *
 * ADDING A LANGUAGE (e.g. Brazilian Portuguese):
 *   1. Add it to LOCALES below:            'pt' => 'Português'
 *   2. Create app/lang/pt.php returning:   return ['Providers' => 'Fornecedores', ...];
 * That's it — the switcher and lookup pick it up automatically.
 *
 * Locale resolution order: explicit session choice → the signed-in user's saved preference → English.
 */
final class I18n
{
    /** Registry of offered languages (code => display name). Add a line here to offer a new language. */
    public const LOCALES = ['en' => 'English', 'es' => 'Español'];

    private static ?string $active = null;
    /** @var array<string,array<string,string>> loaded catalogs, per locale */
    private static array $catalogs = [];

    /** The active locale for this request ('en', 'es', …). */
    public static function locale(): string
    {
        if (self::$active !== null) return self::$active;
        $loc = 'en';
        if (!empty($_SESSION['locale']) && isset(self::LOCALES[$_SESSION['locale']])) {
            $loc = (string) $_SESSION['locale'];
        } elseif (!empty($_SESSION['auth']['locale']) && isset(self::LOCALES[$_SESSION['auth']['locale']])) {
            $loc = (string) $_SESSION['auth']['locale'];
        }
        return self::$active = $loc;
    }

    /** Set the active locale for this request (e.g. after a user switches). */
    public static function setLocale(string $loc): void
    {
        if (isset(self::LOCALES[$loc])) {
            $_SESSION['locale'] = $loc;
            self::$active = $loc;
        }
    }

    /**
     * Translate an English source string to the active locale, falling back to the source string.
     *
     * Optional interpolation with named placeholders, so translations can reorder words freely:
     *   t('Welcome, {name}', ['name' => $who])
     *   t('{n} providers screened', ['n' => $count])
     *
     * @param array<string,string|int> $vars
     */
    /**
     * Translate for a specific locale, independent of the current request locale. Used to compose
     * emails in the recipient's own language (invites, reminders, digests). Honours the DB overrides
     * just like t().
     */
    public static function tl(string $locale, string $en, array $vars = []): string
    {
        $out = ($locale === 'en' ? self::catalog('en') : self::withDb($locale))[$en] ?? $en;
        if ($vars) {
            $out = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
                return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
            }, $out);
        }
        return $out;
    }

    public static function t(string $en, array $vars = []): string
    {
        $loc = self::locale();
        $out = ($loc === 'en' ? self::catalog('en') : self::withDb($loc))[$en] ?? $en;
        if ($vars) {
            $out = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
                return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
            }, $out);
        }
        return $out;
    }

    /** Load (and cache) a locale's catalog: app/lang/{locale}.php overlaid by editable DB rows. */
    private static function catalog(string $locale): array
    {
        if ($locale === 'en') return self::$catalogs['en'] ??= self::load('en');
        return self::$catalogs[$locale] ??= (self::load($locale) + []) ;
    }

    /** Merge editable DB translations over the file catalog (DB wins). Cached per request. */
    private static array $dbLoaded = [];
    private static function withDb(string $locale): array
    {
        if (isset(self::$dbLoaded[$locale])) return self::$catalogs[$locale];
        self::$dbLoaded[$locale] = true;
        $base = self::catalog($locale);
        try {
            $st = Database::pdo()->prepare('SELECT source, target FROM ui_translations WHERE locale = ? AND target IS NOT NULL AND target <> \'\'');
            $st->execute([$locale]);
            foreach ($st->fetchAll() as $r) { $base[(string)$r['source']] = (string)$r['target']; }
        } catch (\Throwable $e) { /* table may not exist yet during install */ }
        return self::$catalogs[$locale] = $base;
    }

    /** Strings rendered in a non-English locale that had no translation (collected per request). */
    private static array $missing = [];

    /**
     * Translate a rendered HTML page. Replaces a text node, or a placeholder/title/aria-label
     * attribute, ONLY when its whole trimmed text exactly matches a dictionary entry — the same rule
     * t() uses, applied to every view at once. <script>/<style> blocks are left untouched. English
     * text with no translation is recorded so the console editor can surface it.
     */
    public static function translateHtml(string $html): string
    {
        $locale = self::locale();
        if ($locale === 'en') return $html;
        $dict = self::withDb($locale);
        $known = array_flip(array_map('strval', $dict));   // translated VALUES — never re-collect those
        $tr = function (string $text) use ($dict, $known): string {
            $core = trim($text);
            if ($core === '' || !preg_match('/[A-Za-z]{2,}/', $core) || mb_strlen($core) > 1200) return $text;
            $key = html_entity_decode($core, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($dict[$key])) {
                return str_replace($core, htmlspecialchars($dict[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $text);
            }
            // v1.172.9: a paragraph that is wrapped across source lines has newlines/indentation inside it; the dictionary
            // keys are single-spaced, so try the whitespace-collapsed form too.
            $norm = trim((string) preg_replace('/\s+/u', ' ', $key));
            if ($norm !== $key && isset($dict[$norm])) {
                return str_replace($core, htmlspecialchars($dict[$norm], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $text);
            }
            // v1.172.9: long paragraphs (catalogue descriptions, hints) are translated when they match a dictionary entry
            // exactly, but they are NEVER recorded as "missing": a long English paragraph is as likely to be a user's
            // free-text note as interface text, and the missing-strings log must not collect those.
            if (mb_strlen($core) > 200) return $text;
            // Only record plausible UI strings: no digits-only, not a URL/code-ish token, not a name-like single token.
            if (!preg_match('/^[\w\s\'’.,:;!?()\/&—–\-%+]+$/u', $key) || preg_match('/^\d[\d\s.,:%\/-]*$/', $key)) return $text;
            if (str_contains($key, '@') || str_contains($key, 'http') || preg_match('/^[a-z_]+$/', $key)) return $text;
            if (isset($known[$key])) return $text;   // already a translation, not an English source
            // Skip data-looking strings: Title Case sequences of 2+ words (names of people, orgs,
            // products) are almost always tenant data, not UI text. Genuine Title-Case UI labels are
            // rare and can still be added by hand in the editor.
            $words = preg_split('/\s+/', $key);
            if (count($words) >= 2 && count($words) <= 6) {
                $allCap = true;
                foreach ($words as $w) { if (!preg_match('/^[A-Z][a-z\'’.]*$|^(&|of|the|and|de|del|la|y|LLC|Inc\.?|PC|MD|DO|PA|NP|RN)$/u', $w)) { $allCap = false; break; } }
                if ($allCap) return $text;
            }
            self::$missing[$key] = true;
            return $text;
        };
        // Split out script/style so their contents are never touched. v1.172.17: also anything between
        // <!--flx-notranslate--> and <!--/flx-notranslate--> (content that is already written per language, e.g. the Help Center
        // topics) — so such a page can have its frame (menu, buttons, footer) translated without its body being touched or collected as "missing".
        $parts = preg_split('#(<!--flx-notranslate-->.*?<!--/flx-notranslate-->|<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1) continue;
            // text nodes
            $p = preg_replace_callback('/>([^<>]+)</', fn($m) => '>' . $tr($m[1]) . '<', $p);
            // common attributes
            $p = preg_replace_callback('/\b(placeholder|title|aria-label)="([^"]{2,200})"/', fn($m) => $m[1] . '="' . $tr($m[2]) . '"', $p);
            // <option>/<button> text and input submit values are covered by the text-node pass;
            // input[type=submit] value attributes:
            $p = preg_replace_callback('/(<input\b[^>]*\btype="submit"[^>]*\bvalue=")([^"]{2,200})(")/', fn($m) => $m[1] . $tr($m[2]) . $m[3], $p);
            $parts[$i] = $p;
        }
        return implode('', $parts);
    }

    /** Screen/section a string was seen on, from the request path. */
    private static function currentContext(): string
    {
        $p = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        if ($p === '') return 'dashboard';
        $seg = explode('/', $p);
        $first = $seg[0];
        // admin sub-screens grouped under their section, everything else by its first path segment
        $map = [
            '' => 'dashboard', 'holders' => 'providers', 'credentials' => 'credentials', 'calendar' => 'calendar',
            'team' => 'team', 'organization' => 'settings', 'settings' => 'settings', 'reports' => 'reports',
            'regulators' => 'regulators', 'templates' => 'templates', 'groups' => 'groups', 'locations' => 'locations',
            'notifications' => 'notifications', 'documents' => 'documents', 'me' => 'portal', 'billing' => 'billing',
            'admin' => 'console' . (isset($seg[1]) ? ':' . preg_replace('/[^a-z]/', '', $seg[1]) : ''),
        ];
        return $map[$first] ?? preg_replace('/[^a-z0-9_]/', '', $first) ?: 'general';
    }

    /** Persist strings seen without a translation (bounded per request). Call after output. */
    public static function flushMissing(): void
    {
        $locale = self::locale();
        if ($locale === 'en' || self::$missing === []) return;
        $ctx = self::currentContext();
        try {
            $pdo = Database::pdo();
            if (self::hasContextColumn()) {
                $ins = $pdo->prepare(
                    'INSERT INTO ui_translations (id, locale, source_hash, source, target, context, seen_count, created_at)
                     VALUES (?,?,?,?,NULL,?,1,?) ON DUPLICATE KEY UPDATE seen_count = seen_count + 1,
                         context = COALESCE(context, VALUES(context))');
                $bind = fn($src) => $ins->execute([uuid4(), $locale, sha1($src), $src, $ctx, utc_now()]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO ui_translations (id, locale, source_hash, source, target, seen_count, created_at)
                     VALUES (?,?,?,?,NULL,1,?) ON DUPLICATE KEY UPDATE seen_count = seen_count + 1');
                $bind = fn($src) => $ins->execute([uuid4(), $locale, sha1($src), $src, utc_now()]);
            }
            $n = 0;
            foreach (array_keys(self::$missing) as $src) {
                if (++$n > 150) break;
                $bind($src);
            }
        } catch (\Throwable $e) { /* never break a page over telemetry */ }
        self::$missing = [];
    }

    /** All rows for the editor, grouped by screen/section. @return array<string,array> */
    public static function editorGroups(string $locale, string $q = '', bool $onlyMissing = false): array
    {
        $hasContext = self::hasContextColumn();
        $cols = $hasContext ? 'id, source, target, context, seen_count' : 'id, source, target, seen_count';
        $sql = "SELECT {$cols} FROM ui_translations WHERE locale = ?";
        $p = [$locale];
        if ($q !== '') { $sql .= ' AND (source LIKE ? OR target LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if ($onlyMissing) $sql .= ' AND (target IS NULL OR target = \'\')';
        $sql .= ' ORDER BY (target IS NULL OR target = \'\') DESC, seen_count DESC, source ASC LIMIT 2000';
        $st = Database::pdo()->prepare($sql); $st->execute($p);
        $groups = [];
        foreach ($st->fetchAll() as $r) {
            // Prefer the recorded screen context; fall back to classifying the string itself, so rows
            // collected before the context column existed still group usefully instead of piling into
            // one giant "general" list.
            $g = ($hasContext ? trim((string)($r['context'] ?? '')) : '');
            if ($g === '') $g = self::classify((string)$r['source']);
            $groups[$g][] = $r;
        }
        uksort($groups, fn($a, $b) => [$a === 'Other', $a] <=> [$b === 'Other', $b]);
        return $groups;
    }

    /** Bucket a UI string by what it is about, for the translation editor. */
    public static function classify(string $src): string
    {
        $s = mb_strtolower($src);
        $has = fn(array $words) => (bool) array_filter($words, fn($w) => str_contains($s, $w));
        return match (true) {
            $has(['provider', 'holder', 'npi', 'specialty', 'practitioner', 'physician', 'nurse'])            => 'Providers',
            $has(['credential', 'license', 'expir', 'renew', 'certif', 'dea', 'malpractice'])                  => 'Credentials',
            $has(['document', 'upload', 'scan', 'photo', 'file', 'pdf', 'verif'])                              => 'Documents & verification',
            $has(['payer', 'medicaid', 'medicare', 'enroll', 'sicro', 'ocs', 'credentialing', 'pecos'])        => 'Payers & enrollment',
            $has(['calendar', 'event', 'deadline', 'due', 'reminder', 'task', 'overdue'])                      => 'Calendar & reminders',
            $has(['share', 'link', 'consent', 'organization', 'recipient', 'external'])                        => 'Sharing',
            $has(['report', 'export', 'csv', 'compliance', 'score', 'readiness', 'level'])                     => 'Reports & compliance',
            $has(['team', 'role', 'permission', 'user', 'invite', 'sign in', 'password', 'security'])          => 'Team & access',
            $has(['audit', 'history', 'activity', 'log', 'timeline'])                                          => 'Audit & history',
            $has(['email', 'notification', 'digest', 'message', 'inbox'])                                      => 'Email & notifications',
            $has(['setting', 'language', 'theme', 'icon', 'brand', 'plan', 'billing', 'invoice'])              => 'Settings & billing',
            $has(['save', 'cancel', 'delete', 'edit', 'add', 'remove', 'search', 'filter', 'back', 'close'])   => 'Buttons & common actions',
            default                                                                                            => 'Other',
        };
    }

    /** Whether ui_translations has the (later-added) context column. Cached per request. */
    private static ?bool $hasContext = null;
    private static function hasContextColumn(): bool
    {
        if (self::$hasContext !== null) return self::$hasContext;
        try {
            Database::pdo()->query('SELECT context FROM ui_translations LIMIT 0');
            return self::$hasContext = true;
        } catch (\Throwable $e) {
            return self::$hasContext = false;
        }
    }

    /** Save (upsert) a translation from the editor. Empty target clears the override. */
    public static function saveTranslation(string $locale, string $source, string $target, ?string $userId): void
    {
        $target = trim($target);
        Database::pdo()->prepare(
            'INSERT INTO ui_translations (id, locale, source_hash, source, target, seen_count, updated_by, created_at, updated_at)
             VALUES (?,?,?,?,?,1,?,?,?)
             ON DUPLICATE KEY UPDATE target = VALUES(target), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        )->execute([uuid4(), $locale, sha1($source), $source, $target !== '' ? $target : null, $userId, utc_now(), utc_now()]);
        unset(self::$dbLoaded[$locale]);
    }

    /** @return array<string,string> */
    private static function load(string $locale): array
    {
        $file = FLXCOREHC_APP . '/lang/' . preg_replace('/[^a-z]/', '', $locale) . '.php';
        if (!is_file($file)) return [];
        $cat = require $file;
        return is_array($cat) ? $cat : [];
    }
}
