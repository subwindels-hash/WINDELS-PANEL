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
$stuck = 0;
foreach ($rows as $r) {
    if (in_array($r->status, array('PENDING','PROCESSING','IN_PROGRESS'), true)
        && strtotime($r->requested_at) < strtotime('-24 hours')) $stuck++;
}
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Refills</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> refill<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <form method="get" action="<?=site_url('admin/refills')?>" class="row" style="gap:.35rem">
    <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
           placeholder="Refill or provider reference" aria-label="Search refills" style="min-width:15rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<?php $this->load->view('admin/operations/_tabs', array('tabs'=>$tabs,'queue'=>$queue)); ?>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/refills'.$qs(array('status'=>null,'page'=>null)))?>">
    All <span class="muted"><?=number_format(array_sum($counts))?></span>
  </a>
  <?php foreach ($statuses as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=($filters['status'] ?? '') === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/refills'.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($stuck): ?>
<div class="alert alert-warning mb-4">
  <?=number_format($stuck)?> refill<?=$stuck === 1 ? ' has' : 's have'?> been waiting more than 24 hours.
  The status worker re-checks them automatically; a provider that never answers needs chasing by hand.
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="muted">No refills match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Refill</th><th>Order</th><th>Customer</th><th>Service</th>
            <th>Status</th><th>Requested</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="mono text-xs"><?=htmlspecialchars((string)$r->public_id)?></td>
          <td>
            <?php if (!empty($r->order_public_id)): ?>
              <a class="mono text-xs" href="<?=site_url('admin/orders/'.$r->order_public_id)?>">
                <?=htmlspecialchars((string)$r->order_public_id)?>
              </a>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r->user_public_id)): ?>
              <a class="text-xs" href="<?=site_url('admin/customers/'.$r->user_public_id)?>">
                <?=htmlspecialchars((string)$r->username)?>
              </a>
            <?php else: ?><span class="muted text-xs">—</span><?php endif; ?>
          </td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($r->service_name ?? '—'))?></td>
          <td>
            <?php
              // metadata carries what the worker knows: how many times the
              // refill has been sent, and whether it is waiting on a human
              // rather than on the provider. Without this an operator cannot
              // tell "the provider is down" from "nobody can ever send this".
              $meta = array();
              if (!empty($r->metadata)) {
                  $decoded = is_array($r->metadata) ? $r->metadata : json_decode((string)$r->metadata, true);
                  if (is_array($decoded)) $meta = $decoded;
              }
              $attempts = (int)($meta['submit_attempts'] ?? 0);
            ?>
            <span class="<?=DashboardStats::status_badge($r->status)?>"><?=htmlspecialchars((string)$r->status)?></span>
            <?php if (!empty($meta['manual']) && $r->status === 'PENDING'): ?>
              <span class="badge badge-warning">Needs staff</span>
            <?php elseif ($attempts > 1 && $r->status === 'PENDING'): ?>
              <span class="text-xs muted">sent <?=number_format($attempts)?>×</span>
            <?php endif; ?>
            <?php if (!empty($r->error)): ?>
              <div class="text-xs muted" title="<?=htmlspecialchars((string)$r->error)?>">
                <?=htmlspecialchars(mb_strimwidth((string)$r->error, 0, 40, '…'))?>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-xs muted whitespace-nowrap">
            <?=htmlspecialchars(date('M j, H:i', strtotime($r->requested_at)))?>
          </td>
          <td>
            <?php if ($has('orders.refill') && !empty($r->order_public_id)
                      && in_array($r->order_status, array('COMPLETED','PARTIAL'), true)): ?>
              <form method="post" action="<?=site_url('admin/refills/'.$r->order_public_id.'/request')?>"
                    style="display:inline">
                <?=$csrf()?>
                <button class="btn btn-ghost btn-sm" type="submit">Retry</button>
              </form>
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
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/refills'.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/refills'.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
