<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var bool $accepted */ /** @var ?array $orgAcceptance */ /** @var bool $mayAcceptForOrg */ /** @var string $org */ /** @var ?string $effective */
$title = 'Terms of Use';
$needOrg = $mayAcceptForOrg && $orgAcceptance === null;
?>
<div class="<?= $accepted ? 'wrap' : 'auth-card' ?>" style="<?= $accepted ? '' : 'max-width:760px' ?>">
  <h1 style="font-size:1.25rem;margin:.4rem 0"><?= e(t('Terms of Use')) ?></h1>
  <?php if ($accepted): ?>
    <p class="hint"><?= e(t('You have accepted the current Terms of Use.')) ?>
      <?php if ($orgAcceptance): ?><?= e(t('Accepted for {organization} by {name} on {date}.', ['organization' => $org, 'name' => (string) ($orgAcceptance['user_name'] ?? ''), 'date' => fmt_dt((string) $orgAcceptance['accepted_at'])])) ?>
      <?php else: ?><?= e(t('The organization has not accepted them yet.')) ?><?php endif; ?></p>
  <?php else: ?>
    <p><?= e(t('Please read and accept the Terms of Use to continue.')) ?></p>
  <?php endif; ?>
  <div class="panel" style="max-height:26rem;overflow:auto"><?php require __DIR__ . '/_body.php'; ?></div>
  <?php if (!$accepted || $needOrg): ?>
  <form method="post" action="<?= BASE_PATH ?>/terms/accept" style="margin-top:1rem">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <?php if ($needOrg): ?>
      <label class="chk" style="display:flex;gap:.5rem;align-items:flex-start;font-weight:400;margin:.4rem 0">
        <input type="checkbox" name="authority" value="1">
        <span><?= e(t('I have authority to accept these Terms for {organization}', ['organization' => $org])) ?></span></label>
      <input type="hidden" name="for_organization" value="1">
    <?php endif; ?>
    <button class="btn gold" type="submit"><?= e(t('I agree')) ?></button>
  </form>
  <?php endif; ?>
  <?php if (!$accepted): ?>
  <form method="post" action="<?= BASE_PATH ?>/logout" style="margin-top:.6rem">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <button class="btn line" type="submit"><?= e(t('Sign out')) ?></button>
  </form>
  <?php endif; ?>
</div>
