<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * The per-request identity every query is scoped by.
 *
 * Built ONLY from the server-side session after successful login —
 * never from request parameters. readonly: cannot be mutated mid-request.
 * This class is FlxCoreHC's replacement for Postgres Row Level Security.
 */
final class TenantContext
{
    /** v1.170.7 — FlxCoreHC is healthcare-only. Whatever a tenant row or session carries, every request
     *  context is healthcare, so no insurance-era branch (vertical !== 'healthcare') can ever render. */
    public readonly string $vertical;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly string $role,
        public readonly string $tenantName,
        string $vertical,
        public readonly string $orgShape,
        public readonly string $userName,
        public readonly bool $isPlatformAdmin = false,
        public readonly ?string $holderId = null,
        public readonly string $userEmail = '',
        public readonly bool $twoFactor = false,
    ) {
        $this->vertical = 'healthcare';
    }

    public static function fromSession(): ?self
    {
        $s = $_SESSION['auth'] ?? null;
        if (!is_array($s) || empty($s['tenant_id']) || empty($s['user_id'])) {
            return null;
        }
        return new self(
            $s['tenant_id'],
            $s['user_id'],
            $s['role'] ?? 'staff',
            $s['tenant_name'] ?? '',
            $s['vertical'] ?? 'healthcare',
            $s['org_shape'] ?? 'medical_group',
            $s['user_name'] ?? '',
            !empty($s['platform_admin']),
            $s['holder_id'] ?? null,
            (string) ($s['user_email'] ?? ''),
            !empty($s['totp_enabled']),
        );
    }
}
