<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var string $range */ /** @var string $from */ /** @var string $today */
/** @var array $totals */ /** @var array $highlights */ /** @var array $byPerson */ /** @var array $byDay */ /** @var array $recent */
$title = 'Activity';
require FLXCOREHC_APP . '/views/org/_tabs.php';
$rangeLabel = ['today'=>'Today','7'=>'Last 7 days','30'=>'Last 30 days'][$range] ?? 'Today';
// sparkline over the last 30 days
$max = max(1, ...array_values($byDay ?: [0]));
$days30 = [];
for ($i = 29; $i >= 0; $i--) { $d = today_local("-$i days"); $days30[$d] = $byDay[$d] ?? 0; }
$entityLabel = ['holders'=>'Providers','credentials'=>'Credentials','credential_documents'=>'Documents',
  'provider_groups'=>'Groups','locations'=>'Locations','tenants'=>'Organization','users'=>'Users','roles'=>'Roles'];
$ago = function(?string $ts): string { if(!$ts) return ''; $d=time()-strtotime($ts.' UTC'); if($d<3600) return t('{n}m ago', ['n' => max(1,intdiv($d,60))]); if($d<86400) return t('{n}h ago', ['n' => intdiv($d,3600)]); return t('{n}d ago', ['n' => intdiv($d,86400)]); };
?>
<div class="wrap">
  <div class="topline"><h1><?= e(t('Activity')) ?></h1>
    <a class="btn sm line" href="<?= BASE_PATH ?>/audit"><?= e(t('Full audit log')) ?></a></div>
  <div class="seg" style="margin-bottom:1rem">
    <?php foreach (['today'=>t('Today'),'7'=>t('7 days'),'30'=>t('30 days')] as $rk=>$rl): ?>
      <a class="<?= $range===$rk?'on':'' ?>" href="<?= BASE_PATH ?>/audit/pulse?range=<?= $rk ?>"><?= e($rl) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="kpis" style="margin-bottom:1rem">
    <div class="kpi"><span class="n"><?= (int)($totals['total'] ?? 0) ?></span><span class="l"><?= e(t('actions')) ?> · <?= e($rangeLabel) ?></span></div>
    <div class="kpi"><span class="n"><?= (int)($totals['actors'] ?? 0) ?></span><span class="l"><?= e(t('people active')) ?></span></div>
    <div class="kpi"><span class="n"><?= (int)($totals['records'] ?? 0) ?></span><span class="l"><?= e(t('records touched')) ?></span></div>
  </div>

  <div class="panel" style="margin-bottom:1rem">
    <div class="topline" style="margin-bottom:.4rem"><h2 style="margin:0"><?= e(t('What happened')) ?></h2><span class="hint"><?= e($rangeLabel) ?></span></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.6rem">
      <?php foreach ($highlights as [$label,$n,$link]): ?>
        <a href="<?= BASE_PATH ?>/audit?entity_type=<?= e($link==='documents'?'credential_documents':($link==='holders'?'holders':($link==='credentials'?'credentials':''))) ?>&from=<?= e($from) ?>"
           style="text-decoration:none;color:inherit;border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.6rem .7rem;display:block">
          <div style="font-size:1.5rem;font-weight:700;<?= $n===0?'opacity:.35':'' ?>"><?= (int)$n ?></div>
          <div class="hint" style="font-size:.76rem"><?= e($label) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="grid2" style="gap:1rem">
    <div class="panel">
      <h2 style="margin-top:0"><?= e(t('Most active people')) ?></h2>
      <?php if ($byPerson === []): ?><p class="hint"><?= e(t('No activity in this window.')) ?></p>
      <?php else: ?>
      <table style="font-size:.85rem">
        <?php $pmax = max(1, (int)($byPerson[0]['n'] ?? 1)); foreach ($byPerson as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td style="width:45%"><div style="background:var(--accent,#2563eb);height:8px;border-radius:4px;width:<?= max(4,(int)round(100*$p['n']/$pmax)) ?>%"></div></td>
          <td class="right mono"><?= (int)$p['n'] ?></td>
          <td class="right hint" style="white-space:nowrap"><?= e($ago($p['last_at'] ?? null)) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2 style="margin-top:0"><?= e(t('30-day trend')) ?></h2>
      <div style="display:flex;align-items:flex-end;gap:2px;height:90px;margin:.5rem 0">
        <?php foreach ($days30 as $d=>$n): $h = $n>0 ? max(6,(int)round(84*$n/$max)) : 2; ?>
          <div title="<?= e(fmt_day($d)) ?>: <?= (int)$n ?>" style="flex:1;background:<?= $n>0?'var(--accent,#2563eb)':'var(--line,#e3e1da)' ?>;height:<?= $h ?>px;border-radius:2px 2px 0 0"></div>
        <?php endforeach; ?>
      </div>
      <div class="hint" style="display:flex;justify-content:space-between;font-size:.7rem"><span><?= e(fmt_day(today_local('-29 days'))) ?></span><span><?= e(t('Today')) ?></span></div>
    </div>
  </div>

  <div class="panel" style="margin-top:1rem">
    <div class="topline" style="margin-bottom:.4rem"><h2 style="margin:0"><?= e(t('Latest activity')) ?></h2>
      <a class="hint" href="<?= BASE_PATH ?>/audit?from=<?= e($from) ?>"><?= e(t('See all')) ?></a></div>
    <?php if ($recent === []): ?><p class="hint"><?= e(t('Nothing yet.')) ?></p>
    <?php else: ?>
    <table style="font-size:.82rem">
      <?php foreach ($recent as $a): ?>
      <tr>
        <td class="mono hint" style="white-space:nowrap;width:9rem" data-label="<?= e(t('When')) ?>"><?= e(fmt_dt($a['created_at'], 'm/d/Y g:i A')) ?></td>
        <td data-label="<?= e(t('Who')) ?>"><?= e($a['user_name'] ?? 'System') ?></td>
        <td data-label="<?= e(t('Action')) ?>"><?= e(str_replace('_',' ',(string)$a['action'])) ?></td>
        <td class="hint" data-label="<?= e(t('Record')) ?>"><?= e($entityLabel[$a['entity_type']] ?? (string)$a['entity_type']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
