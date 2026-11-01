<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Terms of Use (Ownership & White-Label Standard, element 10).
 *  - The text and version ship with the app (app/terms/en.php, es.php) and cannot be edited from a screen.
 *  - Every user accepts at their first sign-in, and again whenever the version changes; nothing else opens until then.
 *  - A person with the permission terms.accept can also accept for the whole organization.
 *  - Every acceptance is a row in terms_acceptances and an audit row (terms_accepted). Rows are never edited or deleted.
 */
final class TermsService
{
    public const PERMISSION = 'terms.accept';

    public const ALLOWED_PLACEHOLDERS = ['organization', 'product', 'owner'];

    /**
     * The current Terms: the newest published version (Console → Terms of Use), or the files shipped with the app when none was published.
     * @return array{version:string,en:array,es:array}   each language: title, draft (banner text, '' = final), sections [[heading,[paragraphs]]]
     */
    private static function current(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $row = Database::pdo()->query('SELECT version, content FROM terms_versions ORDER BY created_at DESC, id DESC LIMIT 1')->fetch();
            if ($row) {
                $c = json_decode((string) $row['content'], true);
                if (is_array($c) && isset($c['en']['sections'])) return $cache = ['version' => (string) $row['version'], 'en' => $c['en'], 'es' => $c['es'] ?? []];
            }
        } catch (\Throwable $e) { /* table missing before migration 137: the shipped files apply */ }
        $en = require FLXCOREHC_APP . '/terms/en.php'; $es = require FLXCOREHC_APP . '/terms/es.php';
        return $cache = ['version' => (string) $en['version'], 'en' => $en, 'es' => $es];
    }

    /** One language of the current Terms; Spanish falls back to English wherever it has nothing. */
    private static function file(string $locale): array
    {
        $cur = self::current();
        $doc = $locale === 'en' ? $cur['en'] : array_replace($cur['en'], array_filter((array) ($cur[$locale] ?? []), fn($v) => $v !== [] && $v !== null));
        $doc['version'] = $cur['version'];
        return $doc;
    }

    public static function version(): string
    {
        return self::current()['version'];
    }

    // ---- editing (platform operator) -------------------------------------------------------------------------

    /** Sections -> the plain-text format of the editor: "# Heading" lines, paragraphs separated by a blank line. */
    public static function toMarkup(array $sections): string
    {
        $out = [];
        foreach ($sections as [$h, $paras]) $out[] = '# ' . $h . "\n\n" . implode("\n\n", $paras);
        return implode("\n\n", $out) . "\n";
    }

    /** The editor's plain text -> [sections, error]. */
    public static function parseMarkup(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') return [[], 'The text is empty.'];
        $secs = []; $cur = null;
        foreach (preg_split('/\n{2,}/', $text) as $block) {
            $block = trim($block);
            if ($block === '') continue;
            if (str_starts_with($block, '# ')) {
                $lines = explode("\n", $block, 2);
                if ($cur !== null) $secs[] = $cur;
                $cur = [trim(substr($lines[0], 2)), []];
                if (isset($lines[1]) && trim($lines[1]) !== '') $cur[1][] = trim((string) preg_replace('/\s*\n\s*/', ' ', $lines[1]));
                continue;
            }
            if ($cur === null) return [[], 'The text must start with a section heading: a line beginning "# ".'];
            $cur[1][] = trim((string) preg_replace('/\s*\n\s*/', ' ', $block));
        }
        if ($cur !== null) $secs[] = $cur;
        foreach ($secs as [$h, $ps]) {
            if ($h === '') return [[], 'A section has an empty heading.'];
            if ($ps === []) return [[], 'The section "' . $h . '" has no paragraph.'];
        }
        $found = [];
        preg_match_all('/\{(\w+)\}/', json_encode($secs, JSON_UNESCAPED_UNICODE), $m);
        foreach (array_unique($m[1]) as $ph) if (!in_array($ph, self::ALLOWED_PLACEHOLDERS, true)) $found[] = '{' . $ph . '}';
        if ($found) return [[], 'Unknown placeholder ' . implode(', ', $found) . ' — only {organization}, {product} and {owner} are filled in.'];
        if (strlen(json_encode($secs)) > 60000) return [[], 'The text is too long.'];
        return [$secs, null];
    }

    /** Publish a new version. @return array{0:bool,1:string} [ok, message or new version] */
    public static function publish(TenantContext $ctx, string $enText, string $esText, string $enDraft, string $esDraft): array
    {
        [$en, $err] = self::parseMarkup($enText);
        if ($err) return [false, t('English: {msg}', ['msg' => $err])];
        $es = [];
        if (trim($esText) !== '') { [$es, $err] = self::parseMarkup($esText); if ($err) return [false, t('Spanish: {msg}', ['msg' => $err])]; }
        $pdo = Database::pdo();
        $day = gmdate('Y-m-d');
        $n = (int) $pdo->query("SELECT COUNT(*) FROM terms_versions WHERE version LIKE '" . $day . ".%'")->fetchColumn() + 1;
        $version = $day . '.' . $n;
        $content = json_encode([
            'en' => ['title' => 'Terms of Use', 'draft' => trim($enDraft), 'sections' => $en],
            'es' => $es ? ['title' => 'Términos de uso', 'draft' => trim($esDraft), 'sections' => $es] : [],
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO terms_versions (id, version, content, created_by, created_at) VALUES (?,?,?,?,?)')
            ->execute([uuid4(), $version, $content, $ctx->userId, utc_now()]);
        audit_log($ctx, 'terms_published', 'terms', substr($version, 0, 36), json_encode(['version' => $version, 'sections_en' => count($en), 'sections_es' => count($es)]));
        return [true, $version];
    }

    /** Published versions, newest first. @return array<int,array{version:string,created_at:string,by:string}> */
    public static function history(): array
    {
        try {
            return Database::pdo()->query('SELECT v.version, v.created_at, COALESCE(u.full_name, \'\') AS `by` FROM terms_versions v LEFT JOIN users u ON u.id = v.created_by ORDER BY v.created_at DESC, v.id DESC LIMIT 50')->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    /** Editor start text for one language of the current Terms. */
    public static function editorText(string $locale): string
    {
        $cur = self::current(); $d = $locale === 'en' ? $cur['en'] : ($cur['es'] ?: []);
        return isset($d['sections']) && $d['sections'] ? self::toMarkup($d['sections']) : '';
    }

    public static function editorDraft(string $locale): string
    {
        $cur = self::current(); return (string) (($locale === 'en' ? $cur['en'] : $cur['es'])['draft'] ?? '');
    }

    /** Raw (placeholder-free) counts for the console: users who accepted the current version / users in all tenants. */
    public static function acceptanceTotals(): array
    {
        $q = Database::pdo()->prepare("SELECT COUNT(DISTINCT user_id) FROM terms_acceptances WHERE scope = 'user' AND terms_version = ?");
        $q->execute([self::version()]);
        $total = (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE platform_admin = 0 AND status = 'active'")->fetchColumn();
        return ['accepted' => (int) $q->fetchColumn(), 'users' => $total];
    }

    /**
     * The Terms for one organization, placeholders filled.
     * @return array{version:string,title:string,draft:string,effective:string,sections:array<int,array{0:string,1:array<int,string>}>}
     */
    public static function text(string $locale, string $organization, ?string $effectiveUtc): array
    {
        $doc = self::file($locale);
        $fill = fn(string $s) => str_replace(['{organization}', '{product}', '{owner}'], [$organization, Ownership::p(), Ownership::OWNER], $s);
        $secs = [];
        foreach ($doc['sections'] as [$h, $paras]) $secs[] = [$fill($h), array_map($fill, $paras)];
        return ['version' => $doc['version'], 'title' => $doc['title'], 'draft' => $doc['draft'],
                'effective' => $effectiveUtc ? fmt_date($effectiveUtc) : '', 'sections' => $secs];
    }

    /** Has this user accepted the CURRENT version for themselves? */
    public static function userAccepted(string $userId): bool
    {
        try {
            $q = Database::pdo()->prepare("SELECT 1 FROM terms_acceptances WHERE user_id = ? AND scope = 'user' AND terms_version = ? LIMIT 1");
            $q->execute([$userId, self::version()]);
            return (bool) $q->fetchColumn();
        } catch (\Throwable $e) { return true; }   // table missing before migration 136: do not lock everyone out
    }

    /** The organization's acceptance of the CURRENT version: null or ['user_name','accepted_at']. */
    public static function organizationAcceptance(string $tenantId): ?array
    {
        try {
            $q = Database::pdo()->prepare("SELECT a.accepted_at, u.full_name AS user_name FROM terms_acceptances a LEFT JOIN users u ON u.id = a.user_id
                                           WHERE a.tenant_id = ? AND a.scope = 'organization' AND a.terms_version = ? ORDER BY a.accepted_at LIMIT 1");
            $q->execute([$tenantId, self::version()]);
            return $q->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /** user_id => accepted_at of the CURRENT version, for the people-who-manage-users screen. @return array<string,string> */
    public static function acceptanceMap(string $tenantId): array
    {
        try {
            $q = Database::pdo()->prepare("SELECT user_id, MIN(accepted_at) AS at FROM terms_acceptances WHERE tenant_id = ? AND scope = 'user' AND terms_version = ? GROUP BY user_id");
            $q->execute([$tenantId, self::version()]);
            return $q->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    /** Record the acceptance(s). $forOrganization is honoured only for a caller who holds terms.accept. Returns what was recorded. */
    public static function accept(TenantContext $ctx, bool $forOrganization): array
    {
        $pdo = Database::pdo(); $v = self::version(); $now = utc_now(); $done = [];
        $rec = function (string $scope) use ($pdo, $ctx, $v, $now, &$done) {
            $dup = $pdo->prepare('SELECT 1 FROM terms_acceptances WHERE user_id = ? AND scope = ? AND terms_version = ? LIMIT 1');
            $dup->execute([$ctx->userId, $scope, $v]);
            if ($dup->fetchColumn()) return;
            $pdo->prepare('INSERT INTO terms_acceptances (id, tenant_id, user_id, scope, terms_version, accepted_at) VALUES (?,?,?,?,?,?)')
                ->execute([uuid4(), $ctx->tenantId, $ctx->userId, $scope, $v, $now]);
            audit_log($ctx, 'terms_accepted', 'terms', substr($v, 0, 36), json_encode(['version' => $v, 'scope' => $scope, 'organization' => $ctx->tenantName, 'user' => $ctx->userName], JSON_UNESCAPED_UNICODE));
            $done[] = $scope;
        };
        $rec('user');
        if ($forOrganization && Authz::can($ctx, self::PERMISSION) && self::organizationAcceptance($ctx->tenantId) === null) $rec('organization');
        $_SESSION['terms_ok'] = $ctx->userId . '|' . $v;
        return $done;
    }
}
