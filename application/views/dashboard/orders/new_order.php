<?php defined('BASEPATH') OR exit('No direct script access allowed');
$old = $this->session->flashdata('old') ?: array();
$svc = $service;
$user_rate = $user_rate ?? null;
$picker = array();
$platforms = array();
foreach ($services as $s) {
    $platform = (string)($s->platform ?? '');
    $cat_id = (string)($s->category_id ?? '');
    $cat_name = (string)($s->category_name ?? 'Other');
    if ($platform !== '') $platforms[$platform] = true;
    $picker[] = array(
        'id' => $s->public_id,
        'name' => $s->name,
        'rate' => (string)$s->rate,
        'min' => (int)$s->min_quantity,
        'max' => (int)$s->max_quantity,
        'step' => (int)($s->increment_step ?: 1),
        'avg' => (string)($s->average_time ?: ''),
        'refill' => (int)$s->refill_supported,
        'cancel' => (int)$s->cancel_supported,
        'platform' => $platform,
        'category_id' => $cat_id,
        'category' => $cat_name,
    );
}
ksort($platforms);
$selected = '';
if (!empty($old['service'])) $selected = $old['service'];
elseif ($svc) $selected = $svc->public_id;
?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <h2 class="card-title">Place an order</h2>
      <p class="muted">Choose a platform, then a category, then a service. The price shown here is a preview — the wallet is charged at the amount the server calculates when you submit.</p>

      <?=form_open('dashboard/orders/create', array('class'=>'mt-4 stack', 'id'=>'ws-order-form'))?>
        <input type="hidden" name="idempotency_key" value="<?=htmlspecialchars($old['idempotency_key'] ?? bin2hex(random_bytes(16)))?>">

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="field mb-0">
            <span class="label">Platform</span>
            <select id="ws-platform" class="select">
              <option value="">All platforms</option>
              <?php foreach (array_keys($platforms) as $p): ?>
                <option value="<?=htmlspecialchars($p)?>"><?=htmlspecialchars(ucfirst($p))?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field mb-0">
            <span class="label">Category</span>
            <select id="ws-category" class="select">
              <option value="">All categories</option>
            </select>
          </label>
        </div>

        <label class="field">
          <span class="label">Service</span>
          <?php
            // Server-rendered so the form is orderable before (or without) the
            // filtering script runs; the script narrows this list, it does not
            // create it.
            $__symbol = trim(str_replace(array('0', '.', ','), '', marvy_money(0)));
          ?>
          <select name="service" id="ws-service" class="select" required>
            <option value="">— Choose a service —</option>
            <?php foreach ($picker as $row): ?>
              <option value="<?=htmlspecialchars($row['id'])?>"
                      data-rate="<?=htmlspecialchars($row['rate'])?>"
                      data-min="<?=(int)$row['min']?>"
                      data-max="<?=(int)$row['max']?>"
                      data-step="<?=(int)$row['step']?>"
                      data-avg="<?=htmlspecialchars($row['avg'])?>"
                      data-refill="<?=$row['refill'] ? '1' : '0'?>"
                      data-cancel="<?=$row['cancel'] ? '1' : '0'?>"
                      data-name="<?=htmlspecialchars($row['name'])?>"
                      data-platform="<?=htmlspecialchars($row['platform'])?>"
                      data-category="<?=htmlspecialchars($row['category_id'])?>"
                      <?=$selected === $row['id'] ? 'selected' : ''?>>
                <?=htmlspecialchars($row['name'].' — '.$__symbol.number_format((float)$row['rate'], 2).'/1k')?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span class="label">Link</span>
          <input class="input" type="url" name="link" required maxlength="2048"
                 placeholder="https://instagram.com/yourhandle"
                 value="<?=htmlspecialchars($old['link'] ?? '')?>">
          <span class="hint" id="ws-link-hint">Must be a public http(s) URL. Never enter your password.</span>
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

        <?php
          // Module 36: one code works on every purchase in the panel — this
          // order form, VTU, number rentals, identity checks and gift cards.
          // The server validates it at checkout and charges the discounted
          // total; the field is never trusted to submit one.
        ?>
        <label class="field">
          <span class="label">Coupon code (optional)</span>
          <input class="input" type="text" name="coupon_code" maxlength="32" autocomplete="off"
                 style="text-transform:uppercase"
                 placeholder="Promo code"
                 value="<?=htmlspecialchars($old['coupon_code'] ?? '')?>">
          <span class="hint">Applied when the order is placed — the final charge reflects it.</span>
        </label>

        <div class="row" style="justify-content:space-between;border-top:1px dashed var(--slate-200);padding-top:1rem">
          <div>
            <div class="muted text-sm">Estimated total</div>
            <strong id="ws-total" style="font-size:1.5rem;color:var(--brand-700)">—</strong>
            <?php if ($user_rate && $svc && bccomp($user_rate, $svc->rate, 8) < 0): ?>
              <span class="badge badge-success">Your price</span>
            <?php endif; ?>
            <p class="hint mb-0">Final charge is calculated on the server at checkout.</p>
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
      <h3 class="card-title" id="ws-info-name"><?=htmlspecialchars($svc->name ?? 'Service details')?></h3>
      <dl class="stack" style="gap:.5rem">
        <div class="row justify-between"><span class="muted">Average time</span><strong id="ws-avg"><?=htmlspecialchars($svc->average_time ?? '—')?></strong></div>
        <div class="row justify-between"><span class="muted">Refill</span><strong id="ws-refill"><?= !empty($svc) && (int)$svc->refill_supported ? 'Yes' : 'No'?></strong></div>
        <div class="row justify-between"><span class="muted">Cancel</span><strong id="ws-cancel"><?= !empty($svc) && (int)$svc->cancel_supported ? 'Yes' : 'No'?></strong></div>
        <div class="row justify-between"><span class="muted">Rate / 1k</span><strong id="ws-rate"><?=$svc ? marvy_money($svc->rate) : '—'?></strong></div>
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
  var catalog = <?=json_encode($picker, JSON_UNESCAPED_SLASHES)?>;
  var selected = <?=json_encode($selected)?>;
  var platformSel = document.getElementById('ws-platform');
  var categorySel = document.getElementById('ws-category');
  var sel = document.getElementById('ws-service');
  var qty = document.getElementById('ws-qty');
  var total = document.getElementById('ws-total');
  var limits = document.getElementById('ws-limits');
  var info = document.getElementById('ws-service-info');
  var avg = document.getElementById('ws-avg');
  var refill = document.getElementById('ws-refill');
  var cancel = document.getElementById('ws-cancel');
  var submit = document.getElementById('ws-submit');
  var infoName = document.getElementById('ws-info-name');
  var rateEl = document.getElementById('ws-rate');
  var sym = <?=json_encode(trim(str_replace(array('0','.',','), '', marvy_money(0))))?>;

  function fmt(v){ return sym + v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function uniqueCategories(rows) {
    var seen = {}, out = [];
    rows.forEach(function(s){
      var key = s.category_id || s.category;
      if (!key || seen[key]) return;
      seen[key] = true;
      out.push({id: s.category_id, name: s.category || 'Other'});
    });
    out.sort(function(a,b){ return a.name.localeCompare(b.name); });
    return out;
  }

  function filtered() {
    var p = platformSel.value, c = categorySel.value;
    return catalog.filter(function(s){
      if (p && s.platform !== p) return false;
      if (c && s.category_id !== c) return false;
      return true;
    });
  }

  function fillCategories() {
    var p = platformSel.value;
    var rows = catalog.filter(function(s){ return !p || s.platform === p; });
    var cats = uniqueCategories(rows);
    var keep = categorySel.value;
    categorySel.innerHTML = '<option value="">All categories</option>';
    cats.forEach(function(cat){
      var opt = document.createElement('option');
      opt.value = cat.id;
      opt.textContent = cat.name;
      if (keep && keep === cat.id) opt.selected = true;
      categorySel.appendChild(opt);
    });
  }

  function fillServices() {
    var rows = filtered();
    var keep = sel.value || selected;
    sel.innerHTML = '<option value="">— Choose a service —</option>';
    rows.forEach(function(s){
      var opt = document.createElement('option');
      opt.value = s.id;
      opt.textContent = s.name + ' — ' + fmt(parseFloat(s.rate||'0')) + '/1k';
      opt.dataset.rate = s.rate;
      opt.dataset.min = s.min;
      opt.dataset.max = s.max;
      opt.dataset.step = s.step;
      opt.dataset.avg = s.avg || '';
      opt.dataset.refill = s.refill ? '1' : '0';
      opt.dataset.cancel = s.cancel ? '1' : '0';
      opt.dataset.name = s.name;
      if (keep && keep === s.id) opt.selected = true;
      sel.appendChild(opt);
    });
    recalc();
  }

  function recalc(){
    var opt = sel.options[sel.selectedIndex], rate=0, min=1, max=0, step=1;
    if (opt && opt.value) {
      rate = parseFloat(opt.dataset.rate||'0');
      min = parseInt(opt.dataset.min||'1',10);
      max = parseInt(opt.dataset.max||'0',10);
      step = parseInt(opt.dataset.step||'1',10);
      qty.min = min; qty.max = max; qty.step = step;
      limits.textContent = 'Min '+min.toLocaleString()+' · Max '+max.toLocaleString();
      if (info) info.style.display = '';
      if (infoName) infoName.textContent = opt.dataset.name || 'Service details';
      if (avg) avg.textContent = opt.dataset.avg || '—';
      if (refill) refill.textContent = opt.dataset.refill === '1' ? 'Yes' : 'No';
      if (cancel) cancel.textContent = opt.dataset.cancel === '1' ? 'Yes' : 'No';
      if (rateEl) rateEl.textContent = fmt(rate);
    } else {
      limits.textContent = '';
      if (info) info.style.display = 'none';
    }
    var q = Math.max(min, parseInt(qty.value||'0',10)||0);
    total.textContent = fmt((rate/1000)*q);
    submit.disabled = !opt || !opt.value || q <= 0;
  }

  platformSel.addEventListener('change', function(){ fillCategories(); fillServices(); });
  categorySel.addEventListener('change', fillServices);
  sel.addEventListener('change', recalc);
  qty.addEventListener('input', recalc);

  if (selected) {
    var pre = null;
    for (var i=0;i<catalog.length;i++) if (catalog[i].id === selected) { pre = catalog[i]; break; }
    if (pre && pre.platform) platformSel.value = pre.platform;
  }
  fillCategories();
  if (selected) {
    var pre2 = null;
    for (var j=0;j<catalog.length;j++) if (catalog[j].id === selected) { pre2 = catalog[j]; break; }
    if (pre2 && pre2.category_id) categorySel.value = pre2.category_id;
  }
  fillServices();
})();
</script>
