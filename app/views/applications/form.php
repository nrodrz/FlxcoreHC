<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $holderList */ /** @var array $regulatorList */
$K = FlxCoreHC\Repository\ApplicationRepository::KINDS;
$app = $app ?? [];
$preHolder = $preHolder ?? '';
$hname = function(array $h) { return ($h['holder_type'] ?? '') === 'organization'
    ? (string)($h['org_name'] ?? '') : trim(($h['first_name'] ?? '').' '.($h['last_name'] ?? '')); };
$title = ($app ? 'Edit' : 'New') . ' application'; ?>
<div class="topline"><h1><?= $app ? 'Edit application' : 'New application' ?></h1>
  <a class="btn sm line" href="<?= BASE_PATH ?>/applications">All applications</a></div>
<div class="panel">
  <form method="post" action="<?= BASE_PATH ?>/applications">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="save">
    <?php if ($app): ?><input type="hidden" name="id" value="<?= e($app['id']) ?>"><?php endif; ?>
    <div class="grid2">
      <div><label>Provider</label>
        <select name="holder_id" <?= $app ? 'disabled' : '' ?>>
          <option value="">Choose a provider…</option>
          <?php foreach ($holderList as $h): $sel = ($app['holder_id'] ?? $preHolder) === $h['id']; ?>
            <option value="<?= e($h['id']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($hname($h)) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($app): ?><input type="hidden" name="holder_id" value="<?= e($app['holder_id']) ?>"><?php endif; ?>
      </div>
      <div><label>Regulator / payer <span class="hint" style="display:inline">(optional)</span></label>
        <select name="regulator_id"><option value="">—</option>
          <?php foreach ($regulatorList as $rg): ?><option value="<?= e($rg['id']) ?>" <?= ($app['regulator_id'] ?? '') === $rg['id'] ? 'selected' : '' ?>><?= e($rg['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Type</label><select name="kind"><?php foreach ($K as $k => $v): ?><option value="<?= e($k) ?>" <?= ($app['kind'] ?? 'enrollment') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Title</label><input type="text" name="title" required value="<?= e($app['title'] ?? '') ?>" placeholder="e.g. Medicare enrollment, Aetna credentialing"></div>
      <div><label>Reference # <span class="hint" style="display:inline">(PECOS/tracking, optional)</span></label><input type="text" name="reference" value="<?= e($app['reference'] ?? '') ?>"></div>
      <div><label>Due / deadline <span class="hint" style="display:inline">(optional)</span></label><input type="date" name="due_on" value="<?= e($app['due_on'] ?? '') ?>"></div>
    </div>
    <div><label>Notes</label><input type="text" name="notes" maxlength="600" value="<?= e($app['notes'] ?? '') ?>"></div>
    <button class="btn" type="submit"><?= $app ? 'Save' : 'Create application' ?></button>
  </form>
</div>
