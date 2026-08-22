<?php defined('BASEPATH') OR exit('No direct script access allowed');
$f = $filters;
$base = site_url('services');
if (!function_exists('ws_query_string')) {
    function ws_query_string($overrides, $current) {
        return http_build_query(array_merge(array_filter($current, function($v){ return $v !== '' && $v !== null; }), $overrides));
    }
}
?>
<?php
$product_areas = $product_areas ?? array();
$how_it_works = $how_it_works ?? array();
$advantages = $advantages ?? array();
$security_practices = $security_practices ?? array();
$show_marketing = !empty($show_marketing);
?>
<?php if ($show_marketing): ?>
<section class="ws-page-hero">
  <div class="container" style="max-width:800px">
    <p class="ws-kicker">What you can buy</p>
    <h1>One wallet for SMM, bills and digital goods</h1>
    <p class="ws-lede">WINDELS PANEL is for creators, agencies and resellers who want prepaid checkout, a live catalogue and a staff-run back office. It solves scattered provider logins and untracked wallet movement — not by promising fake volume numbers.</p>
    <div class="row" style="margin-top:1.25rem">
      <a class="btn btn-primary" href="<?=site_url('register')?>">Create an account</a>
      <a class="btn btn-secondary" href="#catalogue">Jump to catalogue</a>
    </div>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container">
    <h2>Product areas</h2>
    <p class="muted">Only areas the operator has enabled and priced are actually buyable.</p>
    <div class="grid grid-3 mt-4">
      <?php foreach ($product_areas as $area): ?>
      <article class="card" style="padding-top:0;overflow:hidden">
        <?php
          $visual = array(
            'smm' => 'services/smm.jpg',
            'vtu' => 'services/vtu.jpg',
            'numbers' => 'services/numbers.jpg',
            'identity' => 'services/identity.jpg',
            'giftcards' => 'services/giftcards.jpg',
            'marketplace' => 'services/marketplace.jpg',
            'api' => 'services/marketplace.jpg',
          );
          $img = isset($visual[$area['id']]) ? $visual[$area['id']] : null;
        ?>
        <?php if ($img): ?>
          <img class="ws-visual-card" src="<?=base_url('assets/images/'.$img)?>"
               alt="<?=htmlspecialchars($area['name'])?> — visual of this product area"
               width="640" height="400" loading="lazy">
        <?php endif; ?>
        <h3 class="card-title"><?=htmlspecialchars($area['name'])?></h3>
        <p class="hint"><?=htmlspecialchars($area['audience'])?></p>
        <p><?=htmlspecialchars($area['summary'])?></p>
        <ul class="hint" style="padding-left:1.1rem">
          <?php foreach (array_slice($area['capabilities'], 0, 3) as $cap): ?>
            <li><?=htmlspecialchars($cap)?></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-secondary btn-sm" href="<?=site_url($area['href'])?>"><?=htmlspecialchars($area['cta'])?></a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="py-10" id="catalogue">
  <div class="container" style="max-width:1200px">
    <header class="text-center mb-8">
      <h1><?=$show_marketing ? 'SMM catalogue' : 'Services'?></h1>
      <p class="muted">Browse <?=number_format($total)?> published SMM services. Pricing is frozen at checkout. Other product lines live in the signed-in dashboard.</p>
    </header>

    <!-- Search + filters -->
    <form method="get" action="<?=site_url('services')?>" class="card mb-6">
      <div class="grid" style="grid-template-columns:1fr;gap:.75rem">
        <div class="row" style="gap:.5rem">
          <div class="ws-searchwrap">
            <?php $this->load->view('partials/icon', array('name'=>'search','class'=>'w-5 h-5')); ?>
            <input class="input" type="search" name="q" value="<?=htmlspecialchars($f['q'])?>"
                   placeholder="Search services — e.g. Instagram followers">
          </div>
          <button class="btn btn-primary" type="submit">Search</button>
        </div>
        <div class="row" style="gap:.5rem;flex-wrap:wrap">
          <select class="select" name="category" style="width:auto">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?=htmlspecialchars($c->slug)?>" <?=($f['category']===$c->slug)?'selected':''?>><?=htmlspecialchars($c->name)?></option>
            <?php endforeach; ?>
          </select>
          <select class="select" name="platform" style="width:auto">
            <option value="">All platforms</option>
            <?php foreach ($platforms as $p): ?>
              <option value="<?=htmlspecialchars($p)?>" <?=($f['platform']===$p)?'selected':''?>><?=htmlspecialchars(ucfirst($p))?></option>
            <?php endforeach; ?>
          </select>
          <select class="select" name="type" style="width:auto">
            <option value="">All types</option>
            <?php foreach ($types as $t): ?>
              <option value="<?=htmlspecialchars($t)?>" <?=($f['type']===$t)?'selected':''?>><?=htmlspecialchars(str_replace('_',' ',ucwords(strtolower($t))))?></option>
            <?php endforeach; ?>
          </select>
          <select class="select" name="sort" style="width:auto">
            <?php foreach (array('popular'=>'Most popular','price_asc'=>'Price: low to high','price_desc'=>'Price: high to low','name'=>'Name A–Z','newest'=>'Newest') as $k=>$lbl): ?>
              <option value="<?=$k?>" <?=($f['sort']===$k)?'selected':''?>><?=htmlspecialchars($lbl)?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($f['q'] || $f['category'] || $f['platform'] || $f['type']): ?>
            <a class="btn btn-ghost btn-sm" href="<?=site_url('services')?>">Clear</a>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <?php if (empty($services)): ?>
      <div class="card text-center" style="padding:3rem">
        <p class="muted">No services match your filters.</p>
        <a class="btn btn-secondary mt-4" href="<?=site_url('services')?>">Clear filters</a>
      </div>
    <?php else: ?>
    <div class="grid grid-4" style="gap:1rem">
      <?php foreach ($services as $s):
        $flags = json_decode($s->metadata ?? '', true) ?: array();
      ?>
      <article class="card card-hover">
        <div class="row justify-between">
          <span class="badge badge-default"><?=htmlspecialchars($s->category_name ?? $s->service_type)?></span>
          <?php if ((int)$s->trending): ?><span class="badge badge-danger">🔥 Trending</span><?php endif; ?>
        </div>
        <h3 class="card-title mt-2">
          <a href="<?=site_url('services/'.$s->slug)?>" class="text-slate-900 hover:text-brand-700"><?=htmlspecialchars($s->name)?></a>
        </h3>
        <p class="muted text-sm" style="min-height:2.5em">
          <?=htmlspecialchars(mb_strimwidth($s->description ?? '', 0, 80, '…'))?>
        </p>
        <dl class="row text-xs muted" style="gap:.75rem;margin:.5rem 0">
          <span>⏱ <?=htmlspecialchars($s->average_time ?: '—')?></span>
          <?php if ((int)$s->refill_supported): ?><span class="badge badge-success" style="padding:.1rem .4rem">refill</span><?php endif; ?>
          <?php if ((int)$s->cancel_supported): ?><span class="badge badge-info" style="padding:.1rem .4rem">cancel</span><?php endif; ?>
        </dl>
        <div class="row justify-between mt-2">
          <strong style="color:var(--brand-700);font-size:1.1rem"><?=windels_money($s->rate)?> <span class="muted" style="font-weight:400;font-size:.75rem">/ 1k</span></strong>
          <a class="btn btn-primary btn-sm" href="<?=site_url('services/'.$s->slug)?>">Order</a>
        </div>
        <p class="hint"><?=number_format($s->min_quantity)?> – <?=number_format($s->max_quantity)?> units</p>
      </article>
      <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1):
      $make_link = function($p) use ($f) {
        return site_url('services?'.ws_query_string(array('page'=>$p), $f));
      };
    ?>
    <nav class="row justify-between mt-6" aria-label="Pagination">
      <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="<?=$make_link(max(1,$page-1))?>">← Previous</a>
      <span class="text-sm muted">Page <?=$page?> of <?=$total_pages?> · <?=number_format($total)?> services</span>
      <a class="btn btn-ghost btn-sm <?=$page>=$total_pages?'is-disabled':''?>" href="<?=$make_link(min($total_pages,$page+1))?>">Next →</a>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<style>
.ws-searchwrap{position:relative;flex:1;display:flex;align-items:center}
.ws-searchwrap svg{position:absolute;left:.75rem;color:var(--slate-400)}
.ws-searchwrap .input{padding-left:2.5rem}
@media(max-width:560px){.ws-searchwrap{width:100%}}
</style>
