<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $about */
$title = 'About & ownership';
$O = 'FlxCoreHC\\Ownership';
$full = ($about['view'] ?? 'short') === 'full';
$es = FlxCoreHC\I18n::locale() === 'es' ? $O::spanish() : null;
$kv = function (string $label, $value): void {
    echo '<tr><th style="width:230px;text-align:left;vertical-align:top">' . e(t($label)) . '</th><td>' . ($value === null || $value === '' ? '<span class="hint">—</span>' : e((string) $value)) . '</td></tr>';
};
?>
<div class="wrap">
  <div class="topline"><h1><?= e(t('About & ownership')) ?></h1>
    <a class="btn sm line" href="<?= BASE_PATH ?>/help"><?= e(t('Help')) ?></a></div>

  <div class="panel">
    <h2 style="margin-top:0"><?= e($about['product']) ?> <span class="hint" style="font-weight:400">— <?= e(t($about['product_line'])) ?></span></h2>
    <!--flx-notranslate-->
    <p><?= e($about['developed_by']) ?></p>
    <p><?= e($about['independent_developer']) ?></p>
    <!--/flx-notranslate-->
    <?php if ($es): ?>
      <div class="hint" style="border-left:3px solid var(--line,#ddd);padding-left:.8rem;margin:.6rem 0">
        <strong><?= e(t('Courtesy translation (informational; the English text controls)')) ?></strong>
        <p><?= e($es['developed']) ?></p><p><?= e($es['independent']) ?></p>
      </div>
    <?php endif; ?>
    <p><strong><?= e($about['copyright']) ?></strong></p>
    <table class="table-simple" style="width:100%;margin-top:.6rem">
      <?php $kv('Version', $about['version']); $kv('Licensed to', $about['licensed_to']); ?>
    </table>
    <p class="hint" style="margin-top:.8rem"><?= e(t($about['open_source'])) ?></p>
  </div>

  <?php if ($full): ?>
  <div class="panel">
    <h2 style="margin-top:0"><?= e(t('This workspace')) ?></h2>
    <table class="table-simple" style="width:100%">
      <?php
        $kv('Owner', $about['owner']);
        $kv('Licensed to', $about['licensed_to']);
        $kv('Agreement number', $about['agreement_number'] ?? t($about['license_record']));
        $kv('Deployment model', t('Hosted service'));
        $kv('Term', $about['term']);
        $kv('Plan', $about['plan']);
        $kv('Modules', $about['modules'] ? implode(', ', $about['modules']) : '');
        $kv('User limit', $about['user_limit'] === null ? t('Unlimited') : $about['user_limit']);
        $kv('Users in use', $about['users_in_use']);
        $kv('Name shown to people', $about['name_shown']);
        $kv('Workspace ID', $about['workspace_id']);
      ?>
    </table>
  </div>
  <div class="panel">
    <h2 style="margin-top:0"><?= e(t('License terms in short')) ?></h2>
    <!--flx-notranslate-->
    <ol style="line-height:1.55;padding-left:1.2rem">
      <?php foreach ($about['license_terms'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
    </ol>
    <p><strong><?= e($about['license_terms_closing']) ?></strong></p>
    <!--/flx-notranslate-->
    <?php if ($es): ?>
      <div class="hint" style="border-left:3px solid var(--line,#ddd);padding-left:.8rem;margin:.6rem 0">
        <strong><?= e(t('Courtesy translation (informational; the English text controls)')) ?></strong>
        <ol style="line-height:1.55;padding-left:1.2rem"><?php foreach ($es['terms'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ol>
        <p><strong><?= e($es['closing']) ?></strong></p>
      </div>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h2 style="margin-top:0"><?= e(t('License notice (LICENSE.txt)')) ?></h2>
    <!--flx-notranslate-->
    <pre style="max-height:20rem;overflow:auto;white-space:pre-wrap;font-size:.78rem;line-height:1.45;background:var(--surface-2,#f6f6f4);padding:.8rem;border-radius:8px"><?= e($about['license_text'] !== '' ? $about['license_text'] : FlxCoreHC\Ownership::NO_LICENSE) ?></pre>
    <!--/flx-notranslate-->
  </div>
  <div class="panel">
    <h2 style="margin-top:0"><?= e(t('Third-party components')) ?></h2>
    <!--flx-notranslate-->
    <pre style="max-height:20rem;overflow:auto;white-space:pre-wrap;font-size:.78rem;line-height:1.45;background:var(--surface-2,#f6f6f4);padding:.8rem;border-radius:8px"><?= e($about['third_party_notices']) ?></pre>
    <!--/flx-notranslate-->
  </div>
  <?php endif; ?>
</div>
