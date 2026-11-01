<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $sections */ /** @var ?string $cur */ /** @var string $q */ /** @var array $hits */
$L = FlxCoreHC\I18n::locale() === 'es';
$title = $L ? 'Ayuda' : 'Help';
$render = function(array $blocks): void {
    foreach ($blocks as $b) {
        [$t, $v] = $b;
        if ($t === 'h') echo '<h3 style="margin:1rem 0 .3rem;font-size:.95rem">' . e($v) . '</h3>';
        elseif ($t === 'p') echo '<p style="margin:.3rem 0 .6rem;line-height:1.55">' . e($v) . '</p>';
        elseif ($t === 'tip') echo '<div class="panel" style="border-left:3px solid var(--accent,#2563eb);padding:.5rem .8rem;margin:.6rem 0"><span class="hint">' . e($v) . '</span></div>';
        elseif ($t === 'steps') { echo '<ol style="margin:.3rem 0 .8rem 1.2rem;line-height:1.55">'; foreach ($v as $s) echo '<li>' . e($s) . '</li>'; echo '</ol>'; }
    }
};
?>
<div class="wrap">
  <div class="topline"><h1><?= e($title) ?></h1>
    <form method="get" action="<?= BASE_PATH ?>/help" style="margin:0;display:flex;gap:.4rem">
      <input name="q" value="<?= e($q) ?>" placeholder="<?= $L ? 'Buscar en la ayuda' : 'Search help' ?>" style="max-width:240px">
      <button class="btn sm"><?= $L ? 'Buscar' : 'Search' ?></button></form></div>

  <?php if ($q !== ''): ?>
    <div class="panel"><h2 style="margin-top:0"><?= $L ? 'Resultados' : 'Results' ?> · <?= count($hits) ?></h2>
      <?php if (!$hits): ?><p class="hint"><?= $L ? 'Sin coincidencias.' : 'No matches.' ?></p>
      <?php else: foreach ($hits as $key => $sec): ?>
        <div style="margin:.5rem 0"><a href="<?= BASE_PATH ?>/help/<?= e($key) ?>"><strong><?= e($sec['title']) ?></strong></a><div class="hint"><?= e($sec['summary']) ?></div></div>
      <?php endforeach; endif; ?></div>
  <?php elseif ($cur === null): ?>
    <p class="hint" style="max-width:760px"><?= $L ? 'Guías por función. Elija un tema para ver qué es, cómo usarlo y qué esperar.' : 'Guides by feature. Pick a topic to see what it is, how to use it, and what to expect.' ?></p>
    <div class="grid2">
      <?php foreach ($sections as $key => $sec): ?>
        <a class="panel" href="<?= BASE_PATH ?>/help/<?= e($key) ?>" style="display:block;text-decoration:none;color:inherit">
          <strong><?= e($sec['title']) ?></strong><div class="hint" style="margin-top:.2rem"><?= e($sec['summary']) ?></div></a>
      <?php endforeach; ?>
    </div>
  <?php else: $sec = $sections[$cur]; ?>
    <div class="crumb"><a href="<?= BASE_PATH ?>/help"><?= e($title) ?></a> › <?= e($sec['title']) ?></div>
    <div style="display:grid;grid-template-columns:220px 1fr;gap:1rem" class="help-layout">
      <nav class="panel" style="align-self:start;padding:.6rem">
        <?php foreach ($sections as $key => $s): ?>
          <a href="<?= BASE_PATH ?>/help/<?= e($key) ?>" style="display:block;padding:.35rem .5rem;border-radius:6px;text-decoration:none;<?= $key === $cur ? 'background:var(--panel-2,rgba(0,0,0,.05));font-weight:600' : '' ?>"><?= e($s['title']) ?></a>
        <?php endforeach; ?>
      </nav>
      <article class="panel" style="max-width:760px">
        <h2 style="margin-top:0"><?= e($sec['title']) ?></h2>
        <p class="hint" style="margin-top:-.2rem"><?= e($sec['summary']) ?></p>
        <?php $render($sec['blocks']); ?>
      </article>
    </div>
    <style>@media (max-width:760px){.help-layout{grid-template-columns:1fr}}</style>
  <?php endif; ?>
</div>
