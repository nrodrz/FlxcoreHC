<div class="auth-card">
<?php
  // Signed-out pages carry the agency's own logo and name when we can identify them.
  $__b = $GLOBALS['flx_brand'] ?? [];
  $__bn = (string) ($__b['name'] ?? 'FlxCoreHC');
  $__bl = $__b['logo'] ?? null;
?>
  <div class="brand"><?php if ($__bl): ?><img class="brand-logo" src="<?= e($__bl) ?>" alt="<?= e($__bn) ?>" style="height:30px;width:auto;border-radius:8px">
    <?php else: ?><span class="mark"><?= e(mb_strtoupper(mb_substr($__bn, 0, 2))) ?></span><?php endif; ?> <?= e($__bn) ?></div>
  <div class="tag">Create your organization. Pick the shape that matches how you work — FlxCoreHC configures itself around it. New organizations are reviewed before full access is granted; you can look around in read-only mode in the meantime.</div>
  <?php if (!empty($error)): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/register">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div><label for="org_name">Organization / practice name</label>
      <input id="org_name" type="text" name="org_name" value="<?= e($old['org_name'] ?? '') ?>" required></div>
    <?php
      // Industry is the first-class choice; the org shape refines it. Vertical stays derived
      // from the shape (AuthService::ORG_SHAPES), so this is presentation — not a second source
      // of truth. No-JS fallback: all groups render visible with headings.
      $industries = ['healthcare' => 'Healthcare'];
      $byIndustry = [];
      $__allowed = edition_shapes();
      foreach (FlxCoreHC\Auth\AuthService::ORG_SHAPES as $key => [$v, $t, $label]) {
          if ($key === 'platform') continue; /* internal shape, never self-serve */
          if (!in_array($key, $__allowed, true)) continue; /* other vertical's shapes hidden in single-vertical editions */
          $byIndustry[$v][$key] = $label;
      }
      // In a single-vertical edition there's only one industry — skip the industry question.
      $__singleIndustry = count(array_filter(array_keys($byIndustry), fn($k) => $k !== 'custom')) <= 1;
      $oldShape = $old['org_shape'] ?? '';
      $oldInd = '';
      foreach ($byIndustry as $ind => $shapes) { if (isset($shapes[$oldShape])) $oldInd = $ind; }
    ?>
    <div><label><?= $__singleIndustry ? 'What are you?' : 'Industry' ?></label>
      <?php if ($__singleIndustry): ?>
        <?php foreach ($byIndustry as $ind => $shapes): ?>
        <div class="shape-pick">
          <?php foreach ($shapes as $key => $label): ?>
            <label><input type="radio" name="org_shape" value="<?= e($key) ?>"
              <?= $oldShape === $key ? 'checked' : '' ?> required> <?= e($label) ?></label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <div class="shape-pick" id="industry-pick" style="margin-bottom:.5rem">
        <?php foreach ($industries as $ind => $indLabel): if (empty($byIndustry[$ind])) continue; ?>
          <label><input type="radio" name="industry" value="<?= e($ind) ?>" <?= $oldInd === $ind ? 'checked' : '' ?>> <?= e($indLabel) ?></label>
        <?php endforeach; ?>
      </div>
      <?php foreach ($industries as $ind => $indLabel): if (empty($byIndustry[$ind])) continue; ?>
      <div class="shape-pick shape-group" data-industry="<?= e($ind) ?>">
        <span class="hint shape-group-title" style="display:block;font-weight:600"><?= e($indLabel) ?> — what are you?</span>
        <?php foreach ($byIndustry[$ind] as $key => $label): ?>
          <label><input type="radio" name="org_shape" value="<?= e($key) ?>"
            <?= $oldShape === $key ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <script <?= nonce_attr() ?>>
      (function () {
        var groups = document.querySelectorAll('.shape-group');
        var radios = document.querySelectorAll('input[name="industry"]');
        function show(ind) {
          groups.forEach(function (g) {
            var on = g.getAttribute('data-industry') === ind;
            g.style.display = on ? '' : 'none';
            if (!on) g.querySelectorAll('input[name="org_shape"]').forEach(function (r) { r.checked = false; });
            g.querySelector('.shape-group-title').style.display = 'none'; /* heading redundant once filtered */
          });
        }
        radios.forEach(function (r) { r.addEventListener('change', function () { show(this.value); }); });
        var pre = document.querySelector('input[name="industry"]:checked');
        if (pre) show(pre.value); else groups.forEach(function (g) { g.style.display = 'none'; });
      })();
      </script>
      <?php endif; ?>
      </div>
    <div><label for="operating_state">Where do your providers practice?</label>
      <select id="operating_state" name="operating_state">
        <?php $__os = $old['operating_state'] ?? 'PR';
          foreach (['PR' => 'Puerto Rico', 'FL' => 'Florida'] + FlxCoreHC\CatalogService::STATES as $__k => $__v): ?>
          <option value="<?= e($__k) ?>" <?= $__os === $__k ? 'selected' : '' ?>><?= e($__v) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Sets which state licence rules apply. You can add more jurisdictions later in Settings.</span></div>
    <div><label for="full_name">Your name</label>
      <input id="full_name" type="text" name="full_name" value="<?= e($old['full_name'] ?? '') ?>" required></div>
    <div><label for="remail">Work email</label>
      <input id="remail" type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required></div>
    <div class="form-grid" style="gap:.7rem 1rem">
      <div><label for="rphone">Phone <span class="hint" style="display:inline;font-weight:400">(helps us verify you)</span></label>
        <input id="rphone" type="text" name="contact_phone" value="<?= e($old['contact_phone'] ?? '') ?>"></div>
      <div><label for="rweb">Website <span class="hint" style="display:inline;font-weight:400">(optional)</span></label>
        <input id="rweb" type="text" name="website" value="<?= e($old['website'] ?? '') ?>" placeholder="yourpractice.com"></div>
    </div>
    <div><label for="rpass">Password</label>
      <input id="rpass" type="password" name="password" minlength="10" required>
      <div class="hint">At least 10 characters.</div></div>
    <div><label for="rpass2">Confirm password</label>
      <input id="rpass2" type="password" name="password_confirm" minlength="10" required></div>
    <button class="btn gold">Create organization</button>
  </form>
  <div class="auth-alt">Already set up? <a href="<?= BASE_PATH ?>/login">Sign in</a></div>
  <?php include __DIR__ . '/_lang.php'; ?>
</div>
