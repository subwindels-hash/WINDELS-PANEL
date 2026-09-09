<?php defined('BASEPATH') OR exit('No direct script access allowed');
$statuses = array(''=>'All','SUCCESSFUL'=>'Code received','PROCESSING'=>'Waiting',
                  'FAILED'=>'Expired / refunded','CANCELLED'=>'Cancelled','REFUNDED'=>'Refunded');
$badge = array('SUCCESSFUL'=>'badge-success','PROCESSING'=>'badge-warning',
               'PENDING'=>'badge-warning','FAILED'=>'badge-error',
               'REFUNDED'=>'badge-muted','CANCELLED'=>'badge-muted');
$total_pages = max(1, (int)ceil($total / $per_page));
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <div>
      <h2 class="card-title mb-0">Numbers you have rented</h2>
      <p class="muted text-sm mt-1"><?=number_format($total)?> total ·
        page <?=$page?> of <?=$total_pages?></p>
    </div>
    <form method="get" class="row" style="gap:.5rem">
      <select name="status" class="select" style="width:auto" data-autosubmit >
        <?php foreach ($statuses as $k=>$v): ?>
          <option value="<?=htmlspecialchars($k)?>"
            <?=(($filters['status'] ?? '')===$k)?'selected':''?>><?=htmlspecialchars($v)?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-secondary btn-sm" type="submit">Filter</button></noscript>
    </form>
  </div>

  <?php if (empty($transactions)): ?>
    <p class="muted mt-6">You have not rented a number yet.
      <a href="<?=site_url('dashboard/numbers')?>">Rent one →</a></p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead>
        <tr><th>Number</th><th>Code</th><th>Paid</th><th>Status</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t): $n = $numbers[$t->id] ?? null; ?>
        <tr>
          <td class="mono text-sm"><?=$n ? htmlspecialchars($n->msisdn) : '—'?></td>
          <td class="mono"><?=($n && $n->last_code) ? htmlspecialchars($n->last_code) : '—'?></td>
          <td><?=marvy_money($t->amount)?>
            <?php if (bccomp((string)$t->refunded_amount, '0', 8) > 0): ?>
              <div class="text-xs muted">refunded</div>
            <?php endif; ?>
          </td>
          <td><span class="badge <?=$badge[$t->status] ?? 'badge-muted'?>">
            <?=htmlspecialchars($t->status)?></span></td>
          <td class="text-sm muted"><?=htmlspecialchars($t->created_at)?></td>
          <td><a class="btn btn-ghost btn-sm"
                 href="<?=site_url('dashboard/numbers/'.$t->public_id)?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
