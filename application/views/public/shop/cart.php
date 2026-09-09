<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name()).'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'">';
?>
<section class="ws-section-sm">
  <div class="container" style="max-width:1000px">
    <h1>Your cart</h1>
    <?php $this->load->view('partials/flash'); ?>

    <?php if (empty($lines)): ?>
      <div class="card text-center" style="padding:3rem">
        <p class="muted">Your cart is empty.</p>
        <a class="btn btn-primary mt-4" href="<?=site_url('shop')?>">Browse the shop</a>
      </div>
    <?php else: ?>
    <div class="card">
      <table class="table">
        <thead><tr><th>Product</th><th class="text-right">Price</th><th>Qty</th><th class="text-right">Subtotal</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $line): $item = $line['item']; ?>
          <tr>
            <td>
              <a href="<?=site_url('shop/product/'.$item->listing_public_id)?>"><?=htmlspecialchars($item->title)?></a>
              <?php if ($line['unavailable']): ?><div class="text-xs" style="color:var(--danger-600)">No longer available</div><?php endif; ?>
              <?php if ($line['out_of_stock']): ?><div class="text-xs" style="color:var(--danger-600)">Not enough stock — reduce quantity</div><?php endif; ?>
              <?php if (!empty($line['physical_details_missing'])): ?><div class="text-xs" style="color:var(--danger-600)">Shipping details are not ready yet — staff must finish this listing.</div><?php endif; ?>
            </td>
            <td class="text-right mono"><?=marvy_money($line['unit_price'], $item->currency)?></td>
            <td>
              <form method="post" action="<?=site_url('cart/update')?>" class="row" style="gap:.3rem">
                <?=$csrf?>
                <input type="hidden" name="listing" value="<?=htmlspecialchars($item->listing_public_id)?>">
                <input class="input mono" type="number" name="quantity" value="<?=(int)$item->quantity?>" min="0" max="100" style="width:5rem">
                <button class="btn btn-ghost btn-sm" type="submit">Update</button>
              </form>
            </td>
            <td class="text-right mono"><?=marvy_money($line['line_total'], $item->currency)?></td>
            <td>
              <form method="post" action="<?=site_url('cart/remove')?>">
                <?=$csrf?>
                <input type="hidden" name="listing" value="<?=htmlspecialchars($item->listing_public_id)?>">
                <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="row" style="gap:1rem;align-items:flex-start;margin-top:1rem;flex-wrap:wrap">
      <div class="card" style="flex:1;min-width:16rem">
        <h3 class="card-title">Coupon</h3>
        <?php if (!empty($coupon)): ?>
          <p class="text-sm">Applied: <strong><?=htmlspecialchars($coupon->code)?></strong> (<?=marvy_money($discount)?> off)</p>
          <form method="post" action="<?=site_url('cart/coupon')?>">
            <?=$csrf?><input type="hidden" name="action" value="remove">
            <button class="btn btn-ghost btn-sm" type="submit">Remove coupon</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?=site_url('cart/coupon')?>" class="row" style="gap:.4rem">
            <?=$csrf?>
            <input class="input" type="text" name="code" placeholder="Coupon code" style="max-width:12rem">
            <button class="btn btn-secondary btn-sm" type="submit">Apply</button>
          </form>
          <?php if (!empty($available_coupons)): ?>
          <div class="mt-3">
            <p class="text-xs muted mb-1">Available right now:</p>
            <div class="stack" style="gap:.4rem">
              <?php foreach ($available_coupons as $ac): ?>
                <form method="post" action="<?=site_url('cart/coupon')?>" class="row justify-between" style="gap:.4rem;align-items:center;border:1px dashed var(--slate-200);border-radius:.5rem;padding:.4rem .6rem">
                  <?=$csrf?>
                  <input type="hidden" name="code" value="<?=htmlspecialchars($ac->code)?>">
                  <div>
                    <strong class="mono text-sm"><?=htmlspecialchars($ac->code)?></strong>
                    <div class="text-xs muted">
                      <?=$ac->discount_type === 'PERCENT' ? number_format((float)$ac->discount_value, 0).'% off' : marvy_money($ac->discount_value, $ac->currency).' off'?>
                      <?php if ($ac->description): ?> — <?=htmlspecialchars($ac->description)?><?php endif; ?>
                    </div>
                  </div>
                  <button class="btn btn-ghost btn-sm" type="submit">Use this</button>
                </form>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card" style="flex:1;min-width:16rem">
        <h3 class="card-title">Summary</h3>
        <dl class="stack" style="gap:.4rem">
          <div class="row justify-between"><span class="muted">Subtotal</span><strong class="mono"><?=marvy_money($subtotal, $currency)?></strong></div>
          <?php if (bccomp($discount, '0', 8) > 0): ?>
          <div class="row justify-between"><span class="muted">Discount</span><strong class="mono">−<?=marvy_money($discount, $currency)?></strong></div>
          <?php endif; ?>
          <?php if ($has_physical): ?>
          <div class="row justify-between"><span class="muted">Shipping</span><strong class="mono text-xs muted">Calculated at checkout</strong></div>
          <?php endif; ?>
          <div class="row justify-between" style="border-top:1px dashed var(--slate-200);padding-top:.5rem">
            <span class="font-medium">Total</span><strong class="mono" style="font-size:1.2rem"><?=marvy_money($total, $currency)?></strong>
          </div>
        </dl>
        <a class="btn btn-primary btn-block mt-3" href="<?=site_url('checkout')?>">Proceed to Checkout</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
