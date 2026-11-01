<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
/** @var array $counts  status => n */
/** @var array $horizon credentials expiring within 180 days */
/** @var int $holderCnt */
/** @var int $openTasks */
$title = 'Dashboard';
?>
<?php // ---- Getting started (auto-computed; hides when complete or dismissed) ----
  $ob = $onboarding ?? null;
  if ($ob && !$ob['dismissed'] && $ob['done'] < $ob['total']): $pct = (int)round($ob['done'] * 100 / max(1, $ob['total'])); ?>
<div class="panel" style="margin-bottom:1rem;border-left:4px solid var(--accent)">
  <div class="topline">
    <h2 style="margin:0"><?= e(t('Getting started')) ?> <span class="hint" style="font-weight:400"><?= (int)$ob['done'] ?>/<?= (int)$ob['total'] ?> · <?= $pct ?>%</span></h2>
    <?php if (FlxCoreHC\Authz::can($ctx, 'org.manage')): ?>
    <form method="post" action="<?= BASE_PATH ?>/onboarding/dismiss" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line" title="<?= e(t('Hide this checklist')) ?>"><?= e(t('Dismiss')) ?></button></form>
    <?php endif; ?>
  </div>
  <div style="height:6px;background:var(--line);border-radius:3px;margin:.4rem 0 .8rem;overflow:hidden"><div style="width:<?= $pct ?>%;height:100%;background:var(--accent)"></div></div>
  <ol style="list-style:none;padding:0;margin:0;display:grid;gap:.45rem">
    <?php foreach ($ob['steps'] as $i => $s): ?>
    <li style="display:flex;gap:.7rem;align-items:flex-start;<?= $s['done'] ? 'opacity:.55' : '' ?>">
      <span style="flex:0 0 1.5rem;height:1.5rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:600;<?= $s['done'] ? 'background:var(--ok,#2f7d5b);color:#fff' : 'border:2px solid var(--line);color:var(--ink-3)' ?>"><?= $s['done'] ? '✓' : ($i + 1) ?></span>
      <div style="flex:1">
        <a href="<?= BASE_PATH . e($s['href']) ?>" style="font-weight:600;<?= $s['done'] ? 'text-decoration:line-through' : '' ?>"><?= e(t($s['title'])) ?></a>
        <div class="hint" style="font-size:.78rem"><?= e(t($s['hint'])) ?></div>
      </div>
      <?php if (!$s['done']): ?><a class="btn sm gold" href="<?= BASE_PATH . e($s['href']) ?>"><?= e(t('Go')) ?></a><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>
<?php if (!empty($supportPending)): ?>
<div class="panel" style="border-left:4px solid var(--gold);margin-bottom:1rem">
  <strong><?= e(t('Platform support is requesting access')) ?></strong>
  <p class="hint" style="margin:.3rem 0"><?= e($supportPending['requester_name'] ?: 'A platform administrator') ?> — "<?= e($supportPending['reason']) ?>"
    · <?= e(t('If approved, access lasts 4 hours, covers settings and training only (never providers, credentials, or documents), and you can revoke it at any time.')) ?></p>
  <form method="post" action="<?= BASE_PATH ?>/support-access" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="request_id" value="<?= e($supportPending['id']) ?>"><input type="hidden" name="action" value="approve">
    <button class="btn sm gold"><?= e(t('Approve (4 hours)')) ?></button></form>
  <form method="post" action="<?= BASE_PATH ?>/support-access" style="display:inline;margin-left:.4rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="request_id" value="<?= e($supportPending['id']) ?>"><input type="hidden" name="action" value="deny">
    <button class="btn sm line"><?= e(t('Deny')) ?></button></form>
</div>
<?php endif; ?>
<?php if (!empty($supportActive)): ?>
<div class="panel" style="border-left:4px solid #be4b42;margin-bottom:1rem">
  <strong><?= e(t('Platform support access is active')) ?></strong>
  <span class="hint"> · <?= e($supportActive['requester_name'] ?: 'Platform admin') ?> · <?= e(t('until')) ?> <?= e(fmt_dt($supportActive['expires_at'], 'm/d/Y g:i A')) ?></span>
  <form method="post" action="<?= BASE_PATH ?>/support-access" style="display:inline;margin-left:.6rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="request_id" value="<?= e($supportActive['id']) ?>"><input type="hidden" name="action" value="revoke">
    <button class="btn sm line"><?= e(t('Revoke now')) ?></button></form>
</div>
<?php endif; ?>
<?php

$holderLabel = match ($ctx->orgShape) {
    'medical_group' => 'Providers & org',
    'solo_practice' => 'Profiles',
    default         => vocab('holders'),
};
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline">
  <h1><?= e(t('Management dashboard')) ?></h1>
  <a class="btn gold" href="<?= BASE_PATH ?>/credentials/new">Add credential</a>
</div>

