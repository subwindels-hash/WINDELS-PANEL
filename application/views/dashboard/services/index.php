<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-4">
  <aside class="card lg:col-span-1 h-fit">
    <h2 class="card-title">Categories</h2>
    <nav class="stack mt-2" style="gap:.25rem">
      <a class="nav-link <?=empty($active_cat)?'is-active':''?>" href="<?=site_url('dashboard/services')?>">All services</a>
      <?php foreach ($categories as $c): ?>
        <a class="nav-link <?=$active_cat===(int)$c->id?'is-active':''?>"
           href="<?=site_url('dashboard/services?category='.$c->id)?>"><?=htmlspecialchars($c->name)?></a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <div class="lg:col-span-3">
    <div class="row justify-between mb-3">
      <h2 class="mb-0" style="font-size:1.25rem;font-weight:600">
        <?=empty($active_cat) ? 'All services' : 'Services'?>
        <span class="muted text-sm">(<?=count($services)?>)</span>
      </h2>
    </div>

    <?php if (empty($services)): ?>
      <div class="card"><p class="muted">No services available yet.</p></div>
    <?php else: ?>
    <div class="grid grid-3" style="gap:1rem">
      <?php foreach (array_slice($services,0,30) as $s): ?>
      <div class="card card-hover">
        <div class="row justify-between">
          <span class="badge badge-default"><?=htmlspecialchars($s->service_type)?></span>
          <?php if ((int)$s->refill_supported): ?><span class="badge badge-success">refill</span><?php endif; ?>
        </div>
        <h3 class="card-title mt-2"><?=htmlspecialchars($s->name)?></h3>
        <p class="muted text-sm"><?=htmlspecialchars($s->average_time ?: '—')?></p>
        <div class="row justify-between mt-2">
          <strong style="color:var(--brand-700)"><?=windels_money($s->rate)?> / 1k</strong>
          <a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/new-order')?>">Order</a>
        </div>
        <p class="hint"><?=number_format($s->min_quantity)?> – <?=number_format($s->max_quantity)?> units</p>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
