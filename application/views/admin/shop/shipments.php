<?php defined('BASEPATH') OR exit('No direct script access allowed');
$badge = function ($s) {
    $map = array('PENDING'=>'badge-default','PROCESSING'=>'badge-info','SHIPPED'=>'badge-warning',
                 'DELIVERED'=>'badge-success','CANCELLED'=>'badge-danger','RETURNED'=>'badge-danger');
    return $map[$s] ?? 'badge-default';
};
?>
<div class="row justify-between mb-4">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Shipments</h2>
    <p class="muted text-sm"><?=number_format($total)?> physical order(s).</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/shop')?>">← Shop</a>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('admin/shop/shipments')?>">All</a>
  <?php foreach ($statuses as $s): ?>
    <a class="btn btn-sm <?=($filters['status'] ?? '') === $s ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('admin/shop/shipments?status='.$s)?>"><?=ucfirst(strtolower($s))?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($shipments)): ?>
    <p class="muted text-sm">No shipments match this filter.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Status</th><th>Tracking</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($shipments as $s): ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars($s->order_public_id)?></td>
        <td class="text-xs"><?=htmlspecialchars((string)$s->username)?></td>
        <td class="text-xs"><?=htmlspecialchars((string)$s->listing_title)?></td>
        <td><span class="badge <?=$badge($s->status)?>"><?=htmlspecialchars($s->status)?></span></td>
        <td class="text-xs mono">
          <?php if ($s->tracking_url): ?><a href="<?=htmlspecialchars($s->tracking_url)?>" rel="noopener noreferrer" target="_blank"><?=htmlspecialchars((string)($s->tracking_number ?: 'Track'))?></a><?php else: ?><?=htmlspecialchars((string)($s->tracking_number ?: '—'))?><?php endif; ?>
        </td>
        <td><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/shop/shipments/'.$s->public_id)?>">Manage →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
