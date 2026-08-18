<?php defined('BASEPATH') OR exit('No direct script access allowed');
$total_pages = max(1, (int)ceil($total / $per_page));
$query = function ($over = array()) use ($filters, $page) {
    $q = array_merge(array('q'=>$filters['search'], 'category'=>$filters['category'], 'page'=>$page), $over);
    return '?'.http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== null; }));
};
?>
<div class="row justify-between mb-4" style="align-items:flex-start;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="card-title mb-0">Digital marketplace</h2>
    <p class="muted text-sm">Buy from approved sellers. Payment stays in escrow until delivery is accepted.</p>
  </div>
  <div class="row" style="gap:.5rem">
    <a class="btn btn-ghost btn-sm" href="<?=site_url('dashboard/marketplace/orders')?>">My purchases</a>
    <a class="btn btn-secondary btn-sm" href="<?=site_url('dashboard/marketplace/seller')?>"><?=($seller && $seller->status === 'APPROVED') ? 'Seller workspace' : 'Become a seller'?></a>
  </div>
</div>
<form method="get" action="<?=site_url('dashboard/marketplace')?>" class="card mb-4">
  <div class="row" style="gap:.5rem;flex-wrap:wrap">
    <input class="input" style="flex:1;min-width:14rem" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>" placeholder="Search marketplace">
    <input class="input" name="category" value="<?=htmlspecialchars((string)$filters['category'])?>" placeholder="Category">
    <button class="btn btn-primary" type="submit">Search</button>
  </div>
</form>
<?php if (empty($listings)): ?>
<div class="card"><p class="muted">No active listings match this search.</p></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1rem">
<?php foreach ($listings as $item): ?>
  <article class="card" style="display:flex;flex-direction:column">
    <div class="row justify-between"><span class="badge badge-default"><?=htmlspecialchars($item->category)?></span><span class="text-xs muted"><?=($item->stock === null ? 'Available' : (int)$item->stock.' left')?></span></div>
    <h3 class="font-semibold mt-3"><?=htmlspecialchars($item->title)?></h3>
    <p class="text-sm muted" style="flex:1"><?=htmlspecialchars(mb_strimwidth($item->description, 0, 150, '…'))?></p>
    <p class="text-xs muted">Sold by <?=htmlspecialchars($item->seller_name)?> · delivery within <?=(int)$item->delivery_days?> day(s)</p>
    <div class="row justify-between mt-3"><strong><?=windels_money($item->price)?></strong><a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/marketplace/'.$item->public_id)?>">View</a></div>
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
