<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Builds a professional CV / Credential Summary for a single holder from the data on file:
 * identity, identifiers (NPI/NPN), and every credential grouped by category with issuing body,
 * number, dates, and evidence count. Pure data in, PDF string out — the caller fetches all tenant
 * data through repositories.
 *
 * This is the credential CV a billing company drops into a credentialing packet, not a full
 * biographical résumé: it documents what is licensed, certified, insured, and current, from the
 * system of record. Vertical-aware wording comes from the caller via the 'roleWord' key.
 */
final class CvService
{
    private const NAVY = [0.075, 0.145, 0.27];
    private const GOLD = [0.94, 0.70, 0.13];
    private const GRAY = [0.42, 0.46, 0.53];
    private const INK  = [0.13, 0.16, 0.22];
    private const OK   = [0.10, 0.52, 0.31];
    private const WARN = [0.72, 0.50, 0.04];
    private const BAD  = [0.76, 0.20, 0.20];

    /** Category display order + headings. Anything unknown falls into 'Other'. */
    private const SECTIONS = [
        'license'       => 'Licensure',
        'certification' => 'Certifications',
        'identifier'    => 'Identifiers',
        'insurance'     => 'Professional Liability & Insurance',
        'training'      => 'Continuing Education & Training',
        'background'    => 'Background & Conduct',
        'contract'      => 'Contracts & History',
        'other'         => 'Other Documents',
    ];

    /**
     * @param array $o keys:
     *   org (string), holderName (string), roleWord (string, e.g. 'Provider'),
     *   subtitle (string — e.g. 'Physician · NPI 1841293742'),
     *   email (?string), npi (?string),
     *   creds[] each: name, type, category, issuing_body, number, issued, expiry, status, docs (int),
     *   generatedBy (string)
     */
    public static function build(array $o): string
    {
        $pdf = new MiniPdf();
        $pdf->page();
        $M = 46.0; $R = MiniPdf::W - $M;

        // ---- Header ---------------------------------------------------------
        $pdf->rect(0, 0, MiniPdf::W, 96, self::NAVY);
        $pdf->rect(0, 96, MiniPdf::W, 3, self::GOLD);
        $pdf->text($M, 36, (string)$o['holderName'], 17, true, [1, 1, 1]);
        if (!empty($o['subtitle'])) {
            $pdf->text($M, 58, (string)$o['subtitle'], 10, false, [0.78, 0.84, 0.95]);
        }
        $pdf->text($M, 78, 'Credential Summary / CV', 9.5, true, self::GOLD);
        $dateStr = 'Generated ' . fmt_dt(utc_now(), 'm/d/Y');
        $pdf->text($R - MiniPdf::width($dateStr, 9), 36, $dateStr, 9, false, [0.78, 0.84, 0.95]);
        $orgStr = (string)$o['org'];
        $pdf->text($R - MiniPdf::width($orgStr, 9), 54, $orgStr, 9, true, [1, 1, 1]);

        $y = 126.0;

        // ---- Contact / identifier strip -------------------------------------
        $bits = [];
        if (!empty($o['email'])) $bits[] = (string)$o['email'];
        if (!empty($o['npi']))   $bits[] = 'NPI ' . $o['npi'];
        if ($bits !== []) {
            $pdf->text($M, $y, implode('   ·   ', $bits), 9.5, false, self::GRAY);
            $y += 24;
        }

        // ---- Group credentials by category ----------------------------------
        $groups = [];
        foreach ((array)$o['creds'] as $c) {
            $cat = isset(self::SECTIONS[$c['category'] ?? '']) ? (string)$c['category'] : 'other';
            $groups[$cat][] = $c;
        }

        $newPage = function () use ($pdf, $M, $R, $o): float {
            $pdf->page();
            $pdf->rect(0, 0, MiniPdf::W, 34, self::NAVY);
            $pdf->text($M, 22, (string)$o['holderName'] . ' — Credential Summary (cont.)', 9.5, true, [1, 1, 1]);
            return 60.0;
        };

        foreach (self::SECTIONS as $cat => $heading) {
            if (empty($groups[$cat])) continue;
            if ($y > MiniPdf::H - 120) $y = $newPage();
            // section heading
            $pdf->text($M, $y, strtoupper($heading), 10.5, true, self::NAVY);
            $pdf->line($M, $y + 6, $R, $y + 6, 1.0, self::GOLD);
            $y += 24;
            foreach ($groups[$cat] as $c) {
                if ($y > MiniPdf::H - 90) $y = $newPage();
                $stCol = match ((string)($c['status'] ?? 'active')) {
                    'expired' => self::BAD, 'expiring' => self::WARN, default => self::OK,
                };
                // name + status
                $pdf->text($M, $y, (string)$c['name'], 10.5, true, self::INK);
                $st = strtoupper((string)($c['status'] ?? 'active'));
                $pdf->text($R - MiniPdf::width($st, 8.5), $y, $st, 8.5, true, $stCol);
                $y += 15;
                // detail line: issuer · number · dates · evidence
                $parts = [];
                if (!empty($c['issuing_body'])) $parts[] = (string)$c['issuing_body'];
                if (!empty($c['number']))       $parts[] = 'No. ' . $c['number'];
                if (!empty($c['issued']))       $parts[] = 'Issued ' . $c['issued'];
                if (!empty($c['expiry']))       $parts[] = 'Expires ' . $c['expiry'];
                if ((int)($c['docs'] ?? 0) > 0) $parts[] = ((int)$c['docs']) . ' document' . ((int)$c['docs'] === 1 ? '' : 's') . ' on file';
                if ($parts !== []) {
                    $y = $pdf->wrap($M + 10, $y, $R - $M - 20, implode('  ·  ', $parts), 9, false, self::GRAY);
                }
                $y += 10;
            }
            $y += 8;
        }

        if ($groups === []) {
            $pdf->text($M, $y, 'No credentials on file yet.', 10, false, self::GRAY);
            $y += 20;
        }

        // ---- Footer ---------------------------------------------------------
        if ($y > MiniPdf::H - 70) $y = $newPage();
        $pdf->line($M, MiniPdf::H - 52, $R, MiniPdf::H - 52, 0.6, [0.85, 0.87, 0.9]);
        $foot = 'Produced from the credential system of record by ' . (string)($o['generatedBy'] ?? '')
              . ' · ' . (string)$o['org'] . ' · Statuses reflect expiry dates as of the generation date.';
        $pdf->wrap($M, MiniPdf::H - 38, $R - $M, $foot, 7.5, false, self::GRAY);
        $pdf->text($M, MiniPdf::H - 18, 'Powered by ' . Ownership::PRODUCT . '  ·  ' . Ownership::copyright(), 7, false, self::GRAY);

        return $pdf->output();
    }
}
