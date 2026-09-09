<?php defined('BASEPATH') OR exit('No direct script access allowed');
$badge = function ($s) {
    $map = array('SUCCESS'=>'badge-success','PENDING'=>'badge-warning','CREATED'=>'badge-default','FAILED'=>'badge-danger');
    return 'badge '.($map[$s] ?? 'badge-default');
};
$tabs = array('PENDING'=>'Awaiting review', 'SUCCESS'=>'Credited', 'FAILED'=>'Rejected', 'ALL'=>'All');
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Payments</h2>
    <p class="muted text-sm">Manual deposits are credited only when a staff member approves them.</p>
  </div>
  <form method="get" action="<?=site_url('admin/payments')?>" class="row" style="gap:.35rem">
    <input type="hidden" name="status" value="<?=htmlspecialchars((string)($status ?: 'ALL'))?>">
    <input class="input" name="q" value="<?=htmlspecialchars((string)$search)?>"
           placeholder="Deposit ID, reference or customer" aria-label="Search deposits" style="min-width:16rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="grid grid-3 mb-4" style="gap:1rem">
  <div class="card">
    <div class="muted text-sm">Awaiting review</div>
    <div class="text-2xl font-bold"><?=number_format((int)$totals['pending_count'])?></div>
    <div class="hint"><?=marvy_money($totals['pending_amount'])?> held</div>
  </div>
  <div class="card">
    <div class="muted text-sm">Total credited</div>
    <div class="text-2xl font-bold"><?=marvy_money($totals['credited'])?></div>
  </div>
  <div class="card">
    <div class="muted text-sm">Deposits recorded</div>
    <div class="text-2xl font-bold"><?=number_format((int)$totals['total'])?></div>
  </div>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <?php foreach ($tabs as $key => $label): $active = ($key === 'ALL' && $status === null) || $status === $key; ?>
    <a class="btn btn-sm <?=$active ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/payments?status='.$key)?>"><?=htmlspecialchars($label)?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($transactions)): ?>
    <p class="muted">Nothing in this queue.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Deposit</th><th>Customer</th><th>Method</th>
            <th class="text-right">Paid</th><th class="text-right">Credits</th><th>Status</th><th>Created</th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t): ?>
        <tr>
          <td><a class="mono text-xs" href="<?=site_url('admin/payments/'.$t->public_id)?>"><?=htmlspecialchars($t->public_id)?></a></td>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$t->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$t->email)?></div>
          </td>
          <td><?=htmlspecialchars((string)$t->method_name)?>
            <span class="badge badge-default"><?=htmlspecialchars((string)$t->method_type)?></span></td>
          <td class="text-right mono"><?=marvy_money($t->amount)?></td>
          <td class="text-right mono"><?=marvy_money($t->credited_amount ?? $t->amount)?></td>
          <td><span class="<?=$badge($t->status)?>"><?=htmlspecialchars($t->status)?></span></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)$t->created_at)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): $q = function ($p) use ($status, $search) {
      return '?'.http_build_query(array_filter(array('status'=>$status ?: 'ALL','q'=>$search,'page'=>$p))); }; ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/payments'.$q(max(1, $page-1)))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/payments'.$q(min($total_pages, $page+1)))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
