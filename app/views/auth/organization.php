<?php $title = 'Choose an organization'; ?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1 style="margin-bottom:.2rem">Choose an organization</h1>
    <p class="hint" style="margin-top:0">Your email belongs to more than one organization. Which one do you want to open?</p>

    <form method="post" action="<?= BASE_PATH ?>/login/organization" class="stack">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div style="display:grid;gap:.5rem;margin:.6rem 0">
        <?php foreach ($options as $i => $o): ?>
        <label style="display:flex;align-items:center;gap:.7rem;padding:.75rem .9rem;border:1px solid var(--line);border-radius:8px;cursor:pointer">
          <input type="radio" name="user_id" value="<?= e($o['user_id']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
          <span><strong><?= e($o['tenant_name']) ?></strong>
            <span class="hint" style="display:block"><?= e(FlxCoreHC\TeamService::roleLabel((string) $o['role'])) ?></span></span>
        </label>
        <?php endforeach; ?>
      </div>
      <button class="btn" style="width:100%">Continue</button>
    </form>
    <p class="hint" style="margin-top:1rem"><a href="<?= BASE_PATH ?>/login">Sign in as someone else</a></p>
  </div>
  <?php include __DIR__ . '/_lang.php'; ?>
</div>
