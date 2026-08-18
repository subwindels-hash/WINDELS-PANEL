<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Keep this POST-form partial self-contained when rendered outside the editor.
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
?>
<section id="pricing" class="card mt-6 scroll-mt-6">
  <h2 class="card-title">Price overrides</h2>
  <p class="text-sm muted mt-1">Runtime precedence remains customer override → price-group override → default selling rate. Leave a rate blank and save to restore fallback pricing.</p>

  <?php if (!$can_price): ?>
    <div class="alert alert-warning mt-4 mb-0">You need the pricing management permission to change overrides.</div>
  <?php else: ?>
  <div class="grid gap-6 lg:grid-cols-2 mt-5">
    <div>
      <h3 class="font-semibold">Price groups</h3>
      <div class="stack mt-3" style="gap:.75rem">
      <?php foreach ($group_rates as $group): ?>
        <form method="post" action="<?=site_url('admin/services/'.$s->public_id.'/pricing/group/'.$group->id)?>" class="rounded border p-3">
          <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
          <div class="row justify-between" style="gap:1rem"><div><strong><?=htmlspecialchars($group->name)?></strong><?php if ((int)$group->is_default): ?> <span class="badge badge-info">default group</span><?php endif; ?></div>
            <div class="row" style="gap:.5rem"><input class="input mono" style="width:11rem" name="rate" inputmode="decimal" value="<?=htmlspecialchars((string)($group->service_rate ?? ''))?>" placeholder="falls back"><button class="btn btn-secondary btn-sm" type="submit">Save</button></div></div>
        </form>
      <?php endforeach; ?>
      </div>
    </div>

    <div>
      <h3 class="font-semibold">Customer-specific</h3>
      <form method="post" action="<?=site_url('admin/services/'.$s->public_id.'/pricing/user')?>" class="rounded border p-3 mt-3">
        <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
        <label class="field"><span>Customer public ID</span><input class="input mono" name="user_public_id" maxlength="26" required placeholder="USR…"></label>
        <label class="field mt-3"><span>Rate</span><input class="input mono" name="rate" inputmode="decimal" required placeholder="0.00000000"></label>
        <button class="btn btn-secondary btn-sm mt-3" type="submit">Add or update override</button>
      </form>

      <div class="stack mt-3" style="gap:.75rem">
      <?php if (!$user_rates): ?><p class="text-sm muted">No customer-specific rates.</p><?php endif; ?>
      <?php foreach ($user_rates as $row): ?>
        <form method="post" action="<?=site_url('admin/services/'.$s->public_id.'/pricing/user')?>" class="rounded border p-3">
          <input type="hidden" name="<?=htmlspecialchars($csrf_name)?>" value="<?=htmlspecialchars($csrf_hash)?>" readonly>
          <input type="hidden" name="user_public_id" value="<?=htmlspecialchars($row->user_public_id)?>" readonly>
          <div class="row justify-between" style="gap:1rem"><div><strong><?=htmlspecialchars($row->username)?></strong><div class="text-xs muted"><?=htmlspecialchars($row->email)?> · <span class="mono"><?=htmlspecialchars($row->user_public_id)?></span></div></div>
            <div class="row" style="gap:.5rem"><input class="input mono" style="width:11rem" name="rate" inputmode="decimal" value="<?=htmlspecialchars($row->rate)?>"><button class="btn btn-secondary btn-sm" type="submit">Save</button></div></div>
          <small>Clear the rate and save to remove this override.</small>
        </form>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</section>
