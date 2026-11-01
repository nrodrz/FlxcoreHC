<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;
use FlxCoreHC\TenantContext;

/**
 * Base repository: the ONLY way application code touches tenant-scoped tables.
 *
 * Every read appends `AND tenant_id = ?` from the server-side context.
 * Every insert forces tenant_id; every update refuses to change it.
 * A tampered cross-tenant id therefore returns null / affects zero rows.
 */
abstract class TenantRepository
{
    /** Table name. Hard-coded per concrete repository — never user input. */
    abstract protected function table(): string;

    /** Columns callers are allowed to write. Anything else is dropped. */
    abstract protected function fillable(): array;

    public function __construct(protected readonly TenantContext $ctx) {}

    /** The tenant this repository is scoped to — lets helpers derive scope from a repo they hold. */
    public function tenantId(): string { return $this->ctx->tenantId; }

    /** Override: map of plaintext column => encrypted (VARBINARY) column. */
    protected function encryptedColumns(): array { return []; }

    /** Override: map of plaintext column => blind-index (CHAR 64) column. */
    protected function blindIndexColumns(): array { return []; }

    /** Context string binding ciphertext to tenant+table+column (AEAD associated data). */
    private function aadFor(string $encCol): string
    {
        return $this->ctx->tenantId . '|' . $this->table() . '|' . $encCol;
    }

    /** Encrypt configured columns before write; populate blind indexes. */
    private function applyEncryptionOnWrite(array $data): array
    {
        // Blind indexes FIRST — they need the plaintext before it is nulled out.
        foreach ($this->blindIndexColumns() as $plainCol => $bidxCol) {
            if (array_key_exists($plainCol, $data)) {
                $data[$bidxCol] = \FlxCoreHC\Crypto::blindIndex($data[$plainCol] !== null ? (string) $data[$plainCol] : null);
            }
        }
        foreach ($this->encryptedColumns() as $plainCol => $encCol) {
            if (array_key_exists($plainCol, $data)) {
                $data[$encCol] = \FlxCoreHC\Crypto::encrypt($data[$plainCol] !== null ? (string) $data[$plainCol] : null, $this->aadFor($encCol));
                unset($data[$plainCol]); // plaintext column no longer exists at rest
            }
        }
        return $data;
    }

    /** Decrypt configured columns after read. */
    protected function decryptRow(?array $row): ?array
    {
        if ($row === null) return null;
        foreach ($this->encryptedColumns() as $plainCol => $encCol) {
            // Always define the plaintext key so callers can rely on it. When there's no
            // ciphertext (the field was never set), the plaintext is null rather than absent —
            // otherwise views that read $row['email'] emit "Undefined array key" warnings.
            if (array_key_exists($encCol, $row) && $row[$encCol] !== null) {
                $row[$plainCol] = \FlxCoreHC\Crypto::decrypt($row[$encCol], $this->aadFor($encCol));
            } elseif (!array_key_exists($plainCol, $row)) {
                $row[$plainCol] = null;
            }
        }
        return $row;
    }

    protected function decryptRows(array $rows): array
    {
        if ($this->encryptedColumns() === []) return $rows;
        return array_map(fn($r) => $this->decryptRow($r), $rows);
    }


    /** Tables that carry a soft-delete column (deleted_at). */
    protected function softDeletes(): bool
    {
        return in_array($this->table(), [
            'credentials', 'credential_holders', 'carriers',
            'renewal_tasks', 'credential_documents', 'locations', 'provider_groups',
        ], true);
    }

    private function liveClause(): string
    {
        return $this->softDeletes() ? ' AND deleted_at IS NULL' : '';
    }

    /**
     * v1.170.9 — provider scope (need-to-know) IN SQL. A repository whose table carries a provider id
     * declares the column here; find/all/count then add the ProviderScope predicate before ORDER/LIMIT,
     * so restricted users get correct pagination and counts and never touch unassigned providers' rows.
     * credential_holders itself uses 'id'. Return null for tables with no provider relationship.
     */
    protected function providerColumn(): ?string { return null; }

    /** Tables that relate to a provider only THROUGH a credential (credential_documents…) declare this instead. */
    protected function credentialColumn(): ?string { return null; }

    /** [sqlFragment, params] for this repository's provider scope; ['', []] when unscoped/unrestricted. */
    protected function scopeSql(?string $alias = null): array
    {
        $sc = \FlxCoreHC\ProviderScope::for($this->ctx);
        $pfx = $alias !== null ? $alias . '.' : '';
        if (($col = $this->providerColumn()) !== null) return [$sc->sql($pfx . $col, $col !== 'id'), $sc->params()];
        if (($col = $this->credentialColumn()) !== null) return [$sc->sqlViaCredential($pfx . $col), $sc->params()];
        return ['', []];
    }

