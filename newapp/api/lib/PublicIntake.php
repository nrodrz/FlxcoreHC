<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT87 (code audit H-3, M-13, M-14, M-18): what comes in from the public pages without signing in.
 *  - A request sent with the address of a person of the app is not filed in their name until they confirm it: the ticket
 *    keeps the address as an outside contact, marked unconfirmed, and the address gets a "Was this you?" email instead of
 *    the acknowledgement. Confirming (confirm.html, a button, never the link alone) files it in their name.
 *  - The acknowledgement repeats the subject without links or web addresses (a stranger cannot make the app mail a link
 *    to anyone).
 *  - Files from the public forms: the name and the type are the server's (the type is read from the file, the extension
 *    follows it); files nobody submitted are removed after 24 hours.
 */
if (!function_exists('opsh_pub_defang')) {
    /** The subject as plain words: web addresses and e-mail addresses become "[link removed]". */
    function opsh_pub_defang(string $s): string {
        $s = preg_replace('~\b(?:https?|ftp)://\S+|\bwww\.\S+~iu', '[link removed]', $s);
        $s = preg_replace('~\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b~u', '[address removed]', $s);
        $s = preg_replace('~\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,24})\b(?:/\S*)?~iu', '[link removed]', $s);
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, 150);
    }
    function opsh_pub_has_verify(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $h = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='opsh_tickets' AND COLUMN_NAME='verify_user_id'")->fetchColumn() === 1; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    /** The person of the app with this address (active or not), or 0. */
    function opsh_pub_user_for(PDO $db, string $email): int {
        if ($email === '') return 0;
        $u = $db->prepare('SELECT id FROM opsh_users WHERE LOWER(email)=LOWER(?) LIMIT 1'); $u->execute([$email]);
        return (int)($u->fetchColumn() ?: 0);
    }
    /**
     * After a public ticket is made under the fallback account: the address as an outside contact, and — when the address
     * belongs to a person of the app — marked unconfirmed with a token. Returns the token ('' when not needed).
     */
    function opsh_pub_mark(PDO $db, int $ticketId, string $email, string $name): string {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
        try { $db->prepare('UPDATE opsh_tickets SET contact_email=?, contact_name=? WHERE id=?')->execute([$email, mb_substr($name, 0, 160), $ticketId]); } catch (Throwable $e) {}
        $uid = opsh_pub_user_for($db, $email);
        if (!$uid || !opsh_pub_has_verify($db)) return '';
        $tok = bin2hex(random_bytes(24));
        $db->prepare('UPDATE opsh_tickets SET verify_user_id=?, verify_token=? WHERE id=?')->execute([$uid, hash('sha256', $tok), $ticketId]);
        return $tok;
    }
    function opsh_pub_confirm_url(string $tok): string { return (defined('APP_URL') ? rtrim((string)APP_URL, '/') : '') . '/confirm.html?t=' . $tok; }
    /** The acknowledgement, or the "Was this you?" email when the address belongs to a person of the app. */
    function opsh_pub_ack(PDO $db, int $ticketId, string $tn, string $subject, string $email, string $name, string $formName, string $tok): void {
        if ($email === '') return;
        @require_once __DIR__ . '/MailIntake.php';
        if (!function_exists('opsh_email')) return;
        try {
            if ($tok !== '') opsh_email('ticket_confirm_form', $email, ['ticket_number' => $tn, 'subject' => opsh_pub_defang($subject), 'confirm_link' => opsh_pub_confirm_url($tok), 'recipient_name' => $name, 'portal_link' => ''], ['ticket_id' => $ticketId]);
            else opsh_email('ticket_ack_form', $email, ['ticket_number' => $tn, 'subject' => opsh_pub_defang($subject), 'form_name' => $formName, 'recipient_name' => $name], ['ticket_id' => $ticketId]);
        } catch (Throwable $e) {}
    }
    /** Confirm (or, with $apply false, look up) a request by its token. Returns the ticket row or null. */
    function opsh_pub_confirm(PDO $db, string $tok, bool $apply): ?array {
        if (!opsh_pub_has_verify($db) || !preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
        $q = $db->prepare('SELECT id, ticket_number, subject, verify_user_id, contact_email, created_at FROM opsh_tickets WHERE verify_token=? AND created_at > NOW() - INTERVAL 30 DAY');
        $q->execute([hash('sha256', $tok)]); $t = $q->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        if ($apply) {
            $db->prepare('UPDATE opsh_tickets SET requester_id=?, contact_email=NULL, contact_name=NULL, verify_user_id=NULL, verify_token=NULL WHERE id=?')->execute([(int)$t['verify_user_id'], (int)$t['id']]);
            if (function_exists('audit_log')) audit_log('public_ticket_confirmed', 'ticket', (int)$t['id'], $t['ticket_number'], ['requester' => 'unconfirmed: ' . $t['contact_email']], ['requester' => 'user #' . (int)$t['verify_user_id'] . ' (confirmed from the email)']);
        }
        return $t;
    }
    /**
     * A file from a public form: the server's own type and size, the name with the extension of that type. Null when the
     * file is not there or is not a type the app takes.
     */
    function opsh_pub_form_file(string $bn, string $askedName): ?array {
        $bn = basename($bn); $full = UPLOAD_DIR . 'forms/' . $bn;
        if ($bn === '' || !preg_match('/^[a-f0-9]{28}\.[a-z0-9]{2,5}$/', $bn) || !is_file($full)) return null;
        $ext = strtolower(pathinfo($bn, PATHINFO_EXTENSION));
        $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $full) : 'application/octet-stream';
        $base = trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', pathinfo(basename(str_replace('\\', '/', $askedName)), PATHINFO_FILENAME)), ' ._');
        $base = mb_substr($base !== '' ? $base : 'file', 0, 120);
        return ['path' => 'forms/' . $bn, 'name' => $base . '.' . $ext, 'type' => $mime ?: 'application/octet-stream', 'size' => (int)filesize($full)];
    }
    /** Files in uploads/forms that no ticket and no form response points to, older than 24 hours, are removed. */
    function opsh_pub_uploads_clean(PDO $db): int {
        $dir = UPLOAD_DIR . 'forms/'; if (!is_dir($dir)) return 0;
        $n = 0; $cut = time() - 86400;
        foreach (glob($dir . '*') ?: [] as $f) {
            if (!is_file($f) || filemtime($f) > $cut || basename($f)[0] === '.') continue;
            $rel = 'forms/' . basename($f);
            $q = $db->prepare('SELECT COUNT(*) FROM opsh_ticket_attachments WHERE file_path=?'); $q->execute([$rel]);
            if ((int)$q->fetchColumn() > 0) continue;
            try { $q = $db->prepare('SELECT COUNT(*) FROM opsh_form_submissions WHERE data_json LIKE ?'); $q->execute(['%' . basename($f) . '%']); if ((int)$q->fetchColumn() > 0) continue; } catch (Throwable $e) { continue; }
            try { $q = $db->prepare('SELECT COUNT(*) FROM opsh_recycle WHERE data LIKE ?'); $q->execute(['%' . basename($f) . '%']); if ((int)$q->fetchColumn() > 0) continue; } catch (Throwable $e) {}   // BT141: kept in Deleted items
            if (@unlink($f)) $n++;
        }
        return $n;
    }
}
