<?php /* Language switch for the public recipient pages (v1.172.9). Keeps the current path; only the language parameter changes. */
$__lp = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'); $__cur = FlxCoreHC\I18n::locale(); ?>
<div class="share-lang" style="text-align:right;max-width:860px;margin:.6rem auto 0;padding:0 1rem;font-size:.78rem">
  <a href="<?= e($__lp) ?>?lang=en" lang="en"<?= $__cur === 'en' ? ' aria-current="true" style="font-weight:700"' : '' ?>>English</a> ·
  <a href="<?= e($__lp) ?>?lang=es" lang="es"<?= $__cur === 'es' ? ' aria-current="true" style="font-weight:700"' : '' ?>>Español</a>
</div>
