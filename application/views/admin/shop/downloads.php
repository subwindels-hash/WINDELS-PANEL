<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
?>
<div class="row justify-between mb-4">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Digital downloads</h2>
    <p class="muted text-sm"><?=number_format($total)?> delivery record(s).</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/shop')?>">← Shop</a>
</div>

<div class="card">
  <?php if (empty($downloads)): ?>
    <p class="muted text-sm">No digital deliveries yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table class="table">
    <thead><tr><th>Customer</th><th>Product</th><th>Downloads</th><th>Status</th><th>Granted</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($downloads as $d): ?>
      <tr>
        <td class="text-xs"><div class="font-medium text-slate-900"><?=htmlspecialchars((string)$d->username)?></div><div class="muted"><?=htmlspecialchars((string)$d->email)?></div></td>
        <td class="text-xs"><?=htmlspecialchars((string)$d->listing_title)?></td>
        <td class="mono text-xs"><?=(int)$d->download_count?><?=$d->download_limit !== null ? ' / '.(int)$d->download_limit : ''?></td>
        <td>
          <?php if ((int)$d->revoked === 1): ?><span class="badge badge-danger">Revoked</span>
          <?php else: ?><span class="badge badge-success badge-dot">Available</span><?php endif; ?>
        </td>
        <td class="text-xs muted"><?=htmlspecialchars(date('M j, H:i', strtotime($d->created_at)))?></td>
        <td>
          <?php if ((int)$d->revoked === 1): ?>
            <form method="post" action="<?=site_url('admin/shop/downloads/'.$d->public_id.'/restore')?>" style="display:inline">
              <?=$csrf()?><button class="btn btn-secondary btn-sm" type="submit">Restore</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?=site_url('admin/shop/downloads/'.$d->public_id.'/revoke')?>" class="row" style="gap:.3rem;display:inline-flex">
              <?=$csrf()?>
              <input class="input" type="text" name="reason" placeholder="Reason" style="max-width:10rem">
              <button class="btn btn-secondary btn-sm" type="submit">Revoke</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
