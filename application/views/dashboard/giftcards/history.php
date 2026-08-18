<?php defined('BASEPATH') OR exit('No direct script access allowed');
$statuses = array(''=>'All','SUCCESSFUL'=>'Delivered','PROCESSING'=>'Being issued',
                  'FAILED'=>'Failed','REFUNDED'=>'Refunded');
$order_badge = array('DELIVERED'=>'badge-success','PLACED'=>'badge-warning',
                     'PENDING'=>'badge-muted','FAILED'=>'badge-error',
                     'CANCELLED'=>'badge-muted');
$total_pages = max(1, (int)ceil($total / $per_page));
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <div>
      <h2 class="card-title mb-0">Gift cards you have bought</h2>
      <p class="muted text-sm mt-1"><?=number_format($total)?> total ·
        page <?=$page?> of <?=$total_pages?></p>
    </div>
    <form method="get" class="row" style="gap:.5rem">
      <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($statuses as $k=>$v): ?>
          <option value="<?=htmlspecialchars($k)?>"
            <?=(($filters['status'] ?? '')===$k)?'selected':''?>><?=htmlspecialchars($v)?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-secondary btn-sm" type="submit">Filter</button></noscript>
    </form>
  </div>

  <?php if (empty($transactions)): ?>
    <p class="muted mt-6">You have not bought a gift card yet.
      <a href="<?=site_url('dashboard/giftcards')?>">Buy one →</a></p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead>
        <tr><th>Card</th><th>Qty</th><th>Delivery</th><th>Paid</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t): $o = $orders[$t->id] ?? null; ?>
        <tr>
          <td>
            <?php if ($o && $o->face_value !== null): ?>
              <?=htmlspecialchars($o->recipient_currency)?>
              <?=htmlspecialchars(rtrim(rtrim((string)$o->face_value, '0'), '.'))?>
            <?php else: ?>
              <?=htmlspecialchars((string)$t->service_type)?>
            <?php endif; ?>
          </td>
          <td><?=$o ? (int)$o->quantity : 1?></td>
          <td>
            <?php if ($o): ?>
              <span class="badge <?=$order_badge[$o->status] ?? 'badge-muted'?>">
                <?=htmlspecialchars($o->status)?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?=windels_money($t->amount)?>
            <?php if (bccomp((string)$t->refunded_amount, '0', 8) > 0): ?>
              <div class="text-xs muted">refunded</div>
            <?php endif; ?>
          </td>
          <td class="text-sm muted"><?=htmlspecialchars($t->created_at)?></td>
          <td><a class="btn btn-ghost btn-sm"
                 href="<?=site_url('dashboard/giftcards/'.$t->public_id)?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
