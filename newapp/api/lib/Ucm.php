<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT151 — the office phone system: a Grandstream UCM63xx (UCM6302) through its HTTPS API.
 *
 *   API      POST https://<pbx>:8089/api, JSON {"request": {"action": …, "cookie": …}}. Enabled on the PBX under
 *            Integrations → API Configuration → HTTPS API Settings (New), with its own API user (not the admin).
 *   Sign-in  challenge (user, version 1.0) → login (token = md5(challenge + password)) → a cookie for every request;
 *            the session ends after 5 minutes without activity. One sign-in per reading, signed out at the end.
 *   Reads    listAccount (extension, account_type, fullname, out_of_service, status, addr, email when the firmware
 *            gives it), getSIPAccount (one extension), getSystemStatus / getSystemGeneralStatus (model, firmware, uptime).
 *   Writes   updateSIPAccount {extension, out_of_service: yes|no, …} then applyChanges. Only to take an extension out of
 *            service (offboarding) and to put it back. BT153: addSIPAccountAndUser, updateSIPAccount (name, email,
 *            voicemail PIN), getSIPAccountQR, deleteUser (only after the waiting period, PbxService.php); read only:
 *            listVoIPTrunk, listDepartment. BT152: cdrapi (call records).
 *
 * Safety: the PBX bans an address after several failed sign-ins, so a reading stops at the FIRST refused sign-in and
 * says so, and nothing retries by itself. The PBX's own certificate is usually self-signed: the first successful test
 * remembers the certificate's public key (pinned) and every later call must meet the same key — a different
 * certificate is refused until a Super Admin accepts it again on the connection.
 * Address: the PBX's public address and port (one port-forward allowed only from this server), or its RemoteConnect
 * address (xxxx.a.gdms.cloud) when the API answers there. "Test" says which works.
 */
