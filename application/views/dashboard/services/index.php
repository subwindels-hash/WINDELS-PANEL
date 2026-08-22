<?php defined('BASEPATH') OR exit('No direct script access allowed');
$fav_ids = $favorites ?? array();
?>
<div class="grid gap-6 lg:grid-cols-4">
  <aside class="card lg:col-span-1 h-fit">
    <div class="row justify-between">
      <h2 class="card-title mb-0">Categories</h2>
      <?php if (!empty($favorites_only)): ?>
        <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/services')?>">All services</a>
      <?php endif; ?>
    </div>
    <nav class="stack mt-2" style="gap:.25rem">
      <a class="nav-link <?=(empty($active_cat) && empty($favorites_only))?'is-active':''?>" href="<?=site_url('dashboard/services')?>">All services</a>
      <a class="nav-link <?=$favorites_only?'is-active':''?>" href="<?=site_url('dashboard/favorites')?>">
        ★ Favorites
      </a>
      <?php foreach ($categories as $c): ?>
        <a class="nav-link <?=$active_cat===(int)$c->id?'is-active':''?>"
           href="<?=site_url('dashboard/services?category='.$c->id)?>"><?=htmlspecialchars($c->name)?></a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <div class="lg:col-span-3">
    <div class="row justify-between mb-3">
      <h2 class="mb-0" style="font-size:1.25rem;font-weight:600">
        <?=$favorites_only ? 'Your favorites' : 'Services'?>
        <span class="muted text-sm">(<?=count($services)?>)</span>
      </h2>
    </div>

    <?php if (empty($services)): ?>
      <?php $this->load->view('partials/empty_state', array(
          'icon'  => $favorites_only ? 'star' : 'shopping-bag',
          'title' => $favorites_only ? 'No favorites yet' : 'No services available',
          'body'  => $favorites_only
              ? 'Tap the ☆ on a service to keep it here for quick access.'
              : 'Services will appear here as soon as the operator publishes and prices them.',
          'action_href'  => $favorites_only ? site_url('dashboard/services') : '',
          'action_label' => 'Browse services',
      )); ?>
    <?php else: ?>
    <div class="grid grid-3" style="gap:1rem">
      <?php foreach ($services as $s):
        $id = (int)$s->id;
        $is_fav = !empty($fav_ids[$id]);
      ?>
      <div class="card card-hover">
        <div class="row justify-between">
          <span class="badge badge-default"><?=htmlspecialchars($s->category_name ?? $s->service_type)?></span>
          <form method="post" action="<?=site_url(($is_fav?'dashboard/favorites/remove/':'dashboard/favorites/add/').$s->public_id)?>">
            <input type="hidden" name="<?=htmlspecialchars($this->security->get_csrf_token_name())?>" value="<?=htmlspecialchars($this->security->get_csrf_hash())?>" readonly>
            <button class="btn btn-ghost btn-sm" type="submit" title="<?=$is_fav?'Remove favorite':'Add favorite'?>" style="padding:.25rem .5rem;font-size:1rem">
              <?=$is_fav ? '★' : '☆'?>
            </button>
          </form>
        </div>
        <h3 class="card-title mt-2">
          <a class="text-slate-900 hover:text-brand-700" href="<?=site_url('services/'.$s->slug)?>"><?=htmlspecialchars($s->name)?></a>
        </h3>
        <p class="muted text-sm"><?=htmlspecialchars($s->average_time ?: '—')?></p>
        <div class="row justify-between mt-2">
          <strong style="color:var(--brand-700)"><?=windels_money($s->rate)?> / 1k</strong>
          <a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/new-order?service='.$s->public_id)?>">Order</a>
        </div>
        <p class="hint"><?=number_format($s->min_quantity)?> – <?=number_format($s->max_quantity)?> units</p>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
