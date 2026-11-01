<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $tenants */ /** @var string $preTenant */
$adminTab = 'invoices';
$title = 'New invoice';
include __DIR__ . '/_tabs.php';
?>
<p class="crumb"><a href="<?= BASE_PATH ?>/admin/invoices">Invoices</a></p>
<div class="topline"><h1>New invoice</h1></div>

<form method="post" action="<?= BASE_PATH ?>/admin" id="invform">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <input type="hidden" name="action" value="create_invoice">
  <div class="panel">
    <div class="form-grid">
      <div><label>Client organization</label>
        <select name="tenant_id" required>
          <option value="">— choose a client —</option>
          <?php foreach ($tenants as $t): ?>
            <option value="<?= e($t['id']) ?>" <?= $preTenant === $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Due date <span class="hint" style="font-weight:400">(optional)</span></label><input type="date" name="due_date"></div>
    </div>
  </div>

  <div class="panel">
    <div class="topline"><h2>Line items</h2></div>
    <div class="table-wrap"><table id="items">
      <tr><th style="width:55%">Description</th><th style="width:12%">Qty</th><th style="width:18%">Unit price</th><th style="width:15%" class="right">Amount</th></tr>
      <?php for ($i = 0; $i < 3; $i++): ?>
      <tr>
        <td><input name="desc[]" placeholder="<?= $i===0?'e.g. FlxCoreHC — Professional plan, monthly':'' ?>"></td>
        <td><input name="qty[]" type="number" step="0.01" value="1" class="qty"></td>
        <td><input name="unit[]" type="number" step="0.01" placeholder="0.00" class="unit"></td>
        <td class="right mono amt">$0.00</td>
      </tr>
      <?php endfor; ?>
    </table></div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.6rem">
      <button type="button" class="btn sm line" id="addrow">Add line</button>
      <div style="font-size:1.05rem;font-weight:700">Total: <span id="total" class="mono">$0.00</span></div>
    </div>
  </div>

  <div class="panel">
    <div><label>Notes / terms <span class="hint" style="font-weight:400">(appears on the emailed invoice)</span></label>
      <input name="notes" maxlength="500" placeholder="e.g. Payable by check to … / Net 15"></div>
  </div>

  <div style="display:flex;gap:.6rem;margin-bottom:1.4rem">
    <button class="btn">Create draft</button>
    <a class="btn line" href="<?= BASE_PATH ?>/admin/invoices">Cancel</a>
  </div>
</form>

<script <?= nonce_attr() ?>>
(function(){
  var table = document.getElementById('items');
  function recalc(){
    var total = 0;
    table.querySelectorAll('tr').forEach(function(tr){
      var q = tr.querySelector('.qty'), u = tr.querySelector('.unit'), a = tr.querySelector('.amt');
      if(!q||!u||!a) return;
      var amt = (parseFloat(q.value)||0) * (parseFloat(u.value)||0);
      a.textContent = '$' + amt.toFixed(2);
      total += amt;
    });
    document.getElementById('total').textContent = '$' + total.toFixed(2);
  }
  table.addEventListener('input', recalc);
  document.getElementById('addrow').addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input name="desc[]"></td>'
      + '<td><input name="qty[]" type="number" step="0.01" value="1" class="qty"></td>'
      + '<td><input name="unit[]" type="number" step="0.01" placeholder="0.00" class="unit"></td>'
      + '<td class="right mono amt">$0.00</td>';
    table.appendChild(tr);
  });
  recalc();
})();
</script>
