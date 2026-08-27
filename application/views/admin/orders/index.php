<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$statuses = array('PENDING','PROCESSING','IN_PROGRESS','COMPLETED','PARTIAL','CANCELED','FAILED','REFUNDED');
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('status'=>$filters['status'], 'source'=>$filters['source'], 'q'=>$filters['search'], 'page'=>$page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== ''; });
    return $merged ? '?'.http_build_query($merged) : '';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Orders</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> order<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <form method="get" action="<?=site_url('admin/orders')?>" class="row" style="gap:.35rem">
    <?php if (!empty($filters['status'])): ?><input type="hidden" name="status" value="<?=htmlspecialchars($filters['status'])?>"><?php endif; ?>
    <input class="input" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>"
           placeholder="Order ID, provider ID or link" aria-label="Search orders" style="min-width:16rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/orders'.$qs(array('status'=>null,'page'=>null)))?>">
    All <span class="muted"><?=number_format(array_sum($counts))?></span>
  </a>
  <?php foreach ($statuses as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=$filters['status'] === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/orders'.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($orders)): ?>
    <p class="muted">No orders match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Order</th><th>Customer</th><th>Service</th><th class="text-right">Qty</th>
            <th class="text-right">Charge</th><th>Status</th><th>Placed</th></tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>
            <a class="mono text-xs" href="<?=site_url('admin/orders/'.$o->public_id)?>"><?=htmlspecialchars($o->public_id)?></a>
            <?php if ($o->source !== 'WEB'): ?><span class="badge badge-default"><?=htmlspecialchars($o->source)?></span><?php endif; ?>
          </td>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$o->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$o->email)?></div>
          </td>
          <td><?=htmlspecialchars((string)$o->service_name)?></td>
          <td class="text-right mono"><?=number_format((int)$o->quantity)?></td>
          <td class="text-right mono"><?=marvy_money($o->charge)?></td>
          <td><span class="<?=DashboardStats::status_badge($o->status)?>"><?=htmlspecialchars($o->status)?></span></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)$o->created_at)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/orders'.$qs(array('page'=>max(1, $page-1))))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/orders'.$qs(array('page'=>min($total_pages, $page+1))))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
