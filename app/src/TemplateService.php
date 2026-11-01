<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Repository\ProducerRequirementRepository;

/**
 * Compliance templates — reusable requirement bundles anchored to a licence type.
 *
 * The chain the whole product hangs off:
 *   Organization → Producer (holds a licence) → Template → Requirements
 *                → Credentials (+ evidence) → Score → Tasks
 *
 * A template is a named set of requirement tokens (the same grammar used everywhere
 * else: `code`, `code@carrierId`, `cr:<id>`). Applying one writes those tokens onto a
 * producer. The producer keeps a pointer to the template so drift is visible when an
 * agency edits the template later or hand-tweaks an individual.
 */
final class TemplateService
{
    public function __construct(private TenantContext $ctx) {}

    /** @return array<int,array> templates for this tenant, optionally filtered by role/lines */
    public function all(?string $appliesTo = null, ?array $lines = null, ?array $states = null): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT t.*, (SELECT COUNT(*) FROM requirement_template_items i WHERE i.template_id = t.id) AS item_count
               FROM requirement_templates t
              WHERE t.tenant_id = ?
              ORDER BY t.sort, t.name"
        );
        $stmt->execute([$this->ctx->tenantId]);
        $rows = $stmt->fetchAll() ?: [];

        if ($appliesTo !== null) {
            $rows = array_values(array_filter($rows, fn($r) => $r['applies_to'] === $appliesTo));
        }
        if ($lines !== null) {
            $rows = array_values(array_filter($rows,
                fn($r) => $r['line'] === 'all' || in_array($r['line'], $lines, true)));
        }
        // A Florida template is noise for a Georgia-only agency. NULL state = anywhere.
        if ($states !== null) {
            $rows = array_values(array_filter($rows,
                fn($r) => empty($r['state']) || in_array(strtoupper((string)$r['state']), $states, true)));
        }
        return $rows;
    }

    public function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM requirement_templates WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$id, $this->ctx->tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return string[] tokens belonging to a template */
    public function tokens(string $templateId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT token FROM requirement_template_items
              WHERE template_id = ? AND tenant_id = ? ORDER BY token"
        );
        $stmt->execute([$templateId, $this->ctx->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /** Create or update a template plus its item list in one call. */
    public function save(?string $id, array $in, array $tokens): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return [false, 'Give the template a name.', null];

        // Validate against THIS vertical's allowed values, not a hardcoded insurance list — so a
        // healthcare org can create physician/lab templates and an insurance org keeps agent/fmo.
        $validApplies = array_keys(Vocab::appliesToOptions($this->ctx->vertical));
        $validLines   = array_keys(Vocab::lineOptions($this->ctx->vertical));
        $appliesTo = in_array($in['applies_to'] ?? '', $validApplies, true) ? $in['applies_to'] : ($validApplies[0] ?? 'agent');
        $line      = in_array($in['line'] ?? '', $validLines, true) ? $in['line'] : 'all';
        $license   = trim((string) ($in['license_code'] ?? '')) ?: null;
        $stateIn   = strtoupper(trim((string) ($in['state'] ?? '')));
        $state     = preg_match('/^[A-Z]{2}$/', $stateIn) ? $stateIn : null;
        $descr     = trim((string) ($in['description'] ?? '')) ?: null;
        // Optional regulator link ("template belongs to CMS / HIPAA / Aetna / a state board...").
        $regId = trim((string) ($in['regulator_id'] ?? '')) ?: null;
        if ($regId !== null) {
            $chk = Database::pdo()->prepare('SELECT id FROM regulators WHERE id = ? AND tenant_id = ?');
            $chk->execute([$regId, $this->ctx->tenantId]);
            if ($chk->fetchColumn() === false) $regId = null;
        }

        $pdo = Database::pdo();
        if ($id === null) {
            $id = uuid4();
            $pdo->prepare(
                "INSERT INTO requirement_templates
                    (id, tenant_id, name, description, applies_to, line, regulator_id, state, license_code, is_seed, sort, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?,?)"
            )->execute([$id, $this->ctx->tenantId, $name, $descr, $appliesTo, $line, $regId, $state, $license,
                (int) ($in['sort'] ?? 100), utc_now()]);
        } else {
            if (!$this->find($id)) return [false, 'Template not found.', null];
            $pdo->prepare(
                "UPDATE requirement_templates
                    SET name = ?, description = ?, applies_to = ?, line = ?, regulator_id = ?, state = ?, license_code = ?
                  WHERE id = ? AND tenant_id = ?"
            )->execute([$name, $descr, $appliesTo, $line, $regId, $state, $license, $id, $this->ctx->tenantId]);
        }

        // Replace the item list wholesale — simplest correct semantics for an editor.
        $pdo->prepare("DELETE FROM requirement_template_items WHERE template_id = ? AND tenant_id = ?")
            ->execute([$id, $this->ctx->tenantId]);
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO requirement_template_items (id, tenant_id, template_id, token, created_at)
             VALUES (?,?,?,?,?)"
        );
        foreach (array_unique(array_filter(array_map('trim', $tokens))) as $tok) {
            $ins->execute([uuid4(), $this->ctx->tenantId, $id, $tok, utc_now()]);
        }
        return [true, 'Template saved.', $id];
    }

    public function delete(string $id): bool
    {
        if (!$this->find($id)) return false;
        $pdo = Database::pdo();
        $pdo->prepare("DELETE FROM requirement_template_items WHERE template_id = ? AND tenant_id = ?")
            ->execute([$id, $this->ctx->tenantId]);
        $pdo->prepare("UPDATE credential_holders SET template_id = NULL WHERE template_id = ? AND tenant_id = ?")
            ->execute([$id, $this->ctx->tenantId]);
        $pdo->prepare("DELETE FROM requirement_templates WHERE id = ? AND tenant_id = ?")
            ->execute([$id, $this->ctx->tenantId]);
        return true;
    }

    /**
     * Apply a template to a producer.
     *   replace — the template becomes the producer's requirement set (default)
     *   merge   — template items are added, anything already assigned is kept
     * Records which template governs the producer, so drift can be shown later.
     */
    /**
     * Auto-apply the template that matches a provider's profession. This is what makes "pick a
     * profession → its required credentials appear" work without a manual step. Called on create,
     * edit (when provider_type changes), import, and onboarding.
     *
     * @param bool $only_if_empty  when true (default for edits), don't overwrite requirements the
     *                             provider already has — only seed when they have none yet, so a
     *                             tenant's manual tweaks are never clobbered.
     * @return array{0:bool,1:string}
     */
    public function applyByProviderType(string $holderId, ?string $providerType, ProducerRequirementRepository $reqs, bool $only_if_empty = true): array
    {
        $providerType = trim((string) $providerType);
        if ($providerType === '') return [false, 'No profession set.'];
        if ($only_if_empty && $reqs->tokensFor($holderId) !== []) return [false, 'Requirements already assigned.'];

        // Match a template whose applies_to equals the provider_type (Physician→physician, etc.).
        $st = Database::pdo()->prepare(
            "SELECT id FROM requirement_templates WHERE tenant_id = ? AND applies_to = ? ORDER BY is_seed ASC, sort ASC LIMIT 1"
        );
        $st->execute([$this->ctx->tenantId, $providerType]);
        $tid = $st->fetchColumn();
        if (!$tid) return [false, 'No template for profession "' . $providerType . '".'];

        return $this->applyTo($holderId, (string) $tid, $reqs, $only_if_empty ? 'replace' : 'merge');
    }

    public function applyTo(string $holderId, string $templateId, ProducerRequirementRepository $reqs, string $mode = 'replace'): array
    {
        $tpl = $this->find($templateId);
        if (!$tpl) return [false, 'Template not found.'];

        $tokens = $this->tokens($templateId);
        if ($mode === 'merge') {
            $tokens = array_values(array_unique(array_merge($reqs->tokensFor($holderId), $tokens)));
        }
        $reqs->setTokens($holderId, $tokens);

        Database::pdo()->prepare(
            "UPDATE credential_holders SET template_id = ?, license_code = COALESCE(license_code, ?)
              WHERE id = ? AND tenant_id = ?"
        )->execute([$templateId, $tpl['license_code'], $holderId, $this->ctx->tenantId]);

        return [true, 'Applied "' . $tpl['name'] . '" — ' . count($tokens) . ' requirements assigned.'];
    }

    /**
     * How a producer's actual requirements differ from the template they are on.
     * @return array{extra:string[], missing:string[]}
     */
    public function drift(string $holderId, string $templateId, ProducerRequirementRepository $reqs): array
    {
        $tpl  = $this->tokens($templateId);
        $have = $reqs->tokensFor($holderId);
        return [
            'extra'   => array_values(array_diff($have, $tpl)),   // added beyond the template
            'missing' => array_values(array_diff($tpl, $have)),   // template items not assigned
        ];
    }

    /** Templates suggested for a producer, best match first (licence, then role/line). */
    public function suggestFor(array $holder, string $role, ?array $lines = null, ?array $states = null): array
    {
        $all = $this->all($role, $lines, $states);
        $lic = (string) ($holder['license_code'] ?? '');
        if ($lic !== '') {
            usort($all, fn($a, $b) => (int) ($b['license_code'] === $lic) <=> (int) ($a['license_code'] === $lic));
        }
        return $all;
    }
}
