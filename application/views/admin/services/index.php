<?php defined('BASEPATH') OR exit('No direct script access allowed');
$can_manage = in_array('*', $permissions ?? array(), true) || in_array('services.manage', $permissions ?? array(), true);
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
?>
<div class="row justify-between mb-4" style="align-items:flex-start;gap:1rem;flex-wrap:wrap">
  <div>
    <p class="muted">Customer-facing SMM products, separate from the cross-domain product catalogue.</p>
    <p class="text-xs muted mt-1"><?=number_format($total)?> service<?=$total === 1 ? '' : 's'?></p>
  </div>
  <?php if ($can_manage): ?><a class="btn btn-primary" href="<?=site_url('admin/services/create')?>">+ Create service</a><?php endif; ?>
</div>

<form method="get" action="<?=site_url('admin/services')?>" class="card mb-5">
  <div class="grid gap-3 md:grid-cols-6">
    <label class="field md:col-span-2"><span>Search</span>
      <input class="input" type="search" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>" placeholder="Name, slug or upstream ID">
    </label>
    <label class="field"><span>Status</span>
      <select class="input" name="status"><option value="">All statuses</option>
        <?php foreach ($options['statuses'] as $status): ?><option value="<?=$status?>" <?=$filters['status']===$status?'selected':''?>><?=$status?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Category</span>
      <select class="input" name="category"><option value="">All categories</option>
        <?php foreach ($options['categories'] as $category): ?><option value="<?=htmlspecialchars($category->public_id)?>" <?=$filters['category_public_id']===$category->public_id?'selected':''?>><?=htmlspecialchars($category->name)?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Provider</span>
      <select class="input" name="provider"><option value="">All providers</option>
        <?php foreach ($options['providers'] as $provider): ?><option value="<?=htmlspecialchars($provider->public_id)?>" <?=$filters['provider_public_id']===$provider->public_id?'selected':''?>><?=htmlspecialchars($provider->name)?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Type</span>
      <select class="input" name="type"><option value="">All types</option>
        <?php foreach ($options['types'] as $type): ?><option value="<?=$type?>" <?=$filters['service_type']===$type?'selected':''?>><?=str_replace('_',' ',$type)?></option><?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="row mt-3" style="gap:.5rem"><button class="btn btn-secondary" type="submit">Filter</button><a class="btn btn-ghost" href="<?=site_url('admin/services')?>">Reset</a></div>
</form>

<div class="card overflow-x-auto">
  <?php if (!$services): ?><p class="muted">No services match these filters.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Service</th><th>Category</th><th>Provider</th><th>Selling rate</th><th>Limits</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($services as $service):
      $status_class = $service->status === 'ACTIVE' ? 'badge-success' : ($service->status === 'ARCHIVED' ? 'badge-danger' : 'badge-default'); ?>
      <tr>
        <td><div class="font-medium"><?=htmlspecialchars($service->name)?></div><div class="mono text-xs muted"><?=htmlspecialchars($service->public_id)?> · <?=htmlspecialchars($service->service_type)?></div></td>
        <td><?=htmlspecialchars($service->category_name ?? '—')?></td>
        <td><div><?=htmlspecialchars($service->provider_name ?? 'Manual')?></div><?php if ($service->provider_service_id): ?><div class="mono text-xs muted">#<?=htmlspecialchars($service->provider_service_id)?></div><?php endif; ?></td>
        <td class="mono"><?=htmlspecialchars($service->rate)?></td>
        <td class="mono text-xs"><?=number_format((int)$service->min_quantity)?>–<?=number_format((int)$service->max_quantity)?></td>
        <td><span class="badge <?=$status_class?>"><?=htmlspecialchars($service->status)?></span><?php if ((int)$service->auto_price_sync): ?><div class="text-xs muted mt-1">auto-price</div><?php endif; ?></td>
        <td class="text-right">
          <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/services/'.$service->public_id)?>"><?=$can_manage?'Edit':'View'?> →</a>
          <?php if ($can_manage && $service->status !== 'ARCHIVED'): ?>
          <form method="post" action="<?=site_url('admin/services/'.$service->public_id.'/delete')?>" style="display:inline">
            <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>">
            <button class="btn btn-ghost btn-sm text-rose-600" type="submit" data-confirm="Delete this service? This cannot be undone." >Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<nav class="row justify-between mt-4">
  <?php $query = $_GET; $query['page'] = max(1, $page-1); ?>
  <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="?<?=htmlspecialchars(http_build_query($query))?>">← Previous</a>
  <span class="text-sm muted">Page <?=$page?> of <?=$total_pages?></span>
  <?php $query['page'] = min($total_pages, $page+1); ?>
  <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>" href="?<?=htmlspecialchars(http_build_query($query))?>">Next →</a>
</nav>
<?php endif; ?>
