<?php defined('BASEPATH') OR exit('No direct script access allowed');
$statuses = array(''=>'All','PENDING'=>'Pending','IN_PROGRESS'=>'In progress','COMPLETED'=>'Completed','PARTIAL'=>'Partial','CANCELED'=>'Canceled','FAILED'=>'Failed');
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <div>
      <h2 class="card-title mb-0">My orders</h2>
      <p class="muted text-sm mt-1"><?=number_format($total)?> total · page <?=$page?> of <?=$total_pages?></p>
    </div>
    <div class="row">
      <form method="get" class="row" style="gap:.5rem">
        <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
          <?php foreach ($statuses as $k=>$v): ?>
            <option value="<?=htmlspecialchars($k)?>" <?=($status===$k)?'selected':''?>><?=htmlspecialchars($v)?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/new-order')?>">+ New order</a>
    </div>
  </div>

  <?php if (empty($orders)): ?>
    <p class="muted mt-6">No orders found<?=$status?' with status "'.htmlspecialchars($status).'"':''?>.
      <a href="<?=site_url('dashboard/new-order')?>">Place one →</a></p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead>
        <tr><th>Order</th><th>Service</th><th>Link</th><th>Qty</th><th>Charge</th><th>Status</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td class="mono text-xs"><?=htmlspecialchars(substr($o->public_id,0,12))?>…</td>
          <td><?=htmlspecialchars($o->service_name ?? 'Service #'.$o->service_id)?></td>
          <td class="text-xs muted truncate max-w-[180px]"><a href="<?=htmlspecialchars($o->link)?>" target="_blank" rel="noopener nofollow"><?=htmlspecialchars($o->link)?></a></td>
          <td><?=number_format($o->quantity)?></td>
          <td><?=windels_money($o->charge, $o->currency)?></td>
          <td><span class="<?=DashboardStats::status_badge($o->status)?>"><?=htmlspecialchars(ucwords(strtolower(str_replace('_',' ',$o->status))))?></span></td>
          <td class="text-xs muted"><?=date('M j, Y', strtotime($o->created_at))?></td>
          <td><a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/orders/'.$o->public_id)?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>"
       href="<?=site_url('dashboard/orders?'.http_build_query(array_filter(array('status'=>$status,'page'=>max(1,$page-1)))))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>"
       href="<?=site_url('dashboard/orders?'.http_build_query(array_filter(array('status'=>$status,'page'=>min($total_pages,$page+1)))))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
