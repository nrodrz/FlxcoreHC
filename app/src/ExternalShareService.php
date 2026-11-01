<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * External sharing: a read-only, password-protected, time-limited web view of a provider's profile
 * for a recipient who is not a FlxCoreHC user (payer, state agency, accreditation body, hospital…).
 *
 * Two factors: the URL token (stored only as sha256) AND a password (Argon2id). The password is
 * shown once at creation and is never emailed with the link — the sharer delivers it separately.
 * Expiry is set per link in days (1..MAX_DAYS); revocable any time. Every creation, successful view,
 * document download, failed password and lock is logged, so the tenant can show who saw what, when.
 */
final class ExternalShareService
{
    public const MAX_DAYS = 180;
    public const TYPES = ['payer' => 'Insurance payer', 'state_agency' => 'State agency / board',
                          'accreditation' => 'Accreditation body', 'hospital' => 'Hospital / facility', 'other' => 'Other'];
    private const MAX_FAILED = 5;
    private const LOCK_MINUTES = 30;

    public function __construct(private readonly TenantContext $ctx) {}

    /**
     * Create a share. Returns [ok, message, ['url'=>..., 'password'=>...]] — the only time the plain
     * password is available. $days is clamped to 1..MAX_DAYS.
     */
    public function create(string $holderId, string $recipientType, string $recipientName, array $credentialIds,
                           bool $includeDocs, int $days, ?string $password, ?int $maxViews): array
    {
        $recipientType = isset(self::TYPES[$recipientType]) ? $recipientType : 'other';
        $recipientName = trim($recipientName);
        if ($recipientName === '') return [false, 'Give the recipient a name (e.g. the payer or agency).', null];
        $days = max(1, min(self::MAX_DAYS, $days));
        $password = trim((string)$password);
        if ($password === '') $password = self::generatePassword();
        if (mb_strlen($password) < 8) return [false, 'Password must be at least 8 characters.', null];

        $token = bin2hex(random_bytes(24));
        $id = uuid4();
        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("+{$days} days")->format('Y-m-d H:i:s');
        Database::pdo()->prepare(
            'INSERT INTO external_shares (id, tenant_id, holder_id, recipient_type, recipient_name, scope, token_hash, password_hash,
                                          expires_at, max_views, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$id, $this->ctx->tenantId, $holderId, $recipientType, mb_substr($recipientName, 0, 160),
                    json_encode(['credential_ids' => array_values($credentialIds), 'include_documents' => $includeDocs]),
                    hash('sha256', $token), password_hash($password, PASSWORD_ARGON2ID),
                    $expires, $maxViews ?: null, $this->ctx->userId, utc_now()]);

        audit_log($this->ctx, 'external_share_created', 'holders', $holderId,
            json_encode(['share_id' => $id, 'recipient' => $recipientName, 'type' => $recipientType, 'days' => $days, 'docs' => $includeDocs, 'credentials' => count($credentialIds)]));
        ComplianceEventService::record($this->ctx, 'profile_shared_externally',
            'Profile shared with ' . $recipientName . ' (' . self::TYPES[$recipientType] . '), expires in ' . $days . ' day(s)',
            ['holder_id' => $holderId, 'detail' => ['share_id' => $id, 'recipient_type' => $recipientType, 'expires_at' => $expires]]);

