<?php /** @var array $apps */
$K = FlxCoreHC\Repository\ApplicationRepository::KINDS;
$S = FlxCoreHC\Repository\ApplicationRepository::STATUSES;
$open = FlxCoreHC\Repository\ApplicationRepository::OPEN_STATUSES;
$name = function(array $a) { return $a['holder_type'] === 'organization'
    ? (string)$a['org_name'] : trim($a['first_name'].' '.$a['last_name']); };
$chip = function(string $s) { return match($s) {
    'approved' => 'active', 'denied' => 'expired', 'under_review','submitted' => 'gold',
    'withdrawn' => '', default => 'warn' }; };
$title = 'Applications'; ?>
<div class="topline"><h1>Applications &amp; enrollments</h1>
  <a class="btn" href="<?= BASE_PATH ?>/applications/new">+ New application</a></div>
<p class="hint" style="margin-top:-.4rem;max-width:80ch">Enrollment and credentialing submissions in flight — PECOS/Medicare, PR Medicaid (PRMP), payer credentialing and recredentialing — tracked through their status lifecycle with a full history. Official portals stay the system of record; this is your workflow and evidence layer.</p>

<div class="panel">
  <?php if ($apps === []): ?>
    <p class="hint" style="margin:0">No applications yet. <a href="<?= BASE_PATH ?>/applications/new">Start one</a> for a provider's enrollment or credentialing submission.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <tr><th>Provider</th><th>Application</th><th>Regulator</th><th>Type</th><th>Status</th><th>Submitted</th><th>Due</th></tr>
      <?php foreach ($apps as $a): ?>
        <tr>
          <td><strong><?= e($name($a)) ?></strong></td>
          <td><a href="<?= BASE_PATH ?>/applications/<?= e($a['id']) ?>"><?= e($a['title']) ?></a>
            <?php if (!empty($a['reference'])): ?><span class="sub mono"><?= e($a['reference']) ?></span><?php endif; ?></td>
          <td><?= e($a['regulator_name'] ?: '—') ?></td>
          <td><span class="hint"><?= e($K[$a['kind']] ?? $a['kind']) ?></span></td>
          <td><span class="chip <?= $chip($a['status']) ?>"><?= e($S[$a['status']] ?? $a['status']) ?></span></td>
          <td class="mono"><?= $a['submitted_on'] ? e(fmt_day((string)$a['submitted_on'])) : '—' ?></td>
          <td class="mono"><?= $a['due_on'] ? e(fmt_day((string)$a['due_on'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
</div>
