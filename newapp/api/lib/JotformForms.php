<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT149 — Jotform beyond its team members (those go through SystemConnect.php / SaasUsers.php): the forms of the
 * account with who owns each, and the forms you choose whose new submissions become tickets. Read only: the key needs
 * "Read Access" only, and nothing is ever written to Jotform (no webhook is created there; the app asks every ten
 * minutes instead: one call per form switched on, 144 a day, inside even the Starter plan's 1,000 calls a day).
 *
 *   API       GET /v1/user/forms?offset&limit (content: id, username = the owner, title, status, created_at,
 *             updated_at, count, new, last_submission, url) · GET /v1/form/{id}/submissions?offset&limit&filter&orderby
 *             (content: id, form_id, created_at, status, answers {qid: {name, text, type, answer, prettyFormat}}).
 *             Header "APIKEY", never in an address. The US, EU and HIPAA servers as the connection says.
 *   settings  itops_settings.jotform_tickets = {forms: {form id: {on, title, category_id, priority, baseline, on_since,
 *             by}}, last_poll, last_result}
 *   table     itops_jf_submissions: every submission looked at, once (UNIQUE form + submission), with what was done.
 *
 * Rules to keep:
 *   - Submissions sent before a form was switched on never make tickets: switching on records the newest submission
 *     id there is (the baseline), and only higher ids count. No clock or time zone is involved.
 *   - A submission is looked at once. Deleted submissions are passed over.
 *   - The ticket carries the answers as the form shows them; files and signatures are named, not copied.
 *   - When the form has an email answer that belongs to an active person of the app, that person is the requester;
 *     otherwise the person who switched the form on, with the submitter kept as the ticket's contact.
 */
require_once __DIR__ . '/SystemConnect.php';

if (!class_exists('ItopsJotform')) {
    final class ItopsJotform
    {
        private string $key; private string $region;
        public function __construct(string $key, string $region = '')
        {
            $key = trim($key); if ($key === '') throw new RuntimeException('The Jotform key is missing.');
            if (preg_match('/[\r\n\s]/', $key)) throw new RuntimeException('The Jotform key holds a space or a line break. Copy it again.');
            $this->key = $key; $this->region = strtolower(trim($region));
        }
        public function base(): string
        {
            if (defined('ITOPS_SAAS_BASE_JOTFORM') && is_string(ITOPS_SAAS_BASE_JOTFORM) && ITOPS_SAAS_BASE_JOTFORM !== '') return rtrim(ITOPS_SAAS_BASE_JOTFORM, '/');
            return $this->region === 'eu' ? 'https://eu-api.jotform.com' : ($this->region === 'hipaa' ? 'https://hipaa-api.jotform.com' : 'https://api.jotform.com');
        }
        /** Where a person opens a submission in Jotform's own pages. */
        public function web(): string { return $this->region === 'eu' ? 'https://eu.jotform.com' : ($this->region === 'hipaa' ? 'https://hipaa.jotform.com' : 'https://www.jotform.com'); }

        public function get(string $path, array $q = []): array
        {
            if (!function_exists('curl_init')) throw new RuntimeException('This server cannot make web requests (the PHP curl extension is missing).');
            for ($try = 0; $try < 2; $try++) {
                $ch = curl_init($this->base() . $path . ($q ? '?' . http_build_query($q) : '')); $hdr = [];
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => ['APIKEY: ' . $this->key, 'Accept: application/json', 'User-Agent: ' . (function_exists('itops_app_name') ? preg_replace('/[^A-Za-z0-9 ._-]/', '', itops_app_name()) : 'app') . ' forms reader'],
                    CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) { $p = explode(':', $line, 2); if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); }]);
                $raw = curl_exec($ch);
                if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('No answer from Jotform (' . $e . ').'); }
                $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if (($st === 429 || $st === 503) && $try === 0) { sleep(max(1, min(5, (int)($hdr['retry-after'] ?? 2)))); continue; }
                $d = json_decode((string)$raw, true); if (!is_array($d)) $d = [];
                if ($st < 200 || $st >= 300 || (isset($d['responseCode']) && (int)$d['responseCode'] >= 400)) {
                    $code = isset($d['responseCode']) ? (int)$d['responseCode'] : $st; $m = is_string($d['message'] ?? null) ? mb_substr(trim($d['message']), 0, 200) : '';
                    $lead = $code === 401 || $code === 403 ? 'Jotform refused the key (HTTP ' . $code . '). Check the key on the connection, and that it has at least "Read Access".'
                          : ($code === 429 ? 'Jotform says the daily number of API calls of this plan is used up (HTTP 429). It starts again at midnight, Eastern time.'
                          : ($code === 404 ? 'Jotform does not know that form (HTTP 404). It may have been deleted.' : 'Jotform answered HTTP ' . $code . '.'));
                    throw new RuntimeException($lead . ($m !== '' && $code !== 401 ? ' It said: ' . $m : ''));
                }
                return $d;
            }
            throw new RuntimeException('Jotform kept answering "too many requests".');
        }

        /** Every form of the account (deleted ones left out). */
        public function forms(): array
        {
            $out = []; $seen = [];
            for ($off = 0, $n = 0; $n < 20; $n++, $off += 1000) {
                $d = $this->get('/v1/user/forms', ['offset' => $off, 'limit' => 1000]);
                $rows = is_array($d['content'] ?? null) ? $d['content'] : null;
                if ($rows === null) throw new RuntimeException('Jotform answered, but not with a list of forms.');
                foreach ($rows as $r) { if (!is_array($r)) continue; $f = self::form($r); if ($f['id'] === '' || isset($seen[$f['id']]) || $f['status'] === 'DELETED') continue; $seen[$f['id']] = 1; $out[] = $f; }
                if (count($rows) < 1000) return $out;
            }
            throw new RuntimeException('Jotform kept giving more forms; the list was not used.');
        }

        /** The submissions of a form with an id above $after, oldest first (at most $max). */
        public function submissions(string $formId, string $after, int $max = 200): array
        {
            if (!preg_match('/^[0-9]{1,24}$/', $formId)) throw new RuntimeException('Not a Jotform form id.');
            $out = []; $seen = [];
            $filter = $after !== '' ? ['filter' => json_encode(['id:gt' => $after])] : [];
            for ($off = 0; $off < $max; $off += 100) {
                $d = $this->get('/v1/form/' . $formId . '/submissions', ['offset' => $off, 'limit' => 100, 'orderby' => 'id'] + $filter);
                $rows = is_array($d['content'] ?? null) ? $d['content'] : null;
                if ($rows === null) throw new RuntimeException('Jotform answered, but not with a list of submissions.');
                foreach ($rows as $r) { if (!is_array($r)) continue; $id = self::txt($r['id'] ?? ''); if ($id === '' || isset($seen[$id])) continue;
                    if ($after !== '' && self::cmp($id, $after) <= 0) continue;   // the filter is honoured here too
                    $seen[$id] = 1; $out[] = $r; }
                if (count($rows) < 100) break;
            }
            usort($out, fn($a, $b) => self::cmp(self::txt($a['id'] ?? ''), self::txt($b['id'] ?? '')));
            return $out;
        }

        /** The newest submission id of a form ('' when it has none). */
        public function newest(string $formId): string
        {
            if (!preg_match('/^[0-9]{1,24}$/', $formId)) throw new RuntimeException('Not a Jotform form id.');
            $d = $this->get('/v1/form/' . $formId . '/submissions', ['offset' => 0, 'limit' => 20, 'orderby' => 'id']);
            $best = '';
            foreach ((array)($d['content'] ?? []) as $r) { $id = is_array($r) ? self::txt($r['id'] ?? '') : ''; if ($id !== '' && ($best === '' || self::cmp($id, $best) > 0)) $best = $id; }
            return $best;
        }

        public static function txt($v): string { return is_string($v) || is_int($v) || is_float($v) ? trim((string)$v) : ''; }
        /** Compare two submission ids as numbers (they are longer than a PHP integer may hold on some servers). */
        public static function cmp(string $a, string $b): int { $a = ltrim($a, '0'); $b = ltrim($b, '0'); return strlen($a) <=> strlen($b) ?: strcmp($a, $b); }
        public static function form(array $r): array
        {
            return ['id' => mb_substr(self::txt($r['id'] ?? ''), 0, 24), 'title' => mb_substr(self::txt($r['title'] ?? '') ?: 'Untitled form', 0, 190), 'owner' => mb_substr(self::txt($r['username'] ?? ''), 0, 120),
                    'status' => strtoupper(self::txt($r['status'] ?? '')), 'created_at' => self::txt($r['created_at'] ?? ''), 'updated_at' => self::txt($r['updated_at'] ?? ''),
                    'count' => (int)self::txt($r['count'] ?? '0'), 'last_submission' => self::txt($r['last_submission'] ?? ''), 'url' => mb_substr(self::txt($r['url'] ?? ''), 0, 300)];
        }
    }
}

