<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Manual invoicing and a payment ledger, for operators who collect payment outside the app.
 * Create an invoice for a client organization, email it, and record payments as they arrive.
 * Amounts are whole cents throughout; formatting happens at the view layer.
 *
 * Platform-admin surface — callers gate on isPlatformAdmin before invoking.
 */
final class InvoiceService
{
    public const METHODS = ['check' => 'Check', 'ach' => 'ACH / bank transfer', 'wire' => 'Wire',
                            'card' => 'Card', 'cash' => 'Cash', 'other' => 'Other'];

    // ---- reads ----------------------------------------------------------

    /** All invoices (optionally for one tenant), newest first, with the client name joined. */
    public static function all(?string $tenantId = null, ?string $status = null): array
    {
        $sql = "SELECT i.*, t.name AS client_name FROM invoices i
                JOIN tenants t ON t.id = i.tenant_id WHERE 1=1";
        $args = [];
        if ($tenantId !== null) { $sql .= ' AND i.tenant_id = ?'; $args[] = $tenantId; }
        if ($status !== null)   { $sql .= ' AND i.status = ?';   $args[] = $status; }
        $sql .= ' ORDER BY i.created_at DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public static function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT i.*, t.name AS client_name FROM invoices i
             JOIN tenants t ON t.id = i.tenant_id WHERE i.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function items(string $invoiceId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    public static function paymentsFor(string $invoiceId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM payments WHERE invoice_id = ? ORDER BY received_on DESC, created_at DESC'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /** Dashboard rollup: outstanding, overdue, collected this month. */
    public static function summary(): array
    {
        $pdo = Database::pdo();
        $out = (int) $pdo->query(
            "SELECT COALESCE(SUM(total_cents - paid_cents),0) FROM invoices
             WHERE status IN ('sent','partial')"
        )->fetchColumn();
        $overdue = (int) $pdo->query(
            "SELECT COALESCE(SUM(total_cents - paid_cents),0) FROM invoices
             WHERE status IN ('sent','partial') AND due_date IS NOT NULL AND due_date < @flx_today"
        )->fetchColumn();
        $collected = (int) $pdo->query(
            "SELECT COALESCE(SUM(amount_cents),0) FROM payments
             WHERE received_on >= DATE_FORMAT(@flx_today,'%Y-%m-01')"
        )->fetchColumn();
        return ['outstanding_cents' => $out, 'overdue_cents' => $overdue, 'collected_cents' => $collected];
    }

    /**
     * The ledger: invoices (charges) and payments (credits) for one tenant, merged in time
     * order with a running balance. This is the "statement" view of an account.
     */
    public static function ledger(string $tenantId): array
    {
        $pdo = Database::pdo();
        $inv = $pdo->prepare("SELECT id, number, total_cents, issue_date AS on_date, created_at
                              FROM invoices WHERE tenant_id = ? AND status <> 'void'");
        $inv->execute([$tenantId]);
        $rows = [];
        foreach ($inv->fetchAll() as $r) {
            $rows[] = ['date' => $r['on_date'], 'ts' => $r['created_at'], 'type' => 'invoice',
                       'label' => 'Invoice ' . $r['number'], 'ref_id' => $r['id'],
                       'charge_cents' => (int) $r['total_cents'], 'credit_cents' => 0];
        }
        $pay = $pdo->prepare('SELECT id, amount_cents, received_on AS on_date, method, reference, created_at
                              FROM payments WHERE tenant_id = ?');
        $pay->execute([$tenantId]);
        foreach ($pay->fetchAll() as $r) {
            $label = 'Payment' . ($r['reference'] ? ' · ' . $r['reference'] : '') . ' (' . ($r['method']) . ')';
            $rows[] = ['date' => $r['on_date'], 'ts' => $r['created_at'], 'type' => 'payment',
                       'label' => $label, 'ref_id' => $r['id'],
                       'charge_cents' => 0, 'credit_cents' => (int) $r['amount_cents']];
        }
        usort($rows, fn($a, $b) => [$a['date'], $a['ts']] <=> [$b['date'], $b['ts']]);
        $bal = 0;
        foreach ($rows as &$r) { $bal += $r['charge_cents'] - $r['credit_cents']; $r['balance_cents'] = $bal; }
        return ['rows' => $rows, 'balance_cents' => $bal];
    }

    // ---- writes ---------------------------------------------------------

    /** Create a draft invoice with line items. $items: [[description, quantity, unit_cents], ...]. */
    public static function create(string $tenantId, array $items, ?string $dueDate,
        ?string $notes, ?string $byUserId): array
    {
        $tenant = Database::pdo()->prepare('SELECT id FROM tenants WHERE id = ? LIMIT 1');
        $tenant->execute([$tenantId]);
        if (!$tenant->fetchColumn()) return [false, 'Choose a client organization.', null];

        $clean = [];
        foreach ($items as $it) {
            $desc = trim((string) ($it['description'] ?? ''));
            if ($desc === '') continue;
            $qty = max(0, (float) ($it['quantity'] ?? 1));
            $unit = (int) round(((float) ($it['unit'] ?? 0)) * 100);
            $clean[] = ['description' => $desc, 'quantity' => $qty, 'unit_cents' => $unit,
                        'amount_cents' => (int) round($qty * $unit)];
        }
        if (!$clean) return [false, 'Add at least one line item.', null];

        $subtotal = array_sum(array_column($clean, 'amount_cents'));
        $pdo = Database::pdo();
        $id = uuid4(); $now = utc_now();
        $number = self::nextNumber();

        $pdo->prepare(
            'INSERT INTO invoices (id, tenant_id, number, status, subtotal_cents, total_cents,
                issue_date, due_date, notes, created_by, created_at)
             VALUES (?,?,?,\'draft\',?,?,?,?,?,?,?)'
        )->execute([$id, $tenantId, $number, $subtotal, $subtotal,
            today_local(), $dueDate ?: null, $notes ?: null, $byUserId, $now]);

        $sort = 0;
        foreach ($clean as $it) {
            $pdo->prepare(
                'INSERT INTO invoice_items (id, tenant_id, invoice_id, description, quantity, unit_cents, amount_cents, sort_order)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([uuid4(), $tenantId, $id, $it['description'], $it['quantity'],
                $it['unit_cents'], $it['amount_cents'], $sort++]);
        }
        return [true, 'Invoice ' . $number . ' created.', $id];
    }

    /** Mark an invoice sent and email it to the client's owner. */
    public static function send(string $invoiceId): array
    {
        $inv = self::find($invoiceId);
        if (!$inv) return [false, 'Invoice not found.'];
        if ($inv['status'] === 'void') return [false, 'This invoice is void.'];

        $pdo = Database::pdo();
        $pdo->prepare("UPDATE invoices SET status = IF(status='draft','sent',status), sent_at = COALESCE(sent_at, ?) WHERE id = ?")
            ->execute([utc_now(), $invoiceId]);

        // Email the owner via the outbox.
        $owner = $pdo->prepare(
            "SELECT full_name, email FROM users WHERE tenant_id = ? AND role='tenant_owner'
             ORDER BY created_at ASC LIMIT 1"
        );
        $owner->execute([$inv['tenant_id']]);
        $o = $owner->fetch();
        if ($o && !empty($o['email'])) {
            $items = self::items($invoiceId);
            $lines = '';
            foreach ($items as $it) {
                $lines .= '  ' . $it['description'] . ' — '
                        . self::money((int) $it['amount_cents']) . "\n";
            }
            $body = "Hello " . $o['full_name'] . ",\n\n"
                  . "Invoice " . $inv['number'] . " from FlxCoreHC is ready.\n\n"
                  . $lines . "\n"
                  . "Total due: " . self::money((int) $inv['total_cents']) . "\n"
                  . ($inv['due_date'] ? "Due: " . $inv['due_date'] . "\n" : '')
                  . ($inv['notes'] ? "\n" . $inv['notes'] . "\n" : '')
                  . "\nPlease arrange payment per the terms provided. Thank you.\n";
            // Attach the invoice as a PDF.
            $attach = null;
            try {
                $pdfBytes = self::pdf($invoiceId);
                if ($pdfBytes !== null) {
                    $attach = ['name' => $inv['number'] . '.pdf', 'mime' => 'application/pdf', 'bytes' => $pdfBytes];
                }
            } catch (\Throwable $e) { /* fall back to text-only if PDF fails */ }
            MailService::queue($o['email'], 'Invoice ' . $inv['number'] . ' from FlxCoreHC',
                $body, 'invoice', $o['full_name'], $inv['tenant_id'], $attach);
            MailService::flush();
        }
        return [true, 'Invoice ' . $inv['number'] . ' sent.'];
    }

    public static function void(string $invoiceId): array
    {
        Database::pdo()->prepare("UPDATE invoices SET status='void', voided_at=? WHERE id=? AND status<>'paid'")
            ->execute([utc_now(), $invoiceId]);
        return [true, 'Invoice voided.'];
    }

    /** Record a payment against an invoice (or on-account if invoiceId is null). */
    public static function recordPayment(string $tenantId, ?string $invoiceId, float $amount,
        string $method, ?string $reference, ?string $receivedOn, ?string $notes, ?string $byUserId): array
    {
        $cents = (int) round($amount * 100);
        if ($cents <= 0) return [false, 'Enter a payment amount greater than zero.'];
        $method = isset(self::METHODS[$method]) ? $method : 'other';

        $pdo = Database::pdo();
        if ($invoiceId !== null) {
            $inv = self::find($invoiceId);
            if (!$inv || $inv['tenant_id'] !== $tenantId) return [false, 'Invoice not found for this client.'];
        }
        $pdo->prepare(
            'INSERT INTO payments (id, tenant_id, invoice_id, amount_cents, method, reference, received_on, notes, recorded_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([uuid4(), $tenantId, $invoiceId, $cents, $method, $reference ?: null,
            $receivedOn ?: today_local(), $notes ?: null, $byUserId, utc_now()]);

        if ($invoiceId !== null) self::reconcile($invoiceId);
        return [true, t('Payment of {amount} recorded.', ['amount' => self::money($cents)])];
    }

    /** Recompute paid_cents + status for an invoice from its payments. */
    private static function reconcile(string $invoiceId): void
    {
        $pdo = Database::pdo();
        $paid = (int) $pdo->query(
            'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE invoice_id = ' . $pdo->quote($invoiceId)
        )->fetchColumn();
        $inv = self::find($invoiceId);
        if (!$inv) return;
        $total = (int) $inv['total_cents'];
        if ($inv['status'] === 'void') { $status = 'void'; }
        elseif ($paid >= $total && $total > 0) { $status = 'paid'; }
        elseif ($paid > 0) { $status = 'partial'; }
        else { $status = $inv['sent_at'] ? 'sent' : 'draft'; }
        $paidAt = ($status === 'paid') ? utc_now() : null;
        $pdo->prepare('UPDATE invoices SET paid_cents = ?, status = ?, paid_at = COALESCE(?, paid_at) WHERE id = ?')
            ->execute([$paid, $status, $paidAt, $invoiceId]);
    }

    /** Next sequential invoice number, atomically. */
    private static function nextNumber(): string
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE invoice_counter SET next_seq = next_seq + 1 WHERE id = 1')->execute();
        $seq = (int) $pdo->query('SELECT next_seq - 1 FROM invoice_counter WHERE id = 1')->fetchColumn();
        return 'INV-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    public static function money(int $cents, string $currency = 'USD'): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    /**
     * Render an invoice as a dependency-free PDF (MiniPdf). Returns the raw PDF bytes.
     * $brand is optional ['name' => ..., 'from' => ...] for the letterhead.
     */
    public static function pdf(string $invoiceId, array $brand = []): ?string
    {
        $inv = self::find($invoiceId);
        if (!$inv) return null;
        $items = self::items($invoiceId);
        $payments = self::paymentsFor($invoiceId);

        $orgName = $brand['name'] ?? FLXCOREHC_APP_NAME;
        $ink   = [0.13, 0.16, 0.22];
        $gray  = [0.42, 0.46, 0.54];
        $navy  = [0.11, 0.16, 0.30];
        $M = 48.0; $R = MiniPdf::W - $M;

        $pdf = new MiniPdf();
        $pdf->page();

        // Header band.
        $pdf->rect(0, 0, MiniPdf::W, 92, $navy);
        $pdf->text($M, 40, $orgName, 17, true, [1, 1, 1]);
        $pdf->text($M, 62, 'Invoice', 11, false, [0.78, 0.84, 0.95]);
        $numTxt = $inv['number'];
        $pdf->text($R - MiniPdf::width($numTxt, 14), 40, $numTxt, 14, true, [1, 1, 1]);
        $statusTxt = strtoupper((string) $inv['status']);
        $pdf->text($R - MiniPdf::width($statusTxt, 9), 60, $statusTxt, 9, true, [0.78, 0.84, 0.95]);

        $y = 128.0;
        // Bill-to + meta.
        $pdf->text($M, $y, 'BILLED TO', 8, true, $gray);
        $pdf->text($M, $y + 16, (string) $inv['client_name'], 12, true, $ink);
        $metaX = 360.0;
        $pdf->text($metaX, $y, 'ISSUE DATE', 8, true, $gray);
        $pdf->text($metaX + 90, $y, (string) $inv['issue_date'], 10, false, $ink);
        $pdf->text($metaX, $y + 16, 'DUE DATE', 8, true, $gray);
        $pdf->text($metaX + 90, $y + 16, (string) ($inv['due_date'] ?: '—'), 10, false, $ink);
        $pdf->text($metaX, $y + 32, 'BALANCE DUE', 8, true, $gray);
        $bal = (int) $inv['total_cents'] - (int) $inv['paid_cents'];
        $pdf->text($metaX + 90, $y + 32, self::money($bal), 11, true, $ink);

        // Line-item table.
        $y = 196.0;
        $pdf->rect($M, $y - 12, $R - $M, 22, [0.95, 0.96, 0.98]);
        $pdf->text($M + 8, $y + 3, 'DESCRIPTION', 8.5, true, $gray);
        $pdf->text($R - 210, $y + 3, 'QTY', 8.5, true, $gray);
        $pdf->text($R - 150, $y + 3, 'UNIT', 8.5, true, $gray);
        $pdf->text($R - 60, $y + 3, 'AMOUNT', 8.5, true, $gray);
        $y += 26;
        foreach ($items as $it) {
            $pdf->text($M + 8, $y, (string) $it['description'], 10, false, $ink);
            $qty = rtrim(rtrim((string) $it['quantity'], '0'), '.');
            $pdf->text($R - 210, $y, $qty, 10, false, $ink);
            $pdf->text($R - 150, $y, self::money((int) $it['unit_cents']), 10, false, $ink);
            $amt = self::money((int) $it['amount_cents']);
            $pdf->text($R - MiniPdf::width($amt, 10), $y, $amt, 10, false, $ink);
            $y += 18;
            if ($y > 700) { $pdf->page(); $y = 60; }
        }
        // Totals.
        $pdf->line($M, $y, $R, $y, 0.6, [0.8, 0.82, 0.86]);
        $y += 18;
        $totTxt = self::money((int) $inv['total_cents']);
        $pdf->text($R - 150, $y, 'Total', 10.5, true, $ink);
        $pdf->text($R - MiniPdf::width($totTxt, 10.5), $y, $totTxt, 10.5, true, $ink);
        if ((int) $inv['paid_cents'] > 0) {
            $y += 16;
            $paidTxt = self::money((int) $inv['paid_cents']);
            $pdf->text($R - 150, $y, 'Paid', 10, false, $gray);
            $pdf->text($R - MiniPdf::width($paidTxt, 10), $y, $paidTxt, 10, false, $gray);
            $y += 16;
            $balTxt = self::money($bal);
            $pdf->text($R - 150, $y, 'Balance due', 10.5, true, $ink);
            $pdf->text($R - MiniPdf::width($balTxt, 10.5), $y, $balTxt, 10.5, true, $ink);
        }

        // Notes.
        if (!empty($inv['notes'])) {
            $y += 34;
            $pdf->text($M, $y, 'NOTES', 8, true, $gray);
            $pdf->wrap($M, $y + 14, $R - $M, (string) $inv['notes'], 9.5, false, $ink);
        }

        return $pdf->output();
    }
}
