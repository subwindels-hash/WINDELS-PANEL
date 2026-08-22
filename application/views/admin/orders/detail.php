<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
// Only offer transitions the state machine will actually accept.
$targets = array_values(array_filter(
    array('PROCESSING','IN_PROGRESS','COMPLETED','PARTIAL','FAILED'),
    function ($s) use ($order) { return $s !== $order->status && OrderStateMachine::can($order->status, $s); }
));
$can_cancel = OrderStateMachine::can($order->status, 'CANCELED');
$can_refund = OrderStateMachine::can($order->status, 'REFUNDED');
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/orders')?>">← All orders</a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">
      Order <span class="mono"><?=htmlspecialchars($order->public_id)?></span>
    </h2>
    <p class="muted text-sm">
      <span class="<?=DashboardStats::status_badge($order->status)?>"><?=htmlspecialchars($order->status)?></span>
      placed <?=htmlspecialchars((string)$order->created_at)?> via <?=htmlspecialchars($order->source)?>
    </p>
  </div>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Order</h3>
    <table class="table">
      <tbody>
        <tr><th>Customer</th><td><?=htmlspecialchars((string)$order->username)?>
            <span class="muted text-xs"><?=htmlspecialchars((string)$order->email)?></span></td></tr>
        <tr><th>Service</th><td><?=htmlspecialchars((string)$order->service_name)?></td></tr>
        <tr><th>Link</th><td class="mono text-xs" style="word-break:break-all"><?=htmlspecialchars((string)$order->link)?></td></tr>
        <tr><th>Quantity</th><td class="mono"><?=number_format((int)$order->quantity)?>
            <?php if ($order->remains !== null): ?><span class="muted">(<?=number_format((int)$order->remains)?> remaining)</span><?php endif; ?></td></tr>
        <tr><th>Charge</th><td class="mono"><?=windels_money($order->charge)?>
            <span class="muted text-xs">@ <?=windels_money($order->rate_at_order)?>/1000</span></td></tr>
        <?php if (bccomp((string)$order->refunded_amount, '0', 8) > 0): ?>
        <tr><th>Refunded</th><td class="mono"><?=windels_money($order->refunded_amount)?></td></tr>
        <?php endif; ?>
        <tr><th>Provider</th><td>
          <?=$order->provider_name ? htmlspecialchars($order->provider_name) : '<span class="muted">— not submitted</span>'?>
          <?php if ($order->provider_order_id): ?>
            <span class="mono text-xs muted">#<?=htmlspecialchars($order->provider_order_id)?></span>
          <?php endif; ?>
        </td></tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Actions</h3>
    <?php if (!$has('orders.edit') && !$has('orders.cancel') && !$has('orders.refund')): ?>
      <p class="muted text-sm">You have read-only access to orders.</p>
    <?php else: ?>

      <?php if ($has('orders.edit') && $order->status === 'PENDING'): ?>
      <form method="post" action="<?=site_url('admin/orders/'.$order->public_id.'/submit')?>" class="mb-4"
            onsubmit="return confirm('Submit this order to its provider?')">
        <?=$csrf()?>
        <button class="btn btn-primary btn-sm" type="submit">Submit to provider</button>
        <p class="hint">The order is held in PENDING (manual review). Submitting pushes it to the provider.</p>
      </form>
      <?php endif; ?>

      <?php if ($has('orders.edit') && $targets): ?>
      <form method="post" action="<?=site_url('admin/orders/'.$order->public_id.'/status')?>" class="mb-4">
        <?=$csrf()?>
        <label class="text-sm font-medium" for="status">Change status</label>
        <select class="input mb-2" id="status" name="status"
                onchange="document.getElementById('remains-row').hidden = this.value !== 'PARTIAL'">
          <?php foreach ($targets as $t): ?>
            <option value="<?=htmlspecialchars($t)?>"><?=htmlspecialchars($t)?></option>
          <?php endforeach; ?>
        </select>
        <div id="remains-row" hidden>
          <label class="text-sm font-medium" for="remains">Undelivered quantity (remains)</label>
          <input class="input mb-2" id="remains" name="remains" inputmode="numeric"
                 max="<?=(int)$order->quantity?>" placeholder="e.g. 250">
          <p class="hint">The undelivered share is refunded to the customer's wallet automatically.</p>
        </div>
        <input class="input mb-2" name="reason" placeholder="Reason (optional, recorded in history)">
        <button class="btn btn-primary btn-sm" type="submit">Apply status</button>
      </form>
      <?php endif; ?>

      <?php if ($has('orders.cancel') && $can_cancel): ?>
      <form method="post" action="<?=site_url('admin/orders/'.$order->public_id.'/cancel')?>" class="mb-2"
            onsubmit="return confirm('Cancel this order and refund the charge?')">
        <?=$csrf()?>
        <input class="input mb-2" name="reason" placeholder="Cancellation reason">
        <button class="btn btn-secondary btn-sm" type="submit">Cancel &amp; refund</button>
      </form>
      <?php endif; ?>

      <?php if ($has('orders.refund') && $can_refund): ?>
      <form method="post" action="<?=site_url('admin/orders/'.$order->public_id.'/refund')?>"
            onsubmit="return confirm('Refund this order in full?')">
        <?=$csrf()?>
        <input class="input mb-2" name="reason" placeholder="Refund reason">
        <button class="btn btn-secondary btn-sm" type="submit">Refund order</button>
      </form>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<div class="card mt-4">
  <h3 class="text-sm font-semibold mb-2">Status history</h3>
  <?php if (empty($history)): ?>
    <p class="muted text-sm">No transitions recorded yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>When</th><th>From</th><th>To</th><th>Source</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td class="text-xs muted"><?=htmlspecialchars((string)$h->created_at)?></td>
          <td class="text-xs"><?=htmlspecialchars((string)($h->previous_status ?? '—'))?></td>
          <td><span class="<?=DashboardStats::status_badge($h->new_status)?>"><?=htmlspecialchars($h->new_status)?></span></td>
          <td class="text-xs"><?=htmlspecialchars((string)$h->source)?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($h->reason ?? ''))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
