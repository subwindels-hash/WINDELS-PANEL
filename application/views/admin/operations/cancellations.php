<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('status' => $filters['status'] ?? null, 'q' => $filters['search'] ?? null, 'page' => $page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};
// Only these can still be cancelled; anything else is already terminal.
$cancellable = array('PENDING','PROCESSING','IN_PROGRESS');
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Cancellations</h2>
    <p class="muted text-sm">Orders that can still be stopped, and the refund that follows</p>
  </div>
  <form method="get" action="<?=site_url('admin/cancellations')?>" class="row" style="gap:.35rem">
    <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
           placeholder="Order reference or link" aria-label="Search orders" style="min-width:15rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<?php $this->load->view('admin/operations/_tabs', array('tabs'=>$tabs,'queue'=>$queue)); ?>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/cancellations'.$qs(array('status'=>null,'page'=>null)))?>">All</a>
  <?php foreach ($cancellable as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=($filters['status'] ?? '') === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/cancellations'.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="alert alert-info mb-4">
  Cancelling refunds the charge to the customer's wallet through the ledger, and asks the provider
  to stop if the order was already submitted. Orders that have completed are refunded from
  <a href="<?=site_url('admin/orders')?>">Orders</a> instead.
</div>

<div class="card mb-4">
  <?php if (empty($rows)): ?>
    <p class="muted">No orders match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Order</th><th>Customer</th><th>Service</th>
            <th class="text-right">Charge</th><th>Status</th><th>Placed</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $o): ?>
        <tr>
          <td>
            <a class="mono text-xs" href="<?=site_url('admin/orders/'.$o->public_id)?>">
              <?=htmlspecialchars((string)$o->public_id)?>
            </a>
          </td>
          <td class="text-xs"><?=htmlspecialchars((string)($o->username ?? '—'))?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($o->service_name ?? '—'))?></td>
          <td class="text-right mono"><?=marvy_money($o->charge, $o->currency ?? null)?></td>
          <td><span class="<?=DashboardStats::status_badge($o->status)?>"><?=htmlspecialchars($o->status)?></span></td>
          <td class="text-xs muted whitespace-nowrap">
            <?=htmlspecialchars(date('M j, H:i', strtotime($o->created_at)))?>
          </td>
          <td>
            <?php if ($has('orders.cancel') && in_array($o->status, $cancellable, true)): ?>
              <form method="post" action="<?=site_url('admin/cancellations/'.$o->public_id.'/cancel')?>"
                    style="display:inline">
                <?=$csrf()?>
                <input type="hidden" name="reason" value="Canceled by staff">
                <button class="btn btn-ghost btn-sm" type="submit">Cancel &amp; refund</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($requests)): ?>
<div class="card">
  <h3 style="font-size:1rem;font-weight:600" class="mb-3">Provider cancellation requests</h3>
  <table class="table">
    <thead><tr><th>Request</th><th>Order</th><th>Status</th><th class="text-right">Refund</th><th>Raised</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): ?>
      <tr>
        <td class="mono text-xs"><?=htmlspecialchars((string)$r->public_id)?></td>
        <td class="mono text-xs"><?=htmlspecialchars((string)$r->order_id)?></td>
        <td><span class="<?=DashboardStats::status_badge($r->status)?>"><?=htmlspecialchars((string)$r->status)?></span></td>
        <td class="text-right mono"><?=$r->refund_amount !== null ? marvy_money($r->refund_amount) : '—'?></td>
        <td class="text-xs muted"><?=htmlspecialchars(date('M j, H:i', strtotime($r->created_at)))?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($total_pages > 1): ?>
<div class="row justify-between mt-4" style="align-items:center">
  <span class="muted text-sm">Page <?=number_format($page)?> of <?=number_format($total_pages)?></span>
  <div class="row" style="gap:.35rem">
    <?php if ($page > 1): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/cancellations'.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/cancellations'.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
