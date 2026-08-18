<?php defined('BASEPATH') OR exit('No direct script access allowed');
$status_of = function ($key) {
    if (!empty($key->revoked_at)) return 'REVOKED';
    if (!empty($key->expires_at) && strtotime($key->expires_at) <= time()) return 'EXPIRED';
    return 'ACTIVE';
};
$badge = array('ACTIVE'=>'badge-success','EXPIRED'=>'badge-warning','REVOKED'=>'badge-danger');
$total_pages = max(1, (int)ceil($total / $per_page));
$tabs = array(''=>'All','ACTIVE'=>'Active','EXPIRED'=>'Expired','REVOKED'=>'Revoked');
$query = function (array $replace = array()) use ($filters) {
    return '?'.http_build_query(array_filter(array_merge(array(
        'status'=>$filters['status'], 'q'=>$filters['search'], 'user'=>$filters['user'],
    ), $replace), function ($value) { return $value !== '' && $value !== null; }));
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Reseller API keys</h2>
    <p class="muted text-sm">Inspect usage, constrain endpoint access, and revoke compromised keys. Stored credentials are never displayed.</p>
  </div>
  <a class="btn btn-secondary btn-sm" href="<?=site_url('api/docs')?>" target="_blank" rel="noopener">API documentation</a>
</div>

<div class="grid grid-4 mb-4" style="gap:1rem">
  <div class="card"><div class="muted text-sm">Active keys</div><div class="text-2xl font-bold"><?=number_format($totals['active'])?></div></div>
  <div class="card"><div class="muted text-sm">Revoked keys</div><div class="text-2xl font-bold"><?=number_format($totals['revoked'])?></div></div>
  <div class="card"><div class="muted text-sm">Expired keys</div><div class="text-2xl font-bold"><?=number_format($totals['expired'])?></div></div>
  <div class="card"><div class="muted text-sm">Requests · 24 hours</div><div class="text-2xl font-bold"><?=number_format($totals['requests_24h'])?></div></div>
</div>

<div class="row justify-between mb-4" style="align-items:flex-end;flex-wrap:wrap;gap:.75rem">
  <div class="row" style="gap:.4rem;flex-wrap:wrap">
    <?php foreach ($tabs as $value=>$label): ?><a class="btn btn-sm <?=$filters['status']===$value?'btn-primary':'btn-ghost'?>" href="<?=site_url('admin/api-keys'.$query(array('status'=>$value)))?>"><?=htmlspecialchars($label)?></a><?php endforeach; ?>
  </div>
  <form method="get" action="<?=site_url('admin/api-keys')?>" class="row" style="gap:.35rem;flex-wrap:wrap">
    <?php if ($filters['status']): ?><input type="hidden" name="status" value="<?=htmlspecialchars($filters['status'])?>"><?php endif; ?>
    <?php if ($filters['user']): ?><input type="hidden" name="user" value="<?=htmlspecialchars($filters['user'])?>"><?php endif; ?>
    <input class="input" name="q" value="<?=htmlspecialchars($filters['search'])?>" maxlength="100" placeholder="Key, prefix, customer or email" aria-label="Search API keys" style="min-width:18rem">
    <button class="btn btn-secondary btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="card">
<?php if (empty($rows)): ?><p class="muted">No API keys match these filters.</p>
<?php else: ?><div class="overflow-x-auto"><table class="table"><thead><tr><th>Key</th><th>Customer</th><th>Status</th><th>Access</th><th>Limit</th><th>Last used</th><th>Created</th></tr></thead><tbody>
<?php foreach ($rows as $key): $status=$status_of($key); $scopes=$key->scopes===null?null:json_decode($key->scopes,true); ?>
<tr>
  <td><a class="font-medium" href="<?=site_url('admin/api-keys/'.$key->public_id)?>"><?=htmlspecialchars((string)$key->name)?></a><div class="mono text-xs muted"><?=htmlspecialchars($key->prefix)?>••••••••</div></td>
  <td><a href="<?=site_url('admin/users/'.$key->user_public_id)?>"><?=htmlspecialchars((string)$key->username)?></a><div class="text-xs muted"><?=htmlspecialchars((string)$key->email)?></div></td>
  <td><span class="badge <?=$badge[$status]?>"><?=$status?></span></td>
  <td class="text-xs"><?=$scopes===null?'Full (legacy)':number_format(is_array($scopes)?count($scopes):0).' scope(s)'?></td>
  <td class="mono text-xs"><?=number_format((int)$key->rate_limit_per_minute)?>/min</td>
  <td class="text-xs muted"><?=htmlspecialchars((string)($key->last_used_at ?: 'Never'))?></td>
  <td class="text-xs muted"><?=htmlspecialchars((string)$key->created_at)?></td>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php if ($total_pages > 1): ?>
<nav class="row justify-between mt-4" aria-label="Pagination">
  <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="<?=site_url('admin/api-keys'.$query(array('page'=>max(1,$page-1))))?>">← Previous</a>
  <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
  <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>" href="<?=site_url('admin/api-keys'.$query(array('page'=>min($total_pages,$page+1))))?>">Next →</a>
</nav><?php endif; ?>
</div>
