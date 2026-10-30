<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Email security (DNS layer): SPF / DKIM / DMARC checks per verified domain.
 * Pure DNS — no Graph permission needed. Used by /api/m365/email-dns and the Guardian
 * "Email security" card. The EOP policy layer (anti-phish, Safe Links, quarantine) is
 * PowerShell-only; OpsHub generates those commands in the hardening checklist instead.
 */

if (!function_exists('opsh_dns_txt')) {

    /** TXT records for a name (lower-cased), [] on failure. Overridable in tests. */
    function opsh_dns_txt(string $name): array {
        $out = [];
        try {
            foreach ((@dns_get_record($name, DNS_TXT) ?: []) as $r) {
                $t = $r['txt'] ?? (is_array($r['entries'] ?? null) ? implode('', $r['entries']) : '');
                if ($t !== '') $out[] = (string)$t;
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /** CNAME target for a name, '' when none. Overridable in tests. */
    function opsh_dns_cname(string $name): string {
        try {
            foreach ((@dns_get_record($name, DNS_CNAME) ?: []) as $r) {
                if (!empty($r['target'])) return strtolower((string)$r['target']);
            }
        } catch (Throwable $e) {}
        return '';
    }
}

if (!function_exists('opsh_mailsec_domain')) {

    /** Full SPF / DKIM / DMARC picture for one domain. */
    function opsh_mailsec_domain(string $domain): array {
        $domain = strtolower(trim($domain));
        $out = ['domain' => $domain];

        // ── SPF ──
        $spf = ''; $spfN = 0;
        foreach (opsh_dns_txt($domain) as $t) if (stripos($t, 'v=spf1') === 0) { $spfN++; if ($spf === '') $spf = $t; }
        $spfOk = $spf !== '' && $spfN === 1 && stripos($spf, 'spf.protection.outlook.com') !== false;
        // Review M365A-20: a bare "all" means "+all"; two SPF records make SPF fail for everyone
        $hard = $spf !== '' && preg_match('/(?:^|\s)([-~?+]?)all\s*$/i', $spf, $m) ? ($m[1] !== '' ? strtolower($m[1]) : '+') : '';
        $out['spf'] = ['found' => $spf !== '', 'record' => $spf, 'ok' => $spfOk,
            'all' => $hard,   // '-' hard fail (best), '~' soft, '?' neutral, '+' broken
            'records' => $spfN,
            'advice' => $spf === '' ? 'No SPF record. Publish: v=spf1 include:spf.protection.outlook.com -all'
                : ($spfN > 1 ? 'There are ' . $spfN . ' SPF records: receivers then treat SPF as broken. Merge them into one record.'
                : (!$spfOk ? 'SPF exists but does not include spf.protection.outlook.com — Microsoft-sent mail can fail.'
                : ($hard === '+' ? 'SPF ends in +all, which allows ANYONE to send as you — change to -all.'
                : ($hard === '?' || $hard === '' ? 'Finish the record with -all (hard fail) once all senders are listed.' : ''))))];

        // ── DKIM (Microsoft 365 selectors) ──
        $s1 = opsh_dns_cname('selector1._domainkey.' . $domain);
        $s2 = opsh_dns_cname('selector2._domainkey.' . $domain);
        // Microsoft 365 selector targets: classic "…._domainkey.<tenant>.onmicrosoft.com", or the newer format
        // (domains configured from 2025) "…._domainkey.<tenant>.<x>.dkim.mail.microsoft". Accept both — never assume.
        $msTarget = fn($t) => strpos($t, 'onmicrosoft.com') !== false || strpos($t, '.dkim.mail.microsoft') !== false;
        $dkOk = $s1 !== '' && $s2 !== '' && $msTarget($s1) && $msTarget($s2);
        $out['dkim'] = ['selector1' => $s1, 'selector2' => $s2, 'ok' => $dkOk,
            'advice' => $dkOk ? '' : 'DKIM selector CNAMEs are missing. Get the two exact CNAME values Microsoft generates (Defender portal → Email authentication settings → DKIM → ' . $domain . ', or Get-DkimSigningConfig in the checklist) — never build them by hand — publish them, then enable signing.'];

        // ── DMARC ──
        $dm = '';
        foreach (opsh_dns_txt('_dmarc.' . $domain) as $t) if (stripos($t, 'v=DMARC1') === 0) { $dm = $t; break; }
        $policy = '';
        if ($dm !== '' && preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $dm, $m)) $policy = strtolower($m[1]);
        $hasRua = $dm !== '' && stripos($dm, 'rua=') !== false;
        $grade = $dm === '' ? 'missing' : ($policy === 'reject' ? 'reject' : ($policy === 'quarantine' ? 'quarantine' : 'monitor'));
        $out['dmarc'] = ['found' => $dm !== '', 'record' => $dm, 'policy' => $policy, 'rua' => $hasRua, 'grade' => $grade,
            'advice' => $dm === '' ? 'No DMARC record. Start with: v=DMARC1; p=none; rua=mailto:dmarc@' . $domain . ' — monitor for 2–4 weeks, then move to quarantine, then reject.'
                : ($grade === 'monitor' ? 'DMARC is monitor-only (p=none). After reviewing reports, raise to p=quarantine, then p=reject.'
                : ($grade === 'quarantine' ? 'Good. The end goal is p=reject once reports are clean.'
                : (!$hasRua ? 'Add rua=mailto:… so you receive aggregate reports.' : '')))];

        $score = (int)$spfOk + (int)$dkOk + (int)in_array($grade, ['quarantine', 'reject'], true);
        $out['level'] = $score === 3 ? 'ok' : ($score >= 1 ? 'warning' : 'error');
        return $out;
    }
}

if (!function_exists('opsh_mailsec_skip_reason')) {
    /**
     * Domains that must NOT be graded or given SPF/DKIM/DMARC commands: Microsoft-managed domains, domains not
     * used for email, and third-party routing domains whose DNS belongs to a vendor (e.g. the unique
     * "<id>.smtp.codetwo.online" domain CodeTwo's signature service adds to a tenant). Returns '' for a normal domain.
     */
    function opsh_mailsec_skip_reason(string $domain, ?array $supportedServices = null): string {
        $d = strtolower($domain);
        if (substr($d, -16) === '.onmicrosoft.com') return 'managed by Microsoft — nothing to do';
        $VENDORS = ['codetwo.online' => 'CodeTwo', 'codetwo.com' => 'CodeTwo', 'exclaimer.net' => 'Exclaimer', 'exclaimer.cloud' => 'Exclaimer',
                    'mimecast.com' => 'Mimecast', 'pphosted.com' => 'Proofpoint'];
        foreach ($VENDORS as $suffix => $vendor)
            if ($d === $suffix || substr($d, -strlen('.' . $suffix)) === '.' . $suffix)
                return $vendor . ' routing domain — its DNS is managed by ' . $vendor . '; do not create DKIM or change DNS for it';
        if (is_array($supportedServices) && $supportedServices && !in_array('Email', $supportedServices, true))
            return 'not used for email in Microsoft 365 — nothing to check';
        return '';
    }
}
