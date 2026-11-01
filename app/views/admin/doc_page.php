<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var string $heading */ /** @var string $intro */ /** @var string $doc */ /** @var string $file */
$title = 'Platform console — ' . $heading;
$adminTab = 'system';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
?>
<div class="topline"><h1><?= e($heading) ?></h1><span class="hint mono"><?= e($file) ?></span></div>
<p class="hint" style="max-width:820px"><?= e($intro) ?></p>
<div class="panel">
  <?php if ($doc === ''): ?><p class="hint"><?= e($file) ?> was not found in this deployment.</p>
  <?php else: ?>
  <div style="max-height:640px;overflow:auto;border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.8rem;background:var(--panel-2,#fafafa)">
    <pre style="white-space:pre-wrap;font-size:.78rem;line-height:1.55;margin:0"><?= e($doc) ?></pre>
  </div>
  <?php endif; ?>
</div>
