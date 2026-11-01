<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $sources */ /** @var array $due */
$title = 'Platform console — Regulatory sources'; $adminTab = 'regulatory'; require FLXCOREHC_APP . '/views/admin/_tabs.php'; ?>
<div class="topline"><h1>Regulatory sources</h1><?php if ($due): ?><span class="chip warn"><?= count($due) ?> due for review</span><?php endif; ?></div>
<p class="hint" style="max-width:820px">The dated citation behind every requirement. Review each on its cadence; when a law changes (as telehealth did with Ley 8-2025), record the superseded date and the new source so tenants see current rules.</p>
<div class="grid2" style="gap:1rem">
  <div class="panel">
    <?php foreach ($sources as $s): $stale = in_array($s, $due, true); ?>
    <details style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.5rem .7rem;margin-bottom:.5rem;<?= $stale ? 'border-left:3px solid var(--warn,#c47f1a)' : '' ?>">
      <summary style="cursor:pointer"><strong><?= e($s['authority']) ?></strong> <span class="mono hint"><?= e($s['code']) ?></span><?= $stale ? ' <span class="chip warn">review</span>' : '' ?></summary>
      <form method="post" action="<?= BASE_PATH ?>/admin/regulatory" class="stack" style="margin-top:.5rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="code" value="<?= e($s['code']) ?>">
        <div><label>Authority</label><input name="authority" value="<?= e($s['authority']) ?>" required></div>
        <div><label>Regulation / law / circular</label><input name="regulation" value="<?= e($s['regulation'] ?? '') ?>"></div>
        <div><label>URL</label><input name="url" value="<?= e($s['url'] ?? '') ?>"></div>
        <div class="grid2"><div><label>Effective</label><input type="date" name="effective_date" value="<?= e($s['effective_date'] ?? '') ?>"></div><div><label>Superseded</label><input type="date" name="superseded_date" value="<?= e($s['superseded_date'] ?? '') ?>"></div></div>
        <div class="grid2"><div><label>Last reviewed</label><input type="date" name="last_reviewed" value="<?= e($s['last_reviewed'] ?? '') ?>"></div><div><label>Review every (days)</label><input type="number" name="review_every_days" value="<?= (int)$s['review_every_days'] ?>" min="30"></div></div>
        <div><label>Verified by</label><input name="verified_by" value="<?= e($s['verified_by'] ?? '') ?>"></div>
        <div><label>Summary</label><textarea name="summary" rows="3"><?= e($s['summary'] ?? '') ?></textarea></div>
        <div><button class="btn sm">Save</button></div>
      </form>
    </details>
    <?php endforeach; ?>
  </div>
  <div class="panel">
    <h2 style="margin-top:0;font-size:1rem">Add a source</h2>
    <form method="post" action="<?= BASE_PATH ?>/admin/regulatory" class="stack"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label>Code</label><input name="code" placeholder="e.g. pr_controlled_substance" required></div>
      <div><label>Authority</label><input name="authority" required></div>
      <div><label>Regulation / law / circular</label><input name="regulation"></div>
      <div><label>URL</label><input name="url"></div>
      <div class="grid2"><div><label>Effective</label><input type="date" name="effective_date"></div><div><label>Review every (days)</label><input type="number" name="review_every_days" value="180"></div></div>
      <div><label>Summary</label><textarea name="summary" rows="3"></textarea></div>
      <div><button class="btn">Add source</button></div>
    </form>
  </div>
</div>
