<?php defined('BASEPATH') OR exit('No direct script access allowed');
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('q' => $filters['search'] ?? null, 'status' => $filters['status'] ?? null, 'page' => $page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Wallets</h2>
    <p class="muted text-sm">Customer funds held by the panel</p>
  </div>
  <form method="get" action="<?=site_url('admin/wallets')?>" class="row" style="gap:.35rem">
    <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
           placeholder="Username or email" aria-label="Search wallets" style="min-width:14rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="grid mb-4" style="grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.75rem">
  <div class="card">
    <div class="muted text-xs">Total held — a liability</div>
    <div class="mono" style="font-size:1.4rem;font-weight:600"><?=marvy_money($totals['held'])?></div>
    <div class="muted text-xs">across <?=number_format($totals['wallets'])?> wallets</div>
  </div>
  <div class="card">
    <div class="muted text-xs">Lifetime deposited</div>
    <div class="mono" style="font-size:1.1rem"><?=marvy_money($totals['deposited'])?></div>
  </div>
  <div class="card">
    <div class="muted text-xs">Lifetime spent</div>
    <div class="mono" style="font-size:1.1rem"><?=marvy_money($totals['spent'])?></div>
  </div>
</div>

<div class="card">
  <?php if (empty($users)): ?>
    <p class="muted">No wallets match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Customer</th><th class="text-right">Balance</th>
            <th class="text-right">Deposited</th><th class="text-right">Spent</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$u->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$u->email)?></div>
          </td>
          <td class="text-right mono"><?=marvy_money($u->balance ?? '0', $u->currency ?? null)?></td>
          <td class="text-right mono muted"><?=marvy_money($u->total_deposited ?? '0', $u->currency ?? null)?></td>
          <td class="text-right mono muted"><?=marvy_money($u->total_spent ?? '0', $u->currency ?? null)?></td>
          <td><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/customers/'.$u->public_id)?>">Open →</a></td>
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
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/wallets'.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/wallets'.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
