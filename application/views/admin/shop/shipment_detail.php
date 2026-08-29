<?php defined('BASEPATH') OR exit('No direct script access allowed');
$s = $shipment;
$csrf = '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name()).'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'">';
$can_resolve = in_array('*', $permissions ?? array(), true) || in_array('marketplace.resolve', $permissions ?? array(), true);
$refundable = in_array((string)$s->order_status, array('PAID', 'DELIVERED', 'DISPUTED', 'PARTIALLY_REFUNDED'), true) && empty($s->order_released_at);
?>
<div class="row justify-between mb-4">
  <div>
    <a class="text-xs muted" href="<?=site_url('admin/shop/shipments')?>">← Shipments</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Shipment for order <span class="mono"><?=htmlspecialchars($s->order_public_id)?></span></h2>
  </div>
  <div class="row" style="gap:.4rem;align-items:center">
    <span class="text-xs muted">Order: <?=htmlspecialchars((string)$s->order_status)?></span>
    <span class="badge badge-default"><?=htmlspecialchars($s->status)?></span>
  </div>
</div>

<div class="row" style="gap:1rem;flex-wrap:wrap;align-items:flex-start">
  <div class="card" style="flex:1;min-width:20rem">
    <h3 class="card-title">Delivery address</h3>
    <p class="text-sm"><?=htmlspecialchars($s->full_name)?><br><?=htmlspecialchars($s->phone)?><br>
      <?=htmlspecialchars($s->line1)?><?php if ($s->line2): ?>, <?=htmlspecialchars($s->line2)?><?php endif; ?><br>
      <?=htmlspecialchars($s->city)?><?php if ($s->state): ?>, <?=htmlspecialchars($s->state)?><?php endif; ?> <?=htmlspecialchars((string)$s->postal_code)?><br>
      <?=htmlspecialchars($s->country_code)?></p>
    <p class="text-sm muted">Product: <?=htmlspecialchars((string)$s->listing_title)?></p>
    <?php if ($s->physical_sku): ?><p class="text-sm muted">SKU: <span class="mono"><?=htmlspecialchars($s->physical_sku)?></span><?php if ($s->weight_grams !== null): ?> · <?=number_format((int)$s->weight_grams)?> g<?php endif; ?><?php if ($s->length_cm !== null && $s->width_cm !== null && $s->height_cm !== null): ?> · <?=htmlspecialchars($s->length_cm)?> × <?=htmlspecialchars($s->width_cm)?> × <?=htmlspecialchars($s->height_cm)?> cm<?php endif; ?></p><?php endif; ?>
    <?php if ($s->shipping_method_name): ?><p class="text-sm muted">Method: <?=htmlspecialchars($s->shipping_method_name)?><?php if ($s->shipping_cost !== null): ?> · <?=marvy_money($s->shipping_cost)?><?php endif; ?></p><?php endif; ?>
  </div>
  <div class="card" style="flex:1;min-width:20rem">
    <h3 class="card-title">Update status</h3>
    <form method="post" action="<?=site_url('admin/shop/shipments/'.$s->public_id.'/status')?>" class="stack">
      <?=$csrf?>
      <label class="field"><span class="label">Status</span>
        <select class="select" name="status">
          <?php foreach ($statuses as $st): ?><option value="<?=$st?>" <?=$s->status===$st?'selected':''?>><?=ucfirst(strtolower($st))?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span class="label">Carrier</span>
        <input class="input" name="carrier" value="<?=htmlspecialchars((string)$s->carrier)?>"></label>
      <label class="field"><span class="label">Tracking number</span>
        <input class="input mono" name="tracking_number" value="<?=htmlspecialchars((string)$s->tracking_number)?>"></label>
      <label class="field"><span class="label">Tracking URL (optional)</span>
        <input class="input" type="url" name="tracking_url" maxlength="500" value="<?=htmlspecialchars((string)$s->tracking_url)?>" placeholder="https://carrier.example/track/…"></label>
      <?php if ($s->shipped_at): ?><p class="text-xs muted mb-0">Shipped at <?=htmlspecialchars($s->shipped_at)?> UTC.</p><?php endif; ?>
      <?php if ($s->delivered_at): ?><p class="text-xs muted mb-0">Delivered at <?=htmlspecialchars($s->delivered_at)?> UTC.</p><?php endif; ?>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
  </div>
</div>

<?php if ($can_resolve): ?>
<div class="card mt-4" style="max-width:32rem">
  <h3 class="card-title">Refund this order</h3>
  <?php if ($s->order_status === 'REFUNDED'): ?>
    <p class="text-sm muted">This order has already been refunded from escrow.</p>
  <?php elseif (!$refundable): ?>
    <p class="text-sm muted">
      This order's escrow (status <?=htmlspecialchars((string)$s->order_status)?><?=!empty($s->order_released_at) ? ', already released' : ''?>)
      can no longer be refunded from here.
    </p>
  <?php else: ?>
    <p class="muted text-sm mb-3">
      Refunds the buyer from escrow (same path as Marketplace → dispute resolution) and marks this
      shipment cancelled. This cannot be undone.
    </p>
    <form method="post" action="<?=site_url('admin/shop/shipments/'.$s->public_id.'/refund')?>" class="stack"
          data-confirm="Refund this order from escrow? This cannot be undone." >
      <?=$csrf?>
      <label class="field"><span class="label">Reason (shown in the audit trail)</span>
        <input class="input" name="reason" maxlength="500" placeholder="e.g. Item never arrived"></label>
      <button class="btn btn-danger" type="submit">Refund order</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

