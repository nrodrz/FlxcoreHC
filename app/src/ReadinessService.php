<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Provider readiness, decomposed into the named sub-scores from the product strategy (§7):
 * Credential, Payer, Enrollment, Documentation, Renewal Risk, and Audit Evidence. Each is a
 * management-oriented readiness lens on data the app already holds — never a legal-compliance
 * claim. A dimension returns null when it doesn't apply (e.g. no payer regulators assigned), so
 * the UI can show "N/A" rather than a misleading 0.
 *
 * Inputs are the compliance engine's per-provider entry ($mine: rows[], score) plus the
 * provider's applications, verifications, latest attestation, and credential rows.
 */
final class ReadinessService
{
    /**
     * @param array      $mine         one producer entry from flx_compliance_data (rows[], score, pct)
     * @param array      $applications rows from ApplicationRepository::forHolder()
     * @param array      $verifications rows from VerificationRepository::forHolder()
     * @param array|null $attestation  latest attestation row, or null
     * @param array      $creds        credential rows for the provider (each may carry 'evidence' count)
     * @param array      $docCounts    credential_id => document count
     * @return array{overall:?int, summary:string, scores:array<string,array{score:?int,label:string,detail:string}>}
     */
    public static function forProvider(array $mine, array $applications, array $verifications,
                                       ?array $attestation, array $creds, array $docCounts,
                                       ?array $screening = null): array
    {
        $rows = $mine['rows'] ?? [];

        // ---- Credential readiness: graded status over required credentials -------------
        [$credScore, $credDetail] = self::graded($rows);

        // ---- Payer readiness: graded status over payer-scoped requirement rows ---------
        $payerRows = array_values(array_filter($rows, fn($r) => ($r['category'] ?? '') === 'payer'
            || ($r['authority'] ?? '') === 'regulator' && !empty($r['regulator'])));
        // Narrow to rows attributable to a payer regulator where category signals it; if none of
        // the provider's rows are payer-attributable, the dimension is N/A.
        $payerScoped = array_values(array_filter($rows, fn($r) => !empty($r['regulator'])));
        [$payerScore, $payerDetail] = $payerScoped === []
            ? [null, 'No payer/regulator requirements assigned']
            : self::graded($payerScoped);

        // ---- Enrollment readiness: approved share of live applications -----------------
        $live = array_values(array_filter($applications, fn($a) => ($a['status'] ?? '') !== 'withdrawn'));
        if ($live === []) {
            $enrollScore = null; $enrollDetail = t('No enrollment/credentialing applications');
        } else {
            $approved = count(array_filter($live, fn($a) => ($a['status'] ?? '') === 'approved'));
            $open = count(array_filter($live, fn($a) => in_array($a['status'] ?? '', ['not_started','in_progress','submitted','under_review'], true)));
            $enrollScore = (int) round($approved * 100 / count($live));
            $enrollDetail = $open > 0
                ? t('{a} of {n} approved, {open} still open', ['a' => $approved, 'n' => count($live), 'open' => $open])
                : t('{a} of {n} approved', ['a' => $approved, 'n' => count($live)]);
        }

        // ---- Documentation completeness: held credentials with evidence attached -------
        $held = array_values(array_filter($creds, fn($c) => in_array((string)($c['status'] ?? 'active'), ['active','expiring','expired'], true)));
        if ($held === []) {
            $docScore = null; $docDetail = t('No credentials on file');
        } else {
            $withDocs = 0;
            foreach ($held as $c) { if ((int)($docCounts[$c['id']] ?? 0) > 0) $withDocs++; }
            $docScore = (int) round($withDocs * 100 / count($held));
            $docDetail = t('{a} of {n} credentials have evidence attached', ['a' => $withDocs, 'n' => count($held)]);
        }

        // ---- Renewal risk: readiness against near-term expiries (100 = nothing due) -----
        $expiring = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'expiring'));
        $expired  = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'expired'));
        $tot = count($rows);
        if ($tot === 0) {
            $renewScore = null; $renewDetail = t('No requirements to renew');
        } else {
            $renewScore = (int) round((1 - ($expiring * 0.5 + $expired) / $tot) * 100);
            $bits = [];
            if ($expiring) $bits[] = t('{n} expiring soon', ['n' => $expiring]);
            if ($expired)  $bits[] = t('{n} expired', ['n' => $expired]);
            $renewDetail = $bits === [] ? t('Nothing expiring soon') : implode(', ', $bits);
        }

        // ---- Audit evidence completeness: verified credentials + attestation on file ---
        if ($held === []) {
            $auditScore = null; $auditDetail = t('No credentials to verify');
        } else {
            $verifiedIds = [];
            foreach ($verifications as $v) {
                if (($v['result'] ?? '') === 'verified') $verifiedIds[$v['credential_id']] = true;
            }
            $verifiedHeld = 0;
            foreach ($held as $c) { if (isset($verifiedIds[$c['id']])) $verifiedHeld++; }
            $verShare = $verifiedHeld / count($held);
            $attOnFile = $attestation !== null;
            // Verification is the bulk of audit readiness; a current attestation adds the rest.
            $auditScore = (int) round(($verShare * 0.75 + ($attOnFile ? 0.25 : 0)) * 100);
            $auditDetail = t($attOnFile ? '{a} of {n} credentials verified, attestation on file' : '{a} of {n} credentials verified, no attestation on file',
                ['a' => $verifiedHeld, 'n' => count($held)]);
        }

        // ---- Exclusion screening: latest OIG/SAM result --------------------------------
        if ($screening === null) {
            $scrScore = null; $scrDetail = t('Not yet screened against exclusion lists');
        } else {
            $res = $screening['result'] ?? 'clear';
            $scrScore = $res === 'clear' ? 100 : ($res === 'potential_match' ? 40 : 0);
            $srcLabel = \FlxCoreHC\Repository\ScreeningRepository::SOURCES[$screening['source']] ?? $screening['source'];
            $resLabel = \FlxCoreHC\Repository\ScreeningRepository::RESULTS[$res] ?? $res;
            $scrDetail = $resLabel . ' — ' . $srcLabel . ', ' . substr((string)$screening['screened_on'], 0, 10);
        }

        $scores = [
            'credential'    => ['score' => $credScore,   'label' => 'Credential readiness',   'detail' => $credDetail],
            'payer'         => ['score' => $payerScore,  'label' => 'Payer readiness',        'detail' => $payerDetail],
            'enrollment'    => ['score' => $enrollScore, 'label' => 'Enrollment readiness',   'detail' => $enrollDetail],
            'documentation' => ['score' => $docScore,    'label' => 'Documentation',          'detail' => $docDetail],
            'renewal'       => ['score' => $renewScore,  'label' => 'Renewal risk',           'detail' => $renewDetail],
            'audit'         => ['score' => $auditScore,  'label' => 'Audit evidence',         'detail' => $auditDetail],
            'screening'     => ['score' => $scrScore,    'label' => 'Exclusion screening',    'detail' => $scrDetail],
        ];

        // ---- Overall: the compliance engine's graded score, with a plain-language summary
        $overall = $mine['score'] ?? $credScore;
        $summary = self::summarize($overall, $scores);

        return ['overall' => $overall, 'summary' => $summary, 'scores' => $scores];
    }

    /** Graded readiness over requirement rows: active=1, expiring=0.5, else 0. */
    private static function graded(array $rows): array
    {
        $n = count($rows);
        if ($n === 0) return [null, t('No requirements assigned')];
        $pts = 0.0; $active = 0; $missing = 0;
        foreach ($rows as $r) {
            $st = $r['status'] ?? 'missing';
            $pts += $st === 'active' ? 1 : ($st === 'expiring' ? 0.5 : 0);
            if ($st === 'active') $active++;
            if ($st === 'missing' || $st === 'expired') $missing++;
        }
        $detail = $missing > 0
            ? t('{a} of {n} met, {m} need attention', ['a' => $active, 'n' => $n, 'm' => $missing])
            : t('{a} of {n} met', ['a' => $active, 'n' => $n]);
        return [(int) round($pts * 100 / $n), $detail];
    }

    /** A one-line, doc-style summary highlighting the weakest applicable dimensions. */
    private static function summarize(?int $overall, array $scores): string
    {
        $weak = [];
        foreach ($scores as $s) {
            if ($s['score'] !== null && $s['score'] < 100) $weak[] = strtolower($s['label']) . ' (' . $s['detail'] . ')';
        }
        if ($weak === []) return $overall === null ? t('No requirements assigned yet.') : t('Fully ready — all tracked dimensions complete.');
        // Surface the two lowest-hanging items.
        usort($scores, fn($a, $b) => ($a['score'] ?? 101) <=> ($b['score'] ?? 101));
        $items = [];
        foreach ($scores as $s) {
            if ($s['score'] !== null && $s['score'] < 100) { $items[] = $s['detail']; if (count($items) === 2) break; }
        }
        return implode('; ', $items) . '.';
    }
}
