<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $org */
$title='Organization settings';
$accent = $org['brand_accent'] ?? '';
$navColor  = $org['brand_nav'] ?? '';
$themeMode = FlxCoreHC\ThemeService::defaultMode($org);
$radius    = (string) ($org['brand_radius'] ?? '');
$logo = $org['logo_path'] ?? '';
$logoSrc = $logo ? (str_starts_with($logo,'data:') ? $logo : BASE_PATH.'/'.ltrim($logo,'/')) : '';
$brandName = ($org['brand_name'] ?? '') ?: ($org['name'] ?? 'FlxCoreHC');
$initial = strtoupper(substr($brandName,0,1));
?>
<?php include __DIR__ . '/../org/_tabs.php'; ?>
<div class="topline"><h1>Organization profile</h1><a class="btn sm line" href="<?= BASE_PATH ?>/about"><?= e(t('About & ownership')) ?></a></div>

<div class="grid2" style="align-items:start">
  <!-- Organization profile -->
  <div class="panel">
    <div class="topline"><h2>Organization</h2></div>
    <form class="stack" method="post" action="<?= BASE_PATH ?>/settings" style="max-width:none">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label>Organization name</label><input name="name" value="<?= e($org['name'] ?? '') ?>" required></div>
      <div><label>Display / brand name <span class="hint" style="font-weight:400">(shown in the sidebar)</span></label>
        <input name="brand_name" id="f_brand" value="<?= e($org['brand_name'] ?? '') ?>" placeholder="<?= e($org['name'] ?? '') ?>"></div>
      <div class="grid2">
        <div><label>Contact email</label><input type="email" name="contact_email" value="<?= e($org['contact_email'] ?? '') ?>"></div>
        <div><label>Contact phone</label><input name="contact_phone" value="<?= e($org['contact_phone'] ?? '') ?>"></div>
      </div>
      <div><label><?= e(t('Default language')) ?></label>
        <select name="default_locale" style="max-width:220px">
          <?php $__dl = $org['default_locale'] ?? 'en'; foreach (FlxCoreHC\I18n::LOCALES as $lc => $lbl): ?>
            <option value="<?= e($lc) ?>" <?= $__dl === $lc ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint"><?= e(t('New users see the app in this language until they choose their own, and reminder emails are sent in it.')) ?></div></div>
      <div><label><?= e(t('Time zone')) ?></label>
        <select name="timezone" style="max-width:260px" data-confirm-change="<?= e(t('Change the time zone? It changes how every date and time is shown for this organization (stored times are not altered).')) ?>">
          <?php $__otz = (string)($org['timezone'] ?? ''); ?>
          <option value="" <?= $__otz === '' ? 'selected' : '' ?>><?= e(t('Platform default')) ?> — <?= e(flx_timezones()[app_tz_platform_name()] ?? app_tz_platform_name()) ?></option>
          <?php foreach (flx_timezones() as $z => $lbl): ?>
            <option value="<?= e($z) ?>" <?= $__otz === $z ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint"><?= e(t('Dates, "today", due dates and reminder e-mails follow this organization\'s time zone. Puerto Rico does not change its clocks; Florida does.')) ?></div></div>
      <div><label>Website</label><input name="website" value="<?= e($org['website'] ?? '') ?>" placeholder="https://"></div>
      <div><label>Address</label><textarea name="address" rows="2"><?= e($org['address'] ?? '') ?></textarea></div>

      <p class="hint" style="margin:.5rem 0 0"><strong><?= e(t('Email delivery')) ?>:</strong>
        send this organization's invites and reminders from your own mailbox (your own domain, or
        Microsoft 365) on the <a href="<?= BASE_PATH ?>/organization/email"><?= e(t('Email delivery')) ?></a> tab.</p>
      <?php $__lob = ($ctx->vertical === 'healthcare') ? 'Operating jurisdictions' : 'Lines of business'; ?>
      <p class="hint" style="margin:.5rem 0 0"><?= str_replace(['{lob_link}', '{sec_link}'],
          ['<a href="' . e(BASE_PATH) . '/organization/lines">' . e(t($__lob)) . '</a>', '<a href="' . e(BASE_PATH) . '/organization/security">' . e(t('Security')) . '</a>'],
          e(t('{what} live on the {lob_link} tab; two-factor policy is on the {sec_link} tab.', ['what' => t($__lob)]))) ?></p>

      <div class="topline" style="margin:.4rem 0 0"><h3 style="font-size:.95rem">Appearance</h3></div>
      <p class="hint" style="margin:-.2rem 0 .2rem">Match the workspace to your company palette. Leave a field on its default to keep the stock look — text contrast is checked automatically so buttons stay readable.</p>

      <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.2rem">
        <?php foreach (FlxCoreHC\ThemeService::PRESETS as $pk => $pv): ?>
          <button type="button" class="btn sm line f_preset" data-accent="<?= e($pv['accent']) ?>" data-nav="<?= e($pv['nav']) ?>"
                  style="display:flex;align-items:center;gap:.4rem">
            <span style="width:12px;height:12px;border-radius:50%;background:<?= e($pv['accent']) ?>;display:inline-block"></span>
            <?= e($pv['label']) ?></button>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">
        <label style="min-width:110px;font-weight:500">Accent</label>
        <input type="color" id="f_color" name="brand_accent" value="<?= e($accent ?: '#2f6fd6') ?>" style="width:52px;height:40px;padding:2px;border-radius:10px;cursor:pointer">
        <input type="text" id="f_hex" value="<?= e($accent) ?>" placeholder="#2f6fd6" style="width:130px" data-noenhance>
        <button type="button" class="btn sm line" id="f_clear">Reset</button>
        <span class="hint" id="f_contrast"></span>
      </div>

      <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">
        <label style="min-width:110px;font-weight:500">Sidebar</label>
        <input type="color" id="f_navcolor" value="<?= e($navColor ?: '#102138') ?>" style="width:52px;height:40px;padding:2px;border-radius:10px;cursor:pointer">
        <input type="text" id="f_nav" name="brand_nav" value="<?= e($navColor) ?>" placeholder="#102138" style="width:130px" data-noenhance>
        <button type="button" class="btn sm line" id="f_navclear">Reset</button>
        <span class="hint">Navigation background.</span>
      </div>

      <div class="grid2">
        <div><label for="f_mode">Default colour scheme</label>
          <select id="f_mode" name="brand_theme">
            <option value="light" <?= $themeMode==='light'?'selected':'' ?>>Light</option>
            <option value="dark"  <?= $themeMode==='dark'?'selected':'' ?>>Dark</option>
            <option value="auto"  <?= $themeMode==='auto'?'selected':'' ?>>Match the device</option>
          </select>
          <span class="hint">Each person can still switch; this is what they see first.</span></div>
        <div><label for="f_radius">Corner style</label>
          <select id="f_radius" name="brand_radius">
            <option value="soft"  <?= $radius==='soft'||$radius===''?'selected':'' ?>>Soft (default)</option>
            <option value="sharp" <?= $radius==='sharp'?'selected':'' ?>>Sharp</option>
            <option value="round" <?= $radius==='round'?'selected':'' ?>>Rounded</option>
          </select>
          <span class="hint">How rounded panels and buttons look.</span></div>
      </div>
      <p class="hint" style="margin:.2rem 0 0">Want different menu icons?
        <a href="<?= BASE_PATH ?>/organization/icons">Customize the navigation icons</a>.</p>
      <?php if (!empty($customFieldsHtml)): ?>
      <div style="margin:.8rem 0 .2rem;padding:.7rem .9rem;border:1px solid var(--line,#e3e1da);border-radius:8px">
        <strong style="font-size:.9rem"><?= e(t('Additional fields')) ?></strong>
        <div class="stack" style="margin-top:.5rem"><?= $customFieldsHtml ?></div></div>
      <?php endif; ?>
      <div><button class="btn">Save settings</button></div>
    </form>
  </div>
  <?php if (FlxCoreHC\Authz::can($ctx, 'org.manage')): ?>
  <div class="panel" style="margin-top:1rem">
    <div class="topline" style="margin:0"><strong><?= e(t('Your data')) ?></strong>
      <form method="post" action="<?= BASE_PATH ?>/organization/export" style="margin:0" data-novalidate>
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line"><?= e(t('Download all data (ZIP)')) ?></button></form></div>
    <p class="hint" style="margin:.3rem 0 0"><?= e(t('Everything your organization has entered — providers, credentials, a document index with checksums, groups, locations, memberships, and team — as CSV files you own. Nothing is locked in.')) ?></p>
  </div>
  <?php endif; ?>
  </div>

  <!-- Branding preview + logo -->
  <div>
    <div class="panel">
      <div class="topline"><h2>Logo</h2></div>
      <?php if ($logoSrc): ?>
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
          <img src="<?= e($logoSrc) ?>" alt="Logo" style="max-height:52px;max-width:180px;object-fit:contain;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;padding:6px">
          <form method="post" action="<?= BASE_PATH ?>/settings/logo-remove" data-novalidate data-confirm="Remove the logo?" data-confirm-danger>
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm danger">Remove</button></form>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= BASE_PATH ?>/settings/logo" enctype="multipart/form-data" data-novalidate class="stack" style="max-width:none">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <div><label>Upload a logo <span class="hint" style="font-weight:400">(PNG, JPG, GIF or WebP · max 1 MB)</span></label>
          <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required></div>
        <div><button class="btn line">Upload logo</button></div>
      </form>
    </div>

    <div class="panel">
      <div class="topline"><h2>Preview</h2></div>
      <div id="brandPrev" style="background:var(--nav);border-radius:12px;padding:1rem;display:flex;align-items:center;gap:.7rem">
        <?php if ($logoSrc): ?><img src="<?= e($logoSrc) ?>" style="max-height:30px;max-width:150px;object-fit:contain">
        <?php else: ?><span id="prevMark" style="width:34px;height:34px;border-radius:9px;display:grid;place-items:center;font-weight:800;color:#fff;background:<?= e($accent ?: '#2f6fd6') ?>"><?= e($initial) ?></span>
          <span id="prevName" style="color:#fff;font-family:var(--font-disp);font-weight:800"><?= e($brandName) ?></span><?php endif; ?>
      </div>
      <div style="margin-top:.8rem;display:flex;gap:.5rem">
        <button type="button" class="btn" id="prevBtn">Primary button</button>
        <a class="btn line" id="prevLink">Secondary</a>
      </div>
      <p class="hint" style="margin-top:.6rem">Each teammate can still switch between light and dark from the top bar.</p>
    </div>
  </div>
