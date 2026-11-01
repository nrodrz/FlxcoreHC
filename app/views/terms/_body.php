<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** The Terms of Use text. Needs $org (organization name) and $effective (UTC start of the workspace, or null). English controls; in Spanish a courtesy translation follows. */
$__en = FlxCoreHC\TermsService::text('en', (string) $org, $effective);
$__es = FlxCoreHC\I18n::locale() === 'es' ? FlxCoreHC\TermsService::text('es', (string) $org, $effective) : null;
$__render = function (array $d): void {
    foreach ($d['sections'] as $i => [$h, $paras]) {
        echo '<h3 style="margin:1rem 0 .3rem;font-size:.95rem">' . ($i + 1) . '. ' . e($h) . '</h3>';
        foreach ($paras as $p) echo '<p style="margin:.3rem 0 .6rem;line-height:1.55">' . e($p) . '</p>';
    }
};
?>
<p class="hint" style="margin:.2rem 0 .6rem"><strong><?= e(t('Version')) ?> <?= e($__en['version']) ?></strong>
  <?php if ($__en['effective'] !== ''): ?> · <?= e(t('Effective')) ?> <?= e($__en['effective']) ?><?php endif; ?></p>
<p class="error" style="margin:.2rem 0 .8rem"><?= e(t($__en['draft'])) ?></p>
<!--flx-notranslate-->
<?php $__render($__en); ?>
<!--/flx-notranslate-->
<?php if ($__es): ?>
  <div class="hint" style="border-left:3px solid var(--line,#ddd);padding-left:.8rem;margin:1rem 0">
    <strong><?= e(t('Courtesy translation (informational; the English text controls)')) ?></strong>
    <!--flx-notranslate--><?php $__render($__es); ?><!--/flx-notranslate-->
  </div>
<?php endif; ?>
