<?php /** @var array $invoices */ /** @var array $summary */
$adminTab = 'invoices';
$title = 'Invoices';
$m = fn(int $c) => FlxCoreHC\InvoiceService::money($c);
$chip = ['draft'=>'muted','sent'=>'warn','partial'=>'warn','paid'=>'active','void'=>'muted'];
include __DIR__ . '/_tabs.php';
?>
<div class="topline">
  <h1>Invoices</h1>
  <a class="btn" href="<?= BASE_PATH ?>/admin/invoices/new">New invoice</a>
</div>
<p class="hint" style="margin-top:-.4rem;max-width:78ch">
  Bill client organizations and record payments received outside the app. Invoices email to the
  client's owner; enter each payment as it arrives to keep the ledger current.
</p>

<div class="kpis">
  <div class="kpi warn"><span class="n"><?= $m($summary['outstanding_cents']) ?></span><span class="l">Outstanding</span></div>
  <div class="kpi <?= $summary['overdue_cents']>0?'danger':'' ?>"><span class="n"><?= $m($summary['overdue_cents']) ?></span><span class="l">Overdue</span></div>
  <div class="kpi ok"><span class="n"><?= $m($summary['collected_cents']) ?></span><span class="l">Collected this month</span></div>
</div>

<div class="panel">
  <div class="table-wrap"><table>
    <tr><th>Invoice</th><th>Client</th><th>Issued</th><th>Due</th><th class="right">Total</th><th class="right">Balance</th><th>Status</th></tr>
    <?php foreach ($invoices as $i):
      $bal = (int)$i['total_cents'] - (int)$i['paid_cents'];
      $overdue = in_array($i['status'],['sent','partial'],true) && $i['due_date'] && $i['due_date'] < today_local(); ?>
    <tr>
      <td><a href="<?= BASE_PATH ?>/admin/invoices/<?= e($i['id']) ?>" class="mono"><?= e($i['number']) ?></a></td>
      <td><?= e($i['client_name']) ?></td>
      <td class="mono" style="font-size:.8rem"><?= e(fmt_day($i['issue_date'])) ?></td>
      <td class="mono" style="font-size:.8rem"><?= (!empty($i['due_date']) ? e(fmt_day($i['due_date'])) : '—') ?><?php if ($overdue): ?> <span class="chip danger" style="font-size:.6rem">overdue</span><?php endif; ?></td>
      <td class="right mono"><?= $m((int)$i['total_cents']) ?></td>
      <td class="right mono"><?= $bal>0 ? $m($bal) : '—' ?></td>
      <td><span class="chip <?= $chip[$i['status']] ?? 'muted' ?>"><?= e($i['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?><tr><td colspan="7"><span class="hint">No invoices yet. Create the first one.</span></td></tr><?php endif; ?>
  </table></div>
</div>
