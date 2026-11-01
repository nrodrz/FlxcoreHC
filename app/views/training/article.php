<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $article */
$title = $article['title'];
$cats = FlxCoreHC\TrainingLibraryService::CATEGORIES;
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/training">Training Center</a></div>
<div class="topline"><h1><?= e($article['title']) ?></h1></div>
<div class="panel" style="max-width:760px">
  <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem">
    <span class="chip" style="text-transform:uppercase;font-size:.66rem"><?= e($cats[$article['category']] ?? $article['category']) ?></span>
    <?php if ($article['minutes']): ?><span class="hint"><?= e(t('{n} min read', ['n' => (int)$article['minutes']])) ?></span><?php endif; ?>
    <?php if (!empty($article['updated_at'])): ?><span class="hint">· updated <?= e(fmt_day(fmt_dt((string)$article['updated_at'], 'm/d/Y'))) ?></span><?php endif; ?>
  </div>
  <?php if ($article['summary']): ?><p style="font-size:1.02rem;color:var(--ink-2);margin:.2rem 0 1rem"><?= e($article['summary']) ?></p><?php endif; ?>
  <?= FlxCoreHC\TrainingLibraryService::renderBody((string)$article['body']) ?>
</div>

<?php $sources = $sources ?? []; if ($sources || !empty($article['link'])):
  $authLabel = FlxCoreHC\TrainingLibraryService::AUTHORITY_LABEL; ?>
<div class="panel" style="max-width:760px;border-left:3px solid var(--accent)">
  <div class="topline"><h2 style="font-size:1rem">Sources &amp; references</h2></div>
  <p class="hint" style="margin-top:-.3rem">Verify this guidance straight from the authority. Rules and dates can change — always confirm on the official site.</p>
  <div style="display:grid;gap:.5rem">
    <?php foreach ($sources as $src): ?>
    <a href="<?= e($src['url']) ?>" target="_blank" rel="noopener noreferrer"
       style="display:flex;align-items:center;gap:.6rem;padding:.6rem .7rem;border:1px solid var(--line);border-radius:var(--r-sm)">
      <span class="chip" style="text-transform:uppercase;font-size:.64rem;white-space:nowrap"><?= e($authLabel[$src['authority']] ?? $src['authority']) ?></span>
      <span style="flex:1;font-weight:600"><?= e($src['label']) ?></span>
      <span class="hint" style="white-space:nowrap"><?= e(parse_url($src['url'], PHP_URL_HOST) ?: '') ?> ↗</span>
    </a>
    <?php endforeach; ?>
    <?php if (!$sources && !empty($article['link'])): ?>
    <a href="<?= e($article['link']) ?>" target="_blank" rel="noopener noreferrer" class="btn line"><?= e($article['link_label'] ?: 'Open source') ?> ↗</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div><a class="btn sm line" href="<?= BASE_PATH ?>/training">← All training</a></div>
