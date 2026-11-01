<?php /** @var array $invoice */ /** @var array $items */ /** @var array $payments */ /** @var array $methods */
$adminTab = 'invoices';
$title = 'Invoice ' . $invoice['number'];
$m = fn($c) => FlxCoreHC\InvoiceService::money((int) $c);
$chip = ['draft'=>'muted','sent'=>'warn','partial'=>'warn','paid'=>'active','void'=>'muted'];
$bal = (int)$invoice['total_cents'] - (int)$invoice['paid_cents'];
include __DIR__ . '/_tabs.php';
?>
<p class="crumb"><a href="<?= BASE_PATH ?>/admin/invoices">Invoices</a></p>
<div class="topline">
  <h1><?= e($invoice['number']) ?> <span class="chip <?= $chip[$invoice['status']] ?? 'muted' ?>"><?= e($invoice['status']) ?></span></h1>
  <div style="display:flex;gap:.5rem">
    <?php if ($invoice['status'] !== 'void'): ?>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="send_invoice">
      <input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>">
      <button class="btn"><?= $invoice['sent_at'] ? 'Resend' : 'Send' ?> to client</button>
    </form>
    <?php endif; ?>
    <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'void'): ?>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0" data-confirm="Void this invoice?" data-confirm-danger>
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="void_invoice">
      <input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>">
      <button class="btn line" style="color:var(--danger)">Void</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem">
    <div>
      <div class="hint">Billed to</div>
      <strong><a href="<?= BASE_PATH ?>/admin/ledger/<?= e($invoice['tenant_id']) ?>"><?= e($invoice['client_name']) ?></a></strong>
    </div>
    <div>
      <div class="hint">Issued</div><div class="mono"><?= e(fmt_day($invoice['issue_date'])) ?></div>
    </div>
    <div>
      <div class="hint">Due</div><div class="mono"><?= (!empty($invoice['due_date']) ? e(fmt_day($invoice['due_date'])) : '—') ?></div>
    </div>
    <div style="text-align:right">
      <div class="hint">Balance due</div>
      <div style="font-size:1.3rem;font-weight:700"><?= $bal>0 ? $m($bal) : $m(0) ?></div>
    </div>
  </div>
  <div class="table-wrap"><table>
    <tr><th>Description</th><th class="right">Qty</th><th class="right">Unit</th><th class="right">Amount</th></tr>
    <?php foreach ($items as $it): ?>
    <tr>
      <td><?= e($it['description']) ?></td>
      <td class="right mono"><?= rtrim(rtrim((string)$it['quantity'],'0'),'.') ?></td>
      <td class="right mono"><?= $m((int)$it['unit_cents']) ?></td>
      <td class="right mono"><?= $m((int)$it['amount_cents']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr><td colspan="3" class="right"><strong>Total</strong></td><td class="right mono"><strong><?= $m((int)$invoice['total_cents']) ?></strong></td></tr>
    <?php if ((int)$invoice['paid_cents'] > 0): ?>
    <tr><td colspan="3" class="right hint">Paid</td><td class="right mono"><?= $m((int)$invoice['paid_cents']) ?></td></tr>
    <?php endif; ?>
  </table></div>
  <?php if (!empty($invoice['notes'])): ?><p class="hint" style="margin-top:.8rem"><?= e($invoice['notes']) ?></p><?php endif; ?>
</div>

<?php if ($invoice['status'] !== 'void'): ?>
<div class="panel">
  <div class="topline"><h2>Record a payment</h2></div>
  <p class="hint" style="margin-top:-.3rem">Payments are collected outside FlxCoreHC — enter each one here as it's received.</p>
  <form method="post" action="<?= BASE_PATH ?>/admin" class="form-grid">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="record_payment">
    <input type="hidden" name="tenant_id" value="<?= e($invoice['tenant_id']) ?>">
    <input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>">
    <div><label>Amount</label><input name="amount" type="number" step="0.01" min="0.01" value="<?= number_format($bal/100, 2, '.', '') ?>" required></div>
    <div><label>Method</label><select name="method"><?php foreach ($methods as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Received on</label><input name="received_on" type="date" value="<?= today_local() ?>"></div>
    <div><label>Reference <span class="hint" style="font-weight:400">(check #, confirmation)</span></label><input name="reference" maxlength="120"></div>
    <div class="full"><button class="btn">Record payment</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($payments): ?>
<div class="panel">
  <div class="topline"><h2>Payments on this invoice</h2></div>
  <div class="table-wrap"><table>
    <tr><th>Received</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr>
    <?php foreach ($payments as $p): ?>
    <tr>
      <td class="mono" style="font-size:.8rem"><?= e(fmt_day($p['received_on'])) ?></td>
      <td><?= e($methods[$p['method']] ?? $p['method']) ?></td>
      <td><?= e($p['reference'] ?: '—') ?></td>
      <td class="right mono"><?= $m((int)$p['amount_cents']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>
