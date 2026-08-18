<?php defined('BASEPATH') OR exit('No direct script access allowed');
$badge = function ($status) {
    $map = array('PENDING'=>'badge-warning','APPROVED'=>'badge-default','PAID'=>'badge-success',
        'REJECTED'=>'badge-danger','CANCELLED'=>'badge-default');
    return 'badge '.($map[$status] ?? 'badge-default');
};
$tabs = array(''=>'All','PENDING'=>'Pending','APPROVED'=>'Approved','PAID'=>'Paid','REJECTED'=>'Rejected','CANCELLED'=>'Cancelled');
$total_pages = max(1, (int)ceil($total / $per_page));
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div><h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Withdrawal operations</h2>
    <p class="muted text-sm">Review reserved customer funds and record external payouts. Destinations remain masked in this queue.</p></div>
  <form method="get" action="<?=site_url('admin/withdrawals')?>" class="row" style="gap:.35rem">
    <?php if ($filters['status']): ?><input type="hidden" name="status" value="<?=htmlspecialchars($filters['status'])?>"><?php endif; ?>
    <input class="input" name="q" value="<?=htmlspecialchars($filters['search'])?>" placeholder="Request, reference or customer" aria-label="Search withdrawals" style="min-width:16rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>
<div class="grid grid-3 mb-4" style="gap:1rem">
  <div class="card"><div class="muted text-sm">Awaiting settlement</div><div class="text-2xl font-bold"><?=number_format($totals['open_count'])?></div><div class="hint"><?=windels_money($totals['open_amount'])?> payout value</div></div>
  <div class="card"><div class="muted text-sm">Paid value</div><div class="text-2xl font-bold"><?=windels_money($totals['paid_amount'])?></div></div>
  <div class="card"><div class="muted text-sm">Requests recorded</div><div class="text-2xl font-bold"><?=number_format($totals['total'])?></div></div>
</div>
<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap"><?php foreach ($tabs as $key=>$label): ?>
  <a class="btn btn-sm <?=$filters['status']===$key?'btn-primary':'btn-ghost'?>" href="<?=site_url('admin/withdrawals'.($key?'?status='.$key:''))?>"><?=htmlspecialchars($label)?></a>
<?php endforeach; ?></div>
<div class="card">
<?php if (empty($rows)): ?><p class="muted">Nothing in this queue.</p>
<?php else: ?><div class="overflow-x-auto"><table class="table"><thead><tr><th>Request</th><th>Customer</th><th>Destination</th><th class="text-right">Gross</th><th class="text-right">Payout</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
  <td><a class="mono text-xs" href="<?=site_url('admin/withdrawals/'.$row->public_id)?>"><?=htmlspecialchars($row->public_id)?></a></td>
  <td><div class="font-medium"><?=htmlspecialchars((string)$row->username)?></div><div class="text-xs muted"><?=htmlspecialchars((string)$row->email)?></div></td>
  <td><?=htmlspecialchars($row->destination_label)?></td>
  <td class="text-right mono"><?=windels_money($row->amount, $row->currency)?></td><td class="text-right mono"><?=windels_money($row->payout_amount, $row->currency)?></td>
  <td><span class="<?=$badge($row->status)?>"><?=htmlspecialchars($row->status)?></span></td><td class="text-xs muted"><?=htmlspecialchars($row->created_at)?></td>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($total_pages>1): $query=function($p)use($filters){return '?'.http_build_query(array_filter(array('status'=>$filters['status'],'q'=>$filters['search'],'page'=>$p)));}; ?>
<nav class="row justify-between mt-4"><a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="<?=site_url('admin/withdrawals'.$query(max(1,$page-1)))?>">← Previous</a><span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span><a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>" href="<?=site_url('admin/withdrawals'.$query(min($total_pages,$page+1)))?>">Next →</a></nav><?php endif; ?>
</div>
