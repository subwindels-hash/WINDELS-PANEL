<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name()).'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'">';
?>
<div class="row justify-between mb-4"><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/marketplace/orders')?>">← My purchases</a><span class="badge badge-default"><?=htmlspecialchars($order->status)?></span></div>
<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:1rem">
<div>
  <div class="card mb-4">
    <h2 class="card-title"><?=htmlspecialchars((string)$order->listing_title)?></h2>
    <dl class="text-sm" style="display:grid;grid-template-columns:10rem 1fr;gap:.6rem"><dt class="muted">Order</dt><dd class="mono"><?=htmlspecialchars($order->public_id)?></dd><dt class="muted">Buyer</dt><dd><?=htmlspecialchars((string)$order->buyer_username)?></dd><dt class="muted">Quantity</dt><dd><?=(int)$order->quantity?></dd><dt class="muted">Unit price</dt><dd><?=marvy_money($order->unit_price)?></dd><dt class="muted">Gross</dt><dd><?=marvy_money($order->gross_amount)?></dd><dt class="muted">Ordered</dt><dd><?=htmlspecialchars($order->created_at)?></dd><?php if ($order->delivered_at): ?><dt class="muted">Delivered</dt><dd><?=htmlspecialchars($order->delivered_at)?></dd><?php endif; ?><?php if ($order->release_due_at): ?><dt class="muted">Auto-release</dt><dd><?=htmlspecialchars($order->release_due_at)?> UTC</dd><?php endif; ?></dl>
  </div>
  <?php if ($order->delivery_encrypted): ?>
  <div class="card mb-4"><h3 class="card-title">Secure delivery</h3>
    <?php if ($plain !== null): ?><pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:#f8fafc;padding:1rem;border-radius:.5rem"><?=htmlspecialchars($plain)?></pre><p class="text-xs muted">This access was recorded. Copy what you need before leaving this page.</p>
    <?php else: ?><p class="muted text-sm">Delivery is encrypted. Opening it is recorded for dispute safety.</p><form method="post" action="<?=site_url('dashboard/marketplace/orders/'.$order->public_id.'/reveal')?>"><?=$csrf?><button class="btn btn-secondary" type="submit">Open delivery</button></form><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($order->status === 'DISPUTED'): ?><div class="alert alert-warning"><strong>Dispute:</strong> <?=htmlspecialchars((string)$order->dispute_reason)?><br>Escrow is frozen until an administrator resolves it.</div><?php endif; ?>
  <div class="card"><h3 class="card-title">Timeline</h3><?php foreach ($events as $event): ?><div class="text-sm mb-2"><strong><?=htmlspecialchars($event->event_type)?></strong> <span class="muted"><?=htmlspecialchars($event->created_at)?><?=($event->from_status ? ' · '.$event->from_status.' → '.$event->to_status : '')?></span></div><?php endforeach; ?></div>
</div>
<aside>
  <?php if ($is_buyer && $order->status === 'DELIVERED'): ?><div class="card mb-4"><h3 class="card-title">Review delivery</h3><form method="post" action="<?=site_url('dashboard/marketplace/orders/'.$order->public_id.'/accept')?>" class="mb-3"><?=$csrf?><button class="btn btn-primary" type="submit">Accept and release payment</button></form><form method="post" action="<?=site_url('dashboard/marketplace/orders/'.$order->public_id.'/dispute')?>"><?=$csrf?><label class="label">Problem with delivery</label><textarea class="textarea mb-2" name="reason" minlength="10" maxlength="1000" required></textarea><button class="btn btn-secondary" type="submit">Open dispute</button></form></div><?php endif; ?>
  <div class="card"><h3 class="card-title">Escrow</h3><?php if ($order->status === 'COMPLETED'): ?><p class="text-sm">Payment of <?=marvy_money($order->gross_amount)?> was settled as platform revenue.</p><?php elseif ($order->status === 'REFUNDED'): ?><p class="text-sm">Your <?=marvy_money($order->gross_amount)?> payment was refunded to your wallet.</p><?php else: ?><p class="text-sm muted">Payment of <?=marvy_money($order->gross_amount)?> is secured until this order is resolved.</p><?php endif; ?></div>
</aside>
</div>
