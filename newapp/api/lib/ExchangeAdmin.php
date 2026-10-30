<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT113 (Microsoft 365 redesign, phase 2 — Email): Microsoft's Exchange Online Admin API (preview, REST, no PowerShell).
 *
 * Same app registration as Graph (tenant, client, secret), its own token for Exchange:
 *   permission  Office 365 Exchange Online → Application → Exchange.ManageAsAppV2 (admin consent)
 *   role        the app's service principal in the Exchange role group "Recipient Management"
 *               (or the Entra role "Exchange Administrator")
 * Every call is POST https://outlook.office365.com/adminapi/v2.0/<tenant>/<Endpoint> with
 *   {"CmdletInput":{"CmdletName":"<cmdlet>","Parameters":{…}}} and the header X-AnchorMailbox.
 * Only the endpoints and cmdlets Microsoft offers there are allowed (OPSH_EXO_ALLOWED).
 *
 * Message trace is Graph v1.0 (admin/exchange/tracing/messageTraces, ExchangeMessageTrace.Read.All).
 */
require_once __DIR__ . '/Graph.php';

if (!function_exists('opsh_exo_allowed')) {
    function opsh_exo_allowed(): array {
        return [
            'Mailbox'                        => ['Get-Mailbox', 'Set-Mailbox'],
            'MailboxFolderPermission'        => ['Get-MailboxFolderPermission', 'Add-MailboxFolderPermission', 'Set-MailboxFolderPermission', 'Remove-MailboxFolderPermission'],
            'DistributionGroupMember'        => ['Get-DistributionGroupMember'],
            'DynamicDistributionGroupMember' => ['Get-DynamicDistributionGroupMember'],
            'OrganizationConfig'             => ['Get-OrganizationConfig'],
            'AcceptedDomain'                 => ['Get-AcceptedDomain'],
        ];
    }

    /** The token for Exchange (scope outlook.office365.com), cached apart from the Graph token, sealed like it. */
    function opsh_exo_token(bool $force = false): string {
        $c = opsh_graph_cfg();
        if ($c['tenant'] === '') throw new RuntimeException('Microsoft 365 is not configured.');
        $file = preg_replace('/\.json$/', '_exo.json', opsh_graph_token_file());
        if (!$force && is_file($file)) {
            $j = json_decode((string)@file_get_contents($file), true);
            if (is_array($j) && ($j['exp'] ?? 0) > time() + 120 && !empty($j['tok'])) { $t0 = opsh_graph_tok_open((string)$j['tok']); if ($t0 !== '') return $t0; }
        }
        $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($c['tenant']) . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POSTFIELDS => http_build_query(['client_id' => $c['client'], 'client_secret' => $c['secret'], 'grant_type' => 'client_credentials', 'scope' => 'https://outlook.office365.com/.default']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $d = json_decode((string)$resp, true);
        if ($code !== 200 || empty($d['access_token'])) throw new RuntimeException('Exchange token error: ' . ($d['error_description'] ?? ('HTTP ' . $code)));
        @file_put_contents($file, json_encode(['tok' => opsh_graph_tok_seal((string)$d['access_token']), 'exp' => time() + (int)($d['expires_in'] ?? 3600)]), LOCK_EX);
        @chmod($file, 0600);
        return (string)$d['access_token'];
    }

    /** One HTTP call to the Exchange Admin API. [code, json]. Tests replace this function (it is only defined when missing). */
    if (!function_exists('opsh_exo_http')) {
        function opsh_exo_http(string $url, array $body, string $anchor, bool $retry = true): array {
            $h = strtolower((string)parse_url($url, PHP_URL_HOST));
            if ($h !== 'outlook.office365.com') throw new RuntimeException('Not an Exchange Online address.');
            $tok = opsh_exo_token(!$retry);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json', 'Accept: application/json', 'X-AnchorMailbox: ' . $anchor, 'User-Agent: OpsHub-Exchange/1.0']]);
            $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
            if ($resp === false) throw new RuntimeException('Exchange network error: ' . ($cerr ?: 'no response'));
            if ($code === 401 && $retry) return opsh_exo_http($url, $body, $anchor, false);   // a newly consented permission: one fresh token
            // Review M365A-14: throttled (429 = not carried out) → wait and ask again, twice at most
            static $exoWaits = 0;
            if ($code === 429 && $exoWaits < 2) { $exoWaits++; sleep(2 * $exoWaits); return opsh_exo_http($url, $body, $anchor, $retry); }
            return [$code, json_decode((string)$resp, true)];
        }
    }

    /** The routing header: the mailbox itself for mailbox calls, else the system mailbox (app-only). */
    function opsh_exo_anchor(string $mailbox = ''): string {
        $t = opsh_graph_cfg()['tenant'];
        if ($mailbox !== '' && strpos($mailbox, '@') !== false) return 'AAD-UPN:' . $mailbox;
        return 'APP:SystemMailbox{bb558c35-97f1-4cb9-8ff7-d53741dc928c}@' . $t;
    }

    /**
     * Run one allowed cmdlet. Returns the rows (Get-*: every page followed, at most $maxPages) or [] for a change.
     * Errors name what is missing: the permission (401), the Exchange role (403), or the preview not being on (404).
     */
    function opsh_exo_call(string $endpoint, string $cmdlet, array $params = [], string $mailbox = '', array $select = [], int $maxPages = 10): array {
        $al = opsh_exo_allowed();
        if (!isset($al[$endpoint]) || !in_array($cmdlet, $al[$endpoint], true)) throw new InvalidArgumentException('That Exchange command is not allowed here.');
        $t = opsh_graph_cfg()['tenant'];
        if (!preg_match('/^[A-Za-z0-9.\-]{3,100}$/', $t)) throw new RuntimeException('Microsoft 365 is not configured.');
        $url = 'https://outlook.office365.com/adminapi/v2.0/' . rawurlencode($t) . '/' . $endpoint . ($select ? '?$select=' . rawurlencode(implode(',', $select)) : '');
        $body = ['CmdletInput' => ['CmdletName' => $cmdlet] + ($params ? ['Parameters' => $params] : [])];
        $anchor = opsh_exo_anchor($mailbox);
        $rows = []; $page = 0;
        while ($url !== '' && $page++ < $maxPages) {
            [$code, $j] = opsh_exo_http($url, $body, $anchor);
            $msg = is_array($j) ? (string)($j['error']['message'] ?? $j['error']['details'][0]['message'] ?? '') : '';
            if ($code === 401) throw new RuntimeException('Exchange refused the app (HTTP 401): it needs the permission Exchange.ManageAsAppV2 (Office 365 Exchange Online, application) with admin consent.');
            if ($code === 403) throw new RuntimeException('Exchange refused (HTTP 403): the app needs an Exchange role — add its service principal to "Recipient Management" (or give it Exchange Administrator).' . ($msg !== '' ? ' ' . $msg : ''));
            if ($code === 404) throw new RuntimeException('Exchange did not find this (HTTP 404). The Exchange Admin API is in preview and may not be on for this tenant yet.' . ($msg !== '' ? ' ' . $msg : ''));
            if ($code >= 400) throw new RuntimeException('Exchange error (HTTP ' . $code . ')' . ($msg !== '' ? ': ' . $msg : '.'));
            if (!is_array($j)) return $rows;
            if (isset($j['value']) && is_array($j['value'])) foreach ($j['value'] as $r) $rows[] = $r;
            elseif ($j && strpos($cmdlet, 'Get-') === 0 && !isset($j['@odata.context'])) $rows[] = $j;
            $url = (string)($j['@odata.nextLink'] ?? '');
            if ($url !== '' && strtolower((string)parse_url($url, PHP_URL_HOST)) !== 'outlook.office365.com') $url = '';
        }
        return $rows;
    }

    /** Mailbox kinds Exchange names, in plain words. */
    function opsh_exo_kind(string $t): string {
        return ['UserMailbox' => 'User', 'SharedMailbox' => 'Shared', 'RoomMailbox' => 'Room', 'EquipmentMailbox' => 'Equipment',
                'DiscoveryMailbox' => 'Discovery', 'SchedulingMailbox' => 'Scheduling', 'GroupMailbox' => 'Group'][$t] ?? ($t !== '' ? $t : 'Other');
    }

    /** Every mailbox with its kind, addresses and "send on behalf" delegates. */
    function opsh_exo_mailboxes(): array {
        $rows = opsh_exo_call('Mailbox', 'Get-Mailbox', ['ResultSize' => 'Unlimited'], '',
            ['DisplayName', 'UserPrincipalName', 'PrimarySmtpAddress', 'RecipientTypeDetails', 'EmailAddresses', 'GrantSendOnBehalfTo', 'ExternalDirectoryObjectId', 'Alias'], 20);
        $out = [];
        foreach ($rows as $r) {
            $smtp = array_values(array_filter(array_map(fn($a) => preg_match('/^smtp:(.+)$/i', (string)$a, $m) ? strtolower($m[1]) : null, (array)($r['EmailAddresses'] ?? []))));
            $primary = strtolower((string)($r['PrimarySmtpAddress'] ?? ''));
            $out[] = ['name' => (string)($r['DisplayName'] ?? ''), 'upn' => strtolower((string)($r['UserPrincipalName'] ?? '')), 'address' => $primary,
                'aliases' => array_values(array_filter($smtp, fn($a) => $a !== $primary)), 'kind' => opsh_exo_kind((string)($r['RecipientTypeDetails'] ?? '')),
                'delegates' => array_values(array_map('strval', (array)($r['GrantSendOnBehalfTo'] ?? []))), 'oid' => (string)($r['ExternalDirectoryObjectId'] ?? ''),
                'alias' => (string)($r['Alias'] ?? '')];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** One mailbox: delegates with names, and who may see its calendar. */
    function opsh_exo_mailbox(string $id): array {
        $mb = opsh_exo_call('Mailbox', 'Get-Mailbox', ['Identity' => $id, 'IncludeGrantSendOnBehalfToWithDisplayNames' => true], $id);
        $m = $mb[0] ?? [];
        $dl = [];
        foreach ((array)($m['GrantSendOnBehalfToWithDisplayNames'] ?? []) as $x) $dl[] = is_array($x) ? ['name' => (string)($x['DisplayName'] ?? $x['Name'] ?? ''), 'id' => (string)($x['PrimarySmtpAddress'] ?? $x['Identity'] ?? '')] : ['name' => (string)$x, 'id' => (string)$x];
        if (!$dl) foreach ((array)($m['GrantSendOnBehalfTo'] ?? []) as $x) $dl[] = ['name' => (string)$x, 'id' => (string)$x];
        $cal = []; $calErr = '';
        try {
            foreach (opsh_exo_call('MailboxFolderPermission', 'Get-MailboxFolderPermission', ['Identity' => $id . ':\\Calendar'], $id) as $p) {
                $u = $p['User'] ?? ''; $uName = is_array($u) ? (string)($u['DisplayName'] ?? '') : (string)$u;
                $cal[] = ['user' => $uName, 'rights' => array_values(array_map('strval', (array)($p['AccessRights'] ?? []))), 'flags' => array_values(array_map('strval', (array)($p['SharingPermissionFlags'] ?? [])))];
            }
        } catch (Throwable $e) { $calErr = $e->getMessage(); }
        return ['name' => (string)($m['DisplayName'] ?? $id), 'address' => strtolower((string)($m['PrimarySmtpAddress'] ?? $id)), 'kind' => opsh_exo_kind((string)($m['RecipientTypeDetails'] ?? '')),
                'delegates' => $dl, 'calendar' => $cal, 'calendar_error' => $calErr];
    }

    /** Add or remove "send on behalf" delegates (Set-Mailbox GrantSendOnBehalfTo @{add/remove}). */
    function opsh_exo_set_delegates(string $id, array $add, array $remove): void {
        $ok = fn($a) => (bool)filter_var($a, FILTER_VALIDATE_EMAIL);
        $add = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $add)), $ok)));
        $remove = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $remove)), $ok)));
        if (!$add && !$remove) throw new InvalidArgumentException('Name at least one person by email address.');
        $g = []; if ($add) $g['add'] = $add; if ($remove) $g['remove'] = $remove;
        opsh_exo_call('Mailbox', 'Set-Mailbox', ['Identity' => $id, 'GrantSendOnBehalfTo' => $g], $id);
    }

    /** Calendar access roles OpsHub offers (Exchange names). */
    function opsh_exo_cal_roles(): array {
        return ['AvailabilityOnly' => 'Free/busy only', 'LimitedDetails' => 'Free/busy, subject and location', 'Reviewer' => 'Read everything',
                'Author' => 'Read and create, change own', 'Editor' => 'Read, create and change everything', 'Owner' => 'Full control (also share)', 'None' => 'No access'];
    }

    /** Give, change or take away someone's access to a mailbox's calendar. */
    function opsh_exo_set_calendar(string $id, string $user, string $role, string $op): void {
        if (!in_array($op, ['add', 'set', 'remove'], true)) throw new InvalidArgumentException('Unknown change.');
        if ($user !== 'Default' && $user !== 'Anonymous' && !filter_var($user, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Name the person by email address.');
        $p = ['Identity' => $id . ':\\Calendar', 'User' => $user];
        if ($op !== 'remove') { if (!isset(opsh_exo_cal_roles()[$role])) throw new InvalidArgumentException('Unknown calendar access.'); $p['AccessRights'] = [$role]; }
        opsh_exo_call('MailboxFolderPermission', ['add' => 'Add-MailboxFolderPermission', 'set' => 'Set-MailboxFolderPermission', 'remove' => 'Remove-MailboxFolderPermission'][$op], $p, $id);
    }

    /** Members of a distribution list (read only: Exchange offers no change through this API yet). */
    function opsh_exo_dl_members(string $id): array {
        $out = [];
        foreach (opsh_exo_call('DistributionGroupMember', 'Get-DistributionGroupMember', ['Identity' => $id, 'ResultSize' => 'Unlimited'], '', ['DisplayName', 'PrimarySmtpAddress', 'RecipientTypeDetails']) as $r)
            $out[] = ['name' => (string)($r['DisplayName'] ?? ''), 'address' => strtolower((string)($r['PrimarySmtpAddress'] ?? '')), 'kind' => (string)($r['RecipientTypeDetails'] ?? '')];
        return $out;
    }

    /**
     * Message trace (Graph v1.0): messages received in a window of at most 10 days within the last 90, optionally for one
     * sender or recipient, or a subject word. Microsoft filters by date, recipient and subject; the sender is matched here.
     */
    function opsh_exo_trace(string $from, string $to, string $sender = '', string $recipient = '', string $subject = ''): array {
        $a = strtotime($from); $b = strtotime($to);
        if (!$a || !$b || $b < $a) throw new InvalidArgumentException('Choose a start and an end date.');
        if ($b > time() + 60) $b = time();
        if ($b - $a > 10 * 86400) throw new InvalidArgumentException('Microsoft searches at most 10 days at a time.');
        if ($a < time() - 90 * 86400) throw new InvalidArgumentException('Microsoft keeps message traces for 90 days.');
        foreach ([$sender, $recipient] as $x) if ($x !== '' && !filter_var($x, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('An address is not a valid email address.');
        $f = 'receivedDateTime ge ' . gmdate('Y-m-d\TH:i:s\Z', $a) . ' and receivedDateTime le ' . gmdate('Y-m-d\TH:i:s\Z', $b);
        if ($recipient !== '') $f .= " and recipientAddress eq '" . str_replace("'", "''", strtolower($recipient)) . "'";
        if ($subject !== '') $f .= " and contains(subject, '" . str_replace("'", "''", mb_substr($subject, 0, 100)) . "')";
        $j = opsh_graph_get('admin/exchange/tracing/messageTraces?$top=1000&$filter=' . rawurlencode($f));
        $out = [];
        foreach (($j['value'] ?? []) as $m) {
            if ($sender !== '' && strtolower((string)($m['senderAddress'] ?? '')) !== strtolower($sender)) continue;
            $out[] = ['id' => (string)($m['id'] ?? ''), 'received' => (string)($m['receivedDateTime'] ?? ''), 'sender' => (string)($m['senderAddress'] ?? ''),
                'recipient' => (string)($m['recipientAddress'] ?? ''), 'subject' => (string)($m['subject'] ?? ''), 'status' => (string)($m['status'] ?? ''),
                'size' => (int)($m['size'] ?? 0), 'from_ip' => (string)($m['fromIP'] ?? '')];
        }
        return ['rows' => $out, 'more' => !empty($j['@odata.nextLink'])];
    }
}
