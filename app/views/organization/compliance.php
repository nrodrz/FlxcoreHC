<?php /** @var FlxCoreHC\TenantContext $ctx */
/** @var array $sections */ /** @var array $summary */ /** @var array $recs */
$title = 'Security & compliance';
?>
<?php include __DIR__ . '/../org/_tabs.php'; ?>
<div class="topline">
  <h1>Security &amp; compliance</h1>
  <a class="btn gold" href="<?= BASE_PATH ?>/organization/compliance/pdf">Download overview (PDF)</a>
</div>
<p class="hint" style="margin:-.4rem 0 1rem;max-width:78ch">
  The safeguards protecting your provider and credentialing data. Payers, hospitals, delegated-credentialing
  auditors and compliance officers routinely ask organizations to describe the controls behind the software they use — this page is that answer,
  and the PDF is a version you can send on.
</p>

<div class="kpis" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
  <div class="kpi <?= $summary['require2fa'] ? 'ok' : 'warn' ?>">
    <span class="n"><?= $summary['require2fa'] ? 'On' : 'Off' ?></span>
    <span class="l">Two-factor required for admins</span></div>
  <div class="kpi <?= $summary['mfaPct'] >= 100 ? 'ok' : ($summary['mfaPct'] >= 50 ? 'warn' : 'danger') ?>">
    <span class="n"><?= (int) $summary['mfaPct'] ?>%</span>
    <span class="l"><?= e(t('{n} of {m} users with 2FA', ['n' => (int) $summary['mfaUsers'], 'm' => (int) $summary['totalUsers']])) ?></span></div>
  <div class="kpi ok"><span class="n">AES-256</span><span class="l">Encryption at rest</span></div>
  <div class="kpi"><span class="n" style="font-size:1.05rem"><?= e($summary['audit']) ?></span>
    <span class="l">Audit trail</span></div>
</div>

<?php if ($recs !== []): ?>
<div class="panel" style="border-left:4px solid var(--warn)">
  <div class="topline"><h2><?= e(t('Recommended next steps ({n})', ['n' => count($recs)])) ?></h2></div>
  <?php foreach ($recs as [$what, $why]): ?>
    <div style="padding:.5rem 0;border-top:1px solid var(--line)">
      <strong><?= e($what) ?></strong>
      <div class="hint" style="margin-top:.15rem"><?= e($why) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel" style="border-left:4px solid var(--ok)">
  <p style="margin:0"><strong>&#10003; Every recommended control is in place.</strong>
    <span class="hint">Nothing outstanding on the configuration side.</span></p>
</div>
<?php endif; ?>

<?php foreach ($sections as $secTitle => $sec): ?>
<div class="panel">
  <div class="topline"><h2><?= e($secTitle) ?></h2></div>
  <p class="hint" style="margin:-.2rem 0 .3rem"><?= e($sec['summary']) ?></p>
  <?php foreach ($sec['controls'] as $c): ?>
    <div style="padding:.6rem 0;border-top:1px solid var(--line);display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap">
      <div style="flex:1;min-width:260px">
        <strong><?= e($c['name']) ?></strong>
        <div class="hint" style="margin-top:.2rem;max-width:76ch"><?= e($c['detail']) ?></div>
      </div>
      <?php if (!empty($c['state'])): ?>
        <span class="chip active" style="white-space:nowrap"><?= e($c['state']) ?></span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="panel">
  <div class="topline"><h2>Scope and limitations</h2></div>
  <p class="hint" style="margin:0;max-width:82ch">
    This overview describes controls implemented in software. It is not a certification, audit report, or legal
    opinion, and it does not by itself establish regulatory compliance. Written policies, workforce training, risk
    analysis, business associate agreements, and physical and administrative safeguards remain your organization's
    responsibility. Regulations change — confirm current requirements with your compliance counsel.
  </p>
</div>