    public function find(string $id): ?array
    {
        [$scope, $sp] = $this->scopeSql();
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM {$this->table()} WHERE id = ? AND tenant_id = ?{$this->liveClause()}{$scope} LIMIT 1"
        );
        $stmt->execute(array_merge([$id, $this->ctx->tenantId], $sp));
        $row = $stmt->fetch();
        return $row === false ? null : $this->decryptRow($row);
    }

    /**
     * @param array $where  column => value equality filters (developer-controlled keys)
     */
    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        $this->assertOrderBy($orderBy);
        $limit  = max(1, min($limit, 5000));
        [$scope, $sp] = $this->scopeSql();
        $sql    = "SELECT * FROM {$this->table()} WHERE tenant_id = ?{$this->liveClause()}{$scope}";
        $params = array_merge([$this->ctx->tenantId], $sp);
        foreach ($where as $col => $val) {
            $this->assertColumn($col);
            $sql      .= " AND `{$col}` = ?";
            $params[] = $val;
        }
        $sql .= " ORDER BY {$orderBy} LIMIT {$limit}";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $this->decryptRows($stmt->fetchAll());
    }

    public function count(array $where = []): int
    {
        [$scope, $sp] = $this->scopeSql();
        $sql    = "SELECT COUNT(*) AS c FROM {$this->table()} WHERE tenant_id = ?{$this->liveClause()}{$scope}";
        $params = array_merge([$this->ctx->tenantId], $sp);
        foreach ($where as $col => $val) {
            $this->assertColumn($col);
            $sql      .= " AND `{$col}` = ?";
            $params[] = $val;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetch()['c'];
    }

    public function insert(array $data): string
    {
        $data = array_intersect_key($data, array_flip($this->fillable()));
        $data = $this->applyEncryptionOnWrite($data);
        $data['id']         = uuid4();
        $data['tenant_id']  = $this->ctx->tenantId;   // forced — caller cannot override
        $data['created_at'] = utc_now();

        $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        Database::pdo()
            ->prepare("INSERT INTO {$this->table()} ({$cols}) VALUES ({$ph})")
            ->execute(array_values($data));

        $this->audit('create', $data['id'], $data);
        return $data['id'];
    }

    public function update(string $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip($this->fillable()));
        $data = $this->applyEncryptionOnWrite($data);
        unset($data['id'], $data['tenant_id'], $data['created_at']);
        if ($data === []) {
            return false;
        }
        [$scope, $sp] = $this->scopeSql();   // v1.170.9: writes are provider-scoped too
        $set    = implode(',', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $params = [...array_values($data), $id, $this->ctx->tenantId, ...$sp];
        $stmt   = Database::pdo()->prepare(
            "UPDATE {$this->table()} SET {$set} WHERE id = ? AND tenant_id = ?{$scope}"
        );
        $stmt->execute($params);
        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            $this->audit('update', $id, $data);
        }
        return $changed;
    }

    public function delete(string $id): bool
    {
        [$scope, $sp] = $this->scopeSql();   // v1.170.9: writes are provider-scoped too
        if ($this->softDeletes()) {
            $stmt = Database::pdo()->prepare(
                "UPDATE {$this->table()} SET deleted_at = ? WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL{$scope}"
            );
            $stmt->execute(array_merge([utc_now(), $id, $this->ctx->tenantId], $sp));
        } else {
            $stmt = Database::pdo()->prepare(
                "DELETE FROM {$this->table()} WHERE id = ? AND tenant_id = ?{$scope}"
            );
            $stmt->execute(array_merge([$id, $this->ctx->tenantId], $sp));
        }
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->audit('delete', $id, []);
        }
        return $deleted;
    }

    /** Permanent removal. Used by retention-purge jobs and by an explicit, confirmed operator hard-delete
     *  (records.delete). Irreversible — callers must confirm and audit. */
    public function purge(string $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "DELETE FROM {$this->table()} WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$id, $this->ctx->tenantId]);
        $done = $stmt->rowCount() > 0;
        if ($done) { $this->audit('purge', $id, []); }
        return $done;
    }

    /** Restore a soft-deleted row (clears deleted_at). Only meaningful on soft-delete tables. */
    public function restore(string $id): bool
    {
        if (!$this->softDeletes()) return false;
        $stmt = Database::pdo()->prepare(
            "UPDATE {$this->table()} SET deleted_at = NULL WHERE id = ? AND tenant_id = ? AND deleted_at IS NOT NULL"
        );
        $stmt->execute([$id, $this->ctx->tenantId]);
        $done = $stmt->rowCount() > 0;
        if ($done) { $this->audit('restore', $id, []); }
        return $done;
    }

    /** Fetch a soft-deleted row by id (bypasses the deleted_at filter) — for restore/purge screens. */
    public function findTrashed(string $id): ?array
    {
        if (!$this->softDeletes()) return null;
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM {$this->table()} WHERE id = ? AND tenant_id = ? AND deleted_at IS NOT NULL LIMIT 1"
        );
        $stmt->execute([$id, $this->ctx->tenantId]);
        return $stmt->fetch() ?: null;
    }

    protected function audit(string $action, string $entityId, array $changes): void
    {
        unset($changes['password_hash']);
        // never write PHI plaintext or ciphertext blobs into the audit diff
        foreach ($this->encryptedColumns() as $plainCol => $encCol) {
            unset($changes[$plainCol], $changes[$encCol]);
        }
        foreach ($this->blindIndexColumns() as $bidxCol) {
            unset($changes[$bidxCol]);
        }
        Database::pdo()->prepare(
            "INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            uuid4(),
            $this->ctx->tenantId,
            $this->ctx->userId,
            $action,
            $this->table(),
            $entityId,
            json_encode($changes, JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
            utc_now(),
        ]);
    }

    /**
     * ORDER BY must remain developer-supplied. This guard makes the latent
     * interpolation non-exploitable even if user input ever reaches it:
     * only column chars, commas, parens, IS NULL, and ASC/DESC are allowed.
     */
    protected function assertOrderBy(string $orderBy): void
    {
        if (!preg_match('/^[a-z0-9_,()\s]+(?:\b(?:asc|desc|is|null)\b[a-z0-9_,()\s]*)*$/i', $orderBy)
            || preg_match('/[;\'"`-]|\/\*/', $orderBy)) {
            throw new \InvalidArgumentException('Illegal ORDER BY clause.');
        }
    }

    protected function assertColumn(string $col): void
    {
        if (!preg_match('/^[a-z_]+$/', $col)) {
            throw new \InvalidArgumentException("Illegal column name: {$col}");
        }
    }
}
