<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Verification records, matched to what each authority actually offers. FlxCoreHC records the organization's verification
 * activity and evidence; it does not independently perform or certify primary-source verification.
 *
 *   Tier A — AUTOMATED (a real public API exists):
 *     nppes  — CMS NPI Registry, free public API. Re-pulls the live record and compares it to the
 *              provider on file; flags name/taxonomy/state mismatches.
 *     sam    — SAM.gov exclusions, free public API with a self-service key. Real-time exclusion check.
 *     (leie continues via the monthly-file screening in LeieService — OIG offers no API.)
 *
 *   Tier B — ASSISTED MANUAL (no public API: ORCPS, OCS/SICRO, Medicaid PEP, Medicare PECOS):
 *     The app deep-links to the official page; the user reads the result and records it with the
 *     evidence document. Recorded identically (method = manual_source) so the audit trail is uniform.
 *
 * Every verification is stamped (who, when, source, outcome), optionally tied to a credential and an
 * evidence document, and given a re-verify date so checks recur on a cadence.
 */
final class VerificationService
{
    public const SOURCES = [
        'nppes'    => ['NPPES (NPI Registry)', 'api',           'https://npiregistry.cms.hhs.gov/'],
        'sam'      => ['SAM.gov exclusions',   'api',           'https://sam.gov/search/'],
        'leie'     => ['OIG LEIE',             'file',          'https://exclusions.oig.hhs.gov/'],
        'orcps'    => ['ORCPS Good Standing (PR license)', 'manual_source', 'https://orcps.salud.pr.gov/mbps/verificacion'],
        'ocs'      => ['OCS / SICRO',          'manual_source', 'https://credencializacion.ocs.pr.gov/'],
        'medicaid' => ['Medicaid PEP',         'manual_source', 'https://www.medicaid.pr.gov/'],
        'medicare' => ['Medicare PECOS',       'manual_source', 'https://pecos.cms.hhs.gov/'],
    ];

    /** Sources that only exist in Puerto Rico; other tenants are not offered them. */
    public const PR_ONLY = ['orcps', 'ocs'];

    /** Sources offered to a tenant that operates in the given states. */
    public static function sourcesFor(array $states): array
    {
        $pr = in_array('PR', array_map('strtoupper', $states), true);
        return $pr ? self::SOURCES : array_diff_key(self::SOURCES, array_flip(self::PR_ONLY));
    }

    public function __construct(private readonly TenantContext $ctx) {}

