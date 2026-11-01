<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Minimal transactional mailer. Sends via PHP mail() only when 'mail_enabled' is
 * true; otherwise it's a safe no-op returning false so callers degrade gracefully
 * (the on-screen confirmation + downloadable .ics still cover the invitee).
 * Supports an optional text/calendar (.ics) attachment via multipart/mixed.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $textBody, ?string $ics = null, string $icsName = 'appointment.ics'): bool
    {
        if (!config('mail_enabled', false)) return false;
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $from = (string) config('mail_from', 'no-reply@localhost');

        if ($ics === null) {
            $headers = 'From: ' . $from . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
            return @mail($to, $subject, $textBody, $headers);
        }
        $b = 'flx_' . bin2hex(random_bytes(8));
        $headers = "From: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$b}\"";
        $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$textBody}\r\n";
        $body .= "--{$b}\r\nContent-Type: text/calendar; method=PUBLISH; charset=UTF-8; name=\"{$icsName}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$icsName}\"\r\n\r\n";
        $body .= chunk_split(base64_encode($ics)) . "\r\n--{$b}--";
        return @mail($to, $subject, $body, $headers);
    }
}
