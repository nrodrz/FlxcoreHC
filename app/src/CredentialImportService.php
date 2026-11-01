<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\Repository\CredentialRepository;

/**
 * Bulk credential import from a spreadsheet — the second half of onboarding. Providers were imported
 * (or added) first; this attaches their licenses, certifications, trainings and other items in one
 * pass, so a client migrating from a spreadsheet does not re-key every credential.
 *
 * Provider matching: by NPI when given, else by first + last name (case-insensitive) within this
 * tenant. Credential types are validated against the catalog (built-in + this tenant's custom types).
 * If expiry is blank but the type renews on a cycle and an issue date is given, the expiry is derived
 * (issued + cycle) — the same rule the credential form uses — so the calendar and reminders have a
 * date from day one.
 */
final class CredentialImportService
{
    public const TEMPLATE_HEADER = 'npi,first_name,last_name,credential_type,name,credential_number,issuing_body,issued_date,expiry_date,jurisdiction,notes';

    private const MAX_ROWS = 2000;

    public function __construct(private readonly TenantContext $ctx) {}

    /** @return array{ok:bool,error?:string,rows:array<int,array>,summary:array<string,int>} */
    public function preview(string $csv): array
    {
        $parsed = $this->parse($csv);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'Could not read a header row. The first line must name the columns (e.g. ' . self::TEMPLATE_HEADER . ').', 'rows' => [], 'summary' => []];
        }
        [$header, $records] = $parsed;
        if ($records === []) return ['ok' => false, 'error' => 'No data rows found under the header.', 'rows' => [], 'summary' => []];
        if (count($records) > self::MAX_ROWS) return ['ok' => false, 'error' => 'That file has more than ' . self::MAX_ROWS . ' rows — split it into smaller batches.', 'rows' => [], 'summary' => []];

        $holders = new HolderRepository($this->ctx);
        $catalog = [];
        foreach (CatalogService::all(null, null, null, $this->ctx->tenantId) as $c) { $catalog[$c['code']] = $c; }
        $catalog['other'] = ['code' => 'other', 'name' => 'Other', 'renewal_months' => null];
        $nameIndex = null; // lazy: last|first => holder id

        $rows = []; $counts = ['create' => 0, 'skip' => 0, 'error' => 0];
        foreach ($records as $i => $rec) {
            $r = $this->normalizeRow($rec, $header);
            $r['line'] = $i + 2;
            $errors = [];

            // --- provider match ---
            $holder = null;
            if ($r['npi'] !== null) {
                if (($e = Npi::error($r['npi'])) !== null) $errors[] = 'npi: ' . $e;
                else $holder = $holders->findByNpi($r['npi']);
                if ($holder === null && $errors === []) $errors[] = "No provider with NPI {$r['npi']} in this organization — import the provider first.";
            } elseif ($r['first_name'] !== null && $r['last_name'] !== null) {
                if ($nameIndex === null) {
                    $nameIndex = [];
                    foreach ($holders->all() as $h) {
                        if (($h['holder_type'] ?? '') !== 'individual') continue;
                        $nameIndex[mb_strtolower(trim((string)$h['last_name'])) . '|' . mb_strtolower(trim((string)$h['first_name']))] = $h;
                    }
                }
                $holder = $nameIndex[mb_strtolower($r['last_name']) . '|' . mb_strtolower($r['first_name'])] ?? null;
                if ($holder === null) $errors[] = "No provider named {$r['first_name']} {$r['last_name']} — check spelling or give the NPI.";
            } else {
                $errors[] = 'Give an npi, or both first_name and last_name, to identify the provider.';
            }

            // --- credential type ---
            $type = $r['credential_type'];
            if ($type === null) $errors[] = 'credential_type is required.';
            elseif (!isset($catalog[$type])) $errors[] = "Unknown credential_type '{$type}'. Use a code from Organization → Document types.";

            // --- dates ---
            foreach (['issued_date', 'expiry_date'] as $dk) {
                if ($r[$dk] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[$dk])) $errors[] = "{$dk} must be YYYY-MM-DD.";
            }
            if ($r['expiry_date'] === null && $r['issued_date'] !== null && $type !== null && isset($catalog[$type])) {
                $rm = $catalog[$type]['renewal_months'] ?? null;
                if ($rm && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['issued_date'])) {
                    $r['expiry_date'] = (new \DateTimeImmutable($r['issued_date']))->modify('+' . (int)$rm . ' months')->format('Y-m-d');
                    $r['derived_expiry'] = true;
                }
            }
            if ($r['jurisdiction'] !== null && !isset(CatalogService::STATES[$r['jurisdiction']])) $errors[] = "jurisdiction '{$r['jurisdiction']}' is not a known state/territory code.";

            $r['holder_id'] = $holder['id'] ?? null;
            $r['provider_label'] = $holder ? HolderRepository::displayName($holder) : null;
            $r['type_label'] = $catalog[$type]['name'] ?? $type;
            if ($r['name'] === null) $r['name'] = $r['type_label'];

            if ($errors) { $r['status'] = 'error'; $r['reason'] = implode(' ', $errors); $counts['error']++; }
            else { $r['status'] = 'create'; $r['reason'] = null; $counts['create']++; }
            $rows[] = $r;
        }
        return ['ok' => true, 'rows' => $rows, 'summary' => $counts];
    }

    /** @return array{created:int,skipped:int,errors:int} */
    public function commit(string $csv): array
    {
        $pre = $this->preview($csv);
        if (!$pre['ok']) return ['created' => 0, 'skipped' => 0, 'errors' => 1];
        $creds = new CredentialRepository($this->ctx);
        $created = 0; $skipped = 0; $errors = 0;
        foreach ($pre['rows'] as $r) {
            if ($r['status'] !== 'create') { $errors++; continue; }
            $status = \function_exists('credential_status_for') ? \credential_status_for($r['expiry_date']) : self::statusFor($r['expiry_date']);
            $creds->insert([
                'holder_id'         => $r['holder_id'],
                'credential_type'   => $r['credential_type'],
                'name'              => $r['name'],
                'credential_number' => $r['credential_number'],
                // Credentials carry the issuer as text; a jurisdiction code is prefixed onto it so
                // "FL" + "Board of Medicine" reads "FL — Board of Medicine" and stays searchable.
                'issuing_body'      => $r['jurisdiction'] && $r['issuing_body'] ? $r['jurisdiction'] . ' — ' . $r['issuing_body'] : ($r['issuing_body'] ?? $r['jurisdiction']),
                'issued_date'       => $r['issued_date'],
                'expiry_date'       => $r['expiry_date'],
                'status'            => $status,
                'notes'             => $r['notes'],
            ]);
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** Same rule as the app's credential_status_for(): expired / expiring (within window) / active. */
    private static function statusFor(?string $expiry): string
    {
        if ($expiry === null) return 'active';
        $today = today_local_dt();
        $exp = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiry) ?: $today;
        if ($exp < $today) return 'expired';
        $days = (int) $today->diff($exp)->format('%a');
        return $days <= (int) config('expiring_window_days', 90) ? 'expiring' : 'active';
    }

    // ---- parsing ------------------------------------------------------------

    /** @return array{0:array<string,int>,1:array<int,array<int,string>>}|null */
    private function parse(string $csv): ?array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\r|\n/', (string)$csv);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        if ($lines === []) return null;
        $header = array_map(fn($h) => strtolower(trim($h)), flx_str_getcsv(array_shift($lines)));
        $idx = [];
        foreach ($header as $i => $h) { if ($h !== '') $idx[$h] = $i; }
        if (!isset($idx['credential_type'])) return null;
        $records = array_map('flx_str_getcsv', $lines);
        return [$idx, $records];
    }

    private function normalizeRow(array $cols, array $idx): array
    {
        $get = function (string $key) use ($cols, $idx): ?string {
            if (!isset($idx[$key])) return null;
            $v = trim((string)($cols[$idx[$key]] ?? ''));
            return $v === '' ? null : $v;
        };
        $npi = $get('npi'); if ($npi !== null) $npi = preg_replace('/\D/', '', $npi) ?: null;
        $type = $get('credential_type'); if ($type !== null) $type = CatalogService::codeFor(strtolower($type));
        $jur = $get('jurisdiction') ?? $get('state'); if ($jur !== null) $jur = strtoupper($jur);
        return [
            'npi' => $npi, 'first_name' => $get('first_name'), 'last_name' => $get('last_name'),
            'credential_type' => $type, 'name' => $get('name'), 'credential_number' => $get('credential_number') ?? $get('number'),
            'issuing_body' => $get('issuing_body'), 'issued_date' => $get('issued_date'), 'expiry_date' => $get('expiry_date'),
            'jurisdiction' => $jur, 'notes' => $get('notes'), 'derived_expiry' => false,
        ];
    }
}
