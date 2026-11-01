<?php /** @var array $types */ $title='Event types'; ?>
<div class="crumb"><a href="<?= BASE_PATH ?>/calendar">Calendar</a></div>
<div class="topline"><h1>Event types</h1></div>
<p class="hint" style="margin-top:-.4rem">The types available when adding a calendar event. Add your own; the system “Compliance deadline” type is used by auto-generated credential deadlines and can't be removed.</p>
<div class="panel">
  <table><tr><th>Type</th><th>Color</th><th>Status</th><th></th></tr>
  <?php foreach ($types as $t): ?>
    <tr>
      <td><strong><?= e($t['label']) ?></strong> <span class="sub mono"><?= e($t['code']) ?></span><?php if ($t['is_system']): ?> <span class="chip">system</span><?php endif; ?></td>
      <td><?php if (!empty($t['color'])): ?><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= e($t['color']) ?>;vertical-align:middle"></span> <span class="mono"><?= e($t['color']) ?></span><?php endif; ?></td>
      <td><span class="chip <?= $t['is_active'] ? 'active' : '' ?>"><?= $t['is_active'] ? 'active' : 'hidden' ?></span></td>
      <td><?php if (!$t['is_system']): ?>
        <form method="post" action="<?= BASE_PATH ?>/calendar/types" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($t['id']) ?>"><button class="btn sm line"><?= $t['is_active'] ? 'Hide' : 'Show' ?></button></form>
        <form method="post" action="<?= BASE_PATH ?>/calendar/types" style="display:inline" data-confirm="Remove this event type?" data-confirm-danger><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($t['id']) ?>"><button class="btn sm danger">Remove</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
</div>
<div class="panel">
  <h2>Add a type</h2>
  <form method="post" action="<?= BASE_PATH ?>/calendar/types">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="add">
    <div class="grid2">
      <div><label>Name</label><input type="text" name="label" required maxlength="60" placeholder="e.g. Audit, Training, Peer review"></div>
      <div><label>Color</label><input type="color" name="color" value="#2f6fd6"></div>
    </div>
    <button class="btn">Add type</button>
  </form>
</div>
