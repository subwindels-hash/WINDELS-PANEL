<?php defined('BASEPATH') OR exit('No direct script access allowed');
$csrf = function () {
    return '<input type="hidden" name="'.htmlspecialchars($this->security->get_csrf_token_name())
        .'" value="'.htmlspecialchars($this->security->get_csrf_hash()).'" readonly>';
};
?>
<div class="row justify-between mb-4">
  <div><h2 class="mb-0" style="font-size:1.4rem;font-weight:600">Reviews</h2>
  <p class="muted text-sm"><?=number_format($total)?> review(s) — verified purchases only.</p></div>
  <a class="btn btn-ghost btn-sm" href="<?=site_url('admin/shop')?>">← Shop</a>
</div>

<div class="row mb-4" style="gap:.4rem">
  <a class="btn btn-sm <?=empty($filters['status']) ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('admin/shop/reviews')?>">All</a>
  <a class="btn btn-sm <?=($filters['status'] ?? '') === 'PENDING' ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('admin/shop/reviews?status=PENDING')?>">Pending</a>
  <a class="btn btn-sm <?=($filters['status'] ?? '') === 'APPROVED' ? 'btn-primary' : 'btn-ghost'?>" href="<?=site_url('admin/shop/reviews?status=APPROVED')?>">Approved</a>
</div>

<div class="card">
  <?php if (empty($reviews)): ?><p class="muted text-sm">No reviews match this filter.</p>
  <?php else: ?>
  <?php foreach ($reviews as $r): ?>
    <div class="mb-3" style="border-bottom:1px solid var(--color-border);padding-bottom:.75rem">
      <div class="row justify-between">
        <div><strong><?=str_repeat('★', (int)$r->rating)?></strong> <?=htmlspecialchars((string)$r->listing_title)?> — <span class="text-xs muted"><?=htmlspecialchars((string)$r->username)?></span></div>
        <span class="badge badge-default"><?=htmlspecialchars($r->status)?></span>
      </div>
      <?php if ($r->title): ?><div class="font-medium mt-1"><?=htmlspecialchars($r->title)?></div><?php endif; ?>
      <?php if ($r->body): ?><p class="text-sm muted mt-1"><?=htmlspecialchars($r->body)?></p><?php endif; ?>
      <?php if ($r->status === 'PENDING'): ?>
      <div class="row mt-2" style="gap:.4rem">
        <form method="post" action="<?=site_url('admin/shop/reviews/'.$r->public_id.'/moderate')?>"><?=$csrf()?><input type="hidden" name="decision" value="APPROVED"><button class="btn btn-primary btn-sm" type="submit">Approve</button></form>
        <form method="post" action="<?=site_url('admin/shop/reviews/'.$r->public_id.'/moderate')?>"><?=$csrf()?><input type="hidden" name="decision" value="REJECTED"><button class="btn btn-secondary btn-sm" type="submit">Reject</button></form>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
