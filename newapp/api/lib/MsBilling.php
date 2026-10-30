<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT124: Microsoft invoices from Microsoft's billing API (Microsoft Customer Agreement).
 *
 * Same app registration as Microsoft 365 (tenant, client, secret), its own token for Azure Resource Manager
 * (scope https://management.azure.com/.default). No API permission to add: the app's service principal needs the billing
 * role "Billing profile reader" (or "Invoice manager") on the billing profile. Reads only:
 *   GET  /providers/Microsoft.Billing/billingAccounts                                  the billing accounts it can see
 *   GET  …/billingAccounts/{a}/invoices?periodStartDate&periodEndDate                    invoices
 *   GET  …/billingAccounts/{a}/invoices/{n}/transactions                                 the charges on an invoice
 *   POST …/billingAccounts/{a}/invoices/{n}/download?documentName=                       a short-lived link to the PDF
 * An invoice is imported once (vendor "Microsoft", number = Microsoft's invoice name), status "Received": a person
 * approves and pays it in the app as for any vendor. Microsoft's own status is kept in ms_note and refreshed.
 * Not checked on a live tenant yet (built from Microsoft's published API description).
 */
if (!function_exists('itops_msb_token')) {
    if (!defined('ITOPS_MSB_API')) define('ITOPS_MSB_API', '2024-04-01');

    function itops_msb_token(bool $force = false): string {
        $c = itops_graph_cfg();
        if ($c['tenant'] === '') throw new RuntimeException('Microsoft 365 is not configured.');
        $file = preg_replace('/\.json$/', '_arm.json', itops_graph_token_file());
        if (!$force && is_file($file)) {
            $j = json_decode((string)@file_get_contents($file), true);
            if (is_array($j) && ($j['exp'] ?? 0) > time() + 120 && !empty($j['tok'])) { $t0 = itops_graph_tok_open((string)$j['tok']); if ($t0 !== '') return $t0; }
        }
        $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($c['tenant']) . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POSTFIELDS => http_build_query(['client_id' => $c['client'], 'client_secret' => $c['secret'], 'grant_type' => 'client_credentials', 'scope' => 'https://management.azure.com/.default']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $d = json_decode((string)$resp, true);
        if ($code !== 200 || empty($d['access_token'])) throw new RuntimeException('Azure billing token error: ' . ($d['error_description'] ?? ('HTTP ' . $code)));
        @file_put_contents($file, json_encode(['tok' => itops_graph_tok_seal((string)$d['access_token']), 'exp' => time() + (int)($d['expires_in'] ?? 3600)]), LOCK_EX);
        @chmod($file, 0600);
        return (string)$d['access_token'];
    }
    /** One call to Azure Resource Manager. [code, json, headers]. Tests replace it (only defined when missing). */
    if (!function_exists('itops_msb_http')) {
        function itops_msb_http(string $method, string $url): array {
            if (strtolower((string)parse_url($url, PHP_URL_HOST)) !== 'management.azure.com' || parse_url($url, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('Not an Azure Resource Manager address.');
            $hdr = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . itops_msb_token(), 'Accept: application/json', 'Content-Length: 0'],
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { $p = strpos($h, ':'); if ($p) $hdr[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1)); return strlen($h); }]);
            $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
            if ($resp === false) throw new RuntimeException('Azure billing could not be reached: ' . $err);
            static $msbWaits = 0;   // review M365A-14: throttled → wait as asked (at most 10 seconds), twice at most
            if ($code === 429 && $msbWaits < 2) { $msbWaits++; sleep(max(1, min(10, (int)($hdr['retry-after'] ?? 2)))); return itops_msb_http($method, $url); }
            return [$code, json_decode((string)$resp, true) ?: [], $hdr];
        }
    }
    /** Plain words for a refusal. */
    function itops_msb_err(int $code, array $d, string $what): RuntimeException {
        $m = (string)($d['error']['message'] ?? ''); $c = (string)($d['error']['code'] ?? '');
        if ($code === 401 || $code === 403 || stripos($c, 'Authorization') !== false)
            return new RuntimeException('Microsoft refused to show ' . $what . ' (HTTP ' . $code . '). Give the app the billing role "Billing profile reader" on the billing profile. ' . mb_substr($m, 0, 200));
        if ($code === 404) return new RuntimeException('Microsoft does not know ' . $what . ' (HTTP 404). Check the billing account. ' . mb_substr($m, 0, 200));
        return new RuntimeException('Microsoft billing error (HTTP ' . $code . ') for ' . $what . ': ' . mb_substr($m !== '' ? $m : 'no message', 0, 300));
    }
    function itops_msb_get_all(string $path, string $what, int $maxPages = 20): array {
        $url = 'https://management.azure.com' . $path; $out = [];
        for ($i = 0; $i < $maxPages && $url; $i++) {
            [$code, $d] = itops_msb_http('GET', $url);
            if ($code !== 200) throw itops_msb_err($code, $d, $what);
            foreach ((array)($d['value'] ?? []) as $v) $out[] = $v;
            $url = (string)($d['nextLink'] ?? '');
            if ($url !== '' && strtolower((string)parse_url($url, PHP_URL_HOST)) !== 'management.azure.com') $url = '';
        }
        return $out;
    }
    /** A billing account name as Microsoft writes it: GUIDs, colons, underscores, dashes, dates. */
    function itops_msb_valid_account(string $a): bool { return (bool)preg_match('/^[A-Za-z0-9:_\-]{3,200}$/', $a); }
    function itops_msb_accounts(): array {
        $out = [];
        foreach (itops_msb_get_all('/providers/Microsoft.Billing/billingAccounts?api-version=' . ITOPS_MSB_API, 'the billing accounts', 3) as $a) {
            $p = (array)($a['properties'] ?? []);
            $out[] = ['name' => (string)($a['name'] ?? ''), 'display' => (string)($p['displayName'] ?? $a['name'] ?? ''), 'agreement' => (string)($p['agreementType'] ?? '')];
        }
        return $out;
    }
    function itops_msb_money($m): ?float { return is_array($m) && isset($m['value']) && is_numeric($m['value']) ? round((float)$m['value'], 2) : null; }
    function itops_msb_day($s): ?string { $t = strtotime((string)$s); return $s && $t ? date('Y-m-d', $t) : null; }
    function itops_msb_invoices(string $acct, string $from, string $to): array {
        $q = http_build_query(['api-version' => ITOPS_MSB_API, 'periodStartDate' => $from, 'periodEndDate' => $to]);
        $out = [];
        foreach (itops_msb_get_all('/providers/Microsoft.Billing/billingAccounts/' . rawurlencode($acct) . '/invoices?' . $q, 'the invoices') as $v) {
            $p = (array)($v['properties'] ?? []); $name = (string)($v['name'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $name)) continue;
            $doc = ''; foreach ((array)($p['documents'] ?? []) as $d) if (($d['kind'] ?? '') === 'Invoice' && preg_match('/^[A-Za-z0-9_\-]{1,80}$/', (string)($d['name'] ?? ''))) { $doc = (string)$d['name']; break; }
            $out[] = ['name' => $name, 'date' => itops_msb_day($p['invoiceDate'] ?? null), 'due' => itops_msb_day($p['dueDate'] ?? null),
                      'from' => itops_msb_day($p['invoicePeriodStartDate'] ?? null), 'to' => itops_msb_day($p['invoicePeriodEndDate'] ?? null),
                      'status' => (string)($p['status'] ?? ''), 'type' => (string)($p['documentType'] ?? 'Invoice'),
                      'currency' => strtoupper((string)($p['subTotal']['currency'] ?? $p['billedAmount']['currency'] ?? 'USD')),
                      'subtotal' => itops_msb_money($p['subTotal'] ?? null) ?? itops_msb_money($p['billedAmount'] ?? null), 'tax' => itops_msb_money($p['taxAmount'] ?? null) ?? 0.0,
                      'total' => itops_msb_money($p['totalAmount'] ?? null), 'due_now' => itops_msb_money($p['amountDue'] ?? null),
                      'profile' => (string)($p['billingProfileDisplayName'] ?? ''), 'po' => (string)($p['purchaseOrderNumber'] ?? ''), 'doc' => $doc];
        }
        return $out;
    }
    /** The charges on an invoice: [[description, quantity, unit, amount]]. */
    function itops_msb_lines(string $acct, string $inv): array {
        $out = [];
        foreach (itops_msb_get_all('/providers/Microsoft.Billing/billingAccounts/' . rawurlencode($acct) . '/invoices/' . rawurlencode($inv) . '/transactions?api-version=' . ITOPS_MSB_API, 'the charges on invoice ' . $inv, 10) as $t) {
            $p = (array)($t['properties'] ?? []);
            $amt = itops_msb_money($p['subTotal'] ?? null) ?? itops_msb_money($p['transactionAmount'] ?? null) ?? itops_msb_money($p['amount'] ?? null);
            if ($amt === null) continue;
            $qty = is_numeric($p['quantity'] ?? null) && (float)$p['quantity'] > 0 ? round((float)$p['quantity'], 2) : 1.0;
            $desc = trim((string)($p['productDescription'] ?? $p['product'] ?? 'Microsoft charge'));
            $sp = itops_msb_day($p['servicePeriodStartDate'] ?? null); $ep = itops_msb_day($p['servicePeriodEndDate'] ?? null);
            $out[] = ['description' => mb_substr($desc . ($sp && $ep ? ' (' . date('m/d/Y', strtotime($sp)) . '–' . date('m/d/Y', strtotime($ep)) . ')' : ''), 0, 300),
                      'product' => $desc, 'quantity' => $qty, 'unit_price' => round($amt / $qty, 4), 'amount' => $amt];
        }
        return $out;
    }
    /** The invoice PDF: a short-lived link from Microsoft, then the file. Returns the bytes or throws. */
    function itops_msb_pdf(string $acct, string $inv, string $doc): string {
        $url = 'https://management.azure.com/providers/Microsoft.Billing/billingAccounts/' . rawurlencode($acct) . '/invoices/' . rawurlencode($inv) . '/download?' . http_build_query(['api-version' => ITOPS_MSB_API, 'documentName' => $doc]);
        [$code, $d, $h] = itops_msb_http('POST', $url);
        for ($i = 0; $code === 202 && $i < 5; $i++) {
            $loc = (string)($h['location'] ?? ''); if ($loc === '') break;
            usleep((int)min(5, max(1, (int)($h['retry-after'] ?? 2))) * 1000000);
            [$code, $d, $h] = itops_msb_http('GET', $loc);
        }
        if ($code !== 200 || empty($d['url'])) throw itops_msb_err($code, $d, 'the PDF of invoice ' . $inv);
        $u = (string)$d['url']; $host = strtolower((string)parse_url($u, PHP_URL_HOST));
        if (parse_url($u, PHP_URL_SCHEME) !== 'https' || !preg_match('/(\.blob\.core\.windows\.net|\.microsoft\.com|\.azure\.com)$/', $host)) throw new RuntimeException('Microsoft gave a PDF link on an unexpected address; it was not opened.');
        return itops_msb_fetch_pdf($u);
    }
    if (!function_exists('itops_msb_fetch_pdf')) {
        function itops_msb_fetch_pdf(string $u): string {
            $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 15 * 1024 * 1024]);
            $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code !== 200 || !is_string($b)) throw new RuntimeException('The invoice PDF could not be downloaded (HTTP ' . $code . ').');
            return $b;
        }
    }

    function itops_msb_cfg(PDO $db): array {
        $j = json_decode(itops_inv_setting($db, 'ms_billing'), true);
        return ['account' => is_array($j) && itops_msb_valid_account((string)($j['account'] ?? '')) ? (string)$j['account'] : '', 'auto' => is_array($j) && !empty($j['auto']),
                'last' => is_array($j) ? ($j['last'] ?? null) : null];
    }
    function itops_msb_cfg_save(PDO $db, array $c, ?int $by): void {
        $db->prepare('INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)')->execute(['ms_billing', json_encode($c, JSON_UNESCAPED_SLASHES), $by]);
    }
    /** Microsoft's contract the product belongs to: a Microsoft contract whose title names it, else null. */
    function itops_msb_contract_for(array $contracts, string $product): ?int {
        $p = mb_strtolower(trim($product)); if ($p === '') return null;
        foreach ($contracts as $c) if (mb_strtolower(trim((string)$c['title'])) === $p) return (int)$c['id'];
        foreach ($contracts as $c) { $t = mb_strtolower((string)$c['title']); if ($t !== '' && (str_contains($t, $p) || str_contains($p, $t))) return (int)$c['id']; }
        return null;
    }
    /**
     * Import the invoices of [$from, $to]. New ones are created (status Received, source "microsoft", PDF attached when
     * Microsoft gives it); ones already imported get Microsoft's status refreshed in their note. Returns a summary.
     */
    function itops_msb_import(PDO $db, string $acct, string $from, string $to, ?int $by): array {
        $v = itops_inv_ms_vendor($db); if (!$v) throw new InvalidArgumentException('Create the vendor "Microsoft" first (Microsoft 365 → Licenses → Subscriptions & contracts).');
        $vid = (int)$v['id'];
        $q = $db->prepare('SELECT id, title FROM itops_contracts WHERE vendor_id=?'); $q->execute([$vid]); $contracts = $q->fetchAll(PDO::FETCH_ASSOC);
        $sum = ['seen' => 0, 'new' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
        foreach (itops_msb_invoices($acct, $from, $to) as $m) {
            $sum['seen']++;
            if ($m['type'] !== 'Invoice' && $m['type'] !== '') { $sum['skipped'][] = $m['name'] . ' (' . $m['type'] . ')'; continue; }
            if (!$m['date'] || $m['subtotal'] === null) { $sum['skipped'][] = $m['name'] . ' (no date or amount)'; continue; }
            $note = 'Microsoft: ' . ($m['status'] ?: 'unknown') . ($m['due_now'] !== null ? ', amount due ' . number_format($m['due_now'], 2) . ' ' . $m['currency'] : '') . ($m['profile'] ? ' · ' . $m['profile'] : '') . ($m['po'] ? ' · PO ' . $m['po'] : '');
            $ex = $db->prepare('SELECT id, ms_note FROM itops_invoices WHERE vendor_id=? AND invoice_number=?'); $ex->execute([$vid, $m['name']]); $row = $ex->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ((string)$row['ms_note'] !== $note) { $db->prepare('UPDATE itops_invoices SET ms_note=? WHERE id=?')->execute([mb_substr($note, 0, 500), (int)$row['id']]); $sum['updated'][] = ['id' => (int)$row['id'], 'name' => $m['name'], 'note' => $note]; }
                continue;
            }
            $lines = []; $lineErr = null;
            try { $lines = itops_msb_lines($acct, $m['name']); } catch (Throwable $e) { $lineErr = $e->getMessage(); }
            $L = [];
            foreach ($lines as $l) if (abs($l['amount']) >= 0.005) $L[] = ['description' => $l['description'], 'contract_id' => itops_msb_contract_for($contracts, $l['product']), 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'billing' => 'monthly'];
            $diff = round($m['subtotal'] - array_sum(array_map(fn($l) => round($l['quantity'] * $l['unit_price'], 2), $L)), 2);
            if (!$L) $L[] = ['description' => 'Microsoft invoice ' . $m['name'] . ($lineErr ? ' (charges not read)' : ''), 'contract_id' => null, 'quantity' => 1, 'unit_price' => $m['subtotal'], 'billing' => 'monthly'];
            elseif (abs($diff) >= 0.01) $L[] = ['description' => 'Difference to Microsoft\'s subtotal (credits, rounding)', 'contract_id' => null, 'quantity' => 1, 'unit_price' => $diff, 'billing' => 'one_time'];
            try {
                $c = itops_inv_clean($db, ['vendor_id' => $vid, 'invoice_number' => $m['name'], 'invoice_date' => $m['date'], 'period_start' => $m['from'] ?? '', 'period_end' => $m['to'] ?? '',
                    'due_date' => $m['due'] ?? '', 'currency' => $m['currency'], 'tax' => max(0, $m['tax']), 'lines' => $L, 'notes' => 'Imported from Microsoft billing.' . ($lineErr ? ' The charges could not be read: ' . mb_substr($lineErr, 0, 300) : '')]);
                $c['source'] = 'microsoft';
                $iid = itops_inv_save($db, $c, (int)($by ?? 0));
                $db->prepare('UPDATE itops_invoices SET ms_note=? WHERE id=?')->execute([mb_substr($note, 0, 500), $iid]);
                $pdf = null;
                if ($m['doc'] !== '') {
                    try { $pdf = itops_msb_attach($db, $iid, itops_msb_pdf($acct, $m['name'], $m['doc']), 'Microsoft-' . $m['name'] . '.pdf', $by); }
                    catch (Throwable $e) { $sum['errors'][] = $m['name'] . ': PDF not attached (' . mb_substr($e->getMessage(), 0, 200) . ')'; }
                }
                $tot = round($c['total'], 2); $mt = $m['total'];
                $sum['new'][] = ['id' => $iid, 'name' => $m['name'], 'total' => $tot, 'lines' => count($L), 'pdf' => $pdf !== null, 'mismatch' => $mt !== null && abs($mt - $tot) >= 0.01 ? $mt : null];
            } catch (Throwable $e) { $sum['errors'][] = $m['name'] . ': ' . mb_substr($e->getMessage(), 0, 300); }
        }
        return $sum;
    }
    /** One import run with its Audit Log lines (by a person, or by the daily job when $by is null). */
    function itops_msb_run(PDO $db, string $from, string $to, ?int $by, string $via): array {
        $cfg = itops_msb_cfg($db); if ($cfg['account'] === '') throw new InvalidArgumentException('Choose the Microsoft billing account first.');
        $s = itops_msb_import($db, $cfg['account'], $from, $to, $by);
        foreach ($s['new'] as $n) audit_log('invoice_created', 'invoice', $n['id'], 'Microsoft invoice ' . $n['name'], null,
            ['Total' => number_format($n['total'], 2), 'Lines' => $n['lines'], 'PDF' => $n['pdf'] ? 'attached' : 'not attached', 'Read by' => 'Microsoft billing (' . $via . ')'] + ($n['mismatch'] !== null ? ['Microsoft total' => number_format($n['mismatch'], 2)] : []));
        foreach ($s['updated'] as $u) audit_log('invoice_updated', 'invoice', $u['id'], 'Microsoft invoice ' . $u['name'], null, ['Microsoft status' => $u['note']]);
        audit_log('invoice_ms_import', 'invoice', null, 'Microsoft invoices', null, ['Period' => $from . ' – ' . $to, 'Seen' => $s['seen'], 'New' => count($s['new']), 'Status changed' => count($s['updated']),
            'Skipped' => $s['skipped'] ?: 'none', 'Problems' => $s['errors'] ?: 'none', 'How' => $via]);
        $cfg['last'] = ['at' => date('Y-m-d H:i:s'), 'via' => $via, 'seen' => $s['seen'], 'new' => count($s['new']), 'errors' => count($s['errors'])];
        itops_msb_cfg_save($db, ['account' => $cfg['account'], 'auto' => $cfg['auto'], 'last' => $cfg['last']], $by);
        return $s;
    }
    function itops_msb_attach(PDO $db, int $iid, string $bytes, string $name, ?int $by): int {
        if (strncmp($bytes, '%PDF', 4) !== 0) throw new RuntimeException('what Microsoft sent is not a PDF');
        if (strlen($bytes) > 15 * 1024 * 1024) throw new RuntimeException('the PDF is larger than 15 MB');
        $dir = UPLOAD_DIR . 'documents/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $rel = 'documents/' . bin2hex(random_bytes(10)) . '.pdf';
        if (@file_put_contents(UPLOAD_DIR . $rel, $bytes) === false) throw new RuntimeException('the PDF could not be saved');
        $db->prepare('INSERT INTO itops_attachments (entity_type,entity_id,file_name,file_path,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?,?)')
           ->execute(['invoices', $iid, preg_replace('/[^A-Za-z0-9._-]/', '_', $name), $rel, 'application/pdf', strlen($bytes), $by]);
        return (int)$db->lastInsertId();
    }
}
