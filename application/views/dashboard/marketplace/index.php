<?php defined('BASEPATH') OR exit('No direct script access allowed');
$total_pages = max(1, (int)ceil($total / $per_page));
$query = function ($over = array()) use ($filters, $page) {
    $q = array_merge(array('q'=>$filters['search'], 'category'=>$filters['category'], 'page'=>$page), $over);
    return '?'.http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== null; }));
};
// The storefront's effective price is the promo price only when it genuinely
// undercuts the list price. The service double-checks at purchase time.
$effective = function ($item) {
    if ($item->promo_price !== null && (float)$item->promo_price > 0 && bccomp($item->promo_price, $item->price, 8) < 0) {
        return array($item->promo_price, $item->price);
    }
    return array($item->price, null);
};
$price_badges = function ($item) use ($effective) {
    list($now, $was) = $effective($item);
    $html = '<strong>'.windels_money($now).'</strong>';
    if ($was !== null) {
        $html .= ' <span class="text-xs muted" style="text-decoration:line-through">'.windels_money($was).'</span> <span class="badge badge-warning">Promo</span>';
    }
    return $html;
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="card-title mb-0">Digital marketplace</h2>
    <p class="muted text-sm">Official WINDELS catalogue — the platform curates, prices and fulfils every product. Payment stays in escrow until delivery is accepted.</p>
  </div>
  <div class="row" style="gap:.5rem">
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/marketplace/orders')?>">My purchases</a>
  </div>
</div>
<?php if (!empty($featured)): ?>
<div class="mb-4">
  <h3 class="card-title">Featured</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1rem">
  <?php foreach ($featured as $item): ?>
    <a href="<?=site_url('dashboard/marketplace/'.$item->public_id)?>" style="text-decoration:none;color:inherit">
    <article class="card" style="display:flex;flex-direction:column;height:100%;border:1px solid #fde68a;background:#fffbeb">
      <?php if (!empty($item->image)): ?><img alt="<?=htmlspecialchars($item->title)?>" src="<?=base_url($item->image)?>" style="width:100%;height:8rem;object-fit:cover;border-radius:.5rem"><?php endif; ?>
      <div class="row justify-between mt-3"><span class="badge badge-warning">Featured</span><span class="badge badge-default"><?=htmlspecialchars($item->category)?></span></div>
      <h3 class="font-semibold mt-3"><?=htmlspecialchars($item->title)?></h3>
      <div class="mt-3"><?=$price_badges($item)?></div>
    </article>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<form method="get" action="<?=site_url('dashboard/marketplace')?>" class="card mb-4">
  <div class="row" style="gap:.5rem;flex-wrap:wrap">
    <input class="input" style="flex:1;min-width:14rem" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>" placeholder="Search the marketplace">
    <select class="input" name="category">
      <option value="">All categories</option>
      <?php foreach ($categories as $category): ?>
      <option value="<?=htmlspecialchars($category->slug)?>" <?=$filters['category'] === $category->slug ? 'selected' : ''?>><?=htmlspecialchars($category->name)?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Search</button>
  </div>
</form>
<?php if (empty($listings)): ?>
<div class="card"><p class="muted">No active listings match this search.</p></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1rem">
<?php foreach ($listings as $item): ?>
  <article class="card" style="display:flex;flex-direction:column">
    <?php if (!empty($item->image)): ?><img alt="<?=htmlspecialchars($item->title)?>" src="<?=base_url($item->image)?>" style="width:100%;height:9rem;object-fit:cover;border-radius:.5rem;margin-bottom:.75rem"><?php endif; ?>
    <div class="row justify-between"><span class="badge badge-default"><?=htmlspecialchars($item->category)?></span><span class="text-xs muted"><?=($item->stock === null ? 'Available' : (int)$item->stock.' left')?></span></div>
    <h3 class="font-semibold mt-3"><?=htmlspecialchars($item->title)?></h3>
    <p class="text-sm muted" style="flex:1"><?=htmlspecialchars(mb_strimwidth($item->description, 0, 150, '…'))?></p>
    <p class="text-xs muted">Sold by <?=htmlspecialchars($item->seller_name)?> · <?=($item->product_type === 'PHYSICAL' ? 'ships' : 'delivery').' within'?><?=' '.(int)$item->delivery_days?> day(s)</p>
    <div class="row justify-between mt-3"><span><?=$price_badges($item)?></span><a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/marketplace/'.$item->public_id)?>">View</a></div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($total_pages > 1): ?>
<div class="row justify-between mt-4">
  <?=$page > 1 ? '<a class="btn btn-ghost btn-sm" href="'.site_url('dashboard/marketplace'.$query(array('page'=>$page-1))).'">← Previous</a>' : '<span></span>'?>
  <span class="muted text-sm">Page <?=$page?> of <?=$total_pages?></span>
  <?=$page < $total_pages ? '<a class="btn btn-ghost btn-sm" href="'.site_url('dashboard/marketplace'.$query(array('page'=>$page+1))).'">Next →</a>' : '<span></span>'?>
</div>
<?php endif; ?>
