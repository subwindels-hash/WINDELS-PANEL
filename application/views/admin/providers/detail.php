<?php defined('BASEPATH') OR exit('No direct script access allowed');
$p = $provider;
$is_vtu = (isset($family) && $family === 'VTU');
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
        <div><dt class="muted text-xs">Markup</dt><dd class="mono text-sm"><?=rtrim(rtrim(number_format(ProviderSyncService::markup_percent($p), 2, '.', ''), '0'), '.')?>%</dd></div>
        <div><dt class="muted text-xs">Flat add-on</dt><dd class="mono text-sm"><?=marvy_money($p->markup, $p->currency)?></dd></div>
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
        <?php if ($can_manage_services && !$is_vtu): ?>
        <button class="btn btn-secondary" type="button" data-dialog-open="ws-import-services">⇥ Import all services…</button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($has('providers.manage')): ?>
      <div class="row mt-3" style="justify-content:flex-end">
        <form method="post" action="<?=site_url('admin/providers/'.$p->public_id.'/delete')?>"
              style="margin:0">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>"
                 value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <button class="btn btn-danger btn-sm" type="submit"
                  data-confirm="<?=htmlspecialchars('Delete '.($p->name).' and its '
                    .number_format($total).' synced service'.($total === 1 ? '' : 's').'?'
                    .((int)$linked_panel_services > 0
                        ? ' '.number_format((int)$linked_panel_services).' panel service'
                          .((int)$linked_panel_services === 1 ? ' stays' : 's stay')
                          .' sellable but unlinked.'
                        : '')
                    .((int)$linked_orders > 0
                        ? ' '.number_format((int)$linked_orders).' past order'
                          .((int)$linked_orders === 1 ? ' keeps' : 's keep')
                          .' its history with the provider link removed.'
                        : '')
                    .' This cannot be undone.')?>">✕ Delete provider and its synced services</button>
        </form>
      </div>
      <?php endif; ?>
      <?php if ($has('providers.manage')): ?>
      <?php
        $__percent = ProviderSyncService::markup_percent($p);
        $__example_cost = 20;
      ?>
      <div class="card mt-5" style="background:var(--color-surface-muted,#f8fafc)">
        <h3 class="card-title">Pricing rule</h3>
        <p class="muted text-sm">
          What customers pay for anything sourced from this provider:
          <span class="mono">vendor cost + markup %</span> (plus an optional flat amount).
          A vendor price of <?=marvy_money($__example_cost, $p->currency)?> at
          <span class="mono" data-pricing-percent-label><?=rtrim(rtrim(number_format($__percent, 2, '.', ''), '0'), '.')?>%</span>
          sells for <strong class="mono" data-pricing-example><?=marvy_money($__example_cost * (1 + $__percent / 100) + (float)$p->markup, $p->currency)?></strong>.
        </p>

        <form method="post" action="<?=site_url('admin/providers/'.$p->public_id.'/pricing')?>" class="mt-3">
          <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
          <div class="row" style="gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <label class="field mb-0" style="flex:1;min-width:12rem">
              <span class="label">Percentage increase</span>
              <select class="select" name="markup_percent" id="ws-markup-percent" data-pricing-input>
                <?php for ($i = 0; $i <= ProviderSyncService::MAX_MARKUP_PERCENT; $i++): ?>
                  <option value="<?=$i?>" <?=((int)round($__percent) === $i) ? 'selected' : ''?>>
                    <?=$i?>%<?=$i === 0 ? ' — sell at cost' : ''?>
                  </option>
                <?php endfor; ?>
              </select>
              <span class="hint">0% to <?=ProviderSyncService::MAX_MARKUP_PERCENT?>% over the vendor's own rate.</span>
            </label>
            <label class="field mb-0" style="flex:1;min-width:10rem">
              <span class="label">Flat add-on (optional)</span>
              <input class="input" type="number" step="0.01" min="0" name="markup_flat"
                     value="<?=htmlspecialchars(number_format((float)$p->markup, 2, '.', ''))?>" data-pricing-flat>
              <span class="hint">Added after the percentage, in <?=htmlspecialchars($p->currency)?>.</span>
            </label>
          </div>
          <label class="row mt-3" style="gap:.5rem;align-items:flex-start">
            <input type="checkbox" name="reprice" value="1" checked>
            <span class="text-sm">Re-price the services already mirrored from this provider that follow provider pricing.
              Hand-priced services are never touched.</span>
          </label>
          <div class="row mt-3" style="justify-content:flex-end">
            <button class="btn btn-primary" type="submit">Save pricing rule</button>
          </div>
        </form>
      </div>

      <script <?=csp_nonce_attr()?>>
      (function () {
        var sel = document.getElementById('ws-markup-percent');
        var flat = document.querySelector('[data-pricing-flat]');
        var out = document.querySelector('[data-pricing-example]');
        var label = document.querySelector('[data-pricing-percent-label]');
        if (!sel || !out) return;
        var cost = <?=json_encode($__example_cost)?>;
        var sym = <?=json_encode(trim(str_replace(array('0','.',','), '', marvy_money(0, $p->currency))))?>;
        function recalc() {
          var pct = parseFloat(sel.value || '0');
          var add = parseFloat((flat && flat.value) || '0') || 0;
          out.textContent = sym + (cost * (1 + pct / 100) + add).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
          if (label) label.textContent = pct + '%';
        }
        sel.addEventListener('change', recalc);
        if (flat) flat.addEventListener('input', recalc);
        recalc();
      })();
      </script>
      <?php endif; ?>

      <?php if (!empty($p->last_error)): ?>
        <div class="alert alert-danger mt-4 mb-0"><?=htmlspecialchars($p->last_error)?></div>
      <?php endif; ?>
    </div>

    <div class="card">
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
              <td class="mono"><?=$s->provider_cost !== null ? marvy_money($s->provider_cost) : '—'?></td>
              <td class="mono"><?=$s->price !== null ? marvy_money($s->price) : '—'?></td>
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
      <p class="muted text-sm mt-2">
        These are the provider's own services — the raw mirror of a sync. Customers only ever see
        panel services: create them one by one with the link on each row, or use
        <strong>Import all services…</strong> above to bring the whole catalogue across at once.
      </p>
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

