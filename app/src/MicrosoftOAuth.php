<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Microsoft 365 modern authentication for outbound email.
 *
 *   1. The platform operator registers ONE Azure app (Entra ID → App registrations):
 *        - Redirect URI: {app_url}/oauth/microsoft/callback
 *        - API permissions (delegated): Mail.Send, User.Read, offline_access
 *        - Supported account types: any organization ("multitenant") so each client's M365 works
 *      and stores client id + secret in Console → Email.
 *   2. The platform admin (for the platform sender) or a tenant Master Admin (for that tenant)
 *      clicks "Connect Microsoft 365", signs in with the mailbox that should send, consents, and the
 *      refresh token is stored encrypted against that scope.
 *   3. Sending mints a short-lived access token from the refresh token and calls Graph
 *      POST /me/sendMail. No password is ever stored; nothing uses SMTP AUTH.
 *
 * Tokens never leave the server; the state parameter is bound to the session and the scope.
 */
final class MicrosoftOAuth
{
    private const AUTH  = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    public const SCOPES = 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read';

    // ---- app registration (platform-wide) ----------------------------------------

    public static function clientId(): string     { return self::setting('ms_client_id'); }
    public static function clientSecret(): string { $v = self::setting('ms_client_secret'); return $v !== '' ? (Crypto::decrypt(base64_decode($v, true) ?: '') ?: '') : ''; }
    public static function configured(): bool     { return self::clientId() !== '' && self::clientSecret() !== ''; }
    public static function redirectUri(): string  { return flx_public_base_safe() . '/oauth/microsoft/callback'; }

