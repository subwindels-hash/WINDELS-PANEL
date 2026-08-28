<?php defined('BASEPATH') OR exit('No direct script access allowed');
$labels = array('SMM'=>'SMM order','VTU'=>'VTU','NUMBER'=>'Virtual number',
                'IDENTITY'=>'Identity','GIFTCARD'=>'Gift card');
$statuses = array(''=>'Any status','SUCCESSFUL'=>'Successful','COMPLETED'=>'Completed',
                  'PROCESSING'=>'In progress','FAILED'=>'Failed','REFUNDED'=>'Refunded');
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('domain'=>$filters['domain'], 'status'=>$filters['status'], 'page'=>$page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== ''; });
    return $merged ? '?'.http_build_query($merged) : '';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Purchase history</h2>
    <p class="muted text-sm">
      Everything you have bought, newest first ·
      <?=number_format((int)$total)?> item<?=$total == 1 ? '' : 's'?>
    </p>
  </div>
  <form method="get" class="row" style="gap:.5rem">
    <?php if (!empty($filters['domain'])): ?>
      <input type="hidden" name="domain" value="<?=htmlspecialchars($filters['domain'])?>">
    <?php endif; ?>
    <select name="status" class="select" style="width:auto" data-autosubmit >
      <?php foreach ($statuses as $k => $v): ?>
        <option value="<?=htmlspecialchars($k)?>"
          <?=(($filters['status'] ?? '') === $k) ? 'selected' : ''?>><?=htmlspecialchars($v)?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-secondary btn-sm" type="submit">Filter</button></noscript>
  </form>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['domain']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('dashboard/history'.$qs(array('domain'=>null,'page'=>null)))?>">Everything</a>
  <?php foreach ($domains as $d): ?>
    <a class="btn btn-sm <?=$filters['domain'] === $d ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('dashboard/history'.$qs(array('domain'=>$d,'page'=>null)))?>">
      <?=htmlspecialchars($labels[$d] ?? $d)?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'list',
        'title' => empty($filters['domain']) && empty($filters['status'])
            ? 'No purchases yet'
            : 'Nothing matches this filter',
        'body'  => empty($filters['domain']) && empty($filters['status'])
            ? 'Everything you buy — SMM, VTU, numbers, identity and gift cards — appears here in one list.'
            : 'Try a different domain or status, or clear the filter to see everything.',
        'action_href'  => site_url('dashboard/history'),
        'action_label' => 'Clear filter',
    )); ?>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Date</th><th>Type</th><th>What</th><th>Status</th>
            <th class="text-right">Paid</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-xs muted whitespace-nowrap">
            <?=htmlspecialchars(date('M j, Y H:i', strtotime($r['created_at'])))?> UTC
          </td>
          <td><span class="badge badge-default"><?=htmlspecialchars($labels[$r['domain']] ?? $r['domain'])?></span></td>
          <td>
            <?=htmlspecialchars($r['label'])?>
            <div class="mono text-xs muted"><?=htmlspecialchars($r['public_id'])?></div>
          </td>
          <td><span class="<?=DashboardStats::status_badge($r['status'])?>"><?=htmlspecialchars($r['status'])?></span></td>
          <td class="text-right mono">
            <?=marvy_money($r['amount'], $r['currency'])?>
            <?php if (bccomp($r['refunded'], '0', 8) > 0): ?>
              <div class="text-xs muted">refunded <?=marvy_money($r['refunded'], $r['currency'])?></div>
            <?php endif; ?>
          </td>
          <td class="text-right">
            <?php if ($r['url']): ?>
              <a class="btn btn-ghost btn-sm" href="<?=site_url($r['url'])?>">View</a>
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
<div class="row justify-between mt-4">
  <?php if ($page > 1): ?>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/history'.$qs(array('page'=>$page-1)))?>">← Previous</a>
  <?php else: ?><span></span><?php endif; ?>
  <span class="muted text-sm">Page <?=$page?> of <?=$total_pages?></span>
  <?php if ($page < $total_pages): ?>
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/history'.$qs(array('page'=>$page+1)))?>">Next →</a>
  <?php else: ?><span></span><?php endif; ?>
</div>
<?php endif; ?>

<p class="hint mt-4">
  Wallet deposits and refunds are on your
  <a href="<?=site_url('dashboard/transactions')?>">transactions</a> page. This
  list is what you bought; that one is how your balance moved.
</p>
