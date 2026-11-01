<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * State/territory Medicaid exclusion screening — starting with Puerto Rico (PR).
 *
 * Many states publish their own Medicaid exclusion list separate from the federal OIG LEIE and
 * SAM.gov lists, in varying formats. This ingests any of them from a CSV tagged by jurisdiction, so PR
 * works today and other states drop in with no new code. Matching is by exact NPI (when the list
 * carries one) and by normalized name — the same name normalization LEIE/SAM use, so a name matches
 * identically across every source. Every match is a REVIEW SIGNAL stamped with the list vintage,
 * confirmed at the issuing agency before any action.
 *
 * Mirrors LeieService / SamService's public shape (ingestCsv / latestImport / screen) so the
 * screening sweep can treat it as one more source.
 */
final class MedicaidExclusionService
{
    /** Jurisdictions we recognize for import (extend as lists are added). */
    public const JURISDICTIONS = ['PR' => 'Puerto Rico Medicaid', 'FL' => 'Florida Medicaid', 'TX' => 'Texas Medicaid'];

    /** Column aliases -> canonical field (English + common Spanish headers for PR). */
    private const ALIASES = [
        'lastname' => 'last_name', 'last' => 'last_name', 'apellido' => 'last_name', 'apellidos' => 'last_name',
        'firstname' => 'first_name', 'first' => 'first_name', 'nombre' => 'first_name',
        'businessname' => 'business_name', 'entityname' => 'business_name', 'organization' => 'business_name',
        'entidad' => 'business_name', 'empresa' => 'business_name', 'name' => 'business_name', 'providername' => 'business_name',
        'npi' => 'npi',
        'providertype' => 'provider_type', 'type' => 'provider_type', 'tipo' => 'provider_type',
        'reason' => 'excl_reason', 'exclusionreason' => 'excl_reason', 'razon' => 'excl_reason', 'motivo' => 'excl_reason',
        'excldate' => 'excl_date', 'exclusiondate' => 'excl_date', 'date' => 'excl_date', 'fecha' => 'excl_date',
    ];

