<?php
/** @var FlxCoreHC\TenantContext $ctx */
/** @var array $rows */ /** @var string $glabel */ /** @var string $glabelPlural */ /** @var array|null $edit */
$title = $glabelPlural; $e = $edit ?? null;
?>
<div class="wrap">
  <div class="topline"><h1><?= e(t($glabelPlural)) ?></h1></div>
  <p class="hint"><?= e(t('Groups of providers within this organization. A provider can belong to several; one can be marked primary.')) ?></p>
  <div class="panel">
    <?php if ($rows === []): ?>
      <p class="hint"><?= e(t('None yet. Add your first below.')) ?></p>
    <?php else: ?>
    <table>
      <tr><th><?= e(t('Name')) ?></th><th><?= e(t('Code')) ?></th><th>NPI</th><th class="right"><?= e(t('Providers')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><a href="<?= BASE_PATH ?>/groups/<?= e($r['id']) ?>"><?= e($r['name']) ?></a></strong>
          <?php if (!empty($r['note'])): ?><span class="sub"><?= e($r['note']) ?></span><?php endif; ?></td>
        <td class="mono"><?= e($r['code'] ?: '—') ?></td>
        <td class="mono"><?= e($r['npi'] ?: '—') ?></td>
        <td class="right"><?= (int)$r['member_count'] ?></td>
        <td><span class="chip <?= $r['status'] === 'active' ? 'active' : '' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/groups?edit=<?= e($r['id']) ?>"><?= e(t('Edit')) ?></a>
          <form method="post" action="<?= BASE_PATH ?>/groups" style="display:inline" data-confirm="Remove?">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <button class="btn sm line"><?= e(t('Delete')) ?></button></form></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <div class="panel" style="margin-top:1.2rem">
    <h2 style="margin-top:0"><?= $e ? e(t('Edit {what}', ['what' => t($glabel)])) : e(t('Add {what}', ['what' => t($glabel)])) ?></h2>
    <form method="post" action="<?= BASE_PATH ?>/groups" class="stack" style="max-width:720px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="<?= $e ? 'update' : 'create' ?>">
      <?php if ($e): ?><input type="hidden" name="id" value="<?= e($e['id']) ?>"><?php endif; ?>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <div style="flex:2;min-width:220px"><label><?= e(t('Name')) ?></label><input name="name" required maxlength="160" value="<?= e($e['name'] ?? '') ?>" style="width:100%"></div>
        <div style="flex:1;min-width:110px"><label><?= e(t('Code')) ?> <span class="hint">(<?= e(t('optional')) ?>)</span></label><input name="code" maxlength="40" value="<?= e($e['code'] ?? '') ?>" style="width:100%"></div>
        <div style="flex:1;min-width:110px"><label>NPI <span class="hint">(<?= e(t('optional')) ?>)</span></label><input name="npi" maxlength="10" value="<?= e($e['npi'] ?? '') ?>" style="width:100%"></div>
      </div>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:end">
        <div><label><?= e(t('Status')) ?></label>
          <select name="status">
            <option value="active" <?= ($e['status'] ?? 'active') === 'active' ? 'selected' : '' ?>><?= e(t('Active')) ?></option>
            <option value="inactive" <?= ($e['status'] ?? '') === 'inactive' ? 'selected' : '' ?>><?= e(t('Inactive')) ?></option>
          </select></div>
        <div style="flex:1;min-width:200px"><label><?= e(t('Note')) ?></label><input name="note" maxlength="255" value="<?= e($e['note'] ?? '') ?>" style="width:100%"></div>
      </div>
      <div><button class="btn"><?= $e ? e(t('Save')) : e(t('Add {what}', ['what' => t($glabel)])) ?></button>
        <?php if ($e): ?><a class="btn line" href="<?= BASE_PATH ?>/groups"><?= e(t('Cancel')) ?></a><?php endif; ?></div>
    </form>
  </div>
</div>