<?php
$d = $dash ?? null;
if ($d):
  if (!empty($d['queryFailures'])): ?>
  <div class="flash" style="border-color:var(--danger)">Some dashboard figures could not be computed (<?= (int)$d['queryFailures'] ?> query failure<?= (int)$d['queryFailures'] === 1 ? '' : 's' ?> logged). Numbers below may be incomplete — this is not a compliance result.</div>
  <?php endif;
  if (!empty($d['scoped'])): ?>
  <p class="hint" style="margin:-.4rem 0 .6rem">Showing your assigned providers only.</p>
  <?php endif;
  $L = $d['levels']; $lvLabel = ['Not compliant','Licensed','Credentialed','Payer-credentialed','Billable','All tracked requirements current'];
  $lvColor = ['#be4b42','#c47f1a','#c9a227','#2f6fd6','#2f7d5b','#1f9d63'];
  $tot = max(1, array_sum($L));
  // donut geometry
  $r = 54; $cx = 70; $cy = 70; $circ = 2 * M_PI * $r; $off = 0; $segs = '';
  foreach ($L as $lv => $n) { if ($n <= 0) continue; $len = $circ * $n / $tot;
    $segs .= '<circle r="'.$r.'" cx="'.$cx.'" cy="'.$cy.'" fill="none" stroke="'.$lvColor[$lv].'" stroke-width="18" stroke-dasharray="'.$len.' '.($circ-$len).'" stroke-dashoffset="'.(-$off).'" transform="rotate(-90 '.$cx.' '.$cy.')"><title>'.e(t($lvLabel[$lv])).': '.$n.'</title></circle>';
    $off += $len; }
  $fmax = max(1, max(array_column($d['forecast'], 'n')));
?>
<!-- KPI tiles: every number is a door into the list behind it -->
<div class="kpis">
  <a class="kpi <?= $d['billablePct'] >= 80 ? 'ok' : ($d['billablePct'] >= 50 ? 'warn' : 'danger') ?>" href="<?= BASE_PATH ?>/compliance" title="<?= e(t('Providers at level 4–5')) ?>"><span class="n"><?= $d['billablePct'] ?>%</span><span class="l"><?= e(t('Billable')) ?> · <?= $d['billable'] ?>/<?= $d['providers'] ?></span></a>
  <a class="kpi <?= $d['notBillable'] > 0 ? 'danger' : 'ok' ?>" href="<?= BASE_PATH ?>/compliance?below=4"><span class="n"><?= $d['notBillable'] ?></span><span class="l"><?= e(t('Revenue at risk')) ?> · <?= e(t('not billable')) ?></span></a>
  <a class="kpi <?= $d['expired'] > 0 ? 'danger' : 'ok' ?>" href="<?= BASE_PATH ?>/credentials?status=expired"><span class="n"><?= $d['expired'] ?></span><span class="l"><?= e(t('Expired')) ?></span></a>
  <a class="kpi <?= $d['exp30'] > 0 ? 'warn' : '' ?>" href="<?= BASE_PATH ?>/credentials?status=expiring"><span class="n"><?= $d['exp30'] ?></span><span class="l"><?= e(t('Expiring ≤30 days')) ?></span></a>
  <a class="kpi" href="<?= BASE_PATH ?>/calendar?view=agenda"><span class="n"><?= $d['exp90'] ?></span><span class="l"><?= e(t('Due ≤90 days')) ?></span></a>
  <a class="kpi" href="<?= BASE_PATH ?>/holders"><span class="n"><?= $d['providers'] ?></span><span class="l"><?= e($holderLabel) ?></span></a>
</div>

<div class="dash-grid">
  <!-- Risk exposure donut -->
  <div class="panel">
    <div class="topline"><h2 style="margin:0;font-size:1rem"><?= e(t('Compliance exposure')) ?></h2><a class="hint" href="<?= BASE_PATH ?>/compliance"><?= e(t('open report')) ?> →</a></div>
    <div style="display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap">
      <svg viewBox="0 0 140 140" width="140" height="140" aria-label="<?= e(t('Compliance levels')) ?>"><?= $segs ?>
        <text x="70" y="66" text-anchor="middle" font-size="22" font-weight="700" fill="var(--ink)"><?= $d['billablePct'] ?>%</text>
        <text x="70" y="84" text-anchor="middle" font-size="9" fill="var(--ink-3)"><?= e(strtoupper(t('billable'))) ?></text></svg>
      <div style="flex:1;min-width:180px">
        <?php foreach ($L as $lv => $n): ?>
        <a href="<?= BASE_PATH ?>/compliance?level=<?= $lv ?>" style="display:flex;align-items:center;gap:.5rem;padding:.22rem 0;text-decoration:none;color:var(--ink);font-size:.82rem">
          <span style="width:10px;height:10px;border-radius:2px;background:<?= $lvColor[$lv] ?>;flex:none"></span>
          <span style="flex:1">L<?= $lv ?> · <?= e(t($lvLabel[$lv])) ?></span><strong><?= $n ?></strong></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Expiry forecast bars -->
  <div class="panel">
    <div class="topline"><h2 style="margin:0;font-size:1rem"><?= e(t('Expiry forecast — next 6 months')) ?></h2><a class="hint" href="<?= BASE_PATH ?>/calendar?view=agenda"><?= e(t('open calendar')) ?> →</a></div>
    <svg viewBox="0 0 320 130" width="100%" height="130" aria-label="<?= e(t('Expirations by month')) ?>">
      <?php foreach ($d['forecast'] as $i => $f): $x = 14 + $i * 51; $h = (int)round(90 * $f['n'] / $fmax); $y = 105 - $h; ?>
      <a href="<?= BASE_PATH ?>/calendar?view=month&m=<?= e($f['ym']) ?>">
        <rect x="<?= $x ?>" y="<?= $y ?>" width="36" height="<?= max(2,$h) ?>" rx="4" fill="<?= $i === 0 ? '#be4b42' : ($i === 1 ? '#c47f1a' : 'var(--accent)') ?>" opacity=".9"><title><?= e($f['label']) ?>: <?= $f['n'] ?></title></rect>
        <text x="<?= $x + 18 ?>" y="<?= $y - 4 ?>" text-anchor="middle" font-size="11" font-weight="700" fill="var(--ink)"><?= $f['n'] ?></text>
        <text x="<?= $x + 18 ?>" y="122" text-anchor="middle" font-size="10" fill="var(--ink-3)"><?= e($f['label']) ?></text></a>
      <?php endforeach; ?>
    </svg>
    <div class="hint" style="font-size:.72rem"><?= e(t('Click a month to open it on the calendar. Red = this month, amber = next.')) ?></div>
  </div>
