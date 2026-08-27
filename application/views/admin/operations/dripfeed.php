<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('status' => $filters['status'] ?? null, 'q' => $filters['search'] ?? null, 'page' => $page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};
$base_url = 'admin/drip-feed';
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Drip-feeds</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> schedule<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <form method="get" action="<?=site_url($base_url)?>" class="row" style="gap:.35rem">
    <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
           placeholder="Schedule reference or link" aria-label="Search schedules" style="min-width:15rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<?php $this->load->view('admin/operations/_tabs', array('tabs'=>$tabs,'queue'=>$queue)); ?>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url($base_url.$qs(array('status'=>null,'page'=>null)))?>">
    All <span class="muted"><?=number_format(array_sum($counts))?></span>
  </a>
  <?php foreach ($statuses as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=($filters['status'] ?? '') === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url($base_url.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="muted">No schedules match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Schedule</th><th>Customer</th><th>Service</th><th>Progress</th>
            <th class="text-right">Reserved</th><th>Next run</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $done  = (int)$r->runs_completed;
          $total_runs = (int)$r->runs;
          $pct = $total_runs > 0 ? min(100, (int)round($done / $total_runs * 100)) : 0;
        ?>
        <tr>
          <td>
            <div class="mono text-xs"><?=htmlspecialchars((string)$r->public_id)?></div>
            <div class="text-xs muted"><?=htmlspecialchars(mb_strimwidth((string)$r->link, 0, 40, '…'))?></div>
          </td>
          <td>
            <?php if (!empty($r->user_public_id)): ?>
              <a class="text-xs" href="<?=site_url('admin/customers/'.$r->user_public_id)?>">
                <?=htmlspecialchars((string)$r->username)?>
              </a>
            <?php else: ?><span class="muted text-xs">—</span><?php endif; ?>
          </td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($r->service_name ?? '—'))?></td>
          <td class="text-xs">
            <?=number_format($done)?><?=$total_runs > 0 ? ' / '.number_format($total_runs) : ''?>
            <?php if ($total_runs > 0): ?><span class="muted"> (<?=$pct?>%)</span><?php endif; ?>
          </td>
          <td class="text-right mono"><?=marvy_money($r->charge, $r->currency ?? null)?></td>
          <td class="text-xs muted whitespace-nowrap">
            <?=$r->next_run_at ? htmlspecialchars(date('M j, H:i', strtotime($r->next_run_at))) : '—'?>
          </td>
          <td><span class="<?=DashboardStats::status_badge($r->status)?>"><?=htmlspecialchars((string)$r->status)?></span></td>
          <td>
            <?php if ($has('orders.edit')): ?>
            <div class="row" style="gap:.25rem;justify-content:flex-end">
              <?php foreach (array('pause'=>'ACTIVE','resume'=>'PAUSED') as $act => $from): ?>
                <?php if ($r->status === $from): ?>
                  <form method="post" action="<?=site_url($base_url.'/'.$r->public_id.'/'.$act)?>" style="display:inline">
                    <?=$csrf()?>
                    <button class="btn btn-ghost btn-sm" type="submit"><?=ucfirst($act)?></button>
                  </form>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if (!in_array($r->status, array('CANCELED','COMPLETED','EXPIRED'), true)): ?>
                <form method="post" action="<?=site_url($base_url.'/'.$r->public_id.'/cancel')?>" style="display:inline">
                  <?=$csrf()?>
                  <button class="btn btn-ghost btn-sm" type="submit">Cancel</button>
                </form>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="row justify-between mt-4" style="align-items:center">
  <span class="muted text-sm">Page <?=number_format($page)?> of <?=number_format($total_pages)?></span>
  <div class="row" style="gap:.35rem">
    <?php if ($page > 1): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url($base_url.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url($base_url.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
