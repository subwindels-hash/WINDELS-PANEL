<?php defined('BASEPATH') OR exit('No direct script access allowed');
$l = $listing; // null = create
$old = function ($key, $default = '') use ($l) { return $l !== null ? ($l->{$key} ?? $default) : $default; };
?>
<div class="row justify-between mb-4">
  <div><h2 class="card-title mb-0"><?=$l ? 'Edit listing' : 'New listing'?></h2>
  <p class="muted text-sm">Platform catalogue entry — you are selling on behalf of MARVYSOCIALS. Buyers always pay the server-side price saved here.</p></div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/marketplace?tab=listings')?>">← Listings</a>
</div>
<form class="card" style="max-width:46rem" method="post" enctype="multipart/form-data"
      action="<?=site_url($l ? 'admin/marketplace/listings/'.$l->public_id.'/save' : 'admin/marketplace/listings/save')?>">
  <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
  <label class="label" for="title">Title</label>
  <input class="input mb-3" id="title" name="title" maxlength="140" required value="<?=htmlspecialchars((string)$old('title'))?>">
  <label class="label" for="category">Category</label>
  <select class="select mb-3" id="category" name="category" required>
    <option value="">Choose a category…</option>
    <?php foreach ($categories as $category): ?>
    <option value="<?=htmlspecialchars($category->slug)?>" <?=$old('category') === $category->slug ? 'selected' : ''?>><?=htmlspecialchars($category->name)?> (<?=htmlspecialchars($category->slug)?>)</option>
    <?php endforeach; ?>
  </select>
  <label class="label" for="description">Description</label>
  <textarea class="textarea mb-3" id="description" name="description" rows="8" maxlength="10000" required><?=htmlspecialchars((string)$old('description'))?></textarea>
  <div class="row" style="gap:1rem;flex-wrap:wrap">
    <div style="flex:1;min-width:12rem">
      <label class="label" for="price">Price (NGN)</label>
      <input class="input mb-3" id="price" name="price" inputmode="decimal" required value="<?=htmlspecialchars((string)$old('price'))?>">
    </div>
    <div style="flex:1;min-width:12rem">
      <label class="label" for="promo_price">Promotional price (optional)</label>
      <input class="input mb-3" id="promo_price" name="promo_price" inputmode="decimal" placeholder="Leave empty for none" value="<?=htmlspecialchars((string)$old('promo_price'))?>">
      <p class="text-xs muted mb-3">When set below the list price, buyers pay the promo price.</p>
    </div>
  </div>
  <div class="row" style="gap:1rem;flex-wrap:wrap">
    <div style="flex:1;min-width:10rem">
      <label class="label" for="stock">Stock (empty = unlimited)</label>
      <input class="input mb-3" id="stock" name="stock" type="number" min="0" value="<?=htmlspecialchars((string)$old('stock'))?>">
    </div>
    <div style="flex:1;min-width:10rem">
      <label class="label" for="delivery_days">Delivery window (days)</label>
      <input class="input mb-3" id="delivery_days" name="delivery_days" type="number" min="0" max="90" required value="<?=htmlspecialchars((string)$old('delivery_days', 3))?>">
    </div>
    <div style="flex:1;min-width:10rem">
      <label class="label" for="product_type">Fulfilment type</label>
      <select class="select mb-3" id="product_type" name="product_type">
        <option value="DIGITAL" <?=$old('product_type', 'DIGITAL') === 'DIGITAL' ? 'selected' : ''?>>Digital (secure delivery)</option>
        <option value="PHYSICAL" <?=$old('product_type') === 'PHYSICAL' ? 'selected' : ''?>>Physical (shipped)</option>
      </select>
    </div>
  </div>
  <label class="label" for="image">Shelf image (optional)</label>
  <?php if ($l && !empty($l->image)): ?><div class="mb-2"><img alt="" src="<?=base_url($l->image)?>" style="width:6rem;height:6rem;object-fit:cover;border-radius:.5rem"></div><?php endif; ?>
  <input class="input mb-3" id="image" name="image" type="file" accept="image/*">
  <p class="text-xs muted mb-3">Uploaded images are validated and re-encoded before being stored.</p>
  <label class="row mb-4" style="gap:.5rem;align-items:center">
    <input type="checkbox" name="is_featured" value="1" <?=$l && (int)$l->is_featured === 1 ? 'checked' : ''?>>
    <span>Show on the storefront's featured shelf</span>
  </label>
  <div class="row" style="gap:.5rem">
    <button class="btn btn-primary" type="submit"><?=$l ? 'Save changes' : 'Publish listing'?></button>
    <a class="btn btn-ghost" href="<?=site_url('admin/marketplace?tab=listings')?>">Cancel</a>
  </div>
