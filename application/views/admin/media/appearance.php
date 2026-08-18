<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };

/** A radio grid of library images, with the current choice pre-selected. */
$picker = function ($field, $current) use ($images) {
    ?>
    <div class="row" style="gap:.5rem;flex-wrap:wrap;max-height:16rem;overflow-y:auto">
      <label class="card" style="padding:.4rem;min-width:6rem;text-align:center;cursor:pointer">
        <input type="radio" name="<?=htmlspecialchars($field)?>" value="" <?=empty($current) ? 'checked' : ''?>>
        <div class="muted text-xs mt-1">None</div>
      </label>
      <?php foreach ($images as $img): ?>
        <label class="card" style="padding:.4rem;min-width:6rem;text-align:center;cursor:pointer">
          <input type="radio" name="<?=htmlspecialchars($field)?>"
                 value="<?=htmlspecialchars($img->public_id)?>"
                 <?=(string)$current === (string)$img->url ? 'checked' : ''?>>
          <img src="<?=htmlspecialchars($img->url)?>" alt="<?=htmlspecialchars($img->file_name)?>"
               loading="lazy"
               style="width:100%;height:3.5rem;object-fit:contain;margin-top:.25rem">
        </label>
      <?php endforeach; ?>
    </div>
    <?php
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Appearance</h2>
    <p class="muted text-sm">Logo, favicon and brand colour</p>
  </div>
</div>

<?php $this->load->view('admin/system/_tabs', array('tabs'=>$tabs,'area'=>$area)); ?>

<?php if (empty($images)): ?>
<div class="alert alert-info mb-4">
  No images in the library yet. Upload one from
  <a href="<?=site_url('admin/media')?>">Media</a> and it will appear here to choose from.
</div>
<?php endif; ?>

<form method="post" action="<?=site_url('admin/appearance/save')?>">
  <?=$csrf()?>

  <div class="card mb-4">
    <h3 style="font-size:1rem;font-weight:600" class="mb-1">Brand colour</h3>
    <p class="muted text-xs mb-3">Used for buttons and accents across the panel and the homepages.</p>
    <div class="row" style="gap:.5rem;align-items:center">
      <input class="input" type="color" name="brand_primary_color"
             value="<?=htmlspecialchars($branding['brand_primary_color'] ?: '#6366f1')?>"
             style="width:4rem;padding:.2rem" aria-label="Brand colour">
      <span class="mono text-xs muted"><?=htmlspecialchars($branding['brand_primary_color'] ?: '#6366f1')?></span>
    </div>
  </div>

  <div class="card mb-4">
    <h3 style="font-size:1rem;font-weight:600" class="mb-1">Logo</h3>
    <p class="muted text-xs mb-3">
      Shown in the sidebar and on the homepages. Chosen from the library, so the stored value is
      always a URL this panel produced.
    </p>
    <?php $picker('brand_logo_url', $branding['brand_logo_url']); ?>
  </div>

  <div class="card mb-4">
    <h3 style="font-size:1rem;font-weight:600" class="mb-1">Favicon</h3>
    <p class="muted text-xs mb-3">A small square image works best.</p>
    <?php $picker('brand_favicon_url', $branding['brand_favicon_url']); ?>
  </div>

  <div class="row justify-between" style="align-items:center;flex-wrap:wrap;gap:.5rem">
    <span class="muted text-xs">Changes apply immediately across the panel.</span>
    <button class="btn btn-primary" type="submit">Save appearance</button>
  </div>
</form>
