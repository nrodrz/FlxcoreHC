<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Looks up a provider in the CMS NPPES NPI Registry (v2.1, free, no key). The public API is
 * slow (2–5s) and rate-limited, so every result is cached in npi_cache and served from there
 * on repeat lookups. Note: insurance producers are identified by an NPN (from NIPR, not CMS) —
 * this validates NPIs where a producer or the agency entity actually holds one.
 */
final class NpiService
{
    private const ENDPOINT = 'https://npiregistry.cms.hhs.gov/api/';
    private const CACHE_DAYS = 30;

    /**
     * Look up a 10-digit NPI. Returns a normalized array or ['found'=>false].
     * @return array{found:bool,npi?:string,entity_type?:string,name?:string,credential?:string,taxonomy?:string,city?:string,state?:string,cached?:bool}
     */
    public static function lookup(string $npi, bool $forceRefresh = false): array
    {
        $npi = preg_replace('/\D/', '', $npi);
        if (strlen($npi) !== 10) return ['found' => false, 'error' => 'NPI must be exactly 10 digits.'];

        // Only positive results are cached (see cache()), so a transient failure can never
        // poison future lookups. A force refresh skips the cache entirely.
        if (!$forceRefresh) {
            $cached = self::fromCache($npi);
            if ($cached !== null) { $cached['cached'] = true; return $cached; }
        }

        [$raw, $code] = self::httpGet(self::ENDPOINT . '?' . http_build_query(['version' => '2.1', 'number' => $npi]));
        if ($raw === null) {
            return ['found' => false, 'error' => 'Could not reach the NPI registry (HTTP ' . ($code ?: 'timeout')
                . '). This usually means the server needs outbound HTTPS enabled. Try again shortly.'];
        }

        $data = json_decode($raw, true);
        // A well-formed NPPES reply always carries a result_count. If it's absent the response is
        // an error/HTML page, NOT a genuine "not found" — surface it as retryable, don't cache it.
        if (!is_array($data) || !array_key_exists('result_count', $data)) {
            $err = (is_array($data) && !empty($data['Errors'][0]['description'])) ? $data['Errors'][0]['description'] : null;
            return ['found' => false, 'error' => $err ?: 'The NPI registry returned an unexpected response. Please try again.'];
        }

        if ((int) $data['result_count'] < 1 || empty($data['results'][0])) {
            // Genuinely no match in the registry. Not cached, so a corrected number re-queries cleanly.
            return ['found' => false, 'error' => 'No provider is registered under NPI ' . $npi . ' in the CMS registry.'];
        }

        $result = self::normalize($npi, $data['results'][0]);
        self::cache($npi, $result, $raw);
        return $result;
    }

    private static function normalize(string $npi, array $r): array
    {
        $type = ($r['enumeration_type'] ?? '') === 'NPI-2' ? 'organization' : 'individual';
        $b = $r['basic'] ?? [];
        if ($type === 'organization') {
            $name = $b['organization_name'] ?? '';
        } else {
            $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
        }
        $taxonomy = '';
        foreach (($r['taxonomies'] ?? []) as $t) {
            if (!empty($t['primary'])) { $taxonomy = $t['desc'] ?? ''; break; }
        }
        if ($taxonomy === '' && !empty($r['taxonomies'][0]['desc'])) $taxonomy = $r['taxonomies'][0]['desc'];
        $city = ''; $state = '';
        foreach (($r['addresses'] ?? []) as $a) {
            if (($a['address_purpose'] ?? '') === 'LOCATION') { $city = $a['city'] ?? ''; $state = $a['state'] ?? ''; break; }
        }
        return [
            'found' => true, 'npi' => $npi, 'entity_type' => $type,
            'name' => $name, 'credential' => $b['credential'] ?? '', 'taxonomy' => $taxonomy,
            'city' => $city, 'state' => $state,
        ];
    }

    // ---- cache --------------------------------------------------------------

    private static function fromCache(string $npi): ?array
    {
        // Only positive results are stored, so anything in cache is a real hit.
        $st = Database::pdo()->prepare('SELECT * FROM npi_cache WHERE npi = ? AND found = 1 AND fetched_at > (NOW() - INTERVAL ? DAY) LIMIT 1');
        $st->execute([$npi, self::CACHE_DAYS]);
        $row = $st->fetch();
        if (!$row) return null;
        return [
            'found' => true, 'npi' => $npi, 'entity_type' => $row['entity_type'],
            'name' => $row['name'], 'credential' => $row['credential'], 'taxonomy' => $row['taxonomy'],
            'city' => $row['city'], 'state' => $row['state'],
        ];
    }

    private static function cache(string $npi, array $r, string $raw): void
    {
        // Never cache negatives — a transient "not found" must not stick for 30 days.
        if (empty($r['found'])) return;
        Database::pdo()->prepare(
            'INSERT INTO npi_cache (npi, entity_type, name, credential, taxonomy, city, state, payload, found, fetched_at)
             VALUES (?,?,?,?,?,?,?,?,1,NOW())
             ON DUPLICATE KEY UPDATE entity_type=VALUES(entity_type), name=VALUES(name), credential=VALUES(credential),
             taxonomy=VALUES(taxonomy), city=VALUES(city), state=VALUES(state), payload=VALUES(payload),
             found=1, fetched_at=NOW()'
        )->execute([
            $npi, $r['entity_type'] ?? null, $r['name'] ?? null, $r['credential'] ?? null,
            $r['taxonomy'] ?? null, $r['city'] ?? null, $r['state'] ?? null,
            substr($raw, 0, 60000),
        ]);
    }

    /** @return array{0:?string,1:int} [body|null, httpCode] */
    private static function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = \curl_init($url);
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'FlxCoreHC/1.0 (+credential verification)',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = \curl_exec($ch);
            $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);
            return ($body === false || $code >= 400) ? [null, $code] : [(string) $body, $code];
        }
        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['timeout' => 12, 'user_agent' => 'FlxCoreHC/1.0',
                'header' => "Accept: application/json\r\n", 'follow_location' => 1]]);
            $body = @file_get_contents($url, false, $ctx);
            $code = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
            return $body === false ? [null, $code] : [$body, $code];
        }
        return [null, 0];
    }
}
