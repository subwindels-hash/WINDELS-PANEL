<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="row justify-between mb-4" style="flex-wrap:wrap;gap:.5rem">
  <div class="row" style="gap:.4rem"><a class="btn btn-sm <?=$role === 'BUYER' ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('dashboard/marketplace/orders')?>">Purchases</a><?php if ($seller): ?><a class="btn btn-sm <?=$role === 'SELLER' ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('dashboard/marketplace/orders?as=SELLER')?>">Sales</a><?php endif; ?></div>
  <a class="btn btn-secondary btn-sm" href="<?=site_url('dashboard/marketplace')?>">Browse marketplace</a>
</div>
<div class="card">
<?php if (empty($orders)): ?><p class="muted">No marketplace <?=$role === 'SELLER' ? 'sales' : 'purchases'?> yet.</p>
<?php else: ?><div class="overflow-x-auto"><table class="table"><thead><tr><th>Order</th><th>Listing</th><th><?=$role === 'SELLER' ? 'Buyer' : 'Seller'?></th><th>Qty</th><th class="text-right">Amount</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($orders as $order): ?><tr><td><a class="mono text-xs" href="<?=site_url('dashboard/marketplace/orders/'.$order->public_id)?>"><?=htmlspecialchars($order->public_id)?></a></td><td><?=htmlspecialchars((string)$order->listing_title)?></td><td><?=htmlspecialchars((string)$order->counterparty_name)?></td><td><?=(int)$order->quantity?></td><td class="text-right mono"><?=windels_money($order->gross_amount)?></td><td><span class="badge badge-default"><?=htmlspecialchars($order->status)?></span></td><td class="text-xs muted"><?=htmlspecialchars($order->created_at)?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div>
