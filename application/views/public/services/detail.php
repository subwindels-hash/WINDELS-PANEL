<?php defined('BASEPATH') OR exit('No direct script access allowed');
$u = $service;
$unit_label = ($u->service_type === 'PACKAGE') ? 'package' : '1000 units';
$per_unit   = ($u->service_type === 'PACKAGE') ? $u->rate : bcdiv($u->rate, '1000', 8);
$guest_price = $u->rate;
$flags = json_decode($u->metadata ?? '', true) ?: array();
$badges = array(
  'refill_supported' => (int)$u->refill_supported ? 'Refill' : null,
  'cancel_supported' => (int)$u->cancel_supported ? 'Cancel anytime' : null,
  'dripfeed' => (int)$u->dripfeed_supported ? 'Drip-feed' : null,
  'subscription' => (int)$u->subscription_supported ? 'Subscription' : null,
);
?>
<section class="py-10">
  <div class="container" style="max-width:1100px">
    <nav class="text-sm muted mb-4" aria-label="Breadcrumb">
      <a href="<?=site_url('services')?>">Services</a>
      <?php if ($category): ?>
        · <a href="<?=site_url('services?category='.urlencode($category->slug))?>"><?=htmlspecialchars($category->name)?></a>
      <?php endif; ?>
      · <span class="text-slate-700"><?=htmlspecialchars($u->name)?></span>
    </nav>

    <div class="grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <div class="card">
          <div class="row justify-between">
            <div>
              <?php if ($category): ?><span class="badge badge-brand"><?=htmlspecialchars($category->name)?></span><?php endif; ?>
              <h1 class="mt-2"><?=htmlspecialchars($u->name)?></h1>
            </div>
            <div class="row" style="gap:.4rem">
              <?php if ((int)$u->trending): ?><span class="badge badge-danger">🔥 Trending</span><?php endif; ?>
            </div>
          </div>

          <?php if (!empty($u->description)): ?>
            <p class="mt-3 text-slate-600"><?=nl2br(htmlspecialchars($u->description))?></p>
          <?php endif; ?>

          <div class="grid grid-4 mt-5" style="gap:1rem">
            <div><div class="muted text-xs">Start time</div><strong><?=htmlspecialchars($u->average_time ?: '—')?></strong></div>
            <div><div class="muted text-xs">Min</div><strong><?=number_format($u->min_quantity)?></strong></div>
            <div><div class="muted text-xs">Max</div><strong><?=number_format($u->max_quantity)?></strong></div>
            <div><div class="muted text-xs">Type</div><strong><?=htmlspecialchars(str_replace('_',' ',ucwords(strtolower($u->service_type))))?></strong></div>
          </div>

          <div class="row mt-5" style="gap:.4rem;flex-wrap:wrap">
            <?php foreach (array_filter($badges) as $b): ?>
              <span class="badge badge-success"><?=htmlspecialchars($b)?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (!empty($related)): ?>
        <div class="card">
          <h2 class="card-title">Related services</h2>
          <div class="grid grid-3 mt-3" style="gap:1rem">
            <?php foreach ($related as $r): ?>
              <a class="card card-hover" href="<?=site_url('services/'.$r->slug)?>" style="margin:0">
                <h3 class="card-title" style="font-size:1rem"><?=htmlspecialchars($r->name)?></h3>
                <strong style="color:var(--brand-700)"><?=windels_money($r->rate)?> / 1k</strong>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <aside class="space-y-6">
        <div class="card ws-pricecard">
          <div class="muted text-xs">Price</div>
          <div class="text-4xl font-bold" style="font-family:var(--font-display);color:var(--brand-700)">
            <?=windels_money($u->rate)?>
          </div>
          <div class="muted text-sm mb-4">per <?=htmlspecialchars($unit_label)?></div>

          <?php if ($user_price !== null && bccomp($user_price, $guest_price, 8) < 0): ?>
            <div class="alert alert-success mb-3" style="padding:.6rem">
              <strong>Your price:</strong> <?=windels_money($user_price)?> per 1k
            </div>
          <?php endif; ?>

          <form action="<?=site_url('dashboard/new-order')?>" method="get" class="stack">
            <input type="hidden" name="service" value="<?=htmlspecialchars($u->public_id)?>">
            <label class="field">
              <span class="label">Quantity</span>
              <input id="ws-qty" class="input" type="number"
                     min="<?= (int)$u->min_quantity ?>" max="<?= (int)$u->max_quantity ?>"
                     step="<?= (int)($u->increment_step ?: 1) ?>"
                     value="<?= (int)$u->min_quantity ?>">
              <span class="hint"><?=number_format($u->min_quantity)?> – <?=number_format($u->max_quantity)?> units</span>
            </label>
            <div class="row justify-between" style="border-top:1px dashed var(--slate-200);padding-top:.75rem">
              <span class="muted">Total</span>
              <strong id="ws-total" style="font-size:1.25rem"><?=windels_money($u->rate)?></strong>
            </div>
            <button class="btn btn-primary btn-block" type="submit" <?=!empty($current_user) ? '' : 'disabled'?>>
              <?=!empty($current_user) ? 'Continue to order →' : 'Log in to order'?>
            </button>
            <?php if (empty($current_user)): ?>
              <p class="hint text-center"><a href="<?=site_url('login')?>">Log in</a> or <a href="<?=site_url('register')?>">create an account</a> to order.</p>
            <?php endif; ?>
          </form>

          <?php if (!empty($current_user)): ?>
            <div class="mt-4 pt-4" style="border-top:1px solid var(--slate-100)">
              <form method="post" action="<?=site_url($is_favorite ? 'dashboard/favorites/remove/'.$u->public_id : 'dashboard/favorites/add/'.$u->public_id)?>">
                <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
                <button class="btn btn-ghost btn-block btn-sm" type="submit">
                  <?=$is_favorite ? '★ Saved to favorites' : '☆ Save to favorites'?>
                </button>
              </form>
            </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 class="card-title">Why WINDELS</h3>
          <ul class="stack" style="gap:.5rem;padding-left:1.1rem">
            <li>Pricing frozen at checkout — no surprise charges</li>
            <li>Double-entry wallet ledger, refunds handled automatically</li>
            <li>Reseller API with per-key rate limits</li>
            <li>24/7 support and order tracking</li>
          </ul>
        </div>
      </aside>
    </div>
  </div>
</section>

<script <?=csp_nonce_attr()?>>
(function(){
  var qty=document.getElementById('ws-qty'), total=document.getElementById('ws-total');
  if(!qty||!total) return;
  var perUnit=parseFloat(<?=json_encode($per_unit, JSON_PRESERVE_ZERO_FRACTION)?>);
  var isPackage=<?=json_encode($u->service_type === 'PACKAGE')?>;
  function recalc(){
    var q=parseInt(qty.value,10)||0;
    var v = isPackage ? perUnit : perUnit*q;
    total.textContent='$'+v.toFixed(2);
  }
  qty.addEventListener('input',recalc); recalc();
})();
</script>
