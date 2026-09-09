<?php defined('BASEPATH') OR exit('No direct script access allowed');
$s = $service;
$can_manage = in_array('*', $permissions ?? array(), true) || in_array('services.manage', $permissions ?? array(), true);
$can_price = in_array('*', $permissions ?? array(), true) || in_array('pricing.manage', $permissions ?? array(), true);
$provider_public_id = $s->provider_public_id ?? '';
if (!$provider_public_id && !empty($s->provider_id)) {
    foreach ($options['providers'] as $candidate) {
        if ((int)$candidate->id === (int)$s->provider_id) { $provider_public_id = $candidate->public_id; break; }
    }
}
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
?>
<nav class="text-sm muted mb-4"><a href="<?=site_url('admin/services')?>">SMM services</a> · <span><?=$is_create?'Create':htmlspecialchars($s->name)?></span></nav>

<?php if (!$is_create && !$can_manage): ?>
<div class="alert alert-warning">You have read-only access to the service definition.</div>
<?php endif; ?>

<form method="post" action="<?=site_url($form_action)?>" class="space-y-6">
  <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>

  <div class="card">
    <div class="row justify-between"><div><h2 class="card-title">Panel service</h2><p class="text-sm muted mt-1">Set the customer-facing identity, price and availability.</p></div>
    

  <?php if (!$is_create): ?><span class="badge <?=$s->status==='ACTIVE'?'badge-success':'badge-default'?>"><?=htmlspecialchars($s->status)?></span><?php endif; ?>
    </div>
    <div class="grid gap-4 md:grid-cols-2 mt-5">
      <label class="field"><span>Name *</span><input class="input" name="name" maxlength="255" required value="<?=htmlspecialchars((string)$s->name)?>" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Slug *</span><input class="input mono" name="slug" maxlength="255" required value="<?=htmlspecialchars((string)$s->slug)?>" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Category *</span><select class="input" name="category" id="ws-category-select" required <?=$can_manage?'':'disabled'?>>
        <option value="">Choose a category</option>
        <?php if (!empty($s->provider_category) && empty($s->category_id)): ?>
          <option value="<?=SmmServiceAdminService::PROVIDER_CATEGORY_OPTION?>" selected>Create “<?=htmlspecialchars($s->provider_category)?>” (from this provider)</option>
        <?php endif; ?>
        <?php foreach ($options['categories'] as $category): ?><option value="<?=htmlspecialchars($category->public_id)?>" <?=(int)$s->category_id===(int)$category->id?'selected':''?>><?=htmlspecialchars($category->name)?><?=(int)$category->is_active?'':' (inactive)'?></option><?php endforeach; ?>
      </select>
        <?php if (empty($options['categories'])): ?><small>No panel categories exist yet. Link a synced provider service below and pick “create from this provider”, or add one under Admin → System → Categories.</small><?php endif; ?></label>
      <label class="field"><span>Service type *</span><select class="input" name="service_type" required <?=$can_manage?'':'disabled'?>>
        <?php foreach ($options['types'] as $type): ?><option value="<?=$type?>" <?=$s->service_type===$type?'selected':''?>><?=str_replace('_',' ',$type)?></option><?php endforeach; ?>
      </select></label>
      <label class="field"><span>Selling rate per 1,000 *</span><input class="input mono" name="rate" inputmode="decimal" required value="<?=htmlspecialchars((string)$s->rate)?>" <?=$can_manage?'':'disabled'?>>
        <small>Exact decimal, up to 8 places. Ignored while auto-price sync is on.</small></label>
      <label class="field"><span>Status *</span><select class="input" name="status" <?=$can_manage?'':'disabled'?>>
        <?php foreach ($options['statuses'] as $status): ?><option value="<?=$status?>" <?=$s->status===$status?'selected':''?>><?=$status?></option><?php endforeach; ?>
      </select></label>
      <label class="field md:col-span-2"><span>Description</span><textarea class="input" name="description" maxlength="5000" rows="4" <?=$can_manage?'':'disabled'?>><?=htmlspecialchars((string)$s->description)?></textarea></label>
    </div>
  </div>

  <div class="card">
    <h2 class="card-title">Provider source</h2>
    <p class="text-sm muted mt-1">A link is valid only when that upstream ID exists in the selected provider's synced catalogue. Provider cost and snapshots are always resolved on the server.</p>
    <div class="grid gap-4 md:grid-cols-2 mt-5">
      <label class="field"><span>SMM provider</span><select class="input" name="provider" id="ws-provider-select" <?=$can_manage?'':'disabled'?>>
        <option value="">Manual / no provider</option>
        <?php foreach ($options['providers'] as $provider): ?><option value="<?=htmlspecialchars($provider->public_id)?>" <?=$provider_public_id===$provider->public_id?'selected':''?>><?=htmlspecialchars($provider->name)?> · <?=htmlspecialchars($provider->status)?></option><?php endforeach; ?>
      </select></label>
      <div class="field" id="ws-provider-service-field">
        <span>Upstream service</span>
        <?php if (!empty($options['provider_services'])): ?>
          <select class="input" name="provider_service_id" id="ws-provider-service" <?=$can_manage?'':'disabled'?>>
            <option value="">— Choose a service —</option>
            <?php $in_list = false; ?>
            <?php foreach ($options['provider_services'] as $psvc): ?>
              <?php if ((string)$psvc['id'] === (string)$s->provider_service_id) $in_list = true; ?>
              <option value="<?=htmlspecialchars($psvc['id'])?>" <?=(string)$psvc['id']===(string)$s->provider_service_id?'selected':''?>>#<?=htmlspecialchars($psvc['id'])?> — <?=htmlspecialchars($psvc['name'])?> · <?=htmlspecialchars($psvc['rate'])?>/1k</option>
            <?php endforeach; ?>
            <?php if ((string)$s->provider_service_id !== '' && !$in_list): ?>
              <option value="<?=htmlspecialchars((string)$s->provider_service_id)?>" selected><?=htmlspecialchars((string)$s->provider_service_id)?> (not in the synced list)</option>
            <?php endif; ?>
          </select>
          <small>Picked from the provider's synced catalogue. Leave blank to unlink.</small>
        <?php else: ?>
          <input class="input mono" name="provider_service_id" maxlength="64" value="<?=htmlspecialchars((string)$s->provider_service_id)?>" <?=$can_manage?'':'disabled'?>>
          <small>Choose a provider to pick from its synced catalogue, or type the ID. Leave both blank to unlink.</small>
        <?php endif; ?>
      </div>
      <div><div class="text-xs muted">Trusted upstream rate</div><div class="mono font-medium"><?=$s->provider_rate!==null?htmlspecialchars((string)$s->provider_rate):'—'?></div></div>
      <label class="row" style="gap:.65rem;align-items:flex-start"><input type="checkbox" name="auto_price_sync" value="1" <?=(int)$s->auto_price_sync?'checked':''?> <?=$can_manage?'':'disabled'?>>
        <span><strong>Auto-sync selling rate</strong><small class="block muted">Apply provider multiplier and markup whenever this upstream item syncs. Otherwise sync updates only trusted cost and snapshot.</small></span></label>
    </div>
    <?php if (!empty($s->provider_source_snapshot)): ?>
      <details class="mt-4"><summary class="text-sm font-medium">Last trusted provider snapshot</summary><pre class="mt-2 text-xs overflow-x-auto bg-slate-50 rounded p-3"><?=htmlspecialchars(json_encode(json_decode($s->provider_source_snapshot, true), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre></details>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card-title">Limits and capabilities</h2>
    <div class="grid gap-4 md:grid-cols-4 mt-5">
      <label class="field"><span>Minimum *</span><input class="input mono" type="number" min="1" name="min_quantity" required value="<?=(int)$s->min_quantity?>" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Maximum *</span><input class="input mono" type="number" min="1" name="max_quantity" required value="<?=(int)$s->max_quantity?>" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Increment step *</span><input class="input mono" type="number" min="1" name="increment_step" required value="<?=(int)$s->increment_step?>" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Sort order</span><input class="input mono" type="number" name="sorting" value="<?=(int)$s->sorting?>" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Average time label</span><input class="input" name="average_time" maxlength="64" value="<?=htmlspecialchars((string)$s->average_time)?>" placeholder="e.g. 0–1 hour" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Average minutes</span><input class="input mono" type="number" min="0" name="average_time_minutes" value="<?=$s->average_time_minutes!==null?(int)$s->average_time_minutes:''?>" <?=$can_manage?'':'disabled'?>></label>
      <label class="field"><span>Refill days</span><input class="input mono" type="number" min="1" name="refill_days" value="<?=$s->refill_days!==null?(int)$s->refill_days:''?>" <?=$can_manage?'':'disabled'?>></label>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mt-5">
      <?php foreach (array('cancel_supported'=>'Cancellation','refill_supported'=>'Refill','dripfeed_supported'=>'Drip-feed','subscription_supported'=>'Subscription','package_supported'=>'Package','custom_comments_supported'=>'Custom comments','featured'=>'Featured','trending'=>'Trending') as $key=>$label): ?>
      <label class="row" style="gap:.55rem"><input type="checkbox" name="<?=$key?>" value="1" <?=(int)$s->$key?'checked':''?> <?=$can_manage?'':'disabled'?>> <span><?=$label?></span></label>
      <?php endforeach; ?>
    </div>
    <label class="field mt-5"><span>Metadata (JSON object or array)</span><textarea class="input mono text-xs" name="metadata" maxlength="16384" rows="5" <?=$can_manage?'':'disabled'?>><?=htmlspecialchars((string)$s->metadata)?></textarea><small>Use only non-secret service field definitions, labels and badges.</small></label>
  </div>

  <?php if ($can_manage): ?><div class="row justify-between" style="gap:1rem;flex-wrap:wrap"><a class="btn btn-ghost" href="<?=site_url('admin/services')?>">Cancel</a><button class="btn btn-primary" type="submit"><?=$is_create?'Review complete — create service':'Save changes'?></button></div><?php endif; ?>
</form>

<script <?=csp_nonce_attr()?>>
/**
 * Provider → service → category, without a round trip.
 *
 * The server renders the fields for the provider currently selected; this
 * script keeps them coherent when the operator switches provider: it fetches
 * the new provider's synced catalogue (services.view JSON endpoint) and
 * rebuilds the "Upstream service" picker, and offers "create the provider's
 * category" in the Category dropdown when the picked service's category has
 * no panel equivalent. Without JavaScript the form still works — the fields
 * are simply rendered for the provider the page was opened with.
 */
(function () {
  'use strict';

  var providerSel = document.getElementById('ws-provider-select');
  var fieldWrap   = document.getElementById('ws-provider-service-field');
  var categorySel = document.getElementById('ws-category-select');
  var endpoint    = <?=json_encode(site_url('admin/services/provider-services'))?>;
  var sentinel    = <?=json_encode(SmmServiceAdminService::PROVIDER_CATEGORY_OPTION)?>;
  var manualHint  = 'Choose a provider to pick from its synced catalogue, or type the ID. Leave both blank to unlink.';
  if (!providerSel || !fieldWrap) return;

  function buildSelect(services, truncated, currentValue) {
    var select = document.createElement('select');
    select.className = 'input';
    select.name = 'provider_service_id';
    select.id = 'ws-provider-service';
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '— Choose a service —';
    select.appendChild(placeholder);
    var haveCurrent = false;
    services.forEach(function (svc) {
      var opt = document.createElement('option');
      opt.value = svc.id;
      opt.textContent = '#' + svc.id + ' — ' + svc.name + ' · ' + svc.rate + '/1k';
      if (String(svc.id) === String(currentValue)) { opt.selected = true; haveCurrent = true; }
      opt.dataset.category = svc.category || '';
      select.appendChild(opt);
    });
    if (currentValue && String(currentValue) !== '' && !haveCurrent) {
      var stale = document.createElement('option');
      stale.value = currentValue;
      stale.textContent = currentValue + ' (not in the synced list)';
      stale.selected = true;
      select.appendChild(stale);
    }
    select.addEventListener('change', onServicePicked);
    fieldWrap.innerHTML = '';
    var label = document.createElement('span');
    label.textContent = 'Upstream service';
    fieldWrap.appendChild(label);
    fieldWrap.appendChild(select);
    var small = document.createElement('small');
    small.textContent = truncated
      ? 'Showing the first ' + services.length + ' services of a larger catalogue — pick above or type the ID below.'
      : 'Picked from the provider\u2019s synced catalogue. Leave blank to unlink.';
    fieldWrap.appendChild(small);
    if (truncated) {
      var manual = document.createElement('input');
      manual.className = 'input mono';
      manual.name = 'provider_service_id';
      manual.maxLength = 64;
      manual.placeholder = '…or type an upstream service ID';
      manual.style.marginTop = '.35rem';
      fieldWrap.appendChild(manual);
      // Two controls with one name: the empty one is dropped by PHP, the
      // filled one wins. Give the picker precedence when both are set.
      select.addEventListener('change', function () { if (select.value) manual.value = ''; });
      manual.addEventListener('input', function () { if (manual.value) select.value = ''; });
    }
    onServicePicked();
  }

  function buildManual(currentValue) {
    fieldWrap.innerHTML = '';
    var label = document.createElement('span');
    label.textContent = 'Upstream service ID';
    fieldWrap.appendChild(label);
    var input = document.createElement('input');
    input.className = 'input mono';
    input.name = 'provider_service_id';
    input.maxLength = 64;
    input.value = currentValue || '';
    fieldWrap.appendChild(input);
    var small = document.createElement('small');
    small.textContent = manualHint;
    fieldWrap.appendChild(small);
  }

  /** Offer "create the provider's category" when nothing matches its name. */
  function onServicePicked() {
    if (!categorySel) return;
    var existing = sentinelOption();
    if (existing) existing.remove();
    var select = document.getElementById('ws-provider-service');
    if (!select || !select.value) return;
    var chosen = select.options[select.selectedIndex];
    var providerCategory = chosen && chosen.dataset ? (chosen.dataset.category || '') : '';
    if (!providerCategory) return;
    var matches = false;
    for (var i = 0; i < categorySel.options.length; i++) {
      var label = categorySel.options[i].textContent.replace(/\s*\(inactive\)\s*$/, '').trim();
      if (label.toLowerCase() === providerCategory.toLowerCase()) {
        categorySel.selectedIndex = i;
        matches = true;
        break;
      }
    }
    if (matches) return;
    var opt = document.createElement('option');
    opt.value = sentinel;
    opt.textContent = 'Create \u201C' + providerCategory + '\u201D (from this provider)';
    categorySel.insertBefore(opt, categorySel.options.length > 1 ? categorySel.options[1] : null);
    // Selecting while detached does not stick in every engine — set it again
    // once the option is in the list.
    categorySel.value = sentinel;
  }

  function sentinelOption() {
    if (!categorySel) return null;
    for (var i = 0; i < categorySel.options.length; i++) {
      if (categorySel.options[i].value === sentinel) return categorySel.options[i];
    }
    return null;
  }

  providerSel.addEventListener('change', function () {
    var pub = providerSel.value;
    var currentService = document.querySelector('[name=provider_service_id]');
    var currentValue = currentService ? currentService.value : '';
    if (!pub) { buildManual(''); return; }
    buildManual('');
    fetch(endpoint + '?provider=' + encodeURIComponent(pub), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        buildSelect(data.services || [], !!data.truncated, currentValue);
      })
      .catch(function () { buildManual(currentValue); });
  });
})();
</script>

<?php if (!$is_create): ?>
  <?php $this->load->view('admin/services/pricing', compact('s','group_rates','user_rates','can_price')); ?>
  <?php if ($can_manage && $s->status !== 'ARCHIVED'): ?>
  <div class="card mt-6 border-rose-200"><h2 class="card-title text-rose-700">Archive service</h2><p class="text-sm muted mt-1">Archiving removes it from sale without breaking historical order references.</p>
    <form class="mt-4" method="post" action="<?=site_url('admin/services/'.$s->public_id.'/archive')?>"><input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly><button class="btn btn-danger" type="submit">Archive service</button></form>
  </div>
  <div class="card mt-6 border-rose-200"><h2 class="card-title text-rose-700">Delete service</h2><p class="text-sm muted mt-1">Permanently remove this service and its price overrides. Cannot be undone.</p>
    <form class="mt-4" method="post" action="<?=site_url('admin/services/'.$s->public_id.'/delete')?>"><input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly><button class="btn btn-danger" type="submit" data-confirm="Are you sure you want to permanently delete this service? This cannot be undone." >Delete service</button></form>
  </div>
  <?php endif; ?>
<?php endif; ?>
