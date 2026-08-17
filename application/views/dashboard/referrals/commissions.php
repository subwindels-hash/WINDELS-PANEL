<?php defined('BASEPATH') OR exit('No direct script access allowed');
$filters = array(''=>'All','PENDING'=>'Pending','PAID'=>'Paid','REVERSED'=>'Reversed');
?>
<div class="card">
  <div class="row justify-between" style="flex-wrap:wrap;gap:.75rem">
    <div>
      <h2 class="card-title mb-0">Commission history</h2>
      <p class="muted text-sm mt-1"><?=number_format($total)?> total · page <?=$page?> of <?=$total_pages?></p>
    </div>
    <div class="row" style="gap:.5rem">
      <form method="get" class="row" style="gap:.5rem">
        <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
          <?php foreach ($filters as $k => $v): ?>
            <option value="<?=htmlspecialchars($k)?>" <?=((string)$status === $k) ? 'selected' : ''?>><?=htmlspecialchars($v)?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/referrals')?>">← Referrals</a>
    </div>
  </div>

  <?php if (empty($commissions)): ?>
    <p class="muted mt-6">No commissions<?=$status ? ' with status "'.htmlspecialchars($status).'"' : ''?> yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto mt-4">
    <table class="table">
      <thead>
        <tr><th>Date</th><th>Referred user</th><th>Order</th><th>Status</th><th>Paid</th><th class="text-right">Amount</th></tr>
      </thead>
      <tbody>
      <?php foreach ($commissions as $c): ?>
        <tr>
          <td class="text-xs muted whitespace-nowrap"><?=date('M j, Y H:i', strtotime($c->created_at))?> UTC</td>
          <td><?=htmlspecialchars($c->referred_username ?? '—')?></td>
          <td class="mono text-xs"><?=$c->order_public_id ? htmlspecialchars(substr($c->order_public_id, 0, 12)).'…' : '—'?></td>
          <td><span class="badge <?=$c->status === 'PAID' ? 'badge-success' : ($c->status === 'PENDING' ? 'badge-warning' : 'badge-default')?>"><?=htmlspecialchars($c->status)?></span></td>
          <td class="text-xs muted"><?=$c->paid_at ? date('M j, Y', strtotime($c->paid_at)) : '—'?></td>
          <td class="text-right mono font-semibold"><?=windels_money($c->amount, $c->currency)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
       href="<?=site_url('dashboard/referrals/commissions?'.http_build_query(array_filter(array('status'=>$status,'page'=>max(1,$page-1)))))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
       href="<?=site_url('dashboard/referrals/commissions?'.http_build_query(array_filter(array('status'=>$status,'page'=>min($total_pages,$page+1)))))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
