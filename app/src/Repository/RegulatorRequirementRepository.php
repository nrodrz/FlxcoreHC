<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/**
 * The requirements each regulator imposes. kind='credential' rows link to the credential
 * catalog via catalog_code; kind='document' rows are free-named document obligations
 * (HIPAA attestation, OSHA training certificate, COI, W-9...).
 */
final class RegulatorRequirementRepository extends TenantRepository
{
    protected function table(): string { return 'regulator_requirements'; }
    protected function fillable(): array { return ['regulator_id', 'kind', 'catalog_code', 'name', 'category', 'renewal_months', 'notes']; }

    /**
     * Whether the `regulator_requirements` table exists in this database. Cached per request.
     * The table is referenced by this repository and by flx_compliance_data(), but no migration
     * creates it (see docs/architecture/DEPENDENCY_MAP.md / DUPLICATION_REPORT.md P1-4). On a database
     * where it is absent, the read paths below degrade to "no regulator requirements" instead of
     * throwing a PDOException that would take down every compliance view. Where the table DOES exist,
     * this check returns true and behavior is unchanged.
     */
    private static ?bool $tableExists = null;
    /** Public probe: is the regulator_requirements table present? Used by the write route to fail cleanly. */
    public function tableReady(): bool { return $this->hasTable(); }

    private function hasTable(): bool
    {
        if (self::$tableExists !== null) return self::$tableExists;
        try {
            $q = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'regulator_requirements'"
            );
            self::$tableExists = ((int) $q->fetchColumn()) > 0;
        } catch (\Throwable $e) { self::$tableExists = false; }
        return self::$tableExists;
    }

    public function forRegulator(string $regulatorId): array
    {
        if (!$this->hasTable()) return [];
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM regulator_requirements WHERE tenant_id = ? AND regulator_id = ? ORDER BY kind, name"
        );
        $stmt->execute([$this->ctx->tenantId, $regulatorId]);
        return $stmt->fetchAll();
    }

    /** regulator_id => [requirement rows] for the whole tenant. */
    public function byRegulator(): array
    {
        if (!$this->hasTable()) return [];
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM regulator_requirements WHERE tenant_id = ? ORDER BY name"
        );
        $stmt->execute([$this->ctx->tenantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[$r['regulator_id']][] = $r; }
        return $out;
    }

    /** id => row map for token resolution. */
    public function mapById(): array
    {
        if (!$this->hasTable()) return [];
        $stmt = Database::pdo()->prepare("SELECT * FROM regulator_requirements WHERE tenant_id = ?");
        $stmt->execute([$this->ctx->tenantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[$r['id']] = $r; }
        return $out;
    }
}
