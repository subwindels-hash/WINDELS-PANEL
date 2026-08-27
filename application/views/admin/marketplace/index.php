<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf_name = $this->security->get_csrf_token_name(); $csrf_hash = $this->security->get_csrf_hash();
$total_pages = max(1, (int)ceil($total / $per_page));
$can_moderate_listings = in_array('*', $permissions ?? array(), true)
    || in_array('marketplace.moderate_listings', $permissions ?? array(), true);
?>
<div class="row justify-between mb-4" style="flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 class="card-title mb-0">Marketplace operations</h2>
    <p class="muted text-sm">Curate, price and fulfil the platform storefront from here. <?=number_format($total)?> result(s)</p>
  </div>
  <div class="row" style="gap:.4rem;align-items:center;flex-wrap:wrap">
    <?php if ($can_manage): ?>
    <a class="btn btn-primary btn-sm" href="<?=site_url('admin/marketplace/listings/new')?>">+ New listing</a>
    <a class="btn btn-secondary btn-sm" href="<?=site_url('admin/marketplace/categories')?>">Categories</a>
    <?php endif; ?>
    <form method="get" action="<?=site_url('admin/marketplace')?>" class="row" style="gap:.4rem">
      <input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>">
      <input class="input" name="q" value="<?=htmlspecialchars((string)$filters['search'])?>" placeholder="Search reference or title">
      <input class="input" name="status" value="<?=htmlspecialchars((string)$filters['status'])?>" placeholder="Status">
      <button class="btn btn-secondary btn-sm">Filter</button>
    </form>
  </div>
</div>
<div class="row mb-4" style="gap:.4rem"><?php foreach (array('orders'=>'Escrow orders','listings'=>'Listings','analytics'=>'Analytics') as $key=>$label): ?><a class="btn btn-sm <?=$tab === $key ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('admin/marketplace?tab='.$key)?>"><?=$label?></a><?php endforeach; ?></div>
<?php if ($tab === 'analytics'): ?>
<?php $a = $analytics; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1rem">
  <div class="card"><h3 class="card-title">Gross merchandise value</h3><p style="font-size:1.6rem;font-weight:700"><?=marvy_money($a['gmv'])?></p><p class="text-xs muted">All marketplace orders, all statuses.</p></div>
  <div class="card"><h3 class="card-title">Released from escrow</h3><p style="font-size:1.6rem;font-weight:700"><?=marvy_money($a['released'])?></p><p class="text-xs muted">Completed orders only.</p></div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem">
  <div class="card"><h3 class="card-title">Orders by status</h3><?php if (empty($a['by_status'])): ?><p class="muted">No orders yet.</p><?php else: ?><table class="table"><tbody><?php foreach ($a['by_status'] as $row): ?><tr><td><span class="badge badge-default"><?=htmlspecialchars($row->status)?></span></td><td class="text-right"><?=number_format((int)$row->n)?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><h3 class="card-title">Listings by status</h3><?php if (empty($a['listings'])): ?><p class="muted">No listings yet.</p><?php else: ?><table class="table"><tbody><?php foreach ($a['listings'] as $row): ?><tr><td><span class="badge badge-default"><?=htmlspecialchars($row->status)?></span></td><td class="text-right"><?=number_format((int)$row->n)?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><h3 class="card-title">Top 5 listings by orders</h3><?php if (empty($a['top_listings'])): ?><p class="muted">No sales yet.</p><?php else: ?><table class="table"><tbody><?php foreach ($a['top_listings'] as $row): ?><tr><td><?=htmlspecialchars($row->title)?></td><td class="text-right"><?=number_format((int)$row->n)?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
</div>
<?php else: ?>
<div class="card overflow-x-auto">
<?php if (empty($rows)): ?><p class="muted">No records match this filter.</p>
<?php elseif ($tab === 'orders'): ?><table class="table"><thead><tr><th>Order</th><th>Listing</th><th>Buyer</th><th class="text-right">Gross</th><th>Status</th><th>Created</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><a class="mono text-xs" href="<?=site_url('admin/marketplace/orders/'.$row->public_id)?>"><?=htmlspecialchars($row->public_id)?></a></td><td><?=htmlspecialchars((string)$row->listing_title)?></td><td><?=htmlspecialchars((string)$row->buyer_username)?></td><td class="text-right"><?=marvy_money($row->gross_amount)?></td><td><span class="badge badge-default"><?=htmlspecialchars($row->status)?></span></td><td class="text-xs muted"><?=htmlspecialchars($row->created_at)?></td></tr><?php endforeach; ?></tbody></table>
<?php else: ?>
<?php if ($can_manage): ?>
<form method="post" action="<?=site_url('admin/marketplace/listings/bulk')?>" id="bulk-listings-form"
      onsubmit="return confirm('Apply this action to every checked listing?');">
