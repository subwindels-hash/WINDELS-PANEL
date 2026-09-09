<?php defined('BASEPATH') OR exit('No direct script access allowed');
$statuses = array('PENDING','PROCESSING','SUCCESSFUL','FAILED','CANCELLED','REFUNDED');
$order_badge = array('DELIVERED'=>'badge-success','PLACED'=>'badge-warning',
                     'PENDING'=>'badge-muted','FAILED'=>'badge-error',
                     'CANCELLED'=>'badge-muted');
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('status'=>$filters['status'], 'q'=>$filters['search'], 'page'=>$page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== ''; });
    return $merged ? '?'.http_build_query($merged) : '';
};
$undelivered = (int)($order_counts['PLACED'] ?? 0);
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Gift cards</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> order<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <form method="get" action="<?=site_url('admin/giftcards')?>" class="row" style="gap:.35rem">
    <?php if (!empty($filters['status'])): ?><input type="hidden" name="status" value="<?=htmlspecialchars($filters['status'])?>"><?php endif; ?>
    <input class="input" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>"
           placeholder="Transaction or vendor reference" aria-label="Search gift card orders" style="min-width:15rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/giftcards'.$qs(array('status'=>null,'page'=>null)))?>">
    All <span class="muted"><?=number_format(array_sum($counts))?></span>
  </a>
  <?php foreach ($statuses as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=$filters['status'] === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/giftcards'.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($undelivered > 0): ?>
<div class="alert alert-warning mb-4">
  <?=number_format($undelivered)?> order<?=$undelivered == 1 ? ' is' : 's are'?>
  placed with the vendor but undelivered. Each one is a customer who has paid
  and has nothing to spend. The sweep chases them every two minutes and writes
  off — and refunds — the ones that never arrive; the vendor bills us for those
  either way, so a number that stays high is worth chasing with the vendor.
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($transactions)): ?>
    <p class="muted">No gift card orders match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Transaction</th><th>Customer</th><th>Card</th><th>Qty</th>
            <th>Delivery</th><th class="text-right">Amount</th><th>Status</th><th>Bought</th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t): ?>
        <tr>
          <td>
            <a class="mono text-xs" href="<?=site_url('admin/giftcards/'.$t->public_id)?>"><?=htmlspecialchars($t->public_id)?></a>
            <?php if ($t->source !== 'WEB'): ?><span class="badge badge-default"><?=htmlspecialchars((string)$t->source)?></span><?php endif; ?>
          </td>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$t->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$t->email)?></div>
          </td>
          <td class="text-xs">
            <?=htmlspecialchars((string)($t->brand_name ?? $t->service_type))?>
            <?php if (!empty($t->face_value)): ?>
              <div class="muted"><?=htmlspecialchars((string)$t->recipient_currency)?>
                <?=htmlspecialchars(rtrim(rtrim((string)$t->face_value, '0'), '.'))?></div>
            <?php endif; ?>
          </td>
          <td><?=(int)($t->quantity ?? 1)?></td>
          <td>
            <?php if (!empty($t->order_status)): ?>
              <span class="badge <?=$order_badge[$t->order_status] ?? 'badge-muted'?>">
                <?=htmlspecialchars((string)$t->order_status)?></span>
              <?php if ($t->order_status === 'PLACED' && (int)$t->code_attempts > 0): ?>
                <div class="text-xs muted"><?=(int)$t->code_attempts?> attempt(s)</div>
              <?php endif; ?>
              <?php if (!empty($t->reveal_count)): ?>
                <div class="text-xs muted">opened <?=(int)$t->reveal_count?>×</div>
              <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-right mono"><?=marvy_money($t->amount, $t->currency)?></td>
          <td><span class="<?=DashboardStats::status_badge($t->status)?>"><?=htmlspecialchars($t->status)?></span></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)$t->created_at)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="row justify-between mt-4">
  <?php if ($page > 1): ?>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/giftcards'.$qs(array('page'=>$page-1)))?>">← Previous</a>
  <?php else: ?><span></span><?php endif; ?>
  <span class="muted text-sm">Page <?=$page?> of <?=$total_pages?></span>
  <?php if ($page < $total_pages): ?>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/giftcards'.$qs(array('page'=>$page+1)))?>">Next →</a>
  <?php else: ?><span></span><?php endif; ?>
</div>
<?php endif; ?>
