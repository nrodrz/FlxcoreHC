<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var string $version */ /** @var array $history */ /** @var array $totals */
/** @var string $text_en */ /** @var string $text_es */ /** @var string $draft_en */ /** @var string $draft_es */
$title = 'Platform console — Terms of Use';
$adminTab = 'terms';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
?>
<div class="topline"><h1>Terms of Use</h1><span class="chip">current version <?= e($version) ?></span></div>
<p class="hint" style="max-width:80ch">The Terms every user accepts at their first sign-in. They are the same for every organization; the organization's own name and the product name are filled in where the text has
  <code>{organization}</code> and <code>{product}</code>, and the owner's name where it has <code>{owner}</code>. English is the controlling text; Spanish is shown as a courtesy translation.
  <strong>Every save publishes a new version</strong> (earlier versions are kept, never edited), and <strong>everyone has to accept it again</strong> at their next sign-in.
  <?= (int) $totals['accepted'] ?> of <?= (int) $totals['users'] ?> active users have accepted version <?= e($version) ?>.</p>
<form method="post" action="<?= BASE_PATH ?>/admin/terms" class="panel">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <p class="hint">Format: a line starting with <code># </code> is a section heading; paragraphs are separated by a blank line.</p>
  <div class="grid2">
    <div>
      <label for="text_en"><strong>English</strong> (controlling)</label>
      <textarea id="text_en" name="text_en" rows="28" style="width:100%;font-family:var(--font-mono,monospace);font-size:.8rem" required><?= e($text_en) ?></textarea>
      <label for="draft_en" style="margin-top:.6rem">Banner shown above the text <span class="hint" style="font-weight:400">(leave empty when the Terms are final)</span></label>
      <input id="draft_en" name="draft_en" value="<?= e($draft_en) ?>" style="width:100%">
    </div>
    <div>
      <label for="text_es"><strong>Español</strong> (traducción de cortesía)</label>
      <textarea id="text_es" name="text_es" rows="28" style="width:100%;font-family:var(--font-mono,monospace);font-size:.8rem"><?= e($text_es) ?></textarea>
      <label for="draft_es" style="margin-top:.6rem">Banner en español <span class="hint" style="font-weight:400">(vacío cuando los Términos sean definitivos)</span></label>
      <input id="draft_es" name="draft_es" value="<?= e($draft_es) ?>" style="width:100%">
    </div>
  </div>
  <label class="chk" style="display:flex;gap:.5rem;align-items:flex-start;font-weight:400;margin:.9rem 0 .4rem">
    <input type="checkbox" name="confirm_publish" value="1">
    <span>I understand that this publishes a new version and every user must accept it again.</span></label>
  <button class="btn gold" type="submit" data-confirm="Publish a new version of the Terms of Use? Every user will have to accept it again at their next sign-in.">Publish new version</button>
</form>
<div class="panel">
  <div class="topline"><h2>Published versions</h2></div>
  <?php if (!$history): ?><p class="hint">None yet — the Terms shipped with the application (version <?= e($version) ?>) apply.</p>
  <?php else: ?><div class="table-wrap"><table>
    <tr><th>Version</th><th>Published</th><th>By</th></tr>
    <?php foreach ($history as $h): ?><tr><td><strong><?= e($h['version']) ?></strong><?= $h['version'] === $version ? ' <span class="chip active">current</span>' : '' ?></td><td><?= e(fmt_dt((string) $h['created_at'])) ?></td><td><?= e((string) $h['by']) ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
</div>
