<?php defined('BASEPATH') OR exit('No direct script access allowed');
$status_badge = function ($s) {
    $map = array('ACTIVE'=>'badge-success','SUSPENDED'=>'badge-warning','BANNED'=>'badge-danger','PENDING'=>'badge-default');
    return $map[strtoupper((string)$s)] ?? 'badge-default';
};
$qs = function (array $over = array()) use ($filters, $page) {
    $base = array('q' => $filters['search'] ?? null, 'role' => $filters['role'] ?? null, 'page' => $page);
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Staff</h2>
    <p class="muted text-sm"><?=number_format((int)$total)?> account<?=$total == 1 ? '' : 's'?> with access to this panel</p>
  </div>
  <div class="row" style="gap:.35rem;flex-wrap:wrap">
    <a class="btn btn-secondary btn-sm" href="<?=site_url('admin/staff/permissions')?>">Roles and permissions →</a>
  </div>
</div>

<div class="grid mb-4" style="grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem">
  <?php foreach ($roles as $r): if ($r->name === 'CUSTOMER') continue; ?>
    <div class="card">
      <div class="muted text-xs"><?=htmlspecialchars($r->name)?></div>
      <div style="font-size:1.4rem;font-weight:600"><?=number_format((int)$r->headcount)?></div>
      <div class="muted text-xs"><?=htmlspecialchars((string)$r->description)?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($staff)): ?>
    <p class="muted">No staff accounts match this filter.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>Member</th><th>Role</th><th>Status</th><th>MFA</th><th>Last seen</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($staff as $u): ?>
        <tr>
          <td>
            <div class="font-medium text-slate-900"><?=htmlspecialchars((string)$u->username)?></div>
            <div class="text-xs muted"><?=htmlspecialchars((string)$u->email)?></div>
            <?php if (!empty($u->user_code)): ?>
              <div class="text-xs" title="Six-digit account number — also a valid login identifier">
                ID <span class="mono" style="letter-spacing:.15em"><?=htmlspecialchars((string)$u->user_code)?></span>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-xs"><?=htmlspecialchars((string)$u->role)?></td>
          <td><span class="badge <?=$status_badge($u->status)?>"><?=htmlspecialchars((string)$u->status)?></span></td>
          <td>
            <?php if (!empty($u->mfa_enabled)): ?>
              <span class="badge badge-success">on</span>
            <?php else: ?>
              <span class="badge badge-warning">off</span>
            <?php endif; ?>
          </td>
          <td class="text-xs muted whitespace-nowrap">
            <?=$u->last_login_at ? date('M j, H:i', strtotime($u->last_login_at)) : 'never'?>
          </td>
          <td><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/customers/'.$u->public_id)?>">Open →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted text-xs mt-3">
    Promote or demote someone from their own file — role changes sit next to account status.
  </p>
  <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="row justify-between mt-4" style="align-items:center">
  <span class="muted text-sm">Page <?=number_format($page)?> of <?=number_format($total_pages)?></span>
  <div class="row" style="gap:.35rem">
    <?php if ($page > 1): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/staff'.$qs(array('page'=>$page-1)))?>">← Previous</a>
    <?php endif; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/staff'.$qs(array('page'=>$page+1)))?>">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
