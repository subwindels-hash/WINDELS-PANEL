<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$statuses = array('PENDING','PROCESSING','SUCCESSFUL','FAILED','CANCELLED','REFUNDED');
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('status'=>$filters['status'], 'q'=>$filters['search'], 'page'=>$page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== ''; });
    return $merged ? '?'.http_build_query($merged) : '';
};
$now = time();
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Virtual numbers</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> reservation<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <form method="get" action="<?=site_url('admin/numbers')?>" class="row" style="gap:.35rem">
    <?php if (!empty($filters['status'])): ?><input type="hidden" name="status" value="<?=htmlspecialchars($filters['status'])?>"><?php endif; ?>
    <input class="input" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>"
           placeholder="Transaction or vendor reference" aria-label="Search reservations" style="min-width:15rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/numbers'.$qs(array('status'=>null,'page'=>null)))?>">
    All <span class="muted"><?=number_format(array_sum($counts))?></span>
  </a>
  <?php foreach ($statuses as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=$filters['status'] === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/numbers'.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!empty($counts['PROCESSING'])): ?>
<div class="alert alert-info mb-4">
  <?=number_format((int)$counts['PROCESSING'])?> reservation<?=$counts['PROCESSING'] == 1 ? ' is' : 's are'?>
  still waiting for a code. The numbers worker polls them every minute and
  refunds any that expire; open one to poll it now.
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($transactions)): ?>
    <p class="muted">No reservations match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Transaction</th><th>Customer</th><th>Number</th><th>Reservation</th>
            <th>Expires</th><th class="text-right">Amount</th><th>Status</th><th>Rented</th></tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $t):
        $expires = !empty($t->expires_at) ? strtotime($t->expires_at.' UTC') : null;
        $overdue = $expires !== null && $expires <= $now
                   && in_array($t->reservation_status, array('RESERVED'), true);
      ?>
        <tr>
          <td>
            <a class="mono text-xs" href="<?=site_url('admin/numbers/'.$t->public_id)?>"><?=htmlspecialchars($t->public_id)?></a>
            <?php if ($t->source !== 'WEB'): ?><span class="badge badge-default"><?=htmlspecialchars((string)$t->source)?></span><?php endif; ?>
          </td>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$t->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$t->email)?></div>
          </td>
          <td class="mono text-xs">
            <?=htmlspecialchars((string)$t->msisdn)?>
            <?php if (!empty($t->last_code)): ?>
              <div class="muted">code <?=htmlspecialchars((string)$t->last_code)?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?=$t->reservation_status === 'RECEIVED' ? 'badge-success' : 'badge-default'?>">
              <?=htmlspecialchars((string)$t->reservation_status)?></span>
            <div class="text-xs muted"><?=number_format((int)$t->sms_count)?> sms</div>
          </td>
          <td class="text-xs <?=$overdue ? 'text-danger' : 'muted'?>">
            <?=htmlspecialchars((string)$t->expires_at)?>
            <?php if ($overdue): ?><div>overdue</div><?php endif; ?>
          </td>
          <td class="text-right mono">
            <?=windels_money($t->amount, $t->currency)?>
            <?php if (bccomp((string)$t->refunded_amount, '0', 8) > 0): ?>
              <div class="text-xs muted">−<?=windels_money($t->refunded_amount, $t->currency)?> refunded</div>
            <?php endif; ?>
          </td>
          <td><span class="<?=DashboardStats::status_badge($t->status)?>"><?=htmlspecialchars($t->status)?></span></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)$t->created_at)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/numbers'.$qs(array('page'=>max(1, $page-1))))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/numbers'.$qs(array('page'=>min($total_pages, $page+1))))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
