<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Hub navigation: a collapsible right side panel (v1.153). Used by Organization and the Platform
 * console. The panel is fixed to the viewport's right edge; groups are <details> so only one is open
 * at a time (the one containing the current page). It can be closed entirely, leaving a small toggle
 * in the page header to reopen. On phones it is a slide-in drawer with a scrim.
 *
 * Expects: $navTitle (string), $navIcon (svg markup, optional), $navGroups (array).
 */
$__activeLabel = ''; $__activeGroup = '';
foreach ($navGroups as $__g => $__items) {
    foreach ($__items as $__i) { if (!empty($__i[2])) { $__activeLabel = $__i[0]; $__activeGroup = $__g; } }
}
?>
<div class="hub-head">
  <div class="crumb" style="display:flex;align-items:center;gap:.45rem;margin:0">
    <?= $navIcon ?? '' ?><?= e($navTitle) ?>
    <?php if ($__activeLabel !== ''): ?><span class="hint">· <?= e(t($__activeLabel)) ?></span><?php endif; ?>
  </div>
</div>

<!-- Edge tab: flows out of the right edge; same glyph as the left-menu toggle. Shown when collapsed (and on mobile). -->
<button type="button" class="hub-open-btn" data-hub-open aria-controls="hubPanel" aria-label="<?= e($navTitle) ?>" title="<?= e($navTitle) ?>">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h10M4 18h16"/></svg>
</button>

<div class="hub-scrim" data-hub-close hidden></div>
<aside class="hub-panel" id="hubPanel" aria-label="<?= e($navTitle) ?>">
  <div class="hub-panel-top">
    <span><?= e($navTitle) ?></span>
    <button type="button" class="hub-close" data-hub-close aria-label="<?= e(t('Close')) ?>" title="<?= e(t('Close')) ?>">×</button>
  </div>
  <?php foreach ($navGroups as $g => $items): ?>
    <details class="hub-group" data-hub-group <?= ($g === $__activeGroup || ($__activeGroup === '' && $g === array_key_first($navGroups))) ? 'open' : '' ?>>
      <summary><?= e(t($g)) ?></summary>
      <div class="hub-items">
        <?php foreach ($items as $i): ?>
          <a href="<?= e($i[1]) ?>" class="hub-item<?= !empty($i[2]) ? ' active' : '' ?>"><?= e(t($i[0])) ?></a>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
</aside>
<script <?= nonce_attr() ?>>
(function(){
  var body=document.body;
  body.classList.add('has-hub');
  var scrim=document.querySelector('.hub-scrim'); if(scrim) scrim.hidden=false;

  // Remember whether the operator collapsed the panel (desktop), across pages.
  try { if (sessionStorage.getItem('flxHubCollapsed')==='1') body.classList.add('hub-collapsed'); } catch(e){}

  function open(){
    body.classList.remove('hub-collapsed'); body.classList.add('hub-open');
    try { sessionStorage.setItem('flxHubCollapsed','0'); } catch(e){}
  }
  function close(){
    body.classList.add('hub-collapsed'); body.classList.remove('hub-open');
    try { sessionStorage.setItem('flxHubCollapsed','1'); } catch(e){}
  }
  document.querySelectorAll('[data-hub-open]').forEach(function(b){ b.addEventListener('click', open); });
  document.querySelectorAll('[data-hub-close]').forEach(function(b){ b.addEventListener('click', close); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });

  // Accordion: opening one group closes the others.
  var groups=document.querySelectorAll('[data-hub-group]');
  groups.forEach(function(d){
    d.addEventListener('toggle', function(){
      if(!d.open) return;
      groups.forEach(function(o){ if(o!==d) o.open=false; });
    });
  });
})();
</script>
