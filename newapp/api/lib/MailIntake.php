<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
require_once __DIR__ . '/Brand.php';   // BT20: opsh_app_name() for cron scripts too
/** cron-mail.php runs without api/index.php: same rule as opsh_can(user,'tickets','all'). */
if (!function_exists('mail_can_all_tickets')) {
    function mail_can_all_tickets(array $u): bool {
        if (($u['role_slug'] ?? '') === 'super_admin') return true;
        $p = json_decode((string)($u['role_perms'] ?? ''), true); if (!is_array($p)) return false;
        $w = $p['*'] ?? null; if ($w && $w !== '0' && $w !== [] ) return true;
        $v = $p['tickets'] ?? null;
        if (is_array($v)) return in_array('all', $v, true);
        return in_array($v, ['crud','dept_manage','full','read_all','all',true], true);
    }
}
/**
 * OpsHub — Email-to-Ticket intake library
 * Inbound: IMAP (basic auth mailboxes) or Microsoft 365 Graph (modern auth).
 * Outbound: PHP mail(), SMTP, or Microsoft 365 Graph.
 * Configuration lives in opsh_settings (set in the admin area).
 */
require_once __DIR__ . '/O365.php';
require_once __DIR__ . '/EmailTemplates.php';

if (!function_exists('mail_cfg')) {
    function mail_cfg(): array {
        $rows = getDB()->query("SELECT skey, sval FROM opsh_settings WHERE skey LIKE 'mail_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    }
}

if (!function_exists('mail_mailbox_string')) {
    function mail_mailbox_string(array $cfg, ?string $folder = null): string {
        $enc = $cfg['mail_encryption'] ?? 'ssl';
        $flags = '/imap';
        if ($enc === 'ssl')      $flags .= '/ssl';
        elseif ($enc === 'tls')  $flags .= '/tls';
        else                     $flags .= '/notls';
        $port = $cfg['mail_port'] ?? ($enc === 'none' ? 143 : 993);
        $host = $cfg['mail_host'] ?? '';
        $f    = $folder ?? ($cfg['mail_folder'] ?? 'INBOX');
        return '{' . $host . ':' . $port . $flags . '}' . $f;
    }
}

if (!function_exists('mail_intake_test')) {
    function mail_intake_test(array $cfg): array {
        // Honour the selected inbound method. Microsoft 365 mailboxes must use Graph:
        // Exchange Online no longer accepts Basic Auth for IMAP/POP, so the IMAP path
        // below can never authenticate against outlook.office365.com.
        if (($cfg['mail_in_method'] ?? 'imap') === 'graph') {
            require_once __DIR__ . '/O365.php';
            if (!function_exists('graph_test')) return ['ok' => false, 'error' => 'Microsoft 365 (Graph) support is not available on this server.'];
            $r = graph_test();   // verifies the Azure app token + reads the configured mailbox
            if (!empty($r['ok'])) {
                if (!empty($r['note'])) return ['ok' => true, 'via' => 'graph', 'note' => $r['note']];
                return ['ok' => true, 'via' => 'graph', 'mailbox' => $r['mailbox'] ?? '', 'note' => 'Microsoft 365 inbox reachable via Graph' . (!empty($r['mailbox']) ? ' (' . $r['mailbox'] . ')' : '') . '.'];
            }
            return ['ok' => false, 'via' => 'graph', 'error' => 'Microsoft 365 (Graph): ' . ($r['error'] ?? 'connection failed') . ' — set the tenant, client ID, client secret and mailbox in the Microsoft 365 card below, and grant the app Mail.ReadWrite / Mail.Send application permissions with admin consent.'];
        }
        // ---- IMAP path (self-hosted / basic-auth mailboxes only) ----
        if (!function_exists('imap_open')) return ['ok' => false, 'error' => 'The PHP IMAP extension is not installed on this server. (For Microsoft 365 use the Graph inbound method instead.)'];
        if (empty($cfg['mail_host']) || empty($cfg['mail_username'])) return ['ok' => false, 'error' => 'Host and username are required.'];
        $pwd = !empty($cfg['_password_plain']) ? $cfg['_password_plain']
             : (!empty($cfg['mail_password_enc']) ? vault_decrypt($cfg['mail_password_enc']) : '');
        if ($pwd === '') return ['ok' => false, 'error' => 'No password set. Enter the mailbox password and try again.'];
        $imap = @imap_open(mail_mailbox_string($cfg), $cfg['mail_username'], $pwd, 0, 1);
        if (!$imap) {
            $err = imap_last_error() ?: 'Could not connect — check host, port, encryption, and credentials.';
            $host = strtolower($cfg['mail_host'] ?? '');
            if (strpos($host, 'office365') !== false || strpos($host, 'outlook') !== false) {
                $err .= ' — Microsoft 365 no longer allows IMAP with a password (Basic Auth is disabled). Set "Inbound source" to Microsoft 365 (Graph) instead.';
            }
            return ['ok' => false, 'error' => $err];
        }
        $n = imap_num_msg($imap);
        imap_close($imap);
        return ['ok' => true, 'messages' => $n];
    }
}

if (!function_exists('mail_get_body')) {
    /**
     * BT124 (code review DESK-11): the text of a message, walking every MIME part (attachments and alternative parts
     * included): the first text/plain part, else the first text/html part made plain; decoded by the part's own encoding and
     * converted to UTF-8 from its charset. Read without marking the message as seen (FT_PEEK).
     */
    function mail_get_body($imap, $num): string {
        $struct = imap_fetchstructure($imap, $num);
        $decode = function ($data, $enc) {
            if ($enc == 3) return base64_decode($data);
            if ($enc == 4) return quoted_printable_decode($data);
            return $data;
        };
        $charset = function ($part): string { foreach (array_merge((array)($part->parameters ?? []), (array)($part->dparameters ?? [])) as $p) if (strtolower((string)($p->attribute ?? '')) === 'charset') return (string)$p->value; return ''; };
        $utf8 = function (string $t, string $cs): string {
            if ($cs !== '' && strtolower($cs) !== 'utf-8' && strtolower($cs) !== 'us-ascii') { $c = @mb_convert_encoding($t, 'UTF-8', $cs); if (is_string($c)) return $c; }
            return mb_check_encoding($t, 'UTF-8') ? $t : (string)@mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
        };
        $found = ['PLAIN' => null, 'HTML' => null];
        $walk = function ($part, string $sec) use (&$walk, &$found, $imap, $num, $decode, $charset, $utf8) {
            if (!empty($part->parts)) { foreach ($part->parts as $k => $p) $walk($p, ($sec === '' ? '' : $sec . '.') . ($k + 1)); return; }
            $type = (int)($part->type ?? 0); $sub = strtoupper((string)($part->subtype ?? ''));
            $isAttach = strtolower((string)($part->disposition ?? '')) === 'attachment';
            if ($type !== 0 || $isAttach || !isset($found[$sub]) || $found[$sub] !== null) return;
            $raw = $sec === '' ? imap_body($imap, $num, FT_PEEK) : imap_fetchbody($imap, $num, $sec, FT_PEEK);
            $found[$sub] = $utf8($decode((string)$raw, $part->encoding ?? 0), $charset($part));
        };
        $walk($struct, '');
        $body = $found['PLAIN'] ?? '';
        if (trim($body) === '' && $found['HTML'] !== null) $body = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/tr)[^>]*>#i', "\n", preg_replace('#<(style|script)[^>]*>.*?</\1>#is', '', $found['HTML']))), ENT_QUOTES, 'UTF-8');
        return trim(mb_substr((string)$body, 0, 8000));
    }
}

if (!function_exists('mail_auth_verdict')) {
    /**
     * BT87 (code audit H-4): what the receiving mail server said about the sender, from its Authentication-Results (and
     * Microsoft's ARC-Authentication-Results / compauth). 'fail' — DMARC failed (or Microsoft's composite check failed, or
     * SPF and DKIM both failed with nothing passing): the From address is not trusted; 'pass' — DMARC, DKIM or SPF passed;
     * 'none' — the server said nothing (mail inside the same organization), the From address is trusted as before.
     */
    function mail_auth_verdict(string $headers, string $fromEmail = ''): string {
        // BT124 (code review DESK-5): only the top-most Authentication-Results — the one the receiving server added; lines
        // further down can be written by the sender. SPF or DKIM passing counts only for the From address's own domain.
        $ar = null; $arc = null;
        $un = preg_replace("/\r?\n[ \t]+/", ' ', $headers);   // unfold
        foreach (preg_split("/\r?\n/", (string)$un) as $h) {
            if ($ar === null && preg_match('/^Authentication-Results\s*:(.*)$/i', $h, $m)) $ar = strtolower($m[1]);
            elseif ($arc === null && preg_match('/^ARC-Authentication-Results\s*:(.*)$/i', $h, $m)) $arc = strtolower($m[1]);
        }
        $all = $ar ?? $arc;
        if ($all === null) return 'none';
        if (preg_match('/\bdmarc\s*=\s*(fail|permerror)\b/', $all) || preg_match('/\bcompauth\s*=\s*fail\b/', $all)) return 'fail';
        if (preg_match('/\bdmarc\s*=\s*pass\b/', $all) || preg_match('/\bcompauth\s*=\s*pass\b/', $all)) return 'pass';
        $dom = strtolower((string)substr((string)strrchr($fromEmail, '@'), 1));
        $aligned = function (string $mech) use ($all, $dom): bool {
            if ($dom === '' || !preg_match('/\b' . $mech . '\s*=\s*pass\b([^;]*)/', $all, $m)) return false;
            return (bool)preg_match('/(?:header\.d|header\.i|smtp\.mailfrom)\s*=\s*(?:[^\s;@]*@)?' . preg_quote($dom, '/') . '\b/', $m[1]);
        };
        if ($aligned('dkim') || $aligned('spf')) return 'pass';
        if (preg_match('/\bspf\s*=\s*(fail|softfail)\b/', $all) && !preg_match('/\bdkim\s*=\s*pass\b/', $all)) return 'fail';
        return 'none';
    }
    /**
     * BT124 (code review DESK-6): an automatic message (out-of-office, another help desk, a list): Auto-Submitted other than
     * "no", Precedence bulk / junk / list / auto_reply, X-Auto-Response-Suppress, X-Autoreply / X-Autorespond, or an empty
     * Return-Path.
     */
    function mail_is_automatic(string $headers): bool {
        $un = preg_replace("/\r?\n[ \t]+/", ' ', $headers);
        foreach (preg_split("/\r?\n/", (string)$un) as $h) {
            if (preg_match('/^Auto-Submitted\s*:\s*(\S+)/i', $h, $m) && strtolower($m[1]) !== 'no') return true;
            if (preg_match('/^Precedence\s*:\s*(bulk|junk|list|auto_reply)\b/i', $h)) return true;
            if (preg_match('/^(X-Auto-Response-Suppress|X-Autoreply|X-Autorespond)\s*:/i', $h)) return true;
            if (preg_match('/^Return-Path\s*:\s*<\s*>/i', $h)) return true;
        }
        return false;
    }
    /** Graph gives the headers as [{name, value}]. */
    function mail_auth_headers_from_graph(array $h): string {
        $o = ''; foreach ($h as $x) if (is_array($x) && isset($x['name'])) $o .= $x['name'] . ': ' . (string)($x['value'] ?? '') . "\r\n";
        return $o;
    }
}

if (!function_exists('mail_ingest_message')) {
    /**
     * Turn one inbound message into a ticket, or thread it onto an existing
     * ticket if the subject carries a TK-#### number. Shared by IMAP + Graph.
     * Returns 'created' | 'appended' | ''.
     */
    function mail_ingest_message($db, array $cfg, string $subject, string $fromEmail, string $fromName, string $bodyText, string $auth = 'none', bool $auto = false): string {
        $replyMode = $cfg['mail_reply_mode'] ?? 'thread';
        $spoof = $auth === 'fail';   // BT87 (code audit H-4): the sender's domain says this mail is not from that address
        $rid = null;
        $self = array_filter(array_map('strtolower', [(string)($cfg['mail_from_email'] ?? ''), (string)($cfg['mail_username'] ?? '')]));
        try { if (function_exists('o365_cfg')) { $mb = strtolower((string)(o365_cfg()['mailbox'] ?? '')); if ($mb) $self[] = $mb; } } catch (Throwable $e) {}
        $fl = strtolower((string)$fromEmail);
        if ($fl !== '' && (in_array($fl, $self, true) || preg_match('/^(mailer-daemon|postmaster|no-?reply)@/i', $fl))) return 'skipped';
        if ($fromEmail && !$spoof) {
            $u = $db->prepare("SELECT id FROM opsh_users WHERE email=? AND status='active' LIMIT 1");   // review DESK-21: an inactive account is an outsider
            $u->execute([$fromEmail]);
            $rid = $u->fetchColumn() ?: null;
        }
        $known = (bool)$rid;
        if (!$rid) $rid = 1; // system/admin fallback — the real sender is kept in contact_email

        if ($replyMode === 'thread' && preg_match('/TK-\d{4}-\d{3,}/', $subject, $m)) {
            $t = $db->prepare('SELECT id FROM opsh_tickets WHERE ticket_number=?');
            $t->execute([$m[0]]);
            $tid = $t->fetchColumn();
            // BT75: a reply to a merged ticket goes to the ticket it was merged into
            for ($hop = 0; $tid && $hop < 5; $hop++) {
                try { $mq = $db->prepare('SELECT merged_into_id FROM opsh_tickets WHERE id=?'); $mq->execute([(int)$tid]); $nx = (int)($mq->fetchColumn() ?: 0); } catch (Throwable $e) { $nx = 0; }
                if (!$nx) break; $tid = $nx;
            }
            if ($tid) {
                // Only people connected to the ticket (its requester / contact / assignee) or OpsHub users can add
                // a public reply. Anyone else quoting the number (TK numbers are sequential) is kept as an
                // INTERNAL note for the team to review, so strangers can't post into someone else's ticket.
                // OpsHub users count only if they're on the ticket or work the queue (Tickets "All records").
                $ok = false;
                if ($spoof || $auto) { /* failed sender checks, or an automatic reply: never a public reply, never reopens — kept as an internal note */ }
                elseif ($known) { try { $ku = $db->prepare('SELECT u.*, r.slug AS role_slug, r.permissions AS role_perms FROM opsh_users u LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE u.id=?'); $ku->execute([(int)$rid]); $kr = $ku->fetch(); if ($kr) $ok = function_exists('opsh_can') ? opsh_can($kr, 'tickets', 'all') : mail_can_all_tickets($kr); } catch (Throwable $e) {} }
                if (!$ok && !$spoof) {
                    $cq = $db->prepare('SELECT LOWER(COALESCE(t.contact_email,\'\')) ce, LOWER(COALESCE(r.email,\'\')) re, LOWER(COALESCE(a.email,\'\')) ae, LOWER(COALESCE(b.email,\'\')) be FROM opsh_tickets t LEFT JOIN opsh_users r ON r.id=t.requester_id LEFT JOIN opsh_users a ON a.id=t.assignee_id LEFT JOIN opsh_users b ON b.id=t.backup_id WHERE t.id=?');
                    try { $cq->execute([$tid]); $cr = $cq->fetch() ?: []; $ok = in_array($fl, array_filter([$cr['ce'] ?? '', $cr['re'] ?? '', $cr['ae'] ?? '', $cr['be'] ?? '']), true);   /* review DESK-21: the backup too */ } catch (Throwable $e) { $ok = false; }
                }
                $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,?)')
                   ->execute([$tid, $rid, ($ok ? "(email reply from {$fromEmail})" : ($spoof ? "(email claiming to be from {$fromEmail} FAILED the sender checks (DMARC) — it may be forged; review before acting)" : "(email from UNRECOGNISED sender {$fromEmail} quoting this ticket number — review before acting)")) . "\n\n" . $bodyText, $ok ? 0 : 1]);
                if ($spoof && function_exists('audit_log')) { try { audit_log('mail_spoof_suspected', 'ticket', (int)$tid, 'From ' . mb_substr($fromEmail, 0, 120), null, ['result' => 'kept as an internal note', 'subject' => mb_substr($subject, 0, 200)]); } catch (Throwable $e) {} }
                if ($ok) {   // let the agent know (bell + "Requester replied" email)
                    if (!function_exists('opsh_ticket_notify_assignee')) require_once __DIR__ . '/Requesters.php';
                    opsh_ticket_notify_assignee($db, (int)$tid, $known ? (int)$rid : 0, ($fromName && $fromName !== $fromEmail) ? (string)$fromName : (string)$fromEmail, (string)$bodyText);
                    try { require_once __DIR__ . '/TicketLifecycle.php'; opsh_tk_on_requester_reply($db, (int)$tid, $known ? (int)$rid : null, (string)$fromEmail); } catch (Throwable $e) {}   // BT75 reopen; BT92 ends Pending
                }
                return 'appended';
            }
        }

        $clean = trim(preg_replace('/^((re|res|rv|fwd?|reenv)\s*:\s*)+/i', '', $subject)) ?: '(no subject)';
        $desc = ($spoof ? "WARNING: this email FAILED the sender checks (DMARC) — the From address may be forged. It is kept as from an unknown sender.\n" : '') . "From: {$fromName} <{$fromEmail}>\n\n" . $bodyText;
        require_once __DIR__ . '/TicketNumbers.php';   // BT52: a number nobody else holds
        [$tn, $newTicketId] = opsh_ticket_create_numbered($db, function (string $tn) use ($db, $clean, $desc, $cfg, $rid) {
            $db->prepare('INSERT INTO opsh_tickets (ticket_number,subject,description,category_id,department_id,priority,status,requester_id) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$tn, $clean, $desc, (($cfg['mail_default_category'] ?? '') ?: null), (($cfg['mail_default_department'] ?? '') ?: null), (($cfg['mail_default_priority'] ?? '') ?: 'p3_medium'), 'open', $rid]);
            return [$tn, (int)$db->lastInsertId()];
        });
        if (!function_exists('opsh_ticket_sla_fill')) require_once __DIR__ . '/BusinessHours.php';
        opsh_ticket_sla_fill($db, $newTicketId);
        if ($spoof) { try { $db->prepare("UPDATE opsh_tickets SET contact_email='', contact_name=? WHERE id=?")->execute([mb_substr('Unverified sender (failed DMARC): ' . $fromEmail, 0, 160), $newTicketId]); } catch (Throwable $e) {} }   // BT87: no email goes back to a forged address
        else if (!$known && $fromEmail) { try { $db->prepare('UPDATE opsh_tickets SET contact_email=?, contact_name=? WHERE id=?')->execute([strtolower($fromEmail), mb_substr((string)$fromName, 0, 160), $newTicketId]); } catch (Throwable $e) {} }
        // BT17: owner + backup from the category routing, and tell them (or the alert list) — a new ticket is never silent.
        try { require_once __DIR__ . '/TicketRouting.php'; opsh_tr_on_created($db, $newTicketId, null, 'email'); } catch (Throwable $e) {}
        if ($spoof && function_exists('audit_log')) { try { audit_log('mail_spoof_suspected', 'ticket', (int)$newTicketId, $tn . ' · from ' . mb_substr($fromEmail, 0, 120), null, ['result' => 'new ticket from an unknown sender, no acknowledgement sent']); } catch (Throwable $e) {} }
        // BT124 (review DESK-6): no acknowledgement to an automatic message, nor more than five an hour to one sender (loops)
        $ackN = 0; try { $aq = $db->prepare('SELECT COUNT(*) FROM opsh_tickets WHERE contact_email=? AND created_at > NOW() - INTERVAL 1 HOUR'); $aq->execute([strtolower($fromEmail)]); $ackN = (int)$aq->fetchColumn(); } catch (Throwable $e) {}
        if ($fromEmail && !$spoof && !$auto && $ackN <= 5) {   // opsh_email() honours the "mail_ack" switch and the template's own on/off (BT87: nothing back to a forged address)
            $newId = $newTicketId;
            opsh_email('ticket_ack_email', $fromEmail, ['ticket_number' => $tn, 'subject' => $clean, 'requester_email' => $fromEmail,
                'recipient_name' => ($fromName && $fromName !== $fromEmail) ? $fromName : ''], ['ticket_id' => $newId ?: null]);
        }
        return 'created';
    }
}

if (!function_exists('mail_intake_poll')) {
    function mail_intake_poll(): array {
        $cfg = mail_cfg();
        if (($cfg['mail_enabled'] ?? '0') !== '1') return ['ok' => false, 'error' => 'Email intake is disabled.'];
        // BT124 (review DESK-4): one reader of the mailbox at a time (the system cron and the in-app scheduler both poll)
        $db0 = getDB();
        if ((int)$db0->query("SELECT GET_LOCK('opsh_mail_poll', 0)")->fetchColumn() !== 1) return ['ok' => true, 'skipped' => 'another mail check is running', 'scanned' => 0, 'created' => 0, 'appended' => 0];
        try { return mail_intake_poll_locked($cfg); } finally { try { $db0->query("SELECT RELEASE_LOCK('opsh_mail_poll')"); } catch (\Throwable $e) {} }
    }
    function mail_intake_poll_locked(array $cfg): array {

        // Microsoft 365 (Graph) inbound
        if (($cfg['mail_in_method'] ?? 'imap') === 'graph') {
            return graph_poll();
        }

        // IMAP inbound
        if (!function_exists('imap_open')) return ['ok' => false, 'error' => 'The PHP IMAP extension is not installed on this server.'];
        $pwd = !empty($cfg['mail_password_enc']) ? vault_decrypt($cfg['mail_password_enc']) : '';
        $imap = @imap_open(mail_mailbox_string($cfg), $cfg['mail_username'] ?? '', $pwd, 0, 1);
        if (!$imap) return ['ok' => false, 'error' => imap_last_error() ?: 'Could not connect to the mailbox.'];

        $db = getDB();
        $ids = imap_search($imap, 'UNSEEN') ?: [];
        $created = 0; $appended = 0;
        foreach ($ids as $num) {
            $hdr = imap_headerinfo($imap, $num);
            $subject = isset($hdr->subject) ? imap_utf8($hdr->subject) : '(no subject)';
            $fromEmail = isset($hdr->from[0]) ? ($hdr->from[0]->mailbox . '@' . $hdr->from[0]->host) : '';
            $fromName = isset($hdr->from[0]->personal) ? imap_utf8($hdr->from[0]->personal) : $fromEmail;
            $bodyText = mail_get_body($imap, $num);
            $hdrRaw = (string)@imap_fetchheader($imap, $num);
            $auth = mail_auth_verdict($hdrRaw, $fromEmail);   // BT87
            // BT124 (review DESK-11): one message that fails is left unread for the next check; the others go on
            try { $res = mail_ingest_message($db, $cfg, $subject, $fromEmail, $fromName, $bodyText, $auth, mail_is_automatic($hdrRaw)); }
            catch (\Throwable $e) { if (function_exists('opsh_log')) { try { opsh_log('error', 'mail', 'An email could not be taken in: ' . $e->getMessage()); } catch (\Throwable $x) {} } continue; }
            if ($res === 'created') $created++; elseif ($res === 'appended') $appended++;
            @imap_setflag_full($imap, $num, "\\Seen");
        }
        imap_close($imap);
        if (function_exists('audit_log') && ($created || $appended)) audit_log('mail_poll', 'mail', 0, "created {$created}, appended {$appended}");   // review DESK-17: only when something happened
        return ['ok' => true, 'scanned' => count($ids), 'created' => $created, 'appended' => $appended];
    }
}

if (!function_exists('mail_send')) {
    /** Outbound email via the configured method: graph | smtp | mailfn. */
    function mail_send(string $to, string $subject, string $body, array $ctx = []): array {
        require_once __DIR__ . '/Brand.php'; $subject = opsh_brand($subject);   // BT20: the log shows the subject as sent
        $r = mail_send_raw($to, $subject, $body, $ctx['html'] ?? null);
        // Every attempt is recorded (Connection → Email → Outbound email log) so "no email arrived"
        // can be diagnosed without guessing.
        try {
            getDB()->prepare('INSERT INTO opsh_mail_log (to_email,subject,method,kind,ticket_id,ok,error,sent_by) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$to, mb_substr($subject, 0, 255), (string)(mail_cfg()['mail_out_method'] ?? 'mailfn'), (string)($ctx['kind'] ?? ''),
                              isset($ctx['ticket_id']) ? (int)$ctx['ticket_id'] : null, !empty($r['ok']) ? 1 : 0, mb_substr((string)($r['error'] ?? ''), 0, 1000),
                              (int)($_SERVER['OPSH_USER_ID'] ?? 0) ?: null]);
        } catch (Throwable $e) { /* log table missing until db-update runs — never block the send */ }
        return $r;
    }
}

