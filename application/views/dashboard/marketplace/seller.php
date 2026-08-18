<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name()).'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'">';
?>
<div class="row justify-between mb-4" style="flex-wrap:wrap;gap:.5rem">
  <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/marketplace')?>">← Marketplace</a>
  <?php if ($seller && $seller->status === 'APPROVED'): ?><a class="btn btn-secondary btn-sm" href="<?=site_url('dashboard/marketplace/orders?as=SELLER')?>">Sales orders</a><?php endif; ?>
</div>
<?php if (!$seller): ?>
<div class="card" style="max-width:42rem">
  <h2 class="card-title">Apply to sell</h2>
  <p class="muted text-sm">Sellers are reviewed before they can publish.<?=!empty($require_identity) ? ' A completed identity check prevents anonymous marketplace abuse.' : ''?></p>
  <?php if (!empty($require_identity) && empty($checks)): ?>
    <div class="alert alert-warning">You need a verified identity check first. <a href="<?=site_url('dashboard/identity')?>"><strong>Verify identity</strong></a>.</div>
  <?php else: ?>
  <form method="post" action="<?=site_url('dashboard/marketplace/seller/apply')?>">
    <?=$csrf?>
    <label class="label" for="display_name">Store name</label><input class="input mb-4" id="display_name" name="display_name" maxlength="80" required>
    <label class="label" for="bio">What do you sell?</label><textarea class="textarea mb-4" id="bio" name="bio" maxlength="500" rows="4"></textarea>
    <?php if (!empty($checks)): ?>
    <label class="label" for="identity_check_id">Verified identity<?=empty($require_identity) ? ' (optional)' : ''?></label>
    <select class="select mb-4" id="identity_check_id" name="identity_check_id" <?=!empty($require_identity) ? 'required' : ''?>>
      <?php if (empty($require_identity)): ?><option value="">Do not attach an identity check</option><?php endif; ?>
      <?php foreach ($checks as $check): ?><option value="<?=(int)$check->id?>"><?=htmlspecialchars($check->id_type)?> ending <?=htmlspecialchars((string)$check->identifier_last4)?> · <?=htmlspecialchars($check->created_at)?></option><?php endforeach; ?>
    </select>
    <?php else: ?>
    <p class="text-sm muted">Identity verification is currently optional for seller applications.</p>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Submit application</button>
  </form>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card mb-4">
  <div class="row justify-between"><div><h2 class="card-title mb-0"><?=htmlspecialchars($seller->display_name)?></h2><p class="muted text-sm"><?=htmlspecialchars((string)$seller->bio)?></p></div><span class="badge <?=$seller->status === 'APPROVED' ? 'badge-success' : ($seller->status === 'REJECTED' ? 'badge-error' : 'badge-warning')?>"><?=htmlspecialchars($seller->status)?></span></div>
  <?php if ($seller->admin_note): ?><p class="text-sm"><strong>Review note:</strong> <?=htmlspecialchars($seller->admin_note)?></p><?php endif; ?>
</div>
<?php if ($seller->status === 'APPROVED'): ?>
<div class="card mb-4">
  <h3 class="card-title">New listing</h3>
  <form method="post" action="<?=site_url('dashboard/marketplace/seller/listings')?>">
    <?=$csrf?>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:.75rem"><div><label class="label">Title</label><input class="input" name="title" maxlength="120" required></div><div><label class="label">Category</label><input class="input" name="category" value="DIGITAL_GOODS" pattern="[A-Za-z0-9_-]{2,64}" required></div></div>
    <label class="label mt-3">Description</label><textarea class="textarea" name="description" minlength="20" maxlength="10000" rows="5" required></textarea>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem" class="mt-3"><div><label class="label">Unit price</label><input class="input" type="number" step="0.01" min="0.01" name="price" required></div><div><label class="label">Stock <span class="muted">(blank = unlimited)</span></label><input class="input" type="number" min="0" name="stock"></div><div><label class="label">Delivery days</label><input class="input" type="number" min="1" max="30" value="1" name="delivery_days" required></div></div>
    <button class="btn btn-primary mt-4" type="submit">Send for review</button>
  </form>
</div>
<div class="card">
  <h3 class="card-title">Your listings</h3>
  <?php if (empty($listings)): ?><p class="muted">No listings yet.</p><?php endif; ?>
  <?php foreach ($listings as $item): ?>
  <details class="mb-3" style="border-bottom:1px solid #e2e8f0;padding-bottom:.75rem">
    <summary style="cursor:pointer"><strong><?=htmlspecialchars($item->title)?></strong> <span class="badge badge-default"><?=htmlspecialchars($item->status)?></span> <span class="muted"><?=windels_money($item->price)?></span></summary>
    <?php if ($item->moderation_note): ?><p class="text-sm"><strong>Review note:</strong> <?=htmlspecialchars($item->moderation_note)?></p><?php endif; ?>
    <form method="post" action="<?=site_url('dashboard/marketplace/seller/listings/'.$item->public_id)?>" class="mt-3">
      <?=$csrf?>
      <input class="input mb-2" name="title" value="<?=htmlspecialchars($item->title)?>" maxlength="120" required>
      <input class="input mb-2" name="category" value="<?=htmlspecialchars($item->category)?>" required>
      <textarea class="textarea mb-2" name="description" minlength="20" maxlength="10000" rows="4" required><?=htmlspecialchars($item->description)?></textarea>
      <div class="row" style="gap:.5rem;flex-wrap:wrap"><input class="input" type="number" step="0.01" min="0.01" name="price" value="<?=htmlspecialchars($item->price)?>" required><input class="input" type="number" min="0" name="stock" value="<?=htmlspecialchars((string)$item->stock)?>" placeholder="Unlimited"><input class="input" type="number" min="1" max="30" name="delivery_days" value="<?=(int)$item->delivery_days?>" required><button class="btn btn-secondary btn-sm" type="submit">Save and re-submit</button></div>
    </form>
    <?php if (in_array($item->status, array('ACTIVE','PAUSED'), true)): ?>
    <form method="post" action="<?=site_url('dashboard/marketplace/seller/listings/'.$item->public_id.'/status')?>" class="mt-2"><?=$csrf?><button class="btn btn-ghost btn-sm" name="status" value="<?=$item->status === 'ACTIVE' ? 'PAUSED' : 'ARCHIVED'?>" type="submit"><?=$item->status === 'ACTIVE' ? 'Pause' : 'Archive'?></button></form>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