</div>

<!-- WORK QUEUE: the process hub -->
<div class="panel" id="workqueue" style="border-left:4px solid var(--accent)">
  <div class="topline"><h2 style="margin:0;font-size:1rem"><?= e(t('Work queue')) ?> <span class="hint" style="font-weight:400">· <?= $d['queueTotal'] ?> <?= e(t('items, most urgent first')) ?></span></h2>
    <div style="display:flex;gap:.4rem"><a class="btn sm gold" href="<?= BASE_PATH ?>/credentials/new"><?= e(t('Add credential')) ?></a></div></div>
  <?php if ($d['queue'] === []): ?><div class="empty"><?= e(t('Nothing needs attention. You are clear.')) ?></div>
  <?php else: ?>
  <table style="font-size:.84rem" class="wq">
    <tr><th style="width:1.2rem"></th><th><?= e(t('What')) ?></th><th><?= e(t('Who')) ?></th><th><?= e(t('When')) ?></th><th></th></tr>
    <?php $kc = ['expired'=>'#be4b42','blocked'=>'#be4b42','expiring'=>'#c47f1a','review'=>'#2f6fd6','reverify'=>'#8a7a4a'];
    foreach ($d['queue'] as $it): ?>
    <tr>
      <td><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= $kc[$it['kind']] ?? 'var(--ink-3)' ?>"></span></td>
      <td><a href="<?= BASE_PATH . e($it['href']) ?>?from=dashboard" style="font-weight:600;color:var(--ink);text-decoration:none"><?= e(t($it['title'])) ?></a></td>
      <td class="hint"><?= e($it['who']) ?></td>
      <td class="mono hint" style="white-space:nowrap"><?= e(fmt_day((string)$it['when'])) ?></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH . e($it['href']) ?>?from=dashboard"><?= e(t($it['action'])) ?> →</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="dash-grid" style="margin-top:1rem">
  <div class="panel">
    <h2 style="margin:0 0 .5rem;font-size:1rem"><?= e(t('Done in the last 30 days')) ?></h2>
    <div class="kpis" style="margin:0;grid-template-columns:1fr 1fr">
      <div class="kpi ok"><span class="n"><?= $d['throughput']['verified'] ?></span><span class="l"><?= e(t('Verifications')) ?></span></div>
      <div class="kpi ok"><span class="n"><?= $d['throughput']['docs'] ?></span><span class="l"><?= e(t('Documents added')) ?></span></div>
      <div class="kpi ok"><span class="n"><?= $d['throughput']['requests'] ?></span><span class="l"><?= e(t('Requests accepted')) ?></span></div>
      <div class="kpi ok"><span class="n"><?= $d['throughput']['renewed'] ?></span><span class="l"><?= e(t('Credentials updated')) ?></span></div>
    </div>
  </div>
  <div class="panel">
    <div class="topline"><h2 style="margin:0;font-size:1rem"><?= e(t('Blocked providers')) ?></h2><a class="hint" href="<?= BASE_PATH ?>/compliance?below=4"><?= e(t('see all')) ?> →</a></div>
    <?php if ($d['blockedList'] === []): ?><p class="hint"><?= e(t('Everyone is billable.')) ?></p><?php else: ?>
    <?php foreach ($d['blockedList'] as $b): ?>
      <a href="<?= BASE_PATH ?>/holders/<?= e($b['id']) ?>?from=dashboard" style="display:flex;justify-content:space-between;gap:.5rem;padding:.3rem 0;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink);font-size:.84rem">
        <span><strong><?= e($b['name']) ?></strong> <span class="hint"><?= e($b['reason']) ?></span></span>
        <span class="chip <?= $b['level'] === 0 ? 'danger' : 'warn' ?>" style="font-size:.6rem">L<?= $b['level'] ?></span></a>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php endif; ?>
