<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Tenant report — everything the platform operator legitimately holds about one organization, as a
 * PDF (for a file / a client review) or a CSV bundle (ZIP). Operator-facing: it covers the account,
 * plan, usage & health, team, locations/groups, payers, enrollments, verification activity and the
 * organization's own settings — as COUNTS AND ACCOUNT DATA. It never includes provider identities,
 * credential contents or documents; those belong to the tenant and are exported by the tenant itself
 * (Settings → Download all data).
 */
final class TenantReportService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /** Assemble everything once; both renderers consume this. */
    public function assemble(string $tenantId): array
    {
        $pdo = Database::pdo();
        $t = $pdo->prepare('SELECT * FROM tenants WHERE id = ?'); $t->execute([$tenantId]); $tenant = $t->fetch() ?: [];
        if (!$tenant) return [];
        $admin = new SuperadminService($this->ctx);
        $usage = $admin->tenantUsage($tenantId);
        $failed = 0;   // v1.170.9: query failures are counted + logged; the report says so instead of showing blanks
        $q = function (string $sql, array $p = []) use ($pdo, &$failed) { try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); } catch (\Throwable $e) { $failed++; flx_log_error('error', 'tenant report query failed: ' . $e->getMessage()); return []; } };
        return [
            'generated'  => utc_now(),
            'tenant'     => $tenant,
            'plan'       => $q('SELECT p.name, p.code, t.seat_override FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.id = ?', [$tenantId])[0] ?? [],
            'usage'      => $usage,
            // Staff only. Provider-portal logins (producer/provider roles) ARE provider identities, so they are
            // deliberately excluded — this report never names a provider.
            'team'       => $q("SELECT full_name, email, role, status, created_at FROM users WHERE tenant_id = ? AND role NOT IN ('producer','provider') AND holder_id IS NULL ORDER BY role, full_name", [$tenantId]),
            'locations'  => $q('SELECT name, city, state, npi FROM locations WHERE tenant_id = ? ORDER BY name', [$tenantId]),
            'groups'     => $q('SELECT name FROM provider_groups WHERE tenant_id = ? ORDER BY name', [$tenantId]),
            'payers'     => $q('SELECT p.name, pc.status, COUNT(*) n FROM payer_credentialing pc JOIN payers p ON p.id = pc.payer_id WHERE pc.tenant_id = ? GROUP BY p.name, pc.status ORDER BY p.name', [$tenantId]),
            'programs'   => $q('SELECT program, status, COUNT(*) n FROM program_enrollments WHERE tenant_id = ? GROUP BY program, status', [$tenantId]),
            'ocs'        => $q('SELECT sicro_status, COUNT(*) n FROM ocs_profiles WHERE tenant_id = ? GROUP BY sicro_status', [$tenantId]),
            'verif'      => $q('SELECT source, outcome, COUNT(*) n FROM source_verifications WHERE tenant_id = ? GROUP BY source, outcome', [$tenantId]),
            'mail'       => $q('SELECT transport, oauth_account, from_email, verified_at FROM tenant_mail_settings WHERE tenant_id = ?', [$tenantId])[0] ?? [],
            'domains'    => $q('SELECT host AS domain, is_primary, created_at FROM tenant_domains WHERE tenant_id = ?', [$tenantId]),
            'invoices'   => $q('SELECT number, status, total_cents, COALESCE(issue_date, created_at) AS issued_at FROM invoices WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 24', [$tenantId]),
            'support'    => $q('SELECT status, requested_at, decided_at AS approved_at, expires_at FROM impersonation_requests WHERE tenant_id = ? ORDER BY requested_at DESC LIMIT 20', [$tenantId]),
            'levels'     => $this->levelDistribution($tenantId),
            'query_failures' => $failed,
        ];
    }

    /** Compliance-level distribution (counts per level 0–5), computed without exposing identities. */
    private function levelDistribution(string $tenantId): array
    {
        $dist = [0=>0,1=>0,2=>0,3=>0,4=>0,5=>0];
        try {
            $pdo = Database::pdo();
            $u = $pdo->prepare("SELECT id, full_name FROM users WHERE tenant_id = ? AND role = 'tenant_owner' LIMIT 1"); $u->execute([$tenantId]); $owner = $u->fetch();
            $t = $pdo->prepare('SELECT name, vertical, org_shape FROM tenants WHERE id = ?'); $t->execute([$tenantId]); $tt = $t->fetch();
            if (!$owner || !$tt) return $dist;
            $tctx = new TenantContext($tenantId, $owner['id'], 'tenant_owner', $tt['name'], $tt['vertical'], $tt['org_shape'], $owner['full_name'], false);
            $H = new Repository\HolderRepository($tctx); $C = new Repository\CredentialRepository($tctx); $R = new Repository\ProducerRequirementRepository($tctx);
            foreach ($H->all([], 'last_name ASC', 2000) as $h) {
                $cr = $C->all(['holder_id' => $h['id']]);
                $lv = ComplianceLevelService::evaluate($tctx, $h, $cr, $R->tokensFor($h['id']), CatalogService::coverageTokens($cr));
                $dist[$lv['level']] = ($dist[$lv['level']] ?? 0) + 1;
            }
        } catch (\Throwable $e) {}
        return $dist;
    }

    // ---- PDF -------------------------------------------------------------------

    public function pdf(array $r): string
    {
        $pdf = new MiniPdf(); $pdf->page();
        $t = $r['tenant']; $u = $r['usage']; $y = 800; $L = 48; $W = 500;
        $h = function (string $s) use ($pdf, &$y, $L) { if ($y < 90) { $pdf->page(); $y = 800; } $pdf->text($L, $y, $s, 12.5, true); $y -= 18; };
        $kv = function (string $k, string $v) use ($pdf, &$y, $L) { if ($y < 70) { $pdf->page(); $y = 800; } $pdf->text($L, $y, $k, 9, false, [0.45,0.48,0.55]); $pdf->text($L + 190, $y, $v, 9.5); $y -= 13; };
        $gap = function (int $n = 8) use (&$y) { $y -= $n; };
        $rule = function () use ($pdf, &$y, $L, $W) { $pdf->line($L, $y + 4, $L + $W, $y + 4); $y -= 8; };

        $pdf->text($L, 812, 'FlxCoreHC — Tenant report', 9, false, [0.45,0.48,0.55]);
        $pdf->text($L, 790, (string)$t['name'], 18, true); $y = 768;
        $pdf->text($L, $y, ucfirst(str_replace('_',' ',(string)$t['org_shape'])) . ' · ' . ucfirst((string)$t['vertical']) . ' · ' . ucfirst((string)$t['status']) . ' · generated ' . fmt_dt((string)$r['generated'], 'm/d/Y g:i A'), 9, false, [0.45,0.48,0.55]);
        $y -= 24;

        if (!empty($r['query_failures'])) { $kv('WARNING', (int)$r['query_failures'] . ' section(s) could not be read (logged) — this report is INCOMPLETE'); }
        $h('Account'); $rule();
        $kv('Organization ID', (string)$t['id']);
        $kv('Created', substr((string)$t['created_at'],0,10)); $kv('Approved', $t['approved_at'] ? substr((string)$t['approved_at'],0,10) : '—');
        $kv('Contact', trim((string)($t['contact_email'] ?? '') . ' ' . (string)($t['contact_phone'] ?? '')) ?: '—');
        $kv('Default language', strtoupper((string)($t['default_locale'] ?? 'en'))); $kv('Demo organization', !empty($t['is_demo']) ? 'yes' : 'no');
        $kv('Plan', (string)(($r['plan']['name'] ?? '') ?: 'Default') . (isset($r['plan']['seat_override']) && $r['plan']['seat_override'] !== null ? ' · seat override ' . (int)$r['plan']['seat_override'] : ''));
        $kv('Custom domains', $r['domains'] ? implode(', ', array_map(fn($d) => $d['domain'] . (!empty($d['is_primary']) ? ' (primary)' : ''), $r['domains'])) : '—');
        $kv('Email delivery', ($r['mail']['transport'] ?? '') === 'graph' ? 'Microsoft 365 · ' . ($r['mail']['oauth_account'] ?? '') : (($r['mail']['transport'] ?? '') === 'smtp' ? 'Own SMTP · ' . ($r['mail']['from_email'] ?? '') : 'Platform default'));
        $gap();

        $h('Usage & health (counts only)'); $rule();
        $mb = fn(int $b) => $b >= 1048576 ? round($b/1048576,1) . ' MB' : round($b/1024) . ' KB';
        $kv('Providers / credentials / documents', (int)$u['providers'] . ' / ' . (int)$u['credentials'] . ' / ' . (int)$u['documents'] . ' (' . $mb((int)$u['storage_bytes']) . ')');
        $kv('Users (active / invited / portal)', (int)$u['users_active'] . ' / ' . (int)$u['users_invited'] . ' / ' . (int)$u['portal_users']);
        $kv('Groups / locations', (int)$u['groups'] . ' / ' . (int)$u['locations']);
        $kv('Credentials expiring / expired', (int)$u['cred_expiring'] . ' / ' . (int)$u['cred_expired']);
        $kv('Documents verified', (int)$u['docs_verified'] . ' of ' . (int)$u['documents']);
        $kv('Last login / last activity', ($u['last_login'] ? substr((string)$u['last_login'],0,16) : 'never') . ' / ' . ($u['last_activity'] ? substr((string)$u['last_activity'],0,16) : 'never'));
        $kv('Actions 7d / 30d · people active', (int)$u['actions_7d'] . ' / ' . (int)$u['actions_30d'] . ' · ' . (int)$u['active_users_30d']);
        $kv('Feature use', 'requests ' . (int)$u['doc_requests'] . ' · external shares ' . (int)$u['external_shares'] . ' · cross-org links ' . (int)$u['cross_links'] . ' · emails 30d ' . (int)$u['emails_30d']);
        $lv = $r['levels']; $kv('Compliance levels (0→5)', implode(' · ', array_map(fn($k) => "L$k: " . (int)$lv[$k], array_keys($lv))));
        $gap();

        $h('Team (' . count($r['team']) . ')'); $rule();
        foreach ($r['team'] as $m) { $kv((string)$m['full_name'], (string)$m['email'] . ' · ' . (string)$m['role'] . ' · ' . (string)$m['status']); }
        $gap();

        if ($r['locations'] || $r['groups']) {
            $h('Structure'); $rule();
            foreach ($r['locations'] as $l) { $kv('Location', trim((string)$l['name'] . ' — ' . trim((string)($l['city'] ?? '') . ' ' . (string)($l['state'] ?? '')) . ($l['npi'] ? ' · NPI ' . $l['npi'] : ''))); }
            foreach ($r['groups'] as $g) { $kv('Group', (string)$g['name']); }
            $gap();
        }
        if ($r['payers'] || $r['programs'] || $r['ocs']) {
            $h('Payers, programs & OCS (counts by status)'); $rule();
            foreach ($r['payers'] as $p) { $kv((string)$p['name'], str_replace('_',' ',(string)$p['status']) . ' × ' . (int)$p['n']); }
            foreach ($r['programs'] as $p) { $kv(ucfirst((string)$p['program']), str_replace('_',' ',(string)$p['status']) . ' × ' . (int)$p['n']); }
            foreach ($r['ocs'] as $o) { $kv('OCS / SICRO', str_replace('_',' ',(string)$o['sicro_status']) . ' × ' . (int)$o['n']); }
            $gap();
        }
        if ($r['verif']) {
            $h('Verification records (counts)'); $rule();
            foreach ($r['verif'] as $v) { $kv(strtoupper((string)$v['source']), (string)$v['outcome'] . ' × ' . (int)$v['n']); }
            $gap();
        }
        if ($r['invoices']) {
            $h('Invoices (latest)'); $rule();
            foreach ($r['invoices'] as $i) { $kv((string)$i['number'], (string)$i['status'] . ' · $' . number_format(((int)$i['total_cents'])/100, 2) . ' · ' . substr((string)$i['issued_at'],0,10)); }
            $gap();
        }
        if ($r['support']) {
            $h('Platform support access (consent log)'); $rule();
            foreach ($r['support'] as $s) { $kv(substr((string)$s['requested_at'],0,16), (string)$s['status'] . ($s['approved_at'] ? ' · approved ' . substr((string)$s['approved_at'],0,16) : '') . ($s['expires_at'] ? ' · until ' . substr((string)$s['expires_at'],0,16) : '')); }
        }
        $pdf->text($L, 40, 'Counts and account data only — provider identities, credential contents and documents are the organization\'s own and are not included.', 7.5, false, [0.45,0.48,0.55]);
        return $pdf->output();
    }

    // ---- CSV bundle ---------------------------------------------------------------

    public function csvZip(array $r): string
    {
        $csv = function (array $rows, array $cols): string {
            $o = fopen('php://temp', 'r+'); flx_fputcsv($o, $cols);
            foreach ($rows as $row) { flx_fputcsv($o, csv_safe_row(array_map(fn($c) => $row[$c] ?? '', $cols))); }
            rewind($o); return "\xEF\xBB\xBF" . stream_get_contents($o);
        };
        $t = $r['tenant']; $u = $r['usage'];
        $files = [
            'account.csv'   => $csv([[ 'id'=>$t['id'], 'name'=>$t['name'], 'shape'=>$t['org_shape'], 'vertical'=>$t['vertical'], 'status'=>$t['status'], 'created_at'=>$t['created_at'], 'approved_at'=>$t['approved_at'] ?? '', 'contact_email'=>$t['contact_email'] ?? '', 'contact_phone'=>$t['contact_phone'] ?? '', 'default_locale'=>$t['default_locale'] ?? '', 'is_demo'=>$t['is_demo'] ?? 0, 'plan'=>$r['plan']['name'] ?? '', 'seat_override'=>$r['plan']['seat_override'] ?? '', 'mail_transport'=>$r['mail']['transport'] ?? 'platform', 'mail_account'=>$r['mail']['oauth_account'] ?? ($r['mail']['from_email'] ?? '') ]],
                                ['id','name','shape','vertical','status','created_at','approved_at','contact_email','contact_phone','default_locale','is_demo','plan','seat_override','mail_transport','mail_account']),
            'usage.csv'     => $csv([array_merge(['generated' => $r['generated']], array_filter($u, fn($v) => !is_array($v)))], array_merge(['generated'], array_keys(array_filter($u, fn($v) => !is_array($v))))),
            'levels.csv'    => $csv(array_map(fn($k) => ['level'=>$k, 'label'=>ComplianceLevelService::LABELS[$k], 'providers'=>$r['levels'][$k]], array_keys($r['levels'])), ['level','label','providers']),
            'team.csv'      => $csv($r['team'], ['full_name','email','role','status','created_at']),
            'locations.csv' => $csv($r['locations'], ['name','city','state','npi']),
            'groups.csv'    => $csv($r['groups'], ['name']),
            'payers.csv'    => $csv($r['payers'], ['name','status','n']),
            'programs.csv'  => $csv($r['programs'], ['program','status','n']),
            'ocs.csv'       => $csv($r['ocs'], ['sicro_status','n']),
            'verifications.csv' => $csv($r['verif'], ['source','outcome','n']),
            'domains.csv'   => $csv($r['domains'], ['domain','is_primary','created_at']),
            'invoices.csv'  => $csv($r['invoices'], ['number','status','total_cents','issued_at']),
            'support_access.csv' => $csv($r['support'], ['status','requested_at','approved_at','expires_at']),
        ];
        return DataExportService::zip($files);
    }
}
