<?php defined('BASEPATH') OR exit('No direct script access allowed');
$types = array(''=>'All types','AIRTIME'=>'Airtime','DATA'=>'Data','CABLE'=>'Cable TV',
                'ELECTRICITY'=>'Electricity','EXAM_PIN'=>'Exam PINs');
$statuses = array(''=>'All','SUCCESSFUL'=>'Successful','PROCESSING'=>'Processing',
                  'PENDING'=>'Pending','FAILED'=>'Failed','REFUNDED'=>'Refunded');
$badge = array('SUCCESSFUL'=>'badge-success','PROCESSING'=>'badge-warning',
               'PENDING'=>'badge-warning','FAILED'=>'badge-error',
               'REFUNDED'=>'badge-muted','CANCELLED'=>'badge-muted');
$total_pages = max(1, (int)ceil($total / $per_page));
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <div>
      <h2 class="card-title mb-0">VTU history</h2>
      <p class="muted text-sm mt-1"><?=number_format($total)?> total ·
        page <?=$page?> of <?=$total_pages?></p>
    </div>
    <form method="get" class="row" style="gap:.5rem">
      <select name="type" class="select" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($types as $k=>$v): ?>
          <option value="<?=htmlspecialchars($k)?>"
            <?=(($filters['type'] ?? '')===$k)?'selected':''?>><?=htmlspecialchars($v)?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($statuses as $k=>$v): ?>
          <option value="<?=htmlspecialchars($k)?>"
            <?=(($filters['status'] ?? '')===$k)?'selected':''?>><?=htmlspecialchars($v)?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (empty($transactions)): ?>
    <p class="muted mt-6">Nothing here yet.
      <a href="<?=site_url('dashboard/vtu')?>">Buy something →</a></p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead>
        <tr><th>Reference</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t): ?>
        <tr>
          <td class="mono text-xs"><?=htmlspecialchars(substr($t->public_id,0,12))?>…</td>
          <td><?=htmlspecialchars($t->service_type)?></td>
          <td><?=windels_money($t->amount)?></td>
          <td><span class="badge <?=$badge[$t->status] ?? 'badge-muted'?>">
            <?=htmlspecialchars($t->status)?></span></td>
          <td class="text-sm muted"><?=htmlspecialchars($t->created_at)?></td>
          <td><a class="btn btn-ghost btn-sm"
                 href="<?=site_url('dashboard/vtu/receipt/'.$t->public_id)?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
