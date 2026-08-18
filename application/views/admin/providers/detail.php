<?php defined('BASEPATH') OR exit('No direct script access allowed');
$p = $provider;
$can_manage_services = (isset($family) && $family === 'SMM')
    && (in_array('*', $permissions ?? array(), true)
        || in_array('services.manage', $permissions ?? array(), true));
$has = function ($permission) use ($permissions) {
    return in_array('*', $permissions ?? array(), true)
        || in_array($permission, $permissions ?? array(), true);
};
?>
<nav class="text-sm muted mb-4">
  <a href="<?=site_url('admin/providers')?>">Providers</a> · <span class="text-slate-700"><?=htmlspecialchars($p->name)?></span>
</nav>

<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <div class="row justify-between">
        <div>
          <h2 class="card-title"><?=htmlspecialchars($p->name)?></h2>
          <p class="mono text-xs muted"><?=htmlspecialchars($p->api_type)?> · <span class="break-all"><?=htmlspecialchars($p->api_url)?></span></p>
        </div>
        <?php
          $h = strtoupper((string)$p->health_status);
          $hcls = $h==='ONLINE'?'badge-success':($h==='OFFLINE'?'badge-danger':'badge-default');
        ?>
        <span class="badge <?=$hcls?> badge-dot" style="align-self:flex-start"><?=htmlspecialchars($h ?: 'UNKNOWN')?></span>
      </div>

      <dl class="grid grid-4 mt-4" style="gap:1rem">
        <div><dt class="muted text-xs">Status</dt><dd class="font-medium"><?=htmlspecialchars($p->status)?></dd></div>
        <div><dt class="muted text-xs">Balance</dt><dd class="mono"><?=$p->balance!==null?htmlspecialchars($p->balance).' '.htmlspecialchars($p->currency):'—'?></dd></div>
        <div><dt class="muted text-xs">Services</dt><dd class="mono"><?=number_format($total)?></dd></div>
        <div><dt class="muted text-xs">Last sync</dt><dd class="text-sm"><?=$p->last_successful_sync_at?date('M j, H:i',strtotime($p->last_successful_sync_at)):'never'?></dd></div>
        <div><dt class="muted text-xs">Timeout</dt><dd class="mono text-sm"><?=(int)$p->timeout_ms?> ms</dd></div>
        <div><dt class="muted text-xs">Sync every</dt><dd class="mono text-sm"><?=(int)$p->sync_interval_minutes?> min</dd></div>
        <div><dt class="muted text-xs">Rate mult.</dt><dd class="mono text-sm"><?=htmlspecialchars($p->rate_multiplier)?>×</dd></div>
        <div><dt class="muted text-xs">Markup</dt><dd class="mono text-sm"><?=htmlspecialchars($p->markup)?></dd></div>
      </dl>

      <?php if ($has('providers.sync')): ?>
      <div class="row mt-5" style="gap:.5rem;flex-wrap:wrap">
        <form method="post" action="<?=site_url('admin/providers/'.$p->public_id.'/test')?>">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <button class="btn btn-secondary" type="submit">⚡ Test connection</button>
        </form>
        <form method="post" action="<?=site_url('admin/providers/'.$p->public_id.'/sync-balance')?>">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <button class="btn btn-secondary" type="submit">↻ Sync balance</button>
        </form>
        <form method="post" action="<?=site_url('admin/providers/'.$p->public_id.'/sync')?>">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <button class="btn btn-primary" type="submit">⇅ Sync services</button>
        </form>
      </div>
      <?php endif; ?>
      <?php if (!empty($p->last_error)): ?>
        <div class="alert alert-danger mt-4 mb-0"><?=htmlspecialchars($p->last_error)?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <?php $is_vtu = (isset($family) && $family === 'VTU'); ?>
      <h3 class="card-title"><?=$is_vtu ? 'VTU catalogue' : 'Provider services'?></h3>
      <?php if (empty($services)): ?>
        <p class="muted mt-2">
          <?php if ($is_vtu): ?>
            No products synced yet. Run “Sync services” to pull this vendor’s bundles,
            packages and PIN types into the VTU catalogue.
          <?php else: ?>
            No services synced yet. Run “Sync services” to pull the provider catalog.
          <?php endif; ?>
        </p>
      <?php elseif ($is_vtu): ?>
      <p class="muted text-sm mt-2">Synced products start inactive and priced at cost —
        set a price and activate before customers can buy them. A re-sync never
        overwrites a price you have set.</p>
      <div class="overflow-x-auto mt-3">
        <table class="table">
          <thead><tr><th>Type</th><th>Vendor code</th><th>Name</th><th>Cost</th><th>Price</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr>
              <td class="mono text-xs"><?=htmlspecialchars($s->service_type)?></td>
              <td class="mono text-xs"><?=htmlspecialchars((string)$s->provider_code)?></td>
              <td><?=htmlspecialchars($s->name)?></td>
              <td class="mono"><?=$s->provider_cost !== null ? windels_money($s->provider_cost) : '—'?></td>
              <td class="mono"><?=$s->price !== null ? windels_money($s->price) : '—'?></td>
              <td>
                <span class="badge <?=(int)$s->is_active ? 'badge-success' : 'badge-default'?>">
                  <?=(int)$s->is_active ? 'ACTIVE' : 'INACTIVE'?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto mt-3">
        <table class="table">
          <thead><tr><th>Provider ID</th><th>Name</th><th>Rate</th><th>Min/Max</th><th>Flags</th><th>Synced</th><?php if ($can_manage_services): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr>
              <td class="mono text-xs"><?=htmlspecialchars($s->provider_service_id)?></td>
              <td><?=htmlspecialchars($s->name)?></td>
              <td class="mono"><?=htmlspecialchars($s->rate)?></td>
              <td class="mono text-xs"><?=number_format($s->min_quantity)?>–<?=number_format($s->max_quantity)?></td>
              <td>
                <?php if ((int)$s->refill_supported): ?><span class="badge badge-success">refill</span><?php endif; ?>
                <?php if ((int)$s->cancel_supported): ?><span class="badge badge-info">cancel</span><?php endif; ?>
                <?php if ((int)$s->dripfeed_supported): ?><span class="badge badge-brand">drip</span><?php endif; ?>
              </td>
              <td class="text-xs muted"><?=date('M j, H:i', strtotime($s->last_synced_at))?></td>
              <?php if ($can_manage_services): ?><td class="text-right"><a class="btn btn-ghost btn-sm" href="<?=site_url('admin/services/create?'.http_build_query(array('provider'=>$p->public_id,'provider_service'=>$s->provider_service_id)))?>">Create panel service →</a></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total_pages > 1): ?>
      <nav class="row justify-between mt-4">
        <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="?page=<?=max(1,$page-1)?>">← Previous</a>
        <span class="text-sm muted">Page <?=$page?>/<?=$total_pages?></span>
        <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>" href="?page=<?=min($total_pages,$page+1)?>">Next →</a>
      </nav>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <aside class="space-y-6">
    <div class="card">
      <h3 class="card-title">Recent syncs</h3>
      <ul class="stack" style="gap:.5rem;margin-top:.5rem">
        <?php if (empty($sync_logs)): ?><li class="muted text-sm">No syncs recorded.</li><?php endif; ?>
        <?php foreach ($sync_logs as $l): ?>
        <li class="text-sm">
          <span class="badge <?=$l->status==='SUCCESS'?'badge-success':'badge-danger'?>"><?=htmlspecialchars($l->status)?></span>
          <span class="muted"><?=htmlspecialchars($l->type)?><?=$l->items_synced?' · '.number_format($l->items_synced):''?><?=$l->duration_ms?' · '.$l->duration_ms.'ms':''?></span>
          <div class="text-xs muted"><?=date('M j, H:i', strtotime($l->created_at))?> UTC</div>
          <?php if (!empty($l->message)): ?><div class="text-xs text-rose-700"><?=htmlspecialchars($l->message)?></div><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="card">
      <h3 class="card-title">Health</h3>
      <ul class="stack" style="gap:.5rem;margin-top:.5rem">
        <?php if (empty($health_logs)): ?><li class="muted text-sm">No checks yet.</li><?php endif; ?>
        <?php foreach ($health_logs as $l): ?>
        <li class="text-sm row justify-between">
          <span class="badge <?=$l->status==='ONLINE'?'badge-success':'badge-danger'?> badge-dot"><?=htmlspecialchars($l->status)?></span>
          <span class="muted text-xs"><?=$l->latency_ms!==null?(int)$l->latency_ms.'ms':'—'?> · <?=date('M j, H:i', strtotime($l->created_at))?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </aside>
</div>