    /** Verifications for a provider, newest first. */
    public function forHolder(string $holderId, int $limit = 100): array
    {
        if (SupportAccess::active()) return [];
        $sc = ProviderScope::for($this->ctx);   // need-to-know (v1.170.9): a holder id in the URL is not authorization
        $st = Database::pdo()->prepare(
            'SELECT * FROM source_verifications WHERE tenant_id = ? AND holder_id = ?' . $sc->sql('holder_id', false)
            . ' ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 500)));
        $st->execute(array_merge([$this->ctx->tenantId, $holderId], $sc->params()));
        return $st->fetchAll();
    }

    /** Verifications due for re-check (past their reverify_on). */
    public function due(): array
    {
        $st = Database::pdo()->prepare(
            "SELECT sv.*, h.first_name, h.last_name, h.org_name FROM source_verifications sv
             JOIN credential_holders h ON h.id = sv.holder_id AND h.tenant_id = sv.tenant_id AND h.deleted_at IS NULL
             WHERE sv.tenant_id = ? AND sv.reverify_on IS NOT NULL AND sv.reverify_on <= @flx_today" . ProviderScope::for($this->ctx)->sql('sv.holder_id', false) . "
             ORDER BY sv.reverify_on LIMIT 500");
        $st->execute(array_merge([$this->ctx->tenantId], ProviderScope::for($this->ctx)->params()));
        return $st->fetchAll();
    }

    // ---- Tier A: automated ------------------------------------------------------

    /** Re-verify NPI against the live NPPES record and compare to what's on file. */
    public function verifyNppes(array $holder, ?string $reverifyOn = null): array
    {
        $npi = preg_replace('/\D/', '', (string)($holder['npi'] ?? ''));
        if (strlen($npi) !== 10) return $this->record($holder['id'], null, 'nppes', 'api', 'error', ['note' => 'No valid NPI on file'], null, null, $reverifyOn);
        $r = NpiService::lookup($npi, true);
        if (empty($r['found'])) return $this->record($holder['id'], null, 'nppes', 'api', 'not_found', ['npi' => $npi], null, null, $reverifyOn);

        $onFileName = $holder['holder_type'] === 'organization' ? (string)($holder['org_name'] ?? '') : trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? ''));
        $diffs = [];
        if ($onFileName !== '' && !self::nameMatches($onFileName, (string)$r['name'])) $diffs['name'] = ['on_file' => $onFileName, 'nppes' => $r['name']];
        // taxonomy/state are informational; a difference is a soft flag, not a failure
        $detail = ['npi' => $npi, 'nppes_name' => $r['name'], 'taxonomy' => $r['taxonomy'], 'state' => $r['state'], 'differences' => $diffs];
        $outcome = $diffs ? 'mismatch' : 'match';
        return $this->record($holder['id'], null, 'nppes', 'api', $outcome, $detail, 'NPPES ' . today_local(), null, $reverifyOn);
    }

