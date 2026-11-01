<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * ProviderScope (v1.170.9) — need-to-know as an SQL predicate.
 *
 * Authorization model:  Tenant scope + Capability + Provider scope + Record ownership = authorized access.
 * This class supplies the PROVIDER SCOPE term so it can be applied INSIDE the query, before
 * ORDER BY / LIMIT / COUNT / GROUP BY — never as a filter on rows that were already fetched. That way lists
 * paginate correctly, aggregates reflect only authorized providers, and nothing about unassigned providers
 * (not even a count) reaches a restricted user.
 *
 * Restricted = users.provider_access = 'assigned' (see AssignmentService). Owners, platform admins and
 * system contexts are unrestricted → sql() returns '' and nothing changes for them.
 *
 * The predicate is a correlated subquery on holder_assignments (not an inlined id list), so it is correct
 * for any number of assignments, evaluated at request time, and reflects assignment changes immediately.
 *
 * Usage (raw SQL):
 *   $sc = ProviderScope::for($ctx);
 *   $sql = "SELECT ... FROM credentials c WHERE c.tenant_id = ?" . $sc->sql('c.holder_id') . " ORDER BY ...";
 *   $stmt->execute(array_merge([$ctx->tenantId], $sc->params()));
 *
 * NULL holder columns (tenant-wide items such as an organization-level calendar event) are allowed through by
 * default; pass $allowNull=false to exclude them.
 *
 * FAIL-CLOSED: if the user's access mode cannot be determined (query failure), the scope is "sees nothing".
 */
final class ProviderScope
{
    private function __construct(
        private readonly bool $restricted,
        private readonly string $tenantId,
        private readonly string $userId,
        private readonly ?TenantContext $ctx,
    ) {}

    public static function for(TenantContext $ctx): self
    {
        try {
            $restricted = AssignmentService::modeFor($ctx) === 'assigned';
        } catch (\Throwable $e) {
            flx_log_error('error', 'provider scope: access mode unresolved — failing closed');
            return new self(true, $ctx->tenantId, '', null);   // '' user id matches no assignment row
        }
        return new self($restricted, $ctx->tenantId, (string) $ctx->userId, $ctx);
    }

    public function restricted(): bool { return $this->restricted; }

    /**
     * SQL fragment to append to a WHERE clause (starts with " AND ..."); '' when unrestricted.
     * @param string $holderCol  fully-qualified holder id column, e.g. 'c.holder_id' or 'h.id'
     */
    public function sql(string $holderCol, bool $allowNull = true): string
    {
        if (!$this->restricted) return '';
        // developer-controlled: a column, or COALESCE(col, col) for tables that resolve the provider two ways
        $col = '[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?';
        if (!preg_match("/^(?:{$col}|COALESCE\({$col}, ?{$col}\))$/i", $holderCol)) {
            throw new \InvalidArgumentException('Illegal holder column for ProviderScope.');
        }
        $in = "{$holderCol} IN (SELECT ha.holder_id FROM holder_assignments ha WHERE ha.tenant_id = ? AND ha.user_id = ?)";
        return $allowNull ? " AND ({$holderCol} IS NULL OR {$in})" : " AND {$in}";
    }

    /** Bound parameters for sql(), in order. [] when unrestricted. */
    public function params(): array
    {
        return $this->restricted ? [$this->tenantId, $this->userId] : [];
    }

    /**
     * Scope for a column that references a CREDENTIAL (documents, reminders): resolves the holder through
     * the credentials table so the caller does not need a join.
     */
    public function sqlViaCredential(string $credentialCol): string
    {
        if (!$this->restricted) return '';
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $credentialCol)) {
            throw new \InvalidArgumentException('Illegal credential column for ProviderScope.');
        }
        return " AND {$credentialCol} IN (SELECT cx.id FROM credentials cx JOIN holder_assignments ha
                    ON ha.tenant_id = cx.tenant_id AND ha.holder_id = cx.holder_id
                  WHERE cx.tenant_id = ? AND ha.user_id = ?)";
    }

    /**
     * Scope for rows that name their provider directly OR only through a credential (legacy renewal tasks:
     * holder_id may be NULL while credential_id is set). Tenant-wide rows (both NULL) pass. Uses 4 bound
     * params — take them from paramsHolderOrCredential(), not params().
     */
    public function sqlHolderOrCredential(string $holderCol, string $credentialCol): string
    {
        if (!$this->restricted) return '';
        $re = '/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i';
        if (!preg_match($re, $holderCol) || !preg_match($re, $credentialCol)) {
            throw new \InvalidArgumentException('Illegal column for ProviderScope.');
        }
        return " AND (({$holderCol} IS NOT NULL AND {$holderCol} IN (SELECT ha.holder_id FROM holder_assignments ha WHERE ha.tenant_id = ? AND ha.user_id = ?))
                   OR ({$holderCol} IS NULL AND ({$credentialCol} IS NULL OR {$credentialCol} IN
                        (SELECT cx.id FROM credentials cx JOIN holder_assignments ha2 ON ha2.tenant_id = cx.tenant_id AND ha2.holder_id = cx.holder_id
                          WHERE cx.tenant_id = ? AND ha2.user_id = ?))))";
    }

    public function paramsHolderOrCredential(): array
    {
        return $this->restricted ? [$this->tenantId, $this->userId, $this->tenantId, $this->userId] : [];
    }

    /** True if this scope allows the given holder id (for single-record checks after a fetch). */
    public function allows(?string $holderId): bool
    {
        if (!$this->restricted) return true;
        if ($holderId === null || $holderId === '') return true;
        return $this->ctx !== null && AssignmentService::canSee($this->ctx, $holderId);
    }
}
