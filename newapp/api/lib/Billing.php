<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT130: billing on the License Hub (License Manager → Billing). Only on the main installation; no money moves.
 *
 * Price list: per plan and per extra module, per cycle (monthly / annual): a base amount that includes a number of users,
 * plus an amount per extra user. Prices have an effective date; a license keeps the price it was issued with.
 * Billing of a license: its cycle, the amount per period (from the price list at issue, less a discount, or typed), the
 * first period's start. Charges are made for each period (a few days ahead), due a set number of days after the period
 * starts. Payments are recorded by hand (date, amount, method, reference); a charge is due, partly paid, paid or overdue.
 * A renewal or change ends the old license's billing; a revoked license stops billing. Nothing is deleted: a charge or
 * a payment is voided with a reason. Everything is in the Audit Log.
 * Soft tie: a client with overdue charges is flagged in Clients and Installations, and renewing its license warns first.
 */
require_once __DIR__ . '/LicenseManager.php';

if (!function_exists('itops_bill_table')) {

    function itops_bill_table(PDO $db): void {
        static $done = false; if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_prices (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, item_type VARCHAR(10) NOT NULL, item_key VARCHAR(60) NOT NULL,
            cycle VARCHAR(10) NOT NULL, base_amount DECIMAL(12,2) NOT NULL DEFAULT 0, included_users INT UNSIGNED NOT NULL DEFAULT 0, per_user DECIMAL(12,2) NOT NULL DEFAULT 0,
            effective_from DATE NOT NULL, created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL, INDEX idx_lmp_item (item_type, item_key, cycle, effective_from))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_billing (license_id INT UNSIGNED NOT NULL PRIMARY KEY, client_id INT UNSIGNED NULL, cycle VARCHAR(10) NOT NULL,
            amount DECIMAL(12,2) NOT NULL, list_amount DECIMAL(12,2) NOT NULL DEFAULT 0, discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0, breakdown TEXT NULL,
            starts DATE NOT NULL, next_start DATE NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, ended_at DATETIME NULL, created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_charges (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(20) NOT NULL, client_id INT UNSIGNED NULL,
            license_id INT UNSIGNED NULL, description VARCHAR(300) NOT NULL DEFAULT '', period_start DATE NULL, period_end DATE NULL, amount DECIMAL(12,2) NOT NULL,
            due_date DATE NOT NULL, voided TINYINT(1) NOT NULL DEFAULT 0, void_reason VARCHAR(300) NULL, created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL,
            UNIQUE KEY uq_lmch_number (number), UNIQUE KEY uq_lmch_period (license_id, period_start), INDEX idx_lmch_client (client_id))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_payments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, charge_id INT UNSIGNED NOT NULL, client_id INT UNSIGNED NULL,
            paid_on DATE NOT NULL, amount DECIMAL(12,2) NOT NULL, method VARCHAR(20) NOT NULL, reference VARCHAR(120) NOT NULL DEFAULT '', note VARCHAR(500) NOT NULL DEFAULT '',
            voided TINYINT(1) NOT NULL DEFAULT 0, void_reason VARCHAR(300) NULL, created_at DATETIME NOT NULL, created_by INT UNSIGNED NULL, INDEX idx_lmpay_charge (charge_id))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
    function itops_bill_methods(): array { return ['check' => 'Check', 'ach' => 'ACH / bank transfer', 'wire' => 'Wire', 'card' => 'Card', 'cash' => 'Cash', 'other' => 'Other']; }
    function itops_bill_cfg(PDO $db): array {
        $v = json_decode((string)itops_lm_setting($db, 'lm_bill_cfg'), true);
        return (is_array($v) ? $v : []) + ['due_days' => 15, 'lead_days' => 7, 'currency' => 'USD'];
    }
    function itops_bill_money($v): string { return number_format((float)$v, 2, '.', ''); }
    /** An amount from a form: 0 to 9,999,999.99 with up to two decimals. Throws InvalidArgumentException. */
    function itops_bill_amount($v, string $label, bool $allowZero = true): string {
        $s = trim(str_replace([',', '$'], '', (string)$v));
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s)) throw new InvalidArgumentException($label . ' is an amount like 125 or 125.50.');
        if (!$allowZero && (float)$s <= 0) throw new InvalidArgumentException($label . ' must be more than 0.');
        return itops_bill_money($s);
    }

    // ───────────── price list ─────────────

    /** The price of one item on a date (null when none is set). */
    function itops_bill_price(PDO $db, string $type, string $key, string $cycle, ?string $on = null): ?array {
        $q = $db->prepare('SELECT * FROM itops_lm_prices WHERE item_type=? AND item_key=? AND cycle=? AND effective_from<=? ORDER BY effective_from DESC, id DESC LIMIT 1');
        $q->execute([$type, $key, $cycle, $on ?? date('Y-m-d')]); $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
    /** The whole list as it stands today, plus prices that start later. */
    function itops_bill_price_list(PDO $db): array {
        itops_bill_table($db); $out = []; $today = date('Y-m-d');
        $items = []; foreach (itops_plans() as $k => $p) $items[] = ['plan', $k, $p[0]]; foreach (itops_modules_catalog() as $k => $m) $items[] = ['module', $k, $m[0]];
        foreach ($items as [$t, $k, $label]) foreach (['monthly', 'annual'] as $c) {
            $cur = itops_bill_price($db, $t, $k, $c);
            $nx = $db->prepare('SELECT * FROM itops_lm_prices WHERE item_type=? AND item_key=? AND cycle=? AND effective_from>? ORDER BY effective_from LIMIT 1'); $nx->execute([$t, $k, $c, $today]); $nx = $nx->fetch(PDO::FETCH_ASSOC);
            $f = fn($r) => $r ? ['base_amount' => itops_bill_money($r['base_amount']), 'included_users' => (int)$r['included_users'], 'per_user' => itops_bill_money($r['per_user']), 'effective_from' => $r['effective_from']] : null;
            $out[] = ['item_type' => $t, 'item_key' => $k, 'label' => $label, 'cycle' => $c, 'current' => $f($cur), 'next' => $f($nx)];
        }
        return $out;
    }
    /** Sets a price from a date (today when empty). Throws InvalidArgumentException. */
    function itops_bill_set_price(PDO $db, array $b, int $by): array {
        itops_bill_table($db);
        $t = (string)($b['item_type'] ?? ''); $k = (string)($b['item_key'] ?? ''); $c = (string)($b['cycle'] ?? '');
        if (!in_array($t, ['plan', 'module'], true) || ($t === 'plan' && !isset(itops_plans()[$k])) || ($t === 'module' && !isset(itops_modules_catalog()[$k]))) throw new InvalidArgumentException('Unknown plan or module.');
        if (!in_array($c, ['monthly', 'annual'], true)) throw new InvalidArgumentException('The cycle is monthly or annual.');
        $base = itops_bill_amount($b['base_amount'] ?? '', 'The base price'); $per = itops_bill_amount($b['per_user'] ?? '0', 'The price per extra user');
        $inc = $b['included_users'] ?? 0; if (!is_int($inc) && !(is_string($inc) && ctype_digit($inc))) throw new InvalidArgumentException('Users included is a whole number.'); $inc = min(100000, (int)$inc);
        $from = trim((string)($b['effective_from'] ?? '')); if ($from === '') $from = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !checkdate((int)substr($from, 5, 2), (int)substr($from, 8, 2), (int)substr($from, 0, 4))) throw new InvalidArgumentException('The date is YYYY-MM-DD.');
        if ($from < date('Y-m-d')) throw new InvalidArgumentException('A price starts today or later (licenses already issued keep their price).');
        $was = itops_bill_price($db, $t, $k, $c, $from);
        $db->prepare('DELETE FROM itops_lm_prices WHERE item_type=? AND item_key=? AND cycle=? AND effective_from=?')->execute([$t, $k, $c, $from]);   // one price per item and date
        $db->prepare('INSERT INTO itops_lm_prices (item_type, item_key, cycle, base_amount, included_users, per_user, effective_from, created_at, created_by) VALUES (?,?,?,?,?,?,?,NOW(),?)')
           ->execute([$t, $k, $c, $base, $inc, $per, $from, $by ?: null]);
        $d = fn($r) => $r ? itops_bill_money($r['base_amount']) . ' incl. ' . (int)$r['included_users'] . ' users + ' . itops_bill_money($r['per_user']) . '/user' : 'none';
        audit_log('lm_price_set', 'license', null, ucfirst($t) . ' ' . $k . ' · ' . $c, ['Price' => $d($was)], ['Price' => $base . ' incl. ' . $inc . ' users + ' . $per . '/user', 'From' => $from]);
        return ['item_type' => $t, 'item_key' => $k, 'cycle' => $c, 'effective_from' => $from];
    }
    /** Price of one item for a number of users (0 = no limit: the base only). */
    function itops_bill_item_amount(array $p, int $users): float {
        $extra = $users > 0 ? max(0, $users - (int)$p['included_users']) : 0;
        return round((float)$p['base_amount'] + $extra * (float)$p['per_user'], 2);
    }
    /** The list price of a license (its plan and extra modules) for a cycle: ['amount', 'lines' => [[label, amount]], 'missing' => [labels]]. */
    function itops_bill_quote(PDO $db, string $plan, array $modules, int $users, string $cycle): array {
        itops_bill_table($db); $lines = []; $miss = []; $sum = 0.0;
        $planMods = $plan !== '' && isset(itops_plans()[$plan]) ? itops_plans()[$plan][2] : [];
        if ($plan !== '') { $p = itops_bill_price($db, 'plan', $plan, $cycle); $l = itops_plans()[$plan][0] ?? $plan;
            if ($p) { $a = itops_bill_item_amount($p, $users); $lines[] = [$l, itops_bill_money($a)]; $sum += $a; } else $miss[] = $l; }
        foreach ($modules as $m) { if (in_array($m, $planMods, true)) continue; $p = itops_bill_price($db, 'module', $m, $cycle); $l = itops_modules_catalog()[$m][0] ?? $m;
            if ($p) { $a = itops_bill_item_amount($p, $users); $lines[] = [$l, itops_bill_money($a)]; $sum += $a; } else $miss[] = $l; }
        return ['amount' => itops_bill_money($sum), 'lines' => $lines, 'missing' => $miss];
    }

    // ───────────── billing of a license ─────────────

    /**
     * Starts billing for a license just issued. $b: cycle (monthly|annual|''), discount_pct, amount (typed, optional),
     * starts (optional, default the term start). Ends the billing of the license it replaces. Returns the billing row or null.
     */
    function itops_bill_start(PDO $db, array $lic, array $b, int $by, ?int $replaces): ?array {
        itops_bill_table($db);
        if ($replaces) itops_bill_end($db, $replaces, 'replaced by ' . $lic['serial']);
        $cycle = (string)($b['cycle'] ?? ''); if ($cycle === '') return null;
        if (!in_array($cycle, ['monthly', 'annual'], true)) throw new InvalidArgumentException('The billing cycle is monthly, annual or none.');
        $q = itops_bill_quote($db, (string)$lic['plan'], (array)$lic['modules'], (int)$lic['users'], $cycle);
        $disc = trim((string)($b['discount_pct'] ?? '0')); if ($disc === '') $disc = '0';
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $disc) || (float)$disc > 100) throw new InvalidArgumentException('The discount is a percentage from 0 to 100.');
        $typed = trim((string)($b['amount'] ?? ''));
        $amount = $typed !== '' ? itops_bill_amount($typed, 'The amount per period') : itops_bill_money(round((float)$q['amount'] * (100 - (float)$disc) / 100, 2));
        if ($typed === '' && $q['missing']) throw new InvalidArgumentException('No ' . $cycle . ' price is set for: ' . implode(', ', $q['missing']) . '. Set it in Billing → Price list, or type the amount.');
        $starts = trim((string)($b['starts'] ?? '')) ?: (string)$lic['term_start'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts)) throw new InvalidArgumentException('The billing start is YYYY-MM-DD.');
        $db->prepare('REPLACE INTO itops_lm_billing (license_id, client_id, cycle, amount, list_amount, discount_pct, breakdown, starts, next_start, active, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,1,NOW(),?)')
           ->execute([(int)$lic['id'], $lic['client_id'] ?? null, $cycle, $amount, $q['amount'], $typed !== '' ? 0 : $disc, json_encode($q['lines']), $starts, $starts, $by ?: null]);
        audit_log('lm_billing_started', 'license', (int)$lic['id'], $lic['serial'] . ' · ' . $lic['licensee'], null,
                  ['Cycle' => $cycle, 'Amount' => $amount, 'List price' => $q['amount'], 'Discount' => $typed !== '' ? 'typed amount' : $disc . '%', 'From' => $starts]);
        itops_bill_make_charges($db, $by, (int)$lic['id']);
        return itops_bill_row($db, (int)$lic['id']);
    }
    /** Checks the billing part of a license before it is issued (so a mistake issues nothing). Throws InvalidArgumentException. */
    function itops_bill_check(PDO $db, array $p, array $b): void {
        itops_bill_table($db);
        $cycle = (string)($b['cycle'] ?? ''); if ($cycle === '') return;
        if (!in_array($cycle, ['monthly', 'annual'], true)) throw new InvalidArgumentException('The billing cycle is monthly, annual or none.');
        $disc = trim((string)($b['discount_pct'] ?? '0')); if ($disc !== '' && (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $disc) || (float)$disc > 100)) throw new InvalidArgumentException('The discount is a percentage from 0 to 100.');
        $typed = trim((string)($b['amount'] ?? ''));
        if ($typed !== '') { itops_bill_amount($typed, 'The amount per period'); }
        else { $q = itops_bill_quote($db, (string)$p['plan'], (array)$p['modules'], (int)$p['users'], $cycle);
               if ($q['missing']) throw new InvalidArgumentException('No ' . $cycle . ' price is set for: ' . implode(', ', $q['missing']) . '. Set it in Billing → Price list, or type the amount.'); }
        $st = trim((string)($b['starts'] ?? '')); if ($st !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $st) || !checkdate((int)substr($st, 5, 2), (int)substr($st, 8, 2), (int)substr($st, 0, 4)))) throw new InvalidArgumentException('The billing start is YYYY-MM-DD.');
    }
    function itops_bill_row(PDO $db, int $licId): ?array { $q = $db->prepare('SELECT * FROM itops_lm_billing WHERE license_id=?'); $q->execute([$licId]); $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    function itops_bill_end(PDO $db, int $licId, string $why): void {
        itops_bill_table($db);
        $u = $db->prepare('UPDATE itops_lm_billing SET active=0, ended_at=NOW() WHERE license_id=? AND active=1'); $u->execute([$licId]);
        if ($u->rowCount()) audit_log('lm_billing_ended', 'license', $licId, 'Billing', ['Billing' => 'active'], ['Billing' => 'ended', 'Why' => $why]);
    }
    /** The next period's start: one month (or year) after $cur, on the billing's own day of the month (the 31st becomes the 30th, 28th… in shorter months). */
    function itops_bill_next_start(string $cur, string $starts, string $cycle): string {
        $y = (int)substr($cur, 0, 4); $m = (int)substr($cur, 5, 2) + ($cycle === 'annual' ? 12 : 1);
        while ($m > 12) { $m -= 12; $y++; }
        $d = min((int)substr($starts, 8, 2), (int)date('t', mktime(0, 0, 0, $m, 1, $y)));
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
    function itops_bill_period_end(string $start, string $starts, string $cycle): string { return date('Y-m-d', strtotime(itops_bill_next_start($start, $starts, $cycle) . ' -1 day')); }
    function itops_bill_next_number(PDO $db): string {
        $y = date('Y'); $q = $db->prepare('SELECT number FROM itops_lm_charges WHERE number LIKE ? ORDER BY id DESC LIMIT 1'); $q->execute(['BIL-' . $y . '-%']);
        return sprintf('BIL-%s-%04d', $y, (int)substr((string)$q->fetchColumn(), 9) + 1);
    }
    /**
     * Makes the charges of every active billing whose next period starts within the lead days (one per period; the
     * license's term end, when set, ends billing). Returns the number of charges made.
     */
    function itops_bill_make_charges(PDO $db, int $by = 0, ?int $onlyLicense = null): int {
        itops_bill_table($db); $cfg = itops_bill_cfg($db); $made = 0;
        $limit = date('Y-m-d', strtotime('+' . (int)$cfg['lead_days'] . ' days'));
        $sql = 'SELECT b.*, l.serial, l.licensee, l.term_end, l.status FROM itops_lm_billing b JOIN itops_lm_licenses l ON l.id=b.license_id WHERE b.active=1' . ($onlyLicense ? ' AND b.license_id=' . (int)$onlyLicense : '');
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if ($b['status'] !== 'issued') { itops_bill_end($db, (int)$b['license_id'], 'license ' . $b['status']); continue; }
            $next = (string)$b['next_start']; $n = 0;
            while ($next <= $limit && $n < 60) {
                // a period that would start on the term's last day or later is not charged (no one-day periods)
                if ($b['term_end'] && $next >= $b['term_end']) { itops_bill_end($db, (int)$b['license_id'], 'license term ended'); break; }
                $end = itops_bill_period_end($next, (string)$b['starts'], (string)$b['cycle']); if ($b['term_end'] && $end > $b['term_end']) $end = (string)$b['term_end'];
                $due = date('Y-m-d', strtotime($next . ' +' . (int)$cfg['due_days'] . ' days'));
                $desc = 'License ' . $b['serial'] . ' · ' . ($b['cycle'] === 'annual' ? 'year' : 'month') . ' ' . date('m/d/Y', strtotime($next)) . ' – ' . date('m/d/Y', strtotime($end));
                try {
                    $num = itops_bill_next_number($db);
                    $db->prepare('INSERT INTO itops_lm_charges (number, client_id, license_id, description, period_start, period_end, amount, due_date, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,NOW(),?)')
                       ->execute([$num, $b['client_id'], (int)$b['license_id'], $desc, $next, $end, $b['amount'], $due, $by ?: null]);
                    audit_log('lm_charge_made', 'license', (int)$db->lastInsertId(), $num . ' · ' . $b['licensee'], null, ['Amount' => itops_bill_money($b['amount']), 'Due' => $due, 'Period' => $next . ' – ' . $end]);
                    $made++;
                } catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; }   // that period was charged already
                $next = itops_bill_next_start($next, (string)$b['starts'], (string)$b['cycle']); $n++;
                $db->prepare('UPDATE itops_lm_billing SET next_start=? WHERE license_id=?')->execute([$next, (int)$b['license_id']]);
            }
        }
        return $made;
    }
    /** A charge typed by hand (setup fee, services…). */
    function itops_bill_add_charge(PDO $db, array $b, int $by): int {
        itops_bill_table($db);
        $cid = (int)($b['client_id'] ?? 0); $q = $db->prepare('SELECT name FROM itops_lm_clients WHERE id=?'); $q->execute([$cid]); $cn = $q->fetchColumn();
        if ($cn === false) throw new InvalidArgumentException('Choose the client.');
        $amt = itops_bill_amount($b['amount'] ?? '', 'The amount', false);
        $desc = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($b['description'] ?? ''))); if ($desc === '' || mb_strlen($desc) > 300) throw new InvalidArgumentException('Say what the charge is for (up to 300 characters).');
        $due = trim((string)($b['due_date'] ?? '')) ?: date('Y-m-d', strtotime('+' . (int)itops_bill_cfg($db)['due_days'] . ' days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new InvalidArgumentException('The due date is YYYY-MM-DD.');
        $num = itops_bill_next_number($db);
        $db->prepare('INSERT INTO itops_lm_charges (number, client_id, license_id, description, amount, due_date, created_at, created_by) VALUES (?,?,NULL,?,?,?,NOW(),?)')->execute([$num, $cid, $desc, $amt, $due, $by ?: null]);
        $id = (int)$db->lastInsertId();
        audit_log('lm_charge_made', 'license', $id, $num . ' · ' . $cn, null, ['Amount' => $amt, 'Due' => $due, 'For' => $desc, 'By' => 'hand']);
        return $id;
    }

    // ───────────── payments and balances ─────────────

    /** Charges with what was paid and their status (due | partial | paid | overdue | void). */
    function itops_bill_charges(PDO $db, array $f = []): array {
        itops_bill_table($db); $w = ['1=1']; $a = [];
        if (!empty($f['client_id'])) { $w[] = 'c.client_id=?'; $a[] = (int)$f['client_id']; }
        if (!empty($f['charge_id'])) { $w[] = 'c.id=?'; $a[] = (int)$f['charge_id']; }
        $q = $db->prepare("SELECT c.*, cl.name AS client_name, l.serial, (SELECT COALESCE(SUM(p.amount),0) FROM itops_lm_payments p WHERE p.charge_id=c.id AND p.voided=0) AS paid
            FROM itops_lm_charges c LEFT JOIN itops_lm_clients cl ON cl.id=c.client_id LEFT JOIN itops_lm_licenses l ON l.id=c.license_id WHERE " . implode(' AND ', $w) . ' ORDER BY c.due_date DESC, c.id DESC');
        $q->execute($a); $today = date('Y-m-d'); $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $bal = round((float)$c['amount'] - (float)$c['paid'], 2);
            $st = $c['voided'] ? 'void' : ($bal <= 0 ? 'paid' : ($c['due_date'] < $today ? 'overdue' : ((float)$c['paid'] > 0 ? 'partial' : 'due')));
            $late = $st === 'overdue' ? (int)floor((strtotime($today) - strtotime($c['due_date'])) / 86400) : 0;
            $out[] = ['id' => (int)$c['id'], 'number' => $c['number'], 'client_id' => $c['client_id'] === null ? null : (int)$c['client_id'], 'client_name' => (string)$c['client_name'],
                      'license_id' => $c['license_id'] === null ? null : (int)$c['license_id'], 'serial' => (string)$c['serial'], 'description' => $c['description'],
                      'period_start' => $c['period_start'], 'period_end' => $c['period_end'], 'amount' => itops_bill_money($c['amount']), 'paid' => itops_bill_money($c['paid']),
                      'balance' => itops_bill_money(max(0, $bal)), 'due_date' => $c['due_date'], 'status' => $st, 'days_late' => $late, 'void_reason' => $c['void_reason'], 'created_at' => $c['created_at']];
        }
        if (!empty($f['status'])) $out = array_values(array_filter($out, fn($c) => $f['status'] === 'open' ? in_array($c['status'], ['due', 'partial', 'overdue'], true) : $c['status'] === $f['status']));
        return $out;
    }
    /** Records a payment against a charge. Throws InvalidArgumentException. Returns the payment id. */
    function itops_bill_pay(PDO $db, int $chargeId, array $b, int $by): int {
        $c = itops_bill_charges($db, ['charge_id' => $chargeId])[0] ?? null;
        if (!$c) throw new InvalidArgumentException('Unknown charge.');
        if ($c['status'] === 'void') throw new InvalidArgumentException('That charge was voided.');
        $amt = itops_bill_amount($b['amount'] ?? '', 'The amount', false);
        $on = trim((string)($b['paid_on'] ?? '')) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > date('Y-m-d')) throw new InvalidArgumentException('The payment date is YYYY-MM-DD, today or earlier.');
        $m = (string)($b['method'] ?? ''); if (!isset(itops_bill_methods()[$m])) throw new InvalidArgumentException('Choose how it was paid.');
        $ref = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($b['reference'] ?? ''))), 0, 120); $note = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($b['note'] ?? ''))), 0, 500);
        $over = (float)$amt > (float)$c['balance'] + 0.001;
        if ($over && ($b['confirm'] ?? '') !== 'OVERPAY') throw new InvalidArgumentException('That is more than the balance (' . $c['balance'] . '). Send confirm "OVERPAY" to record it anyway.');
        $db->prepare('INSERT INTO itops_lm_payments (charge_id, client_id, paid_on, amount, method, reference, note, created_at, created_by) VALUES (?,?,?,?,?,?,?,NOW(),?)')
           ->execute([$chargeId, $c['client_id'], $on, $amt, $m, $ref, $note, $by ?: null]);
        $id = (int)$db->lastInsertId();
        audit_log('lm_payment_recorded', 'license', $id, $c['number'] . ' · ' . $c['client_name'], ['Balance' => $c['balance']],
                  ['Paid' => $amt, 'On' => $on, 'Method' => itops_bill_methods()[$m], 'Reference' => $ref ?: '—', 'Balance' => itops_bill_money(max(0, (float)$c['balance'] - (float)$amt))]);
        return $id;
    }
    function itops_bill_payments(PDO $db, int $limit = 200): array {
        itops_bill_table($db);
        $rows = $db->query('SELECT p.*, c.number, cl.name AS client_name FROM itops_lm_payments p JOIN itops_lm_charges c ON c.id=p.charge_id LEFT JOIN itops_lm_clients cl ON cl.id=p.client_id ORDER BY p.paid_on DESC, p.id DESC LIMIT ' . max(1, min(2000, $limit)))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['charge_id'] = (int)$r['charge_id']; $r['amount'] = itops_bill_money($r['amount']); $r['voided'] = (bool)$r['voided']; $r['method_label'] = itops_bill_methods()[$r['method']] ?? $r['method']; } unset($r);
        return $rows;
    }
    /** Voids a charge (no payment on it) or a payment, with a reason. */
    function itops_bill_void(PDO $db, string $what, int $id, string $why): void {
        $why = trim($why); if ($why === '' || mb_strlen($why) > 300) throw new InvalidArgumentException('Say why (up to 300 characters).');
        if ($what === 'charge') {
            $c = itops_bill_charges($db, ['charge_id' => $id])[0] ?? null; if (!$c) throw new InvalidArgumentException('Unknown charge.');
            if ($c['status'] === 'void') throw new InvalidArgumentException('It is already void.');
            if ((float)$c['paid'] > 0) throw new InvalidArgumentException('It has payments: void them first.');
            $db->prepare('UPDATE itops_lm_charges SET voided=1, void_reason=? WHERE id=?')->execute([$why, $id]);
            audit_log('lm_charge_voided', 'license', $id, $c['number'] . ' · ' . $c['client_name'], ['Amount' => $c['amount']], ['Status' => 'void', 'Why' => $why]);
        } else {
            $q = $db->prepare('SELECT p.*, c.number FROM itops_lm_payments p JOIN itops_lm_charges c ON c.id=p.charge_id WHERE p.id=?'); $q->execute([$id]); $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new InvalidArgumentException('Unknown payment.'); if ($p['voided']) throw new InvalidArgumentException('It is already void.');
            $db->prepare('UPDATE itops_lm_payments SET voided=1, void_reason=? WHERE id=?')->execute([$why, $id]);
            audit_log('lm_payment_voided', 'license', $id, $p['number'], ['Paid' => itops_bill_money($p['amount'])], ['Status' => 'void', 'Why' => $why]);
        }
    }
    /** Per client: open balance, overdue amount, oldest days late; and the aging of everything open. */
    function itops_bill_summary(PDO $db): array {
        $ch = itops_bill_charges($db, ['status' => 'open']); $cl = []; $age = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0];
        $soon = date('Y-m-d', strtotime('+7 days')); $dueSoon = 0;
        foreach ($ch as $c) {
            $b = (float)$c['balance']; $k = (string)($c['client_id'] ?? 0);
            $cl[$k] = ($cl[$k] ?? ['client_id' => $c['client_id'], 'client_name' => $c['client_name'], 'open' => 0.0, 'overdue' => 0.0, 'days_late' => 0]);
            $cl[$k]['open'] += $b;
            if ($c['status'] === 'overdue') { $cl[$k]['overdue'] += $b; $cl[$k]['days_late'] = max($cl[$k]['days_late'], $c['days_late']); }
            $d = $c['days_late']; $age[$c['status'] !== 'overdue' ? 'current' : ($d <= 30 ? 'd30' : ($d <= 60 ? 'd60' : ($d <= 90 ? 'd90' : 'd90p')))] += $b;
            if ($c['status'] !== 'overdue' && $c['due_date'] <= $soon) $dueSoon++;
        }
        foreach ($cl as &$x) { $x['open'] = itops_bill_money($x['open']); $x['overdue'] = itops_bill_money($x['overdue']); } unset($x);
        return ['clients' => array_values($cl), 'aging' => array_map('itops_bill_money', $age), 'open_total' => itops_bill_money(array_sum(array_map(fn($c) => (float)$c['balance'], $ch))),
                'overdue_count' => count(array_filter($ch, fn($c) => $c['status'] === 'overdue')), 'due_soon' => $dueSoon];
    }
    /** client_id => ['overdue', 'days_late'] for the clients with overdue charges (the soft tie). */
    function itops_bill_overdue_clients(PDO $db): array {
        try { $o = []; foreach (itops_bill_summary($db)['clients'] as $c) if ((float)$c['overdue'] > 0 && $c['client_id'] !== null) $o[(int)$c['client_id']] = ['overdue' => $c['overdue'], 'days_late' => $c['days_late']]; return $o; }
        catch (Throwable $e) { return []; }
    }
    /** CSV of the charges (with paid and balance) or of the payments. */
    function itops_bill_csv(PDO $db, string $what): string {
        $h = fopen('php://temp', 'w+');
        $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;   // no formulas in a spreadsheet
        if ($what === 'payments') {
            fputcsv($h, ['Paid on', 'Charge', 'Client', 'Amount', 'Method', 'Reference', 'Note', 'Void', 'Void reason']);
            foreach (itops_bill_payments($db, 2000) as $p) fputcsv($h, array_map($safe, [date('m/d/Y', strtotime($p['paid_on'])), $p['number'], $p['client_name'], $p['amount'], $p['method_label'], $p['reference'], $p['note'], $p['voided'] ? 'yes' : '', (string)$p['void_reason']]));
        } else {
            fputcsv($h, ['Number', 'Client', 'License', 'Description', 'Period start', 'Period end', 'Amount', 'Paid', 'Balance', 'Due', 'Status', 'Days late']);
            foreach (itops_bill_charges($db) as $c) fputcsv($h, array_map($safe, [$c['number'], $c['client_name'], $c['serial'], $c['description'], $c['period_start'] ? date('m/d/Y', strtotime($c['period_start'])) : '', $c['period_end'] ? date('m/d/Y', strtotime($c['period_end'])) : '', $c['amount'], $c['paid'], $c['balance'], date('m/d/Y', strtotime($c['due_date'])), $c['status'], $c['days_late']]));
        }
        rewind($h); $s = stream_get_contents($h); fclose($h); return $s;
    }
    /** Once a day on the hub: make the charges coming due, and tell the Super Admins what is due this week or overdue. */
    function itops_bill_daily(PDO $db): string {
        itops_bill_table($db);
        if (itops_lm_setting($db, 'lm_bill_day') === date('Y-m-d')) return '';
        itops_lm_set($db, 'lm_bill_day', date('Y-m-d'));
        $made = itops_bill_make_charges($db, 0); $s = itops_bill_summary($db);
        if ($made || $s['overdue_count'] || $s['due_soon']) {
            $msg = ($made ? $made . ' new charge' . ($made === 1 ? '' : 's') . '. ' : '') . ($s['due_soon'] ? $s['due_soon'] . ' due within 7 days. ' : '') . ($s['overdue_count'] ? $s['overdue_count'] . ' overdue.' : '');
            try { $ins = $db->prepare("INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
                  foreach ($db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE u.status='active' AND r.slug='super_admin'")->fetchAll(PDO::FETCH_COLUMN) as $u) $ins->execute([(int)$u, 'Billing', trim($msg), 'alert', '#license_manager']); } catch (Throwable $e) {}
            return trim($msg);
        }
        return 'Nothing due.';
    }
}
