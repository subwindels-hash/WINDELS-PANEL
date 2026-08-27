<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
?>
<div class="row justify-between mb-4">
  <div><h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Shipping methods</h2>
  <p class="muted text-sm">Offered to customers checking out with a physical item.</p></div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/shop')?>">← Shop</a>
</div>

<div class="card mb-4">
  <?php if (empty($methods)): ?><p class="muted text-sm">No shipping methods yet.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Name</th><th>Carrier</th><th class="text-right">Price</th><th>Days</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($methods as $m): ?>
      <tr>
        <td><?=htmlspecialchars($m->name)?></td>
        <td class="text-xs muted"><?=htmlspecialchars((string)$m->carrier)?></td>
        <td class="text-right mono"><?=marvy_money($m->price, $m->currency)?></td>
        <td class="text-xs"><?=$m->estimated_days_min ? (int)$m->estimated_days_min.'-'.(int)$m->estimated_days_max : '—'?></td>
        <td><?=($m->is_active ? '<span class="badge badge-success badge-dot">Active</span>' : '<span class="badge badge-default">Disabled</span>')?></td>
        <td>
          <form method="post" action="<?=site_url('admin/shop/shipping-methods/'.$m->public_id.'/status')?>" style="display:inline">
            <?=$csrf()?><button class="btn btn-ghost btn-sm" type="submit"><?=$m->is_active ? 'Disable' : 'Enable'?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card" style="max-width:36rem">
  <h3 class="card-title">Add a shipping method</h3>
  <form method="post" action="<?=site_url('admin/shop/shipping-methods/save')?>" class="stack">
    <?=$csrf()?>
    <label class="field"><span class="label">Name</span><input class="input" name="name" required maxlength="120" placeholder="Standard shipping"></label>
    <label class="field"><span class="label">Carrier</span><input class="input" name="carrier" maxlength="80" placeholder="DHL"></label>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Price</span><input class="input mono" type="number" step="0.01" min="0" name="price" required value="0"></label>
      <label class="field" style="flex:1"><span class="label">Min days</span><input class="input mono" type="number" min="0" name="estimated_days_min"></label>
      <label class="field" style="flex:1"><span class="label">Max days</span><input class="input mono" type="number" min="0" name="estimated_days_max"></label>
    </div>
    <button class="btn btn-primary" type="submit">Add</button>
  </form>
</div>
