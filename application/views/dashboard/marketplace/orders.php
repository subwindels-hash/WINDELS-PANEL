<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between mb-4" style="flex-wrap:wrap;gap:.5rem">
  <h2 class="card-title mb-0">My purchases</h2>
  <a class="btn btn-secondary btn-sm" href="<?=site_url('dashboard/marketplace')?>">Browse marketplace</a>
</div>
<div class="card">
<?php if (empty($orders)): ?><p class="muted">No marketplace purchases yet.</p>
<?php else: ?><div class="overflow-x-auto"><table class="table"><thead><tr><th>Order</th><th>Listing</th><th>Qty</th><th class="text-right">Amount</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($orders as $order): ?><tr><td><a class="mono text-xs" href="<?=site_url('dashboard/marketplace/orders/'.$order->public_id)?>"><?=htmlspecialchars($order->public_id)?></a></td><td><?=htmlspecialchars((string)$order->listing_title)?></td><td><?=(int)$order->quantity?></td><td class="text-right mono"><?=marvy_money($order->gross_amount)?></td><td><span class="badge badge-default"><?=htmlspecialchars($order->status)?></span></td><td class="text-xs muted"><?=htmlspecialchars($order->created_at)?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div>
