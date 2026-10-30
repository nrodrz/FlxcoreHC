<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT126 — read-only user lists of HubSpot, Jotform and eFax (Documo), in the shape SystemConnect uses (the same
 * as JustCall): id, name, email, role, extension, owned_numbers, shared_numbers, groups, created_at, last_login.
 * BT133: HubSpot users can also be changed and removed from a person's page (HubspotPeople.php), through call(); the
 * lists here stay read-only. Jotform and Documo are never written to. Keys go in a request header, never in an address.
 *
 *   HubSpot  GET https://api.hubapi.com/settings/v3/users?limit=100&after=…  (scope settings.users.read or
 *            crm.objects.users.read). "Authorization: Bearer <service key or private app token>". {results:[{id, email,
 *            firstName, lastName, roleId, roleIds, superAdmin, primaryTeamId, secondaryTeamIds, seatNames}], paging:{next:
 *            {after}}}. HubSpot's newer path /settings/users/v3 is tried when the first one answers 404. Confirmed in
 *            HubSpot's developer reference and its PHP SDK (PublicUser).
 *   Jotform  GET https://api.jotform.com/v1/user/subusers (eu-api / hipaa-api for those servers), header "APIKEY". The
 *            answer is {responseCode, content:[…]}. The fields of a sub-user are not published; they are read
 *            tolerantly and "Test" shows which fields came back. The account owner (GET /v1/user) is added too.
 *   Documo   "Authorization: Basic <the key as it is>". GET /v1/me is documented (the test). A user list is not
 *            documented: GET /v1/users is tried; when Documo answers 404 the connection says so and eFax stays by hand.
 *
 * A test installation can point a vendor elsewhere with ITOPS_SAAS_BASE_HUBSPOT / _JOTFORM / _EFAX in config.php.
 */
