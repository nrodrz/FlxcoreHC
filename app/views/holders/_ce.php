<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Shared CME / CE panel (healthcare). Expects: $row (holder), $ce (entries), $ceHours, $cycle, $ctx.
 *  Providers can add their own entries; managers can add for anyone.
 *  v1.170.7 — replaced the Florida insurance CE rule (24 h / Law & Ethics / F.S. 626.2815). CME/CE
 *  requirements differ by licensing board, licence type and state, so no statutory target is asserted:
 *  the panel totals hours in the trailing 24 months and by category. Legacy categories still display. */
$catLabel = [
    'cme_cat1'  => 'CME — AMA PRA Category 1',
    'cme_cat2'  => 'CME — Category 2',
    'ce'        => 'Nursing / allied health CE',
    'ethics'    => 'Ethics / professional responsibility',
    'controlled_substances' => 'Controlled substances / opioid prescribing',
    'patient_safety' => 'Patient safety / risk management',
    'general'   => 'General',
    'other'     => 'Other',
];
$legacyLabel = ['law_ethics' => 'Ethics', 'flood' => 'Other'];
$byCat = [];
foreach ($ce as $__e) {
    $d = (string) $__e['completed_date'];
    if ($d >= $cycle['start']->format('Y-m-d') && $d <= $cycle['end']->format('Y-m-d')) {
        $k = $catLabel[$__e['category']] ?? ($legacyLabel[$__e['category']] ?? 'Other');
        $byCat[$k] = ($byCat[$k] ?? 0) + (float) $__e['hours'];
    }
}
$fmtH = fn($h) => rtrim(rtrim(number_format((float) $h, 1), '0'), '.');
?>
<div class="panel">
  <div class="topline"><h2>CME / continuing education</h2>
    <span class="chip"><?= e(t('{h} hrs · last {m} months', ['h' => $fmtH($ceHours), 'm' => (int) ($cycle['months'] ?? 24)])) ?></span></div>
  <p class="hint" style="margin-top:-.2rem;max-width:78ch">
    <?= e(t('Requirements differ by licensing board, licence type and jurisdiction (for example the PR Board of Medical Licensure and Discipline or the FL Board of Medicine), and some renewals require specific topics such as ethics or controlled-substance prescribing. Record completed hours here and keep the certificates on the matching licence credential.')) ?>
    <?= e(t('Hours from {date} to today are totalled.', ['date' => $cycle['start']->format('m/d/Y')])) ?>
  </p>
  <?php if ($byCat): ?>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:.3rem 0 .8rem">
    <?php foreach ($byCat as $k => $h): ?><span class="chip" style="font-size:.7rem"><?= e($k) ?>: <?= $fmtH($h) ?> h</span><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($ce !== []): ?>
  <div class="table-wrap"><table>
    <tr><th>Course</th><th>Category</th><th>Completed</th><th>Hours</th><th></th></tr>
    <?php foreach ($ce as $e): $inCycle = $e['completed_date'] >= $cycle['start']->format('Y-m-d') && $e['completed_date'] <= $cycle['end']->format('Y-m-d'); ?>
    <tr style="<?= $inCycle ? '' : 'opacity:.55' ?>">
      <td><strong><?= e($e['course_name']) ?></strong><?= $e['provider'] ? '<span class="sub">'.e($e['provider']).'</span>' : '' ?></td>
      <td><span class="chip" style="font-size:.66rem;text-transform:uppercase"><?= e($catLabel[$e['category']] ?? ($legacyLabel[$e['category']] ?? $e['category'])) ?></span></td>
      <td class="mono"><?= e(fmt_day($e['completed_date'])) ?><?= $inCycle ? '' : ' <span class="hint">(older than ' . (int) ($cycle['months'] ?? 24) . ' months)</span>' ?></td>
      <td class="mono"><?= rtrim(rtrim(number_format((float)$e['hours'],1),'0'),'.') ?></td>
      <td><form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/ce-delete/<?= e($e['id']) ?>" data-confirm="Remove this CME/CE entry?" style="margin:0">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line" style="color:var(--danger)">×</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?>
  <p class="hint" style="margin:0 0 .5rem">No CME/CE hours recorded yet.</p>
  <?php endif; ?>

  <details style="margin-top:.7rem">
    <summary class="btn sm line" style="list-style:none;cursor:pointer;display:inline-block">+ Record CME/CE hours</summary>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/ce" class="form-grid" style="margin-top:.8rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label>Course name</label><input name="course_name" required placeholder="e.g. Opioid prescribing — safe practices"></div>
      <div><label>Accredited sponsor (optional)</label><input name="provider" placeholder="e.g. ACCME-accredited sponsor, ANCC"></div>
      <div><label>Category</label><select name="category">
        <?php foreach ($catLabel as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Hours</label><input name="hours" type="number" step="0.5" min="0.5" max="100" required></div>
      <div><label>Completed</label><input name="completed_date" type="date" required value="<?= today_local() ?>"></div>
      <div class="full"><button class="btn">Add entry</button></div>
    </form>
  </details>
</div>
