<?php /** @var array $app */ /** @var array $history */ /** @var array $holderList */ /** @var array $regulatorList */
$K = FlxCoreHC\Repository\ApplicationRepository::KINDS;
$S = FlxCoreHC\Repository\ApplicationRepository::STATUSES;
$chip = function(string $s) { return match($s) {
    'approved' => 'active', 'denied' => 'expired', 'under_review','submitted' => 'gold',
    'withdrawn' => '', default => 'warn' }; };
$hname = function($id) use ($holderList) { foreach ($holderList as $h) if ($h['id'] === $id)
    return ($h['holder_type'] ?? '') === 'organization' ? (string)$h['org_name'] : trim($h['first_name'].' '.$h['last_name']); return ''; };
$regName = function($id) use ($regulatorList) { foreach ($regulatorList as $r) if ($r['id'] === $id) return $r['name']; return null; };
$title = $app['title']; ?>
<div class="topline"><h1><?= e($app['title']) ?></h1>
  <div style="display:flex;gap:.5rem">
    <a class="btn sm line" href="<?= BASE_PATH ?>/applications/<?= e($app['id']) ?>/edit-form">Edit</a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/applications">All applications</a>
  </div></div>
<p class="hint" style="margin-top:-.4rem">
  <?= e($hname($app['holder_id'])) ?> · <?= e($K[$app['kind']] ?? $app['kind']) ?>
  <?php if ($app['regulator_id']): ?> · <?= e($regName($app['regulator_id'])) ?><?php endif; ?>
  <?php if (!empty($app['reference'])): ?> · <span class="mono"><?= e($app['reference']) ?></span><?php endif; ?>
</p>

<div class="panel">
  <div class="topline"><h2>Status</h2>
    <span class="chip <?= $chip($app['status']) ?>" style="font-size:.8rem"><?= e($S[$app['status']] ?? $app['status']) ?></span></div>
  <div class="hint" style="margin:.2rem 0 .8rem">
    <?php if ($app['submitted_on']): ?>Submitted <span class="mono"><?= e(fmt_day((string)$app['submitted_on'])) ?></span>. <?php endif; ?>
    <?php if ($app['decision_on']): ?>Decision <span class="mono"><?= e(fmt_day((string)$app['decision_on'])) ?></span>. <?php endif; ?>
    <?php if ($app['due_on']): ?>Due <span class="mono"><?= e(fmt_day((string)$app['due_on'])) ?></span>.<?php endif; ?>
  </div>
  <form method="post" action="<?= BASE_PATH ?>/applications/<?= e($app['id']) ?>">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="status">
    <div class="grid2">
      <div><label>Advance to</label><select name="status">
        <?php foreach ($S as $k => $v): ?><option value="<?= e($k) ?>" <?= $app['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Note <span class="hint" style="display:inline">(optional)</span></label><input type="text" name="note" maxlength="400" placeholder="what changed / next step"></div>
    </div>
    <button class="btn sm" type="submit">Update status</button>
  </form>
  <?php if (!empty($app['notes'])): ?><p class="hint" style="margin-top:.7rem"><strong>Notes:</strong> <?= e($app['notes']) ?></p><?php endif; ?>
</div>

<div class="panel">
  <h2>Status history</h2>
  <?php if ($history === []): ?><p class="hint">No history yet.</p>
  <?php else: ?>
    <table class="tbl"><tr><th>When</th><th>Status</th><th>Note</th><th>By</th></tr>
      <?php foreach ($history as $h): ?>
        <tr><td class="mono"><?= e(fmt_dt((string)$h['changed_at'], 'm/d/Y g:i A')) ?></td>
          <td><span class="chip <?= $chip($h['status']) ?>"><?= e($S[$h['status']] ?? $h['status']) ?></span></td>
          <td><?= e($h['note'] ?: '—') ?></td>
          <td><?= e($h['changed_by'] ?: '—') ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php if (FlxCoreHC\Authz::can($ctx, 'records.delete')): ?>
<form method="post" action="<?= BASE_PATH ?>/applications/<?= e($app['id']) ?>" data-confirm="Remove this application and its history?" data-confirm-danger style="margin-top:.4rem">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete">
  <button class="btn danger sm">Remove application</button>
</form>
<?php endif; ?>
