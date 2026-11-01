<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Unified Compliance Risk Score.
 *
 * Turns the live compliance engine (flx_compliance_data) into a single transparent 0-100 score +
 * risk band per provider, plus an organization rollup. Transparent by design — a compliance tool
 * must show *why*, so every score ships with the concrete gaps behind it.
 *
 * Each provider's score comes straight from the compliance engine's graded score
 * (active credential = 1 point, expiring = 1/2, expired/missing = 0, over the provider's applicable
 * requirement rows). Gaps are the non-active rows. This is the same data the compliance dashboard
 * and readiness panels use, so Reports now agrees with the rest of the app instead of scoring
 * everyone 100% against an empty legacy requirement set.
 *
 * Band:  >= 85 low · 65-84 medium · < 65 high   (any expired required row floors the band at medium)
 */
final class RiskScoreService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /**
     * Score one provider from its compliance-engine entry (an element of
     * flx_compliance_data()['producers']). Returns the transparent score packet.
     */
    public function scoreFromCompliance(array $producer): array
    {
        $rows = $producer['rows'] ?? [];
        $total = (int) ($producer['total'] ?? count($rows));

        // The engine already computed a graded score; when there are no applicable rows it is null,
        // which we treat as "nothing to assess" -> not a real 100.
        $score = $producer['score'] ?? null;
        $assessed = $total > 0 && $score !== null;
        $score = $assessed ? (int) $score : null;

        $tally = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'missing' => 0];
        $gaps = [];
        foreach ($rows as $r) {
            $st = $r['status'] ?? 'missing';
            if (isset($tally[$st])) $tally[$st]++;
            if (in_array($st, ['expired', 'missing', 'expiring'], true)) {
                $gaps[] = [
                    'title'     => $r['label'] ?? $r['name'] ?? $r['authority'] ?? 'Requirement',
                    'state'     => $st,
                    'detail'    => self::gapDetail($r),
                    'authority' => $r['authority'] ?? '',
                    'regulator' => $r['regulator'] ?? null,
                ];
            }
        }

        $band = self::band($score, $tally);

        // worst-first: expired, then missing, then expiring
        $order = ['expired' => 0, 'missing' => 1, 'expiring' => 2];
        usort($gaps, fn($a, $b) => ($order[$a['state']] ?? 9) <=> ($order[$b['state']] ?? 9));

        return [
            'score'     => $score,          // int, or null when nothing is assessable
            'assessed'  => $assessed,
            'band'      => $band,
            'required'  => $total,
            'tally'     => $tally,
            'gaps'      => $gaps,
        ];
    }

    /**
     * Organization rollup from the full compliance producer set.
     * @param array<int,array> $producers  flx_compliance_data()['producers']
     */
    public function rollupFromCompliance(array $producers): array
    {
        $perHolder = [];
        $sum = 0; $n = 0; $highRisk = 0; $unassessed = 0;
        foreach ($producers as $p) {
            $h = $p['holder'];
            $s = $this->scoreFromCompliance($p);
            $name = ($h['holder_type'] ?? '') === 'organization'
                ? ($h['org_name'] ?: 'Organization')
                : trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));
            $perHolder[] = [
                'holder' => $h, 'name' => $name,
                'score'  => $s['score'], 'assessed' => $s['assessed'],
                'band'   => $s['band'], 'gaps' => count($s['gaps']),
            ];
            if ($s['assessed']) { $sum += (int) $s['score']; $n++; if ($s['band'] === 'high') $highRisk++; }
            else { $unassessed++; }
        }
        // worst first; unassessed (null score) sink to the bottom
        usort($perHolder, function ($a, $b) {
            if ($a['score'] === null && $b['score'] === null) return 0;
            if ($a['score'] === null) return 1;
            if ($b['score'] === null) return -1;
            return $a['score'] <=> $b['score'];
        });

        return [
            'average'    => $n ? (int) round($sum / $n) : null,
            'holders'    => $perHolder,
            'high_risk'  => $highRisk,
            'count'      => $n,
            'unassessed' => $unassessed,
        ];
    }

    private static function band(?int $score, array $tally): string
    {
        if ($score === null) return 'none';
        $band = $score >= 85 ? 'low' : ($score >= 65 ? 'medium' : 'high');
        if (($tally['expired'] ?? 0) > 0 && $band === 'low') $band = 'medium';
        return $band;
    }

    private static function gapDetail(array $row): string
    {
        $st = $row['status'] ?? 'missing';
        return match ($st) {
            'expired'  => 'Expired' . (!empty($row['expiry']) ? ' ' . substr((string) $row['expiry'], 0, 10) : ''),
            'expiring' => 'Expires' . (!empty($row['expiry']) ? ' ' . substr((string) $row['expiry'], 0, 10) : ' soon'),
            'missing'  => 'No credential on file',
            default    => ucfirst((string) $st),
        };
    }

    public static function bandColor(string $band): string
    {
        return ['low' => '#2f7d5b', 'medium' => '#c77b21', 'high' => '#be4b42', 'none' => '#6b7280'][$band] ?? '#6b7280';
    }

    public static function bandLabel(string $band): string
    {
        return ['low' => 'Low risk', 'medium' => 'Medium risk', 'high' => 'High risk', 'none' => 'Not assessed'][$band] ?? $band;
    }
}
