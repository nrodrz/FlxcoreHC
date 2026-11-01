<?php /** @var array $plans */ /** @var array $features */ /** @var array|null $edit */
$title='Plans';
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/admin">Platform admin</a></div>
<div class="topline"><h1>Plans</h1></div>
<p class="hint" style="margin-top:-.4rem">Define what each subscription tier includes — seat limits and features. Assign a plan to an organization from its page.</p>

<div class="panel">
  <div class="topline"><h2>All plans</h2></div>
  <table>
    <tr><th>Plan</th><th>Code</th><th>Price</th><th>Seats</th><th>Providers</th><th>Features</th><th>Orgs</th><th></th></tr>
    <?php foreach ($plans as $p): $f=json_decode((string)$p['features'],true)?:[]; ?>
    <tr>
      <td><strong><?= e($p['name']) ?></strong><?= $p['is_default']?' <span class="chip active">default</span>':'' ?><?= $p['active']?'':' <span class="chip expired">inactive</span>' ?></td>
      <td class="mono"><?= e($p['code']) ?></td>
      <td class="mono"><?= $p['price_monthly']!==null?'$'.number_format((float)$p['price_monthly'],2):'—' ?></td>
      <td class="mono"><?= $p['max_users']===null?'∞':(int)$p['max_users'] ?></td>
      <td class="mono" style="font-size:.8rem"><?php if(($p['producer_price']??null)===null||(float)$p['producer_price']<=0): ?>free<?php else: ?>$<?= number_format((float)$p['producer_price'],2) ?><?php if((int)($p['producer_included']??0)>0): ?> <span class="hint">(<?= (int)$p['producer_included'] ?> free)</span><?php endif; ?><?php endif; ?></td>
      <td class="sub"><?= $f?e(implode(', ',$f)):'core only' ?></td>
      <td class="mono"><?= (int)$p['tenants'] ?></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/admin/plans/<?= e($p['id']) ?>">Edit</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel">
  <div class="topline"><h2><?= $edit ? 'Edit plan: '.e($edit['name']) : 'Create a plan' ?></h2><?php if($edit): ?><a class="btn sm line" href="<?= BASE_PATH ?>/admin/plans">New plan</a><?php endif; ?></div>
  <form class="stack" method="post" action="<?= BASE_PATH ?>/admin" style="max-width:560px">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="save_plan">
    <?php if($edit): ?><input type="hidden" name="plan_id" value="<?= e($edit['id']) ?>"><?php endif; ?>
    <div class="grid2">
      <div><label>Name</label><input name="name" value="<?= e($edit['name']??'') ?>" required></div>
      <div><label>Code</label><input name="code" value="<?= e($edit['code']??'') ?>" <?= $edit?'readonly':'' ?> placeholder="e.g. pro" required></div>
    </div>
    <div class="grid2">
      <div><label>Price / month (blank = n/a)</label><input name="price_monthly" type="number" step="0.01" value="<?= e($edit['price_monthly']??'') ?>"></div>
      <div><label>Seats (blank = unlimited)</label><input name="max_users" type="number" min="1" value="<?= e($edit['max_users']??'') ?>"></div>
    </div>
    <div class="grid2">
      <div><label>Max providers (blank = unlimited)</label><input name="max_holders" type="number" min="0" value="<?= e($edit['max_holders']??'') ?>"></div>
      <div><label>Max document storage MB (blank = unlimited)</label><input name="max_storage_mb" type="number" min="0" value="<?= e($edit['max_storage_mb']??'') ?>"></div>
    </div>
    <div class="grid2">
      <div><label>Provider portal login price / month <span class="hint">(blank/0 = provider logins free)</span></label><input name="producer_price" type="number" step="0.01" min="0" value="<?= e($edit['producer_price']??'') ?>" placeholder="0.00"></div>
      <div><label>Provider portal logins included free</label><input name="producer_included" type="number" min="0" value="<?= e($edit['producer_included']??'0') ?>" placeholder="0"></div>
    </div>
    <div><label>Stripe Price ID <span class="hint" style="font-weight:400">(price_… from your Stripe dashboard)</span></label><input name="stripe_price_id" value="<?= e($edit['stripe_price_id']??'') ?>" placeholder="price_1AbC…"></div>
    <div><label>Included features</label>
      <?php $ef = $edit ? (json_decode((string)$edit['features'],true)?:[]) : ['documents','analytics']; ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem;margin-top:.3rem">
        <?php foreach ($features as $code=>$label): ?>
          <label style="font-size:.9rem"><input type="checkbox" name="features[]" value="<?= $code ?>" <?= in_array($code,$ef,true)?'checked':'' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <label class="hint"><input type="checkbox" name="active" value="1" <?= (!$edit || $edit['active'])?'checked':'' ?>> Active (selectable)</label>
    <div><button class="btn"><?= $edit?'Save plan':'Create plan' ?></button></div>
  </form>
</div>
