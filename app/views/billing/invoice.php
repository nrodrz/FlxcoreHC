<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $invoice */ /** @var array $items */ /** @var array $payments */ /** @var array $methods */
$title = 'Invoice ' . $invoice['number'];
$m = fn($c) => FlxCoreHC\InvoiceService::money((int) $c);
$chip = ['draft'=>'muted','sent'=>'warn','partial'=>'warn','paid'=>'active','void'=>'muted'];
$bal = (int)$invoice['total_cents'] - (int)$invoice['paid_cents'];
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/billing/invoices">Invoices</a></div>
<div class="topline">
  <h1><?= e($invoice['number']) ?> <span class="chip <?= $chip[$invoice['status']] ?? 'muted' ?>"><?= e($invoice['status']) ?></span></h1>
  <a class="btn line" href="<?= BASE_PATH ?>/billing/invoice/<?= e($invoice['id']) ?>/pdf" target="_blank">Download PDF</a>
</div>

<div class="panel">
  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem">
    <div><div class="hint">Billed to</div><strong><?= e($invoice['client_name']) ?></strong></div>
    <div><div class="hint">Issued</div><div class="mono"><?= e(fmt_day($invoice['issue_date'])) ?></div></div>
    <div><div class="hint">Due</div><div class="mono"><?= (!empty($invoice['due_date']) ? e(fmt_day($invoice['due_date'])) : '—') ?></div></div>
    <div style="text-align:right"><div class="hint">Balance due</div><div style="font-size:1.3rem;font-weight:700"><?= $m($bal) ?></div></div>
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

<?php if ($payments): ?>
<div class="panel">
  <div class="topline"><h2>Payments received</h2></div>
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
