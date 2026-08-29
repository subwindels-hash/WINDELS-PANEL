<?php defined('BASEPATH') OR exit('No direct script access allowed');
$on_sale = $listing->promo_price !== null && (float)$listing->promo_price > 0 && bccomp($listing->promo_price, $listing->price, 8) < 0;
$effective_price = $on_sale ? $listing->promo_price : $listing->price;
?>
<div class="row mb-4" style="gap:.5rem"><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/marketplace')?>">← Marketplace</a></div>
<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:1rem">
  <div class="card">
    <?php if (!empty($listing->image)): ?><img alt="<?=htmlspecialchars($listing->title)?>" src="<?=base_url($listing->image)?>" style="width:100%;max-height:18rem;object-fit:cover;border-radius:.5rem" class="mb-4"><?php endif; ?>
    <span class="badge badge-default"><?=htmlspecialchars($listing->category)?></span>
    <span class="badge badge-default"><?=$listing->product_type === 'PHYSICAL' ? 'Physical product' : 'Digital product'?></span>
    <?php if ((int)$listing->is_featured === 1): ?><span class="badge badge-warning">Featured</span><?php endif; ?>
    <h2 class="mt-3" style="font-size:1.5rem;font-weight:650"><?=htmlspecialchars($listing->title)?></h2>
    <p class="text-sm muted">An official platform listing.</p>
    <div style="white-space:pre-wrap;line-height:1.7"><?=htmlspecialchars($listing->description)?></div>
  </div>
  <aside class="card" style="height:max-content">
    <h3 class="card-title">Order</h3>
    <p style="font-size:1.5rem;font-weight:700"><?=marvy_money($effective_price)?><span class="text-sm muted"> each</span>
      <?php if ($on_sale): ?><br><span class="text-sm muted" style="text-decoration:line-through;font-weight:400"><?=marvy_money($listing->price)?></span> <span class="badge badge-warning">Promo</span><?php endif; ?>
    </p>
    <p class="text-sm muted"><?=($listing->product_type === 'PHYSICAL' ? 'Ships' : 'Digital delivery')?> within <?=(int)$listing->delivery_days?> day(s). <?=($listing->stock === null ? 'Unlimited availability.' : number_format((int)$listing->stock).' currently available.')?></p>
    <p class="text-sm muted">Wallet balance: <strong><?=marvy_money($wallet->balance, $wallet->currency ?? marvy_base_currency())?></strong></p>
    <?php if ($listing->stock !== null && (int)$listing->stock < 1): ?>
      <div class="alert alert-warning">This listing is sold out.</div>
    <?php else: ?>
    <form method="post" action="<?=site_url('dashboard/marketplace/'.$listing->public_id.'/buy')?>">
      <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
      <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('mp', true))?>">
      <label class="label" for="quantity">Quantity</label>
      <input class="input mb-4" id="quantity" type="number" name="quantity" value="1" min="1" max="<?=min(100, $listing->stock === null ? 100 : (int)$listing->stock)?>" required>
      <button class="btn btn-primary" type="submit"><?=($listing->product_type === 'PHYSICAL' ? 'Add to cart and choose shipping' : 'Pay from wallet')?></button>
    </form>
    <?php endif; ?>
    <hr class="my-4">
    <p class="text-xs muted">The final price is always the server-side catalogue price. Your payment remains secured until you accept delivery or the escrow window closes.</p>
  </aside>
</div>
