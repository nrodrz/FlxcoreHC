<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — vTiger provisioning client.
 *
 * Two channels:
 *   1. itops_vtiger_bridge_call() — the PREFERRED path for creating users, since
 *      vTiger's Web Services CANNOT create Users. Talks to ITOps's own bridge
 *      (itops-bridge.php) over an HMAC-signed request. ITOps's own secret.
 *   2. ItopsVTigerClient — the webservice (getchallenge → login), used as a
 *      fallback and to verify credentials. Rarely completes a Users create on
 *      its own, which is why the bridge exists.
 *
 * Config from itops_settings key 'vtiger_provision' (JSON):
 *   endpoint, username, access_key, default_roleid, bridge_url, bridge_secret
 */

if (!function_exists('itops_vtiger_bridge_call')) {

    /**
     * HMAC-signed POST to ITOps's vTiger bridge, with ITOps's own headers/secret. Returns the decoded JSON reply
     * (['ok'=>bool, ...]); never throws — transport errors come back as ok:false.
     */
    function itops_vtiger_bridge_call(array $prov, string $action, array $payload = []): array {
        $url    = (string)($prov['bridge_url'] ?? '');
        $secret = (string)($prov['bridge_secret'] ?? '');
        if ($url === '' || $secret === '') return ['ok' => false, 'error' => 'Bridge not configured.'];
        // BT89 (M-38): a one-time number inside the signed body. Bridge o8 keeps the numbers it has seen and refuses a request
        // that comes a second time (a copied request cannot be sent again). Older bridges ignore it.
        $body = json_encode(['action' => $action, 'nonce' => bin2hex(random_bytes(16))] + $payload);
        $ts   = (string)time();
        $sig  = hash_hmac('sha256', $ts . "\n" . $body, $secret);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Itops-Timestamp: ' . $ts, 'X-Itops-Signature: ' . $sig],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) return ['ok' => false, 'error' => 'Could not reach bridge: ' . $err];
        $data = json_decode((string)$resp, true);
        if (!is_array($data)) return ['ok' => false, 'error' => 'Bridge returned non-JSON (HTTP ' . $code . '): ' . substr(trim((string)$resp), 0, 200)];
        return $data;
    }

    /** BT89: passwords travel only over HTTPS, or to the same machine. */
    function itops_vtiger_url_secure(string $u): bool {
        $h = strtolower(trim((string)parse_url($u, PHP_URL_HOST), '[]'));
        return stripos(trim($u), 'https://') === 0 || in_array($h, ['127.0.0.1', 'localhost', '::1'], true);
    }

    /** Is the bridge configured (URL + secret present)? */
    function itops_vtiger_bridge_ready(array $prov): bool {
        return trim((string)($prov['bridge_url'] ?? '')) !== '' && trim((string)($prov['bridge_secret'] ?? '')) !== '';
    }
}

if (!class_exists('ItopsVTigerClient')) {
    /**
     * Minimal vTiger Web Services client: getchallenge → login. Used to verify
     * credentials (testLogin) and as a create fallback. No session-cache
     * stampede handling (onboarding is a single call, not the parallel dashboard).
     */
    final class ItopsVTigerClient {
        private string $endpoint;
        private string $username;
        private string $accessKey;
        private ?string $sessionName = null;
        private ?string $userId = null;

        public function __construct(string $endpoint, string $username, string $accessKey) {
            if (!preg_match('~/webservice\.php$~', $endpoint)) {
                $endpoint = rtrim($endpoint, '/') . '/webservice.php';
            }
            $this->endpoint  = $endpoint;
            $this->username  = $username;
            $this->accessKey = $accessKey;
        }

        private function http(string $method, array $query = [], array $post = []): array {
            $url = $this->endpoint . ($query ? ('?' . http_build_query($query)) : '');
            $ch = curl_init($url);
            $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 75, CURLOPT_CONNECTTIMEOUT => 5];
            if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($post); }
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = json_decode((string)$resp, true);
            if (!is_array($data)) throw new RuntimeException('vTiger returned non-JSON (HTTP ' . $code . ').');
            return $data;
        }

        /** Verify credentials. Returns the login result (sessionName, userId). */
        public function testLogin(): array {
            $challenge = $this->http('GET', ['operation' => 'getchallenge', 'username' => $this->username]);
            if (empty($challenge['success'])) {
                throw new RuntimeException('vTiger getchallenge failed: ' . ($challenge['error']['message'] ?? 'unknown'));
            }
            $token = (string)($challenge['result']['token'] ?? '');
            if ($token === '') throw new RuntimeException('Empty challenge token.');
            $login = $this->http('POST', [], [
                'operation' => 'login',
                'username'  => $this->username,
                'accessKey' => md5($token . $this->accessKey),
            ]);
            if (empty($login['success'])) {
                throw new RuntimeException('vTiger login failed: ' . ($login['error']['message'] ?? 'unknown'));
            }
            $this->sessionName = (string)($login['result']['sessionName'] ?? '');
            $this->userId      = (string)($login['result']['userId'] ?? '');
            return $login['result'] ?? [];
        }

        public function currentUserId(): ?string {
            if ($this->sessionName === null) { try { $this->testLogin(); } catch (Throwable $e) { return null; } }
            return $this->userId;
        }
    }
}