if (!function_exists('mail_send_raw')) {
    /** MIME body: plain text only, or multipart/alternative (text + HTML). Base64 parts: safe for
     *  UTF-8/Spanish text, long lines and SMTP dot-stuffing. Returns [content-type header, body]. */
    function mail_mime(string $text, ?string $html): array {
        $b64 = fn($s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
        if (!$html) return ["Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64", $b64($text)];
        $bd = '=_opsh_' . bin2hex(random_bytes(8));
        $body = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($text) . "\r\n"
              . "--$bd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n--$bd--";
        return ["Content-Type: multipart/alternative; boundary=\"$bd\"", $body];
    }
    /** RFC 2047 header encoding for non-ASCII subjects / names (accents, ñ, em-dashes). */
    function mail_hdr(string $s): string {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }
    function mail_send_raw(string $to, string $subject, string $body, ?string $html = null): array {
        // BT20: every outgoing email carries the client's app name (also covers templates saved before BT20).
        require_once __DIR__ . '/Brand.php';
        $subject = opsh_brand($subject); $body = opsh_brand($body); if ($html !== null) $html = opsh_brand($html);
        $cfg = mail_cfg();
        if (($cfg['mail_out_enabled'] ?? '0') !== '1') return ['ok' => false, 'error' => 'Outbound email is disabled (Connection → Email → Outbound email)'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid recipient'];
        $method = $cfg['mail_out_method'] ?? 'mailfn';

        if ($method === 'graph') {
            return graph_send_mail($to, $subject, $body, $html);
        }

        $fromEmail = ($cfg['mail_from_email'] ?? '') ?: ($cfg['mail_username'] ?? '');
        $fromName  = opsh_brand(($cfg['mail_from_name'] ?? '') ?: 'IT Help Desk');   // BT20
        if (!$fromEmail) return ['ok' => false, 'error' => 'No From address configured'];

        if ($method === 'smtp') {
            return smtp_send($cfg, $fromEmail, $fromName, $to, $subject, $body, $html);
        }
        [$ctype, $mime] = mail_mime($body, $html);
        $headers  = 'From: ' . mail_hdr($fromName) . ' <' . $fromEmail . ">\r\n";
        $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n" . $ctype . "\r\n";
        $ok = @mail($to, mail_hdr($subject), $mime, $headers, '-f' . $fromEmail);
        return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'PHP mail() returned false (check server mail / try SMTP or Microsoft 365)'];
    }
}

if (!function_exists('smtp_send')) {
    function smtp_send(array $cfg, string $fromEmail, string $fromName, string $to, string $subject, string $body, ?string $html = null): array {
        $host = $cfg['mail_smtp_host'] ?? '';
        if (!$host) return ['ok' => false, 'error' => 'SMTP host not set'];
        $sec  = $cfg['mail_smtp_security'] ?? 'tls';
        $port = (int)(($cfg['mail_smtp_port'] ?? '') ?: ($sec === 'ssl' ? 465 : 587));
        $user = ($cfg['mail_smtp_user'] ?? '') ?: ($cfg['mail_username'] ?? '');
        $pass = !empty($cfg['mail_smtp_pass_enc']) ? vault_decrypt($cfg['mail_smtp_pass_enc'])
              : (!empty($cfg['mail_password_enc']) ? vault_decrypt($cfg['mail_password_enc']) : '');

        $transport = ($sec === 'ssl' ? 'ssl://' : '') . $host;
        $fp = @fsockopen($transport, $port, $errno, $errstr, 15);
        if (!$fp) return ['ok' => false, 'error' => "SMTP connect failed: {$errstr}"];
        stream_set_timeout($fp, 15);
        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 515)) { $data .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
            return $data;
        };
        $cmd = function ($c) use ($fp, $read) { fputs($fp, $c . "\r\n"); return $read(); };
        $ehlo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $read();
        $cmd($ehlo);
        if ($sec === 'tls') {
            $r = $cmd('STARTTLS');
            if (substr($r, 0, 3) !== '220') { fclose($fp); return ['ok' => false, 'error' => 'STARTTLS failed: ' . trim($r)]; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return ['ok' => false, 'error' => 'TLS negotiation failed']; }
            $cmd($ehlo);
        }
        if ($user) {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($user));
            $r = $cmd(base64_encode($pass));
            if (substr($r, 0, 3) !== '235') { fclose($fp); return ['ok' => false, 'error' => 'SMTP auth failed: ' . trim($r)]; }
        }
        $cmd('MAIL FROM:<' . $fromEmail . '>');
        $rc = $cmd('RCPT TO:<' . $to . '>');
        if (!in_array(substr($rc, 0, 3), ['250', '251'])) { fclose($fp); return ['ok' => false, 'error' => 'Recipient rejected: ' . trim($rc)]; }
        $cmd('DATA');
        [$ctype, $mime] = mail_mime($body, $html);
        $msg  = 'From: ' . mail_hdr($fromName) . ' <' . $fromEmail . ">\r\n";
        $msg .= 'To: <' . $to . ">\r\n";
        $msg .= 'Reply-To: ' . $fromEmail . "\r\n";
        $msg .= 'Subject: ' . mail_hdr($subject) . "\r\n";
        $msg .= 'Date: ' . date('r') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n" . $ctype . "\r\n\r\n";
        $msg .= $mime . "\r\n.";
        $r = $cmd($msg);
        $cmd('QUIT');
        fclose($fp);
        if (substr($r, 0, 3) !== '250') return ['ok' => false, 'error' => 'Message rejected: ' . trim($r)];
        return ['ok' => true, 'error' => ''];
    }
}
