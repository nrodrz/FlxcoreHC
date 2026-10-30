<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Microsoft Graph client with a stable GraphClient interface (BT21).
 *
 * The Microsoft 365 area uses a domain service (OpshM365, the lib/M365.php
 * design) unchanged. This adapter lets that code run on OpsHub's
 * own Graph connection: the Azure app saved under Settings → Sign-in & AI, the shared token cache
 * and the self-healing 401/403 retry in opsh_graph_http(). Errors are thrown as
 * OpshGraphException(code, message, status), the GraphException shape.
 */
require_once __DIR__ . '/Graph.php';

if (!class_exists('OpshGraphException')) {
    final class OpshGraphException extends RuntimeException
    {
        private string $graphCode;
        private int $status;
        public function __construct(string $code, string $message, int $status = 0)
        {
            parent::__construct($message, $status);
            $this->graphCode = $code;
            $this->status = $status;
        }
        public function getGraphCode(): string { return $this->graphCode; }
        public function getStatus(): int { return $this->status; }
    }

    final class OpshGraphClient
    {
        private const GRAPH = 'https://graph.microsoft.com/v1.0';
        private const BETA  = 'https://graph.microsoft.com/beta';

        /** GET a single resource. $query is an associative array of OData params. */
        public function get(string $path, array $query = []): array { return $this->request('GET', $path, null, $query); }

        /** GET every page of a collection, returning the merged `value` array. */
        public function getAll(string $path, array $query = [], int $maxPages = 50, ?callable $onPage = null): array
        {
            $out = [];
            $url = self::GRAPH . '/' . ltrim($path, '/');
            if ($query) $url .= '?' . http_build_query($query);
            $pages = 0;
            while ($url !== null && $pages < $maxPages) {
                $resp = $this->raw('GET', $url);
                if (isset($resp['value']) && is_array($resp['value'])) foreach ($resp['value'] as $row) $out[] = $row;
                $url = $resp['@odata.nextLink'] ?? null;
                $pages++;
                if ($onPage) $onPage($pages);   // BT29.1: lets a long read keep the database connection awake between pages
            }
            if ($url !== null) {   // BT88: a list cut by the page limit is said, not hidden
                $GLOBALS['OPSH_GRAPH_TRUNCATED'] = true;
                if (function_exists('opsh_log')) { try { opsh_log('warning', 'm365', 'A Microsoft 365 list was longer than ' . count($out) . ' rows; only those were read.', ['path' => strtok($path, '?')]); } catch (\Throwable $e) {} }
            }
            return $out;
        }

        public function post(string $path, array $body): array  { return $this->request('POST', $path, $body); }
        public function patch(string $path, array $body): array { return $this->request('PATCH', $path, $body); }
        public function put(string $path, array $body): array   { return $this->request('PUT', $path, $body); }
        public function delete(string $path): array             { return $this->request('DELETE', $path, null); }

        /** GET a single page from the beta endpoint. */
        public function getBeta(string $path, array $query = []): array
        {
            $url = self::BETA . '/' . ltrim($path, '/');
            if ($query) $url .= '?' . http_build_query($query);
            return $this->raw('GET', $url);
        }

        /** Usage report as JSON (keeps $format literal — report endpoints reject the encoded slash). */
        public function getReport(string $path): array
        {
            $sep = strpos($path, '?') !== false ? '&' : '?';
            return $this->raw('GET', self::GRAPH . '/' . ltrim($path, '/') . $sep . '$format=application/json');
        }

        public function getReportBeta(string $path): array
        {
            $sep = strpos($path, '?') !== false ? '&' : '?';
            return $this->raw('GET', self::BETA . '/' . ltrim($path, '/') . $sep . '$format=application/json');
        }

        /** GET with extra request headers (e.g. ConsistencyLevel: eventual). A text/plain answer ($count) comes back as ['_raw' => '893']. */
        public function getWith(string $path, array $query = [], array $headers = []): array
        {
            $url = self::GRAPH . '/' . ltrim($path, '/');
            if ($query) $url .= '?' . http_build_query($query);
            try { [$status, $data, $raw] = opsh_graph_http('GET', $url, null, $headers); }
            catch (Throwable $e) { throw new OpshGraphException('NETWORK_ERROR', $e->getMessage(), 0); }
            if ($status >= 200 && $status < 300) return is_array($data) ? $data : ['_raw' => trim((string)$raw)];
            $err = is_array($data) ? ($data['error'] ?? []) : [];
            throw new OpshGraphException((string)($err['code'] ?? ('HTTP_' . $status)), (string)($err['message'] ?? ('Graph returned ' . $status)), (int)$status);
        }

        /**
         * JSON batching (BT31): up to 20 GETs per round trip. $requests = [id => ['url' => 'groups/x/members/$count', 'headers' => [...]]].
         * Returns [id => ['status' => int, 'body' => mixed]]. A failed batch call throws; a failed inner request is reported in its status.
         */
        public function batch(array $requests): array
        {
            $out = [];
            foreach (array_chunk($requests, 20, true) as $chunk) {
                $reqs = [];
                foreach ($chunk as $id => $r) {
                    $one = ['id' => (string)$id, 'method' => 'GET', 'url' => '/' . ltrim((string)$r['url'], '/')];
                    if (!empty($r['headers'])) $one['headers'] = $r['headers'];
                    $reqs[] = $one;
                }
                $resp = $this->call('POST', self::GRAPH . '/$batch', ['requests' => $reqs]);
                foreach (($resp['responses'] ?? []) as $x) $out[(string)($x['id'] ?? '')] = ['status' => (int)($x['status'] ?? 0), 'body' => $x['body'] ?? null];
            }
            return $out;
        }

        public function token(): string { return opsh_graph_token(); }

        /**
         * BT41 — for the rest of this request: at most $seconds per call to Microsoft and, with $authRetry = false, no
         * second attempt after a 401/403. Used by the endpoints that send several writes in one request, so the whole
         * request ends before the web host cuts it (about 60 seconds on shared hosting) and every part gets its answer.
         */
        public static function setRequestTimeout(int $seconds, bool $authRetry = true): void
        {
            $GLOBALS['OPSH_GRAPH_REQ'] = ['timeout' => max(3, min(30, $seconds)), 'auth_retry' => $authRetry];
        }

        private function request(string $method, string $path, ?array $body, array $query = []): array
        {
            // BT88: an id never brings its own query or fragment into the address (the query goes in $query)
            if (strpbrk($path, "?#\\") !== false) throw new OpshGraphException('BAD_ID', 'That id is not valid: it cannot be part of a Microsoft 365 address.', 400);
            // Review M365A-12: no step out of the address (../), no encoded slash or dot, no spaces or control characters
            if (preg_match('#(^|/)\.\.?(/|$)|%2e|%2f|%5c|[\s\x00-\x1f]#i', $path)) throw new OpshGraphException('BAD_ID', 'That id is not valid: it cannot be part of a Microsoft 365 address.', 400);
            $url = self::GRAPH . '/' . ltrim($path, '/');
            if ($query) $url .= '?' . http_build_query($query);
            return $this->call($method, $url, $body);
        }

        private function raw(string $method, string $url): array { return $this->call($method, $url, null); }

        private function call(string $method, string $url, ?array $body): array
        {
            try {
                [$status, $data, $raw] = opsh_graph_http($method, $url, $body);
            } catch (Throwable $e) {
                throw new OpshGraphException('NETWORK_ERROR', $e->getMessage(), 0);
            }
            if ($status >= 200 && $status < 300) return is_array($data) ? $data : [];
            $err = is_array($data) ? ($data['error'] ?? []) : [];
            throw new OpshGraphException(
                (string)($err['code'] ?? ('HTTP_' . $status)),
                (string)($err['message'] ?? ('Graph returned ' . $status)),
                (int)$status
            );
        }
    }
}
