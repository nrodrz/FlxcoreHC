<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Repository\HolderRepository;

/**
 * Bulk provider import from CSV.
 *
 * Two-phase by design: parse+validate produces a preview (what would be created, what has errors,
 * what already exists) and commit actually inserts. Nothing is created from a file without the user
 * seeing the preview first.
 *
 * Accepted columns (header row, case-insensitive; extra columns ignored):
 *   holder_type      individual|organization   (default: individual, or organization if org_name set)
 *   provider_type    physician|nurse|lab|clinic|group
 *   first_name       (individuals)
 *   last_name        (individuals)
 *   org_name         (organizations)
 *   npi              10 digits (optional; used for de-duplication)
 *   email            (optional)
 *   department       (optional)
 *   role_title       (optional)
 *
 * De-duplication: a row whose NPI already belongs to a provider in this tenant is skipped.
 */
final class ProviderImportService
{
    /** Columns clients download. `group` = client group / department; `location` = practice site. Both are created by name if new. */
    public const TEMPLATE_HEADER = 'holder_type,provider_type,first_name,last_name,org_name,npi,email,group,location,role_title';

    /** Valid provider_type values — the same healthcare list the pickers use (Vocab is the source of truth). */
    private static function validTypes(): array { return array_keys(Vocab::appliesToOptions('healthcare')); }

    public function __construct(private readonly TenantContext $ctx) {}

