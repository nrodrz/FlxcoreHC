<?php /** @var array $producers */ /** @var array $totals */ /** @var array $soon */ /** @var bool $available */
$title = 'Compliance';
$hname = fn($h)=> ($h['holder_type']??'')==='organization' ? ($h['org_name']??'') : trim((string)($h['first_name']??'').' '.(string)($h['last_name']??''));
$bar = fn(int $pct)=> '<div style="height:8px;background:var(--line);border-radius:5px;overflow:hidden;min-width:110px"><div style="height:100%;width:'.$pct.'%;background:'.($pct>=100?'var(--ok)':($pct>=60?'var(--warn)':'var(--danger)')).'"></div></div>';
$t = $totals;
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1><?= e(t('Compliance')) ?></h1></div>
<?php $tab='overview'; require FLXCOREHC_APP.'/views/compliance/_tabs.php'; ?>
<p class="hint" style="margin-top:-.6rem">Rules current as of <strong><?= e(FlxCoreHC\CatalogService::RULESET_LABEL) ?></strong> · ruleset <?= e(FlxCoreHC\CatalogService::RULESET_VERSION) ?>. <a href="<?= BASE_PATH ?>/training">Training Center →</a></p>

<?php if (!$available): ?>
  <div class="panel"><div class="empty">The credential catalog isn't installed yet. A platform admin can apply it from
    <a href="<?= BASE_PATH ?>/admin/system">System &amp; updates → Update database</a>.</div></div>
<?php elseif ($t['producer_count'] === 0): ?>
  <div class="panel"><div class="empty">No providers yet. Add physicians, nurses, groups or facilities to start tracking compliance.
    <br><a class="btn gold" href="<?= BASE_PATH ?>/holders/new">Add a provider</a></div></div>
<?php else: ?>

<div class="kpis">
  <?php $sc = $t['score']; ?>
  <div class="kpi <?= $sc===null||$sc>=90?'ok':($sc>=60?'warn':'danger') ?>"><span class="n"><?= $sc===null?'—':$sc ?></span><span class="l"><?= e(t('Compliance score')) ?> / 100</span></div>
  <div class="kpi <?= $t['pct']>=100?'ok':($t['pct']>=60?'warn':'danger') ?>"><span class="n"><?= $t['pct'] ?>%</span><span class="l"><?= e(t('Requirements fully active')) ?></span></div>
  <a class="kpi" href="<?= BASE_PATH ?>/compliance/producers"><span class="n"><?= $t['compliant_producers'] ?>/<?= $t['producer_count'] ?></span><span class="l"><?= e(t('Providers with all tracked requirements current')) ?></span></a>
  <div class="kpi <?= $t['missing']>0?'danger':'ok' ?>"><span class="n"><?= $t['missing'] ?></span><span class="l"><?= e(t('Missing')) ?></span></div>
  <div class="kpi <?= ($t['expiring']+$t['expired'])>0?'warn':'ok' ?>"><span class="n"><?= $t['expiring'] + $t['expired'] ?></span><span class="l"><?= e(t('Expiring / expired')) ?></span></div>
  <div class="kpi ok"><span class="n"><?= $t['active'] ?></span><span class="l"><?= e(t('Active credentials')) ?></span></div>
  <div class="kpi <?= $t['satisfied']>0 && $t['evidenced']>=$t['satisfied'] ? 'ok' : 'warn' ?>"><span class="n"><?= $t['evidenced'] ?>/<?= $t['satisfied'] ?></span><span class="l"><?= e(t('Evidenced requirements')) ?></span></div>
</div>

<div class="cols-2">
  <div class="panel">
    <div class="topline"><h2><?= e(t('Providers needing attention')) ?></h2><a class="btn sm line" href="<?= BASE_PATH ?>/compliance/producers"><?= e(t('All providers')) ?></a></div>
    <?php $worst = array_slice(array_filter($producers, fn($p)=>$p['pct'] < 100 && $p['total'] > 0), 0, 8); ?>
    <?php if (!$worst): ?><div class="empty" style="padding:1.4rem"><?= e(t('All tracked requirements are current for every provider.')) ?></div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <tr><th><?= e(t('Provider')) ?></th><th><?= e(t('Role')) ?></th><th style="width:150px"><?= e(t('Coverage')) ?></th><th class="right"><?= e(t('Gaps')) ?></th><th></th></tr>
      <?php foreach ($worst as $p): $h=$p['holder']; ?>
      <tr>
        <td><strong><?= e($hname($h)) ?></strong></td>
        <td class="sub"><?= e(ucfirst($p['role'])) ?></td>
        <td><?= $bar($p['pct']) ?><span class="hint"><?= $p['active'] ?>/<?= $p['total'] ?> <?= e(t('active')) ?></span></td>
        <td class="right mono"><?= $p['total'] - $p['active'] ?></td>
        <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>"><?= e(t('Open')) ?></a></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel">
      <div class="topline"><h2>Status breakdown</h2></div>
      <?php $mk=fn($lbl,$n,$color)=>'<div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0"><span style="display:flex;align-items:center;gap:.5rem"><i style="width:9px;height:9px;border-radius:50%;background:'.$color.';display:inline-block"></i>'.$lbl.'</span><strong class="mono">'.$n.'</strong></div>'; ?>
      <div style="font-size:.9rem">
        <?= $mk('Active', $t['active'], 'var(--ok)') ?>
        <?= $mk('Expiring soon', $t['expiring'], 'var(--warn)') ?>
        <?= $mk('Expired', $t['expired'], 'var(--danger)') ?>
        <?= $mk('Missing', $t['missing'], 'var(--ink-3)') ?>
      </div>
    </div>
    <div class="panel">
      <div class="topline"><h2>Expiring in 30 days</h2>
        <?php if (!empty($canManage)): ?>
        <form method="post" action="<?= BASE_PATH ?>/compliance/run-reminders" style="margin:0"
              title="Email providers whose credentials are approaching expiry">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <button class="btn sm line">Send reminders now</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if (!empty($canManage)): ?><p class="hint" style="margin:-.3rem 0 .6rem"><?= e(t('Providers are emailed automatically at {days} days before a credential expires. Configure delivery under', ['days' => FlxCoreHC\ReminderService::thresholdsLabel()])) ?> <a href="<?= BASE_PATH ?>/organization/email">Organization → Email</a>.</p><?php endif; ?>
      <?php if (!$soon): ?><p class="hint" style="margin:0">Nothing expires in the next 30 days.</p>
      <?php else: ?>
      <div style="display:grid;gap:.45rem;font-size:.88rem">
        <?php foreach (array_slice($soon,0,6) as $c): ?>
        <div style="display:flex;justify-content:space-between;gap:.6rem">
          <span><strong><?= e($c['name']) ?></strong> <span class="hint">· <?= e(trim(($c['first_name']??'').' '.($c['last_name']??'')) ?: ($c['org_name']??'')) ?></span></span>
          <span class="mono" style="white-space:nowrap"><?= e(fmt_day($c['expiry_date'])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:.7rem"><a class="btn sm line" href="<?= BASE_PATH ?>/credentials?status=expiring">Open expiring list</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
