<?php defined('BASEPATH') OR exit('No direct script access allowed');
$min = 5.0; $max = 10000.0;
?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 card">
    <h2 class="card-title">Add funds to your wallet</h2>
    <p class="muted">Wallet balance is held in <strong>USD</strong> and used to pay for orders.
      Deposits between <?=windels_money($min)?> and <?=windels_money($max)?>.</p>

    <form class="mt-4 stack" onsubmit="return false">
      <label class="field">
        <span class="label">Amount (USD)</span>
        <input id="ws-amount" class="input" type="number" min="<?=$min?>" max="<?=$max?>" step="0.01" value="25.00" inputmode="decimal">
        <span class="hint">Minimum <?=windels_money($min)?>, maximum <?=windels_money($max)?>.</span>
      </label>

      <div>
        <span class="label">Payment method</span>
        <div class="grid grid-3 mt-2">
          <?php if (empty($methods)): ?>
            <p class="muted">No payment methods are enabled yet.</p>
          <?php else: foreach ($methods as $m): ?>
            <label class="ws-payopt card">
              <input type="radio" name="method" value="<?=htmlspecialchars($m->code)?>" <?=!empty($m->is_active)?'':'disabled'?>>
              <span class="font-medium"><?=htmlspecialchars($m->name)?></span>
              <span class="badge <?=!empty($m->is_active)?'badge-success':'badge-default'?>"><?=!empty($m->is_active)?'active':'disabled'?></span>
            </label>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <button class="btn btn-primary" disabled>Continue to payment (Session 11)</button>
    </form>
  </div>

  <aside class="card">
    <h3 class="card-title">Summary</h3>
    <dl class="mt-3 stack" style="gap:.5rem">
      <div class="row justify-between"><span class="muted">Current balance</span><strong><?=windels_money($wallet->balance ?? '0')?></strong></div>
      <div class="row justify-between"><span class="muted">Deposit</span><strong id="ws-deposit">$25.00</strong></div>
      <div class="row justify-between border-t pt-2" style="border-color:var(--slate-200)"><span>New balance</span><strong id="ws-newbal"><?=windels_money(($wallet->balance ?? 0) + 25)?></strong></div>
    </dl>
    <p class="hint mt-3">Gateway checkout flows and webhook reconciliation ship in Session 11 (Payments).
      No card data is ever stored on this server.</p>
  </aside>
</div>

<style>
.ws-payopt{display:flex;align-items:center;gap:.6rem;cursor:pointer;padding:1rem}
.ws-payopt input{margin:0}
</style>
<script>
(function(){
  var amt=document.getElementById('ws-amount'), dep=document.getElementById('ws-deposit'), nb=document.getElementById('ws-newbal');
  var base=parseFloat(<?=json_encode((float)($wallet->balance ?? 0))?>);
  function recalc(){
    var v=parseFloat(amt.value)||0;
    dep.textContent='$'+v.toFixed(2);
    nb.textContent='$'+(base+v).toFixed(2);
  }
  amt.addEventListener('input',recalc); recalc();
})();
</script>