</form>

<?php if ($l && strtoupper((string)$l->product_type) === 'DIGITAL'): ?>
<div class="card mt-4" style="max-width:46rem">
  <h3 class="card-title">Downloadable file</h3>
  <p class="muted text-sm mb-3">
    Uploaded to private storage — never a public URL. A customer can only reach it through an
    authenticated, expiring, audited download link after payment clears.
  </p>
  <?php if (!empty($digital_product)): ?>
    <p class="text-sm mb-3">
      Current file: <strong><?=htmlspecialchars($digital_product->original_filename)?></strong>
      (<?=number_format($digital_product->size_bytes / 1048576, 2)?> MB)
    </p>
  <?php else: ?>
    <p class="muted text-sm mb-3">No file attached yet — this listing cannot be automatically delivered until one is uploaded.</p>
  <?php endif; ?>
  <form method="post" action="<?=site_url('admin/marketplace/listings/'.$l->public_id.'/digital-file')?>" enctype="multipart/form-data" class="row" style="gap:.5rem;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
    <label class="field mb-0" style="flex:1;min-width:16rem"><span class="label">Choose file (max 200 MB)</span>
      <input class="input" type="file" name="file" required></label>
    <label class="field mb-0"><span class="label">Download limit (blank = unlimited)</span>
      <input class="input mono" type="number" min="1" name="download_limit" style="width:9rem"
             value="<?=htmlspecialchars((string)($digital_product->download_limit ?? ''))?>"></label>
    <label class="field mb-0"><span class="label">Link valid for (hours)</span>
      <input class="input mono" type="number" min="1" name="link_ttl_hours" style="width:9rem"
             value="<?=htmlspecialchars((string)($digital_product->link_ttl_hours ?? 168))?>"></label>
    <button class="btn btn-primary" type="submit">Upload</button>
  </form>
</div>
<?php endif; ?>

<?php if ($l && strtoupper((string)$l->product_type) === 'PHYSICAL'): ?>
<div class="card mt-4" style="max-width:46rem">
  <h3 class="card-title">Shipping details</h3>
  <p class="muted text-sm mb-3">
    SKU, weight and package dimensions used by the shipping method calculator at checkout and shown
    to fulfilment staff on the shipment queue. Required before this listing can be sold.
  </p>
  <?php if (empty($physical_product)): ?>
    <p class="muted text-sm mb-3">No SKU set yet — this listing cannot be fulfilled correctly until one is saved.</p>
  <?php endif; ?>
  <form method="post" action="<?=site_url('admin/marketplace/listings/'.$l->public_id.'/physical')?>" class="row" style="gap:.5rem;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
    <label class="field mb-0" style="min-width:12rem"><span class="label">SKU</span>
      <input class="input mono" type="text" name="sku" maxlength="64" required
             value="<?=htmlspecialchars((string)($physical_product->sku ?? ''))?>"></label>
    <label class="field mb-0"><span class="label">Weight (grams)</span>
      <input class="input mono" type="number" min="0" name="weight_grams" style="width:9rem"
             value="<?=htmlspecialchars((string)($physical_product->weight_grams ?? ''))?>"></label>
    <label class="field mb-0"><span class="label">Length (cm)</span>
      <input class="input mono" type="number" min="0" step="0.01" name="length_cm" style="width:8rem"
             value="<?=htmlspecialchars((string)($physical_product->length_cm ?? ''))?>"></label>
    <label class="field mb-0"><span class="label">Width (cm)</span>
      <input class="input mono" type="number" min="0" step="0.01" name="width_cm" style="width:8rem"
             value="<?=htmlspecialchars((string)($physical_product->width_cm ?? ''))?>"></label>
    <label class="field mb-0"><span class="label">Height (cm)</span>
      <input class="input mono" type="number" min="0" step="0.01" name="height_cm" style="width:8rem"
             value="<?=htmlspecialchars((string)($physical_product->height_cm ?? ''))?>"></label>
    <label class="row mb-0" style="gap:.4rem;align-items:center">
      <input type="checkbox" name="requires_shipping" value="1"
             <?=(!isset($physical_product->requires_shipping) || (int)$physical_product->requires_shipping === 1) ? 'checked' : ''?>>
      <span>Requires shipping</span>
    </label>
    <button class="btn btn-primary" type="submit">Save shipping details</button>
  </form>
</div>
<?php endif; ?>
