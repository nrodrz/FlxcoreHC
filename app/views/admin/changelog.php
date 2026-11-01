<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var string $doc */
$title = 'Platform console — Changelog';
$adminTab = 'system';
require FLXCOREHC_APP . '/views/admin/_tabs.php';

// Parse the markdown into (version, title, body) entries.
$entries = [];
if ($doc !== '') {
    $parts = preg_split('/(?=^### v)/m', $doc);
    foreach ($parts as $p) {
        if (!preg_match('/^### v(\S+)\s+—?\s*(.*)$/m', $p, $m)) continue;
        $head = trim($m[0]);
        $body = trim(substr($p, strlen($m[0])));
        $entries[] = ['ver' => $m[1], 'title' => trim($m[2]), 'body' => $body];
    }
}
$current = FLXCOREHC_VERSION;
// very small markdown → html for the body (headings already stripped; handle bullets, bold, code)
$md = function (string $t): string {
    $t = e($t);
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t);
    $lines = explode("\n", $t); $out = ''; $inList = false;
    foreach ($lines as $l) {
        if (preg_match('/^\s*[-*]\s+(.*)/', $l, $mm)) {
            if (!$inList) { $out .= '<ul>'; $inList = true; }
            $out .= '<li>' . trim($mm[1]) . '</li>';
        } else {
            if ($inList) { $out .= '</ul>'; $inList = false; }
            if (trim($l) !== '') $out .= '<p>' . $l . '</p>';
        }
    }
    if ($inList) $out .= '</ul>';
    return $out;
};
?>
<div class="topline"><h1>Changelog</h1><span class="chip active">current: v<?= e($current) ?></span></div>
<p class="hint" style="max-width:820px">What changed in each release, newest first. When you upgrade, read from the top down to your previous version — and run <strong>Update database</strong> if a release mentions a migration.</p>

<?php if ($entries === []): ?>
  <div class="panel"><p class="hint">docs/CHANGELOG.md was not found in this deployment.</p></div>
<?php else: ?>
  <div class="panel">
  <div class="doc-cols">
    <?php foreach ($entries as $i => $en): $isCurrent = $en['ver'] === $current; ?>
    <details style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.5rem .7rem;margin-bottom:.5rem<?= $isCurrent ? ';border-left:3px solid var(--ok,#2f7d5b)' : '' ?>">
      <summary style="cursor:pointer;display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap">
        <strong class="mono">v<?= e($en['ver']) ?></strong>
        <?php if ($isCurrent): ?><span class="chip active" style="font-size:.6rem">current</span><?php endif; ?>
        <span><?= e($en['title']) ?></span>
      </summary>
      <div style="font-size:.85rem;line-height:1.5;margin-top:.5rem"><?= $md($en['body']) ?></div>
    </details>
    <?php endforeach; ?>
  </div>
  </div>
<?php endif; ?>
