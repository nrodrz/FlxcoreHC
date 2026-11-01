<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * The unified credential checklist for one provider — the heart of the app, presented as a single
 * list instead of two parallel "requirements" and "credentials" screens.
 *
 * Each row is a REQUIRED credential for this provider's profession, showing whether it is satisfied
 * (a matching document is on file, with its status and expiry) or still needed. Extra credentials the
 * provider holds beyond their requirements are listed after, so nothing is hidden. This merges the
 * old requirement/credential split into one "what does this provider need, and do we have it?" view.
 */
final class ChecklistService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /**
     * @return array{
     *   rows: array<int,array{token:string,label:string,meta:string,status:string,
     *          credential_id:string,expiry:?string,source:?array,is_extra:bool}>,
     *   done:int, total:int, pct:int, missing:int, expiring:int, expired:int,
     *   provider_type:?string, has_template:bool
     * }
     */
    public function forProvider(array $holder): array
    {
        $hid = (string)$holder['id'];
        $creds = (new Repository\CredentialRepository($this->ctx))->all(['holder_id' => $hid], 'expiry_date ASC', 500);
        $required = (new Repository\ProducerRequirementRepository($this->ctx))->tokensFor($hid);
        $cov = CatalogService::coverageDetail($creds);
        $cat = [];
        foreach (CatalogService::all(null, null, null, $this->ctx->tenantId) as $c) $cat[$c['code']] = $c;

        $rows = []; $done = 0; $expiring = 0; $expired = 0; $missing = 0;
        $seenCredIds = [];
        $__ceMonths = flx_ce_months(OrgService::statesFor($this->ctx->tenantId));   // v1.171.5: the CE cycle shown here must match the CE panel (PR = 36)

        // 1) One row per REQUIRED item, satisfied or not.
        foreach ($required as $tok) {
            $c = $cov[$tok] ?? null;
            $def = $cat[$tok] ?? null;
            $label = $def['name'] ?? ($c['credential_name'] ?? $tok);
            $__rm  = $def ? (int)($def['renewal_months'] ?? 0) : 0;
            if (in_array($tok, ['cme', 'pr_cme'], true)) $__rm = $__ceMonths;
            $meta  = $def ? trim((string)($def['authority'] ?? '') . ($__rm ? ' · renews every ' . $__rm . ' mo' : '')) : '';
            $status = $c['status'] ?? 'missing';
            if ($status === 'active')   $done++;
            elseif ($status === 'expiring') { $expiring++; }
            elseif ($status === 'expired')  { $expired++; }
            else { $missing++; $status = 'missing'; }
            if ($c) $seenCredIds[$c['credential_id']] = true;
            $rows[] = [
                'token' => $tok, 'label' => $label, 'meta' => $meta,
                'status' => $status,
                'credential_id' => (string)($c['credential_id'] ?? ''),
                'expiry' => $c['expiry_date'] ?? null,
                'source' => $def && !empty($def['source_code']) ? RegulatorySources::byCode($def['source_code']) : null,
                'is_extra' => false,
                'add_type' => $tok,
            ];
        }

        // 2) Extra credentials the provider holds that aren't in their required set — shown, not hidden.
        foreach ($creds as $c) {
            if (isset($seenCredIds[$c['id']])) continue;
            $st = (string)($c['status'] ?? 'active');
            if (!in_array($st, ['active','expiring','expired'], true)) $st = 'active';
            if ($st === 'expiring') $expiring++; elseif ($st === 'expired') $expired++;
            $rows[] = [
                'token' => (string)$c['credential_type'], 'label' => (string)($c['name'] ?: $c['credential_type']),
                'meta' => (string)($c['issuing_body'] ?? ''), 'status' => $st,
                'credential_id' => (string)$c['id'], 'expiry' => $c['expiry_date'] ?? null,
                'source' => null, 'is_extra' => true, 'add_type' => '',
            ];
        }

        // Order: problems first (missing, expired, expiring), then active, then extras.
        $ord = ['missing' => 0, 'expired' => 1, 'expiring' => 2, 'active' => 3];
        usort($rows, fn($a, $b) => [$a['is_extra'], $ord[$a['status']] ?? 9, $a['label']]
                                 <=> [$b['is_extra'], $ord[$b['status']] ?? 9, $b['label']]);

        $total = count($required);
        return [
            'rows' => $rows, 'done' => $done, 'total' => $total,
            'pct' => $total ? (int)round(100 * $done / $total) : 0,
            'missing' => $missing, 'expiring' => $expiring, 'expired' => $expired,
            'provider_type' => $holder['provider_type'] ?? null,
            'has_template' => !empty($holder['template_id']) || $total > 0,
        ];
    }
}
