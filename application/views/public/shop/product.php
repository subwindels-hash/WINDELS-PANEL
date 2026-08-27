<?php defined('BASEPATH') OR exit('No direct script access allowed');
$l = $listing;
$on_sale = $l->promo_price !== null && (float)$l->promo_price > 0 && bccomp($l->promo_price, $l->price, 8) < 0;
$effective_price = $on_sale ? $l->promo_price : $l->price;
?>
<section class="ws-section-sm">
  <div class="container" style="max-width:1100px">
    <a class="text-xs muted" href="<?=site_url('shop')?>">← Shop</a>
    <div style="display:grid;grid-template-columns:minmax(0,1.3fr) minmax(280px,1fr);gap:2rem;margin-top:1rem">
      <div>
        <div class="card">
          <?php if (!empty($l->image)): ?><img alt="<?=htmlspecialchars($l->title)?>" src="<?=base_url($l->image)?>" style="width:100%;max-height:22rem;object-fit:cover;border-radius:.75rem" class="mb-4"><?php endif; ?>
          <div class="row" style="gap:.4rem">
            <span class="badge badge-default"><?=htmlspecialchars($l->category)?></span>
            <span class="badge badge-default"><?=$l->product_type === 'PHYSICAL' ? 'Physical product' : 'Digital product'?></span>
            <?php if ((int)$l->is_featured === 1): ?><span class="badge badge-warning">Featured</span><?php endif; ?>
            <?php if (!empty($rating['count'])): ?><span class="badge badge-info">★ <?=number_format($rating['average'],1)?> (<?=$rating['count']?>)</span><?php endif; ?>
          </div>
          <h1 class="mt-3" style="font-size:1.6rem"><?=htmlspecialchars($l->title)?></h1>
          <div style="white-space:pre-wrap;line-height:1.7" class="mt-2"><?=htmlspecialchars($l->description)?></div>
        </div>

        <div class="card mt-4">
          <h3 class="card-title">Delivery &amp; refund policy</h3>
          <?php if ($l->product_type === 'PHYSICAL'): ?>
            <p class="text-sm muted">Ships within <?=(int)$l->delivery_days?> day(s) once payment is confirmed. Provide a shipping address at checkout; you can track your order from My Orders.</p>
          <?php else: ?>
            <p class="text-sm muted">Delivered digitally within <?=(int)$l->delivery_days?> day(s) — most digital items unlock immediately after payment. Access appears under Dashboard → Downloads.</p>
          <?php endif; ?>
          <p class="text-sm muted">Your payment stays secured in escrow until you accept delivery or the review window closes. See our <a href="<?=site_url('refund-policy')?>">refund policy</a> for details.</p>
        </div>

        <?php if (!empty($reviews)): ?>
        <div class="card mt-4">
          <h3 class="card-title">Reviews</h3>
          <?php foreach ($reviews as $r): ?>
            <div class="mb-3" style="border-bottom:1px solid var(--color-border);padding-bottom:.75rem">
              <div class="row justify-between"><strong><?=str_repeat('★', (int)$r->rating).str_repeat('☆', 5-(int)$r->rating)?></strong>
                <span class="text-xs muted"><?=htmlspecialchars($r->username)?> · <?=htmlspecialchars(date('M j, Y', strtotime($r->created_at)))?></span></div>
              <?php if ($r->title): ?><div class="font-medium mt-1"><?=htmlspecialchars($r->title)?></div><?php endif; ?>
              <?php if ($r->body): ?><p class="text-sm muted mt-1"><?=htmlspecialchars($r->body)?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <aside class="card" style="height:max-content">
        <h3 class="card-title">Buy</h3>
        <p style="font-size:1.6rem;font-weight:700"><?=marvy_money($effective_price)?>
          <?php if ($on_sale): ?><br><span class="text-sm muted" style="text-decoration:line-through;font-weight:400"><?=marvy_money($l->price)?></span> <span class="badge badge-warning">Promo</span><?php endif; ?>
        </p>
        <p class="text-sm muted"><?=($l->stock === null ? 'In stock' : ((int)$l->stock > 0 ? number_format((int)$l->stock).' available' : 'Sold out'))?></p>

        <?php if ($l->stock !== null && (int)$l->stock < 1): ?>
          <div class="alert alert-warning">This item is sold out.</div>
        <?php elseif (!$current_user): ?>
          <a class="btn btn-primary btn-block" href="<?=site_url('login?redirect='.rawurlencode('shop/product/'.$l->public_id))?>">Sign in to buy</a>
        <?php else: ?>
        <form method="post" action="<?=site_url('cart/add')?>" class="stack">
          <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
          <input type="hidden" name="listing" value="<?=htmlspecialchars($l->public_id)?>">
          <input type="hidden" name="redirect_to" value="cart">
          <label class="label" for="quantity">Quantity</label>
          <input class="input mb-3" id="quantity" type="number" name="quantity" value="1" min="1"
                 max="<?=min(100, $l->stock === null ? 100 : (int)$l->stock)?>" required>
          <button class="btn btn-primary btn-block" type="submit">Add to Cart</button>
        </form>
        <form method="post" action="<?=site_url('cart/add')?>" class="mt-2">
          <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
          <input type="hidden" name="listing" value="<?=htmlspecialchars($l->public_id)?>">
          <input type="hidden" name="quantity" value="1">
          <input type="hidden" name="redirect_to" value="checkout">
          <button class="btn btn-secondary btn-block" type="submit">Buy Now</button>
        </form>
        <?php endif; ?>
        <hr class="my-4">
        <p class="text-xs muted">The final price is always the server-side catalogue price. Payment is secured through your wallet until delivery is confirmed.</p>
      </aside>
    </div>
  </div>
</section>
