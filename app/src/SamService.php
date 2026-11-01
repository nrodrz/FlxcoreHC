<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * SAM.gov exclusions screening — the government-wide debarment/suspension list (successor to EPLS).
 *
 * SAM offers a REST API and a downloadable public extract; both need a free SAM.gov API key, so the
 * paste/upload path here needs no key. The extract/API CSV is header-based with classification types
 * Firm / Individual / Vessel / Special Entity Designation.
 *
 * SAM records carry NO NPI, so provider matching is name-based only — a weaker signal than the NPI
 * matches LEIE supports. Every SAM match is therefore a REVIEW SIGNAL for confirmation at sam.gov,
 * never a verdict. Mirrors LeieService's public shape (ingestCsv / latestImport / screen) so the
 * screening sweep can treat both sources uniformly.
 */
final class SamService
{
    /** Column aliases -> canonical field. Header matched case-insensitively, spaces/underscores ignored. */
    private const ALIASES = [
        'classification' => 'classification', 'classificationtype' => 'classification',
        'name' => 'entity_name', 'entityname' => 'entity_name', 'exclusionname' => 'entity_name',
        'firstname' => 'first_name', 'first' => 'first_name',
        'lastname' => 'last_name', 'last' => 'last_name',
        'middlename' => 'middle_name', 'middle' => 'middle_name',
        'ueisam' => 'uei', 'uei' => 'uei', 'uniqueentityid' => 'uei',
        'cage' => 'cage', 'cagecode' => 'cage',
        'exclusiontype' => 'excl_type', 'excltype' => 'excl_type',
        'exclusionprogram' => 'excl_program', 'exclprogram' => 'excl_program',
        'excludingagency' => 'excl_agency', 'agencyname' => 'excl_agency',
        'activationdate' => 'active_date', 'activedate' => 'active_date', 'creationdate' => 'active_date',
        'state' => 'state', 'stateprovince' => 'state',
    ];

    public static function ingestCsv(string $csv, string $fileMonth, ?string $userId = null, ?string $note = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $fileMonth)) {
            return ['ok' => false, 'error' => 'File month must be YYYY-MM.'];
        }
        $rows = self::parseCsv($csv);
        if ($rows === null) {
            return ['ok' => false, 'error' => 'That does not look like a SAM.gov exclusions CSV (no recognizable name column in the header).'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('SELECT id FROM sam_imports WHERE file_month = ?');
            $del->execute([$fileMonth]);
            if ($old = $del->fetchColumn()) {
                $pdo->prepare('DELETE FROM sam_imports WHERE id = ?')->execute([$old]);
            }
            $importId = uuid4();
            $pdo->prepare(
                'INSERT INTO sam_imports (id, file_month, row_count, source_note, imported_by, imported_at)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$importId, $fileMonth, count($rows), $note, $userId, utc_now()]);

            $ins = $pdo->prepare(
                'INSERT INTO sam_exclusions
                   (id, import_id, classification, entity_name, first_name, last_name, middle_name,
                    uei, cage, excl_type, excl_program, excl_agency, active_date, state, name_key)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($rows as $r) {
                $ins->execute([
                    uuid4(), $importId, $r['classification'], $r['entity_name'], $r['first_name'],
                    $r['last_name'], $r['middle_name'], $r['uei'], $r['cage'], $r['excl_type'],
                    $r['excl_program'], $r['excl_agency'], $r['active_date'], $r['state'], $r['name_key'],
                ]);
            }
            $pdo->commit();
            return ['ok' => true, 'rows' => count($rows), 'import_id' => $importId, 'file_month' => $fileMonth];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()];
        }
    }

    public static function latestImport(): ?array
    {
        $row = Database::pdo()->query('SELECT * FROM sam_imports ORDER BY file_month DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    /**
     * Screen one identity against the loaded SAM list (name-based). Same shape as LeieService::screen.
     * @param array{npi?:?string,first_name?:?string,last_name?:?string,org_name?:?string} $who
     */
    public static function screen(array $who): array
    {
        $import = self::latestImport();
        if (!$import) {
            return ['result' => 'clear', 'vintage' => null, 'matches' => [], 'loaded' => false];
        }
        $nameKey = self::nameKey(
            (string)($who['org_name'] ?? ''),
            (string)($who['last_name'] ?? ''),
            (string)($who['first_name'] ?? '')
        );
        $matches = [];
        if ($nameKey !== '') {
            $st = Database::pdo()->prepare('SELECT * FROM sam_exclusions WHERE import_id = ? AND name_key = ? LIMIT 25');
            $st->execute([$import['id'], $nameKey]);
            foreach ($st->fetchAll() as $m) { $m['match_on'] = 'name'; $matches[] = $m; }
        }
        return [
            'result'  => $matches ? 'potential_match' : 'clear',
            'vintage' => $import['file_month'],
            'matches' => $matches,
            'loaded'  => true,
        ];
    }

    // ---- CSV parsing --------------------------------------------------------

    /** @return array<int,array<string,?string>>|null */
    private static function parseCsv(string $csv): ?array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!$lines || count($lines) < 2) return null;

        $header = flx_str_getcsv(array_shift($lines));
        $map = []; // column index -> canonical field
        foreach ($header as $i => $h) {
            $norm = preg_replace('/[^a-z0-9]/', '', strtolower(trim($h)));
            if (isset(self::ALIASES[$norm])) $map[self::ALIASES[$norm]] = $i;
        }
        // need at least a name column of some kind
        if (!isset($map['entity_name']) && !isset($map['last_name']) && !isset($map['first_name'])) {
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
            $entity = self::up($get($cols, 'entity_name'));
            $last = self::up($get($cols, 'last_name'));
            $first = self::up($get($cols, 'first_name'));
            $out[] = [
                'classification' => $get($cols, 'classification'),
                'entity_name'    => $entity,
                'first_name'     => $first,
                'last_name'      => $last,
                'middle_name'    => self::up($get($cols, 'middle_name')),
                'uei'            => $get($cols, 'uei'),
                'cage'           => $get($cols, 'cage'),
                'excl_type'      => $get($cols, 'excl_type'),
                'excl_program'   => $get($cols, 'excl_program'),
                'excl_agency'    => $get($cols, 'excl_agency'),
                'active_date'    => $get($cols, 'active_date'),
                'state'          => $get($cols, 'state'),
                'name_key'       => self::nameKey((string)$entity, (string)$last, (string)$first),
            ];
        }
        return $out;
    }

    /** Same normalization as LeieService so a name matches identically across sources. */
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
}
