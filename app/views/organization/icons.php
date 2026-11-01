<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $overrides */
use FlxCoreHC\IconService as I;
$title = 'Menu icons';
?>
<?php include __DIR__ . '/../org/_tabs.php'; ?>
<div class="topline"><h1>Menu icons</h1></div>
<p class="hint" style="margin:-.4rem 0 1rem;max-width:80ch">
  Change the icon on any navigation group or item so the menu matches how your organization talks about its
  business. Click an icon to pick a replacement; anything you leave alone keeps the standard icon.
  This applies to everyone in your workspace.
</p>

<form method="post" action="<?= BASE_PATH ?>/organization/icons" id="iconForm">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <div class="panel">
    <div class="topline"><h2>Navigation</h2>
      <button type="button" class="btn line sm" id="resetIcons">Reset all to default</button></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.5rem">
      <?php foreach (I::SLOT_LABELS as $slot => $label):
        $cur = $overrides[$slot] ?? I::DEFAULTS[$slot];
        $isCustom = isset($overrides[$slot]); ?>
        <div class="icon-slot" style="display:flex;align-items:center;gap:.6rem;padding:.5rem .6rem;border:1px solid var(--line);border-radius:var(--r-sm)">
          <button type="button" class="icon-pick" data-slot="<?= e($slot) ?>"
                  title="Choose an icon for <?= e($label) ?>"
                  style="display:grid;place-items:center;width:38px;height:38px;flex:none;cursor:pointer;
                         border:1px solid var(--line);border-radius:var(--r-sm);background:var(--surface-2);color:var(--accent)">
            <span data-preview="<?= e($slot) ?>"><?= I::svg($cur) ?></span>
          </button>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.86rem"><?= e($label) ?></div>
            <div class="hint" style="font-size:.72rem" data-name="<?= e($slot) ?>">
              <?= e($cur) ?><?= $isCustom ? ' · custom' : '' ?></div>
          </div>
          <input type="hidden" name="icons[<?= e($slot) ?>]" value="<?= e($cur) ?>" data-input="<?= e($slot) ?>">
        </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:1rem"><button class="btn">Save menu icons</button></div>
  </div>
</form>

<?php // ---- Icon chooser ---------------------------------------------------------- ?>
<div id="iconModal" class="modal-scrim">
  <div class="modal" role="dialog" aria-modal="true" aria-label="Choose an icon" style="max-width:660px">
    <div class="topline"><h2 style="margin:0">Choose an icon</h2>
      <button type="button" class="btn line sm" data-icon-close>Close</button></div>
    <input type="search" id="iconSearch" placeholder="Search icons…" style="width:100%;margin:.5rem 0 .8rem">
    <div id="iconGrid" style="max-height:52vh;overflow:auto">
      <?php foreach (I::CATALOG as $group => $icons): ?>
        <div class="icon-group" data-group="<?= e($group) ?>">
          <div class="hint" style="text-transform:uppercase;letter-spacing:.08em;font-size:.68rem;font-weight:700;margin:.5rem 0 .3rem"><?= e($group) ?></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(44px,1fr));gap:.3rem">
            <?php foreach ($icons as $name => $path): ?>
              <button type="button" class="icon-opt" data-icon="<?= e($name) ?>" title="<?= e($name) ?>"
                      style="display:grid;place-items:center;height:42px;cursor:pointer;border:1px solid transparent;
                             border-radius:var(--r-sm);background:var(--surface-2);color:var(--ink-2)">
                <?= I::svg($name) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script <?= nonce_attr() ?>>
(function () {
  var modal = document.getElementById('iconModal'),
      grid  = document.getElementById('iconGrid'),
      search= document.getElementById('iconSearch'),
      active = null;   // slot currently being edited

  var DEFAULTS = <?= flx_json(I::DEFAULTS) ?>;

  function open(slot) {
    active = slot;
    modal.classList.add('open');
    search.value = ''; filter('');
    setTimeout(function(){ search.focus(); }, 30);
  }
  function close() { modal.classList.remove('open'); active = null; }

  function filter(q) {
    q = q.trim().toLowerCase();
    grid.querySelectorAll('.icon-opt').forEach(function (b) {
      b.style.display = !q || b.getAttribute('data-icon').indexOf(q) !== -1 ? '' : 'none';
    });
    // Hide a group heading when everything inside it is filtered out.
    grid.querySelectorAll('.icon-group').forEach(function (g) {
      var any = Array.prototype.some.call(g.querySelectorAll('.icon-opt'), function (b) { return b.style.display !== 'none'; });
      g.style.display = any ? '' : 'none';
    });
  }

  document.addEventListener('click', function (e) {
    var pick = e.target.closest('.icon-pick');
    if (pick) { open(pick.getAttribute('data-slot')); return; }

    if (e.target.closest('[data-icon-close]') || e.target === modal) { close(); return; }

    var opt = e.target.closest('.icon-opt');
    if (opt && active) {
      var name = opt.getAttribute('data-icon');
      document.querySelector('[data-preview="' + active + '"]').innerHTML = opt.innerHTML;
      document.querySelector('[data-input="' + active + '"]').value = name;
      var lbl = document.querySelector('[data-name="' + active + '"]');
      lbl.textContent = name + (name !== DEFAULTS[active] ? ' \u00b7 custom' : '');
      close();
      return;
    }

    if (e.target.closest('#resetIcons')) {
      Object.keys(DEFAULTS).forEach(function (slot) {
        var input = document.querySelector('[data-input="' + slot + '"]');
        if (!input) return;
        input.value = DEFAULTS[slot];
        var src = grid.querySelector('.icon-opt[data-icon="' + DEFAULTS[slot] + '"]');
        if (src) document.querySelector('[data-preview="' + slot + '"]').innerHTML = src.innerHTML;
        document.querySelector('[data-name="' + slot + '"]').textContent = DEFAULTS[slot];
      });
    }
  });

  search && search.addEventListener('input', function () { filter(search.value); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && active) close(); });
})();
</script>