    public static function ingestCsv(string $csv, string $jurisdiction, string $fileMonth, ?string $userId = null, ?string $note = null): array
    {
        $jur = strtoupper(trim($jurisdiction));
        if (!isset(self::JURISDICTIONS[$jur])) {
            return ['ok' => false, 'error' => 'Unknown jurisdiction "' . $jur . '".'];
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $fileMonth)) {
            return ['ok' => false, 'error' => 'File month must be YYYY-MM.'];
        }
        $rows = self::parseCsv($csv, $jur);
        if ($rows === null) {
            return ['ok' => false, 'error' => 'That does not look like a provider list (no name column recognized in the header).'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('SELECT id FROM medicaid_imports WHERE jurisdiction = ? AND file_month = ?');
            $del->execute([$jur, $fileMonth]);
            if ($old = $del->fetchColumn()) {
                $pdo->prepare('DELETE FROM medicaid_imports WHERE id = ?')->execute([$old]);
            }
            $importId = uuid4();
            $pdo->prepare(
                'INSERT INTO medicaid_imports (id, jurisdiction, file_month, row_count, source_note, imported_by, imported_at)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$importId, $jur, $fileMonth, count($rows), $note, $userId, utc_now()]);

            $ins = $pdo->prepare(
                'INSERT INTO medicaid_exclusions
                   (id, import_id, jurisdiction, last_name, first_name, business_name, npi, provider_type, excl_reason, excl_date, name_key)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($rows as $r) {
                $ins->execute([
                    uuid4(), $importId, $jur, $r['last_name'], $r['first_name'], $r['business_name'],
                    $r['npi'], $r['provider_type'], $r['excl_reason'], $r['excl_date'], $r['name_key'],
                ]);
            }
            $pdo->commit();
            return ['ok' => true, 'rows' => count($rows), 'import_id' => $importId, 'jurisdiction' => $jur, 'file_month' => $fileMonth];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()];
        }
    }

    /** Latest import per jurisdiction, or the single latest across all if $jur is null. */
    public static function latestImport(?string $jur = null): ?array
    {
        if ($jur !== null) {
            $st = Database::pdo()->prepare('SELECT * FROM medicaid_imports WHERE jurisdiction = ? ORDER BY file_month DESC LIMIT 1');
            $st->execute([strtoupper($jur)]);
            return $st->fetch() ?: null;
        }
        $row = Database::pdo()->query('SELECT * FROM medicaid_imports ORDER BY file_month DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    /** All loaded jurisdictions with their current vintage. @return array<int,array{jurisdiction:string,file_month:string,id:string}> */
    public static function loadedJurisdictions(): array
    {
        return Database::pdo()->query(
            'SELECT m.* FROM medicaid_imports m
              JOIN (SELECT jurisdiction, MAX(file_month) mx FROM medicaid_imports GROUP BY jurisdiction) latest
                ON latest.jurisdiction = m.jurisdiction AND latest.mx = m.file_month
             ORDER BY m.jurisdiction'
        )->fetchAll();
    }

    /**
     * Screen an identity against every loaded jurisdiction (or one specific jurisdiction).
     * Returns the standard shape; 'vintage' is a "PR 2026-08" style label when multiple load.
     * @param array{npi?:?string,first_name?:?string,last_name?:?string,org_name?:?string} $who
     */
    public static function screen(array $who, ?string $onlyJur = null): array
    {
        $imports = $onlyJur !== null
            ? array_filter([self::latestImport($onlyJur)])
            : self::loadedJurisdictions();
        if (!$imports) {
            return ['result' => 'clear', 'vintage' => null, 'matches' => [], 'loaded' => false];
        }
        $pdo = Database::pdo();
        $npi = preg_replace('/\D/', '', (string)($who['npi'] ?? ''));
        $nameKey = self::nameKey((string)($who['org_name'] ?? ''), (string)($who['last_name'] ?? ''), (string)($who['first_name'] ?? ''));

        $matches = []; $vintages = [];
        foreach ($imports as $imp) {
            $vintages[] = $imp['jurisdiction'] . ' ' . $imp['file_month'];
            if (strlen($npi) === 10) {
                $st = $pdo->prepare('SELECT * FROM medicaid_exclusions WHERE import_id = ? AND npi = ? LIMIT 25');
                $st->execute([$imp['id'], $npi]);
                foreach ($st->fetchAll() as $m) { $m['match_on'] = 'npi'; $matches[] = $m; }
            }
            if ($nameKey !== '') {
                $st = $pdo->prepare('SELECT * FROM medicaid_exclusions WHERE import_id = ? AND name_key = ? LIMIT 25');
                $st->execute([$imp['id'], $nameKey]);
                foreach ($st->fetchAll() as $m) {
                    $dupe = false; foreach ($matches as $x) { if ($x['id'] === $m['id']) { $dupe = true; break; } }
                    if (!$dupe) { $m['match_on'] = 'name'; $matches[] = $m; }
                }
            }
        }
        return [
            'result'  => $matches ? 'potential_match' : 'clear',
            'vintage' => implode(', ', $vintages),
            'matches' => $matches,
            'loaded'  => true,
        ];
    }

    // ---- CSV parsing --------------------------------------------------------

    /** @return array<int,array<string,?string>>|null */
    private static function parseCsv(string $csv, string $jur): ?array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!$lines || count($lines) < 2) return null;

        $header = flx_str_getcsv(array_shift($lines));
        $map = [];
        foreach ($header as $i => $h) {
            $norm = preg_replace('/[^a-z0-9]/', '', strtolower(trim($h)));
            if (isset(self::ALIASES[$norm])) $map[self::ALIASES[$norm]] = $i;
        }
        if (!isset($map['business_name']) && !isset($map['last_name']) && !isset($map['first_name'])) {
            return null;
        }
        $get = function (array $cols, string $field) use ($map): ?string {
            if (!isset($map[$field])) return null;
            $v = trim((string)($cols[$map[$field]] ?? ''));
            return $v === '' ? null : $v;
        };
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cols = flx_str_getcsv($line);
            $last = self::up($get($cols, 'last_name'));
            $first = self::up($get($cols, 'first_name'));
            $biz = self::up($get($cols, 'business_name'));
            $npiRaw = preg_replace('/\D/', '', (string)$get($cols, 'npi'));
            $npi = ($npiRaw !== '' && $npiRaw !== '0000000000' && strlen($npiRaw) === 10) ? $npiRaw : null;
            $out[] = [
                'last_name'     => $last,
                'first_name'    => $first,
                'business_name' => $biz,
                'npi'           => $npi,
                'provider_type' => $get($cols, 'provider_type'),
                'excl_reason'   => $get($cols, 'excl_reason'),
                'excl_date'     => $get($cols, 'excl_date'),
                'name_key'      => self::nameKey((string)$biz, (string)$last, (string)$first),
            ];
        }
        return $out;
    }

    public static function nameKey(string $business, string $last, string $first): string
    {
        $norm = fn(string $s): string => preg_replace('/\s+/', ' ', trim(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($s))));
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
}
