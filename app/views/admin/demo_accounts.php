<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var string $doc */ /** @var bool $built */
$title = 'Platform console — Demo accounts';
$adminTab = 'system';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
?>
<div class="topline"><h1>Demo accounts</h1>
  <span><?= $built ? '<span class="chip active">demo is built</span>' : '<span class="chip warn">not built yet</span>' ?></span></div>
<p class="hint" style="max-width:820px">Credentials for the five demo organizations, to hand to people testing the app.
  <?php if (!$built): ?>Build it first from <a href="<?= BASE_PATH ?>/admin/system">Console → System</a>.<?php endif; ?>
  Every demo account uses the password <strong class="mono">FlxDemo2026!</strong> except the five organization owners, which are listed below.</p>
<div class="panel">
  <?php if ($doc === ''): ?><p class="hint">docs/DEMO_ACCOUNTS.md was not found in this deployment.</p>
  <?php else: ?>
  <div style="max-height:620px;overflow:auto;border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.8rem;background:var(--panel-2,#fafafa)">
    <pre style="white-space:pre-wrap;font-size:.78rem;line-height:1.55;margin:0"><?= e($doc) ?></pre>
  </div>
  <?php endif; ?>
</div>
