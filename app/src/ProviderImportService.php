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

    /** One file may not carry more rows than this (same cap as the credential import). */
    public const MAX_ROWS = 2000;

    /**
     * Parse + validate a CSV string into a preview.
     * Statuses: create | skip (already there / repeated in the file) | limit (over the plan) | error.
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
        if (count($records) > self::MAX_ROWS) {
            return ['ok' => false, 'error' => 'That file has more than ' . self::MAX_ROWS . ' rows — split it into smaller files.', 'rows' => [], 'summary' => []];
        }

        $holders = new HolderRepository($this->ctx);
        // Who is already here (decrypted once): e-mails and organization names. NPIs use the blind index.
        $haveEmail = []; $haveOrg = [];
        foreach ($holders->all([], 'created_at DESC', 20000) as $h) {
            if (!empty($h['email'])) $haveEmail[mb_strtolower(trim((string) $h['email']))] = true;
            if (($h['holder_type'] ?? '') === 'organization' && !empty($h['org_name'])) $haveOrg[mb_strtolower(trim((string) $h['org_name']))] = true;
        }
        $room = PlanService::holderRoom($this->ctx->tenantId);   // null = unlimited
        $knownGroups = $this->existingNames(new \FlxCoreHC\Repository\GroupRepository($this->ctx));
        $knownLocs   = $this->existingNames(new \FlxCoreHC\Repository\LocationRepository($this->ctx));
        $newGroups = []; $newLocs = [];

        $rows = [];
        $seenNpi = []; $seenEmail = []; $seenOrg = [];   // within-file duplicate detection (value => line)
        $counts = ['create' => 0, 'skip' => 0, 'limit' => 0, 'error' => 0];

        foreach ($records as $i => $rec) {
            $r = $this->normalizeRow($rec, $header);
            $r['line'] = $i + 2; // 1-based + header
            $errors = [];

            $type = $r['holder_type'];
            if ($type === 'individual') {
                if ($r['first_name'] === null || $r['last_name'] === null) {
                    $errors[] = 'Individual needs a first and a last name.';
                }
                // Same rule as the Add provider form: the e-mail becomes the portal login.
                if ($r['email'] === null) $errors[] = 'Individual needs an e-mail (it becomes the portal login).';
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
            $emailKey = $r['email'] !== null ? mb_strtolower($r['email']) : null;
            $orgKey = $type === 'organization' && $r['org_name'] !== null ? mb_strtolower($r['org_name']) : null;

            if ($errors) {
                $status = 'error'; $reason = implode(' ', $errors);
            } elseif ($r['npi'] !== null && isset($seenNpi[$r['npi']])) {
                $status = 'skip'; $reason = 'Duplicate NPI within the file (line ' . $seenNpi[$r['npi']] . ').';
            } elseif ($emailKey !== null && isset($seenEmail[$emailKey])) {
                $status = 'skip'; $reason = 'Duplicate e-mail within the file (line ' . $seenEmail[$emailKey] . ').';
            } elseif ($orgKey !== null && isset($seenOrg[$orgKey])) {
                $status = 'skip'; $reason = 'Duplicate organization name within the file (line ' . $seenOrg[$orgKey] . ').';
            } elseif ($r['npi'] !== null && $holders->findByNpi($r['npi'])) {
                $status = 'skip'; $reason = 'A provider with this NPI already exists.';
            } elseif ($emailKey !== null && isset($haveEmail[$emailKey])) {
                $status = 'skip'; $reason = 'A provider with this e-mail already exists.';
            } elseif ($orgKey !== null && isset($haveOrg[$orgKey])) {
                $status = 'skip'; $reason = 'An organization with this name already exists.';
            }
            if ($status === 'create' && $room !== null && $counts['create'] >= $room) {
                $status = 'limit'; $reason = 'Over your plan limit — upgrade to add this provider.';
            }
            if ($status === 'create') {
                if ($r['npi'] !== null) $seenNpi[$r['npi']] = $r['line'];
                if ($emailKey !== null) $seenEmail[$emailKey] = $r['line'];
                if ($orgKey !== null) $seenOrg[$orgKey] = $r['line'];
                // What the placement will do: use an existing group / location by name, or create it.
                $r['group_new'] = $r['group'] !== null && !isset($knownGroups[mb_strtolower($r['group'])]);
                $r['location_new'] = $r['location'] !== null && !isset($knownLocs[mb_strtolower($r['location'])]);
                if ($r['group_new']) $newGroups[mb_strtolower($r['group'])] = $r['group'];
                if ($r['location_new']) $newLocs[mb_strtolower($r['location'])] = $r['location'];
            }

            $r['status'] = $status;
            $r['reason'] = $reason;
            $rows[] = $r;
            $counts[$status]++;
        }

        return ['ok' => true, 'rows' => $rows, 'summary' => $counts,
                'new_groups' => array_values($newGroups), 'new_locations' => array_values($newLocs)];
    }

    /** @return array<string,true> lower-cased names of the active rows of a group / location repository */
    private function existingNames(object $repo): array
    {
        $out = [];
        foreach ($repo->all([], 'name ASC') as $g) { $out[mb_strtolower(trim((string) $g['name']))] = true; }
        return $out;
    }

    /**
     * Commit the importable rows. Re-validates at insert time (the preview may be stale). A row that fails is
     * counted and reported, and the rest carry on; the plan limit was already applied in the preview.
     * Returns created/skipped/errors counts, the ids created, the rows that did not come in, and how many arrived
     * without their requirement checklist.
     */
    public function commit(string $csv): array
    {
        $pre = $this->preview($csv);
        if (empty($pre['ok'])) {
            return ['created' => 0, 'skipped' => 0, 'errors' => 0, 'capacity' => true, 'error' => $pre['error'] ?? 'Invalid file.'];
        }
        $holders = new HolderRepository($this->ctx);
        $created = 0; $skipped = 0; $errors = 0; $hitCap = false; $noChecklist = 0;
        $ids = []; $rejected = [];

        foreach ($pre['rows'] as $r) {
            if ($r['status'] === 'error') { $errors++; $rejected[] = $r; continue; }
            if ($r['status'] === 'skip')  { $skipped++; $rejected[] = $r; continue; }
            if ($r['status'] === 'limit' || !PlanService::canAddHolder($this->ctx->tenantId)) {
                $hitCap = true; $r['status'] = 'limit'; $r['reason'] = 'Over your plan limit — upgrade to add this provider.'; $rejected[] = $r; continue;
            }
            // guard against a race: skip if the NPI now exists
            if ($r['npi'] !== null && $holders->findByNpi($r['npi'])) { $skipped++; $r['status'] = 'skip'; $r['reason'] = 'A provider with this NPI already exists.'; $rejected[] = $r; continue; }

            try {
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
            } catch (\Throwable $e) {
                $errors++; $r['status'] = 'error'; $r['reason'] = 'Could not be saved.'; $rejected[] = $r; continue;
            }
            // Auto-apply the profession's requirement checklist so imported providers arrive with it; count the ones that did not get it.
            if (!empty($r['provider_type'])) {
                try {
                    [$okT] = (new \FlxCoreHC\TemplateService($this->ctx))->applyByProviderType((string) $hid, $r['provider_type'], new \FlxCoreHC\Repository\ProducerRequirementRepository($this->ctx));
                    if (!$okT) $noChecklist++;
                } catch (\Throwable $e) { $noChecklist++; }
            } else {
                $noChecklist++;
            }
            // Place the provider: group and location are looked up by name (case-insensitive) or
            // created on the fly, so a spreadsheet can onboard a whole roster with its structure.
            try {
                if (!empty($r['group'])) {
                    $gid = $this->groupIdByName($r['group']);
                    if ($gid) GroupService::add($this->ctx, $hid, $gid, null, true);
                }
                if (!empty($r['location'])) {
                    $lid = $this->locationIdByName($r['location']);
                    if ($lid) LocationService::affiliate($this->ctx, $hid, $lid, null, true);
                }
            } catch (\Throwable $e) { /* the provider exists; placement can be set from the provider page */ }
            $created++; $ids[] = (string) $hid;
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'capacity' => !$hitCap,
                'no_checklist' => $noChecklist, 'ids' => $ids, 'rejected' => $rejected];
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

    /**
     * Read a spreadsheet export. Handles what Excel really produces: a byte-order mark, Windows-1252 text (accents),
     * semicolon- or tab-separated files (Spanish-language setups), and quoted cells that contain line breaks.
     * @return array{0:array<string,int>,1:array<int,array<int,string>>}|null
     */
    private function parse(string $csv): ?array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        $first = strtok($csv, "\r\n");
        if ($first === false || trim($first) === '') return null;
        $delim = ',';
        foreach ([';', "\t"] as $d) { if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d; }

        $h = fopen('php://temp', 'r+'); fwrite($h, $csv); rewind($h);
        $header = flx_fgetcsv($h, $delim);
        if (!$header) { fclose($h); return null; }
        $idx = [];
        foreach ($header as $i => $name) { $idx[strtolower(trim((string) $name))] = $i; }
        // must recognize at least one identity column
        if (!isset($idx['first_name']) && !isset($idx['last_name']) && !isset($idx['org_name'])) { fclose($h); return null; }
        $records = [];
        while (($row = flx_fgetcsv($h, $delim)) !== false) {
            if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') continue;   // blank line
            $records[] = array_map('strval', $row);
            if (count($records) > self::MAX_ROWS) break;   // enough to know the file is too big
        }
        fclose($h);
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