    /**
     * Parse + validate a CSV string into a preview.
     * @return array{ok:bool,error?:string,rows:array<int,array>,summary:array<string,int>}
     */
    public function preview(string $csv): array
    {
        $parsed = $this->parse($csv);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'Could not read a header row. The first line must name the columns (e.g. ' . self::TEMPLATE_HEADER . ').', 'rows' => [], 'summary' => []];
        }
        [$header, $records] = $parsed;
        if ($records === []) {
            return ['ok' => false, 'error' => 'No data rows found under the header.', 'rows' => [], 'summary' => []];
        }

        $holders = new HolderRepository($this->ctx);
        $rows = [];
        $seenNpi = [];       // within-file duplicate detection
        $seenName = [];
        $counts = ['create' => 0, 'skip' => 0, 'error' => 0];

        foreach ($records as $i => $rec) {
            $r = $this->normalizeRow($rec, $header);
            $r['line'] = $i + 2; // 1-based + header
            $errors = [];

            $type = $r['holder_type'];
            if ($type === 'individual') {
                if ($r['first_name'] === null && $r['last_name'] === null) {
                    $errors[] = 'Individual needs a first or last name.';
                }
            } else { // organization
                if ($r['org_name'] === null) $errors[] = 'Organization needs an org_name.';
            }
            if ($r['npi'] !== null && ($e = Npi::error($r['npi'])) !== null) {
                $errors[] = $e;
            }
            if ($r['email'] !== null && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email is not valid.';
            }
            if ($r['provider_type'] !== null && !in_array($r['provider_type'], self::validTypes(), true)) {
                $errors[] = 'provider_type must be one of: ' . implode(', ', self::validTypes()) . '.';
            }

            $status = 'create';
            $reason = null;

            if ($errors) {
                $status = 'error'; $reason = implode(' ', $errors);
            } else {
                // duplicate within the file
                if ($r['npi'] !== null && isset($seenNpi[$r['npi']])) {
                    $status = 'skip'; $reason = 'Duplicate NPI within the file (line ' . $seenNpi[$r['npi']] . ').';
                } else {
                    $nameKey = $type === 'organization'
                        ? 'ORG|' . strtoupper((string)$r['org_name'])
                        : 'IND|' . strtoupper((string)$r['last_name']) . '|' . strtoupper((string)$r['first_name']);
                    if ($r['npi'] === null && isset($seenName[$nameKey])) {
                        $status = 'skip'; $reason = 'Duplicate name within the file (line ' . $seenName[$nameKey] . ').';
                    } elseif ($r['npi'] !== null && $holders->findByNpi($r['npi'])) {
                        // existing provider in this tenant
                        $status = 'skip'; $reason = 'A provider with this NPI already exists.';
                    }
                }
                if ($status === 'create') {
                    if ($r['npi'] !== null) $seenNpi[$r['npi']] = $r['line'];
                    else $seenName[$type === 'organization' ? 'ORG|' . strtoupper((string)$r['org_name']) : 'IND|' . strtoupper((string)$r['last_name']) . '|' . strtoupper((string)$r['first_name'])] = $r['line'];
                }
            }

            $r['status'] = $status;
            $r['reason'] = $reason;
            $rows[] = $r;
            $counts[$status]++;
        }

        return ['ok' => true, 'rows' => $rows, 'summary' => $counts];
    }

    /**
     * Commit the importable rows. Re-validates and re-checks capacity + NPI existence at insert time
     * (the preview may be stale). Returns ['created'=>int,'skipped'=>int,'errors'=>int,'capacity'=>bool].
     */
    public function commit(string $csv): array
    {
        $pre = $this->preview($csv);
        if (empty($pre['ok'])) {
            return ['created' => 0, 'skipped' => 0, 'errors' => 0, 'capacity' => true, 'error' => $pre['error'] ?? 'Invalid file.'];
        }
        $holders = new HolderRepository($this->ctx);
        $created = 0; $skipped = 0; $errors = 0; $hitCap = false;

        foreach ($pre['rows'] as $r) {
            if ($r['status'] === 'error') { $errors++; continue; }
            if ($r['status'] === 'skip')  { $skipped++; continue; }

            if (!PlanService::canAddHolder($this->ctx->tenantId)) { $hitCap = true; break; }
            // guard against a race: skip if the NPI now exists
            if ($r['npi'] !== null && $holders->findByNpi($r['npi'])) { $skipped++; continue; }

            $hid = $holders->insert([
                'holder_type'   => $r['holder_type'],
                'provider_type' => $r['provider_type'],
                'first_name'    => $r['first_name'],
                'last_name'     => $r['last_name'],
                'org_name'      => $r['org_name'],
                'npi'           => $r['npi'],
                'email'         => $r['email'],
                'department'    => $r['group'] ?? null,   // legacy text field mirrors the group name
                'role_title'    => $r['role_title'],
                'vertical'      => $this->ctx->vertical,
                'status'        => 'active',
            ]);
            // Auto-apply the profession's requirement template so imported providers arrive with their checklist.
            try { if (!empty($r['provider_type'])) (new \FlxCoreHC\TemplateService($this->ctx))->applyByProviderType((string)$hid, $r['provider_type'], new \FlxCoreHC\Repository\ProducerRequirementRepository($this->ctx)); } catch (\Throwable $e) {}
            // Place the provider: group and location are looked up by name (case-insensitive) or
            // created on the fly, so a spreadsheet can onboard a whole roster with its structure.
            if (!empty($r['group'])) {
                $gid = $this->groupIdByName($r['group']);
                if ($gid) GroupService::add($this->ctx, $hid, $gid, null, true);
            }
            if (!empty($r['location'])) {
                $lid = $this->locationIdByName($r['location']);
                if ($lid) LocationService::affiliate($this->ctx, $hid, $lid, null, true);
            }
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'capacity' => !$hitCap];
    }

    private array $groupCache = [];
    private array $locationCache = [];

    private function groupIdByName(string $name): ?string
    {
        $key = mb_strtolower(trim($name)); if ($key === '') return null;
        if (isset($this->groupCache[$key])) return $this->groupCache[$key];
        $repo = new \FlxCoreHC\Repository\GroupRepository($this->ctx);
        foreach ($repo->all([], 'name ASC') as $g) { if (mb_strtolower((string)$g['name']) === $key) return $this->groupCache[$key] = $g['id']; }
        return $this->groupCache[$key] = $repo->insert(['name' => trim($name), 'status' => 'active']);
    }

    private function locationIdByName(string $name): ?string
    {
        $key = mb_strtolower(trim($name)); if ($key === '') return null;
        if (isset($this->locationCache[$key])) return $this->locationCache[$key];
        $repo = new \FlxCoreHC\Repository\LocationRepository($this->ctx);
        foreach ($repo->all([], 'name ASC') as $l) { if (mb_strtolower((string)$l['name']) === $key) return $this->locationCache[$key] = $l['id']; }
        return $this->locationCache[$key] = $repo->insert(['name' => trim($name), 'status' => 'active']);
    }

    // ---- parsing ------------------------------------------------------------

    /** @return array{0:array<string,int>,1:array<int,array<int,string>>}|null */
    private function parse(string $csv): ?array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // strip BOM
        $lines = preg_split('/\r\n|\n|\r/', rtrim($csv));
        if (!$lines || trim($lines[0]) === '') return null;

        $header = flx_str_getcsv(array_shift($lines));
        $idx = [];
        foreach ($header as $i => $h) { $idx[strtolower(trim($h))] = $i; }
        // must recognize at least one identity column
        if (!isset($idx['first_name']) && !isset($idx['last_name']) && !isset($idx['org_name'])) {
            return null;
        }
        $records = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $records[] = flx_str_getcsv($line);
        }
        return [$idx, $records];
    }

    /** @param array<string,int> $idx */
    private function normalizeRow(array $cols, array $idx): array
    {
        $get = function (string $key) use ($cols, $idx): ?string {
            if (!isset($idx[$key])) return null;
            $v = trim((string)($cols[$idx[$key]] ?? ''));
            return $v === '' ? null : $v;
        };
        $org = $get('org_name');
        $htRaw = strtolower((string)$get('holder_type'));
        $type = $htRaw === 'organization' ? 'organization'
              : ($htRaw === 'individual' ? 'individual' : ($org !== null ? 'organization' : 'individual'));
        $pt = $get('provider_type');
        $npi = $get('npi');
        if ($npi !== null) $npi = preg_replace('/\D/', '', $npi);

        return [
            'holder_type'   => $type,
            'provider_type' => $pt !== null ? strtolower($pt) : null,
            'first_name'    => $type === 'individual' ? $get('first_name') : null,
            'last_name'     => $type === 'individual' ? $get('last_name') : null,
            'org_name'      => $type === 'organization' ? $org : null,
            'npi'           => ($npi !== null && $npi !== '') ? $npi : null,
            'email'         => $get('email'),
            'group'         => $get('group') ?? $get('department'),
            'location'      => $get('location'),
            'department'    => $get('department'),
            'role_title'    => $get('role_title'),
        ];
    }
}
