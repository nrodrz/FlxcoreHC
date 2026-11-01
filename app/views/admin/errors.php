<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $errors */
/** @var int $unresolved */ /** @var bool $showingOpen */
$title = 'Errors';
$adminTab = 'errors';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
?>

<div class="topline"><h1>Errors</h1>
  <?php if ($unresolved > 0): ?><span class="chip expired"><?= (int) $unresolved ?> open</span>
  <?php else: ?><span class="chip active">all clear</span><?php endif; ?>
</div>

<p class="hint" style="margin:-.4rem 0 1rem;max-width:80ch">
  Uncaught exceptions and fatal errors from the live application, recorded here so breakage reaches
  a screen instead of a customer's phone call. Each entry shows what failed and where; stack traces
  and request contents are deliberately not stored. Mark an entry handled once you've looked into it.
</p>

<div class="panel">
  <div class="topline">
    <h2><?= $showingOpen ? 'Open errors' : 'All errors' ?></h2>
    <span style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
      <?php if ($showingOpen): ?>
        <a class="btn line sm" href="<?= BASE_PATH ?>/admin/errors?all=1">Show all</a>
      <?php else: ?>
        <a class="btn line sm" href="<?= BASE_PATH ?>/admin/errors">Open only</a>
      <?php endif; ?>
      <?php if ($unresolved > 0): ?>
      <form method="post" action="<?= BASE_PATH ?>/admin/errors" style="margin:0"
            data-confirm="Mark all open errors as handled?">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="resolve_all">
        <button class="btn line sm">Mark all handled</button>
      </form>
      <?php endif; ?>
      <form method="post" action="<?= BASE_PATH ?>/admin/errors" style="margin:0"
            data-confirm="Delete error entries older than 90 days?">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="purge">
        <button class="btn line sm">Purge &gt; 90 days</button>
      </form>
    </span>
  </div>

  <?php if ($errors === []): ?>
    <div class="empty" style="margin-top:.6rem">
      <?= $showingOpen ? 'No open errors. Nothing has broken since you last cleared the list.' : 'No errors recorded.' ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <tr><th>When</th><th>Error</th><th>Where</th><th>Request</th><th></th></tr>
      <?php foreach ($errors as $er): ?>
      <tr<?= $er['resolved_at'] ? ' style="opacity:.55"' : '' ?>>
        <td class="sub" style="white-space:nowrap"><?= e(fmt_dt($er['created_at'])) ?></td>
        <td>
          <span class="chip <?= $er['level'] === 'fatal' ? 'expired' : 'warn' ?>"><?= e($er['level']) ?></span>
          <?php if (!empty($er['error_class'])): ?>
            <span class="mono" style="font-size:.78rem"><?= e($er['error_class']) ?></span>
          <?php endif; ?>
          <div style="margin-top:.2rem;max-width:52ch"><?= e($er['message']) ?></div>
        </td>
        <td class="mono sub" style="font-size:.75rem;max-width:26ch;word-break:break-all">
          <?= $er['file'] ? e(basename((string) $er['file'])) . ':' . (int) $er['line'] : '—' ?>
        </td>
        <td class="sub" style="font-size:.78rem">
          <?php if (!empty($er['path'])): ?>
            <span class="mono"><?= e($er['method'] ?: '') ?> <?= e($er['path']) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="right">
          <?php if ($er['resolved_at']): ?>
            <span class="chip active">handled</span>
          <?php else: ?>
          <form method="post" action="<?= BASE_PATH ?>/admin/errors" style="margin:0">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="error_id" value="<?= e($er['id']) ?>">
            <button class="btn line sm">Mark handled</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
