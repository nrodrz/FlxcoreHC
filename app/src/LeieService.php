<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Automated OIG LEIE screening.
 *
 * The OIG publishes the List of Excluded Individuals/Entities as a monthly downloadable CSV and
 * has stated there are NO plans for a public API, so screening is done by ingesting that file into
 * leie_exclusions and matching providers against it locally. The file layout (documented header):
 *
 *   LASTNAME,FIRSTNAME,MIDNAME,BUSNAME,GENERAL,SPECIALTY,UPIN,NPI,DOB,ADDRESS,CITY,STATE,ZIP,
 *   EXCLTYPE,EXCLDATE,REINDATE,WAIVERDATE,WVRSTATE
 *
 * Screening philosophy (kept deliberately conservative):
 *   - A match is a REVIEW SIGNAL, never a verdict. We record 'potential_match', never 'excluded'.
 *   - Every screening record is stamped with the list vintage (the import's file_month) so a result
 *     always shows which publication it came from.
 *   - Some LEIE rows carry no NPI ('0000000000' or blank), so NPI matches are exact and high-signal;
 *     name matches are surfaced for human confirmation, not treated as conclusive.
 *   - Identity must always be confirmed at oig.hhs.gov before any action is taken.
 */
final class LeieService
{
    /** Official monthly download (used on real hosting; the sandbox cannot reach it). */
    public const DOWNLOAD_URL = 'https://oig.hhs.gov/exclusions/downloadables/UPDATED.csv';

    /**
     * Ingest a LEIE CSV (already fetched into a string) for a given vintage month (YYYY-MM).
     * Replaces any prior import for that month. Returns ['ok'=>bool,'rows'=>int,'import_id'=>string]
     * or ['ok'=>false,'error'=>string].
     */
    public static function ingestCsv(string $csv, string $fileMonth, ?string $userId = null, ?string $note = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $fileMonth)) {
            return ['ok' => false, 'error' => 'File month must be YYYY-MM.'];
        }
        $rows = self::parseCsv($csv);
        if ($rows === null) {
            return ['ok' => false, 'error' => 'That does not look like a LEIE CSV (header row not recognized).'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Replace any existing import for this month (ON DELETE CASCADE clears its rows).
            $del = $pdo->prepare('SELECT id FROM leie_imports WHERE file_month = ?');
            $del->execute([$fileMonth]);
            if ($old = $del->fetchColumn()) {
                $pdo->prepare('DELETE FROM leie_imports WHERE id = ?')->execute([$old]);
            }

            $importId = uuid4();
            $pdo->prepare(
                'INSERT INTO leie_imports (id, file_month, row_count, source_note, imported_by, imported_at)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$importId, $fileMonth, count($rows), $note, $userId, utc_now()]);

            $ins = $pdo->prepare(
                'INSERT INTO leie_exclusions
                   (id, import_id, last_name, first_name, midname, business_name, npi, upin,
                    general, specialty, excltype, excldate, state, name_key)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($rows as $r) {
                $ins->execute([
                    uuid4(), $importId, $r['last_name'], $r['first_name'], $r['midname'],
                    $r['business_name'], $r['npi'], $r['upin'], $r['general'], $r['specialty'],
                    $r['excltype'], $r['excldate'], $r['state'], $r['name_key'],
                ]);
            }
            $pdo->commit();
            return ['ok' => true, 'rows' => count($rows), 'import_id' => $importId, 'file_month' => $fileMonth];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()];
        }
    }

    /** The most recent import (the "current" list), or null if none loaded yet. */
    public static function latestImport(): ?array
    {
        $row = Database::pdo()
            ->query('SELECT * FROM leie_imports ORDER BY file_month DESC LIMIT 1')
            ->fetch();
        return $row ?: null;
    }

    /**
     * Screen one identity against the loaded list. Returns:
     *   ['result'=>'clear'|'potential_match', 'vintage'=>?YYYY-MM, 'matches'=>[...], 'loaded'=>bool]
     * 'loaded' is false when no LEIE file has been imported yet (screening can't run).
     *
     * @param array{npi?:?string,first_name?:?string,last_name?:?string,org_name?:?string} $who
     */
    public static function screen(array $who): array
    {
        $import = self::latestImport();
        if (!$import) {
            return ['result' => 'clear', 'vintage' => null, 'matches' => [], 'loaded' => false];
        }
        $pdo = Database::pdo();
        $matches = [];

        // 1) Exact NPI match — highest signal (but the file's NPI can be blank/zero).
        $npi = preg_replace('/\D/', '', (string)($who['npi'] ?? ''));
        if (strlen($npi) === 10) {
            $st = $pdo->prepare(
                'SELECT * FROM leie_exclusions WHERE import_id = ? AND npi = ? LIMIT 25'
            );
            $st->execute([$import['id'], $npi]);
            foreach ($st->fetchAll() as $m) { $m['match_on'] = 'npi'; $matches[] = $m; }
        }

        // 2) Exact normalized-name match — a review signal for human confirmation.
        $nameKey = self::nameKey(
            (string)($who['org_name'] ?? ''),
            (string)($who['last_name'] ?? ''),
            (string)($who['first_name'] ?? '')
        );
        if ($nameKey !== '') {
            $st = $pdo->prepare(
                'SELECT * FROM leie_exclusions WHERE import_id = ? AND name_key = ? LIMIT 25'
            );
            $st->execute([$import['id'], $nameKey]);
            foreach ($st->fetchAll() as $m) {
                // avoid double-listing a row already caught by NPI
                $dupe = false;
                foreach ($matches as $x) { if ($x['id'] === $m['id']) { $dupe = true; break; } }
                if (!$dupe) { $m['match_on'] = 'name'; $matches[] = $m; }
            }
        }

        return [
            'result'  => $matches ? 'potential_match' : 'clear',
            'vintage' => $import['file_month'],
            'matches' => $matches,
            'loaded'  => true,
        ];
    }

    /**
     * Screen every active provider in a tenant against the current LEIE list, recording a result
     * for each provider that hasn't yet been screened against this list's vintage. Deduped via
     * entity_reminders (marker = 'leie:<vintage>') so a fresh monthly list triggers a new sweep but
     * repeated cron runs against the same vintage are no-ops. Returns:
     *   ['screened'=>int,'matches'=>int,'vintage'=>?string,'loaded'=>bool]
     * On potential matches, queues one summary email to the tenant owner.
     */
    /**
     * Screen every active provider in a tenant against every loaded exclusion source (OIG LEIE +
     * SAM.gov). Each provider is screened once per source per list vintage (deduped via
     * entity_reminders). Records one screening row per source, and emails the owner a combined
     * summary on potential matches.
     */
    public static function sweepTenant(TenantContext $ctx, ?string $ownerEmail = null): array
    {
        // Registry of screening sources. Each yields a current import (with vintage) and a screen().
        $sources = [
            ['key' => 'oig_leie', 'label' => 'OIG LEIE', 'authority' => 'oig.hhs.gov', 'marker' => 'leie',
             'import' => self::latestImport(), 'screen' => fn(array $w) => self::screen($w)],
            ['key' => 'sam_gov', 'label' => 'SAM.gov', 'authority' => 'sam.gov', 'marker' => 'sam',
             'import' => SamService::latestImport(), 'screen' => fn(array $w) => SamService::screen($w)],
        ];
        // One source per loaded Medicaid jurisdiction (PR, and any others loaded).
        foreach (MedicaidExclusionService::loadedJurisdictions() as $mi) {
            $jur = $mi['jurisdiction'];
            $sources[] = [
                'key' => $jur === 'PR' ? 'pr_medicaid' : 'state_medicaid',
                'label' => (MedicaidExclusionService::JURISDICTIONS[$jur] ?? ($jur . ' Medicaid')),
                'authority' => $jur . ' Medicaid agency', 'marker' => 'medicaid_' . strtolower($jur),
                'import' => $mi, 'screen' => fn(array $w) => MedicaidExclusionService::screen($w, $jur),
            ];
        }
        $active = array_values(array_filter($sources, fn($s) => $s['import'] !== null));
        if (!$active) {
            return ['screened' => 0, 'matches' => 0, 'vintage' => null, 'loaded' => false, 'sources' => 0];
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT id FROM credential_holders
              WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'active'"
        );
        $st->execute([$ctx->tenantId]);
        $ids = array_column($st->fetchAll(), 'id');
        if (!$ids) return ['screened' => 0, 'matches' => 0, 'vintage' => null, 'loaded' => true, 'sources' => count($active)];

        $holders    = new Repository\HolderRepository($ctx);
        $screenings = new Repository\ScreeningRepository($ctx);
        $screened = 0; $matchNames = [];

        foreach ($ids as $hid) {
            $h = null; // resolve lazily on first source that claims this provider
            foreach ($active as $src) {
                $vintage = (string) $src['import']['file_month'];
                if (!self::claimSweep($ctx->tenantId, (string) $hid, $src['marker'] . ':' . $vintage)) continue;
                if ($h === null) { $h = $holders->find((string) $hid); if (!$h) break; }

                $scan = ($src['screen'])([
                    'npi'        => $h['npi'] ?? null,
                    'first_name' => $h['first_name'] ?? null,
                    'last_name'  => $h['last_name'] ?? null,
                    'org_name'   => $h['org_name'] ?? null,
                ]);
                $name = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')) ?: ($h['org_name'] ?? 'Provider');
                $note = null;
                if ($scan['matches']) {
                    $bits = [];
                    foreach ($scan['matches'] as $m) {
                        $who = ($m['business_name'] ?? $m['entity_name'] ?? '') ?: trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? ''));
                        $extra = $m['excltype'] ?? $m['excl_type'] ?? $m['excl_reason'] ?? '';
                        $bits[] = $who . ' [' . strtoupper((string) ($m['match_on'] ?? '')) . ($extra ? ', ' . $extra : '') . ']';
                    }
                    $note = 'Scheduled auto-screen against ' . $src['label'] . ' (' . $vintage . '). REVIEW REQUIRED — confirm identity at ' . $src['authority'] . ' before acting. Signals: ' . implode('; ', $bits);
                    $matchNames[$name] = true;
                }
                $screenings->insert([
                    'holder_id'        => (string) $hid,
                    'source'           => $src['key'],
                    'screened_on'      => today_local(),
                    'result'           => $scan['result'],
                    'reference'        => $src['label'] . ' ' . $vintage . ' (scheduled)' . ($scan['matches'] ? ' — ' . count($scan['matches']) . ' potential match(es)' : ' — no match'),
                    'screened_by'      => 'Scheduled sweep (' . $src['label'] . ' ' . $vintage . ')',
                    'screened_by_user' => null,
                    'notes'            => $note,
                ]);
                $screened++;
            }
        }

        $matchList = array_keys($matchNames);
        if ($matchList && $ownerEmail) {
            $subject = 'Exclusion screening: ' . count($matchList) . ' provider(s) need review';
            $body = "The scheduled exclusion screening flagged the following provider(s) as potential "
                  . "matches across the loaded lists (OIG LEIE / SAM.gov):\n\n  • " . implode("\n  • ", $matchList) . "\n\n"
                  . "These are review signals, not conclusions. Confirm identity at the source (oig.hhs.gov / "
                  . "sam.gov) before taking any action, then record your determination on each provider in "
                  . "FlxCoreHC.\n\n— " . $ctx->tenantName . "\n";
            MailService::queue($ownerEmail, $subject, $body, 'reminder', null, $ctx->tenantId);
        }

        return ['screened' => $screened, 'matches' => count($matchList), 'vintage' => null, 'loaded' => true, 'sources' => count($active)];
    }

    /** Screen every active, non-demo tenant against every loaded source. */
    public static function sweepAll(): array
    {
        if (self::latestImport() === null && SamService::latestImport() === null) {
            return ['tenants' => 0, 'screened' => 0, 'matches' => 0, 'loaded' => false];
        }

        $tenants = Database::pdo()->query(
            "SELECT t.id, t.name, t.vertical, t.org_shape,
                    (SELECT MIN(u2.id) FROM users u2 WHERE u2.tenant_id = t.id AND u2.role = 'tenant_owner') AS uid,
                    (SELECT u3.email FROM users u3 WHERE u3.tenant_id = t.id AND u3.role = 'tenant_owner' ORDER BY u3.id LIMIT 1) AS owner_email
             FROM tenants t
             WHERE t.status = 'active' AND t.is_demo = 0"
        )->fetchAll();

        $totScreened = 0; $totMatches = 0; $nTenants = 0;
        foreach ($tenants as $t) {
            $ctx = new TenantContext($t['id'], $t['uid'] ?: '', 'tenant_owner',
                $t['name'], $t['vertical'] ?: 'healthcare', $t['org_shape'] ?: 'agency', 'System', false);
            $r = self::sweepTenant($ctx, $t['owner_email'] ?: null);
            if ($r['screened'] > 0) $nTenants++;
            $totScreened += $r['screened'];
            $totMatches  += $r['matches'];
        }
        return ['tenants' => $nTenants, 'screened' => $totScreened, 'matches' => $totMatches, 'loaded' => true];
    }

    /** Claim a per-provider-per-vintage sweep slot. Returns true if newly claimed (should screen). */
    private static function claimSweep(string $tenantId, string $holderId, string $vintage): bool
    {
        try {
            $n = Database::pdo()->prepare(
                'INSERT IGNORE INTO entity_reminders
                   (id, tenant_id, entity_type, entity_id, marker, channel, queued_at, sent_at)
                 VALUES (?,?,?,?,?,\'system\',?,?)'
            );
            $now = utc_now();
            $n->execute([uuid4(), $tenantId, 'leie_sweep', $holderId, 'leie:' . $vintage, $now, $now]);
            return $n->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---- CSV parsing --------------------------------------------------------

    /**
     * Parse the LEIE CSV into normalized rows. Returns null if the header is unrecognized.
     * @return array<int,array<string,?string>>|null
     */
    private static function parseCsv(string $csv): ?array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // strip UTF-8 BOM
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!$lines || count($lines) < 2) return null;

        $header = flx_str_getcsv(array_shift($lines));
        $idx = [];
        foreach ($header as $i => $h) { $idx[strtoupper(trim($h))] = $i; }
        // The file must at least carry the identity columns we rely on.
        if (!isset($idx['LASTNAME']) || !isset($idx['BUSNAME']) || !isset($idx['NPI'])) {
            return null;
        }
        $get = function (array $cols, string $key) use ($idx): ?string {
            if (!isset($idx[$key])) return null;
            $v = trim((string)($cols[$idx[$key]] ?? ''));
            return $v === '' ? null : $v;
        };

        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cols = flx_str_getcsv($line);
            $last = $get($cols, 'LASTNAME');
            $first = $get($cols, 'FIRSTNAME');
            $bus = $get($cols, 'BUSNAME');
            $npiRaw = preg_replace('/\D/', '', (string)$get($cols, 'NPI'));
            $npi = ($npiRaw !== '' && $npiRaw !== '0000000000' && strlen($npiRaw) === 10) ? $npiRaw : null;
            $out[] = [
                'last_name'     => self::up($last),
                'first_name'    => self::up($first),
                'midname'       => self::up($get($cols, 'MIDNAME')),
                'business_name' => self::up($bus),
                'npi'           => $npi,
                'upin'          => $get($cols, 'UPIN'),
                'general'       => $get($cols, 'GENERAL'),
                'specialty'     => $get($cols, 'SPECIALTY'),
                'excltype'      => $get($cols, 'EXCLTYPE'),
                'excldate'      => $get($cols, 'EXCLDATE'),
                'state'         => $get($cols, 'STATE'),
                'name_key'      => self::nameKey((string)$bus, (string)$last, (string)$first),
            ];
        }
        return $out;
    }

    /** Normalized match key: business name if present, else 'LAST|FIRST'. Uppercased, punctuation stripped. */
    public static function nameKey(string $business, string $last, string $first): string
    {
        $norm = fn(string $s): string => preg_replace('/\s+/', ' ',
            trim(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($s))));
        $business = $norm($business);
        if ($business !== '') return $business;
        $last = $norm($last); $first = $norm($first);
        if ($last === '' && $first === '') return '';
        return $last . '|' . $first;
    }

    private static function up(?string $s): ?string
    {
        if ($s === null) return null;
        $s = trim(strtoupper($s));
        return $s === '' ? null : $s;
    }

    /**
     * Fetch the current LEIE CSV from OIG over HTTPS (real hosting only). Returns the body or null.
     * The sandbox cannot reach oig.hhs.gov; on real hosting outbound HTTPS is open.
     */
    public static function download(): ?string
    {
        if (function_exists('curl_init')) {
            $ch = \curl_init(self::DOWNLOAD_URL);
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'FlxCoreHC/1.0 (+exclusion screening)',
            ]);
            $body = \curl_exec($ch);
            $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);
            return ($body === false || $code >= 400) ? null : (string) $body;
        }
        return null;
    }
}
