<?php defined('BASEPATH') OR exit('No direct script access allowed');
$statuses = array(''=>'All','SUCCESSFUL'=>'Verified','FAILED'=>'Not found / failed',
                  'REFUNDED'=>'Refunded');
$badge = array('SUCCESSFUL'=>'badge-success','PROCESSING'=>'badge-warning',
               'PENDING'=>'badge-warning','FAILED'=>'badge-error',
               'REFUNDED'=>'badge-muted','CANCELLED'=>'badge-muted');
$check_badge = array('VERIFIED'=>'badge-success','NOT_FOUND'=>'badge-warning',
                     'FAILED'=>'badge-error','PENDING'=>'badge-muted');
$total_pages = max(1, (int)ceil($total / $per_page));
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <div>
      <h2 class="card-title mb-0">Identity checks you have run</h2>
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
    <p class="muted mt-6">You have not run an identity check yet.
      <a href="<?=site_url('dashboard/identity')?>">Run one →</a></p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead>
        <tr><th>Type</th><th>Number</th><th>Result</th><th>Paid</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t): $c = $checks[$t->id] ?? null; ?>
        <tr>
          <td><?=htmlspecialchars((string)$t->service_type)?></td>
          <td class="mono text-sm">
            <?=$c && $c->identifier_last4 ? '•••••••'.htmlspecialchars($c->identifier_last4) : '—'?>
          </td>
          <td>
            <?php if ($c): ?>
              <span class="badge <?=$check_badge[$c->status] ?? 'badge-muted'?>">
                <?=htmlspecialchars($c->status)?></span>
              <?php if (!empty($c->purged_at)): ?>
                <div class="text-xs muted">result deleted</div>
              <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?=windels_money($t->amount)?>
            <?php if (bccomp((string)$t->refunded_amount, '0', 8) > 0): ?>
              <div class="text-xs muted">refunded</div>
            <?php endif; ?>
          </td>
          <td class="text-sm muted"><?=htmlspecialchars($t->created_at)?></td>
          <td><a class="btn btn-ghost btn-sm"
                 href="<?=site_url('dashboard/identity/'.$t->public_id)?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
