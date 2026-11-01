<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $agents */ /** @var array $types */
$types = $types ?? [];
$title='Add task / event'; ?>
<div class="crumb"><a href="<?= BASE_PATH ?>/calendar">Calendar</a></div>
<div class="topline"><h1>Add task / event</h1></div>
<div class="panel"><form class="stack" method="post" action="<?= BASE_PATH ?>/calendar">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <div class="grid2">
    <div><label>Type</label><select name="kind">
      <?php foreach($types as $t): ?><option value="<?= e($t['code']) ?>"><?= e($t['label']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>Priority</label><select name="priority"><option>normal</option><option>high</option><option>low</option></select></div>
  </div>
  <div><label>Title</label><input name="title" required placeholder="Call back, annual review, follow up…"></div>
  <div class="grid2">
    <div><label>Date &amp; time</label><input name="due_at" type="datetime-local" required></div>
    <div><label><?= e(vocab('holder')) ?> (optional)</label><select name="holder_id"><option value="">—</option>
      <?php foreach($agents as $a): ?><option value="<?= e($a['id']) ?>"><?= e(trim(($a['first_name']??'').' '.($a['last_name']??''))) ?></option><?php endforeach; ?>
    </select></div>
  </div>
  <div><label>Detail</label><textarea name="detail" rows="2"></textarea></div>
  <div><button class="btn">Add to calendar</button> <a class="btn line" href="<?= BASE_PATH ?>/calendar">Cancel</a></div>
</form></div>
