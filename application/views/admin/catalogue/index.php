<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$can_price = $has('pricing.manage');

$qs = function (array $over = array()) use ($filters, $page, $domain) {
    $base = array(
        'status'  => $filters['status'] ?? null,
        'pricing' => $filters['pricing'] ?? null,
        'q'       => $filters['search'] ?? null,
        'network' => $filters['network_id'] ?? null,
        'country' => $filters['country_id'] ?? null,
        'service' => $filters['service_id'] ?? null,
        'brand'   => $filters['brand_id'] ?? null,
        'type'    => $filters['service_type'] ?? ($filters['id_type'] ?? ($filters['denomination_type'] ?? null)),
        'page'    => $page,
    );
    $merged = array_filter(array_merge($base, $over), function ($v) { return $v !== null && $v !== '' && $v !== 0; });
    return $merged ? '?'.http_build_query($merged) : '';
};

// The one number that matters on this screen: rows a sync imported and nobody
// has priced. They are invisible to customers and stay that way silently.
$unpriced = 0;
foreach ($rows as $r) { if ($r->price === null) $unpriced++; }

$type_options = array(
    'vtu'       => array('AIRTIME','DATA','CABLE','ELECTRICITY','EXAM_PIN'),
    'identity'  => array('NIN','BVN'),
    'giftcards' => array('FIXED','RANGE'),
);
$current_type = $filters['service_type'] ?? ($filters['id_type'] ?? ($filters['denomination_type'] ?? ''));
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Catalogue</h2>
    <p class="muted text-sm">
      <?=number_format((int)$total)?> <?=htmlspecialchars(strtolower(CatalogueService::label($domain)))?>
      matching this view. Prices are in <?=htmlspecialchars(marvy_base_currency())?>.
    </p>
  </div>
  <?php if ($can_price): ?>
    <button class="btn btn-primary"
            data-dialog-open="ws-new-product" >+ Add product</button>
  <?php endif; ?>
</div>

<div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
  <?php foreach ($domains as $key => $label): ?>
    <a class="btn btn-sm <?=$domain === $key ? 'btn-primary' : 'btn-ghost'?>"
       href="<?=site_url('admin/catalogue/'.$key)?>"><?=htmlspecialchars($label)?></a>
  <?php endforeach; ?>
</div>

<form method="get" action="<?=site_url('admin/catalogue/'.$domain)?>" class="row mb-4" style="gap:.35rem;flex-wrap:wrap">
  <?php if ($domain === 'vtu'): ?>
    <select class="input" name="network" aria-label="Filter by network">
      <option value="">All networks</option>
      <?php foreach ($options['networks'] as $n): ?>
        <option value="<?=(int)$n->id?>" <?=(int)($filters['network_id'] ?? 0) === (int)$n->id ? 'selected' : ''?>>
          <?=htmlspecialchars($n->name)?> (<?=htmlspecialchars($n->service_type)?>)
        </option>
      <?php endforeach; ?>
    </select>
  <?php elseif ($domain === 'numbers'): ?>
    <select class="input" name="country" aria-label="Filter by country">
      <option value="">All countries</option>
      <?php foreach ($options['countries'] as $c): ?>
        <option value="<?=(int)$c->id?>" <?=(int)($filters['country_id'] ?? 0) === (int)$c->id ? 'selected' : ''?>>
          <?=htmlspecialchars($c->name)?>
        </option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="service" aria-label="Filter by service">
      <option value="">All services</option>
      <?php foreach ($options['services'] as $s): ?>
        <option value="<?=(int)$s->id?>" <?=(int)($filters['service_id'] ?? 0) === (int)$s->id ? 'selected' : ''?>>
          <?=htmlspecialchars($s->name)?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php elseif ($domain === 'giftcards'): ?>
    <select class="input" name="brand" aria-label="Filter by brand">
      <option value="">All brands</option>
      <?php foreach ($options['brands'] as $b): ?>
        <option value="<?=(int)$b->id?>" <?=(int)($filters['brand_id'] ?? 0) === (int)$b->id ? 'selected' : ''?>>
          <?=htmlspecialchars($b->name)?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>

  <?php if (isset($type_options[$domain])): ?>
    <select class="input" name="type" aria-label="Filter by type">
      <option value="">All types</option>
      <?php foreach ($type_options[$domain] as $t): ?>
        <option value="<?=htmlspecialchars($t)?>" <?=$current_type === $t ? 'selected' : ''?>><?=htmlspecialchars($t)?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>

  <select class="input" name="pricing" aria-label="Filter by pricing">
    <option value="">Priced and unpriced</option>
    <option value="unpriced" <?=($filters['pricing'] ?? '') === 'unpriced' ? 'selected' : ''?>>Needs a price</option>
    <option value="priced"   <?=($filters['pricing'] ?? '') === 'priced' ? 'selected' : ''?>>Priced</option>
  </select>
  <select class="input" name="status" aria-label="Filter by status">
    <option value="">On and off sale</option>
    <option value="active"   <?=($filters['status'] ?? '') === 'active' ? 'selected' : ''?>>On sale</option>
    <option value="inactive" <?=($filters['status'] ?? '') === 'inactive' ? 'selected' : ''?>>Off sale</option>
  </select>
  <input class="input" name="q" value="<?=htmlspecialchars((string)($filters['search'] ?? ''))?>"
         placeholder="Code or name" aria-label="Search the catalogue" style="min-width:12rem">
  <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
