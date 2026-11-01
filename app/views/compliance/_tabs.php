<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var string $tab */ ?>
<div class="seg" style="margin-bottom:1.1rem;display:inline-flex">
  <a href="<?= BASE_PATH ?>/compliance" class="<?= $tab==='overview'?'on':'' ?>">Overview</a>
  <a href="<?= BASE_PATH ?>/compliance/producers" class="<?= $tab==='producers'?'on':'' ?>">Providers</a>
  <a href="<?= BASE_PATH ?>/credentials/requirements" class="">Catalog</a>
  <a href="<?= BASE_PATH ?>/compliance/security" class="<?= $tab==='security'?'on':'' ?>">Security &amp; privacy</a>
</div>
