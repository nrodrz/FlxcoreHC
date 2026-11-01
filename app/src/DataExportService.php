<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\Repository\CredentialRepository;
use FlxCoreHC\Repository\DocumentRepository;
use FlxCoreHC\Repository\GroupRepository;
use FlxCoreHC\Repository\LocationRepository;

/**
 * "Download all my data": one ZIP of CSVs a tenant controls — providers, credentials, a document
 * index (metadata + checksums; the files themselves stay in the app), groups, locations, group and
 * location memberships, and the team. Encrypted fields are decrypted through the repositories, so
 * the export contains what the organization actually entered.
 *
 * The ZIP is written by a minimal, dependency-free "store" (no compression) writer because shared
 * cPanel hosts commonly lack the ZipArchive extension. Every file is still a standard ZIP entry.
 */
final class DataExportService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /** Build the ZIP in memory and return its bytes. */
    public function build(): string
    {
        SupportAccess::denyIfSupport('Exporting organization data');
        $files = [];
        $files['providers.csv']   = $this->csv($this->providers());
        $files['credentials.csv'] = $this->csv($this->credentials());
        $files['documents.csv']   = $this->csv($this->documents());
        $files['groups.csv']      = $this->csv($this->groups());
        $files['locations.csv']   = $this->csv($this->locations());
        $files['group_memberships.csv']    = $this->csv($this->memberships('holder_groups', 'group_id', 'provider_groups'));
        $files['location_affiliations.csv'] = $this->csv($this->memberships('holder_locations', 'location_id', 'locations'));
        $files['team.csv']        = $this->csv($this->team());
        $files['README.txt']      = "FlxCoreHC data export for {$this->ctx->tenantName}\nGenerated " . gmdate('Y-m-d H:i') . " UTC\n\n"
            . "providers.csv – every provider on file\ncredentials.csv – every credential with number, issuer, dates, status\n"
            . "documents.csv – index of uploaded documents (name, type, size, SHA-256, verification); files remain in the application\n"
            . "groups.csv / locations.csv – organizational structure\ngroup_memberships.csv / location_affiliations.csv – who belongs where\n"
            . "team.csv – users and roles (no passwords)\n\nThis export contains protected provider information. Store it securely.\n";
        return self::zip($files);
    }

    // ---- datasets ---------------------------------------------------------------

    private function providers(): array
    {
        // Custom fields become extra columns (header = the field label), so exports carry everything the tenant defined.
        $cf = null; $defs = [];
        try { $cf = new CustomFieldService($this->ctx); $defs = array_values(array_filter($cf->definitions('provider'), fn($d) => empty($d['is_sensitive']))); } catch (\Throwable $e) {}
        $head = ['id','holder_type','provider_type','first_name','last_name','org_name','npi','email','phone','status','role_title','created_at'];
        foreach ($defs as $d) $head[] = 'cf:' . $d['label'];
        $rows = [$head];
        foreach ((new HolderRepository($this->ctx))->all([], 'last_name ASC, first_name ASC', 100000) as $h) {
            $r = [$h['id'], $h['holder_type'], $h['provider_type'] ?? '', $h['first_name'] ?? '', $h['last_name'] ?? '', $h['org_name'] ?? '',
                  $h['npi'] ?? '', $h['email'] ?? '', $h['phone'] ?? '', $h['status'] ?? '', $h['role_title'] ?? '', $h['created_at'] ?? ''];
            if ($defs) { $vals = $cf->valuesFor((string)$h['id']); foreach ($defs as $d) $r[] = $vals[$d['id']] ?? ''; }
            $rows[] = $r;
        }
        return $rows;
    }

    private function credentials(): array
    {
        $names = [];
        foreach ((new HolderRepository($this->ctx))->all([], 'id ASC', 100000) as $h) { $names[$h['id']] = HolderRepository::displayName($h); }
        $rows = [['id','provider_id','provider','credential_type','name','credential_number','issuing_body','issued_date','expiry_date','status','last_verified_at','notes']];
        foreach ((new CredentialRepository($this->ctx))->all([], 'holder_id ASC, expiry_date ASC', 100000) as $c) {
            $rows[] = [$c['id'], $c['holder_id'], $names[$c['holder_id']] ?? '', $c['credential_type'], $c['name'], $c['credential_number'] ?? '',
                       $c['issuing_body'] ?? '', $c['issued_date'] ?? '', $c['expiry_date'] ?? '', $c['status'], $c['last_verified_at'] ?? '', $c['notes'] ?? ''];
        }
        return $rows;
    }

    private function documents(): array
    {
        $rows = [['id','credential_id','original_name','mime_type','size_bytes','sha256','uploaded_at','verified_at','verification_method','verification_expires']];
        foreach ((new DocumentRepository($this->ctx))->all([], 'created_at ASC', 100000) as $d) {
            $rows[] = [$d['id'], $d['credential_id'], $d['original_name'], $d['mime_type'] ?? '', $d['size_bytes'] ?? '', $d['sha256'] ?? '',
                       $d['created_at'] ?? '', $d['verified_at'] ?? '', $d['verification_method'] ?? '', $d['verification_expires'] ?? ''];
        }
        return $rows;
    }

    private function groups(): array
    {
        $rows = [['id','name','code','npi','status','note']];
        foreach ((new GroupRepository($this->ctx))->all([], 'name ASC', 10000) as $g) { $rows[] = [$g['id'], $g['name'], $g['code'] ?? '', $g['npi'] ?? '', $g['status'], $g['note'] ?? '']; }
        return $rows;
    }

    private function locations(): array
    {
        $rows = [['id','name','npi','address1','address2','city','state','zip','phone','status']];
        foreach ((new LocationRepository($this->ctx))->all([], 'name ASC', 10000) as $l) {
            $rows[] = [$l['id'], $l['name'], $l['npi'] ?? '', $l['address1'] ?? '', $l['address2'] ?? '', $l['city'] ?? '', $l['state'] ?? '', $l['zip'] ?? '', $l['phone'] ?? '', $l['status']];
        }
        return $rows;
    }

    private function memberships(string $table, string $fk, string $parent): array
    {
        $st = Database::pdo()->prepare(
            "SELECT m.holder_id, m.{$fk} AS parent_id, p.name AS parent_name, m.is_primary
             FROM {$table} m JOIN {$parent} p ON p.id = m.{$fk}
             WHERE m.tenant_id = ?" . ProviderScope::for($this->ctx)->sql('m.holder_id', false) . " ORDER BY p.name, m.holder_id");
        $st->execute(array_merge([$this->ctx->tenantId], ProviderScope::for($this->ctx)->params()));
        $rows = [['provider_id', 'parent_id', 'name', 'is_primary']];
        foreach ($st->fetchAll() as $r) { $rows[] = [$r['holder_id'], $r['parent_id'], $r['parent_name'], (int)$r['is_primary']]; }
        return $rows;
    }

    private function team(): array
    {
        $rows = [['id','full_name','email','role','status','provider_access','created_at']];
        foreach ((new TeamService($this->ctx))->users() as $u) {
            $rows[] = [$u['id'], $u['full_name'], $u['email'], TeamService::ROLES[$u['role']] ?? $u['role'], $u['status'], $u['provider_access'] ?? 'all', $u['created_at'] ?? ''];
        }
        return $rows;
    }

    // ---- encoding ---------------------------------------------------------------

    private function csv(array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 (accents) correctly
        foreach ($rows as $r) { flx_fputcsv($fh, csv_safe_row(array_map(fn($v) => (string)$v, $r))); }
        rewind($fh); $out = stream_get_contents($fh); fclose($fh);
        return (string)$out;
    }

    /** Minimal ZIP writer (method 0 = store). Standard local headers + central directory. */
    public static function zip(array $files): string
    {
        $data = ''; $central = ''; $offset = 0; $count = 0;
        $dosTime = self::dosTime(time());
        foreach ($files as $name => $content) {
            $crc = crc32($content); $len = strlen($content); $nlen = strlen($name);
            $local = "\x50\x4b\x03\x04" . pack('vvvvvVVVvv', 20, 0x0800, 0, $dosTime[1], $dosTime[0], $crc, $len, $len, $nlen, 0) . $name . $content;
            $central .= "\x50\x4b\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 0, $dosTime[1], $dosTime[0], $crc, $len, $len, $nlen, 0, 0, 0, 0, 0, $offset) . $name;
            $data .= $local; $offset += strlen($local); $count++;
        }
        $end = "\x50\x4b\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($data), 0);
        return $data . $central . $end;
    }

    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | (int)($d['seconds'] / 2);
        return [$date, $time];
    }
}
