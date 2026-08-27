<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Shop</h2>
    <p class="muted text-sm">Digital delivery, shipments, shipping methods, coupons and reviews. Products, categories and the order queue live in Marketplace.</p>
  </div>
</div>

<div class="grid grid-4 mb-4">
  <?php foreach (array('PENDING','PROCESSING','SHIPPED','DELIVERED') as $s): ?>
  <div class="card">
    <div class="muted text-xs"><?=ucfirst(strtolower($s))?> shipments</div>
    <div class="mono" style="font-size:1.5rem;font-weight:600"><?=(int)($shipment_counts[$s] ?? 0)?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="ws-action-grid">
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/marketplace?tab=listings')?>">
    <h3 class="card-title">Products</h3><p class="muted text-sm">Create, edit and publish digital, physical and marketplace listings.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/marketplace?tab=orders')?>">
    <h3 class="card-title">Orders</h3><p class="muted text-sm">The full order queue — escrow, delivery and disputes.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/shop/downloads')?>">
    <h3 class="card-title">Digital downloads</h3><p class="muted text-sm">See and, if necessary, revoke a customer's download access.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/shop/shipments')?>">
    <h3 class="card-title">Shipments</h3><p class="muted text-sm">Physical orders — address, carrier and tracking number.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/shop/shipping-methods')?>">
    <h3 class="card-title">Shipping methods</h3><p class="muted text-sm">Rates and estimated delivery windows offered at checkout.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/shop/coupons')?>">
    <h3 class="card-title">Coupons</h3><p class="muted text-sm">Percentage or fixed discounts, with usage limits and date windows.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/shop/reviews')?>">
    <h3 class="card-title">Reviews</h3><p class="muted text-sm">Moderate verified-purchase reviews before they appear on a product page.</p>
  </a>
  <a class="card card-hover ws-action-card" href="<?=site_url('admin/giftcards')?>">
    <h3 class="card-title">Gift cards</h3><p class="muted text-sm">The gift-card vendor catalogue and delivered-order queue.</p>
  </a>
</div>
