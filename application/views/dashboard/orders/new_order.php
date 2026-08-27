<?php defined('BASEPATH') OR exit('No direct script access allowed');
$old = $this->session->flashdata('old') ?: array();
$svc = $service;
$user_rate = $user_rate ?? null;
?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <h2 class="card-title">Place an order</h2>
      <p class="muted">Pricing is frozen at checkout. Your wallet is charged immediately; if the provider rejects the order the charge is refunded automatically.</p>

      <?=form_open('dashboard/orders/create', array('class'=>'mt-4 stack', 'id'=>'ws-order-form'))?>
        <input type="hidden" name="idempotency_key" value="<?=htmlspecialchars($old['idempotency_key'] ?? bin2hex(random_bytes(16)))?>">

        <label class="field">
          <span class="label">Service</span>
          <select name="service" id="ws-service" class="select" required>
            <option value="">— Choose a service —</option>
            <?php foreach ($services as $s): ?>
              <option value="<?=htmlspecialchars($s->public_id)?>"
                data-rate="<?=htmlspecialchars($s->rate)?>"
                data-min="<?= (int)$s->min_quantity?>"
                data-max="<?= (int)$s->max_quantity?>"
                data-step="<?= (int)($s->increment_step ?: 1)?>"
                data-name="<?=htmlspecialchars($s->name)?>"
                <?= (isset($old['service']) && $old['service']==$s->public_id) || ($svc && $svc->id==$s->id) ? 'selected' : ''?>>
                <?=htmlspecialchars($s->name)?> — <?=marvy_money($s->rate)?>/1k
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span class="label">Link</span>
          <input class="input" type="url" name="link" required maxlength="2048"
                 placeholder="https://instagram.com/yourhandle"
                 value="<?=htmlspecialchars($old['link'] ?? '')?>">
          <span class="hint">Must be a public http(s) URL. Never enter your password.</span>
        </label>

        <div class="row" style="gap:1rem;align-items:flex-end">
          <label class="field" style="flex:1">
            <span class="label">Quantity</span>
            <input class="input" id="ws-qty" type="number" name="quantity" required min="1" step="1"
                   value="<?=htmlspecialchars($old['quantity'] ?? ($svc ? (int)$svc->min_quantity : ''))?>">
          </label>
          <div class="text-sm muted" id="ws-limits">
            <?php if ($svc): ?>Min <?=number_format($svc->min_quantity)?> · Max <?=number_format($svc->max_quantity)?><?php endif; ?>
          </div>
        </div>

        <label class="field">
          <span class="label">Note (optional)</span>
          <textarea class="textarea" name="note" maxlength="500" rows="2"><?=htmlspecialchars($old['note'] ?? '')?></textarea>
        </label>

        <div class="row" style="justify-content:space-between;border-top:1px dashed var(--slate-200);padding-top:1rem">
          <div>
            <div class="muted text-sm">You pay</div>
            <strong id="ws-total" style="font-size:1.5rem;color:var(--brand-700)">—</strong>
            <?php if ($user_rate && $svc && bccomp($user_rate, $svc->rate, 8) < 0): ?>
              <span class="badge badge-success">Your price</span>
            <?php endif; ?>
          </div>
          <button class="btn btn-primary btn-lg" type="submit" id="ws-submit">Place order →</button>
        </div>
      <?=form_close()?>
    </div>
  </div>

  <aside class="space-y-6">
    <div class="card">
      <h3 class="card-title">Wallet</h3>
      <div class="text-3xl font-bold" style="font-family:var(--font-display)"><?=marvy_money($wallet->balance ?? '0', $wallet->currency ?? marvy_base_currency())?></div>
      <?php if (bccomp($wallet->balance ?? '0', '0', 8) <= 0): ?>
        <div class="alert alert-warning mt-3 mb-0">Your wallet is empty. Add funds before placing an order.</div>
      <?php endif; ?>
      <a class="btn btn-secondary btn-block btn-sm mt-3" href="<?=site_url('dashboard/add-funds')?>">Add funds →</a>
    </div>

    <div class="card" id="ws-service-info" <?php if (!$svc): ?>style="display:none"<?php endif; ?>>
      <h3 class="card-title">Service details</h3>
      <dl class="stack" style="gap:.5rem">
        <div class="row justify-between"><span class="muted">Average time</span><strong id="ws-avg"><?=htmlspecialchars($svc->average_time ?? '—')?></strong></div>
        <div class="row justify-between"><span class="muted">Refill</span><strong id="ws-refill"><?= !empty($svc) && (int)$svc->refill_supported ? 'Yes' : 'No'?></strong></div>
        <div class="row justify-between"><span class="muted">Cancel</span><strong id="ws-cancel"><?= !empty($svc) && (int)$svc->cancel_supported ? 'Yes' : 'No'?></strong></div>
      </dl>
    </div>

    <div class="card">
      <h3 class="card-title">How it works</h3>
      <ol class="stack" style="gap:.5rem;padding-left:1.25rem">
        <li>Pick a service and paste the public link.</li>
        <li>The price is frozen and your wallet is charged.</li>
        <li>The order is submitted to the provider and tracked automatically.</li>
        <li>Partial deliveries are refunded proportionally.</li>
      </ol>
    </div>
  </aside>
</div>

<script <?=csp_nonce_attr()?>>
(function(){
  var sel=document.getElementById('ws-service'),
      qty=document.getElementById('ws-qty'),
      total=document.getElementById('ws-total'),
      limits=document.getElementById('ws-limits'),
      info=document.getElementById('ws-service-info'),
      avg=document.getElementById('ws-avg'),
      refill=document.getElementById('ws-refill'),
      cancel=document.getElementById('ws-cancel'),
      submit=document.getElementById('ws-submit');

  // Service metadata is embedded on the option; live lookup is purely client-side.
  // Currency symbol from the server so live totals match server-rendered prices.
  var sym=<?=json_encode(trim(str_replace(array('0','.',','), '', marvy_money(0))))?>;
  function fmt(v){return sym+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function recalc(){
    var opt=sel.options[sel.selectedIndex], rate=0, min=1, max=0, step=1;
    if (opt && opt.value) {
      rate=parseFloat(opt.dataset.rate||'0');
      min=parseInt(opt.dataset.min||'1',10); max=parseInt(opt.dataset.max||'0',10);
      step=parseInt(opt.dataset.step||'1',10);
      qty.min=min; qty.max=max; qty.step=step;
      limits.textContent='Min '+min.toLocaleString()+' · Max '+max.toLocaleString();
      if (info) info.style.display='';
    } else {
      limits.textContent='';
      if (info) info.style.display='none';
    }
    var q=Math.max(min, parseInt(qty.value||'0',10)||0);
    var v=(rate/1000)*q;
    total.textContent=fmt(v);
    submit.disabled = !opt.value || q<=0;
  }
  sel.addEventListener('change', recalc);
  qty.addEventListener('input', recalc);
  recalc();
})();
</script>
