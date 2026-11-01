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

    /** @return array{version:string,title:string,draft:string,sections:array} */
    private static function file(string $locale): array
    {
        static $cache = [];
        if (isset($cache[$locale])) return $cache[$locale];
        $en = require FLXCOREHC_APP . '/terms/en.php';
        $f  = FLXCOREHC_APP . '/terms/' . $locale . '.php';
        $doc = ($locale !== 'en' && is_file($f)) ? array_replace($en, (array) require $f) : $en;
        $doc['version'] = $en['version'];   // the version is the English file's
        return $cache[$locale] = $doc;
    }

    public static function version(): string
    {
        return (string) self::file('en')['version'];
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
