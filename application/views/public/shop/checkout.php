<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name()).'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'">';
$u = $current_user;
?>
<section class="ws-section-sm">
  <div class="container" style="max-width:1000px">
    <h1>Checkout</h1>
    <?php $this->load->view('partials/flash'); ?>

    <form method="post" action="<?=site_url('checkout/place')?>">
      <?=$csrf?>
      <div style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,1fr);gap:1.5rem">
        <div class="stack">
          <div class="card">
            <h3 class="card-title">Customer information</h3>
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="field mb-0"><span class="label">Name</span>
                <input class="input" type="text" value="<?=htmlspecialchars($u->username)?>" disabled></label>
              <label class="field mb-0"><span class="label">Email</span>
                <input class="input" type="email" value="<?=htmlspecialchars($u->email)?>" disabled></label>
            </div>
          </div>

          <?php if ($has_physical): ?>
          <div class="card">
            <h3 class="card-title">Shipping address</h3>
            <?php if (!empty($addresses)): ?>
              <label class="field"><span class="label">Use a saved address</span>
                <select class="select" name="shipping_address_id" id="ws-addr-select">
                  <option value="">Enter a new address below</option>
                  <?php foreach ($addresses as $a): ?>
                    <option value="<?=htmlspecialchars($a->public_id)?>"><?=htmlspecialchars($a->full_name)?> — <?=htmlspecialchars($a->line1)?>, <?=htmlspecialchars($a->city)?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
            <div id="ws-new-address" class="grid gap-3 sm:grid-cols-2">
              <label class="field mb-0"><span class="label">Full name</span><input class="input" name="full_name" maxlength="160"></label>
              <label class="field mb-0"><span class="label">Phone</span><input class="input" name="phone" maxlength="32"></label>
              <label class="field mb-0" style="grid-column:1/-1"><span class="label">Address line 1</span><input class="input" name="line1" maxlength="255"></label>
              <label class="field mb-0" style="grid-column:1/-1"><span class="label">Address line 2 (optional)</span><input class="input" name="line2" maxlength="255"></label>
              <label class="field mb-0"><span class="label">City</span><input class="input" name="city" maxlength="120"></label>
              <label class="field mb-0"><span class="label">State/region</span><input class="input" name="state" maxlength="120"></label>
              <label class="field mb-0"><span class="label">Postal code</span><input class="input" name="postal_code" maxlength="32"></label>
              <label class="field mb-0"><span class="label">Country code (e.g. NG, US)</span><input class="input" name="country_code" maxlength="2" style="text-transform:uppercase"></label>
            </div>
            <label class="row mt-2" style="gap:.5rem;align-items:center">
              <input type="checkbox" name="save_address" value="1"><span class="text-sm">Save this address for next time</span>
            </label>
          </div>

          <?php if (!empty($shipping_methods)): ?>
          <div class="card">
            <h3 class="card-title">Shipping method</h3>
            <?php foreach ($shipping_methods as $m): ?>
              <label class="row mb-2" style="gap:.5rem;align-items:flex-start">
                <input type="radio" name="shipping_method" value="<?=htmlspecialchars($m->public_id)?>" <?=$loop_first ?? false ? 'checked' : ''?>>
                <span><strong><?=htmlspecialchars($m->name)?></strong> — <?=marvy_money($m->price, $m->currency)?>
                  <?php if ($m->estimated_days_min): ?><span class="text-xs muted"> (<?=(int)$m->estimated_days_min?>-<?=(int)$m->estimated_days_max?> days)</span><?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php endif; ?>

          <div class="card">
            <h3 class="card-title">Payment</h3>
            <p class="text-sm muted">Charged from your wallet balance: <strong><?=marvy_money($wallet->balance ?? '0')?></strong> available.</p>
            <?php if (bccomp($wallet->balance ?? '0', $total, 8) < 0): ?>
              <div class="alert alert-warning mb-0">Your wallet balance is too low for this order. <a href="<?=site_url('dashboard/add-funds')?>">Add funds</a> first.</div>
            <?php endif; ?>
          </div>
        </div>

        <aside class="card" style="height:max-content">
          <h3 class="card-title">Order summary</h3>
          <?php foreach ($lines as $line): $item = $line['item']; ?>
            <div class="row justify-between text-sm mb-1"><span><?=htmlspecialchars($item->title)?> × <?=(int)$item->quantity?></span><span class="mono"><?=marvy_money($line['line_total'], $item->currency)?></span></div>
          <?php endforeach; ?>
          <hr class="my-3">
          <div class="row justify-between"><span class="muted">Subtotal</span><span class="mono"><?=marvy_money($subtotal, $currency)?></span></div>
          <?php if (bccomp($discount, '0', 8) > 0): ?>
          <div class="row justify-between"><span class="muted">Discount</span><span class="mono">−<?=marvy_money($discount, $currency)?></span></div>
          <?php endif; ?>
          <div class="row justify-between mt-2" style="border-top:1px dashed var(--slate-200);padding-top:.5rem">
            <strong>Total</strong><strong class="mono" style="font-size:1.2rem"><?=marvy_money($total, $currency)?></strong>
          </div>
          <button class="btn btn-primary btn-block mt-3" type="submit"
                  <?=bccomp($wallet->balance ?? '0', $total, 8) < 0 ? 'disabled' : ''?>>Place order</button>
          <a class="btn btn-ghost btn-block mt-2" href="<?=site_url('cart')?>">← Back to cart</a>
        </aside>
      </div>
    </form>
  </div>
</section>
