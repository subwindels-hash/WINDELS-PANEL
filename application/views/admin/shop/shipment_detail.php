<?php defined('BASEPATH') OR exit('No direct script access allowed');
$s = $shipment;
$csrf = '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name()).'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'">';
$can_resolve = in_array('*', $permissions ?? array(), true) || in_array('marketplace.resolve', $permissions ?? array(), true);
$refundable = in_array((string)$s->order_status, array('PAID', 'DELIVERED', 'DISPUTED'), true) && empty($s->order_released_at);
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
          onsubmit="return confirm('Refund this order from escrow? This cannot be undone.');">
      <?=$csrf?>
      <label class="field"><span class="label">Reason (shown in the audit trail)</span>
        <input class="input" name="reason" maxlength="500" placeholder="e.g. Item never arrived"></label>
      <button class="btn btn-danger" type="submit">Refund order</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

