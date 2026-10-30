<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT148 — CallTrackingMetrics (CTM), read only.
 *
 *   API      https://api.calltrackingmetrics.com/api/v1 (REST, JSON). Basic authentication with the account's access key
 *            and secret key (Account Settings → API Integration), sent in the Authorization header only, never in an
 *            address. CTM allows about 10 requests a second; the client waits once on HTTP 429.
 *   Account  GET /accounts.json lists the accounts the key reaches. A key made for one account reaches one; an agency
 *            key reaches several, and then the account ID must be given on the connection.
 *   Lists    GET /accounts/{id}/users.json, /numbers.json, /calls.json with page and per_page (CTM caps per_page near
 *            100). Calls take start_date and end_date (YYYY-MM-DD, in the account's time zone).
 *   Statuses CTM's activity statuses: answered, completed (the caller used a menu and nobody answered), in progress,
 *            hangup, busy, failed, no answer, canceled. A call answered by a voicemail box counts as answered.
 *
 * The fields of a user, a number and a call are read tolerantly (several names each): CTM's reference does not fix them
 * for every account type, and "Save the key and test" shows the fields a real user carries.
 * Nothing is ever written to CTM. A test installation can point the client elsewhere with ITOPS_CTM_BASE in config.php.
 */
if (!class_exists('ItopsCtm')) {
    class ItopsCtmException extends RuntimeException {
        public int $status = 0;
        public function __construct(string $m, int $status = 0) { parent::__construct($m); $this->status = $status; }
    }

    final class ItopsCtm
    {
        const BASE = 'https://api.calltrackingmetrics.com/api/v1';
        const PER_PAGE = 100;
        const MAX_PAGES = 40;          // 4,000 users or numbers: far more than one account has; a guard against an endless list
        const MISSED = ['no answer', 'busy', 'canceled', 'hangup', 'completed', 'failed'];   // what "missed" may mean (chosen per company)
        private string $auth; private string $account; private int $timeout;
        public ?array $acct = null;    // the account resolved: ['id', 'name']

        public function __construct(string $accessKey, string $secretKey, string $accountId = '', int $timeout = 15)
        {
            $accessKey = trim($accessKey); $secretKey = trim($secretKey); $accountId = trim($accountId);
            if ($accessKey === '' || $secretKey === '') throw new ItopsCtmException('The CallTrackingMetrics access key or secret key is missing.');
            if (preg_match('/[\r\n\s]/', $accessKey . $secretKey)) throw new ItopsCtmException('The CallTrackingMetrics key holds a space or a line break. Copy it again.');
            if ($accountId !== '' && !preg_match('/^[0-9]{1,20}$/', $accountId)) throw new ItopsCtmException('The CallTrackingMetrics account ID is a number (Account Settings shows it). Leave it empty when the key reaches one account.');
            $this->auth = 'Basic ' . base64_encode($accessKey . ':' . $secretKey); $this->account = $accountId; $this->timeout = max(3, min(40, $timeout));
        }

        public static function base(): string
        {
            return defined('ITOPS_CTM_BASE') && is_string(ITOPS_CTM_BASE) && ITOPS_CTM_BASE !== '' ? rtrim(ITOPS_CTM_BASE, '/') : self::BASE;
        }

        /** The account the key works on. Throws with a sentence when there is none, or several and none was chosen. */
        public function account(): array
        {
            if ($this->acct) return $this->acct;
            [$s, $d] = $this->req('/accounts.json', ['page' => 1, 'per_page' => self::PER_PAGE]);
            if ($s < 200 || $s >= 300) throw new ItopsCtmException($this->errMsg($s, $d), $s);
            $rows = self::rowsOf($d, 'accounts');
            if ($rows === null && isset($d['id'])) $rows = [$d];   // a key that answers with its own account
            if ($rows === null) throw new ItopsCtmException('CallTrackingMetrics answered, but not with a list of accounts.', $s);
            $all = [];
            foreach ($rows as $r) if (is_array($r) && self::txt($r['id'] ?? '') !== '') $all[] = ['id' => self::txt($r['id']), 'name' => mb_substr(self::txt($r['name'] ?? ''), 0, 120)];
            if ($this->account !== '') {
                foreach ($all as $a) if ($a['id'] === $this->account) return $this->acct = $a;
                if (!$all) return $this->acct = ['id' => $this->account, 'name' => ''];   // the list is empty for an account-level key on some plans: trust the ID given
                throw new ItopsCtmException('This key does not reach the account ' . $this->account . '. It reaches: ' . self::names($all) . '.');
            }
            if (count($all) === 1) return $this->acct = $all[0];
            if (!$all) throw new ItopsCtmException('This key reaches no CallTrackingMetrics account. Enter the account ID on the connection.');
            throw new ItopsCtmException('This key reaches ' . count($all) . ' accounts (' . self::names($all) . '). Enter the ID of the company\'s account on the connection.');
        }

        /** One cheap call that proves the key works and can read users. */
        public function test(): array
        {
            $a = $this->account();
            [$s, $d] = $this->req('/accounts/' . $a['id'] . '/users.json', ['page' => 1, 'per_page' => 1]);
            if ($s < 200 || $s >= 300) throw new ItopsCtmException($this->errMsg($s, $d), $s);
            $rows = self::rowsOf($d, 'users');
            if ($rows === null) throw new ItopsCtmException('CallTrackingMetrics answered, but not with a list of users. The key may not be allowed to read users.', $s);
            return ['account' => $a, 'users_seen' => count($rows), 'fields' => $rows && is_array($rows[0]) ? array_slice(array_keys($rows[0]), 0, 30) : [], 'total' => self::total($d)];
        }

        /** Every user of the account, in the shape SystemConnect uses. Throws when the list is not complete. */
        public function users(): array
        {
            $a = $this->account(); $out = [];
            foreach ($this->all('/accounts/' . $a['id'] . '/users.json', 'users', [], self::MAX_PAGES, true) as $r) { $u = self::user($r); if ($u['id'] !== '') $out[$u['id']] = $u; }
            return array_values($out);
        }

        /** The tracking numbers, each with its label, source and where it rings. */
        public function numbers(): array
        {
            $a = $this->account(); $out = [];
            foreach ($this->all('/accounts/' . $a['id'] . '/numbers.json', 'numbers', [], self::MAX_PAGES, true) as $r) { $n = self::number($r); if ($n['id'] !== '' || $n['number'] !== '') $out[] = $n; }
            return $out;
        }

        /**
         * The calls of a period (dates YYYY-MM-DD, both included). Returns [rows, truncated]: at most $maxPages pages are
         * read, and "truncated" says the period had more.
         */
        public function calls(string $from, string $to, int $maxPages = 30): array
        {
            $a = $this->account(); $rows = []; $trunc = false;
            $raw = $this->all('/accounts/' . $a['id'] . '/calls.json', 'calls', ['start_date' => $from, 'end_date' => $to], $maxPages, false, $trunc);
            $seen = [];
            foreach ($raw as $r) { $c = self::call($r); if ($c['id'] === '' || isset($seen[$c['id']])) continue; $seen[$c['id']] = 1; $rows[] = $c; }
            return [$rows, $trunc];
        }

        // ───────────── shapes ─────────────
        public static function txt($v): string { return is_string($v) || is_int($v) || is_float($v) ? trim((string)$v) : ''; }
        private static function pick(array $r, array $keys): string { foreach ($keys as $k) { $v = self::txt($r[$k] ?? ''); if ($v !== '') return $v; } return ''; }
        private static function names(array $all): string { return implode(', ', array_map(fn($a) => ($a['name'] !== '' ? $a['name'] . ' ' : '') . '#' . $a['id'], array_slice($all, 0, 8))) . (count($all) > 8 ? '…' : ''); }

        public static function user(array $r): array
        {
            $name = self::pick($r, ['name', 'full_name', 'display_name']);
            if ($name === '') $name = trim(self::pick($r, ['first_name', 'firstname']) . ' ' . self::pick($r, ['last_name', 'lastname']));
            $groups = [];
            foreach ((array)($r['user_groups'] ?? ($r['groups'] ?? ($r['teams'] ?? []))) as $g) { $t = is_array($g) ? self::pick($g, ['name', 'title']) : self::txt($g); if ($t !== '') $groups[] = mb_substr($t, 0, 80); }
            $nums = [];
            foreach ((array)($r['numbers'] ?? ($r['phone_numbers'] ?? [])) as $n) { $t = is_array($n) ? self::pick($n, ['number', 'phone', 'formatted']) : self::txt($n); if ($t !== '' && !in_array($t, $nums, true)) $nums[] = mb_substr($t, 0, 40); }
            $role = self::pick($r, ['role', 'role_name', 'user_role', 'access_level', 'type']);
            if ($role === '' && isset($r['admin'])) $role = !empty($r['admin']) ? 'Admin' : 'User';
            return [
                'id' => mb_substr(self::pick($r, ['id', 'user_id', 'sid']), 0, 64),
                'name' => mb_substr($name, 0, 190),
                'email' => mb_strtolower(mb_substr(self::pick($r, ['email', 'email_address', 'login']), 0, 190)),
                'role' => mb_substr($role, 0, 60),
                'extension' => mb_substr(self::pick($r, ['extension', 'ext']), 0, 20),
                'owned_numbers' => array_slice($nums, 0, 50), 'shared_numbers' => [],
                'groups' => array_slice($groups, 0, 30),
                'created_at' => mb_substr(self::pick($r, ['created_at', 'created']), 0, 25),
                'last_login' => mb_substr(self::pick($r, ['last_login_at', 'last_sign_in_at', 'current_sign_in_at', 'last_login', 'last_seen_at']), 0, 25),
            ];
        }

        public static function number(array $r): array
        {
            $src = $r['source'] ?? ($r['tracking_source'] ?? '');
            $source = is_array($src) ? self::pick($src, ['name', 'title']) : self::txt($src);
            if ($source === '') $source = self::pick($r, ['source_name', 'tracking_source_name']);
            $rcv = [];
            foreach ((array)($r['receiving_numbers'] ?? ($r['routes'] ?? [])) as $x) { $t = is_array($x) ? self::pick($x, ['number', 'formatted', 'name']) : self::txt($x); if ($t !== '' && !in_array($t, $rcv, true)) $rcv[] = mb_substr($t, 0, 60); }
            $rt = $r['routing'] ?? ($r['route_to'] ?? '');
            $routing = is_array($rt) ? self::pick($rt, ['name', 'type', 'route_type']) : self::txt($rt);
            return [
                'id' => mb_substr(self::pick($r, ['id', 'number_id']), 0, 64),
                'number' => mb_substr(self::pick($r, ['number', 'tracking_number', 'filter_number', 'phone']), 0, 40),
                'formatted' => mb_substr(self::pick($r, ['formatted', 'number_formatted', 'display_number']), 0, 40),
                'label' => mb_substr(self::pick($r, ['name', 'tracking_label', 'label']), 0, 160),
                'source' => mb_substr($source, 0, 120),
                'receiving' => array_slice($rcv, 0, 10),
                'routing' => mb_substr($routing, 0, 60),
                'status' => mb_substr(self::pick($r, ['status', 'state']), 0, 30),
                'kind' => mb_substr(self::pick($r, ['number_type', 'type', 'kind']), 0, 30),
            ];
        }

        public static function call(array $r): array
        {
            $ag = $r['agent'] ?? null;
            $agentName = is_array($ag) ? self::pick($ag, ['name', 'full_name', 'email']) : self::txt($ag);
            $agentEmail = is_array($ag) ? mb_strtolower(self::pick($ag, ['email'])) : '';
            if ($agentName === '') $agentName = self::pick($r, ['agent_name', 'answered_by']);
            $src = $r['source'] ?? ''; $source = is_array($src) ? self::pick($src, ['name']) : self::txt($src);
            $ts = $r['unix_time'] ?? ($r['unix_timestamp'] ?? null);
            $at = is_numeric($ts) && (int)$ts > 946684800 ? gmdate('Y-m-d\TH:i:s\Z', (int)$ts) : '';
            if ($at === '') { $c = self::pick($r, ['called_at', 'start_time', 'started_at', 'created_at']); $t = $c !== '' ? strtotime($c) : false; $at = $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : ''; }
            $dir = mb_strtolower(self::pick($r, ['direction', 'call_direction']));
            $status = mb_strtolower(self::pick($r, ['call_status', 'status', 'dial_status']));
            return [
                'id' => mb_substr(self::pick($r, ['id', 'call_id', 'sid']), 0, 64),
                'at' => $at,
                'direction' => $dir === 'msg_inbound' ? 'inbound' : ($dir === '' ? 'inbound' : $dir),
                'status' => mb_substr($status, 0, 30),
                'caller_number' => mb_substr(self::pick($r, ['caller_number', 'caller_number_format', 'caller', 'from_number', 'customer_number']), 0, 40),
                'caller_name' => mb_substr(self::pick($r, ['name', 'cnam', 'caller_name', 'customer_name']), 0, 160),
                'tracking_number' => mb_substr(self::pick($r, ['tracking_number', 'tracking_number_format', 'to_number', 'business_number']), 0, 40),
                'tracking_label' => mb_substr(self::pick($r, ['tracking_label', 'tracking_number_name', 'number_name']), 0, 160),
                'source' => mb_substr($source, 0, 120),
                'receiving_number' => mb_substr(self::pick($r, ['receiving_number', 'receiving_number_format']), 0, 40),
                'agent_name' => mb_substr($agentName, 0, 160), 'agent_email' => mb_substr($agentEmail, 0, 190),
                'duration' => (int)max(0, (float)self::pick($r, ['duration', 'total_time'])),
                'talk_time' => (int)max(0, (float)self::pick($r, ['talk_time', 'talk_duration'])),
                'ring_time' => (int)max(0, (float)self::pick($r, ['ring_time', 'ring_duration', 'wait_time'])),
                'voicemail' => !empty($r['voicemail']) || !empty($r['is_voicemail']),
            ];
        }

        // ───────────── transport ─────────────
        public static function rowsOf(array $d, string $key): ?array
        {
            if (isset($d[$key]) && is_array($d[$key]) && ($d[$key] === [] || array_is_list($d[$key]))) return $d[$key];
            if (isset($d['data']) && is_array($d['data']) && ($d['data'] === [] || array_is_list($d['data']))) return $d['data'];
            if (array_is_list($d) && ($d === [] || is_array($d[0] ?? null))) return $d;
            return null;
        }
        private static function total(array $d): ?int { foreach (['total_entries', 'total', 'total_count', 'count'] as $k) if (isset($d[$k]) && is_numeric($d[$k])) return (int)$d[$k]; return null; }

        /**
         * Every page of a list. $strict: a list shorter than the total CTM states is refused (a list that is not
         * complete is not a list). Otherwise at most $maxPages pages, and $trunc says more were there.
         */
        private function all(string $path, string $key, array $q, int $maxPages, bool $strict, ?bool &$trunc = null): array
        {
            $out = []; $total = null; $pages = null; $trunc = false; $seen = [];
            for ($p = 1; $p <= $maxPages; $p++) {
                [$s, $d] = $this->req($path, $q + ['page' => $p, 'per_page' => self::PER_PAGE]);
                if ($s < 200 || $s >= 300) throw new ItopsCtmException($this->errMsg($s, $d), $s);
                $rows = self::rowsOf($d, $key);
                if ($rows === null) throw new ItopsCtmException('CallTrackingMetrics answered, but not with a list of ' . $key . '.', $s);
                if ($total === null) $total = self::total($d);
                if ($pages === null && isset($d['total_pages']) && is_numeric($d['total_pages'])) $pages = (int)$d['total_pages'];
                $new = 0;
                foreach ($rows as $r) { if (!is_array($r)) continue; $id = self::txt($r['id'] ?? '') ?: md5(json_encode($r)); if (isset($seen[$id])) continue; $seen[$id] = 1; $out[] = $r; $new++; }
                $more = $pages !== null ? $p < $pages : (count($rows) >= self::PER_PAGE && $new > 0);
                if (array_key_exists('next_page', $d) && ($d['next_page'] === null || $d['next_page'] === '' || $d['next_page'] === false)) $more = false;
                if (!$more) { $trunc = false; break; }
                if ($p === $maxPages) $trunc = true;
            }
            if ($strict && ($trunc || ($total !== null && count($out) < $total)))
                throw new ItopsCtmException('CallTrackingMetrics has ' . ($total ?? 'more') . ' ' . $key . ' but only ' . count($out) . ' could be read. Nothing was changed.', 0);
            return $out;
        }

        private function req(string $path, array $query = []): array
        {
            if (!function_exists('curl_init')) throw new ItopsCtmException('This server cannot make web requests (the PHP curl extension is missing).');
            $url = self::base() . $path . ($query ? '?' . http_build_query($query) : '');
            for ($try = 0; $try < 2; $try++) {
                $ch = curl_init($url); $hdr = [];
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (defined('ITOPS_CTM_BASE') ? CURLPROTO_HTTP : 0),
                    CURLOPT_HTTPHEADER => ['Authorization: ' . $this->auth, 'Accept: application/json', 'User-Agent: ' . (function_exists('itops_app_name') ? preg_replace('/[^A-Za-z0-9 ._-]/', '', itops_app_name()) : 'app') . ' company systems reader'],
                    CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) { $p = explode(':', $line, 2); if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); },
                ]);
                $raw = curl_exec($ch);
                if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new ItopsCtmException('No answer from CallTrackingMetrics (' . $err . ').', 0); }
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($status === 429 && $try === 0) { sleep(max(1, min(5, (int)($hdr['retry-after'] ?? 2)))); continue; }
                $data = json_decode((string)$raw, true);
                return [$status, is_array($data) ? $data : ['_not_json' => true]];
            }
            return [429, []];
        }

        private function errMsg(int $status, array $data): string
        {
            $m = $data['message'] ?? ($data['error'] ?? ($data['errors'][0] ?? ''));
            if (is_array($m)) $m = self::txt($m['message'] ?? '');
            $m = is_string($m) ? trim(mb_substr($m, 0, 200)) : '';
            $lead = $status === 401 || $status === 403 ? 'CallTrackingMetrics refused the key (HTTP ' . $status . '). Check the access key and the secret key, and that API access is on for the account (Account Settings → API Integration).'
                  : ($status === 404 ? 'CallTrackingMetrics does not know that address or account (HTTP 404). Check the account ID on the connection.'
                  : ($status === 429 ? 'CallTrackingMetrics says too many requests were made with this key (HTTP 429). Wait a minute and try again.'
                  : ($status >= 500 ? 'CallTrackingMetrics has a problem on its side (HTTP ' . $status . '). Try again later.'
                  : 'CallTrackingMetrics answered HTTP ' . $status . '.')));
            return $lead . ($m !== '' ? ' It said: ' . $m : '');
        }
    }
}
