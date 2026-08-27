<?php defined('BASEPATH') OR exit('No direct script access allowed');
$statuses = array('PENDING','PROCESSING','SUCCESSFUL','FAILED','CANCELLED','REFUNDED');
$check_badge = array('VERIFIED'=>'badge-success','NOT_FOUND'=>'badge-warning',
                     'FAILED'=>'badge-error','PENDING'=>'badge-muted');
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('status'=>$filters['status'], 'q'=>$filters['search'], 'page'=>$page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== ''; });
    return $merged ? '?'.http_build_query($merged) : '';
};
$not_found = (int)($check_counts['NOT_FOUND'] ?? 0);
$verified  = (int)($check_counts['VERIFIED'] ?? 0);
$completed = $not_found + $verified;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Identity checks</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> check<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <form method="get" action="<?=site_url('admin/identity')?>" class="row" style="gap:.35rem">
    <?php if (!empty($filters['status'])): ?><input type="hidden" name="status" value="<?=htmlspecialchars($filters['status'])?>"><?php endif; ?>
    <input class="input" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>"
           placeholder="Transaction or vendor reference" aria-label="Search checks" style="min-width:15rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/identity'.$qs(array('status'=>null,'page'=>null)))?>">
    All <span class="muted"><?=number_format(array_sum($counts))?></span>
  </a>
  <?php foreach ($statuses as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=$filters['status'] === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/identity'.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($completed >= 20 && $not_found * 4 >= $completed): ?>
<div class="alert alert-warning mb-4">
  <?=number_format($not_found)?> of the last <?=number_format($completed)?> completed
  lookups found no record — that is over a quarter. Each one is refunded to the
  customer but still billed to us by the vendor. A rate this high usually means
  a broken form, a customer bulk-testing numbers, or the wrong ID type being
  sold; it is worth looking at before the vendor bill arrives.
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($transactions)): ?>
    <p class="muted">No checks match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Transaction</th><th>Customer</th><th>Type</th><th>Number</th>
            <th>Result</th><th class="text-right">Amount</th><th>Status</th><th>Run</th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t): ?>
        <tr>
          <td>
            <a class="mono text-xs" href="<?=site_url('admin/identity/'.$t->public_id)?>"><?=htmlspecialchars($t->public_id)?></a>
            <?php if ($t->source !== 'WEB'): ?><span class="badge badge-default"><?=htmlspecialchars((string)$t->source)?></span><?php endif; ?>
          </td>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$t->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$t->email)?></div>
          </td>
          <td class="text-xs"><?=htmlspecialchars((string)($t->id_type ?? $t->service_type))?></td>
          <td class="mono text-xs">
            <?php /* Masked in the list, always. The full number is not stored
                     anywhere, so there is nothing here to accidentally widen. */ ?>
            <?=!empty($t->identifier_last4) ? '•••••••'.htmlspecialchars($t->identifier_last4) : '—'?>
          </td>
          <td>
            <?php if (!empty($t->check_status)): ?>
              <span class="badge <?=$check_badge[$t->check_status] ?? 'badge-muted'?>">
                <?=htmlspecialchars((string)$t->check_status)?></span>
              <?php if (!empty($t->reveal_count)): ?>
                <div class="text-xs muted">opened <?=(int)$t->reveal_count?>×</div>
              <?php endif; ?>
              <?php if (!empty($t->purged_at)): ?>
                <div class="text-xs muted">purged</div>
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
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/identity'.$qs(array('page'=>$page-1)))?>">← Previous</a>
  <?php else: ?><span></span><?php endif; ?>
  <span class="muted text-sm">Page <?=$page?> of <?=$total_pages?></span>
  <?php if ($page < $total_pages): ?>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/identity'.$qs(array('page'=>$page+1)))?>">Next →</a>
  <?php else: ?><span></span><?php endif; ?>
</div>
<?php endif; ?>
