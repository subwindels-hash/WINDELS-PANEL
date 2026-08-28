<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <div class="row justify-between">
        <div>
          <div class="muted text-xs mono"><?=htmlspecialchars($order->public_id)?></div>
          <h2 class="card-title mt-1"><?=htmlspecialchars($service->name ?? 'Service #'.$order->service_id)?></h2>
        </div>
        <span class="<?=DashboardStats::status_badge($order->status)?>" style="align-self:flex-start"><?=htmlspecialchars(ucwords(strtolower(str_replace('_',' ',$order->status))))?></span>
      </div>

      <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 mt-4">
        <div><dt class="muted text-xs">Quantity</dt><dd class="font-semibold"><?=number_format($order->quantity)?></dd></div>
        <div><dt class="muted text-xs">Charge</dt><dd class="font-semibold"><?=marvy_money($order->charge, $order->currency)?><?php
            if (!empty($coupon) && isset($coupon_discount) && bccomp((string)$coupon_discount, '0', 8) > 0):
              ?> <span class="badge badge-success" title="Coupon <?=htmlspecialchars((string)$coupon->code)?> applied">−<?=marvy_money($coupon_discount)?> coupon</span><?php
            endif;
        ?></dd></div>
        <div><dt class="muted text-xs">Rate / 1k</dt><dd class="font-semibold"><?=marvy_money($order->rate_at_order, $order->currency)?></dd></div>
        <div><dt class="muted text-xs">Source</dt><dd class="font-semibold"><?=htmlspecialchars($order->source)?></dd></div>
        <div><dt class="muted text-xs">Start count</dt><dd class="font-semibold"><?=$order->start_count!==null ? number_format($order->start_count) : '—'?></dd></div>
        <div><dt class="muted text-xs">Remains</dt><dd class="font-semibold"><?=$order->remains!==null ? number_format($order->remains) : '—'?></dd></div>
      </dl>

      <div class="mt-4">
        <div class="muted text-xs">Link</div>
        <a class="text-sm break-all" href="<?=htmlspecialchars($order->link)?>" target="_blank" rel="noopener nofollow"><?=htmlspecialchars($order->link)?></a>
      </div>

      <?php if ($order->refunded_amount && bccomp($order->refunded_amount,'0',8)>0): ?>
        <div class="alert alert-warning mt-4 mb-0">
          Refunded <?=marvy_money($order->refunded_amount, $order->currency)?> for the undelivered portion.
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 class="card-title">Status history</h3>
      <?php if (empty($history)): ?>
        <p class="muted">No status changes recorded.</p>
      <?php else: ?>
      <ol class="ws-timeline">
        <?php foreach ($history as $h): ?>
        <li>
          <div class="dot"></div>
          <div>
            <div class="font-medium text-sm">
              <?=htmlspecialchars($h->previous_status ? ucwords(strtolower(str_replace('_',' ',$h->previous_status))).' → ' : '')?>
              <?=htmlspecialchars(ucwords(strtolower(str_replace('_',' ',$h->new_status))))?>
            </div>
            <div class="muted text-xs"><?=htmlspecialchars($h->source)?> · <?=date('M j, Y H:i', strtotime($h->created_at))?> UTC</div>
            <?php if (!empty($h->reason)): ?><div class="text-sm mt-1"><?=htmlspecialchars($h->reason)?></div><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
  </div>

  <aside class="space-y-6">
    <div class="card">
      <h3 class="card-title">Actions</h3>
      <div class="stack">
        <?php
          $cancelable = in_array($order->status, array('PENDING','PROCESSING','IN_PROGRESS'), true);
          $cancel_supported = !empty($service) && (int)$service->cancel_supported === 1;
        ?>
        <?php if ($cancelable && $cancel_supported): ?>
          <form method="post" action="<?=site_url('dashboard/orders/'.$order->public_id.'/cancel')?>"
                data-confirm="Cancel this order? An in-progress order may still be delivered." >
            <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
            <button class="btn btn-danger btn-block" type="submit">Request cancellation</button>
          </form>
        <?php elseif ($cancelable): ?>
          <button class="btn btn-secondary btn-block" disabled>Cancellation not supported</button>
        <?php endif; ?>
        <?php if (!empty($service) && (int)$service->refill_supported === 1 && in_array($order->status, array('COMPLETED','PARTIAL'), true)): ?>
          <form method="post" action="<?=site_url('dashboard/orders/'.$order->public_id.'/refill')?>"
                data-confirm="Request a refill for this order?" >
            <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
            <button class="btn btn-secondary btn-block" type="submit">Request refill</button>
          </form>
        <?php elseif (!empty($service) && (int)$service->refill_supported === 1): ?>
          <button class="btn btn-secondary btn-block" disabled>Refill available on completion</button>
        <?php endif; ?>
        <a class="btn btn-ghost btn-block" href="<?=site_url('dashboard/orders')?>">← Back to orders</a>
      </div>
    </div>
    <div class="card">
      <h3 class="card-title">Need help?</h3>
      <p class="text-sm muted">Open a support ticket and reference order
        <span class="mono"><?=htmlspecialchars(substr($order->public_id,0,12))?>…</span></p>
      <a class="btn btn-primary btn-sm mt-2" href="<?=site_url('dashboard/tickets')?>">Contact support</a>
    </div>
  </aside>
</div>

<style>
.ws-timeline{list-style:none;margin:1rem 0 0;padding:0;position:relative}
.ws-timeline::before{content:"";position:absolute;left:7px;top:6px;bottom:6px;width:2px;background:var(--slate-200)}
.ws-timeline li{position:relative;padding:0 0 1rem 1.75rem}
.ws-timeline .dot{position:absolute;left:2px;top:4px;width:12px;height:12px;border-radius:50%;background:var(--brand-500);box-shadow:0 0 0 3px #fff}
</style>
