<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT45 — JustCall, read only.
 *
 * The request, the header and the error text follow JustCall API v2.1 ( the Authorization header is
 * "{key}:{secret}", with a colon and not Basic). ITOps reads the USERS:
 * who has a seat, with which role and which numbers. Nothing is written to JustCall. JustCall's API has no call to
 * add or remove a user, so those stay by hand and ITOps checks afterwards.
 *
 * A user as JustCall documents it: id, name, email, role, extension, groups, owned_numbers[], shared_numbers[],
 * created_at, last_login_timestamp, timezone, available, on_call. Fields the answer does not carry are read as empty.
 *
 * Not verified against the live account when this was written. "Test connection" shows what the first user looked like.
 */
if (!class_exists('ItopsJustCallException')) {
    class ItopsJustCallException extends RuntimeException {
        public int $status = 0;
        public function __construct(string $m, int $status = 0) { parent::__construct($m); $this->status = $status; }
    }

    final class ItopsJustCall
    {
        const BASE = 'https://api.justcall.io/v2.1';
        const PER_PAGE = 50;
        const MAX_PAGES = 40;      // 2,000 users: far more than a company account has; a guard against an endless list
        private string $auth;
        private int $timeout;
        public ?int $limitLeft = null;

        public function __construct(string $apiKey, string $apiSecret, int $timeout = 12)
        {
            $apiKey = trim($apiKey); $apiSecret = trim($apiSecret);
            if ($apiKey === '' || $apiSecret === '') throw new ItopsJustCallException('The JustCall API key or secret is missing.');
            if (preg_match('/[\r\n]/', $apiKey . $apiSecret)) throw new ItopsJustCallException('The JustCall API key or secret holds a line break. Copy it again.');
            $this->auth = $apiKey . ':' . $apiSecret; $this->timeout = max(3, min(30, $timeout));
        }

        /** Where the API is. A test installation can point somewhere else with the constant ITOPS_JUSTCALL_BASE in config.php. */
        public static function base(): string
        {
            return defined('ITOPS_JUSTCALL_BASE') && is_string(ITOPS_JUSTCALL_BASE) && ITOPS_JUSTCALL_BASE !== '' ? rtrim(ITOPS_JUSTCALL_BASE, '/') : self::BASE;
        }

        /** One cheap call that proves the key works and can read users. Returns ['users_seen' => n, 'fields' => [...]]. */
        public function test(): array
        {
            [$s, $d] = $this->req('/users', ['per_page' => 1]);
            if ($s < 200 || $s >= 300) throw new ItopsJustCallException($this->errMsg($s, $d), $s);
            $rows = $this->rows($d);
            if ($rows === null) throw new ItopsJustCallException('JustCall answered, but not with a list of users. The key may belong to another kind of account.', $s);
            return ['users_seen' => count($rows), 'fields' => $rows ? array_slice(array_keys((array)$rows[0]), 0, 30) : [], 'total' => isset($d['total_count']) && is_numeric($d['total_count']) ? (int)$d['total_count'] : null];
        }

        /**
         * Every user of the account, in ITOps's shape. Throws when any page cannot be read: a list that is not complete
         * is not a list (an offboarding check must never read "could not read" as "no user").
         */
        public function users(): array
        {
            $out = []; $seen = []; $page = null; $guessed = false; $total = null;
            for ($n = 0; $n < self::MAX_PAGES; $n++) {
                $q = ['per_page' => self::PER_PAGE] + ($page !== null ? ['page' => $page] : []);
                [$s, $d] = $this->req('/users', $q);
                if ($s < 200 || $s >= 300) throw new ItopsJustCallException($this->errMsg($s, $d), $s);
                $rows = $this->rows($d);
                if ($rows === null) throw new ItopsJustCallException('JustCall answered, but not with a list of users.', $s);
                foreach (['total_count', 'total', 'totalCount'] as $tk) if ($total === null && isset($d[$tk]) && is_numeric($d[$tk])) $total = (int)$d[$tk];
                $new = 0;
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $u = self::shape($r);
                    if ($u['id'] === '' || isset($seen[$u['id']])) continue;
                    $seen[$u['id']] = 1; $out[] = $u; $new++;
                }
                // The next page: the link JustCall gives when it gives one; otherwise the next number, as long as pages are full.
                $next = self::pageOf($d['next_page_link'] ?? null);
                if ($next !== null) { if ($page !== null && $next <= $page) break; $page = $next; continue; }
                if (array_key_exists('next_page_link', $d)) break;            // the link is there and empty: this was the last page
                if (count($rows) < self::PER_PAGE) break;
                if ($new === 0) { if ($guessed) break; $guessed = true; }     // a full page of users already seen: page numbers start one later than guessed
                $page = $page === null ? 1 : $page + 1;
            }
            // JustCall said how many there are and fewer were read: the list is not complete, so it is not used.
            if ($total !== null && count($out) < $total) throw new ItopsJustCallException('JustCall has ' . $total . ' users but only ' . count($out) . ' could be read. Nothing was changed.', 0);
            return $out;
        }

        /** BT150: the company's JustCall numbers (for choosing which lines make tickets). */
        public function numbers(): array
        {
            $out = []; $seen = [];
            for ($p = 0; $p < 20; $p++) {
                [$s, $d] = $this->req('/phone-numbers', ['per_page' => 100, 'page' => $p]);
                if ($s < 200 || $s >= 300) throw new ItopsJustCallException($this->errMsg($s, $d), $s);
                $rows = $this->rows($d) ?? (isset($d['phone_numbers']) && is_array($d['phone_numbers']) ? $d['phone_numbers'] : null);
                if ($rows === null) throw new ItopsJustCallException('JustCall answered, but not with a list of numbers.', $s);
                $new = 0;
                foreach ($rows as $r) { if (!is_array($r)) continue; $t = fn($v) => is_string($v) || is_int($v) ? trim((string)$v) : '';
                    $num = $t($r['justcall_number'] ?? ($r['number'] ?? ($r['phone_number'] ?? ''))); if ($num === '' || isset($seen[$num])) continue; $seen[$num] = 1; $new++;
                    $out[] = ['id' => $t($r['id'] ?? ''), 'number' => mb_substr($num, 0, 40), 'formatted' => '', 'label' => mb_substr($t($r['friendly_name'] ?? ($r['name'] ?? ($r['justcall_line_name'] ?? ''))), 0, 160),
                              'source' => '', 'receiving' => [], 'routing' => '', 'status' => '', 'kind' => mb_substr($t($r['number_type'] ?? ($r['type'] ?? '')), 0, 30)]; }
                if (count($rows) < 100 || $new === 0) break;
            }
            return $out;
        }

        /**
         * BT150: calls, newest first, until a call id at or below $stopId (exclusive) or $maxPages pages. $q adds the
         * filters JustCall documents (from_datetime, to_datetime, call_direction, call_type…). Returns [rows, truncated].
         */
        public function calls(array $q, string $stopId = '', int $maxPages = 10): array
        {
            $out = []; $seen = []; $trunc = false; $reached = false; $guessed = false;
            for ($p = 0; $p < $maxPages + 1; $p++) {
                [$s, $d] = $this->req('/calls', $q + ['per_page' => 100, 'page' => $p, 'sort' => 'id', 'order' => 'desc']);
                if ($s < 200 || $s >= 300) throw new ItopsJustCallException($this->errMsg($s, $d), $s);
                $rows = $this->rows($d) ?? (isset($d['calls']) && is_array($d['calls']) ? $d['calls'] : null);
                if ($rows === null) throw new ItopsJustCallException('JustCall answered, but not with a list of calls.', $s);
                $new = 0;
                foreach ($rows as $r) { if (!is_array($r)) continue; $c = self::call($r); if ($c['id'] === '' || isset($seen[$c['id']])) continue;
                    if ($stopId !== '' && self::cmpId($c['id'], $stopId) <= 0) { $reached = true; continue; }
                    $seen[$c['id']] = 1; $out[] = $c; $new++; }
                if ($reached || count($rows) < 100) break;
                if ($new === 0) { if ($guessed) break; $guessed = true; continue; }   // page numbers start at 1 on this account: page 0 was page 1
                if ($p >= $maxPages - 1) { $trunc = true; break; }
            }
            return [$out, $trunc];
        }

        /** The newest call id there is ('' when none). */
        public function newestCallId(): string
        {
            [$s, $d] = $this->req('/calls', ['per_page' => 1, 'page' => 0, 'sort' => 'id', 'order' => 'desc']);
            if ($s < 200 || $s >= 300) throw new ItopsJustCallException($this->errMsg($s, $d), $s);
            $rows = $this->rows($d) ?? [];
            return $rows && is_array($rows[0]) ? self::call($rows[0])['id'] : '';
        }

        public static function cmpId(string $a, string $b): int { $a = ltrim($a, '0'); $b = ltrim($b, '0'); return strlen($a) <=> strlen($b) ?: strcmp($a, $b); }

        /** A JustCall call in the shape the missed-call engine and the report use. */
        public static function call(array $r): array
        {
            $t = fn($v) => is_string($v) || is_int($v) || is_float($v) ? trim((string)$v) : '';
            $info = is_array($r['call_info'] ?? null) ? $r['call_info'] : [];
            $dur = is_array($r['call_duration'] ?? null) ? $r['call_duration'] : [];
            $dir = mb_strtolower($t($info['direction'] ?? ($r['direction'] ?? '')));
            $type = mb_strtolower($t($info['type'] ?? ($r['type'] ?? '')));
            $type = str_replace(['incoming_', 'outgoing_', '_'], ['', '', ' '], $type);
            $date = $t($r['call_date'] ?? ''); $time = $t($r['call_time'] ?? '');
            $ts = $date !== '' ? strtotime($date . ' ' . ($time !== '' ? $time : '00:00:00')) : false;
            return [
                'id' => mb_substr($t($r['id'] ?? ''), 0, 64),
                'at' => $ts ? date('Y-m-d\TH:i:sP', $ts) : '',
                'direction' => in_array($dir, ['incoming', 'inbound'], true) ? 'inbound' : ($dir === '' ? '' : 'outbound'),
                'status' => mb_substr($type, 0, 30),
                'caller_number' => mb_substr($t($r['contact_number'] ?? ''), 0, 40),
                'caller_name' => mb_substr($t($r['contact_name'] ?? ''), 0, 160),
                'tracking_number' => mb_substr($t($r['justcall_number'] ?? ''), 0, 40),
                'tracking_label' => mb_substr($t($r['justcall_line_name'] ?? ''), 0, 160),
                'source' => '',
                'agent_name' => mb_substr($t($r['agent_name'] ?? ''), 0, 160), 'agent_email' => mb_strtolower(mb_substr($t($r['agent_email'] ?? ''), 0, 190)),
                'duration' => (int)$t($dur['total_duration'] ?? ($r['call_duration_total'] ?? 0)),
                'talk_time' => (int)$t($dur['conversation_time'] ?? ($dur['talk_time'] ?? 0)),
                'ring_time' => (int)$t($dur['ring_time'] ?? 0),
                'voicemail' => $type === 'voicemail',
                'transcript' => mb_substr($t($info['voicemail_transcription'] ?? ''), 0, 2000),
            ];
        }

        /** A JustCall user as the rest of ITOps uses it. */
        public static function shape(array $r): array
        {
            $txt = fn($v) => is_string($v) || is_int($v) || is_float($v) ? trim((string)$v) : '';
            $nums = function ($v) use ($txt): array {
                $o = [];
                foreach (is_array($v) ? $v : [] as $x) { $t = is_array($x) ? $txt($x['number'] ?? ($x['phone_number'] ?? ($x['phone'] ?? ''))) : $txt($x); if ($t !== '' && !in_array($t, $o, true)) $o[] = mb_substr($t, 0, 40); }
                return array_slice($o, 0, 50);
            };
            $groups = [];
            foreach (is_array($r['groups'] ?? null) ? $r['groups'] : [] as $g) { $t = is_array($g) ? $txt($g['name'] ?? ($g['group_name'] ?? '')) : $txt($g); if ($t !== '') $groups[] = mb_substr($t, 0, 80); }
            $name = $txt($r['name'] ?? '');
            if ($name === '') $name = trim($txt($r['firstname'] ?? ($r['first_name'] ?? '')) . ' ' . $txt($r['lastname'] ?? ($r['last_name'] ?? '')));
            return [
                'id' => mb_substr($txt($r['id'] ?? ($r['agent_id'] ?? '')), 0, 64),
                'name' => mb_substr($name, 0, 190),
                'email' => mb_strtolower(mb_substr($txt($r['email'] ?? ''), 0, 190)),
                'role' => mb_substr($txt($r['role'] ?? ''), 0, 60),
                'extension' => mb_substr($txt($r['extension'] ?? ''), 0, 20),
                'owned_numbers' => $nums($r['owned_numbers'] ?? []),
                'shared_numbers' => $nums($r['shared_numbers'] ?? []),
                'groups' => array_slice($groups, 0, 30),
                'created_at' => mb_substr($txt($r['created_at'] ?? ''), 0, 25),
                'last_login' => mb_substr($txt($r['last_login_timestamp'] ?? ($r['last_login'] ?? '')), 0, 25),
            ];
        }

        private function rows(array $d): ?array
        {
            if (isset($d['data']) && is_array($d['data']) && ($d['data'] === [] || array_is_list($d['data']))) return $d['data'];
            if (isset($d['users']) && is_array($d['users']) && array_is_list($d['users'])) return $d['users'];
            return null;
        }

        /** The page number inside a "next page" link, or null. Only the number is taken: the link itself is never followed. */
        private static function pageOf($link): ?int
        {
            if (!is_string($link) || $link === '') return null;
            $q = parse_url($link, PHP_URL_QUERY); if (!is_string($q)) return null;
            parse_str($q, $p);
            return isset($p['page']) && is_string($p['page']) && ctype_digit($p['page']) ? (int)$p['page'] : null;
        }

        private function req(string $path, array $query = []): array
        {
            if (!function_exists('curl_init')) throw new ItopsJustCallException('This server cannot make web requests (the PHP curl extension is missing).');
            $url = self::base() . $path . ($query ? '?' . http_build_query($query) : '');
            $ch = curl_init($url); $hdr = [];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Authorization: ' . $this->auth, 'Accept: application/json', 'User-Agent: ITOps-JustCall/1.0'],
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) { $p = explode(':', $line, 2); if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); },
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new ItopsJustCallException('No answer from JustCall (' . $err . ').', 0); }
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            foreach (['x-rate-limit-remaining', 'x-ratelimit-remaining', 'ratelimit-remaining'] as $h) if (isset($hdr[$h]) && is_numeric($hdr[$h])) $this->limitLeft = (int)$hdr[$h];
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) $data = ['_not_json' => true];
            return [$status, $data];
        }

        /** What went wrong, in a sentence: by the status first, with JustCall's own words after it. */
        private function errMsg(int $status, array $data): string
        {
            $m = $data['message'] ?? ($data['error'] ?? ($data['errors'][0]['message'] ?? ''));
            $m = is_string($m) ? trim(mb_substr($m, 0, 200)) : '';
            $lead = $status === 401 || $status === 403 ? 'JustCall refused the key (HTTP ' . $status . '). Check the API key and secret, and that the plan includes the API.'
                  : ($status === 429 ? 'JustCall says too many requests were made with this key (HTTP 429). Wait a few minutes; the key is shared with any other app that uses it.'
                  : ($status >= 500 ? 'JustCall has a problem on its side (HTTP ' . $status . '). Try again later.'
                  : 'JustCall answered HTTP ' . $status . '.'));
            return $lead . ($m !== '' ? ' JustCall said: ' . $m : '');
        }
    }
}
