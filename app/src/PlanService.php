<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Subscription plans: seat limits + included features per tenant.
 *
 * Plan gating is a PRODUCT-tier concept, not a security boundary — it fails open
 * (if a plan can't be resolved, features are allowed) so a mis-set plan never locks
 * a customer out of their data. Seat limits and feature visibility are enforced on
 * top of the real tenant isolation, which is unchanged.
 */
final class PlanService
{
    /** Canonical feature catalog (code => label). Core areas are always available. */
    public const FEATURES = [
        'documents'    => 'Document management',
        'analytics'    => 'Analytics & reports',
    ];

    /** Resolve a tenant's effective plan (assigned, else the default). */
    /**
     * Resolve a tenant's plan. Precedence:
     *   1. plan_id — an explicit subscription (set by Stripe checkout or an admin)
     *   2. plan_tier — the tier chosen at sign-up (solo / agency / fmo / starter)
     *   3. the default plan
     * Step 2 matters: before this, a tenant that had never paid fell straight through
     * to the default plan, so a Starter signup silently received Agency entitlements.
     */
    public static function forTenant(string $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT p.*, t.plan_tier FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id
             WHERE t.id = ? LIMIT 1"
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['id'])) return $row;

        $tier = strtolower(trim((string)($row['plan_tier'] ?? '')));
        if ($tier !== '') {
            $byTier = Database::pdo()->prepare(
                "SELECT * FROM plans WHERE active = 1 AND LOWER(name) = ? LIMIT 1"
            );
            $byTier->execute([$tier]);
            $plan = $byTier->fetch();
            if ($plan !== false) return $plan;
        }
        return self::defaultPlan();
    }

    public static function defaultPlan(): ?array
    {
        $row = Database::pdo()->query(
            "SELECT * FROM plans WHERE is_default = 1 AND active = 1 ORDER BY sort_order LIMIT 1"
        )->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Feature codes enabled for a tenant — from the entitlement check (a signed license, a trial, or the core only), no longer from the plan
     * alone and no longer open when nothing is set (v1.175.0).
     */
    public static function featuresFor(string $tenantId): array
    {
        return Entitlement::forTenant($tenantId)['modules'];
    }

    public static function tenantHasFeature(string $tenantId, string $code): bool
    {
        return in_array($code, self::featuresFor($tenantId), true);
    }

    /** Seats: active + pending(invited) users count against the limit. */
    /**
     * Billable seats. Producer self-service logins are counted or not depending on the
     * 'producer_seats_billable' config flag — a pricing lever, not a technical one:
     *   true  (default) — every producer login consumes a seat (per-seat pricing)
     *   false           — producer logins are free; only staff/manager/owner seats count
     *                     (agency-tier pricing, where you charge for the back office)
     */
    public static function seatCount(string $tenantId): int
    {
        $billableProducers = (bool) config('producer_seats_billable', false);
        $sql = "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status IN ('active','invited')";
        if (!$billableProducers) {
            $sql .= " AND role <> 'producer'";
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$tenantId]);
        return (int) $stmt->fetchColumn();
    }

    public static function seatLimit(string $tenantId): ?int
    {
        // A per-tenant override (a custom seat grant for this customer) wins over the plan's limit.
        $ov = Database::pdo()->prepare("SELECT seat_override FROM tenants WHERE id = ? LIMIT 1");
        $ov->execute([$tenantId]);
        $override = $ov->fetchColumn();
        if ($override !== null && $override !== false && (int) $override > 0) return (int) $override;

        $ent = Entitlement::forTenant($tenantId);   // v1.175.0: a licensed organization's user limit is the license's
        if (in_array($ent['state'], ['licensed', 'grace'], true)) return $ent['users'];
        $plan = self::forTenant($tenantId);
        if (!$plan || $plan['max_users'] === null) return null; // unlimited
        return (int) $plan['max_users'];
    }

    public static function canAddUser(string $tenantId): bool
    {
        $limit = self::seatLimit($tenantId);
        return $limit === null || self::seatCount($tenantId) < $limit;
    }

    private static function countHolders(string $tenantId): int
    {
        $q = Database::pdo()->prepare("SELECT COUNT(*) FROM credential_holders WHERE tenant_id = ? AND deleted_at IS NULL");
        $q->execute([$tenantId]); return (int) $q->fetchColumn();
    }
    /** Live document storage used by a tenant, in MB (legacy rows without size count 0). */
    public static function storageUsedMb(string $tenantId): float
    {
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(size_bytes),0) FROM credential_documents WHERE tenant_id = ? AND deleted_at IS NULL');
        $st->execute([$tenantId]);
        return round(((float) $st->fetchColumn()) / 1048576, 2);
    }

    /** May this tenant store $addBytes more of documents under its plan quota? */
    public static function canStoreFile(string $tenantId, int $addBytes): bool
    {
        $plan = self::forTenant($tenantId);
        $cap = $plan['max_storage_mb'] ?? null;
        if ($cap === null || (int)$cap <= 0) return true; // unlimited
        return (self::storageUsedMb($tenantId) + $addBytes / 1048576) <= (float) $cap;
    }

    public static function canAddHolder(string $tenantId): bool
    {
        $plan = self::forTenant($tenantId);
        $cap = $plan['max_holders'] ?? null;
        return $cap === null || self::countHolders($tenantId) < (int) $cap;
    }
    /** Usage snapshot for billing/profile display. */
    public static function usage(string $tenantId): array
    {
        $plan = self::forTenant($tenantId);
        return [
            'plan'      => $plan,
            'seats'     => ['used' => self::seatCount($tenantId),    'limit' => self::seatLimit($tenantId)],
            'holders'   => ['used' => self::countHolders($tenantId), 'limit' => $plan['max_holders'] ?? null],
            'storage_mb'=> ['used' => self::storageUsedMb($tenantId), 'limit' => $plan['max_storage_mb'] ?? null],
            'producers' => self::producerBilling($tenantId, $plan),
        ];
    }

    /** Count producer portal logins (active + invited) for a tenant. */
    public static function producerSeatCount(string $tenantId): int
    {
        $st = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'producer' AND status IN ('active','invited')"
        );
        $st->execute([$tenantId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Producer-seat billing for a plan: how many producer logins, how many are within the free
     * allowance, how many are billable, the per-seat price, and the resulting monthly charge.
     */
    public static function producerBilling(string $tenantId, ?array $plan = null): array
    {
        $plan ??= self::forTenant($tenantId);
        $count    = self::producerSeatCount($tenantId);
        $price    = ($plan['producer_price'] ?? null) !== null ? (float) $plan['producer_price'] : 0.0;
        $included = (int) ($plan['producer_included'] ?? 0);
        $billable = $price > 0 ? max(0, $count - $included) : 0;
        return [
            'count'    => $count,
            'included' => $included,
            'price'    => $price,
            'billable' => $billable,
            'monthly'  => round($billable * $price, 2),
            'free'     => $price <= 0,
        ];
    }

    // ---- platform-admin management ----

    public static function all(): array
    {
        return Database::pdo()->query(
            "SELECT p.*, (SELECT COUNT(*) FROM tenants t WHERE t.plan_id = p.id) AS tenants
             FROM plans p ORDER BY p.sort_order, p.price_monthly"
        )->fetchAll();
    }

    public static function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    public static function save(array $in, ?string $id = null): array
    {
        $name  = trim((string) ($in['name'] ?? ''));
        $code  = strtolower(trim((string) ($in['code'] ?? '')));
        if ($name === '' || !preg_match('/^[a-z0-9_]+$/', $code)) {
            return [false, 'A name and a code (letters, numbers, underscore) are required.'];
        }
        $price = ($in['price_monthly'] ?? '') === '' ? null : (float) $in['price_monthly'];
        $maxU  = ($in['max_users'] ?? '') === '' ? null : max(1, (int) $in['max_users']);
        $maxH  = ($in['max_holders'] ?? '') === '' ? null : max(0, (int) $in['max_holders']);
        $maxS  = ($in['max_storage_mb'] ?? '') === '' ? null : max(0, (int) $in['max_storage_mb']);
        $price_id = trim((string) ($in['stripe_price_id'] ?? '')) ?: null;
        $prodPrice = ($in['producer_price'] ?? '') === '' ? null : max(0, (float) $in['producer_price']);
        $prodIncl  = max(0, (int) ($in['producer_included'] ?? 0));
        $feats = array_values(array_intersect((array) ($in['features'] ?? []), array_keys(self::FEATURES)));
        $featJson = json_encode($feats);

        if ($id) {
            Database::pdo()->prepare(
                "UPDATE plans SET name=?, code=?, price_monthly=?, max_users=?, max_holders=?, max_storage_mb=?, producer_price=?, producer_included=?, stripe_price_id=?, features=?, active=? WHERE id=?"
            )->execute([$name, $code, $price, $maxU, $maxH, $maxS, $prodPrice, $prodIncl, $price_id, $featJson, empty($in['active']) ? 0 : 1, $id]);
            return [true, 'Plan updated.'];
        }
        $dupe = Database::pdo()->prepare("SELECT 1 FROM plans WHERE code = ? LIMIT 1");
        $dupe->execute([$code]);
        if ($dupe->fetchColumn()) return [false, 'A plan with that code already exists.'];
        Database::pdo()->prepare(
            "INSERT INTO plans (id, code, name, price_monthly, max_users, max_holders, max_storage_mb, producer_price, producer_included, stripe_price_id, features, is_default, active, sort_order, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,0,1,50,?)"
        )->execute([uuid4(), $code, $name, $price, $maxU, $maxH, $maxS, $prodPrice, $prodIncl, $price_id, $featJson, utc_now()]);
        return [true, 'Plan created.'];
    }

    public static function assign(string $tenantId, string $planId): array
    {
        if ($planId === '') { // clear -> default
            Database::pdo()->prepare("UPDATE tenants SET plan_id = NULL WHERE id = ?")->execute([$tenantId]);
            return [true, 'Plan reset to default.'];
        }
        if (!self::find($planId)) return [false, 'Plan not found.'];
        Database::pdo()->prepare("UPDATE tenants SET plan_id = ? WHERE id = ?")->execute([$planId, $tenantId]);
        return [true, 'Plan assigned.'];
    }
}
