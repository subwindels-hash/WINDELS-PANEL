<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name()).'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'">';
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">My Downloads</h2>
    <p class="muted text-sm">Digital products you have purchased. Each download link is single-use and time-limited for your protection.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('shop')?>">Browse shop</a>
</div>

<?php if (empty($downloads)): ?>
  <div class="card text-center" style="padding:3rem">
    <p class="muted">You have not purchased any digital products yet.</p>
    <a class="btn btn-primary mt-4" href="<?=site_url('shop')?>">Browse the shop</a>
  </div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead><tr><th>Product</th><th>File</th><th>Downloads used</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($downloads as $d): ?>
      <tr>
        <td>
          <div class="font-medium"><?=htmlspecialchars($d->listing_title)?></div>
          <div class="text-xs muted">Order <?=htmlspecialchars($d->order_public_id)?></div>
        </td>
        <td class="text-xs mono"><?=htmlspecialchars($d->original_filename)?>
          <div class="muted"><?=number_format(($d->size_bytes ?? 0) / 1048576, 2)?> MB</div>
        </td>
        <td class="mono text-xs"><?=(int)$d->download_count?><?=$d->download_limit !== null ? ' / '.(int)$d->download_limit : ''?></td>
        <td>
          <?php if ((int)$d->revoked === 1): ?>
            <span class="badge badge-danger">Revoked</span>
            <?php if (!empty($d->revoked_reason)): ?><div class="text-xs muted"><?=htmlspecialchars($d->revoked_reason)?></div><?php endif; ?>
          <?php else: ?>
            <span class="badge badge-success badge-dot">Available</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((int)$d->revoked !== 1): ?>
          <form method="post" action="<?=site_url('dashboard/downloads/'.$d->public_id.'/link')?>" style="margin:0">
            <?=$csrf?>
            <button class="btn btn-primary btn-sm" type="submit">Download</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