    /** Real-time exclusion check against SAM.gov (needs a platform API key). */
    public function verifySam(array $holder, ?string $reverifyOn = null): array
    {
        $key = self::samKey();
        if ($key === '') return [false, 'A SAM.gov API key is not configured (Console → System). Register a free key at sam.gov.', null];
        $name = $holder['holder_type'] === 'organization' ? (string)($holder['org_name'] ?? '') : trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? ''));
        if ($name === '') return [false, 'No name on file to check.', null];
        $resp = self::samQuery($name, $key);
        if ($resp === null) return [false, 'Could not reach SAM.gov. Try again later.', null];
        $hits = (int)($resp['totalRecords'] ?? 0);
        $outcome = $hits > 0 ? 'flagged' : 'clear';
        $ref = 'SAM.gov ' . today_local();
        $row = $this->record($holder['id'], null, 'sam', 'api', $outcome, ['name' => $name, 'records' => $hits], $ref, null, $reverifyOn);
        // A flag is also an exclusion-screening record, so it feeds the compliance hard stop.
        if ($hits > 0) {
            try {
                Database::pdo()->prepare("INSERT INTO exclusion_screenings (id, tenant_id, holder_id, source, screened_on, result, reference, screened_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([uuid4(), $this->ctx->tenantId, $holder['id'], 'sam', today_local(), 'match', $ref, 'Automated (SAM.gov)', utc_now()]);
            } catch (\Throwable $e) {}
        }
        return [true, $hits > 0 ? 'SAM.gov returned ' . $hits . ' possible match — review.' : 'SAM.gov: no exclusion match.', $row];
    }

    // ---- Tier B: assisted manual -----------------------------------------------

    /** Record a verification a person performed against an official page (ORCPS, OCS, PEP, PECOS…). */
    public function recordManual(string $holderId, array $in): array
    {
        $source = isset(self::SOURCES[$in['source'] ?? '']) ? $in['source'] : 'other';
        $outcome = in_array($in['outcome'] ?? '', ['clear','flagged','recorded','match','mismatch','not_found'], true) ? $in['outcome'] : 'recorded';
        $rev = (isset($in['reverify_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['reverify_on'])) ? $in['reverify_on'] : null;
        $row = $this->record($holderId, ($in['credential_id'] ?? '') ?: null, $source, 'manual_source', $outcome,
            ['note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 400)], mb_substr(trim((string)($in['reference'] ?? '')), 0, 160) ?: null,
            ($in['document_id'] ?? '') ?: null, $rev);
        return [true, 'Verification recorded.', $row];
    }

    // ---- shared write -----------------------------------------------------------

    private function record(string $holderId, ?string $credId, string $source, string $method, string $outcome,
                            ?array $detail, ?string $reference, ?string $documentId, ?string $reverifyOn): array
    {
        $id = uuid4();
        $byId = $method === 'api' ? null : $this->ctx->userId;
        $byName = $method === 'api' ? 'System (' . strtoupper($source) . ')' : ($this->ctx->userName ?? null);
        Database::pdo()->prepare(
            'INSERT INTO source_verifications (id, tenant_id, holder_id, credential_id, source, method, outcome, detail, reference, document_id, verified_by, verified_by_name, verified_on, reverify_on, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$id, $this->ctx->tenantId, $holderId, $credId, $source, $method, $outcome,
            $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, $reference, $documentId, $byId, $byName, today_local(),
            ($reverifyOn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reverifyOn)) ? $reverifyOn : null, utc_now()]);
        audit_log($this->ctx, 'source_verified', 'holders', $holderId, json_encode(['source' => $source, 'outcome' => $outcome, 'method' => $method]));
        ComplianceEventService::record($this->ctx, 'source_verified',
            (self::SOURCES[$source][0] ?? $source) . ' — ' . ucfirst($outcome) . ($method === 'api' ? ' (automated)' : ' (verified at source)'),
            ['holder_id' => $holderId, 'credential_id' => $credId, 'document_id' => $documentId, 'effective_on' => today_local(),
             'detail' => ['source' => $source, 'outcome' => $outcome]]);
        // If tied to a credential and it's a clean primary-source check, stamp the credential's last_verified_at.
        if ($credId && in_array($outcome, ['match','clear','recorded'], true)) {
            try { (new Repository\CredentialRepository($this->ctx))->update($credId, ['last_verified_at' => utc_now()]); } catch (\Throwable $e) {}
        }
        return ['id' => $id, 'outcome' => $outcome];
    }

    private static function nameMatches(string $a, string $b): bool
    {
        $norm = fn($s) => preg_replace('/[^a-z]/', '', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s));
        $na = $norm($a); $nb = $norm($b);
        if ($na === '' || $nb === '') return true;
        return str_contains($nb, $na) || str_contains($na, $nb) || similar_text($na, $nb) / max(strlen($na), strlen($nb)) > 0.8;
    }

    // ---- SAM.gov key + query ----------------------------------------------------

    public static function samKey(): string
    {
        try {
            $st = Database::pdo()->prepare("SELECT sval FROM platform_settings WHERE skey = 'sam_api_key' LIMIT 1");
            $st->execute(); $v = (string)($st->fetchColumn() ?: '');
            return $v !== '' ? (Crypto::decrypt(base64_decode($v, true) ?: '') ?: '') : '';
        } catch (\Throwable $e) { return ''; }
    }

    public static function setSamKey(string $key): void
    {
        $enc = trim($key) === '' ? '' : base64_encode((string)Crypto::encrypt(trim($key)));
        Database::pdo()->prepare("INSERT INTO platform_settings (skey, sval, updated_at) VALUES ('sam_api_key', ?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval), updated_at = VALUES(updated_at)")
            ->execute([$enc, utc_now()]);
    }

    private static function samQuery(string $name, string $key): ?array
    {
        $url = 'https://api.sam.gov/entity-information/v4/exclusions?api_key=' . urlencode($key)
             . '&exclusionName=' . urlencode($name);
        if (!function_exists('curl_init')) return null;
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'FlxCoreHC/1.0']);
        $body = \curl_exec($ch); $code = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE); \curl_close($ch);
        if ($body === false || $code >= 400) return null;
        $j = json_decode((string)$body, true);
        return is_array($j) ? $j : null;
    }
}
