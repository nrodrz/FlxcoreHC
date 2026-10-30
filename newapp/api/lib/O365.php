<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Microsoft 365 / Graph integration
 * Configuration lives in itops_settings (set in the admin area). One Azure AD
 * app registration powers both:
 *   • SSO sign-in (delegated, User.Read) — used by auth.php
 *   • Mailbox send/read (application, Mail.Send + Mail.ReadWrite) — used here
 * The client secret is stored encrypted via the same vault as other credentials.
 */

if (!function_exists('o365_cfg')) {
    function o365_cfg(): array {
        $rows = getDB()->query("SELECT skey,sval FROM itops_settings WHERE skey LIKE 'o365_%'")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return [
            'enabled'   => ($rows['o365_enabled'] ?? '0') === '1',
            // BT14: one app configuration shared with Graph (tenant keys kept in sync; one encrypted secret).
            'tenant'    => ($rows['o365_tenant'] ?? '') !== '' ? $rows['o365_tenant'] : ($rows['o365_tenant_id'] ?? ''),
            'client_id' => $rows['o365_client_id'] ?? '',
            'secret'    => !empty($rows['o365_client_secret_enc']) ? vault_decrypt($rows['o365_client_secret_enc']) : (string)($rows['o365_client_secret'] ?? ''),
            'redirect'  => $rows['o365_redirect'] ?? '',
            'mailbox'   => $rows['o365_mailbox'] ?? '',
        ];
    }
}

if (!function_exists('o365_enabled')) {
    function o365_enabled(): bool {
        $c = o365_cfg();
        return $c['enabled'] && $c['tenant'] && $c['client_id'] && $c['secret'];
    }
}

if (!function_exists('graph_token')) {
    /** App-only (client credentials) Graph token. Returns token string or null. */
    function graph_token(): ?string {
        static $tok = null;
        if ($tok) return $tok;
        if (!function_exists('curl_init')) return null;
        $c = o365_cfg();
        if (!$c['tenant'] || !$c['client_id'] || !$c['secret']) return null;
        $ch = curl_init("https://login.microsoftonline.com/{$c['tenant']}/oauth2/v2.0/token");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $c['client_id'],
                'client_secret' => $c['secret'],
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $r = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $tok = $r['access_token'] ?? null;
        return $tok;
    }
}

if (!function_exists('graph_call')) {
    /** Generic Graph request. Returns [http_code, decoded_body]. */
    function graph_call(string $method, string $url, string $token, $jsonBody = null): array {
        $ch = curl_init($url);
        $headers = ["Authorization: Bearer {$token}"];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 25];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp ? json_decode($resp, true) : null];
    }
}

if (!function_exists('graph_test')) {
    function graph_test(): array {
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP cURL extension not available'];
        $c = o365_cfg();
        if (!$c['tenant'] || !$c['client_id'] || !$c['secret']) return ['ok' => false, 'error' => 'Tenant, Client ID and Client secret are required'];
        $tok = graph_token();
        if (!$tok) return ['ok' => false, 'error' => 'Could not obtain a Graph token — check tenant, client ID and secret'];
        if (!$c['mailbox']) return ['ok' => true, 'note' => 'Token OK. Set a mailbox to enable send/receive.'];
        [$code, $body] = graph_call('GET', "https://graph.microsoft.com/v1.0/users/" . rawurlencode($c['mailbox']) . "?\$select=displayName,mail,userPrincipalName", $tok);
        if ($code === 200) return ['ok' => true, 'mailbox' => $body['mail'] ?? $body['userPrincipalName'] ?? $c['mailbox'], 'name' => $body['displayName'] ?? ''];
        return ['ok' => false, 'error' => "Token OK but mailbox check failed (HTTP {$code}): " . ($body['error']['message'] ?? 'check the mailbox address') . graph_mail_hint($code, 'Mail.ReadWrite')];
    }
}

if (!function_exists('graph_mail_hint')) {
    /** Turn a Graph HTTP status into an actionable hint for mail operations. */
    function graph_mail_hint(int $code, string $need): string {
        if ($code === 403) return " — Access denied by Microsoft. Most likely the Azure app is missing the \"{$need}\" APPLICATION permission (Type = Application, not Delegated) with admin consent granted, OR an Exchange Application Access Policy is scoping the app away from this mailbox. Fix: Azure Portal → App registrations → (your app) → API permissions → Add a permission → Microsoft Graph → Application permissions → add \"{$need}\", then click \"Grant admin consent for <tenant>\".";
        if ($code === 401) return " — Unauthorized: the client secret is wrong or expired. Create a new client secret in Azure and paste it into the Microsoft 365 card below.";
        if ($code === 404) return " — Mailbox not found: check the mailbox address in the Microsoft 365 card (it must be a real, licensed mailbox in this tenant).";
        return "";
    }
}

if (!function_exists('graph_send_mail')) {
    function graph_send_mail(string $to, string $subject, string $body, ?string $html = null): array {
        $c = o365_cfg();
        if (!$c['mailbox']) return ['ok' => false, 'error' => 'No Microsoft 365 mailbox configured'];
        $tok = graph_token();
        if (!$tok) return ['ok' => false, 'error' => 'Could not obtain a Graph token'];
        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => $html ? ['contentType' => 'HTML', 'content' => $html] : ['contentType' => 'Text', 'content' => $body],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
            'saveToSentItems' => true,
        ];
        [$code, $err] = graph_call('POST', "https://graph.microsoft.com/v1.0/users/" . rawurlencode($c['mailbox']) . "/sendMail", $tok, $payload);
        if ($code === 202) return ['ok' => true, 'error' => ''];
        return ['ok' => false, 'error' => "Graph sendMail HTTP {$code}: " . ($err['error']['message'] ?? 'send failed') . graph_mail_hint($code, 'Mail.Send')];
    }
}