</form>

<?php if ($unpriced): ?>
<div class="alert alert-info mb-4">
  <?=number_format($unpriced)?> row<?=$unpriced === 1 ? '' : 's'?> on this page
  <?=$unpriced === 1 ? 'has' : 'have'?> no price. A catalogue sync imports products
  unpriced and switched off on purpose — the vendor knows its cost, not your margin —
  so they stay invisible to customers until you price them here.
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="muted">
      No products match this filter.
      <?php if ($domain === 'numbers' || $domain === 'giftcards'): ?>
        This catalogue is built by syncing a provider — connect one under Providers, run a
        sync, then price what it imports. You can also add a row by hand.
      <?php endif; ?>
    </p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr>
          <th>Product</th>
          <th><?=$domain === 'vtu' ? 'Network' : ($domain === 'numbers' ? 'Country / service'
              : ($domain === 'giftcards' ? 'Brand' : 'ID type'))?></th>
          <th class="text-right">Price</th>
          <th class="text-right">Cost</th>
          <th class="text-right">Margin</th>
          <th>Status</th>
          <?php if ($can_price): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $variable = $domain === 'vtu' && in_array($r->service_type, array('AIRTIME','ELECTRICITY'), true);
        $margin = ($r->price !== null && $r->provider_cost !== null)
            ? bcsub((string)$r->price, (string)$r->provider_cost, 8) : null;
      ?>
        <tr>
          <td>
            <a href="<?=site_url('admin/catalogue/'.$domain.'/'.$r->public_id)?>"><?=htmlspecialchars((string)$r->name)?></a>
            <div class="mono text-xs muted"><?=htmlspecialchars((string)$r->code)?></div>
          </td>
          <td class="text-sm">
            <?php if ($domain === 'vtu'): ?>
              <?=htmlspecialchars((string)$r->network_name)?>
              <div class="text-xs muted"><?=htmlspecialchars((string)$r->service_type)?></div>
            <?php elseif ($domain === 'numbers'): ?>
              <?=htmlspecialchars((string)$r->country_name)?>
              <div class="text-xs muted"><?=htmlspecialchars((string)$r->service_name)?>
                <?php if ($r->stock !== null): ?> · stock <?=number_format((int)$r->stock)?><?php endif; ?></div>
            <?php elseif ($domain === 'giftcards'): ?>
              <?=htmlspecialchars((string)$r->brand_name)?>
              <div class="text-xs muted">
                <?=htmlspecialchars((string)$r->denomination_type)?>
                <?php if ($r->face_value !== null): ?>
                  · <?=htmlspecialchars((string)$r->recipient_currency)?>
                  <?=number_format((float)$r->face_value, 2)?>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <?=htmlspecialchars((string)$r->id_type)?>
              <div class="text-xs muted">by <?=htmlspecialchars(strtolower((string)$r->lookup_field))?></div>
            <?php endif; ?>
          </td>
          <td class="text-right mono">
            <?php if ($variable): ?>
              <span class="muted text-xs">customer names it</span>
              <div class="text-xs muted">less <?=htmlspecialchars(rtrim(rtrim((string)$r->discount_percent, '0'), '.'))?>%</div>
            <?php elseif ($r->price === null): ?>
              <span class="badge badge-warning">no price</span>
            <?php else: ?>
              <?=marvy_money($r->price)?>
            <?php endif; ?>
          </td>
          <td class="text-right mono text-sm muted">
            <?=$r->provider_cost === null ? '—' : marvy_money($r->provider_cost)?>
          </td>
          <td class="text-right mono text-sm">
            <?php if ($margin === null): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="<?=bccomp($margin, '0', 8) < 0 ? 'badge badge-danger' : ''?>"><?=marvy_money($margin)?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r->is_active): ?>
              <span class="badge badge-success">On sale</span>
            <?php else: ?>
              <span class="badge badge-default">Off sale</span>
            <?php endif; ?>
          </td>
          <?php if ($can_price): ?>
          <td class="text-right">
            <form method="post" action="<?=site_url('admin/catalogue/'.$domain.'/'.$r->public_id.'/status')?>">
              <?=$csrf()?>
              <input type="hidden" name="is_active" value="<?=$r->is_active ? '0' : '1'?>">
              <button class="btn btn-ghost btn-sm" type="submit">
                <?=$r->is_active ? 'Take off sale' : 'Put on sale'?>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <nav class="row justify-between mt-4" aria-label="Pagination">
    <a class="btn btn-ghost btn-sm <?=$page <= 1 ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/catalogue/'.$domain.$qs(array('page'=>max(1, $page-1))))?>">← Previous</a>
    <span class="text-sm muted">Page <?=$page?> / <?=$total_pages?></span>
    <a class="btn btn-ghost btn-sm <?=$page >= $total_pages ? 'is-disabled' : ''?>"
       href="<?=site_url('admin/catalogue/'.$domain.$qs(array('page'=>min($total_pages, $page+1))))?>">Next →</a>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($can_price): ?>
