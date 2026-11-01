<?php /** @var array $tenant */ /** @var array $ledger */ /** @var array $invoices */ /** @var array $methods */
$adminTab = 'invoices';
$title = 'Ledger — ' . ($tenant['name'] ?? '');
$m = fn($c) => FlxCoreHC\InvoiceService::money((int) $c);
include __DIR__ . '/_tabs.php';
?>
<p class="crumb"><a href="<?= BASE_PATH ?>/admin/invoices">Invoices</a></p>
<div class="topline">
  <h1>Ledger — <?= e($tenant['name'] ?? '') ?></h1>
  <a class="btn line" href="<?= BASE_PATH ?>/admin/invoices/new?tenant=<?= e($tenant['id'] ?? '') ?>">New invoice</a>
</div>

<div class="kpis">
  <div class="kpi <?= $ledger['balance_cents']>0?'warn':'ok' ?>">
    <span class="n"><?= $m($ledger['balance_cents']) ?></span>
    <span class="l"><?= $ledger['balance_cents']>0 ? 'Balance owed' : 'Settled' ?></span>
  </div>
</div>

<div class="panel">
  <div class="topline"><h2>Statement</h2></div>
  <div class="table-wrap"><table>
    <tr><th>Date</th><th>Description</th><th class="right">Charge</th><th class="right">Payment</th><th class="right">Balance</th></tr>
    <?php foreach ($ledger['rows'] as $r): ?>
    <tr>
      <td class="mono" style="font-size:.8rem"><?= e($r['date']) ?></td>
      <td>
        <?php if ($r['type'] === 'invoice'): ?>
          <a href="<?= BASE_PATH ?>/admin/invoices/<?= e($r['ref_id']) ?>"><?= e($r['label']) ?></a>
        <?php else: ?><?= e($r['label']) ?><?php endif; ?>
      </td>
      <td class="right mono"><?= $r['charge_cents']>0 ? $m($r['charge_cents']) : '' ?></td>
      <td class="right mono" style="color:var(--ok)"><?= $r['credit_cents']>0 ? $m($r['credit_cents']) : '' ?></td>
      <td class="right mono"><?= $m($r['balance_cents']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($ledger['rows'])): ?><tr><td colspan="5"><span class="hint">No activity yet.</span></td></tr><?php endif; ?>
  </table></div>
</div>

<div class="panel">
  <div class="topline"><h2>Record an on-account payment</h2></div>
  <p class="hint" style="margin-top:-.3rem">Use this for a payment not tied to a specific invoice. To pay a specific invoice, open it and record the payment there.</p>
  <form method="post" action="<?= BASE_PATH ?>/admin" class="form-grid">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="record_payment">
    <input type="hidden" name="tenant_id" value="<?= e($tenant['id'] ?? '') ?>">
    <div><label>Amount</label><input name="amount" type="number" step="0.01" min="0.01" required></div>
    <div><label>Method</label><select name="method"><?php foreach ($methods as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Received on</label><input name="received_on" type="date" value="<?= today_local() ?>"></div>
    <div><label>Reference</label><input name="reference" maxlength="120"></div>
    <div class="full"><button class="btn line">Record payment</button></div>
  </form>
</div>
