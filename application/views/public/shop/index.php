<?php defined('BASEPATH') OR exit('No direct script access allowed');
$f = $filters;
$effective = function ($item) {
    if ($item->promo_price !== null && (float)$item->promo_price > 0 && bccomp($item->promo_price, $item->price, 8) < 0) {
        return array($item->promo_price, $item->price);
    }
    return array($item->price, null);
};
$type_labels = array('' => 'All', 'DIGITAL' => 'Digital Products', 'PHYSICAL' => 'Physical Products');
?>
<section class="ws-page-hero">
  <div class="container" style="max-width:900px">
    <p class="ws-kicker">Shop</p>
    <h1>Digital products, physical goods and gift cards</h1>
    <p class="ws-lede">One prepaid wallet, one checkout. Every listing is sold and fulfilled directly by MarvySocials.</p>
    <form method="get" action="<?=site_url('shop')?>" class="row mt-4" style="gap:.5rem;max-width:32rem">
      <div class="ws-searchwrap">
        <?php $this->load->view('partials/icon', array('name'=>'search','class'=>'w-5 h-5')); ?>
        <label class="sr-only" for="shop-search">Search products</label>
        <input class="input" id="shop-search" type="search" name="q" value="<?=htmlspecialchars((string)$f['search'])?>" placeholder="Search products…">
      </div>
      <button class="btn btn-primary" type="submit">Search</button>
    </form>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container" style="max-width:1200px">
    <div class="row mb-4" style="gap:.4rem;flex-wrap:wrap">
      <a class="btn btn-sm <?=($f['category']==='' && $f['type']==='') ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('shop')?>">All</a>
      <a class="btn btn-sm <?=$f['type']==='DIGITAL' ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('shop?type=DIGITAL')?>">Digital Products</a>
      <a class="btn btn-sm <?=$f['type']==='PHYSICAL' ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('shop?type=PHYSICAL')?>">Physical Products</a>
      <a class="btn btn-sm btn-ghost" href="<?=site_url('shop/gift-cards')?>">Gift Cards</a>
      <?php foreach ($categories as $c): ?>
        <a class="btn btn-sm <?=$f['category']===$c->slug ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('shop?category='.rawurlencode($c->slug))?>"><?=htmlspecialchars($c->name)?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($featured) && $f['search'] === '' && $f['category'] === '' && $f['type'] === ''): ?>
    <h2 class="ws-section-title mb-3">Featured</h2>
    <div class="ws-landing-cards mb-6">
      <?php foreach ($featured as $item): list($now, $was) = $effective($item); ?>
      <a class="ws-landing-service card card-hover" href="<?=site_url('shop/product/'.$item->public_id)?>">
        <?php if (!empty($item->image)): ?><img alt="<?=htmlspecialchars($item->title)?>" src="<?=base_url($item->image)?>" style="width:100%;height:9rem;object-fit:cover;border-radius:.75rem;margin-bottom:.75rem"><?php endif; ?>
        <span class="badge badge-warning">Featured</span>
        <span class="badge badge-default"><?=htmlspecialchars($item->product_type === 'PHYSICAL' ? 'Physical' : 'Digital')?></span>
        <h3 class="card-title mt-2"><?=htmlspecialchars($item->title)?></h3>
        <div class="mt-2"><strong><?=marvy_money($now)?></strong>
          <?php if ($was !== null): ?> <span class="text-xs muted" style="text-decoration:line-through"><?=marvy_money($was)?></span><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="ws-section-title mb-3"><?=number_format($total)?> product<?=$total===1?'':'s'?></h2>
    <?php if (empty($listings)): ?>
      <div class="card text-center" style="padding:3rem">
        <p class="muted">No products match this search yet.</p>
        <a class="btn btn-secondary mt-4" href="<?=site_url('shop')?>">Clear filters</a>
      </div>
    <?php else: ?>
    <div class="ws-landing-cards">
      <?php foreach ($listings as $item): list($now, $was) = $effective($item); ?>
      <article class="card card-hover" style="display:flex;flex-direction:column">
        <a href="<?=site_url('shop/product/'.$item->public_id)?>" style="text-decoration:none;color:inherit">
          <?php if (!empty($item->image)): ?><img alt="<?=htmlspecialchars($item->title)?>" src="<?=base_url($item->image)?>" style="width:100%;height:9rem;object-fit:cover;border-radius:.75rem;margin-bottom:.75rem"><?php endif; ?>
          <div class="row justify-between">
            <span class="badge badge-default"><?=htmlspecialchars($item->category)?></span>
            <span class="badge badge-default"><?=htmlspecialchars($item->product_type === 'PHYSICAL' ? 'Physical' : 'Digital')?></span>
          </div>
          <h3 class="card-title mt-2"><?=htmlspecialchars($item->title)?></h3>
        </a>
        <p class="muted text-sm" style="flex:1"><?=htmlspecialchars(mb_strimwidth($item->description, 0, 100, '…'))?></p>
        <p class="text-xs muted"><?=($item->stock === null ? 'In stock' : ((int)$item->stock > 0 ? (int)$item->stock.' left' : 'Sold out'))?></p>
        <div class="row justify-between mt-2">
          <span><strong><?=marvy_money($now)?></strong>
            <?php if ($was !== null): ?><br><span class="text-xs muted" style="text-decoration:line-through"><?=marvy_money($was)?></span><?php endif; ?>
          </span>
          <?php if ($item->stock === null || (int)$item->stock > 0): ?>
          <form method="post" action="<?=site_url('cart/add')?>">
            <input type="hidden" name="<?=$this->security->get_csrf_token_name()?>" value="<?=$this->security->get_csrf_hash()?>">
            <input type="hidden" name="listing" value="<?=htmlspecialchars($item->public_id)?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="redirect_to" value="shop">
            <button class="btn btn-primary btn-sm" type="submit">Add to Cart</button>
          </form>
          <?php else: ?>
          <span class="badge badge-danger">Sold out</span>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if ($total > $per_page): ?>
    <nav class="row justify-between mt-6" aria-label="Pagination">
      <a class="btn btn-ghost btn-sm <?=$page<=1?'is-disabled':''?>" href="<?=site_url('shop?'.http_build_query(array_merge($f,array('page'=>max(1,$page-1)))))?>">← Previous</a>
      <span class="text-sm muted">Page <?=$page?> of <?=max(1,(int)ceil($total/$per_page))?></span>
      <a class="btn btn-ghost btn-sm <?=$page>=ceil($total/$per_page)?'is-disabled':''?>" href="<?=site_url('shop?'.http_build_query(array_merge($f,array('page'=>$page+1))))?>">Next →</a>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
