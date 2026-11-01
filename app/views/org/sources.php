<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $sources */
$title = 'Regulations'; $__path = '/organization/sources'; require FLXCOREHC_APP . '/views/org/_tabs.php'; ?>
<div class="topline"><h1><?= e(t('Regulatory sources')) ?></h1></div>
<p class="hint" style="max-width:800px"><?= e(t('Why the system requires each item — the law, regulation or circular letter behind it, with effective dates and when it was last reviewed. Maintained by the platform team.')) ?></p>
<div class="panel">
  <?php foreach ($sources as $s): ?>
  <div style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.6rem .8rem;margin-bottom:.5rem">
    <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap"><strong><?= e($s['authority']) ?></strong><span class="mono hint"><?= e($s['code']) ?></span></div>
    <?php if ($s['regulation']): ?><div style="font-size:.85rem"><?= e(fmt_iso_text((string)$s['regulation'])) ?></div><?php endif; ?>
    <?php if ($s['summary']): ?><div class="hint" style="font-size:.8rem;margin:.2rem 0"><?= e(fmt_iso_text((string)$s['summary'])) ?></div><?php endif; ?>
    <div class="hint" style="font-size:.72rem"><?= $s['effective_date'] ? e(t('effective')) . ' ' . e(fmt_day($s['effective_date'])) . ' · ' : '' ?><?= $s['superseded_date'] ? '<span style="color:var(--danger,#be4b42)">' . e(t('superseded')) . ' ' . e(fmt_day($s['superseded_date'])) . '</span> · ' : '' ?><?= e(t('reviewed')) ?> <?= e($s['last_reviewed'] ? fmt_day($s['last_reviewed']) : '—') ?><?php if ($s['url']): ?> · <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e(t('source')) ?> ↗</a><?php endif; ?></div>
  </div>
  <?php endforeach; ?>
</div>
