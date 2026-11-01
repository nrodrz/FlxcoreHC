<?php /** @var string $reason */ $title = 'Link unavailable'; ?>
<?php require __DIR__ . '/_lang.php'; ?>
<div class="auth-card" style="max-width:440px;margin:3rem auto;text-align:center">
  <h1 style="font-size:1.2rem"><?= e(t('This link is no longer available')) ?></h1>
  <p class="hint"><?= e(t($reason)) ?></p>
  <p class="hint" style="font-size:.75rem"><?= e(t('If you still need this profile, ask the organization that shared it to send a new link.')) ?></p>
</div>
