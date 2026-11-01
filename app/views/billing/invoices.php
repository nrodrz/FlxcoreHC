<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $invoices */ /** @var array $ledger */ /** @var array|null $plan */
$title = 'Billing';
$m = fn($c) => FlxCoreHC\InvoiceService::money((int) $c);
$chip = ['draft'=>'muted','sent'=>'warn','partial'=>'warn','paid'=>'active','void'=>'muted'];
$owed = (int) $ledger['balance_cents'];
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1>Billing</h1></div>
<p class="hint" style="margin-top:-.4rem;max-width:74ch">Your invoices and account balance. Payments are arranged with us directly — reach out if you have any questions about an invoice.</p>

<div class="kpis">
  <div class="kpi <?= $owed>0?'warn':'ok' ?>">
    <span class="n"><?= $m($owed) ?></span>
    <span class="l"><?= $owed>0 ? 'Balance due' : 'Paid up' ?></span>
  </div>
  <?php if ($plan): ?>
  <div class="kpi"><span class="n"><?= e($plan['name']) ?></span><span class="l">Current plan<?= $plan['price_monthly']!==null?' · $'.number_format((float)$plan['price_monthly'],2).'/mo':'' ?></span></div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="topline"><h2>Invoices</h2></div>
  <div class="table-wrap"><table>
    <tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="right">Total</th><th class="right">Balance</th><th>Status</th><th></th></tr>
    <?php foreach ($invoices as $i):
      if ($i['status'] === 'draft') continue; // clients never see drafts
      $bal = (int)$i['total_cents'] - (int)$i['paid_cents'];
      $overdue = in_array($i['status'],['sent','partial'],true) && $i['due_date'] && $i['due_date'] < today_local(); ?>
    <tr>
      <td><a href="<?= BASE_PATH ?>/billing/invoice/<?= e($i['id']) ?>" class="mono"><?= e($i['number']) ?></a></td>
      <td class="mono" style="font-size:.8rem"><?= e(fmt_day($i['issue_date'])) ?></td>
      <td class="mono" style="font-size:.8rem"><?= (!empty($i['due_date']) ? e(fmt_day($i['due_date'])) : '—') ?><?php if ($overdue): ?> <span class="chip danger" style="font-size:.6rem">overdue</span><?php endif; ?></td>
      <td class="right mono"><?= $m((int)$i['total_cents']) ?></td>
      <td class="right mono"><?= $bal>0 ? $m($bal) : '—' ?></td>
      <td><span class="chip <?= $chip[$i['status']] ?? 'muted' ?>"><?= e($i['status']) ?></span></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/billing/invoice/<?= e($i['id']) ?>/pdf" target="_blank">PDF</a></td>
    </tr>
    <?php endforeach; ?>
    <?php $visible = array_filter($invoices, fn($i)=>$i['status']!=='draft'); if (empty($visible)): ?>
      <tr><td colspan="7"><span class="hint">No invoices yet.</span></td></tr>
    <?php endif; ?>
  </table></div>
</div>