<?php if ($can_manage_services && empty($is_vtu)): ?>
<dialog id="ws-import-services" class="ws-dialog" data-dialog-light-dismiss>
  <?=form_open('admin/providers/'.$p->public_id.'/import', array('class'=>'stack'))?>
    <h3 class="card-title mb-0">Import every synced service</h3>
    <p class="text-sm muted mt-1">
      Creates a panel service for each of this provider's <?=number_format($total)?> synced
      services that is not linked to one already — pricing each at the provider's rate plus your
      pricing rule (currently <?=rtrim(rtrim(number_format(ProviderSyncService::markup_percent($p), 2, '.', ''), '0'), '.')?>%).
      Re-running after a sync only ever adds what is new.
    </p>
    <fieldset class="stack" style="gap:.5rem">
      <legend class="text-sm font-medium">Import as</legend>
      <label class="row" style="gap:.5rem"><input type="radio" name="status" value="ACTIVE" checked>
        <span><strong>Active</strong> — customers can see and order them immediately.</span></label>
      <label class="row" style="gap:.5rem"><input type="radio" name="status" value="INACTIVE">
        <span><strong>Inactive</strong> — review and switch on from the services list first.</span></label>
    </fieldset>
    <label class="row" style="gap:.5rem;align-items:flex-start">
      <input type="checkbox" name="create_categories" value="1" checked>
      <span><strong>Create categories from the provider's own</strong>
        <small class="block muted">Panel categories named after the provider's categories; an existing category with the same name is reused.</small></span>
    </label>
    <label class="row" style="gap:.5rem;align-items:flex-start">
      <input type="checkbox" name="auto_price_sync" value="1" checked>
      <span><strong>Follow provider pricing</strong>
        <small class="block muted">Each service re-prices itself whenever this provider syncs. Hand-priced later — it stops following.</small></span>
    </label>
    <div class="row" style="justify-content:flex-end;gap:.5rem">
      <button type="button" class="btn btn-ghost" data-dialog-close="ws-import-services">Cancel</button>
      <button type="submit" class="btn btn-primary"
              data-confirm="Import <?=number_format($total)?> services from <?=htmlspecialchars($p->name)?>? This cannot be undone one by one — bulk-deleting later is the way back.">Import all services</button>
    </div>
  <?=form_close()?>
</dialog>
<style>.ws-dialog{border:0;border-radius:1rem;padding:0;width:min(560px,92vw);box-shadow:0 30px 80px -20px rgba(0,0,0,.4)}
.ws-dialog::backdrop{background:rgba(15,23,42,.55)} .ws-dialog form{padding:1.5rem}</style>
<?php endif; ?>
