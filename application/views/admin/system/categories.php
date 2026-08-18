<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () { return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
    .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>'; };
$by_id = array();
foreach ($categories as $c) $by_id[(int)$c->id] = $c;
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Service categories</h2>
    <p class="muted text-sm">How services are grouped on the storefront</p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('ws-new-cat').showModal()">+ New category</button>
</div>

<?php $this->load->view('admin/system/_tabs', array('tabs'=>$tabs,'area'=>$area)); ?>

<div class="card">
  <?php if (empty($categories)): ?>
    <p class="muted">No categories yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th style="width:3rem">#</th><th>Name</th><th>Platform</th><th>Parent</th>
            <th class="text-right">Services</th><th>Visible</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td class="mono text-xs muted"><?=(int)$c->sorting?></td>
          <td>
            <div class="font-medium"><?=htmlspecialchars((string)$c->name)?></div>
            <div class="text-xs muted mono">/<?=htmlspecialchars((string)$c->slug)?></div>
          </td>
          <td class="text-xs muted"><?=htmlspecialchars((string)($c->platform ?: '—'))?></td>
          <td class="text-xs muted">
            <?=!empty($c->parent_id) && isset($by_id[(int)$c->parent_id])
                ? htmlspecialchars($by_id[(int)$c->parent_id]->name) : '—'?>
          </td>
          <td class="text-right mono text-xs"><?=number_format((int)$c->service_count)?></td>
          <td>
            <span class="badge <?=$c->is_active ? 'badge-success' : 'badge-default'?>">
              <?=$c->is_active ? 'shown' : 'hidden'?>
            </span>
          </td>
          <td>
            <button class="btn btn-ghost btn-sm"
                    onclick="document.getElementById('ws-cat-<?=(int)$c->id?>').showModal()">Edit</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php
/** One editor dialog, reused for create and edit. */
$form = function ($c) use ($csrf, $categories) {
    $id     = $c ? (int)$c->id : 0;
    $dialog = $c ? 'ws-cat-'.$id : 'ws-new-cat';
    $action = $c ? site_url('admin/categories/'.$c->public_id.'/save') : site_url('admin/categories/save');
    ?>
    <dialog id="<?=$dialog?>" class="ws-dialog">
      <form method="post" action="<?=$action?>">
        <?=$csrf()?>
        <h3 style="font-size:1rem;font-weight:600" class="mb-3"><?=$c ? 'Edit category' : 'New category'?></h3>

        <div class="field" style="margin-bottom:.75rem">
          <label class="label">Name</label>
          <input class="input" name="name" required maxlength="128"
                 value="<?=htmlspecialchars((string)($c->name ?? ''))?>">
        </div>
        <div class="row" style="gap:.5rem;flex-wrap:wrap">
          <div class="field" style="flex:1;min-width:11rem">
            <label class="label">Slug</label>
            <input class="input mono" name="slug" maxlength="128"
                   value="<?=htmlspecialchars((string)($c->slug ?? ''))?>"
                   placeholder="from the name">
          </div>
          <div class="field" style="flex:1;min-width:9rem">
            <label class="label">Platform</label>
            <input class="input" name="platform" maxlength="32"
                   value="<?=htmlspecialchars((string)($c->platform ?? ''))?>"
                   placeholder="instagram">
          </div>
          <div class="field" style="flex:1;min-width:7rem">
            <label class="label">Sort</label>
            <input class="input mono" type="number" name="sorting" value="<?=(int)($c->sorting ?? 0)?>">
          </div>
        </div>
        <div class="field" style="margin-bottom:.75rem">
          <label class="label">Parent</label>
          <select class="select" name="parent_id">
            <option value="">Top level</option>
            <?php foreach ($categories as $opt): ?>
              <?php if ($c && (int)$opt->id === (int)$c->id) continue; ?>
              <option value="<?=(int)$opt->id?>"
                <?=$c && (int)$c->parent_id === (int)$opt->id ? 'selected' : ''?>>
                <?=htmlspecialchars($opt->name)?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin-bottom:.75rem">
          <label class="label">Description</label>
          <textarea class="input" name="description" rows="2"><?=htmlspecialchars((string)($c->description ?? ''))?></textarea>
        </div>
        <label class="row" style="gap:.5rem;align-items:center;margin-bottom:1rem">
          <input type="checkbox" name="is_active" value="1" <?=(!$c || !empty($c->is_active)) ? 'checked' : ''?>>
          <span class="label" style="margin:0">Visible on the storefront</span>
        </label>

        <div class="row" style="gap:.5rem;justify-content:flex-end">
          <button class="btn btn-ghost btn-sm" type="button"
                  onclick="document.getElementById('<?=$dialog?>').close()">Cancel</button>
          <button class="btn btn-primary btn-sm" type="submit">Save</button>
        </div>
      </form>

      <?php if ($c): ?>
        <form method="post" action="<?=site_url('admin/categories/'.$c->public_id.'/delete')?>"
              style="margin-top:.75rem;border-top:1px solid #e2e8f0;padding-top:.75rem">
          <?=$csrf()?>
          <div class="row justify-between" style="align-items:center;gap:.5rem">
            <span class="muted text-xs">
              <?=(int)$c->service_count?> service<?=(int)$c->service_count === 1 ? '' : 's'?> in this category.
              Deleting is refused while any remain.
            </span>
            <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
          </div>
        </form>
      <?php endif; ?>
    </dialog>
    <?php
};
$form(null);
foreach ($categories as $c) $form($c);
?>
