<?php defined('BASEPATH') OR exit('No direct script access allowed');
$perms = $permissions ?? array();
$has   = function ($k) use ($perms) { return in_array('*', $perms, true) || in_array($k, $perms, true); };
$csrf  = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$can_price = $has('pricing.manage');

$variable = $domain === 'vtu' && in_array($product->service_type, array('AIRTIME','ELECTRICITY'), true);
$margin = ($product->price !== null && $product->provider_cost !== null)
    ? bcsub((string)$product->price, (string)$product->provider_cost, 8) : null;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <a class="text-sm muted" href="<?=site_url('admin/catalogue/'.$domain)?>">← <?=htmlspecialchars(CatalogueService::label($domain))?></a>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600"><?=htmlspecialchars((string)$product->name)?></h2>
    <p class="muted text-sm">
      <span class="mono"><?=htmlspecialchars((string)$product->code)?></span> ·
      <?php if ($product->is_active): ?>
        <span class="badge badge-success">On sale</span>
      <?php else: ?>
        <span class="badge badge-default">Off sale</span>
      <?php endif; ?>
      <?php if ($product->price === null && !$variable): ?>
        <span class="badge badge-warning">no price</span>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($can_price): ?>
  <form method="post" action="<?=site_url('admin/catalogue/'.$domain.'/'.$product->public_id.'/status')?>">
    <?=$csrf()?>
    <input type="hidden" name="is_active" value="<?=$product->is_active ? '0' : '1'?>">
    <button class="btn <?=$product->is_active ? 'btn-secondary' : 'btn-primary'?>" type="submit">
      <?=$product->is_active ? 'Take off sale' : 'Put on sale'?>
    </button>
  </form>
  <?php endif; ?>
</div>

<div class="grid grid-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Pricing today</h3>
    <table class="table">
      <tbody>
        <?php if ($variable): ?>
          <tr><th>Price</th><td class="muted">Customer names the amount</td></tr>
          <tr><th>Discount</th><td class="mono"><?=htmlspecialchars(rtrim(rtrim((string)$product->discount_percent, '0'), '.'))?>%</td></tr>
          <tr><th>Limits</th><td class="mono">
            <?=$product->min_amount === null ? 'no minimum' : windels_money($product->min_amount)?>
            –
            <?=$product->max_amount === null ? 'no maximum' : windels_money($product->max_amount)?>
          </td></tr>
        <?php else: ?>
          <tr><th>Price</th><td class="mono">
            <?=$product->price === null ? '<span class="badge badge-warning">not set</span>' : windels_money($product->price)?>
          </td></tr>
          <tr><th>Vendor cost</th><td class="mono">
            <?=$product->provider_cost === null ? '—' : windels_money($product->provider_cost)?>
          </td></tr>
          <tr><th>Margin</th><td class="mono">
            <?php if ($margin === null): ?>—<?php else: ?>
              <span class="<?=bccomp($margin, '0', 8) < 0 ? 'badge badge-danger' : ''?>"><?=windels_money($margin)?></span>
            <?php endif; ?>
          </td></tr>
        <?php endif; ?>
        <tr><th>Sort order</th><td class="mono"><?=(int)$product->sorting?></td></tr>
        <tr><th>Last changed</th><td class="text-xs muted"><?=htmlspecialchars((string)$product->updated_at)?></td></tr>
      </tbody>
    </table>
    <?php if (!$product->is_active): ?>
      <p class="text-xs muted">
        Off-sale rows are invisible to customers everywhere — storefront, dashboard and
        API — and the buying path refuses them even by direct code.
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 class="text-sm font-semibold mb-2">Edit</h3>
    <?php if (!$can_price): ?>
      <p class="muted text-sm">You can see what is on sale but not change it — that needs
        the <span class="mono">pricing.manage</span> permission.</p>
    <?php else: ?>
    <form method="post" action="<?=site_url('admin/catalogue/'.$domain.'/'.$product->public_id.'/update')?>"
          class="grid" style="gap:.75rem">
      <?=$csrf()?>
      <?php $this->load->view('admin/catalogue/_form', array(
          'domain' => $domain, 'options' => $options, 'product' => $product)); ?>
      <div class="row" style="justify-content:flex-end">
        <a class="btn btn-ghost" href="<?=site_url('admin/catalogue/'.$domain)?>">Cancel</a>
        <button type="submit" class="btn btn-primary">Save changes</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
