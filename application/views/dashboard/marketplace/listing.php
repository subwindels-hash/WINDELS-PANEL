<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row mb-4" style="gap:.5rem"><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/marketplace')?>">← Marketplace</a></div>
<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:1rem">
  <div class="card">
    <span class="badge badge-default"><?=htmlspecialchars($listing->category)?></span>
    <h2 class="mt-3" style="font-size:1.5rem;font-weight:650"><?=htmlspecialchars($listing->title)?></h2>
    <p class="text-sm muted">Approved seller: <strong><?=htmlspecialchars($listing->seller_name)?></strong></p>
    <div style="white-space:pre-wrap;line-height:1.7"><?=htmlspecialchars($listing->description)?></div>
  </div>
  <aside class="card" style="height:max-content">
    <h3 class="card-title">Order</h3>
    <p style="font-size:1.5rem;font-weight:700"><?=windels_money($listing->price)?><span class="text-sm muted"> each</span></p>
    <p class="text-sm muted">Delivery within <?=(int)$listing->delivery_days?> day(s). <?=($listing->stock === null ? 'Unlimited availability.' : number_format((int)$listing->stock).' currently available.')?></p>
    <p class="text-sm muted">Wallet balance: <strong><?=windels_money($wallet->balance)?></strong></p>
    <?php if ((int)$listing->seller_user_id === (int)$current_user->id): ?>
      <div class="alert alert-warning">This is your listing. Sellers cannot buy their own goods.</div>
    <?php elseif ($listing->stock !== null && (int)$listing->stock < 1): ?>
      <div class="alert alert-warning">This listing is sold out.</div>
    <?php else: ?>
    <form method="post" action="<?=site_url('dashboard/marketplace/'.$listing->public_id.'/buy')?>">
      <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
      <input type="hidden" name="form_token" value="<?=htmlspecialchars(uniqid('mp', true))?>">
      <label class="label" for="quantity">Quantity</label>
      <input class="input mb-4" id="quantity" type="number" name="quantity" value="1" min="1" max="<?=min(100, $listing->stock === null ? 100 : (int)$listing->stock)?>" required>
      <button class="btn btn-primary" type="submit">Pay from wallet</button>
    </form>
    <?php endif; ?>
    <hr class="my-4">
    <p class="text-xs muted">Your payment remains secured while the seller fulfils the order. You may accept delivery or open a dispute before automatic release.</p>
  </aside>
</div>
