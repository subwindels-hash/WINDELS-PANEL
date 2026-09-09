<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$statuses = array('ACTIVE','SUSPENDED','BANNED','PENDING');

$qs = function (array $over = array()) use ($filters, $page) {
    $base = array(
        'status' => $filters['status'] ?? null,
        'role'   => $filters['role'] ?? null,
        'q'      => $filters['search'] ?? null,
        'group'  => $filters['price_group_id'] ?? null,
        'page'   => $page,
    );
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};

$status_badge = function ($s) {
    $map = array('ACTIVE'=>'badge-success','SUSPENDED'=>'badge-warning','BANNED'=>'badge-danger','PENDING'=>'badge-default');
    return $map[strtoupper((string)$s)] ?? 'badge-default';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Customers</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> account<?=$total == 1 ? '' : 's'?> matching this view</p>
  </div>
  <form method="get" action="<?=site_url('admin/customers')?>" class="row" style="gap:.35rem;flex-wrap:wrap">
    <?php if (!empty($filters['status'])): ?>
      <input type="hidden" name="status" value="<?=htmlspecialchars($filters['status'])?>">
    <?php endif; ?>
    <select class="input" name="role" aria-label="Filter by role">
      <option value="">Customers only</option>
      <?php foreach ($roles as $r): ?>
        <option value="<?=htmlspecialchars($r)?>" <?=($filters['role'] ?? '') === $r ? 'selected' : ''?>>
          <?=htmlspecialchars($r)?>
        </option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="group" aria-label="Filter by price group">
      <option value="">All price groups</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?=(int)$g->id?>" <?=(int)($filters['price_group_id'] ?? 0) === (int)$g->id ? 'selected' : ''?>>
          <?=htmlspecialchars($g->name)?>
        </option>
      <?php endforeach; ?>
    </select>
    <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
           placeholder="Username, email or ID" aria-label="Search customers" style="min-width:14rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>"
     href="<?=site_url('admin/customers'.$qs(array('status'=>null,'page'=>null)))?>">
    All <span class="muted"><?=number_format(array_sum($counts))?></span>
  </a>
  <?php foreach ($statuses as $s): if (empty($counts[$s])) continue; ?>
    <a class="btn btn-sm <?=($filters['status'] ?? '') === $s ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/customers'.$qs(array('status'=>$s,'page'=>null)))?>">
      <?=htmlspecialchars($s)?> <span class="muted"><?=number_format((int)$counts[$s])?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($users)): ?>
    <p class="muted">No accounts match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Customer</th><th>Role</th><th>Status</th><th>Price group</th>
            <th>Security PIN</th>
            <th class="text-right">Balance</th><th class="text-right">Lifetime spend</th>
            <th>Last seen</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$u->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$u->email)?></div>
            <?php if (!empty($u->user_code)): ?>
              <div class="text-xs" title="Six-digit account number — also a valid login identifier">
                ID <span class="mono" style="letter-spacing:.15em"><?=htmlspecialchars((string)$u->user_code)?></span>
              </div>
            <?php endif; ?>
            <?php if (empty($u->email_verified_at)): ?>
              <span class="badge badge-warning">unverified</span>
            <?php endif; ?>
            <?php if (!empty($u->mfa_enabled)): ?>
              <span class="badge badge-default">MFA</span>
            <?php endif; ?>
          </td>
          <td class="text-xs"><?=htmlspecialchars((string)$u->role)?></td>
          <td><span class="badge <?=$status_badge($u->status)?>"><?=htmlspecialchars((string)$u->status)?></span></td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($u->price_group_name ?? '—'))?></td>
          <td>
            <?php if (!empty($can_reveal_pins) && isset($pins[(int)$u->id])): ?>
              <span class="mono" style="letter-spacing:.2em" title="Security PIN — reveal audited"><?=htmlspecialchars($pins[(int)$u->id])?></span>
            <?php elseif (!empty($u->pin_hash)): ?>
              <span class="badge badge-default">set</span>
              <?php if (!empty($can_reveal_pins)): ?>
                <span class="muted text-xs" title="Set before encrypted PIN history — readable only after the customer sets their next PIN">· legacy</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted text-xs">—</span>
            <?php endif; ?>
          </td>
          <td class="text-right mono"><?=marvy_money($u->balance ?? '0', $u->currency ?? null)?></td>
          <td class="text-right mono muted"><?=marvy_money($u->total_spent ?? '0', $u->currency ?? null)?></td>
          <td class="text-xs muted whitespace-nowrap">
            <?=$u->last_login_at ? date('M j, H:i', strtotime($u->last_login_at)) : 'never'?>
          </td>
          <td>
            <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/customers/'.$u->public_id)?>">Open →</a>
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
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/customers'.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/customers'.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
