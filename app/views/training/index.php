<?php /** @var array $library */ /** @var array $resources */ /** @var array $regulators */
/** @var bool $canManage */ /** @var string $q */ /** @var ?array $results */ /** @var bool $available */
$title = 'Training Center';
$cats = FlxCoreHC\TrainingLibraryService::CATEGORIES;
$sourceCounts = $sourceCounts ?? [];
$catChip = fn($c)=> '<span class="chip" style="text-transform:uppercase;font-size:.66rem">'.e($cats[$c] ?? $c).'</span>';
$byCat = [];
foreach ($library as $a) { $byCat[$a['category']][] = $a; }
?>
<?php $hc = isset($ctx) && $ctx->vertical === 'healthcare'; ?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1>Training Center</h1></div>
<p class="hint" style="margin-top:-.4rem"><?= $hc ? "Reference guides for the credentialing lifecycle — licenses, DEA, CLIA, CAQH, board certification, and more. Add your own SOPs and payer materials below." : "Reference guides for the credentialing lifecycle." ?></p>

<div class="panel">
  <form method="get" action="<?= BASE_PATH ?>/training" style="display:flex;gap:.5rem;flex-wrap:wrap">
    <input name="q" value="<?= e($q) ?>" placeholder="Ask a question — e.g. “DEA renewal”, “CLIA certificate”, “CAQH attestation”" style="flex:1;min-width:240px" autocomplete="off">
    <button class="btn">Ask</button>
    <?php if ($q !== ''): ?><a class="btn line" href="<?= BASE_PATH ?>/training">Clear</a><?php endif; ?>
  </form>
  <?php if ($results !== null): ?>
    <div style="margin-top:.9rem">
      <?php if ($results === []): ?>
        <p class="hint" style="margin:0">No matches for “<?= e($q) ?>”. Try a keyword like DEA, CLIA, license, NPI, or malpractice.</p>
      <?php else: ?>
        <div class="hint" style="margin-bottom:.5rem">Top matches for “<?= e($q) ?>”:</div>
        <div style="display:grid;gap:.5rem">
        <?php foreach ($results as $r): ?>
          <a class="rowcard" href="<?= BASE_PATH ?>/training/<?= e($r['code']) ?>" style="display:block;padding:.7rem .8rem;border:1px solid var(--line);border-radius:var(--r-sm)">
            <strong><?= e($r['title']) ?></strong> <?= $catChip($r['category']) ?>
            <span class="sub" style="margin-top:.2rem"><?= e($r['summary']) ?></span>
          </a>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!$available): ?>
  <div class="panel"><div class="empty">The training library isn't installed yet — a platform admin can apply it from
    <a href="<?= BASE_PATH ?>/admin/system">System &amp; updates → Update database</a>.</div></div>
<?php else: ?>
<div class="topline" style="margin-top:.4rem"><h2>Reference library</h2><span class="hint"><?= $hc ? "credentialing reference — links to official sources" : "credentialing reference" ?></span></div>
<?php foreach ($byCat as $cat => $list): ?>
<div class="panel">
  <div class="topline"><h3 style="font-size:.98rem"><?= e($cats[$cat] ?? ucfirst($cat)) ?></h3></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:.7rem">
    <?php foreach ($list as $a): ?>
    <a class="rowcard" href="<?= BASE_PATH ?>/training/<?= e($a['code']) ?>" style="display:block;padding:.8rem .9rem;border:1px solid var(--line);border-radius:var(--r-sm)">
      <strong><?= e($a['title']) ?></strong>
      <span class="sub" style="margin:.25rem 0 .4rem"><?= e($a['summary']) ?></span>
      <span class="hint"><?= $a['minutes'] ? e(t('{n} min read', ['n' => (int)$a['minutes']])) : e(t('Reference')) ?><?php $sc = ($sourceCounts[$a['code']] ?? 0); if ($sc): ?> · <?= e($sc === 1 ? t('1 source') : t('{n} sources', ['n' => $sc])) ?><?php endif; ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="topline" style="margin-top:.4rem"><h2>Your resources</h2><span class="hint"><?= count($resources) ?></span></div>
<div class="panel">
  <?php if ($resources === []): ?>
    <p class="hint" style="margin:0<?= $canManage?';margin-bottom:.6rem':'' ?>">No custom resources yet. <?= $canManage ? 'Add payer portal links, SOPs, or study notes below.' : 'Your administrators haven’t added any yet.' ?></p>
  <?php else: ?>
    <div style="display:grid;gap:.6rem">
    <?php foreach ($resources as $r): ?>
      <div style="display:flex;justify-content:space-between;gap:.8rem;padding:.7rem .8rem;border:1px solid var(--line);border-radius:var(--r-sm)">
        <div>
          <strong><?= e($r['title']) ?></strong>
          <?php if (!empty($r['regulator_name'])): ?><span class="chip"><?= e($r['regulator_name']) ?></span><?php endif; ?>
          <?php if ($r['body']): ?><span class="sub" style="margin-top:.2rem"><?= e($r['body']) ?></span><?php endif; ?>
          <?php if ($r['link']): ?><a href="<?= e($r['link']) ?>" target="_blank" rel="noopener" class="hint" style="color:var(--accent)"><?= e($r['link']) ?></a><?php endif; ?>
        </div>
        <?php if ($canManage): ?>
        <form method="post" action="<?= BASE_PATH ?>/training/<?= e($r['id']) ?>/delete" data-confirm="Remove this resource?" data-confirm-danger style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line" style="color:var(--danger)">Remove</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <details style="margin-top:1rem">
    <summary class="btn sm line" style="list-style:none;cursor:pointer;display:inline-block">+ Add a resource</summary>
    <form method="post" action="<?= BASE_PATH ?>/training" class="form-grid" style="margin-top:.8rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label>Title</label><input name="title" required placeholder="e.g. Humana provider portal"></div>
      <div><label>Category</label><select name="category">
        <?php foreach ($cats as $k=>$v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Regulator / payer (optional)</label><select name="regulator_id"><option value="">—</option>
        <?php foreach ($regulators as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Link (optional)</label><input name="link" type="url" placeholder="https://…"></div>
      <div class="full"><label>Notes (optional)</label><textarea name="body" rows="2" placeholder="What providers should know or do"></textarea></div>
      <div class="full"><button class="btn">Add resource</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>