</div>

<script <?= nonce_attr() ?>>
(function(){
  var color=document.getElementById('f_color'), hex=document.getElementById('f_hex'),
      clr=document.getElementById('f_clear'), brand=document.getElementById('f_brand'),
      navC=document.getElementById('f_navcolor'), navH=document.getElementById('f_nav'),
      navClr=document.getElementById('f_navclear'), rad=document.getElementById('f_radius'),
      mark=document.getElementById('prevMark'), name=document.getElementById('prevName'),
      cNote=document.getElementById('f_contrast');
  var DEF_ACCENT='#2f6fd6', DEF_NAV='#102138';
  function val(el){ var v=(el&&el.value||'').trim(); if(!v) return null;
    if(v[0]!=='#') v='#'+v; return /^#[0-9a-fA-F]{6}$/.test(v)?v.toLowerCase():null; }

  /* Push the current choices to the whole page — same CSS the server will save. */
  function preview(){
    var accent=val(hex)||val(color), nav=val(navH)||val(navC);
    window.flxPreviewTheme({ accent:accent, nav:nav, radius:rad?rad.value:null });
    note(accent||DEF_ACCENT);
  }
  function note(c){
    if(!cNote||!window.flxThemeUtil) return;
    var u=window.flxThemeUtil, r=u.contrast(c,u.inkFor(c));
    cNote.textContent=(r>=4.5?'\u2713 ':'\u26a0 ')+r.toFixed(1)+':1 '+(r>=4.5?'readable':'low contrast \u2014 try a deeper shade');
    cNote.style.color=r>=4.5?'var(--ok)':'var(--warn)';
  }
  function sync(src,dst){ if(src&&dst) dst.value=src.value; }

  color && color.addEventListener('input', function(){ hex.value=color.value; preview(); });
  hex   && hex.addEventListener('input', function(){ var v=val(hex); if(v){ color.value=v; } preview(); });
  clr   && clr.addEventListener('click', function(){ hex.value=''; color.value=DEF_ACCENT; preview(); });
  navC  && navC.addEventListener('input', function(){ navH.value=navC.value; preview(); });
  navH  && navH.addEventListener('input', function(){ var v=val(navH); if(v){ navC.value=v; } preview(); });
  navClr&& navClr.addEventListener('click', function(){ navH.value=''; navC.value=DEF_NAV; preview(); });
  rad   && rad.addEventListener('change', preview);

  /* Presets fill both fields and preview immediately. */
  Array.prototype.forEach.call(document.querySelectorAll('.f_preset'), function(b){
    b.addEventListener('click', function(){
      var a=b.getAttribute('data-accent'), n=b.getAttribute('data-nav');
      if(hex){hex.value=a;} if(color){color.value=a;}
      if(navH){navH.value=n;} if(navC){navC.value=n;}
      preview();
      window.flxToast && window.flxToast('Previewing \u2014 press Save settings to apply for everyone.', 'info');
    });
  });

  /* Live brand-name echo in the preview card. */
  brand && brand.addEventListener('input', function(){
    var v=brand.value.trim()||'<?= e(addslashes($org['name'] ?? 'FlxCoreHC')) ?>';
    if(name) name.textContent=v; if(mark) mark.textContent=v.charAt(0).toUpperCase();
  });

  /* Leaving with unsaved theme edits would silently drop them — warn instead. */
  var initial=JSON.stringify([val(hex),val(navH),rad?rad.value:null]);
  window.addEventListener('beforeunload', function(e){
    if(JSON.stringify([val(hex),val(navH),rad?rad.value:null])!==initial){ e.preventDefault(); e.returnValue=''; }
  });
  document.addEventListener('submit', function(){ initial=JSON.stringify([val(hex),val(navH),rad?rad.value:null]); });

  note(val(hex)||DEF_ACCENT);
})();
</script>
