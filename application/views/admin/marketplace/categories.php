<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf_name = $this->security->get_csrf_token_name(); $csrf_hash = $this->security->get_csrf_hash();
?>
<div class="row justify-between mb-4">
  <div><h2 class="card-title mb-0">Marketplace categories</h2>
  <p class="muted text-sm">Listings may only be filed under ACTIVE categories. Archiving one hides it from new listings; existing listings keep working.</p></div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/marketplace?tab=listings')?>">← Marketplace</a>
</div>
<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:1rem;align-items:start">
  <div class="card overflow-x-auto">
    <table class="table">
      <thead><tr><th>Name</th><th>Slug</th><th>Sort</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($categories as $category): ?>
      <tr>
        <td><strong><?=htmlspecialchars($category->name)?></strong></td>
        <td class="mono text-xs"><?=htmlspecialchars($category->slug)?></td>
        <td><?=(int)$category->sort_order?></td>
        <td><span class="badge badge-default"><?=htmlspecialchars($category->status)?></span></td>
        <td>
          <form method="post" action="<?=site_url('admin/marketplace/categories/'.$category->public_id.'/save')?>" class="row mb-2" style="gap:.3rem;flex-wrap:wrap">
            <input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>">
            <input class="input" style="width:10rem" name="name" maxlength="80" value="<?=htmlspecialchars($category->name)?>" required>
            <input class="input" style="width:8rem" name="slug" maxlength="64" value="<?=htmlspecialchars($category->slug)?>" required>
            <button class="btn btn-secondary btn-sm">Save</button>
          </form>
          <form method="post" action="<?=site_url('admin/marketplace/categories/'.$category->public_id.'/status')?>">
            <input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>">
            <input type="hidden" name="status" value="<?=$category->status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE'?>">
            <button class="btn btn-ghost btn-sm"><?=$category->status === 'ACTIVE' ? 'Archive' : 'Reactivate'?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <h3 class="card-title">New category</h3>
    <form method="post" action="<?=site_url('admin/marketplace/categories/save')?>">
      <input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>">
      <label class="label" for="name">Name</label>
      <input class="input mb-3" id="name" name="name" maxlength="80" required>
      <label class="label" for="slug">Slug (unique key)</label>
      <input class="input mb-3" id="slug" name="slug" maxlength="64" placeholder="GIFT_CARDS" required>
      <label class="label" for="sort_order">Sort order</label>
      <input class="input mb-3" id="sort_order" name="sort_order" type="number" value="0">
      <button class="btn btn-primary" type="submit">Create category</button>
    </form>
  </div>
</div>