if (!class_exists('ItopsSaasUsers')) {
    class ItopsSaasException extends RuntimeException {
        public int $status = 0;
        public function __construct(string $m, int $status = 0) { parent::__construct($m); $this->status = $status; }
    }

    final class ItopsSaasUsers
    {
        const MAX_PAGES = 40;
        private string $vendor; private array $creds; public ?int $limitLeft = null;

        public function __construct(string $vendor, array $creds)
        {
            if (!in_array($vendor, ['hubspot', 'jotform', 'efax'], true)) throw new ItopsSaasException('Unknown system.');
            $key = trim((string)($creds['api_key'] ?? ''));
            if ($key === '') throw new ItopsSaasException('The key is missing.');
            if (preg_match('/[\r\n\s]/', $key)) throw new ItopsSaasException('The key holds a space or a line break. Copy it again.');
            $this->vendor = $vendor; $this->creds = ['api_key' => $key, 'region' => (string)($creds['region'] ?? '')];
        }

        public static function name(string $v): string { return ['hubspot' => 'HubSpot', 'jotform' => 'Jotform', 'efax' => 'Documo'][$v] ?? $v; }

        private function base(): string
        {
            $c = 'ITOPS_SAAS_BASE_' . strtoupper($this->vendor);
            if (defined($c) && is_string(constant($c)) && constant($c) !== '') return rtrim(constant($c), '/');
            if ($this->vendor === 'hubspot') return 'https://api.hubapi.com';
            if ($this->vendor === 'efax') return 'https://api.documo.com';
            $r = strtolower(trim($this->creds['region']));
            return $r === 'eu' ? 'https://eu-api.jotform.com' : ($r === 'hipaa' ? 'https://hipaa-api.jotform.com' : 'https://api.jotform.com');
        }

        private function req(string $path, array $q = [], string $method = 'GET', ?array $body = null): array
        {
            if (!function_exists('curl_init')) throw new ItopsSaasException('This server cannot make web requests (the PHP curl extension is missing).');
            $k = $this->creds['api_key'];
            $auth = $this->vendor === 'hubspot' ? 'Authorization: Bearer ' . $k : ($this->vendor === 'jotform' ? 'APIKEY: ' . $k : 'Authorization: Basic ' . $k);
            for ($try = 0; $try < 2; $try++) {
                $ch = curl_init($this->base() . $path . ($q ? '?' . http_build_query($q) : '')); $hdr = [];
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => array_merge($body !== null ? ['Content-Type: application/json'] : [], [$auth, 'Accept: application/json', 'User-Agent: ' . (function_exists('itops_app_name') ? preg_replace('/[^A-Za-z0-9 ._-]/', '', itops_app_name()) : 'app') . ' user reader']),
                    CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) { $p = explode(':', $line, 2); if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); }]);
                if ($method === 'GET') curl_setopt($ch, CURLOPT_HTTPGET, true); else curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $raw = curl_exec($ch);
                if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new ItopsSaasException('No answer from ' . self::name($this->vendor) . ' (' . $e . ').'); }
                $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                foreach (['x-hubspot-ratelimit-daily-remaining', 'x-ratelimit-remaining', 'ratelimit-remaining'] as $h) if (isset($hdr[$h]) && is_numeric($hdr[$h])) $this->limitLeft = (int)$hdr[$h];
                if (($st === 429 || ($this->vendor === 'jotform' && $st === 503)) && $try === 0 && $method === 'GET') { sleep(max(1, min(5, (int)($hdr['retry-after'] ?? 2)))); continue; }
                $d = json_decode((string)$raw, true);
                return [$st, is_array($d) ? $d : ($st === 204 ? [] : ['_not_json' => true])];
            }
            return [429, []];
        }

        /** BT133: one HubSpot call for HubspotPeople.php (GET, PUT or DELETE). Returns [status, answer]. */
        public function call(string $method, string $path, array $q = [], ?array $body = null): array
        {
            if ($this->vendor !== 'hubspot' || !in_array($method, ['GET', 'PUT', 'DELETE'], true)) throw new ItopsSaasException('That call is not allowed.');
            return $this->req($path, $q, $method, $body);
        }
        public function errText(int $st, array $d): string { return $this->err($st, $d); }

        private function err(int $st, array $d): string
        {
            $n = self::name($this->vendor);
            $m = $d['message'] ?? ($d['error'] ?? ($d['errors'][0]['message'] ?? ''));
            $m = is_string($m) ? trim(mb_substr($m, 0, 200)) : '';
            $lead = in_array($st, [401, 403], true) ? $n . ' refused the key (HTTP ' . $st . ').' . ($this->vendor === 'hubspot' ? ' Check the key and that it has the scope settings.users.read.' : ($this->vendor === 'efax' ? ' Check the key, and that its access level is admin.' : ' Check the key.'))
                  : ($st === 429 ? $n . ' says too many requests were made with this key. Try again in a few minutes.'
                  : ($st >= 500 ? $n . ' has a problem on its side (HTTP ' . $st . '). Try again later.' : $n . ' answered HTTP ' . $st . '.'));
            return $lead . ($m !== '' ? ' ' . $n . ' said: ' . $m : '');
        }

        /** One cheap call proving the key works. Returns ['users_seen', 'fields', 'total', 'note']. */
        public function test(): array
        {
            if ($this->vendor === 'efax') {
                [$s, $d] = $this->req('/v1/me'); if ($s < 200 || $s >= 300) throw new ItopsSaasException($this->err($s, $d), $s);
                [$s2, $d2] = $this->req('/v1/users', ['limit' => 1]);
                if ($s2 === 404) return ['users_seen' => 0, 'fields' => [], 'total' => null, 'lists' => false,
                    'note' => 'Documo accepted the key (it belongs to ' . (string)($d['email'] ?? 'a user') . '), but its API does not list the users for this key. eFax stays tracked by hand; nothing else changes.'];
                if ($s2 < 200 || $s2 >= 300) throw new ItopsSaasException($this->err($s2, $d2), $s2);
                $rows = $this->rows($d2);
                return ['users_seen' => count($rows ?? []), 'fields' => $rows ? array_slice(array_keys((array)$rows[0]), 0, 30) : [], 'total' => isset($d2['count']) && is_numeric($d2['count']) ? (int)$d2['count'] : null, 'lists' => $rows !== null, 'note' => ''];
            }
            [$s, $d] = $this->vendor === 'hubspot' ? $this->hubPage(null, 1) : $this->req('/v1/user/subusers');
            if ($s < 200 || $s >= 300) throw new ItopsSaasException($this->err($s, $d), $s);
            $rows = $this->rows($d);
            if ($rows === null) throw new ItopsSaasException(self::name($this->vendor) . ' answered, but not with a list of users.', $s);
            return ['users_seen' => count($rows), 'fields' => $rows ? array_slice(array_keys((array)$rows[0]), 0, 30) : [], 'total' => null, 'lists' => true, 'note' => ''];
        }

        private function hubPage(?string $after, int $limit = 100): array
        {
            $q = ['limit' => $limit] + ($after !== null ? ['after' => $after] : []);
            [$s, $d] = $this->req('/settings/v3/users', $q);
            if ($s === 404) [$s, $d] = $this->req('/settings/users/v3', $q);
            return [$s, $d];
        }

        private function rows(array $d): ?array
        {
            foreach (['results', 'content', 'rows', 'data', 'users'] as $k) if (isset($d[$k]) && is_array($d[$k]) && ($d[$k] === [] || array_is_list($d[$k]))) return $d[$k];
            return null;
        }

        /** Every user. Throws when any page cannot be read (an incomplete list is not a list). */
        public function users(): array
        {
            $out = []; $seen = [];
            $add = function (array $r) use (&$out, &$seen) { $u = $this->shape($r); if ($u['id'] === '' || isset($seen[$u['id']])) return; $seen[$u['id']] = 1; $out[] = $u; };
            if ($this->vendor === 'hubspot') {
                $after = null;
                for ($n = 0; $n < self::MAX_PAGES; $n++) {
                    [$s, $d] = $this->hubPage($after);
                    if ($s < 200 || $s >= 300) throw new ItopsSaasException($this->err($s, $d), $s);
                    $rows = $this->rows($d); if ($rows === null) throw new ItopsSaasException('HubSpot answered, but not with a list of users.', $s);
                    foreach ($rows as $r) if (is_array($r)) $add($r);
                    $nx = $d['paging']['next']['after'] ?? null;
                    if (!is_string($nx) && !is_int($nx)) return $out;
                    if ((string)$nx === (string)$after) return $out;
                    $after = (string)$nx;
                }
                throw new ItopsSaasException('HubSpot kept giving more pages; the list was not used.');
            }
            if ($this->vendor === 'jotform') {
                [$s, $d] = $this->req('/v1/user');   // the account owner
                if ($s >= 200 && $s < 300 && is_array($d['content'] ?? null)) $add($d['content'] + ['_owner' => true]);
                [$s, $d] = $this->req('/v1/user/subusers');
                if ($s < 200 || $s >= 300) throw new ItopsSaasException($this->err($s, $d), $s);
                $rows = $this->rows($d); if ($rows === null) throw new ItopsSaasException('Jotform answered, but not with a list of team members.', $s);
                foreach ($rows as $r) if (is_array($r)) $add($r);
                return $out;
            }
            for ($p = 1; $p <= self::MAX_PAGES; $p++) {   // Documo
                [$s, $d] = $this->req('/v1/users', ['limit' => 100, 'page' => $p]);
                if ($s === 404) throw new ItopsSaasException('Documo\'s API does not list the users for this key, so eFax stays tracked by hand.', 404);
                if ($s < 200 || $s >= 300) throw new ItopsSaasException($this->err($s, $d), $s);
                $rows = $this->rows($d); if ($rows === null) throw new ItopsSaasException('Documo answered, but not with a list of users.', $s);
                foreach ($rows as $r) if (is_array($r)) $add($r);
                $count = isset($d['count']) && is_numeric($d['count']) ? (int)$d['count'] : null;
                if (count($rows) < 100 || ($count !== null && count($out) >= $count)) {
                    if ($count !== null && count($out) < $count) throw new ItopsSaasException('Documo has ' . $count . ' users but only ' . count($out) . ' could be read. Nothing was changed.');
                    return $out;
                }
            }
            throw new ItopsSaasException('Documo kept giving more pages; the list was not used.');
        }

        /** A user of any of the three in ITOps's shape (fields the answer does not carry stay empty). */
        public function shape(array $r): array
        {
            $t = fn($v) => is_string($v) || is_int($v) || is_float($v) ? trim((string)$v) : '';
            $name = $t($r['name'] ?? '');
            if ($name === '') $name = trim($t($r['firstName'] ?? ($r['first_name'] ?? ($r['firstname'] ?? ''))) . ' ' . $t($r['lastName'] ?? ($r['last_name'] ?? ($r['lastname'] ?? ''))));
            if ($name === '') $name = $t($r['username'] ?? '');
            $role = '';
            if ($this->vendor === 'hubspot') { $role = !empty($r['superAdmin']) ? 'Super Admin' : ($t($r['roleId'] ?? '') !== '' ? 'role ' . $t($r['roleId']) : ''); }
            elseif ($this->vendor === 'jotform') { $role = !empty($r['_owner']) ? 'Account owner' : $t($r['role'] ?? ($r['account_type'] ?? ($r['status'] ?? ''))); }
            else { $role = $t($r['userRole'] ?? ($r['role'] ?? '')); }
            $groups = [];
            if ($this->vendor === 'hubspot') { foreach ((array)($r['seatNames'] ?? []) as $x) if (is_string($x) && $x !== '') $groups[] = 'seat: ' . mb_substr($x, 0, 60);
                if ($t($r['primaryTeamId'] ?? '') !== '') $groups[] = 'team ' . $t($r['primaryTeamId']); }
            $nums = [];
            if ($this->vendor === 'efax') foreach (['faxCallerId', 'faxNumber', 'phone'] as $k) { $v = $t($r[$k] ?? ''); if ($v !== '' && !in_array($v, $nums, true)) $nums[] = $v; }
            $id = $t($r['id'] ?? ($r['uuid'] ?? ($r['userId'] ?? ($r['username'] ?? ''))));
            return ['id' => mb_substr($id, 0, 64), 'name' => mb_substr($name, 0, 190), 'email' => mb_strtolower(mb_substr($t($r['email'] ?? ''), 0, 190)),
                    'role' => mb_substr($role, 0, 60), 'extension' => '', 'owned_numbers' => $nums, 'shared_numbers' => [], 'groups' => array_slice($groups, 0, 30),
                    'created_at' => mb_substr($t($r['createdAt'] ?? ($r['created_at'] ?? '')), 0, 25), 'last_login' => mb_substr($t($r['lastLogin'] ?? ($r['last_login'] ?? '')), 0, 25)];
        }
    }
}
