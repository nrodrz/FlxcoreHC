<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $addresses */
$title = 'Addresses'; $__path = '/organization/addresses'; require FLXCOREHC_APP . '/views/org/_tabs.php'; ?>
<div class="topline"><h1><?= e(t('Organization addresses')) ?></h1></div>
<p class="hint" style="max-width:780px"><?= e(t('Mailing, billing, correspondence and legal addresses for your organization — distinct from practice locations. Credentials and enrollments can cite the address on file with each issuer or payer.')) ?></p>
<div class="panel">
  <?php $base = BASE_PATH . '/organization/addresses'; $canEdit = FlxCoreHC\Authz::can($ctx, 'org.manage'); require FLXCOREHC_APP . '/views/_address_list.php'; ?>
</div>
<div class="panel" style="margin-top:1rem">
  <h2 style="margin-top:0;font-size:1rem"><?= e(t('Add an address')) ?></h2>
  <?php $action = BASE_PATH . '/organization/addresses'; $a = null; require FLXCOREHC_APP . '/views/_address_form.php'; ?>
</div>
