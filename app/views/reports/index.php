<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $rollup */ /** @var int $planYear */
use FlxCoreHC\RiskScoreService;
$title='Reports'; ?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1>Compliance reports</h1>
  <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap"><span class="hint"><?= e(t('Reporting year {year}', ['year' => (int)$planYear])) ?></span>
    <a class="btn sm line" href="<?= BASE_PATH ?>/reports/readiness/export" title="One row per provider plus readiness by group and by location — for clients and auditors">Export readiness (CSV)</a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/reports/credentials">Credential report</a></div></div>
<div class="tabs" style="display:flex;gap:.5rem;margin-bottom:1rem">
  <a class="btn sm" href="<?= BASE_PATH ?>/reports">Compliance</a>
  
  <a class="btn sm line" href="<?= BASE_PATH ?>/reports/credentials">Credentials</a>
</div>
<div class="kpis">
  <div class="kpi"><span class="n"><?= $rollup['average'] === null ? '—' : (int)$rollup['average'] ?></span><span class="l">Avg risk score</span></div>
  <div class="kpi <?= $rollup['high_risk']>0?'danger':'ok' ?>"><span class="n"><?= (int)$rollup['high_risk'] ?></span><span class="l">High-risk providers</span></div>
  <div class="kpi"><span class="n"><?= (int)$rollup['count'] ?></span><span class="l">Providers scored</span></div>
  <?php if (!empty($rollup['unassessed'])): ?><div class="kpi"><span class="n"><?= (int)$rollup['unassessed'] ?></span><span class="l">Not yet assessable</span></div><?php endif; ?>
</div>
<?php if ($rollup['count'] === 0 && !empty($rollup['unassessed'])): ?>
  <p class="hint">No providers have applicable requirements yet — assign regulators and record credentials, and scores will populate here from the same data the Compliance view uses.</p>
<?php endif; ?>
<div class="panel">
  <div class="topline"><h2>Risk score by provider</h2><span class="hint">worst first</span></div>
  <table>
    <tr><th>Provider</th><th>Score</th><th>Risk</th><th class="right">Open gaps</th><th></th></tr>
    <?php foreach($rollup['holders'] as $h):
      $col=RiskScoreService::bandColor($h['band']); ?>
    <tr>
      <td><strong><?= e($h['name']) ?></strong></td>
      <td class="mono" style="font-weight:700;color:<?= $col ?>"><?= $h['score'] === null ? '—' : (int)$h['score'] ?></td>
      <td><span class="chip" style="background:<?= $col ?>22;color:<?= $col ?>"><?= e(RiskScoreService::bandLabel($h['band'])) ?></span></td>
      <td class="right mono"><?= (int)$h['gaps'] ?></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/reports/audit/<?= e($h['holder']['id']) ?>">Audit package</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<p class="hint"><?= str_replace('{pkg}', '<strong>' . e(t('Audit-Readiness package')) . '</strong>', e(t("The {pkg} assembles each provider's credentials, requirement evidence, compliance posture, and activity trail into one printable document — your one-click answer to a payer or CMS audit request. It contains no PHI."))) ?></p>
