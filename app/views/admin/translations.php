<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var string $locale */ /** @var array $groups */ /** @var string $q */ /** @var bool $onlyMissing */
$title = 'Platform console — Translations';
$adminTab = 'translations';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
$langName = ['es' => 'Spanish', 'en' => 'English'][$locale] ?? strtoupper($locale);
$totalRows = 0; $totalMissing = 0;
foreach ($groups as $rows) { foreach ($rows as $r) { $totalRows++; if ($r['target'] === null || $r['target'] === '') $totalMissing++; } }
// friendly group titles
$gtitle = function(string $k): string {
    if (str_starts_with($k, 'console:')) return 'Console — ' . ucfirst(substr($k, 8));
    $names = ['dashboard'=>'Dashboard','providers'=>'Providers','credentials'=>'Credentials','calendar'=>'Calendar',
      'team'=>'Team','settings'=>'Organization & settings','reports'=>'Reports','regulators'=>'Regulators',
      'templates'=>'Templates','groups'=>'Groups','locations'=>'Locations','notifications'=>'Notifications',
      'documents'=>'Documents','portal'=>'Provider portal','billing'=>'Billing','console'=>'Console','general'=>'General / shared'];
    return $names[$k] ?? ucfirst(str_replace('_',' ',$k));
};
?>
<div class="topline"><h1>UI translations — <?= e($langName) ?></h1></div>
<p class="hint" style="max-width:820px">Grouped by the screen each string appears on. Groups are collapsed by default — open one, fill in the
  <?= e($langName) ?> column, and press <strong>Save</strong> on that group. Each group saves on its own, so you never lose work.
  Switch your language to <?= e($langName) ?> and browse to collect more strings. Leave a field blank to fall back to English.</p>

<form method="get" action="<?= BASE_PATH ?>/admin/translations" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:0 0 1rem">
  <input type="hidden" name="locale" value="<?= e($locale) ?>">
  <input name="q" value="<?= e($q) ?>" placeholder="Search English or translated text" style="max-width:320px">
  <label style="font-weight:normal"><input type="checkbox" name="missing" value="1" <?= $onlyMissing ? 'checked' : '' ?>> Untranslated only</label>
  <button class="btn sm">Filter</button>
  <span class="hint"><?= (int)$totalRows ?> string(s) · <?= (int)$totalMissing ?> untranslated · <?= count($groups) ?> group(s)</span>
</form>

<?php if ($groups === []): ?>
  <div class="panel"><p class="hint">Nothing collected yet. Switch your language to <?= e($langName) ?> (user menu), browse a few pages, then return — strings appear here grouped by screen.</p></div>
<?php else: foreach ($groups as $gkey => $rows):
    $gMissing = count(array_filter($rows, fn($r) => $r['target'] === null || $r['target'] === '')); ?>
  <details class="panel tr-group" style="margin-bottom:.6rem" <?= ($q !== '' || $onlyMissing) ? 'open' : '' ?>>
    <summary style="cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:.5rem;font-weight:600">
      <span><?= e($gtitle($gkey)) ?> <span class="hint" style="font-weight:400">· <?= count($rows) ?> string(s)</span></span>
      <?php if ($gMissing): ?><span class="chip danger"><?= (int)$gMissing ?> untranslated</span><?php else: ?><span class="chip active">complete</span><?php endif; ?>
    </summary>
    <form method="post" action="<?= BASE_PATH ?>/admin/translations" style="margin-top:.6rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="locale" value="<?= e($locale) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
      <input type="hidden" name="missing" value="<?= $onlyMissing ? '1' : '' ?>"><input type="hidden" name="group" value="<?= e($gkey) ?>">
      <table>
        <tr><th style="width:44%">English</th><th><?= e($langName) ?></th></tr>
        <?php foreach ($rows as $r): $h = sha1($r['source']); $miss = ($r['target'] === null || $r['target'] === ''); ?>
        <tr <?= $miss ? 'style="background:rgba(190,75,66,.05)"' : '' ?>>
          <td><?= e($r['source']) ?>
            <input type="hidden" name="tr[<?= $h ?>][source]" value="<?= e($r['source']) ?>">
            <input type="hidden" name="tr[<?= $h ?>][was]" value="<?= e((string)$r['target']) ?>"></td>
          <td><input name="tr[<?= $h ?>][target]" value="<?= e((string)$r['target']) ?>" style="width:100%" placeholder="<?= $miss ? 'Enter ' . e($langName) : '' ?>"></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <div style="margin-top:.6rem"><button class="btn">Save <?= e($gtitle($gkey)) ?></button></div>
    </form>
  </details>
<?php endforeach; endif; ?>
