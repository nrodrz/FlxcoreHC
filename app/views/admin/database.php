<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $summary */ /** @var array $checks */ /** @var array $dictionary */ /** @var string $q */
$title = 'Platform console — Database';
$adminTab = 'database';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
$mb = fn(int $b) => $b >= 1048576 ? round($b/1048576,1) . ' MB' : ($b >= 1024 ? round($b/1024) . ' KB' : $b . ' B');
$icon = ['ok'=>'✓','warn'=>'!','bad'=>'✕']; $chip = ['ok'=>'active','warn'=>'warn','bad'=>'danger'];
$rows = $q === '' ? $dictionary : array_values(array_filter($dictionary, fn($d) =>
    stripos($d['table'], $q) !== false || stripos((string)($d['real'] ?? ''), $q) !== false || stripos((string)$d['purpose'], $q) !== false || stripos((string)$d['note'], $q) !== false));
$counts = ['ok'=>0,'warn'=>0,'bad'=>0]; foreach ($checks as $c) { $counts[$c['state']]++; }
?>
<div class="topline"><h1>Database</h1>
  <span class="hint"><?= (int)$summary['tables'] ?> tables · <?= number_format((int)$summary['rows']) ?> rows · <?= e($mb((int)$summary['bytes'])) ?> · <?= e($summary['version']) ?></span></div>
<p class="hint" style="max-width:840px">Live data dictionary and health for this installation. Structure, counts and sizes only — no tenant record contents are read here.</p>

<div class="panel" style="margin-bottom:1rem">
  <div class="topline" style="margin-bottom:.4rem"><h2 style="margin:0;font-size:1rem">Health</h2>
    <span style="display:flex;gap:.3rem">
      <span class="chip active"><?= (int)$counts['ok'] ?> ok</span>
      <?php if ($counts['warn']): ?><span class="chip warn"><?= (int)$counts['warn'] ?> review</span><?php endif; ?>
      <?php if ($counts['bad']): ?><span class="chip danger"><?= (int)$counts['bad'] ?> action</span><?php endif; ?>
    </span></div>
  <table style="font-size:.85rem">
    <?php foreach ($checks as $c): ?>
    <tr><td style="width:2rem"><span class="chip <?= $chip[$c['state']] ?>" style="padding:0 .35rem"><?= $icon[$c['state']] ?></span></td>
        <td style="width:12rem"><strong><?= e($c['label']) ?></strong></td>
        <td class="hint"><?= e($c['detail']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel" style="margin-bottom:1rem">
  <h2 style="margin-top:0;font-size:1rem">Largest tables</h2>
  <?php $max = max(1, (int)($summary['largest'][0]['total_bytes'] ?? 1)); ?>
  <table style="font-size:.82rem">
    <?php foreach ($summary['largest'] as $l): ?>
    <tr><td style="width:14rem"><span class="mono"><?= e($l['table']) ?></span></td>
        <td style="width:50%"><div style="background:var(--accent,#2563eb);height:8px;border-radius:4px;width:<?= max(3,(int)round(100*$l['total_bytes']/$max)) ?>%"></div></td>
        <td class="right mono"><?= e($mb((int)$l['total_bytes'])) ?></td>
        <td class="right hint"><?= number_format((int)$l['rows']) ?> rows</td></tr>
    <?php endforeach; ?>
  </table>
</div>

<form method="get" action="<?= BASE_PATH ?>/admin/database" style="display:flex;gap:.5rem;margin-bottom:.8rem">
  <input name="q" value="<?= e($q) ?>" placeholder="Search tables, purpose or notes" style="max-width:320px">
  <button class="btn sm">Filter</button>
  <?php if ($q !== ''): ?><a class="btn sm line" href="<?= BASE_PATH ?>/admin/database">Clear</a><?php endif; ?>
  <span class="hint" style="align-self:center"><?= count($rows) ?> of <?= count($dictionary) ?> tables</span>
</form>

<div class="panel">
  <h2 style="margin-top:0;font-size:1rem">Data dictionary</h2>
  <div class="doc-cols">
  <?php foreach ($rows as $d): ?>
  <details id="t-<?= e($d['table']) ?>" style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.5rem .7rem;margin-bottom:.5rem">
    <summary style="cursor:pointer;display:flex;justify-content:space-between;gap:.5rem;align-items:center;flex-wrap:wrap">
      <span><strong class="mono"><?= e($d['real'] ?? $d['table']) ?></strong>
        <?php if (!$d['tenant_scoped'] && $d['tenant_expected']): ?><span class="chip danger" style="font-size:.6rem">no tenant_id</span><?php endif; ?>
        <?php if ($d['note']): ?><span class="chip" style="font-size:.6rem">note</span><?php endif; ?></span>
      <span class="hint"><?= number_format((int)$d['rows']) ?> rows · <?= (int)$d['columns'] ?> cols · <?= (int)$d['indexes'] ?> idx · <?= e($mb((int)$d['total_bytes'])) ?></span>
    </summary>
    <p style="margin:.5rem 0;font-size:.85rem"><?= e($d['purpose']) ?></p>
    <div class="hint" style="font-size:.75rem">Engine <?= e($d['engine']) ?> · <?= e($d['collation']) ?> · tenant-scoped: <?= $d['tenant_scoped'] ? 'yes' : ($d['tenant_expected'] ? 'NO' : 'n/a') ?><?php if ($d['updated']): ?> · updated <?= e(substr((string)$d['updated'],0,16)) ?><?php endif; ?></div>
    <form method="post" action="<?= BASE_PATH ?>/admin/database" style="margin-top:.5rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="table" value="<?= e($d['table']) ?>">
      <label style="font-size:.75rem">Operator notes</label>
      <textarea name="note" rows="2" style="width:100%;font-size:.82rem" placeholder="Why this table exists, gotchas, retention decisions…"><?= e((string)$d['note']) ?></textarea>
      <div style="margin-top:.3rem"><button class="btn sm line">Save note</button>
        <?php if ($d['note_updated']): ?><span class="hint" style="font-size:.72rem">last updated <?= e(substr((string)$d['note_updated'],0,16)) ?></span><?php endif; ?></div>
    </form>
  </details>
  <?php endforeach; ?>
  </div>
</div>
