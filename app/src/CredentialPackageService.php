<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Builds the one-click Credential Package: a professional PDF bundle
 * an organization sends to a payer or auditor — provider
 * identity, compliance score, requirement status, and credentials on file
 * with their supporting-evidence index. Pure data in, PDF string out
 * (all tenant data must be fetched by the caller through repositories).
 */
final class CredentialPackageService
{
    private const NAVY  = [0.075, 0.145, 0.27];
    private const GOLD  = [0.94, 0.70, 0.13];
    private const GRAY  = [0.42, 0.46, 0.53];
    private const INK   = [0.13, 0.16, 0.22];
    private const OK    = [0.10, 0.52, 0.31];
    private const WARN  = [0.72, 0.50, 0.04];
    private const BAD   = [0.76, 0.20, 0.20];

    /**
     * @param array $o keys: org, holderName, role, npn, email, regulator(?string),
     *                 score(?int), rows[] (name, regulator, authority, status), verifications[], attestation(?),
     *                 creds[] (name, type, number, issued, expiry, status, docs[]),
     *                 ruleset, generatedBy
     */
    public static function build(array $o): string
    {
        $pdf = new MiniPdf();
        $pdf->page();
        $M = 46.0; $R = MiniPdf::W - $M;
        $pageNo = 1;

        // ---- Header band ----------------------------------------------------
        $pdf->rect(0, 0, MiniPdf::W, 86, self::NAVY);
        $pdf->rect(0, 86, MiniPdf::W, 3, self::GOLD);
        $pdf->text($M, 34, (string)$o['org'], 15, true, [1, 1, 1]);
        $pdf->text($M, 56, 'Credential Package', 10.5, false, [0.78, 0.84, 0.95]);
        $dateStr = 'Generated ' . fmt_dt(utc_now(), 'm/d/Y');
        $pdf->text($R - MiniPdf::width($dateStr, 9), 34, $dateStr, 9, false, [0.78, 0.84, 0.95]);
        if (!empty($o['regulator'])) {
            $c = 'Prepared for: ' . $o['regulator'];
            $pdf->text($R - MiniPdf::width($c, 9), 52, $c, 9, true, [1, 1, 1]);
        }

        // ---- Provider block --------------------------------------------------
        $y = 122.0;
        $pdf->text($M, $y, (string)$o['holderName'], 15, true, self::INK);
        $score = $o['score'];
        if ($score !== null) {
            [$rgb, $lbl] = $score >= 90 ? [self::OK, 'Compliance score'] : ($score >= 60 ? [self::WARN, 'Compliance score'] : [self::BAD, 'Compliance score']);
            $sTxt = $score . ' / 100';
            $bw = MiniPdf::width($sTxt, 13) + 20;
            $pdf->rect($R - $bw, $y - 15, $bw, 22, $rgb);
            $pdf->text($R - $bw + 10, $y, $sTxt, 13, true, [1, 1, 1]);
            $pdf->text($R - $bw - MiniPdf::width($lbl, 8.5) - 8, $y - 1, $lbl, 8.5, false, self::GRAY);
        }
        $y += 18;
        $meta = array_filter([(string)$o['role'],
            $o['npn'] ? 'NPI ' . $o['npn'] : null, $o['email'] ?: null]);
        $pdf->text($M, $y, implode('  ·  ', $meta), 9.5, false, self::GRAY);
        $y += 24;

        $newPage = function () use ($pdf, &$pageNo, $M, $o): float {
            self::footer($pdf, $pageNo, $o); $pageNo++;
            $pdf->page();
            $pdf->text($M, 40, $o['holderName'] . ' — credential package (continued)', 9, false, self::GRAY);
            $pdf->line($M, 48, MiniPdf::W - $M, 48);
            return 70.0;
        };

        // ---- Requirement status ---------------------------------------------
        $pdf->text($M, $y, 'Requirement status', 12, true, self::NAVY);
        $y += 8; $pdf->line($M, $y, $R, $y, 1.1, self::NAVY); $y += 16;
        $cols = [$M, $M + 250, $M + 336, $M + 406, $M + 472];
        $pdf->text($cols[0], $y, 'REQUIREMENT', 7.5, true, self::GRAY);
        $pdf->text($cols[1], $y, 'REGULATOR', 7.5, true, self::GRAY);
        $pdf->text($cols[2], $y, 'AUTHORITY', 7.5, true, self::GRAY);
        $pdf->text($cols[3], $y, 'STATUS', 7.5, true, self::GRAY);
        $pdf->text($cols[4], $y, 'EVIDENCE', 7.5, true, self::GRAY);
        $y += 6; $pdf->line($M, $y, $R, $y); $y += 13;

        foreach ($o['rows'] as $r) {
            if ($y > MiniPdf::H - 96) { $y = $newPage(); }
            $name = strlen($r['name']) > 52 ? substr($r['name'], 0, 49) . '...' : $r['name'];
            $pdf->text($cols[0], $y, $name, 9, false, self::INK);
            $pdf->text($cols[1], $y, $r['regulator'] ?: 'General', 8.5, false, self::GRAY);
            $pdf->text($cols[2], $y, strtoupper((string)$r['authority']), 8.5, false, self::GRAY);
            $st = (string)$r['status'];
            $rgb = $st === 'active' ? self::OK : ($st === 'expiring' ? self::WARN : self::BAD);
            $pdf->text($cols[3], $y, strtoupper($st), 8.5, true, $rgb);
            $ev = (int)($r['evidence'] ?? 0);
            $pdf->text($cols[4], $y, $ev > 0 ? $ev . ' doc' . ($ev === 1 ? '' : 's') : '—', 8.5, $ev > 0, $ev > 0 ? self::OK : self::GRAY);
            $y += 5; $pdf->line($M, $y, $R, $y, 0.4, [0.9, 0.91, 0.94]); $y += 12;
        }
        if ($o['rows'] === []) {
            $pdf->text($M, $y, 'No requirements assigned.', 9, false, self::GRAY); $y += 16;
        }
        $y += 14;

        // ---- Credentials on file ---------------------------------------------
        if ($y > MiniPdf::H - 150) { $y = $newPage(); }
        $pdf->text($M, $y, 'Credentials on file', 12, true, self::NAVY);
        $y += 8; $pdf->line($M, $y, $R, $y, 1.1, self::NAVY); $y += 18;

        foreach ($o['creds'] as $c) {
            $need = 34 + ($c['docs'] ? 13 : 0);
            if ($y + $need > MiniPdf::H - 78) { $y = $newPage(); }
            $st = (string)$c['status'];
            $rgb = $st === 'active' ? self::OK : ($st === 'expiring' ? self::WARN : self::BAD);
            $pdf->rect($M, $y - 8, 3, $need - 10, $rgb);
            $pdf->text($M + 12, $y, (string)$c['name'], 10.5, true, self::INK);
            $stW = MiniPdf::width(strtoupper($st), 8.5);
            $pdf->text($R - $stW, $y, strtoupper($st), 8.5, true, $rgb);
            $y += 14;
            $bits = array_filter([
                $c['type'] ?: null,
                $c['number'] ? '# ' . $c['number'] : null,
                $c['issued'] ? 'Issued ' . $c['issued'] : null,
                $c['expiry'] ? 'Expires ' . $c['expiry'] : 'No expiration',
            ]);
            $pdf->text($M + 12, $y, implode('   ·   ', $bits), 8.8, false, self::GRAY);
            $y += 13;
            if ($c['docs']) {
                $pdf->text($M + 12, $y, 'Evidence on file: ' . implode(', ', array_slice($c['docs'], 0, 4))
                    . (count($c['docs']) > 4 ? ' +' . (count($c['docs']) - 4) . ' more' : ''), 8.3, false, [0.3, 0.42, 0.62]);
                $y += 13;
            }
            $y += 8;
        }
        if ($o['creds'] === []) {
            $pdf->text($M, $y, 'No credentials recorded.', 9, false, self::GRAY); $y += 16;
        }

        // ---- Verification-record trail -------------------------------
        $vers = $o['verifications'] ?? [];
        if ($vers !== []) {
            if ($y + 40 > MiniPdf::H - 78) { $y = $newPage(); }
            $y += 14;
            $pdf->text($M, $y, 'Verification trail', 12, true, self::NAVY);
            $y += 16;
            $vcols = [$M, $M + 92, $M + 250, $R - 120];
            $pdf->text($vcols[0], $y, 'DATE', 7.5, true, self::GRAY);
            $pdf->text($vcols[1], $y, 'METHOD', 7.5, true, self::GRAY);
            $pdf->text($vcols[2], $y, 'SOURCE', 7.5, true, self::GRAY);
            $pdf->text($vcols[3], $y, 'RESULT', 7.5, true, self::GRAY);
            $y += 12;
            $vmeth = ['primary_source'=>'Primary source','copy'=>'Copy on file','database'=>'Database','attestation'=>'Attestation','other'=>'Other'];
            foreach (array_slice($vers, 0, 12) as $v) {
                if ($y + 14 > MiniPdf::H - 78) { $y = $newPage(); }
                $pdf->text($vcols[0], $y, (string)$v['verified_on'], 8.5, false, self::INK);
                $pdf->text($vcols[1], $y, $vmeth[$v['method']] ?? (string)$v['method'], 8.5, false, self::GRAY);
                $pdf->text($vcols[2], $y, substr((string)($v['source'] ?: '—'), 0, 34), 8.5, false, self::GRAY);
                $ok = ($v['result'] ?? '') === 'verified';
                $pdf->text($vcols[3], $y, strtoupper((string)$v['result']), 8.5, true, $ok ? self::OK : self::WARN);
                $y += 14;
            }
        }

        // ---- Attestation ------------------------------------------------------
        $att = $o['attestation'] ?? null;

        // ---- Attestation note -------------------------------------------------
        if ($y + 54 > MiniPdf::H - 78) { $y = $newPage(); }
        $y += 8;
        $pdf->rect($M, $y - 10, $R - $M, $att ? 60 : 44, [0.95, 0.96, 0.985]);
        if ($att) {
            $pdf->text($M + 12, $y + 2, 'Provider attestation on file — ' . substr((string)$att['attested_on'], 0, 10)
                . ($att['attested_by'] ? ' · ' . $att['attested_by'] : ''), 8.6, true, self::NAVY);
            $y += 14;
            $y = $pdf->wrap($M + 12, $y + 4, $R - $M - 24, '"' . (string)$att['statement'] . '"', 8.3, false, self::GRAY);
            $y += 4;
        }
        $y = $pdf->wrap($M + 12, $y + 4, $R - $M - 24,
            'This package reflects the provider\'s credential records in FlxCoreHC at generation time. '
            . 'Status is computed against ' . $o['ruleset'] . '. Supporting documents listed above are retained '
            . 'in the organization\'s secure repository and available on request.', 8.3, false, self::GRAY);

        self::footer($pdf, $pageNo, $o);
        return $pdf->output();
    }

    private static function footer(MiniPdf $pdf, int $pageNo, array $o): void
    {
        $y = MiniPdf::H - 42;
        $pdf->line(46, $y - 12, MiniPdf::W - 46, $y - 12);
        $pdf->text(46, $y, $o['org'] . '  ·  Powered by FlxCoreHC  ·  ' . $o['ruleset'], 7.8, false, self::GRAY);
        $pn = 'Page ' . $pageNo;
        $pdf->text(MiniPdf::W - 46 - MiniPdf::width($pn, 7.8), $y, $pn, 7.8, false, self::GRAY);
    }
}