<input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>">
<div class="row mb-2" style="gap:.5rem;align-items:center;flex-wrap:wrap">
  <select class="select btn-sm" name="bulk_action" required>
    <option value="">Bulk action…</option>
    <option value="publish">Publish</option>
    <option value="unpublish">Unpublish</option>
    <option value="archive">Archive</option>
    <option value="feature">Feature</option>
    <option value="unfeature">Unfeature</option>
  </select>
  <button class="btn btn-secondary btn-sm" type="submit">Apply to selected</button>
  <span class="text-xs muted">Check listings below, choose an action, apply. Each listing is still validated and audited individually.</span>
</div>
<?php endif; ?>
<table class="table"><thead><tr>
  <?php if ($can_manage): ?><th style="width:1.5rem"><input type="checkbox" onclick="document.querySelectorAll('.listing-check').forEach(function(c){c.checked=this.checked}.bind(this))"></th><?php endif; ?>
  <th>Listing</th><th>Price</th><th>Stock</th><th>Type</th><th>Status</th><th>Actions</th>
</tr></thead><tbody><?php foreach ($rows as $row): ?><tr>
<?php if ($can_manage): ?><td><input class="listing-check" type="checkbox" name="listing_ids[]" value="<?=htmlspecialchars($row->public_id)?>" form="bulk-listings-form"></td><?php endif; ?>
<td><div class="row" style="gap:.5rem;align-items:center"><?php if (!empty($row->image)): ?><img alt="" src="<?=base_url($row->image)?>" style="width:2.5rem;height:2.5rem;object-fit:cover;border-radius:.4rem"><?php endif; ?><div><strong><?=htmlspecialchars($row->title)?></strong><?php if ((int)$row->is_featured === 1): ?> <span class="badge badge-warning">Featured</span><?php endif; ?><div class="mono text-xs muted"><?=htmlspecialchars($row->public_id)?></div><div class="text-xs muted"><?=htmlspecialchars($row->category)?></div></div></div></td><td><?=marvy_money($row->price)?><?php if ($row->promo_price !== null && (float)$row->promo_price > 0 && bccomp($row->promo_price, $row->price, 8) < 0): ?><div class="text-xs"><span class="badge badge-warning">Promo <?=marvy_money($row->promo_price)?></span></div><?php endif; ?></td><td><?=$row->stock === null ? '∞' : (int)$row->stock?></td><td><span class="badge badge-default"><?=htmlspecialchars($row->product_type)?></span></td><td><span class="badge badge-default"><?=htmlspecialchars($row->status)?></span></td><td><?php if ($can_manage): ?><div class="row" style="gap:.3rem;flex-wrap:wrap"><a class="btn btn-secondary btn-sm" href="<?=site_url('admin/marketplace/listings/'.$row->public_id.'/edit')?>">Edit</a><form method="post" action="<?=site_url('admin/marketplace/listings/'.$row->public_id.'/status')?>"><input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>"><input type="hidden" name="status" value="<?=$row->status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE'?>"><button class="btn btn-ghost btn-sm"><?=$row->status === 'ACTIVE' ? 'Unpublish' : 'Publish'?></button></form><form method="post" action="<?=site_url('admin/marketplace/listings/'.$row->public_id.'/status')?>" onsubmit="return confirm('Archive this listing? It disappears from the storefront.');"><input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>"><input type="hidden" name="status" value="ARCHIVED"><button class="btn btn-ghost btn-sm">Archive</button></form><form method="post" action="<?=site_url('admin/marketplace/listings/'.$row->public_id.'/feature')?>"><input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>"><input type="hidden" name="featured" value="<?=(int)$row->is_featured === 1 ? '0' : '1'?>"><button class="btn btn-ghost btn-sm"><?=(int)$row->is_featured === 1 ? 'Unfeature' : 'Feature'?></button></form></div><?php endif; ?><?php if ($can_moderate_listings): ?><form method="post" action="<?=site_url('admin/marketplace/listings/'.$row->public_id.'/moderate')?>" class="row" style="gap:.3rem;flex-wrap:wrap;margin-top:.3rem"><input type="hidden" name="<?=$csrf_name?>" value="<?=$csrf_hash?>"><input class="input" name="note" value="<?=htmlspecialchars((string)$row->moderation_note)?>" placeholder="Moderation note"><select class="select" name="status"><option>ACTIVE</option><option>REJECTED</option><option>PAUSED</option><option>ARCHIVED</option></select><button class="btn btn-secondary btn-sm">Moderate</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
<?php if ($can_manage): ?></form><?php endif; ?>
<?php endif; ?>
</div>
<?php if ($total_pages > 1): ?><div class="row justify-between mt-4"><?=$page > 1 ? '<a class="btn btn-ghost btn-sm" href="'.site_url('admin/marketplace?'.http_build_query(array('tab'=>$tab,'page'=>$page-1,'status'=>$filters['status'],'q'=>$filters['search']))).'">← Previous</a>' : '<span></span>'?><span class="muted text-sm">Page <?=$page?> of <?=$total_pages?></span><?=$page < $total_pages ? '<a class="btn btn-ghost btn-sm" href="'.site_url('admin/marketplace?'.http_build_query(array('tab'=>$tab,'page'=>$page+1,'status'=>$filters['status'],'q'=>$filters['search']))).'">Next →</a>' : '<span></span>'?></div><?php endif; ?>
<?php endif; ?>
