<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /* Language switch for signed-out pages (v1.172.10). Keeps the current path and query-free. */
$__lp = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'); $__cur = FlxCoreHC\I18n::locale(); ?>
<div class="auth-lang" style="text-align:center;margin-top:.9rem;font-size:.78rem">
  <a href="<?= e($__lp) ?>?lang=en" lang="en"<?= $__cur === 'en' ? ' aria-current="true" style="font-weight:700"' : '' ?>>English</a> ·
  <a href="<?= e($__lp) ?>?lang=es" lang="es"<?= $__cur === 'es' ? ' aria-current="true" style="font-weight:700"' : '' ?>>Español</a>
</div>