if (!function_exists('itops_jf_client')) {

    function itops_jf_client(PDO $db): ItopsJotform {
        $cn = itops_conn_public($db, 'jotform');
        if (!$cn || !$cn['configured']) throw new RuntimeException('Jotform is not connected: a Super Admin enters its key under Settings → Company systems → Jotform → Set up.');
        $c = itops_conn_creds($db, 'jotform');
        return new ItopsJotform($c['api_key'] ?? '', $c['region'] ?? '');
    }

    function itops_jf_cfg(PDO $db): array {
        try { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute(['jotform_tickets']); $j = json_decode((string)$q->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        $j = (is_array($j) ? $j : []) + ['forms' => [], 'last_poll' => 0, 'last_result' => null];
        if (!is_array($j['forms'])) $j['forms'] = [];
        $clean = [];
        foreach ($j['forms'] as $id => $f) if (is_array($f) && preg_match('/^[0-9]{1,24}$/', (string)$id)) $clean[(string)$id] = $f + ['on' => false, 'title' => '', 'category_id' => 0, 'priority' => 'p3_medium', 'baseline' => '', 'on_since' => null, 'by' => 0];
        $j['forms'] = $clean;
        return $j;
    }
    function itops_jf_save(PDO $db, array $c): void {
        $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['jotform_tickets', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    function itops_jf_any_on(array $c): bool { foreach ($c['forms'] as $f) if (!empty($f['on'])) return true; return false; }

    function itops_jf_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_jf_submissions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id VARCHAR(24) NOT NULL, submission_id VARCHAR(32) NOT NULL,
            form_title VARCHAR(190) NOT NULL DEFAULT '', submitted_at VARCHAR(25) NOT NULL DEFAULT '', submitter VARCHAR(190) NOT NULL DEFAULT '', action VARCHAR(10) NOT NULL DEFAULT '',
            reason VARCHAR(160) NOT NULL DEFAULT '', ticket_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_jf_sub (form_id, submission_id), INDEX idx_jf_ticket (ticket_id), INDEX idx_jf_created (created_at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    function itops_jf_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_jf_submissions LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    /** The forms, kept five minutes, with who owns each (matched to the team members read) and which make tickets. */
    function itops_jf_forms(PDO $db, bool $fresh = false): array {
        require_once __DIR__ . '/SafeCache.php';
        $f = itops_cache_dir('jotform') . '/forms.json'; $j = null;
        if (!$fresh && ($raw = itops_cache_read($f)) !== null) { $x = json_decode($raw, true); if (is_array($x) && time() - (int)($x['at'] ?? 0) < 300) $j = $x; }
        if ($j === null) { $rows = itops_jf_client($db)->forms(); $j = ['at' => time(), 'rows' => $rows]; itops_cache_write($f, json_encode($j)); }
        // owner username → the person (the team members read from Jotform carry their username as the account id)
        $who = [];
        try { $q = $db->prepare("SELECT ext_id, email, person_name, account FROM itops_people_links WHERE system_key='jotform' AND status='active' AND ext_id IS NOT NULL"); $q->execute();
              foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $who[mb_strtolower((string)$r['ext_id'])] = ['email' => itops_conn_is_placeholder((string)$r['email']) ? '' : (string)$r['email'], 'name' => (string)($r['person_name'] ?? '')]; } catch (Throwable $e) {}
        $cfg = itops_jf_cfg($db);
        foreach ($j['rows'] as &$r) { $o = $who[mb_strtolower($r['owner'])] ?? null; $r['owner_email'] = $o['email'] ?? ''; $r['owner_name'] = $o['name'] ?? ''; $r['tickets'] = !empty($cfg['forms'][$r['id']]['on']); }
        unset($r);
        usort($j['rows'], fn($a, $b) => [$a['tickets'] ? 0 : 1, mb_strtolower($a['title'])] <=> [$b['tickets'] ? 0 : 1, mb_strtolower($b['title'])]);
        return ['rows' => $j['rows'], 'read_at' => date('Y-m-d H:i:s', (int)$j['at'])];
    }

    /** The forms a person owns (by their Jotform username), for the offboarding step. null = could not be read. */
    function itops_jf_forms_of(PDO $db, array $usernames): ?array {
        if (!$usernames) return [];
        try { $all = itops_jf_forms($db)['rows']; } catch (Throwable $e) { return null; }
        $u = array_flip(array_map('mb_strtolower', $usernames));
        return array_values(array_map(fn($f) => ['id' => $f['id'], 'title' => $f['title'], 'status' => $f['status'], 'count' => $f['count']], array_filter($all, fn($f) => isset($u[mb_strtolower($f['owner'])]))));
    }

    /** One answer as text, or null for parts of a form that are not answers (headings, buttons, pictures). */
    function itops_jf_answer(array $a): ?string {
        $type = (string)($a['type'] ?? '');
        if (in_array($type, ['control_head', 'control_button', 'control_text', 'control_divider', 'control_image', 'control_collapse', 'control_pagebreak', 'control_captcha', 'control_widget_html'], true)) return null;
        if (!array_key_exists('answer', $a)) return null;
        if ($type === 'control_signature') return $a['answer'] ? '(signed in Jotform)' : '';
        if ($type === 'control_fileupload') { $n = []; foreach ((array)$a['answer'] as $x) if (is_string($x)) $n[] = basename(parse_url($x, PHP_URL_PATH) ?: $x); return $n ? implode(', ', $n) . ' (file in Jotform)' : ''; }
        $p = $a['prettyFormat'] ?? null;
        if (is_string($p) && trim($p) !== '') return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $p)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $v = $a['answer'];
        if (is_array($v)) { $flat = []; array_walk_recursive($v, function ($x, $k) use (&$flat) { if (is_scalar($x) && trim((string)$x) !== '') $flat[] = (is_string($k) && !ctype_digit($k) ? $k . ': ' : '') . trim((string)$x); }); return implode(', ', $flat); }
        return is_scalar($v) ? trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string)$v)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';   // rich-text answers: the text, not the HTML
    }

    /**
     * The check (the scheduled job every ten minutes, or "Check now"): for each form switched on, the submissions above
     * its baseline become tickets. Returns counts.
     */
    function itops_jf_poll(PDO $db, ?int $now = null): array {
        $now = $now ?? time(); $cfg = itops_jf_cfg($db);
        $res = ['at' => date('Y-m-d H:i:s', $now), 'forms' => 0, 'read' => 0, 'tickets' => 0, 'skipped' => 0, 'errors' => []];
        if (!itops_jf_any_on($cfg)) return $res + ['off' => true];
        $sys = itops_sys_get($db, 'jotform');
        if (!$sys || $sys['mode'] !== 'connected') { $res['errors'][] = 'Jotform is not set to Connected'; return $res; }
        itops_jf_table($db);
        try { $cl = itops_jf_client($db); } catch (Throwable $e) { $res['errors'][] = $e->getMessage(); return $res; }
        require_once __DIR__ . '/PhoneTickets.php';   // itops_ph_requester: who opens a ticket nobody in the app sent
        $ins = $db->prepare("INSERT IGNORE INTO itops_jf_submissions (form_id, submission_id, form_title, submitted_at, submitter, action, reason, created_at) VALUES (?,?,?,?,?,'','',NOW())");
        foreach ($cfg['forms'] as $fid => $fc) {
            if (empty($fc['on'])) continue;
            $res['forms']++;
            try { $subs = $cl->submissions((string)$fid, (string)$fc['baseline'], 500); }
            catch (Throwable $e) { $res['errors'][] = ($fc['title'] ?: 'form ' . $fid) . ': ' . $e->getMessage(); continue; }
            $req = itops_ph_requester($db, (int)$fc['by']);
            foreach ($subs as $s) {
                $sid = ItopsJotform::txt($s['id'] ?? ''); if ($sid === '') continue;
                $res['read']++;
                $answers = []; $email = ''; $name = '';
                foreach ((array)($s['answers'] ?? []) as $a) {
                    if (!is_array($a)) continue;
                    $txt = itops_jf_answer($a); if ($txt === null) continue;
                    $label = trim(strip_tags((string)($a['text'] ?? ($a['name'] ?? ''))));
                    $type = (string)($a['type'] ?? '');
                    if ($type === 'control_email' && $email === '' && filter_var($txt, FILTER_VALIDATE_EMAIL)) $email = mb_strtolower($txt);
                    if ($type === 'control_fullname' && $name === '') $name = mb_substr(preg_replace('/\s+/', ' ', $txt), 0, 160);
                    $answers[] = [$label !== '' ? $label : 'Answer', $txt];
                }
                $who = $name !== '' && $email !== '' ? $name . ' (' . $email . ')' : ($name !== '' ? $name : ($email !== '' ? $email : 'someone who gave no name'));
                $ins->execute([(string)$fid, $sid, mb_substr((string)$fc['title'], 0, 190), mb_substr(ItopsJotform::txt($s['created_at'] ?? ''), 0, 25), mb_substr($who, 0, 190)]);
                $isNew = $ins->rowCount() > 0;
                if ($isNew) $rowId = (int)$db->lastInsertId();
                // the baseline moves on as each submission is handled, so the next check starts after it
                $cfg2 = itops_jf_cfg($db); if (isset($cfg2['forms'][$fid]) && ItopsJotform::cmp($sid, (string)$cfg2['forms'][$fid]['baseline']) > 0) { $cfg2['forms'][$fid]['baseline'] = $sid; itops_jf_save($db, $cfg2); }
                if (!$isNew) continue;
                $mark = function (string $act, string $why, ?int $tid) use ($db, $rowId) { $db->prepare('UPDATE itops_jf_submissions SET action=?, reason=?, ticket_id=? WHERE id=?')->execute([$act, mb_substr($why, 0, 160), $tid, $rowId]); };
                if (strtoupper(ItopsJotform::txt($s['status'] ?? '')) === 'DELETED') { $mark('skipped', 'deleted in Jotform', null); $res['skipped']++; continue; }
                $rid = 0; $known = false;
                if ($email !== '') { $u = $db->prepare("SELECT id FROM itops_users WHERE LOWER(email)=? AND status='active' LIMIT 1"); $u->execute([$email]); $rid = (int)$u->fetchColumn(); $known = $rid > 0; }
                if (!$rid) $rid = $req;
                if (!$rid) { $mark('skipped', 'no active Super Admin to open the ticket', null); $res['skipped']++; continue; }
                $title = (string)($fc['title'] ?: 'Jotform form ' . $fid);
                $subj = mb_substr($title . ': ' . $who, 0, 250);
                $body = 'Sent on the Jotform form "' . $title . '"' . (ItopsJotform::txt($s['created_at'] ?? '') !== '' ? ' on ' . ItopsJotform::txt($s['created_at']) . ' (Jotform\'s time)' : '') . ".\n\n";
                foreach ($answers as [$q, $v]) $body .= $q . ': ' . ($v !== '' ? $v : '—') . "\n";
                $body .= "\nIn Jotform: " . $cl->web() . '/inbox/' . $fid . '/' . $sid . "\n\nThis ticket was made by the forms check; each submission makes one ticket.";
                $body = mb_substr($body, 0, 20000);
                $cat = (int)$fc['category_id']; $pri = in_array($fc['priority'], ['p1_critical', 'p2_high', 'p3_medium', 'p4_low'], true) ? $fc['priority'] : 'p3_medium';
                $type = 'request'; try { require_once __DIR__ . '/ServiceMgmt.php'; $type = itops_svc_type_for($db, $cat ?: null); } catch (Throwable $e) {}
                require_once __DIR__ . '/TicketNumbers.php';
                try {
                    [$tn, $tid] = itops_ticket_create_numbered($db, function (string $tn) use ($db, $subj, $body, $cat, $pri, $type, $rid, $known, $name, $email) {
                        $db->prepare("INSERT INTO itops_tickets (ticket_number,subject,description,category_id,priority,status,ticket_type,requester_id,contact_email,contact_name) VALUES (?,?,?,?,?,'open',?,?,?,?)")
                           ->execute([$tn, $subj, $body, $cat ?: null, $pri, $type, $rid, $known || $email === '' ? null : $email, $known ? null : ($name !== '' ? $name : null)]);
                        return [$tn, (int)$db->lastInsertId()];
                    });
                } catch (Throwable $e) { $mark('skipped', 'the ticket could not be made: ' . $e->getMessage(), null); $res['skipped']++; continue; }
                if (!function_exists('itops_ticket_sla_fill')) require_once __DIR__ . '/BusinessHours.php';
                try { itops_ticket_sla_fill($db, $tid); } catch (Throwable $e) {}
                try { require_once __DIR__ . '/TicketRouting.php'; itops_tr_on_created($db, $tid, null, 'jotform'); } catch (Throwable $e) {}
                $mark('ticket', $known ? 'sent by a person of the app' : '', $tid); $res['tickets']++;
                if (function_exists('audit_log')) audit_log('ticket_created', 'ticket', $tid, $tn, null, ['From' => 'Jotform form', 'Form' => $title, 'Submission' => $sid, 'Sent by' => $who]);
            }
        }
        $c = itops_jf_cfg($db); $c['last_poll'] = $now; $c['last_result'] = $res; itops_jf_save($db, $c);
        return $res;
    }

    /** What a screen may see: the forms switched on, and the submissions looked at lately. */
    function itops_jf_public(PDO $db, int $recent = 40): array {
        $c = itops_jf_cfg($db); $rows = [];
        if (itops_jf_ready($db)) {
            $q = $db->query('SELECT s.*, t.ticket_number, t.status AS ticket_status FROM itops_jf_submissions s LEFT JOIN itops_tickets t ON t.id=s.ticket_id ORDER BY s.id DESC LIMIT ' . max(1, min(200, $recent)));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = ['form_id' => (string)$r['form_id'], 'form' => (string)$r['form_title'], 'submission' => (string)$r['submission_id'], 'at' => (string)$r['submitted_at'],
                'who' => (string)$r['submitter'], 'action' => (string)$r['action'], 'reason' => (string)$r['reason'], 'ticket_id' => $r['ticket_id'] !== null ? (int)$r['ticket_id'] : null,
                'ticket_number' => (string)($r['ticket_number'] ?? ''), 'ticket_status' => (string)($r['ticket_status'] ?? '')];
        }
        $forms = [];
        foreach ($c['forms'] as $id => $f) {
            $by = ''; if ((int)$f['by']) { try { $q = $db->prepare('SELECT display_name FROM itops_users WHERE id=?'); $q->execute([(int)$f['by']]); $by = (string)$q->fetchColumn(); } catch (Throwable $e) {} }
            $forms[] = ['id' => (string)$id, 'on' => (bool)$f['on'], 'title' => (string)$f['title'], 'category_id' => (int)$f['category_id'], 'priority' => (string)$f['priority'], 'on_since' => $f['on_since'], 'by_name' => $by];
        }
        return ['forms' => $forms, 'last_poll' => $c['last_poll'] ? date('Y-m-d H:i:s', (int)$c['last_poll']) : '', 'last_result' => $c['last_result'], 'recent' => $rows, 'ready' => itops_jf_ready($db)];
    }
}
