<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT120: software licenses and invoices.
 *
 *  - Microsoft: the tenant's subscriptions (Graph directory/subscriptions and subscribedSkus) are read; on a person's
 *    word the vendor "Microsoft" is created and one contract per subscription (title, subscription id, seats, the price
 *    used in IT Budget, the date Microsoft renews or ends it). Later reads show what changed (seats, renewal date,
 *    status) and "Update the contracts" applies it: a new term when Microsoft renewed, the amounts when seats changed.
 *  - Invoices from any vendor: number, dates, billing period, lines tied to contracts (quantity × unit price), tax,
 *    the PDF (Documents), a status (received → approved → paid, or disputed). Totals are computed here, never taken
 *    from the browser. Paying an invoice records the spend in IT Budget (actuals); undoing it removes that record.
 *  - Checks: Microsoft seats billed vs bought vs assigned, the unit price vs the price in IT Budget, the amount vs the
 *    contract; a contract whose invoice is missing for a period.
 *  - Reading a PDF: the browser extracts the text (pdf.js) and the AI integration proposes the fields; a person checks
 *    them and saves. Nothing is saved from the AI's answer alone.
 * Tables: itops_invoices, itops_invoice_lines, itops_license_subs.
 */

if (!function_exists('itops_inv_ready')) {

    function itops_inv_ready(PDO $db): bool {
        try { $db->query('SELECT 1 FROM itops_invoices LIMIT 1'); $db->query('SELECT 1 FROM itops_invoice_lines LIMIT 1'); $db->query('SELECT 1 FROM itops_license_subs LIMIT 1'); return true; }
        catch (Throwable $e) { return false; }
    }
    function itops_inv_statuses(): array { return ['received' => 'Received', 'approved' => 'Approved', 'paid' => 'Paid', 'disputed' => 'Disputed']; }
    function itops_inv_date($d): ?string { $d = trim((string)$d); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)) ? $d : null; }
    function itops_inv_setting(PDO $db, string $k): string { try { $s = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $s->execute([$k]); return (string)$s->fetchColumn(); } catch (Throwable $e) { return ''; } }

    /* ─────────────── Microsoft subscriptions ─────────────── */

    /** Microsoft's subscriptions, with the seats bought and assigned per license. Throws when Microsoft cannot be read. */
    function itops_inv_ms_read(): array {
        $skus = [];
        foreach ((itops_graph_get('subscribedSkus?$select=skuId,skuPartNumber,prepaidUnits,consumedUnits')['value'] ?? []) as $s)
            $skus[strtolower((string)($s['skuId'] ?? ''))] = ['part' => (string)($s['skuPartNumber'] ?? ''), 'bought' => (int)($s['prepaidUnits']['enabled'] ?? 0), 'assigned' => (int)($s['consumedUnits'] ?? 0)];
        $out = [];
        foreach (itops_graph_paged('directory/subscriptions', 5) as $s) {
            $sku = strtolower((string)($s['skuId'] ?? '')); $part = (string)($s['skuPartNumber'] ?? ($skus[$sku]['part'] ?? ''));
            $name = (function_exists('itops_budget_m365_catalog') ? (itops_budget_m365_catalog()[$part][0] ?? null) : null) ?? ($part !== '' ? $part : 'Microsoft subscription');
            $d = fn($v) => $v ? substr((string)$v, 0, 10) : null;
            $out[] = ['id' => (string)($s['id'] ?? ''), 'commerce_id' => (string)($s['commerceSubscriptionId'] ?? ''), 'sku_id' => $sku, 'part' => $part, 'name' => $name,
                      'status' => (string)($s['status'] ?? ''), 'trial' => !empty($s['isTrial']), 'seats' => (int)($s['totalLicenses'] ?? 0),
                      'renews' => $d($s['nextLifecycleDateTime'] ?? null), 'created' => $d($s['createdDateTime'] ?? null),
                      'bought' => $skus[$sku]['bought'] ?? null, 'assigned' => $skus[$sku]['assigned'] ?? null];
        }
        usort($out, fn($a, $b) => [$a['status'] !== 'Enabled', $a['name']] <=> [$b['status'] !== 'Enabled', $b['name']]);
        return $out;
    }
    /** The vendor Microsoft licenses are bought from (IT Budget's link, else the one vendor named Microsoft). */
    function itops_inv_ms_vendor(PDO $db): ?array {
        $vid = null;
        if (function_exists('itops_budget_m365_link')) { try { $vid = itops_budget_m365_link($db)['vendor_id']; } catch (Throwable $e) {} }
        if (!$vid) { try { $r = $db->query("SELECT id FROM itops_vendors WHERE LOWER(name) LIKE 'microsoft%' AND COALESCE(status,'active')<>'archived'")->fetchAll(PDO::FETCH_COLUMN); if (count($r) === 1) $vid = (int)$r[0]; } catch (Throwable $e) {} }
        if (!$vid) return null;
        $q = $db->prepare("SELECT id, name, status FROM itops_vendors WHERE id=?"); $q->execute([$vid]); $v = $q->fetch(PDO::FETCH_ASSOC);
        return $v ? ['id' => (int)$v['id'], 'name' => $v['name']] : null;
    }
    function itops_inv_ms_vendor_create(PDO $db): array {
        if ($v = itops_inv_ms_vendor($db)) return [$v, false];
        $db->prepare("INSERT INTO itops_vendors (name, website, support_url, support_phone, category, risk_tier, status, notes) VALUES (?,?,?,?,?,?,?,?)")
           ->execute(['Microsoft', 'https://www.microsoft.com', 'https://admin.microsoft.com/#/support', '', 'Software / Cloud', 'high', 'active', 'Microsoft 365 licenses, bought directly from Microsoft. Created from Microsoft 365 → Licenses → Subscriptions.']);
        $id = (int)$db->lastInsertId();
        try {
            $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='budget_m365_link'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true) ?: [];
            $j['vendor_id'] = $id;
            $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('budget_m365_link',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($j)]);
        } catch (Throwable $e) {}
        return [['id' => $id, 'name' => 'Microsoft'], true];
    }
    /** The price used for a license (IT Budget): [per license per month, billed monthly|annual, from]. */
    function itops_inv_ms_price(PDO $db, string $skuId, string $part): array {
        if (!function_exists('itops_budget_m365_price')) return [null, 'annual', 'unknown'];
        $cfg = itops_budget_cfg($db); $p = itops_budget_m365_price(itops_budget_prices($db), $skuId, $part, (string)$cfg['pricing']);
        return [$p['price'] === null ? null : round($p['price'] * ($p['billing'] === 'monthly' ? 1.05 : 1.0), 4), $p['billing'], $p['from']];
    }
    /** What a subscription's contract says. */
    function itops_inv_ms_contract(PDO $db, array $s, int $vendorId): array {
        [$unit, $bill] = itops_inv_ms_price($db, $s['sku_id'], $s['part']);
        $monthly = $unit === null ? 0.0 : round($unit * $s['seats'], 2);
        $start = $s['created'] ?: date('Y-m-d'); $end = $s['renews'] ?: date('Y-m-d', strtotime($start . ' +1 year'));
        if ($end <= $start) $end = date('Y-m-d', strtotime($start . ' +1 day'));
        return ['title' => $s['name'] . ' (Microsoft subscription)', 'vendor_id' => $vendorId, 'contract_number' => mb_substr($s['commerce_id'] ?: $s['id'], 0, 100),
                'contract_type' => 'subscription', 'status' => 'active', 'start_date' => $start, 'end_date' => $end,
                'auto_renew' => ($s['status'] === 'Enabled' && !$s['trial']) ? 1 : 0, 'renewal_notice_days' => 30,
                'payment_frequency' => $bill === 'monthly' ? 'monthly' : 'annual', 'payment_amount' => $bill === 'monthly' ? $monthly : round($monthly * 12, 2), 'total_value' => round($monthly * 12, 2),
                'notes' => $s['seats'] . ' seat' . ($s['seats'] === 1 ? '' : 's') . ' · ' . ($unit === null ? 'no price yet (save the invoice price)' : number_format($unit, 2) . ' per license per month') . ($s['trial'] ? ' · trial' : '') . ' · status ' . $s['status'] . ' · read from Microsoft 365 on ' . date('m/d/Y') . '.'];
    }
    /** Subscriptions with what is linked and what changed since the last look. */
    function itops_inv_ms_view(PDO $db, array $subs): array {
        $links = []; foreach ($db->query('SELECT * FROM itops_license_subs')->fetchAll(PDO::FETCH_ASSOC) as $r) $links[$r['sub_id']] = $r;
        $cids = array_filter(array_map(fn($r) => (int)$r['contract_id'], $links));
        $cons = [];
        if ($cids) foreach ($db->query('SELECT c.id, c.title, c.end_date, c.status, c.payment_amount, c.payment_frequency FROM itops_contracts c WHERE c.id IN (' . implode(',', array_map('intval', $cids)) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) $cons[(int)$c['id']] = $c;
        $out = [];
        foreach ($subs as $s) {
            $l = $links[$s['id']] ?? null; $c = $l && $l['contract_id'] ? ($cons[(int)$l['contract_id']] ?? null) : null;
            $ch = [];
            if ($l && $c) {
                if ((int)$l['seats'] !== $s['seats']) $ch[] = 'seats ' . $l['seats'] . ' → ' . $s['seats'];
                if ((string)$l['renews'] !== (string)$s['renews']) $ch[] = 'renews ' . ($l['renews'] ? date('m/d/Y', strtotime($l['renews'])) : '—') . ' → ' . ($s['renews'] ? date('m/d/Y', strtotime($s['renews'])) : '—');
                if ((string)$l['status'] !== $s['status']) $ch[] = 'status ' . $l['status'] . ' → ' . $s['status'];
            }
            [$unit, $bill, $from] = itops_inv_ms_price($db, $s['sku_id'], $s['part']);
            $out[] = $s + ['contract' => $c ? ['id' => (int)$c['id'], 'title' => $c['title'], 'end_date' => $c['end_date'], 'status' => $c['status']] : null,
                           'contract_gone' => $l && $l['contract_id'] && !$c, 'changes' => $ch, 'unit_month' => $unit, 'billing' => $bill, 'price_from' => $from,
                           'unused' => $s['bought'] !== null && $s['assigned'] !== null ? max(0, $s['bought'] - $s['assigned']) : null];
        }
        return $out;
    }
    /**
     * Create the contracts of the chosen subscriptions (not linked yet), and bring linked ones up to date. Returns
     * [created, updated, renewed, notes]. Every change goes in the Audit Log (by the caller's audit callback).
     */
    function itops_inv_ms_apply(PDO $db, array $subs, array $create, array $user, callable $audit): array {
        require_once __DIR__ . '/Terms.php';
        $v = itops_inv_ms_vendor($db); if (!$v) throw new InvalidArgumentException('Create or choose the Microsoft vendor first.');
        $links = []; foreach ($db->query('SELECT * FROM itops_license_subs')->fetchAll(PDO::FETCH_ASSOC) as $r) $links[$r['sub_id']] = $r;
        $made = 0; $upd = 0; $ren = 0; $notes = [];
        $save = function (array $s, ?int $cid, string $change) use ($db) {
            $db->prepare('INSERT INTO itops_license_subs (sub_id, commerce_id, sku_id, sku_part, name, contract_id, seats, status, trial, renews, created, assigned, last_seen, last_change) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)
                          ON DUPLICATE KEY UPDATE commerce_id=VALUES(commerce_id), sku_id=VALUES(sku_id), sku_part=VALUES(sku_part), name=VALUES(name), contract_id=VALUES(contract_id), seats=VALUES(seats), status=VALUES(status),
                          trial=VALUES(trial), renews=VALUES(renews), created=VALUES(created), assigned=VALUES(assigned), last_seen=NOW(), last_change=COALESCE(VALUES(last_change), last_change)')
               ->execute([$s['id'], $s['commerce_id'], $s['sku_id'], $s['part'], mb_substr($s['name'], 0, 200), $cid, $s['seats'], $s['status'], $s['trial'] ? 1 : 0, $s['renews'], $s['created'], $s['assigned'], $change !== '' ? mb_substr($change, 0, 500) : null]);
        };
        foreach ($subs as $s) {
            if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $s['id'])) continue;
            $l = $links[$s['id']] ?? null; $cid = $l && $l['contract_id'] ? (int)$l['contract_id'] : null;
            $c = null; if ($cid) { $q = $db->prepare('SELECT * FROM itops_contracts WHERE id=?'); $q->execute([$cid]); $c = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
            $f = itops_inv_ms_contract($db, $s, $v['id']);
            if (!$c) {
                if (!in_array($s['id'], $create, true)) continue;
                $db->prepare('INSERT INTO itops_contracts (title, vendor_id, contract_number, contract_type, status, start_date, end_date, auto_renew, renewal_notice_days, total_value, payment_frequency, payment_amount, owner_id, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$f['title'], $f['vendor_id'], $f['contract_number'], $f['contract_type'], $f['status'], $f['start_date'], $f['end_date'], $f['auto_renew'], $f['renewal_notice_days'], $f['total_value'], $f['payment_frequency'], $f['payment_amount'], (int)$user['id'], $f['notes']]);
                $nid = (int)$db->lastInsertId(); $save($s, $nid, 'contract created'); $made++;
                $audit('contract_created', 'contract', $nid, $f['title'], null, ['From' => 'Microsoft 365 subscription ' . $f['contract_number'], 'Seats' => $s['seats'], 'Term' => $f['start_date'] . ' → ' . $f['end_date'], 'Amount' => $f['payment_amount'] . ' ' . $f['payment_frequency']]);
                continue;
            }
            $ch = [];
            if ($s['renews'] && $s['renews'] > (string)$c['end_date'] && !itops_term_closed($c, itops_term_cfg('contract')) && in_array($c['status'], ['active', 'expiring', 'expired'], true)) {   // Microsoft renewed it: a new term (the term code writes the Audit Log)
                try {
                    $r = itops_term_renew($db, itops_term_cfg('contract'), (int)$c['id'], ['start' => date('Y-m-d', strtotime($c['end_date'] . ' +1 day')), 'end' => $s['renews'], 'note' => 'Renewed by Microsoft (read from Microsoft 365).',
                        'payment_amount' => $f['payment_amount'], 'total_value' => $f['total_value']], $user);
                    $newId = (int)($r['id'] ?? $r['new_id'] ?? 0);
                    if ($newId) { $cid = $newId; $ren++; $ch[] = 'renewed to ' . date('m/d/Y', strtotime($s['renews'])); }
                } catch (Throwable $e) { $notes[] = $c['title'] . ': the new term could not be made (' . $e->getMessage() . '); renew it on the contract.'; }
            } elseif ((float)$c['payment_amount'] !== (float)$f['payment_amount'] || (int)($l['seats'] ?? -1) !== $s['seats']) {
                $db->prepare('UPDATE itops_contracts SET payment_amount=?, total_value=?, payment_frequency=?, notes=CONCAT(COALESCE(notes,\'\'), ?) WHERE id=?')
                   ->execute([$f['payment_amount'], $f['total_value'], $f['payment_frequency'], "\n" . date('m/d/Y') . ': ' . $f['notes'], (int)$c['id']]);
                $upd++; $ch[] = 'seats ' . ($l['seats'] ?? '?') . ' → ' . $s['seats'];
                $audit('contract_updated', 'contract', (int)$c['id'], $c['title'], ['Amount' => $c['payment_amount'], 'Seats' => $l['seats'] ?? ''], ['Amount' => $f['payment_amount'], 'Seats' => $s['seats'], 'Why' => 'Read from Microsoft 365']);
            }
            if ($s['status'] !== ($l['status'] ?? '') && in_array($s['status'], ['Deleted', 'Suspended', 'LockedOut'], true)) $notes[] = $c['title'] . ': Microsoft says the subscription is ' . $s['status'] . '. End the contract if it is no longer used.';
            $save($s, $cid, implode('; ', $ch));
        }
        return [$made, $upd, $ren, $notes];
    }

    /* ─────────────── invoices ─────────────── */

    /** Validate and compute an invoice from the browser's input. Throws InvalidArgumentException. */
    function itops_inv_clean(PDO $db, array $in, ?int $id = null): array {
        $vid = (int)($in['vendor_id'] ?? 0);
        $q = $db->prepare('SELECT id, name FROM itops_vendors WHERE id=?'); $q->execute([$vid]); $v = $q->fetch(PDO::FETCH_ASSOC);
        if (!$v) throw new InvalidArgumentException('Choose the vendor.');
        $num = trim((string)($in['invoice_number'] ?? '')); if ($num === '' || mb_strlen($num) > 80) throw new InvalidArgumentException('Enter the invoice number (up to 80 characters).');
        $date = itops_inv_date($in['invoice_date'] ?? ''); if (!$date) throw new InvalidArgumentException('Enter the invoice date.');
        $ps = ($in['period_start'] ?? '') === '' ? null : itops_inv_date($in['period_start']); $pe = ($in['period_end'] ?? '') === '' ? null : itops_inv_date($in['period_end']);
        if (($in['period_start'] ?? '') !== '' && !$ps || ($in['period_end'] ?? '') !== '' && !$pe) throw new InvalidArgumentException('The billing period dates are not valid.');
        if ($ps && $pe && $pe < $ps) throw new InvalidArgumentException('The billing period ends before it starts.');
        $due = ($in['due_date'] ?? '') === '' ? null : itops_inv_date($in['due_date']);
        $cur = strtoupper(trim((string)($in['currency'] ?? 'USD'))); if (!preg_match('/^[A-Z]{3}$/', $cur)) throw new InvalidArgumentException('The currency is a three-letter code (USD).');
        $tax = round((float)($in['tax'] ?? 0), 2); if ($tax < 0 || $tax > 10000000) throw new InvalidArgumentException('Tax is 0 or more.');
        $lines = [];
        foreach (array_slice((array)($in['lines'] ?? []), 0, 100) as $l) {
            $desc = trim((string)($l['description'] ?? '')); $cid = (int)($l['contract_id'] ?? 0) ?: null;
            $qty = round((float)($l['quantity'] ?? 0), 2); $unit = round((float)($l['unit_price'] ?? 0), 4);
            if ($desc === '' && !$cid && !$qty && !$unit) continue;
            if ($qty <= 0 || $qty > 1000000) throw new InvalidArgumentException('Each line needs a quantity above 0.');
            if ($unit < -10000000 || $unit > 10000000) throw new InvalidArgumentException('A unit price is out of range.');
            if ($cid) { $c = $db->prepare('SELECT vendor_id FROM itops_contracts WHERE id=?'); $c->execute([$cid]); $cv = $c->fetchColumn(); if ($cv === false) throw new InvalidArgumentException('A line names a contract that does not exist.'); if ((int)$cv !== $vid) throw new InvalidArgumentException('A line names a contract of another vendor.'); }
            $bill = in_array($l['billing'] ?? '', ['monthly', 'annual', 'one_time'], true) ? $l['billing'] : 'monthly';
            $lines[] = ['description' => mb_substr($desc, 0, 300), 'contract_id' => $cid, 'quantity' => $qty, 'unit_price' => $unit, 'amount' => round($qty * $unit, 2), 'billing' => $bill,
                        'sku_part' => preg_match('/^[A-Za-z0-9_]{2,60}$/', (string)($l['sku_part'] ?? '')) ? (string)$l['sku_part'] : null];
        }
        if (!$lines) throw new InvalidArgumentException('Add at least one line.');
        $sub = round(array_sum(array_column($lines, 'amount')), 2);
        $dup = $db->prepare('SELECT id FROM itops_invoices WHERE vendor_id=? AND invoice_number=?' . ($id ? ' AND id<>?' : '')); $dup->execute($id ? [$vid, $num, $id] : [$vid, $num]);
        if ($dup->fetchColumn()) throw new InvalidArgumentException('This vendor already has invoice ' . $num . '.');
        $src = in_array($in['source'] ?? '', ['manual', 'pdf_ai'], true) ? $in['source'] : 'manual';
        return ['vendor_id' => $vid, 'vendor_name' => $v['name'], 'invoice_number' => $num, 'invoice_date' => $date, 'period_start' => $ps, 'period_end' => $pe, 'due_date' => $due,
                'currency' => $cur, 'subtotal' => $sub, 'tax' => $tax, 'total' => round($sub + $tax, 2), 'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000), 'source' => $src, 'lines' => $lines];
    }
    function itops_inv_save(PDO $db, array $c, int $by, ?int $id = null): int {
        $db->beginTransaction();
        try {
            if ($id) {
                $db->prepare('UPDATE itops_invoices SET vendor_id=?, invoice_number=?, invoice_date=?, period_start=?, period_end=?, due_date=?, currency=?, subtotal=?, tax=?, total=?, notes=? WHERE id=?')
                   ->execute([$c['vendor_id'], $c['invoice_number'], $c['invoice_date'], $c['period_start'], $c['period_end'], $c['due_date'], $c['currency'], $c['subtotal'], $c['tax'], $c['total'], $c['notes'], $id]);
                $db->prepare('DELETE FROM itops_invoice_lines WHERE invoice_id=?')->execute([$id]);
            } else {
                $db->prepare("INSERT INTO itops_invoices (vendor_id, invoice_number, invoice_date, period_start, period_end, due_date, currency, subtotal, tax, total, status, source, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,'received',?,?,?,NOW())")
                   ->execute([$c['vendor_id'], $c['invoice_number'], $c['invoice_date'], $c['period_start'], $c['period_end'], $c['due_date'], $c['currency'], $c['subtotal'], $c['tax'], $c['total'], $c['source'], $c['notes'], $by ?: null]);
                $id = (int)$db->lastInsertId();
            }
            $ins = $db->prepare('INSERT INTO itops_invoice_lines (invoice_id, pos, contract_id, description, quantity, unit_price, amount, billing, sku_part) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($c['lines'] as $i => $l) $ins->execute([$id, $i + 1, $l['contract_id'], $l['description'], $l['quantity'], $l['unit_price'], $l['amount'], $l['billing'], $l['sku_part']]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        return $id;
    }
    function itops_inv_get(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT i.*, v.name AS vendor_name, u.display_name AS created_by_name FROM itops_invoices i LEFT JOIN itops_vendors v ON v.id=i.vendor_id LEFT JOIN itops_users u ON u.id=i.created_by WHERE i.id=?'); $q->execute([$id]);
        $i = $q->fetch(PDO::FETCH_ASSOC); if (!$i) return null;
        $l = $db->prepare('SELECT l.*, c.title AS contract_title FROM itops_invoice_lines l LEFT JOIN itops_contracts c ON c.id=l.contract_id WHERE l.invoice_id=? ORDER BY l.pos'); $l->execute([$id]);
        $i['lines'] = array_map(fn($r) => ['contract_id' => $r['contract_id'] ? (int)$r['contract_id'] : null, 'contract_title' => $r['contract_title'], 'description' => $r['description'], 'quantity' => (float)$r['quantity'],
            'unit_price' => (float)$r['unit_price'], 'amount' => (float)$r['amount'], 'billing' => $r['billing'], 'sku_part' => $r['sku_part']], $l->fetchAll(PDO::FETCH_ASSOC));
        foreach (['id', 'vendor_id', 'created_by'] as $k) $i[$k] = $i[$k] === null ? null : (int)$i[$k];
        foreach (['subtotal', 'tax', 'total'] as $k) $i[$k] = (float)$i[$k];
        return $i;
    }
    /** What to look at on an invoice: seats, prices, amounts. Each: [level ok|warn|info, text, action?]. */
    function itops_inv_checks(PDO $db, array $inv): array {
        $out = [];
        $subs = []; foreach ($db->query('SELECT * FROM itops_license_subs')->fetchAll(PDO::FETCH_ASSOC) as $s) if ($s['contract_id']) $subs[(int)$s['contract_id']][] = $s;
        foreach ($inv['lines'] as $n => $l) {
            $tag = 'Line ' . ($n + 1) . ($l['description'] !== '' ? ' (' . mb_substr($l['description'], 0, 60) . ')' : '');
            if (!$l['contract_id']) { $out[] = ['info', $tag . ': not tied to a contract, so it is not checked.']; continue; }
            $q = $db->prepare('SELECT * FROM itops_contracts WHERE id=?'); $q->execute([$l['contract_id']]); $c = $q->fetch(PDO::FETCH_ASSOC); if (!$c) continue;
            if (isset($subs[(int)$c['id']])) {
                $s = $subs[(int)$c['id']][0]; $bought = (int)$s['seats']; $as = $s['assigned'] === null ? null : (int)$s['assigned'];
                if ((int)round($l['quantity']) > $bought) $out[] = ['warn', $tag . ': billed for ' . (int)$l['quantity'] . ' seats, but the subscription has ' . $bought . '.'];
                elseif ((int)round($l['quantity']) < $bought) $out[] = ['info', $tag . ': billed for ' . (int)$l['quantity'] . ' seats; the subscription now has ' . $bought . ' (bought since?).'];
                if ($as !== null && $as < (int)round($l['quantity'])) $out[] = ['warn', $tag . ': ' . ((int)round($l['quantity']) - $as) . ' of the ' . (int)$l['quantity'] . ' seats billed are not assigned to anyone. Microsoft 365 → Licenses → Reclaim.'];
                [$unit] = itops_inv_ms_price($db, (string)$s['sku_id'], (string)$s['sku_part']);
                $perMonth = $l['billing'] === 'annual' ? round($l['unit_price'] / 12, 4) : $l['unit_price'];
                if ($unit === null) $out[] = ['warn', $tag . ': IT Budget has no price for this license yet.', ['kind' => 'use_price', 'sku_id' => $s['sku_id'], 'name' => $s['name'], 'price' => $perMonth, 'billing' => $l['billing'] === 'annual' ? 'annual' : 'monthly']];
                elseif (abs($unit - $perMonth) > 0.01) $out[] = ['warn', $tag . ': ' . number_format($perMonth, 2) . ' per license per month here; IT Budget uses ' . number_format($unit, 2) . '.', ['kind' => 'use_price', 'sku_id' => $s['sku_id'], 'name' => $s['name'], 'price' => $perMonth, 'billing' => $l['billing'] === 'annual' ? 'annual' : 'monthly']];
                else $out[] = ['ok', $tag . ': price matches IT Budget.'];
            } elseif ((float)$c['payment_amount'] > 0) {
                if (abs((float)$c['payment_amount'] - $l['amount']) > 0.01) $out[] = ['warn', $tag . ': ' . number_format($l['amount'], 2) . ' here; the contract says ' . number_format((float)$c['payment_amount'], 2) . ' ' . str_replace('_', '-', $c['payment_frequency']) . '.'];
                else $out[] = ['ok', $tag . ': matches the contract.'];
            }
            if ($inv['period_end'] && $c['end_date'] && $inv['period_start'] && $inv['period_start'] > $c['end_date']) $out[] = ['warn', $tag . ': billed for a period after the contract ended (' . date('m/d/Y', strtotime($c['end_date'])) . ').'];
        }
        $prev = $db->prepare("SELECT total FROM itops_invoices WHERE vendor_id=? AND id<>? AND invoice_date<? ORDER BY invoice_date DESC LIMIT 1"); $prev->execute([$inv['vendor_id'], $inv['id'] ?? 0, $inv['invoice_date']]);
        $pt = $prev->fetchColumn();
        if ($pt !== false && (float)$pt > 0 && abs((float)$pt - $inv['total']) / (float)$pt > 0.1) $out[] = ['info', 'The total is ' . ($inv['total'] > (float)$pt ? 'up' : 'down') . ' ' . round(abs($inv['total'] - (float)$pt) / (float)$pt * 100) . '% from this vendor\'s previous invoice (' . number_format((float)$pt, 2) . ').'];
        return $out;
    }
    /** Contracts with no invoice for a period they should have been billed in (the last $months months). */
    function itops_inv_missing(PDO $db, int $months = 12): array {
        $step = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12];
        $today = date('Y-m-d'); $from = date('Y-m-01', strtotime("-$months months"));
        $out = [];
        $cs = $db->query("SELECT c.id, c.title, c.vendor_id, v.name AS vendor_name, c.start_date, c.end_date, c.payment_frequency, c.payment_amount FROM itops_contracts c LEFT JOIN itops_vendors v ON v.id=c.vendor_id
                          WHERE c.status IN ('active','expiring') AND c.vendor_id IS NOT NULL AND c.payment_frequency IN ('monthly','quarterly','semi_annual','annual')")->fetchAll(PDO::FETCH_ASSOC);
        $has = $db->prepare('SELECT COUNT(*) FROM itops_invoice_lines l JOIN itops_invoices i ON i.id=l.invoice_id WHERE l.contract_id=? AND (COALESCE(i.period_start, i.invoice_date) < ? AND COALESCE(i.period_end, i.invoice_date) >= ?)');
        foreach ($cs as $c) {
            $m = $step[$c['payment_frequency']]; $start = max((string)$c['start_date'], $from);
            // periods follow the contract start (an annual contract started in March is billed each March)
            $p = (string)$c['start_date'];
            while ($p < $start) $p = date('Y-m-d', strtotime($p . " +$m months"));
            for ($i = 0; $i < 60 && $p <= $today && $p <= (string)$c['end_date']; $i++) {
                $pend = date('Y-m-d', strtotime($p . " +$m months"));
                if (date('Y-m-d', strtotime($p . ' +15 days')) <= $today) {   // give the vendor two weeks to send it
                    $has->execute([(int)$c['id'], $pend, $p]);
                    if (!(int)$has->fetchColumn()) $out[] = ['contract_id' => (int)$c['id'], 'contract' => $c['title'], 'vendor_id' => (int)$c['vendor_id'], 'vendor' => $c['vendor_name'], 'from' => $p, 'to' => date('Y-m-d', strtotime($pend . ' -1 day')), 'amount' => (float)$c['payment_amount']];
                }
                $p = $pend;
            }
        }
        usort($out, fn($a, $b) => [$b['from'], $a['vendor']] <=> [$a['from'], $b['vendor']]);
        return $out;
    }
    /** Paying records the spend in IT Budget (when the module is on); returns the actual's id or null. */
    function itops_inv_to_budget(PDO $db, array $inv, int $by): ?int {
        if (!function_exists('itops_budget_fy_of')) { if (is_file(__DIR__ . '/Budget.php')) require_once __DIR__ . '/Budget.php'; else return null; }
        try { $db->query('SELECT 1 FROM itops_budget_actuals LIMIT 1'); } catch (Throwable $e) { return null; }
        $cfg = itops_budget_cfg($db); $day = $inv['period_start'] ?: $inv['invoice_date'];
        // BT124 (code review OPS-6): an invoice in another currency than the budget's is not added to it as if it were the same
        $bc = strtoupper((string)($cfg['currency'] ?? 'USD'));
        if (strtoupper((string)$inv['currency']) !== $bc) throw new RuntimeException('the invoice is in ' . $inv['currency'] . ' and IT Budget counts in ' . $bc . ': enter the spend by hand in ' . $bc . '.');
        $fy = itops_budget_fy_of($cfg, $day); $line = null; $pool = 'software';
        $cids = array_values(array_unique(array_filter(array_column($inv['lines'], 'contract_id'))));
        if (count($cids) === 1) { $q = $db->prepare('SELECT id, pool FROM itops_budget_lines WHERE contract_id=? AND fy=? ORDER BY id LIMIT 1'); $q->execute([$cids[0], $fy]); $bl = $q->fetch(PDO::FETCH_ASSOC); if ($bl) { $line = (int)$bl['id']; $pool = (string)$bl['pool'] ?: 'software'; } }
        $db->prepare("INSERT INTO itops_budget_actuals (fy, line_id, pool, site_id, period, amount, vendor_id, reference, note, created_by) VALUES (?,?,?,NULL,?,?,?,?,?,?)")
           ->execute([$fy, $line, $pool, substr($day, 0, 7), $inv['total'], $inv['vendor_id'], mb_substr('Invoice ' . $inv['invoice_number'], 0, 120), 'Recorded when the invoice was marked paid.', $by]);
        return (int)$db->lastInsertId();
    }

    /* ─────────────── reading a PDF with the AI integration ─────────────── */

    function itops_inv_ai_read(PDO $db, string $text, array $contracts): array {
        require_once __DIR__ . '/AiCall.php';   // BT121: the shared AI call
        if ($why = itops_ai_off($db)) throw new InvalidArgumentException($why . ' Enter the invoice by hand.');
        $text = mb_substr(trim(preg_replace('/[ \t]+/', ' ', $text)), 0, 30000); if (mb_strlen($text) < 40) throw new InvalidArgumentException('The PDF has no text to read (a scanned image?). Enter the invoice by hand.');
        $cl = implode("\n", array_map(fn($c) => '- id ' . $c['id'] . ': ' . $c['title'], array_slice($contracts, 0, 60)));
        $sys = 'You read a vendor invoice and reply with ONLY a JSON object, no prose, no code fences, with keys: invoice_number (string), invoice_date (YYYY-MM-DD), '
             . 'period_start and period_end (YYYY-MM-DD or empty), due_date (YYYY-MM-DD or empty), currency (3 letters), tax (number), total (number as printed), '
             . 'lines (array of {description, quantity (number), unit_price (number, before tax), billing ("monthly", "annual" or "one_time"), contract_id (one of the ids listed, or null)}). '
             . 'Copy numbers exactly as printed; never invent a value; leave a field empty when the invoice does not show it.';
        $user = "This vendor's contracts:\n" . ($cl ?: '(none)') . "\n\nInvoice text:\n" . $text;
        $j = itops_ai_json(itops_ai_ask($db, $sys, $user, 2000));
        if (!is_array($j)) throw new RuntimeException('The AI answer could not be read. Enter the invoice by hand.');
        $ids = array_map(fn($c) => (int)$c['id'], $contracts);
        $d = fn($v) => itops_inv_date($v) ?? '';
        $lines = [];
        foreach (array_slice((array)($j['lines'] ?? []), 0, 100) as $l) {
            if (!is_array($l)) continue;
            $cid = (int)($l['contract_id'] ?? 0);
            $lines[] = ['description' => mb_substr((string)($l['description'] ?? ''), 0, 300), 'quantity' => is_numeric($l['quantity'] ?? null) ? (float)$l['quantity'] : 1, 'unit_price' => is_numeric($l['unit_price'] ?? null) ? (float)$l['unit_price'] : 0,
                        'billing' => in_array($l['billing'] ?? '', ['monthly', 'annual', 'one_time'], true) ? $l['billing'] : 'monthly', 'contract_id' => in_array($cid, $ids, true) ? $cid : null];
        }
        $cur = strtoupper((string)($j['currency'] ?? ''));
        $o = ['invoice_number' => mb_substr((string)($j['invoice_number'] ?? ''), 0, 80), 'invoice_date' => $d($j['invoice_date'] ?? ''), 'period_start' => $d($j['period_start'] ?? ''), 'period_end' => $d($j['period_end'] ?? ''),
              'due_date' => $d($j['due_date'] ?? ''), 'currency' => preg_match('/^[A-Z]{3}$/', $cur) ? $cur : '', 'tax' => is_numeric($j['tax'] ?? null) ? (float)$j['tax'] : 0, 'printed_total' => is_numeric($j['total'] ?? null) ? (float)$j['total'] : null, 'lines' => $lines];
        $sum = round(array_sum(array_map(fn($l) => $l['quantity'] * $l['unit_price'], $lines)) + $o['tax'], 2);
        $o['total_matches'] = $o['printed_total'] === null ? null : abs($sum - $o['printed_total']) < 0.02;
        return $o;
    }
}