        return [true, 'Share link created.', ['url' => flx_public_base_safe() . '/s/' . $token, 'password' => $password, 'expires_at' => $expires, 'id' => $id]];
    }

    public function revoke(string $shareId): bool
    {
        $st = Database::pdo()->prepare('UPDATE external_shares SET revoked_at = ?, revoked_by = ? WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL');
        $st->execute([utc_now(), $this->ctx->userId, $shareId, $this->ctx->tenantId]);
        if ($st->rowCount() > 0) {
            $s = $this->find($shareId);
            audit_log($this->ctx, 'external_share_revoked', 'holders', $s['holder_id'] ?? null, json_encode(['share_id' => $shareId]));
            return true;
        }
        return false;
    }

    public function find(string $shareId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM external_shares WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$shareId, $this->ctx->tenantId]);
        return $st->fetch() ?: null;
    }

    /** Shares for one provider (tenant view), newest first, with creator + status. */
    public function forHolder(string $holderId): array
    {
        if (SupportAccess::active()) return [];
        $st = Database::pdo()->prepare(
            'SELECT s.*, u.full_name AS created_by_name FROM external_shares s
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.tenant_id = ? AND s.holder_id = ? ORDER BY s.created_at DESC LIMIT 100');
        $st->execute([$this->ctx->tenantId, $holderId]);
        return array_map([$this, 'decorate'], $st->fetchAll());
    }

    /** All shares across the org (oversight list). */
    public function all(int $limit = 300): array
    {
        if (SupportAccess::active()) return [];
        $sc = ProviderScope::for($this->ctx);   // v1.170.9: oversight list shows only providers this user may see
        $st = Database::pdo()->prepare(
            'SELECT s.*, u.full_name AS created_by_name, h.first_name, h.last_name, h.org_name, h.holder_type
             FROM external_shares s LEFT JOIN users u ON u.id = s.created_by
             JOIN credential_holders h ON h.id = s.holder_id
             WHERE s.tenant_id = ?' . $sc->sql('s.holder_id', false) . ' ORDER BY s.created_at DESC LIMIT ' . max(1, min($limit, 1000)));
        $st->execute(array_merge([$this->ctx->tenantId], $sc->params()));
        return array_map([$this, 'decorate'], $st->fetchAll());
    }

    /** Access log for one share. */
    public function accessLog(string $shareId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM external_share_access WHERE share_id = ? AND tenant_id = ? ORDER BY created_at DESC LIMIT 500');
        $st->execute([$shareId, $this->ctx->tenantId]);
        return $st->fetchAll();
    }

    private function decorate(array $s): array
    {
        $s['state'] = $s['revoked_at'] ? 'revoked' : (strtotime($s['expires_at'] . ' UTC') < time() ? 'expired'
                    : (($s['max_views'] && $s['view_count'] >= $s['max_views']) ? 'exhausted' : 'active'));
        return $s;
    }

    // ---- public (unauthenticated) side -----------------------------------------

    /** Resolve a URL token to a share row (no tenant context — this is the public path). */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $st = Database::pdo()->prepare('SELECT * FROM external_shares WHERE token_hash = ? LIMIT 1');
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: null;
    }

    /** Why a share cannot be opened, or null if usable (before password). */
    public static function blockedReason(array $s): ?string
    {
        if ($s['revoked_at']) return 'This link has been revoked.';
        // v1.170.6 — a provider in Deleted records (or purged) is not shown through an external link.
        $h = Database::pdo()->prepare('SELECT 1 FROM credential_holders WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1');
        $h->execute([$s['holder_id'], $s['tenant_id']]);
        if (!$h->fetchColumn()) return 'This link is no longer available.';
        if (strtotime($s['expires_at'] . ' UTC') < time()) return 'This link has expired.';
        if ($s['max_views'] && (int)$s['view_count'] >= (int)$s['max_views']) return 'This link has reached its view limit.';
        if ($s['locked_until'] && strtotime($s['locked_until'] . ' UTC') > time()) return 'Too many incorrect passwords — try again later.';
        return null;
    }

    /** Verify the password; on failure count attempts and lock after MAX_FAILED. */
    public static function checkPassword(array $s, string $password): bool
    {
        $pdo = Database::pdo();
        if (password_verify($password, (string)$s['password_hash'])) {
            $pdo->prepare('UPDATE external_shares SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$s['id']]);
            return true;
        }
        $n = (int)$s['failed_attempts'] + 1;
        $lock = $n >= self::MAX_FAILED ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . self::LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE external_shares SET failed_attempts = ?, locked_until = ? WHERE id = ?')->execute([$lock ? 0 : $n, $lock, $s['id']]);
        self::logAccess($s, $lock ? 'locked' : 'password_failed');
        return false;
    }

    public static function recordView(array $s): void
    {
        Database::pdo()->prepare('UPDATE external_shares SET view_count = view_count + 1, last_viewed_at = ? WHERE id = ?')->execute([utc_now(), $s['id']]);
        self::logAccess($s, 'view');
    }

    public static function logAccess(array $s, string $event, ?string $documentId = null): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO external_share_access (id, share_id, tenant_id, event, document_id, ip_address, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?)'
            )->execute([uuid4(), $s['id'], $s['tenant_id'], $event, $documentId,
                        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), utc_now()]);
        } catch (\Throwable $e) {}
    }

    /** Data for the public read-only page, limited to the share's scope. */
    public static function profileFor(array $s): array
    {
        $pdo = Database::pdo();
        $scope = json_decode((string)($s['scope'] ?? ''), true) ?: [];
        $ids = array_values(array_filter((array)($scope['credential_ids'] ?? []), 'is_string'));
        $h = $pdo->prepare('SELECT * FROM credential_holders WHERE id = ? AND tenant_id = ? LIMIT 1');
        $h->execute([$s['holder_id'], $s['tenant_id']]);
        $holder = $h->fetch() ?: [];
        $t = $pdo->prepare('SELECT name, brand_name FROM tenants WHERE id = ?'); $t->execute([$s['tenant_id']]); $tenant = $t->fetch() ?: [];
        $creds = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $c = $pdo->prepare("SELECT id, credential_type, name, issuing_body, issued_date, expiry_date, status, last_verified_at
                                FROM credentials WHERE tenant_id = ? AND holder_id = ? AND deleted_at IS NULL AND id IN ($in) ORDER BY expiry_date");
            $c->execute(array_merge([$s['tenant_id'], $s['holder_id']], $ids));
            $creds = $c->fetchAll();
        }
        $docs = [];
        if (!empty($scope['include_documents']) && $creds) {
            $cin = implode(',', array_fill(0, count($creds), '?'));
            $d = $pdo->prepare("SELECT id, credential_id, original_name, mime_type, size_bytes, kind, verified_at, verification_method
                                FROM credential_documents WHERE tenant_id = ? AND deleted_at IS NULL AND credential_id IN ($cin) ORDER BY created_at DESC");
            $d->execute(array_merge([$s['tenant_id']], array_column($creds, 'id')));
            foreach ($d->fetchAll() as $row) { $docs[$row['credential_id']][] = $row; }
        }
        $scr = [];
        try { $q = $pdo->prepare('SELECT source, screened_on, result FROM exclusion_screenings WHERE tenant_id = ? AND holder_id = ? ORDER BY screened_on DESC LIMIT 5'); $q->execute([$s['tenant_id'], $s['holder_id']]); $scr = $q->fetchAll(); } catch (\Throwable $e) {}
        return ['holder' => $holder, 'tenant' => $tenant, 'credentials' => $creds, 'docs' => $docs, 'screenings' => $scr, 'scope' => $scope];
    }

    public static function generatePassword(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) { $out .= $alpha[random_int(0, strlen($alpha) - 1)]; }
        return $out;
    }
}
