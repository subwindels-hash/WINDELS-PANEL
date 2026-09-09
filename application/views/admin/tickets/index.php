<?php defined('BASEPATH') OR exit('No direct script access allowed');
$sbadge = function ($s) {
    $map = array('OPEN'=>'badge-warning','PENDING'=>'badge-default','ANSWERED'=>'badge-info','CLOSED'=>'badge-success');
    return 'badge '.($map[$s] ?? 'badge-default');
};
$pbadge = function ($p) {
    $map = array('URGENT'=>'badge-danger','HIGH'=>'badge-warning','MEDIUM'=>'badge-default','LOW'=>'badge-default');
    return 'badge '.($map[$p] ?? 'badge-default');
};
$statuses = array('OPEN','PENDING','ANSWERED','CLOSED');
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Support queue</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> ticket<?=$total == 1 ? '' : 's'?> in this view</p>
  </div>
  <form method="get" action="<?=site_url('admin/tickets')?>" class="row" style="gap:.35rem">
    <input class="input" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>"
           placeholder="Subject, ticket ID or email" aria-label="Search tickets" style="min-width:16rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) && !$mine && empty($filters['unassigned']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/tickets')?>">All <span class="muted"><?=number_format(array_sum($counts))?></span></a>
  <?php foreach ($statuses as $s): ?>
    <a class="btn btn-sm <?=$filters['status'] === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/tickets?status='.$s)?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)($counts[$s] ?? 0))?></span>
    </a>
  <?php endforeach; ?>
  <a class="btn btn-sm <?=$mine ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('admin/tickets?mine=1')?>">Assigned to me</a>
  <a class="btn btn-sm <?=!empty($filters['unassigned']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/tickets?unassigned=1')?>">Unassigned</a>
</div>

<div class="card">
  <?php if (empty($tickets)): ?>
    <p class="muted">No tickets match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Subject</th><th>Customer</th><th>Priority</th><th>Status</th>
            <th>Assignee</th><th>Last activity</th></tr>
      </thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr>
          <td>
            <a href="<?=site_url('admin/tickets/'.$t->public_id)?>" class="font-medium"><?=htmlspecialchars($t->subject)?></a>
            <div class="text-xs muted mono"><?=htmlspecialchars($t->public_id)?>
              <?php if ($t->department): ?>· <?=htmlspecialchars($t->department)?><?php endif; ?>
            </div>
          </td>
          <td>
            <div class="text-sm"><?=htmlspecialchars((string)$t->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$t->email)?></div>
          </td>
          <td><span class="<?=$pbadge($t->priority)?>"><?=htmlspecialchars($t->priority)?></span></td>
          <td><span class="<?=$sbadge($t->status)?>"><?=htmlspecialchars($t->status)?></span></td>
          <td class="text-xs"><?=$t->assignee_username
              ? htmlspecialchars($t->assignee_username)
              : '<span class="muted">unassigned</span>'?></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($t->last_reply_at ?: $t->created_at))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1):
      $q = function ($p) use ($filters, $mine) {
          return '?'.http_build_query(array_filter(array(
              'status'=>$filters['status'], 'priority'=>$filters['priority'],
              'q'=>$filters['search'], 'unassigned'=>$filters['unassigned'],
              'mine'=>$mine ? 1 : null, 'page'=>$p))); }; ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/tickets'.$q(max(1, $page-1)))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/tickets'.$q(min($total_pages, $page+1)))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