    /**
     * Save the app registration. $expires (YYYY-MM-DD) is the client secret's expiry date as shown in Entra; the console warns
     * before it passes. A new secret saved without a date clears the stored date (the old date no longer applies).
     */
    public static function saveApp(string $clientId, string $clientSecret, ?string $expires = null): void
    {
        self::setSetting('ms_client_id', trim($clientId));
        $expires = trim((string) $expires);
        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) && checkdate((int) substr($expires, 5, 2), (int) substr($expires, 8, 2), (int) substr($expires, 0, 4));
        if (trim($clientSecret) !== '') {
            self::setSetting('ms_client_secret', base64_encode((string) Crypto::encrypt(trim($clientSecret))));
            self::setSetting('ms_secret_expires', $validDate ? $expires : '');
        } elseif ($expires === '' || $validDate) {
            self::setSetting('ms_secret_expires', $validDate ? $expires : '');
        }
    }

    public static function secretExpires(): string { return self::setting('ms_secret_expires'); }

    /** Whole days until the client secret expires (negative = already expired), or null when no date is stored / not configured. */
    public static function secretDaysLeft(?int $now = null): ?int
    {
        $d = self::secretExpires();
        if ($d === '' || !self::configured()) return null;
        $exp = \DateTimeImmutable::createFromFormat('!Y-m-d', $d, new \DateTimeZone('UTC'));
        if (!$exp) return null;
        $today = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        return (int) $today->diff($exp)->format('%r%a');
    }

    /** Console banner: null when nothing to say, else ['level' => 'info'|'error', 'text' => ...]. Starts 60 days before expiry. */
    public static function secretNotice(?int $now = null): ?array
    {
        $n = self::secretDaysLeft($now);
        if ($n === null || $n > 60) return null;
        if ($n < 0)  return ['level' => 'error', 'days' => $n, 'text' => 'The Microsoft 365 client secret expired on ' . self::secretExpires() . '. E-mail sent through Microsoft 365 is failing. Create a new secret in Microsoft Entra and save it under Email → App registration.'];
        if ($n <= 14) return ['level' => 'error', 'days' => $n, 'text' => 'The Microsoft 365 client secret expires in ' . $n . ' day' . ($n === 1 ? '' : 's') . ' (' . self::secretExpires() . '). Create a new secret in Microsoft Entra and save it under Email → App registration before then.'];
        return ['level' => 'info', 'days' => $n, 'text' => 'The Microsoft 365 client secret expires in ' . $n . ' days (' . self::secretExpires() . '). Plan to create a new one in Microsoft Entra and save it under Email → App registration.'];
    }

    // ---- authorization flow -----------------------------------------------------

    /** Build the sign-in URL. $scope is 'platform' or a tenant id; bound into state. */
    public static function authorizeUrl(string $scope): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['ms_oauth_state'] = ['state' => $state, 'scope' => $scope, 'at' => time()];
        $q = http_build_query([
            'client_id' => self::clientId(), 'response_type' => 'code', 'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query', 'scope' => self::SCOPES, 'state' => $state, 'prompt' => 'select_account',
        ]);
        return self::AUTH . '?' . $q;
    }

    /** Handle the callback: exchange code → tokens, look up the mailbox, store the refresh token. */
    public static function handleCallback(string $code, string $state): array
    {
        $s = $_SESSION['ms_oauth_state'] ?? null;
        unset($_SESSION['ms_oauth_state']);
        if (!$s || !hash_equals((string)$s['state'], $state) || (time() - (int)$s['at']) > 600) return [false, 'Sign-in session expired or invalid — try again.', null];
        $tok = self::post(self::TOKEN, [
            'client_id' => self::clientId(), 'client_secret' => self::clientSecret(), 'grant_type' => 'authorization_code',
            'code' => $code, 'redirect_uri' => self::redirectUri(), 'scope' => self::SCOPES,
        ]);
        if (empty($tok['refresh_token']) || empty($tok['access_token'])) return [false, self::explainToken($tok), null];
        $me = self::get(self::GRAPH . '/me', $tok['access_token']);
        $account = (string)($me['mail'] ?? $me['userPrincipalName'] ?? '');
        if ($account === '') return [false, 'Could not read the connected mailbox address.', null];
        $enc = base64_encode((string) Crypto::encrypt((string)$tok['refresh_token']));
        if ($s['scope'] === 'platform') {
            self::setSetting('ms_platform_account', $account);
            self::setSetting('ms_platform_refresh', $enc);
            self::setSetting('ms_platform_connected_at', utc_now());
        } else {
            Database::pdo()->prepare(
                "INSERT INTO tenant_mail_settings (id, tenant_id, transport, oauth_provider, oauth_account, oauth_refresh_enc, oauth_connected_at, from_email, created_at, updated_at)
                 VALUES (?,?,'graph','microsoft',?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE transport='graph', oauth_provider='microsoft', oauth_account=VALUES(oauth_account), oauth_refresh_enc=VALUES(oauth_refresh_enc),
                   oauth_connected_at=VALUES(oauth_connected_at), from_email=VALUES(from_email), verified_at=NULL, last_error=NULL, updated_at=VALUES(updated_at)"
            )->execute([uuid4(), $s['scope'], $account, $enc, utc_now(), $account, utc_now(), utc_now()]);
        }
        return [true, t('Connected {account} — messages will send through Microsoft 365.', ['account' => $account]), $account];
    }

    public static function disconnect(string $scope): void
    {
        if ($scope === 'platform') { foreach (['ms_platform_account','ms_platform_refresh','ms_platform_connected_at'] as $k) self::setSetting($k, ''); return; }
        Database::pdo()->prepare("UPDATE tenant_mail_settings SET transport='smtp', oauth_provider=NULL, oauth_account=NULL, oauth_refresh_enc=NULL, oauth_connected_at=NULL WHERE tenant_id=?")->execute([$scope]);
    }

    public static function platformAccount(): string { return self::setting('ms_platform_account'); }

    // ---- sending via Graph ------------------------------------------------------

    /** Test hook: fn(string $method, string $url, array $headers, string $body): array{0:int,1:string,2:string} = [HTTP status, body, network error]. Null = real cURL. */
    public static $transport = null;

    /**
     * Send one message through Microsoft Graph as the connected mailbox. $refreshEnc is the stored encrypted refresh token;
     * $scope ('platform' or a tenant id) says where to save the NEW refresh token Microsoft returns on every refresh.
     * Returns [ok, error]; the error is plain language and never contains a token.
     */
    public static function sendMail(string $refreshEnc, string $fromName, string $to, string $toName, string $subject, string $body, ?array $attach = null, ?string $scope = null): array
    {
        $refresh = Crypto::decrypt(base64_decode($refreshEnc, true) ?: '') ?: '';
        if ($refresh === '') return [false, 'Microsoft 365 is not connected. Open Email settings and choose Connect Microsoft 365.'];
        $tok = self::post(self::TOKEN, [
            'client_id' => self::clientId(), 'client_secret' => self::clientSecret(), 'grant_type' => 'refresh_token',
            'refresh_token' => $refresh, 'scope' => self::SCOPES,
        ]);
        if (empty($tok['access_token'])) return [false, self::explainToken($tok)];
        // Microsoft rotates the refresh token on use. Keep the new one, or the connection silently dies about 90 days after it was made.
        if ($scope !== null && !empty($tok['refresh_token']) && $tok['refresh_token'] !== $refresh) {
            try { self::saveRefresh($scope, base64_encode((string) Crypto::encrypt((string) $tok['refresh_token']))); } catch (\Throwable $e) { /* sending still works with the old one until it expires */ }
        }
        $msg = ['message' => [
            'subject' => preg_replace('/[\r\n]+/', ' ', $subject),
            'body' => ['contentType' => 'Text', 'content' => $body],
            'toRecipients' => [['emailAddress' => ['address' => $to, 'name' => $toName ?: $to]]],
        ], 'saveToSentItems' => true];
        if ($attach) {
            $msg['message']['attachments'] = [['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $attach['name'], 'contentType' => $attach['mime'], 'contentBytes' => base64_encode($attach['bytes'])]];
        }
        [$code, $resp, $neterr] = self::postJson(self::GRAPH . '/me/sendMail', $tok['access_token'], $msg);
        if ($code === 202) return [true, null];
        return [false, self::explainGraph($code, $resp, $neterr)];
    }

    /** Plain-language reason for a failed token request. */
    public static function explainToken(array $tok): string
    {
        $err = (string) ($tok['error'] ?? '');
        $desc = (string) ($tok['error_description'] ?? '');
        if ($err === 'network') return 'Could not reach Microsoft (login.microsoftonline.com). Check that the server can make outbound HTTPS requests.';
        if ($err === 'invalid_grant' || stripos($desc, 'AADSTS70000') !== false || stripos($desc, 'AADSTS700082') !== false) return 'Microsoft no longer accepts the saved sign-in (it expired, was revoked, or the password changed). Choose Connect Microsoft 365 again.';
        if ($err === 'invalid_client' || stripos($desc, 'AADSTS7000215') !== false || stripos($desc, 'AADSTS7000222') !== false) return 'Microsoft rejected the app registration: the client secret is wrong or has expired. Create a new client secret in Entra and save it under App registration.';
        if (stripos($desc, 'AADSTS65001') !== false) return 'The mailbox owner or a Microsoft 365 administrator has not consented to this app. Connect again and accept the permissions (an administrator may need to grant consent).';
        return 'Microsoft 365 sign-in failed: ' . mb_substr(preg_replace('/\s+/', ' ', $desc !== '' ? $desc : ($err !== '' ? $err : 'unknown error')), 0, 180) . ' Reconnect the mailbox.';
    }

    /** Plain-language reason for a failed sendMail call. */
    public static function explainGraph(int $code, array $resp, string $neterr = ''): string
    {
        if ($code === 0) return 'Could not reach Microsoft Graph (graph.microsoft.com). Check that the server can make outbound HTTPS requests.';
        $gcode = (string) ($resp['error']['code'] ?? '');
        $gmsg  = mb_substr(preg_replace('/\s+/', ' ', (string) ($resp['error']['message'] ?? '')), 0, 160);
        if ($code === 401) return 'Microsoft rejected the access token. Choose Connect Microsoft 365 again.';
        if ($code === 403 || $gcode === 'ErrorAccessDenied') return 'Microsoft refused to send. The app needs the Mail.Send permission and the mailbox needs an Exchange Online licence; a Microsoft 365 administrator may have to grant consent. (' . ($gcode ?: 'HTTP 403') . ')';
        if ($code === 400 && stripos($gcode . $gmsg, 'MailboxNotEnabledForRESTAPI') !== false) return 'This mailbox has no Exchange Online mailbox (or is not licensed for one), so Microsoft cannot send mail as it.';
        if ($code === 429 || $code === 503) return 'Microsoft asked us to slow down (HTTP ' . $code . '). The message stays queued and will be retried.';
        return 'Microsoft Graph refused the message (HTTP ' . $code . ($gcode !== '' ? ', ' . $gcode : '') . ($gmsg !== '' ? ': ' . $gmsg : '') . ').';
    }

    /** Store a rotated refresh token against the platform sender or a tenant. */
    private static function saveRefresh(string $scope, string $enc): void
    {
        if ($scope === 'platform') { self::setSetting('ms_platform_refresh', $enc); return; }
        Database::pdo()->prepare("UPDATE tenant_mail_settings SET oauth_refresh_enc = ?, updated_at = ? WHERE tenant_id = ? AND transport = 'graph'")->execute([$enc, utc_now(), $scope]);
    }

    // ---- http + settings helpers -----------------------------------------------

    /** One HTTP call. Returns [status, body, networkError]. */
    private static function http(string $method, string $url, array $headers, string $body): array
    {
        if (is_callable(self::$transport)) return (array) (self::$transport)($method, $url, $headers, $body);
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers] + ($method === 'POST' ? [CURLOPT_POSTFIELDS => $body] : []));
        $b = \curl_exec($ch); $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = (string) \curl_error($ch); \curl_close($ch);
        return [$code, is_string($b) ? $b : '', is_string($b) ? '' : ($err !== '' ? $err : 'network')];
    }
    private static function post(string $url, array $form): array
    {
        [$code, $b, $neterr] = self::http('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($form));
        if ($neterr !== '' || $b === '') return ['error' => 'network'];
        return json_decode($b, true) ?: ['error' => 'bad_response'];
    }
    private static function get(string $url, string $bearer): array
    {
        [, $b] = self::http('GET', $url, ['Authorization: Bearer ' . $bearer], '');
        return json_decode($b, true) ?: [];
    }
    private static function postJson(string $url, string $bearer, array $json): array
    {
        [$code, $b, $neterr] = self::http('POST', $url, ['Authorization: Bearer ' . $bearer, 'Content-Type: application/json'], (string) json_encode($json));
        return [$code, $b !== '' ? (json_decode($b, true) ?: []) : [], $neterr];
    }
    private static function setting(string $k): string
    {
        try { $st = Database::pdo()->prepare('SELECT sval FROM platform_settings WHERE skey = ?'); $st->execute([$k]); return (string)($st->fetchColumn() ?: ''); }
        catch (\Throwable $e) { return ''; }
    }
    private static function setSetting(string $k, string $v): void
    {
        Database::pdo()->prepare('INSERT INTO platform_settings (skey, sval, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_at=VALUES(updated_at)')->execute([$k, $v, utc_now()]);
    }
    public static function platformRefreshEnc(): string { return self::setting('ms_platform_refresh'); }
}