if (!class_exists('OpshUcm')) {
    class OpshUcmException extends RuntimeException {
        public int $code2 = 0; public bool $loginRefused = false; public bool $pinMismatch = false;
    }

    final class OpshUcm
    {
        private string $url; private string $user; private string $pass; private string $pin; private ?string $cookie = null;
        public string $seenPin = '';   // the public key of the certificate met (sha256//… form), for the first pin

        public function __construct(string $address, string $user, string $pass, string $pin = '')
        {
            $address = trim($address); $user = trim($user);
            if ($address === '' || $user === '' || $pass === '') throw new OpshUcmException('The address, the API user or the API password of the phone system is missing.');
            if (!preg_match('~^https://[A-Za-z0-9.-]{1,190}(:\d{2,5})?/?$~', rtrim($address, '/') . '/')) throw new OpshUcmException('The address is the PBX\'s web address with https and the API port, for example https://pbx.example.com:8089 (no path).');
            $this->url = rtrim($address, '/') . '/api'; $this->user = $user; $this->pass = $pass; $this->pin = trim($pin);
            if (defined('OPSH_UCM_TEST_URL') && is_string(OPSH_UCM_TEST_URL) && OPSH_UCM_TEST_URL !== '') $this->url = OPSH_UCM_TEST_URL;   // a test copy only
        }

        /** One request. Throws a sentence. */
        private function post(array $req, bool $loose = false): array
        {
            if (!function_exists('curl_init')) throw new OpshUcmException('This server cannot make web requests (the PHP curl extension is missing).');
            if ($this->cookie !== null && !isset($req['cookie'])) $req['cookie'] = $this->cookie;
            $ch = curl_init($this->url);
            $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json;charset=UTF-8', 'Accept: application/json'], CURLOPT_POSTFIELDS => json_encode(['request' => $req]),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (defined('OPSH_UCM_TEST_URL') ? CURLPROTO_HTTP : 0), CURLOPT_CERTINFO => true];
            // the PBX's own certificate: checked against the remembered public key, not against a public authority
            $opt[CURLOPT_SSL_VERIFYPEER] = false; $opt[CURLOPT_SSL_VERIFYHOST] = 0;
            if ($this->pin !== '') $opt[CURLOPT_PINNEDPUBLICKEY] = $this->pin;
            curl_setopt_array($ch, $opt);
            $raw = curl_exec($ch);
            if ($raw === false) {
                $no = curl_errno($ch); $e = curl_error($ch); curl_close($ch);
                $x = new OpshUcmException($no === 90 ? 'The phone system answered with a different certificate than the one accepted before. If the PBX\'s certificate was renewed, a Super Admin accepts the new one: enter the API password again on the connection and click "Save the key and test". Nothing was read.' : 'No answer from the phone system at this address (' . $e . '). Check the address and port, that the PBX allows this server\'s address, and that the port is open to it.');
                $x->pinMismatch = $no === 90; throw $x;
            }
            if ($this->pin === '' && $this->seenPin === '') {
                $ci = curl_getinfo($ch, CURLINFO_CERTINFO);
                foreach ((array)$ci as $cert) { $pem = is_array($cert) ? ($cert['Cert'] ?? '') : ''; if ($pem !== '') { $pk = openssl_pkey_get_public($pem); $det = $pk ? openssl_pkey_get_details($pk) : null;
                    if ($det && !empty($det['key'])) { $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $det['key'])); $this->seenPin = 'sha256//' . base64_encode(hash('sha256', $der, true)); } break; } }
            }
            $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($st !== 200) throw new OpshUcmException('The phone system answered HTTP ' . $st . ' at this address. Is it the API port (8089 by default) and is the HTTPS API switched on (Integrations → API Configuration)?');
            $d = json_decode((string)$raw, true);
            if ($loose && is_array($d) && (isset($d['cdr_root']) || isset($d['response']['cdr_root']))) return $d;   // BT152: call records may come without "status"
            if (!is_array($d) || !array_key_exists('status', $d)) throw new OpshUcmException('The phone system answered, but not in the API\'s form. Is this the API address of a Grandstream UCM?');
            return $d;
        }

        private static function errText(int $s): string
        {
            $m = [-6 => 'the session cookie is missing', -8 => 'the session expired', -37 => 'the API user or password is wrong', -45 => 'another change is being applied on the PBX; try again in a minute',
                  -47 => 'the API user has no permission for this', -68 => 'the API user is locked (too many failed sign-ins)', -70 => 'this address is not allowed to sign in', -24 => 'that extension does not exist', -25 => 'a value was refused'];
            return $m[$s] ?? 'error ' . $s;
        }

        public function login(): void
        {
            $c = $this->post(['action' => 'challenge', 'user' => $this->user, 'version' => '1.0']);
            if ((int)$c['status'] !== 0 || empty($c['response']['challenge'])) { $x = new OpshUcmException('The phone system refused the API user (' . self::errText((int)$c['status']) . '). Check the user under Integrations → API Configuration. Nothing more was tried: the PBX blocks an address after a few failed sign-ins.'); $x->loginRefused = true; $x->code2 = (int)$c['status']; throw $x; }
            $l = $this->post(['action' => 'login', 'user' => $this->user, 'token' => md5((string)$c['response']['challenge'] . $this->pass)]);
            if ((int)$l['status'] !== 0 || empty($l['response']['cookie'])) { $x = new OpshUcmException('The phone system refused the sign-in (' . self::errText((int)$l['status']) . '). Check the API password. Nothing more was tried: the PBX blocks an address after a few failed sign-ins.'); $x->loginRefused = true; $x->code2 = (int)$l['status']; throw $x; }
            $this->cookie = (string)$l['response']['cookie'];
        }
        public function logout(): void { if ($this->cookie === null) return; try { $this->post(['action' => 'logout']); } catch (Throwable $e) {} $this->cookie = null; }

        public function call(string $action, array $p = []): array
        {
            if ($this->cookie === null) $this->login();
            $d = $this->post(['action' => $action] + $p);
            if ((int)$d['status'] !== 0) throw new OpshUcmException('The phone system refused "' . $action . '" (' . self::errText((int)$d['status']) . ').');
            return is_array($d['response'] ?? null) ? $d['response'] : [];
        }

        /**
         * BT152: call records of a period through the API ("cdrapi" with the session cookie). $from/$to are the PBX's
         * local time, YYYY-MM-DDTHH:MM:SS. Returns the records as they came (each: the main record), oldest first.
         * At most $maxPages × 1,000. Throws a sentence; ->code2 = -1 when the PBX does not know the action.
         */
        public function cdr(string $from, string $to, int $maxPages = 10, ?bool &$more = null): array
        {
            if ($this->cookie === null) $this->login();
            $out = []; $more = false;
            for ($p = 0; $p < $maxPages; $p++) {
                $d = $this->post(['action' => 'cdrapi', 'format' => 'json', 'numRecords' => '1000', 'offset' => (string)($p * 1000), 'startTime' => $from, 'endTime' => $to], true);
                if (isset($d['status']) && (int)$d['status'] !== 0 && !isset($d['cdr_root']) && !isset($d['response']['cdr_root'])) {
                    $x = new OpshUcmException('The phone system did not give its call records (' . self::errText((int)$d['status']) . '). Check that the API user may read call records (CDR).'); $x->code2 = (int)$d['status']; throw $x; }
                $rows = $d['cdr_root'] ?? ($d['response']['cdr_root'] ?? []);
                if (!is_array($rows)) $rows = [];
                foreach ($rows as $r) if (is_array($r)) $out[] = self::cdrRow($r);
                if (count($rows) < 1000) return $out;
            }
            $more = true;
            return $out;
        }

        /**
         * BT152: the older call-record service of the UCM (Value-added Features → API Configuration → CDR HTTP API, port 8443,
         * its own user, digest sign-in, "Permitted IP(s)"). Used only when the connection has a CDR user and the main API
         * does not give call records on this firmware.
         */
        public function cdrLegacy(string $user, string $pass, int $port, string $from, string $to, int $maxPages = 10, ?bool &$more = null): array
        {
            $base = defined('OPSH_UCM_TEST_CDR_URL') && is_string(OPSH_UCM_TEST_CDR_URL) && OPSH_UCM_TEST_CDR_URL !== '' ? OPSH_UCM_TEST_CDR_URL
                  : preg_replace('~^(https://[^/:]+)(:\d+)?/api$~', '$1:' . max(1, min(65535, $port)) . '/cdrapi', $this->url);
            $out = []; $more = false;
            for ($p = 0; $p < $maxPages; $p++) {
                $ch = curl_init($base . '?' . http_build_query(['format' => 'json', 'numRecords' => 1000, 'offset' => $p * 1000, 'startTime' => $from, 'endTime' => $to]));
                $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40,
                        CURLOPT_HTTPAUTH => CURLAUTH_DIGEST, CURLOPT_USERPWD => $user . ':' . $pass, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (defined('OPSH_UCM_TEST_CDR_URL') ? CURLPROTO_HTTP : 0)];
                if ($this->pin !== '') $opt[CURLOPT_PINNEDPUBLICKEY] = $this->pin;
                curl_setopt_array($ch, $opt);
                $raw = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $e = curl_error($ch); curl_close($ch);
                if ($raw === false) throw new OpshUcmException('No answer from the call-record service of the phone system (' . $e . '). Check its port (8443 by default) and that "Permitted IP(s)" there includes this server.');
                if ($st === 401) throw new OpshUcmException('The call-record service refused the CDR user or password (HTTP 401).');
                if ($st !== 200) throw new OpshUcmException('The call-record service answered HTTP ' . $st . '.');
                $d = json_decode((string)$raw, true); $rows = is_array($d['cdr_root'] ?? null) ? $d['cdr_root'] : [];
                foreach ($rows as $r) if (is_array($r)) $out[] = self::cdrRow($r);
                if (count($rows) < 1000) return $out;
            }
            $more = true; return $out;
        }

        /** One call record in OpsHub's shape (the main record; transfers keep their first leg). */
        public static function cdrRow(array $r): array
        {
            $m = is_array($r['main_cdr'] ?? null) ? $r['main_cdr'] : $r;
            if (!isset($m['start']) && is_array($r['sub_cdr_1'] ?? null)) $m = $r['sub_cdr_1'] + $m;
            $t = fn($k) => isset($m[$k]) && is_scalar($m[$k]) ? trim((string)$m[$k]) : '';
            $srcT = $t('src_trunk_name'); $dstT = $t('dst_trunk_name');
            $dir = $srcT !== '' ? 'inbound' : ($dstT !== '' ? 'outbound' : 'internal');
            $disp = strtoupper($t('disposition'));
            $status = stripos($t('lastapp'), 'voicemail') !== false ? 'voicemail' : ($disp === 'ANSWERED' ? 'answered' : strtolower($disp));
            $ext = $dir === 'inbound' ? ($t('dstchannel_ext') ?: ($t('dstanswer') ?: $t('dst'))) : ($t('channel_ext') ?: $t('src'));
            $other = $dir === 'inbound' ? $t('src') : ($dir === 'outbound' ? $t('dst') : $t('dst'));
            $key = $t('AcctId') !== '' ? 'a' . $t('AcctId') : ($t('uniqueid') !== '' ? 'u' . $t('uniqueid') : 's' . md5($t('session') . '|' . $t('start') . '|' . $t('src') . '|' . $t('dst')));
            $rec = $t('recordfiles'); $rec = trim(str_replace('@', ' ', $rec));
            return ['key' => mb_substr($key, 0, 80), 'start' => $t('start'), 'answer' => $t('answer'), 'end' => $t('end'), 'duration' => (int)$t('duration'), 'billsec' => (int)$t('billsec'),
                    'disposition' => mb_substr($disp, 0, 20), 'status' => mb_substr($status, 0, 20), 'direction' => $dir, 'src' => mb_substr($t('src'), 0, 64), 'dst' => mb_substr($t('dst'), 0, 64),
                    'caller_name' => mb_substr($t('caller_name') ?: preg_replace('/\s*<[^>]*>\s*/', '', $t('clid')), 0, 160), 'ext' => mb_substr(preg_replace('/[^0-9]/', '', $ext), 0, 20),
                    'other' => mb_substr($other, 0, 64), 'trunk' => mb_substr($srcT !== '' ? $srcT : $dstT, 0, 80), 'action_type' => mb_substr($t('action_type'), 0, 40),
                    'recording' => mb_substr($rec, 0, 255)];
        }

        /** Model, firmware, uptime (each may be empty when the firmware words it differently). */
        public function status(): array
        {
            $g = []; $s = [];
            try { $g = $this->call('getSystemGeneralStatus'); } catch (OpshUcmException $e) {}
            try { $s = $this->call('getSystemStatus'); } catch (OpshUcmException $e) {}
            $t = fn($v) => is_scalar($v) ? trim((string)$v) : '';
            return ['model' => $t($s['product-model'] ?? ($g['product-model'] ?? ($s['product_model'] ?? ''))), 'firmware' => $t($g['prog-version'] ?? ($g['prog_version'] ?? ($s['prog-version'] ?? ''))),
                    'uptime' => $t($s['up-time'] ?? ($s['uptime'] ?? '')), 'serial' => $t($s['serial-number'] ?? ($s['serial_number'] ?? '')), 'part' => $t($s['part-number'] ?? '')];
        }

        /** Every extension (SIP, and the others the PBX lists), in one shape. Throws when the list is not complete. */
        public function extensions(): array
        {
            $out = []; $total = null;
            for ($p = 1; $p <= 40; $p++) {
                $r = $this->call('listAccount', ['item_num' => 100, 'page' => $p, 'sidx' => 'extension', 'sord' => 'asc', 'options' => 'extension,account_type,fullname,out_of_service,status,addr,email,first_name,last_name']);
                $rows = is_array($r['account'] ?? null) ? $r['account'] : null;
                if ($rows === null) throw new OpshUcmException('The phone system answered, but not with a list of extensions.');
                if ($total === null && isset($r['total_item']) && is_numeric($r['total_item'])) $total = (int)$r['total_item'];
                foreach ($rows as $a) if (is_array($a) && trim((string)($a['extension'] ?? '')) !== '') $out[trim((string)$a['extension'])] = self::ext($a);
                $pages = isset($r['total_page']) && is_numeric($r['total_page']) ? (int)$r['total_page'] : null;
                if ($pages !== null ? $p >= $pages : count($rows) < 100) break;   // the PBX may give fewer per page than asked: its page count decides
            }
            if ($total !== null && count($out) < $total) throw new OpshUcmException('The phone system has ' . $total . ' extensions but only ' . count($out) . ' could be read. Nothing was changed.');
            return array_values($out);
        }

        public static function ext(array $a): array
        {
            $t = fn($v) => is_scalar($v) ? trim((string)$v) : '';
            $name = $t($a['fullname'] ?? ''); if ($name === '') $name = trim($t($a['first_name'] ?? '') . ' ' . $t($a['last_name'] ?? ''));
            return ['extension' => mb_substr($t($a['extension']), 0, 20), 'name' => mb_substr($name, 0, 160), 'email' => mb_strtolower(mb_substr($t($a['email'] ?? ''), 0, 190)),
                    'type' => mb_substr($t($a['account_type'] ?? ''), 0, 20), 'out_of_service' => in_array(strtolower($t($a['out_of_service'] ?? 'no')), ['yes', '1', 'true'], true),
                    'status' => mb_substr($t($a['status'] ?? ''), 0, 30), 'addr' => mb_substr($t($a['addr'] ?? ''), 0, 60)];
        }

        /**
         * Take an extension out of service (or put it back). Out of service also clears the call forwarding the PBX
         * reports for it (the fields present on this firmware), so calls are not sent on to the leaver's mobile.
         * Returns what changed, field by field.
         */
        public function setService(string $ext, bool $out): array
        {
            if (!preg_match('/^[0-9]{2,18}$/', $ext)) throw new OpshUcmException('Not an extension number.');
            $cur = $this->call('getSIPAccount', ['extension' => $ext]);
            $e = is_array($cur['extension'] ?? null) ? $cur['extension'] : $cur;
            $now = in_array(strtolower((string)($e['out_of_service'] ?? 'no')), ['yes', '1', 'true'], true);
            $chg = []; $p = ['extension' => $ext];
            if ($now !== $out) { $p['out_of_service'] = $out ? 'yes' : 'no'; $chg['Out of service'] = [$now ? 'yes' : 'no', $out ? 'yes' : 'no']; }
            if ($out) foreach (['cfu' => 'Forward all calls', 'cfn' => 'Forward when not answered', 'cfb' => 'Forward when busy'] as $k => $label)
                if (isset($e[$k]) && is_scalar($e[$k]) && trim((string)$e[$k]) !== '') { $p[$k] = ''; $chg[$label] = [trim((string)$e[$k]), '(cleared)']; }
            if (count($p) === 1) return [];
            $this->call('updateSIPAccount', $p);
            $this->apply();
            return $chg;
        }

        /** Apply the pending changes on the PBX (an apply already running, -45: wait a moment and try again). */
        public function apply(): void
        {
            for ($i = 0; $i < 3; $i++) {
                try { $this->call('applyChanges'); return; }
                catch (OpshUcmException $x) { if ($i === 2 || strpos($x->getMessage(), 'another change') === false) throw $x; sleep(3); }
            }
        }

        /** BT153: one extension as the PBX keeps it (getSIPAccount), or null when it does not exist (-24). */
        public function account(string $ext): ?array
        {
            try { $cur = $this->call('getSIPAccount', ['extension' => $ext]); }
            catch (OpshUcmException $x) { if (strpos($x->getMessage(), 'does not exist') !== false) return null; throw $x; }
            $e = is_array($cur['extension'] ?? null) ? $cur['extension'] : $cur;
            return $e ?: null;
        }

        /**
         * BT153: a new SIP extension with its user (addSIPAccountAndUser), then applied. $p: extension, fullname, email,
         * secret (SIP password), vmsecret (voicemail PIN), user_password (user portal), permission. The secrets are made by
         * the caller and never kept by the app.
         */
        public function addExtension(array $p): void
        {
            $first = trim(strtok((string)($p['fullname'] ?? ''), ' ') ?: ''); $last = trim(substr((string)($p['fullname'] ?? ''), strlen($first)));
            $this->call('addSIPAccountAndUser', ['extension' => (string)$p['extension'], 'fullname' => (string)($p['fullname'] ?? ''), 'first_name' => $first, 'last_name' => $last,
                'email' => (string)($p['email'] ?? ''), 'secret' => (string)$p['secret'], 'vmsecret' => (string)$p['vmsecret'], 'user_password' => (string)$p['user_password'],
                'permission' => (string)($p['permission'] ?? 'internal-local-national'), 'hasvoicemail' => 'yes']);
            $this->apply();
        }

        /** BT153: change some settings of an extension (updateSIPAccount), then applied. */
        public function updateExtension(string $ext, array $p): void { $this->call('updateSIPAccount', ['extension' => $ext] + $p); $this->apply(); }

        /** BT153: remove an extension and its user (deleteUser), then applied. */
        public function deleteExtension(string $ext): void { $this->call('deleteUser', ['user_name' => $ext]); $this->apply(); }

        /** BT153: the QR code that signs Grandstream Wave in as this extension, as a PNG data URI ('' when the firmware gives none). */
        public function waveQr(string $ext): string
        {
            $r = $this->call('getSIPAccountQR', ['extension' => $ext]);
            $b = '';
            foreach (['qr_base', 'qr_code', 'qrcode', 'qr'] as $k) if (isset($r[$k]) && is_string($r[$k]) && $r[$k] !== '') { $b = $r[$k]; break; }
            if ($b === '') return '';
            if (str_starts_with($b, 'data:image/')) return $b;
            $raw = base64_decode(preg_replace('/\s+/', '', $b), true);
            if ($raw === false || strlen($raw) < 8) return '';
            $mime = str_starts_with($raw, "\x89PNG") ? 'image/png' : (str_starts_with($raw, "\xFF\xD8") ? 'image/jpeg' : (str_starts_with($raw, 'GIF8') ? 'image/gif' : ''));
            return $mime !== '' ? 'data:' . $mime . ';base64,' . base64_encode($raw) : '';
        }

        /** BT153: the departments of the PBX (listDepartment), as {id, name, members}. [] when the firmware has none. */
        public function departments(): array
        {
            try { $r = $this->call('listDepartment'); } catch (OpshUcmException $x) { return []; }
            $rows = $r['local_department_info'] ?? ($r['department'] ?? []); $out = [];
            foreach (is_array($rows) ? $rows : [] as $d) if (is_array($d)) {
                $m = $d['members'] ?? ($d['member'] ?? '');
                $out[] = ['id' => (string)($d['department_id'] ?? ($d['id'] ?? '')), 'name' => mb_substr((string)($d['department_name'] ?? ($d['name'] ?? '')), 0, 120),
                          'members' => is_array($m) ? array_values(array_map('strval', $m)) : array_values(array_filter(array_map('trim', explode(',', (string)$m)), 'strlen'))];
            }
            return $out;
        }

        /** BT153: the VoIP trunks (listVoIPTrunk): name, host, type, and whether it is out of service. */
        public function trunks(): array
        {
            $r = $this->call('listVoIPTrunk', ['options' => 'trunk_index,trunk_name,host,trunk_type,out_of_service,status']);
            $rows = $r['voip_trunk'] ?? ($r['trunk'] ?? []); $out = [];
            foreach (is_array($rows) ? $rows : [] as $t) if (is_array($t)) {
                $v = fn($k) => isset($t[$k]) && is_scalar($t[$k]) ? trim((string)$t[$k]) : '';
                $out[] = ['index' => $v('trunk_index'), 'name' => mb_substr($v('trunk_name'), 0, 80), 'host' => mb_substr($v('host'), 0, 120), 'type' => mb_substr($v('trunk_type'), 0, 30),
                          'out_of_service' => in_array(strtolower($v('out_of_service')), ['yes', '1', 'true'], true), 'status' => mb_substr($v('status'), 0, 40)];
            }
            return $out;
        }
    }
}
