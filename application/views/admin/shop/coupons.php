<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
?>
<div class="row justify-between mb-4">
  <div><h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Coupons</h2>
  <p class="muted text-sm"><?=number_format($total)?> coupon(s).</p></div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/shop')?>">← Shop</a>
</div>

<div class="card mb-4">
  <?php if (empty($coupons)): ?><p class="muted text-sm">No coupons yet.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Code</th><th>Discount</th><th>Used</th><th>Window</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($coupons as $c): ?>
      <tr>
        <td class="mono"><?=htmlspecialchars($c->code)?><?php if ($c->description): ?><div class="text-xs muted"><?=htmlspecialchars($c->description)?></div><?php endif; ?></td>
        <td class="text-xs"><?=$c->discount_type === 'PERCENT' ? number_format((float)$c->discount_value,2).'%' : marvy_money($c->discount_value, $c->currency)?></td>
        <td class="mono text-xs"><?=(int)$c->times_used?><?=$c->usage_limit !== null ? ' / '.(int)$c->usage_limit : ''?></td>
        <td class="text-xs muted">
          <?=$c->starts_at ? htmlspecialchars(date('M j', strtotime($c->starts_at))) : '—'?> –
          <?=$c->ends_at ? htmlspecialchars(date('M j, Y', strtotime($c->ends_at))) : 'no end'?>
        </td>
        <td><?=($c->is_active ? '<span class="badge badge-success badge-dot">Active</span>' : '<span class="badge badge-default">Disabled</span>')?></td>
        <td>
          <form method="post" action="<?=site_url('admin/shop/coupons/'.$c->public_id.'/status')?>" style="display:inline">
            <?=$csrf()?><input type="hidden" name="active" value="<?=$c->is_active ? '0' : '1'?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?=$c->is_active ? 'Disable' : 'Enable'?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card" style="max-width:40rem">
  <h3 class="card-title">Create a coupon</h3>
  <form method="post" action="<?=site_url('admin/shop/coupons/save')?>" class="stack">
    <?=$csrf()?>
    <label class="field"><span class="label">Code</span><input class="input mono" name="code" required maxlength="32" placeholder="SUMMER10" style="text-transform:uppercase"></label>
    <label class="field"><span class="label">Description (optional)</span><input class="input" name="description" maxlength="255"></label>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Type</span>
        <select class="select" name="discount_type"><option value="PERCENT">Percent off</option><option value="FIXED">Fixed amount off</option></select>
      </label>
      <label class="field" style="flex:1"><span class="label">Value</span><input class="input mono" type="number" step="0.01" min="0" name="discount_value" required></label>
    </div>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Minimum order (optional)</span><input class="input mono" type="number" step="0.01" min="0" name="min_order_amount"></label>
      <label class="field" style="flex:1"><span class="label">Max discount cap (optional)</span><input class="input mono" type="number" step="0.01" min="0" name="max_discount_amount"></label>
      <label class="field" style="flex:1"><span class="label">Usage limit (optional)</span><input class="input mono" type="number" min="0" name="usage_limit"></label>
    </div>
    <div class="row" style="gap:.75rem">
      <label class="field" style="flex:1"><span class="label">Starts (optional)</span><input class="input" type="date" name="starts_at"></label>
      <label class="field" style="flex:1"><span class="label">Ends (optional)</span><input class="input" type="date" name="ends_at"></label>
    </div>
    <label class="row" style="gap:.5rem;align-items:center"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label>
    <button class="btn btn-primary" type="submit">Create coupon</button>
  </form>
</div>
