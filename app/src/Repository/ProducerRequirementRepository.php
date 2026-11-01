<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/**
 * Tenant-scoped assignment of required credentials to producers.
 *
 * Each assignment is stored as (catalog_code, regulator_id):
 *   - ('ahip', NULL)            — a general catalog requirement
 *   - ('carrier_cert', <uuid>)  — a catalog requirement scoped to one regulator
 *   - ('cr:<uuid>', <uuid>)     — a custom requirement defined on the Carriers page
 * In the UI these travel as tokens: "ahip", "carrier_cert@<uuid>", "cr:<uuid>".
 */
final class ProducerRequirementRepository extends TenantRepository
{
    // Platform support sessions never see tenant records — see SupportAccess.
    public function find(string $id): ?array
    {
        if (\FlxCoreHC\SupportAccess::active()) return null;
        return parent::find($id);
    }

    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active()) return [];
        return parent::all($where, $orderBy, $limit);
    }

    protected function table(): string { return 'producer_requirements'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array { return ['holder_id', 'catalog_code', 'regulator_id', 'assigned_user_id']; }

    public static function token(string $code, ?string $regulatorId): string
    {
        if (str_starts_with($code, 'cr:')) return $code;
        return $regulatorId ? $code . '@' . $regulatorId : $code;
    }

    /** @return array{0:string,1:?string} [catalog_code, regulator_id] */
    public static function parse(string $token): array
    {
        $token = trim($token);
        if (str_starts_with($token, 'cr:')) return [$token, null]; // regulator resolved via the requirement row
        $at = strpos($token, '@');
        if ($at !== false) return [substr($token, 0, $at), substr($token, $at + 1) ?: null];
        return [$token, null];
    }

    /** Tokens assigned to one producer. */
    /** holderId => (token => assigned_user_id) for the whole tenant. */
    public function allAssignees(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT holder_id, catalog_code, regulator_id, assigned_user_id FROM producer_requirements
              WHERE tenant_id = ? AND assigned_user_id IS NOT NULL{$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql()[1]));
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $tok = $r['catalog_code'] . ($r['regulator_id'] ? '@' . $r['regulator_id'] : '');
            $out[$r['holder_id']][$tok] = $r['assigned_user_id'];
        }
        return $out;
    }

    /** token => assigned_user_id, for every requirement that has an owner. */
    public function assigneesFor(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT catalog_code, regulator_id, assigned_user_id FROM producer_requirements
              WHERE tenant_id = ? AND holder_id = ?{$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $holderId], $this->scopeSql()[1]));
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $tok = $r['catalog_code'] . ($r['regulator_id'] ? '@' . $r['regulator_id'] : '');
            $out[$tok] = $r['assigned_user_id'];
        }
        return $out;
    }

    /** Name the person accountable for one requirement (null clears it). */
    public function assign(string $holderId, string $token, ?string $userId): void
    {
        [$code, $regulatorId] = self::parse($token);
        if ($code === '') return;
        $sql = "UPDATE producer_requirements SET assigned_user_id = ?
                 WHERE tenant_id = ? AND holder_id = ? AND catalog_code = ? AND "
             . ($regulatorId === null ? "regulator_id IS NULL" : "regulator_id = ?");
        $args = [$userId, $this->ctx->tenantId, $holderId, $code];
        if ($regulatorId !== null) $args[] = $regulatorId;
        Database::pdo()->prepare($sql)->execute($args);
    }

    public function tokensFor(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT catalog_code, regulator_id FROM producer_requirements WHERE tenant_id = ? AND holder_id = ?{$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $holderId], $this->scopeSql()[1]));
        return array_map(fn($r) => self::token($r['catalog_code'], $r['regulator_id']), $stmt->fetchAll());
    }

    /** Replace the full assignment set for a producer from UI tokens. */
    public function setTokens(string $holderId, array $tokens, array $customRegulatorMap = []): void
    {
        $pdo = Database::pdo();

        // Who currently owns each requirement? setTokens rewrites the whole set (a
        // template re-apply does exactly that), and losing the accountable person every
        // time would make ownership useless. Carry the assignments across.
        $prev = [];
        $cur = $pdo->prepare(
            "SELECT catalog_code, regulator_id, assigned_user_id FROM producer_requirements
              WHERE tenant_id = ? AND holder_id = ? AND assigned_user_id IS NOT NULL"
        );
        $cur->execute([$this->ctx->tenantId, $holderId]);
        foreach ($cur->fetchAll() ?: [] as $r) {
            $prev[$r['catalog_code'] . '|' . (string) $r['regulator_id']] = $r['assigned_user_id'];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM producer_requirements WHERE tenant_id = ? AND holder_id = ?")
                ->execute([$this->ctx->tenantId, $holderId]);
            $ins = $pdo->prepare(
                "INSERT IGNORE INTO producer_requirements (id, tenant_id, holder_id, catalog_code, regulator_id, assigned_user_id, created_at)
                 VALUES (?,?,?,?,?,?,?)"
            );
            foreach (array_unique(array_filter(array_map('strval', $tokens))) as $tok) {
                [$code, $regulatorId] = self::parse($tok);
                if ($code === '') continue;
                if (str_starts_with($code, 'cr:')) {
                    $regulatorId = $customRegulatorMap[substr($code, 3)] ?? null;   // regulator of the custom requirement
                }
                $owner = $prev[$code . '|' . (string) $regulatorId] ?? null;
                $ins->execute([uuid4(), $this->ctx->tenantId, $holderId, $code, $regulatorId, $owner, utc_now()]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** holder_id => [tokens] for every producer. */
    public function map(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT holder_id, catalog_code, regulator_id FROM producer_requirements WHERE tenant_id = ?{$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql()[1]));
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[$r['holder_id']][] = self::token($r['catalog_code'], $r['regulator_id']); }
        return $out;
    }
}
