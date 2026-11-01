<?php /** @var array $o */
$title='Revenue';
$money=fn($n)=>'$'.number_format((float)$n,2);
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/admin">Platform admin</a></div>
<div class="topline"><h1>Revenue &amp; subscriptions</h1></div>

<div class="kpis">
  <div class="kpi ok"><span class="n"><?= $money($o['mrr']) ?></span><span class="l">MRR</span></div>
  <div class="kpi"><span class="n"><?= $money($o['arr']) ?></span><span class="l">ARR (run-rate)</span></div>
  <div class="kpi"><span class="n"><?= (int)$o['paying'] ?></span><span class="l">Paying orgs</span></div>
  <div class="kpi <?= $o['locked']?'danger':'' ?>"><span class="n"><?= (int)$o['locked'] ?></span><span class="l">Read-only (locked)</span></div>
</div>
<div class="kpis">
  <div class="kpi"><span class="n"><?= (int)$o['status']['active'] ?></span><span class="l">Active</span></div>
  <div class="kpi"><span class="n"><?= (int)$o['status']['trialing'] ?></span><span class="l">Trialing</span></div>
  <div class="kpi <?= $o['status']['past_due']?'warn':'' ?>"><span class="n"><?= (int)$o['status']['past_due'] ?></span><span class="l">Past due</span></div>
  <div class="kpi"><span class="n"><?= (int)$o['status']['canceled'] ?></span><span class="l">Canceled</span></div>
</div>

<div class="panel">
  <div class="topline"><h2>Revenue by plan</h2></div>
  <table>
    <tr><th>Plan</th><th class="right">Orgs</th><th class="right">MRR</th></tr>
    <?php foreach ($o['per_plan'] as $name=>$row): ?>
    <tr><td><?= e($name) ?></td><td class="right mono"><?= (int)$row['tenants'] ?></td><td class="right mono"><?= $money($row['mrr']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel">
  <div class="topline"><h2>Needs attention</h2></div>
  <?php if (!$o['past_due'] && !$o['trials_ending']): ?>
    <p class="hint">Nothing to chase right now.</p>
  <?php endif; ?>
  <?php if ($o['past_due']): ?>
    <h3 style="font-size:.9rem;margin:.4rem 0">Past due</h3>
    <table>
      <?php foreach ($o['past_due'] as $r): ?>
      <tr><td><a href="<?= BASE_PATH ?>/admin/tenant/<?= e($r['id']) ?>"><?= e($r['name']) ?></a></td>
          <td class="sub">since <?= $r['since']?e(fmt_dt($r['since'], 'm/d/Y')):'—' ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($o['trials_ending']): ?>
    <h3 style="font-size:.9rem;margin:.8rem 0 .4rem">Trials ending within 7 days</h3>
    <table>
      <?php foreach ($o['trials_ending'] as $r): ?>
      <tr><td><a href="<?= BASE_PATH ?>/admin/tenant/<?= e($r['id']) ?>"><?= e($r['name']) ?></a></td>
          <td class="sub"><?= $r['days']===0?'ends today':('in '.$r['days'].' day'.($r['days']===1?'':'s')) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
