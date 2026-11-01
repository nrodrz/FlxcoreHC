<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $tenantRow */ /** @var array $usage */ /** @var array $plans */ /** @var bool $billingOn */
$title='Billing';
$plan = $usage['plan'];
$status = $tenantRow['billing_status'] ?? 'none';
$statusChip = ['active'=>'active','trialing'=>'in_progress','past_due'=>'expiring','canceled'=>'expired','none'=>''][$status] ?? '';
$bar = function($u){ $lim=$u['limit']; if($lim===null) return '<span class="mono">'.$u['used'].' / ∞</span>';
  $pct=$lim>0?min(100,round(100*$u['used']/$lim)):0; $col=$pct>=90?'var(--danger)':($pct>=70?'var(--warn,#c77b21)':'var(--ok)');
  return '<span class="mono">'.$u['used'].' / '.$lim.'</span><div style="height:6px;background:var(--line);border-radius:4px;margin-top:3px"><div style="height:6px;border-radius:4px;width:'.$pct.'%;background:'.$col.'"></div></div>'; };
?>
<?php include __DIR__ . '/org/_tabs.php'; ?>
<div class="topline"><h1>Plan &amp; billing</h1><?php if($status!=='none'): ?><span class="chip <?= $statusChip ?>"><?= e($status) ?></span><?php endif; ?></div>

<?php if (!empty($_GET['ok'])): ?><div class="flash">Thanks — your subscription is being activated.</div><?php endif; ?>

<div class="panel">
  <div class="topline"><h2>Current plan</h2></div>
  <p><strong><?= e($plan['name'] ?? 'Default') ?></strong>
     <?= isset($plan['price_monthly'])&&$plan['price_monthly']!==null?' · $'.number_format((float)$plan['price_monthly'],2).'/mo':'' ?>
     <?php if(!empty($tenantRow['current_period_end'])): ?> · renews <?= e(fmt_day($tenantRow['current_period_end'])) ?><?php endif; ?></p>
  <table style="max-width:460px">
    <tr><td>Staff seats</td><td><?= $bar($usage['seats']) ?></td></tr>
    <tr><td>Holders</td><td><?= $bar($usage['holders']) ?></td></tr>
    <?php $pb = $usage['producers']; ?>
    <tr><td>Provider logins</td><td>
      <?php if ($pb['free']): ?>
        <?= (int) $pb['count'] ?> <span class="hint">(included free)</span>
      <?php else: ?>
        <?= (int) $pb['count'] ?><?php if ($pb['included']): ?> <span class="hint">(<?= (int)$pb['included'] ?> free, then</span><?php endif; ?>
        <span class="hint"><?= $pb['included'] ? '' : '· ' ?>$<?= number_format($pb['price'], 2) ?>/mo each<?= $pb['included'] ? ')' : '' ?></span>
        <?php if ($pb['monthly'] > 0): ?><strong style="margin-left:.4rem">+$<?= number_format($pb['monthly'], 2) ?>/mo</strong><?php endif; ?>
      <?php endif; ?>
    </td></tr>
  </table>
  <?php if ($billingOn && !empty($tenantRow['stripe_customer_id'])): ?>
    <form method="post" action="<?= BASE_PATH ?>/billing" style="margin-top:.8rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="portal"><button class="btn line">Manage billing</button></form>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="topline"><h2>Plans</h2></div>
  <?php if (!$billingOn): ?>
    <div class="hint">Online billing isn't set up on this instance yet. An administrator can add Stripe keys in <span class="mono">app/config.php</span> and a Stripe Price to each plan. You can still be moved between plans by your provider.</div>
  <?php endif; ?>
  <div class="kpis">
    <?php foreach ($plans as $pl): if(!$pl['active']) continue; $cur = ($tenantRow['plan_id']??'')===$pl['id']; $f=json_decode((string)$pl['features'],true)?:[]; ?>
    <div class="panel" style="margin:0;<?= $cur?'border-color:var(--ok)':'' ?>">
      <strong><?= e($pl['name']) ?></strong> <?= $cur?'<span class="chip active">current</span>':'' ?>
      <div style="font-size:1.3rem;font-weight:800;margin:.2rem 0"><?= $pl['price_monthly']!==null?'$'.number_format((float)$pl['price_monthly'],0):'—' ?><span style="font-size:.7rem;color:var(--ink-2)">/mo</span></div>
      <div class="hint"><?= e(t('{seats} seats · {providers} providers', ['seats' => $pl['max_users']===null ? t('Unlimited') : (int)$pl['max_users'], 'providers' => $pl['max_holders']===null ? '∞' : (int)$pl['max_holders']])) ?></div>
      <div class="hint" style="margin:.3rem 0"><?= $f?e(implode(', ',$f)):'core only' ?></div>
      <?php if ($billingOn && !$cur && !empty($pl['stripe_price_id'])): ?>
        <a class="btn sm" href="<?= BASE_PATH ?>/billing/subscribe/<?= e($pl['id']) ?>">Choose <?= e($pl['name']) ?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