<!-- Add product dialog -->
<dialog id="ws-new-product" class="ws-dialog" data-dialog-light-dismiss >
  <form method="post" action="<?=site_url('admin/catalogue/'.$domain.'/create')?>" class="grid" style="gap:.75rem">
    <?=$csrf()?>
    <h3 style="font-size:1.1rem;font-weight:600;margin:0">New <?=htmlspecialchars(strtolower(CatalogueService::label($domain)))?> product</h3>
    <p class="text-xs muted" style="margin:0">
      Most rows arrive from a provider sync. Add one by hand when a vendor's catalogue
      does not list it, or when you sell something the vendor does not name the same way.
    </p>
    <?php $this->load->view('admin/catalogue/_form', array(
        'domain' => $domain, 'options' => $options, 'product' => null)); ?>
    <div class="row" style="justify-content:flex-end">
      <button type="button" class="btn btn-ghost" data-dialog-close="ws-new-product" >Cancel</button>
      <button type="submit" class="btn btn-primary">Create product</button>
    </div>
  </form>
</dialog>

<style>
.ws-dialog{border:0;border-radius:1rem;padding:0;width:min(560px,92vw);box-shadow:0 30px 80px -20px rgba(0,0,0,.4)}
.ws-dialog::backdrop{background:rgba(15,23,42,.55)}
.ws-dialog form{padding:1.5rem}
</style>
<?php endif; ?>
