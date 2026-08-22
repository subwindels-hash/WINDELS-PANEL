<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Bounds and currency are supplied by the controller from the settings table.
$min = (float)($min_deposit ?? 500);
$max = (float)($max_deposit ?? 5000000);
$cur = $base_currency ?? windels_base_currency();
// A naira default deposit is a round hundreds figure, not 25.00.
$suggested = min($max, max($min, 5000));
?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <h2 class="card-title">Add funds to your wallet</h2>
      <p class="muted">Wallet balance is held in <strong><?=html_escape($cur)?></strong> and used to pay for orders. Deposits between <?=windels_money($min, $cur)?> and <?=windels_money($max, $cur)?>.</p>
      <p class="muted text-sm mt-2">Your WINDELS wallet is a platform spending balance: it pays for services, orders and other supported purchases inside WINDELS PANEL. Wallet funds are for spending within the platform and stay inside it.</p>

      <?=form_open('dashboard/wallet/deposit', array('class'=>'mt-4 stack'))?>
        <label class="field">
          <span class="label">Amount (<?=html_escape($cur)?>)</span>
          <input id="ws-amount" class="input" name="amount" type="number" min="<?=$min?>" max="<?=$max?>" step="0.01" value="<?=number_format($suggested, 2, '.', '')?>" inputmode="decimal" required>
          <span class="hint">Minimum <?=windels_money($min, $cur)?>, maximum <?=windels_money($max, $cur)?>.</span>
        </label>

        <div>
          <span class="label">Payment method</span>
          <div class="grid grid-3 mt-2" style="gap:1rem">
            <?php if (empty($methods)): ?>
              <p class="muted">No payment methods are enabled yet.</p>
            <?php else: foreach ($methods as $m): ?>
              <label class="ws-payopt card">
                <input type="radio" name="payment_method" value="<?=htmlspecialchars($m->code)?>" required <?=!empty($m->is_active)?'':'disabled'?>>
                <span class="font-medium"><?=htmlspecialchars($m->name)?></span>
                <span class="badge <?=!empty($m->is_active)?'badge-success':'badge-default'?>"><?=!empty($m->is_active)?'active':'disabled'?></span>
                <?php if ((float)$m->bonus_percent > 0): ?>
                  <span class="badge badge-warning">+<?=rtrim(rtrim(number_format($m->bonus_percent,2),'0'),'.')?>% bonus</span>
                <?php endif; ?>
              </label>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <button class="btn btn-primary" type="submit" id="ws-submit" data-loading-text="Processing…">Continue →</button>
      <?=form_close()?>
    </div>

    <div class="card">
      <div class="row justify-between">
        <h2 class="card-title mb-0">Recent deposits</h2>
        <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/wallet/deposits')?>">View all →</a>
      </div>
      <?php $recent = $this->db->where('user_id',$current_user->id)->order_by('created_at','DESC')->limit(5)->get('payment_transactions')->result(); ?>
      <?php if (empty($recent)): ?>
        <p class="muted mt-3">No deposits yet.</p>
      <?php else: ?>
      <div class="overflow-x-auto mt-3">
        <table class="table">
          <thead><tr><th>Reference</th><th>Method</th><th>Amount</th><th>Credited</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $d): ?>
            <tr>
              <td class="mono text-xs"><?=htmlspecialchars(substr($d->public_id,0,12))?>…</td>
              <td><?=htmlspecialchars($d->payment_method_id)?></td>
              <td class="mono"><?=windels_money($d->amount, $d->currency)?></td>
              <td class="mono"><?=$d->credited_amount!==null ? windels_money($d->credited_amount, $d->currency) : '—'?></td>
              <td><span class="badge <?=$d->status==='SUCCESS'?'badge-success':($d->status==='FAILED'?'badge-danger':'badge-warning')?>"><?=htmlspecialchars($d->status)?></span></td>
              <td class="text-xs muted"><?=date('M j, H:i', strtotime($d->created_at))?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <aside class="space-y-6">
    <div class="card">
      <h3 class="card-title">Summary</h3>
      <dl class="mt-3 stack" style="gap:.5rem">
        <div class="row justify-between"><span class="muted">Current balance</span><strong><?=windels_money($wallet->balance ?? '0', $cur)?></strong></div>
        <div class="row justify-between"><span class="muted">Deposit</span><strong id="ws-deposit"><?=windels_money($suggested, $cur)?></strong></div>
        <div class="row justify-between border-t pt-2" style="border-color:var(--slate-200)"><span>New balance</span><strong id="ws-newbal"><?=windels_money(($wallet->balance ?? 0)+$suggested, $cur)?></strong></div>
      </dl>
      <p class="hint mt-3">Funds are credited after confirmation. No card data touches this server for hosted-gateway methods.</p>
    </div>
  </aside>
</div>

<style>
.ws-payopt{display:flex;align-items:center;gap:.6rem;cursor:pointer;padding:1rem}
.ws-payopt input{margin:0}
</style>
<script <?=csp_nonce_attr()?>>
(function(){
  var amt=document.getElementById('ws-amount'),dep=document.getElementById('ws-deposit'),nb=document.getElementById('ws-newbal');
  var base=parseFloat(<?=json_encode((float)($wallet->balance ?? 0))?>);
  // Symbol comes from the server so the running total matches the rest of the
  // page in whatever the base currency happens to be.
  var sym=<?=json_encode(trim(str_replace(array('0','.',','), '', windels_money(0, $cur))))?>;
  function fmt(v){return sym+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function recalc(){var v=parseFloat(amt.value)||0;dep.textContent=fmt(v);nb.textContent=fmt(base+v);}
  amt.addEventListener('input',recalc);recalc();
})();
</script>
