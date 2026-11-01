<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Carrier-facing share links.
 *
 * A share link is a capability URL: possession of the token grants read-only view of exactly
 * one producer's credential status, nothing else. It expires, it can be revoked, and every
 * view is counted. The token lives only in the URL — resolving it is deliberately outside the
 * tenant scope (the viewer has no session), so resolveForView() is the one place that reads
 * across tenants, and it does so by an unguessable 32-char token, checking expiry and
 * revocation before returning anything.
 */
final class ShareLinkService
{
    public const SCOPES   = ['summary' => 'Status only', 'full' => 'Status + evidence counts'];
    public const DAY_OPTIONS = [7 => '7 days', 14 => '14 days', 30 => '30 days', 90 => '90 days'];

    public function __construct(private TenantContext $ctx) {}

    /** All active + past links for a producer, newest first. */
    public function forHolder(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM share_links WHERE tenant_id = ? AND holder_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$this->ctx->tenantId, $holderId]);
        return $stmt->fetchAll() ?: [];
    }

    public function create(string $holderId, array $in): array
    {
        $days  = (int) ($in['days'] ?? 30);
        if (!isset(self::DAY_OPTIONS[$days])) $days = 30;
        $scope = in_array($in['scope'] ?? '', ['summary', 'full'], true) ? $in['scope'] : 'summary';
        $label = trim((string) ($in['label'] ?? '')) ?: null;

        // Privacy gate: sharing the FULL credential file requires an active provider consent
        // ("security clearance") on record. Routine summary verification does not, but is linked to
        // a consent when one exists, so every disclosure can answer "on whose authority?".
        $consent = (new ConsentService($this->ctx))->activeFor($holderId, $scope);
        if ($scope === 'full' && $consent === null) {
            return [false, 'A full-file share needs an active provider consent on record first — record the clearance below, then create the link.', null];
        }

        $token = bin2hex(random_bytes(16));   // 32 hex chars, unguessable
        $id    = uuid4();
        Database::pdo()->prepare(
            "INSERT INTO share_links (id, tenant_id, holder_id, token, label, scope, consent_id, created_by, expires_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $id, $this->ctx->tenantId, $holderId, $token, $label, $scope, $consent['id'] ?? null, $this->ctx->userId,
            (new \DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s'),
            utc_now(),
        ]);
        return [true, 'Share link created.', $token];
    }



    public function revoke(string $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE share_links SET revoked_at = ? WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL"
        );
        $stmt->execute([utc_now(), $id, $this->ctx->tenantId]);
        return $stmt->rowCount() > 0;
    }

    public static function status(array $link): string
    {
        if (!empty($link['revoked_at'])) return 'revoked';
        if (strtotime((string) $link['expires_at']) < time()) return 'expired';
        return 'active';
    }

    /**
     * Resolve a token for a public viewer. No tenant scope — the token IS the authorization.
     * Returns the link row (with tenant_id + holder_id) only if active; null otherwise.
     * Increments the view counter as a side effect.
     */
    public static function resolveForView(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $stmt = Database::pdo()->prepare("SELECT * FROM share_links WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $link = $stmt->fetch();
        if (!$link) return null;
        if (!empty($link['revoked_at'])) return null;
        if (strtotime((string) $link['expires_at']) < time()) return null;
        // v1.170.6 — never resolve a link for a provider in Deleted records (or purged).
        $h = Database::pdo()->prepare('SELECT 1 FROM credential_holders WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1');
        $h->execute([$link['holder_id'], $link['tenant_id']]);
        if (!$h->fetchColumn()) return null;

        Database::pdo()->prepare(
            "UPDATE share_links SET view_count = view_count + 1, last_viewed = ? WHERE id = ?"
        )->execute([utc_now(), $link['id']]);
        return $link;
    }
}
