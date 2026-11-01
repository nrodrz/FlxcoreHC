<?php /** @var array $plan */ /** @var bool $ok */ /** @var string $clientSecret */ /** @var string $error */ /** @var string $pk */
$title = 'Subscribe — ' . $plan['name'];
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/billing">Billing</a></div>
<div class="topline"><h1>Subscribe to <?= e($plan['name']) ?></h1>
  <span class="hint"><?= $plan['price_monthly']!==null?'$'.number_format((float)$plan['price_monthly'],2).'/mo':'' ?></span></div>

<?php if (!$ok): ?>
  <div class="panel">
    <div class="flash" style="background:#fbe9e7;color:#be4b42"><?= e($error ?: 'Checkout is unavailable right now.') ?></div>
    <p class="hint">If online billing isn't configured on this instance yet, your provider can move you between plans manually. Otherwise, please try again shortly.</p>
    <a class="btn line" href="<?= BASE_PATH ?>/billing">Back to billing</a>
  </div>
<?php else: ?>
  <div class="panel">
    <div id="checkout"><div class="hint">Loading secure checkout…</div></div>
  </div>
  <script <?= nonce_attr() ?> src="https://js.stripe.com/v3/"></script>
  <script <?= nonce_attr() ?>>
    (function(){
      try {
        var stripe = Stripe(<?= flx_json($pk) ?>);
        stripe.initEmbeddedCheckout({ clientSecret: <?= flx_json($clientSecret) ?> })
          .then(function(checkout){ checkout.mount('#checkout'); })
          .catch(function(e){ document.getElementById('checkout').innerHTML = 'Could not load checkout. <a href="<?= BASE_PATH ?>/billing">Go back</a>.'; });
      } catch (e) {
        document.getElementById('checkout').innerHTML = 'Stripe failed to initialize.';
      }
    })();
  </script>
<?php endif; ?>
