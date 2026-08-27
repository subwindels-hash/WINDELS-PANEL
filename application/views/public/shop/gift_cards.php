<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Public gift-card showcase. Buying itself always goes through the existing,
 * already-audited dashboard/Giftcards flow (GiftcardService) — this page is a
 * storefront window onto that real catalogue, not a second purchase path.
 */
?>
<section class="ws-page-hero">
  <div class="container" style="max-width:900px">
    <p class="ws-kicker">Shop</p>
    <h1>Gift cards</h1>
    <p class="ws-lede">Top brands, paid from your wallet, delivered to your dashboard the moment payment clears.</p>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container" style="max-width:1100px">
    <?php if (empty($brands)): ?>
      <div class="card text-center" style="padding:3rem">
        <p class="muted">Gift cards are not on sale yet. Check back soon.</p>
      </div>
    <?php else: ?>
    <div class="ws-landing-cards">
      <?php foreach ($brands as $b): ?>
      <article class="card card-hover">
        <?php if (!empty($b->logo_url)): ?><img alt="<?=htmlspecialchars($b->name)?>" src="<?=htmlspecialchars($b->logo_url)?>" style="width:100%;height:5rem;object-fit:contain;margin-bottom:.75rem"><?php endif; ?>
        <h3 class="card-title"><?=htmlspecialchars($b->name)?></h3>
        <p class="muted text-sm"><?=(int)$b->product_count?> denomination<?=(int)$b->product_count===1?'':'s'?> available</p>
        <?php if ($current_user): ?>
          <a class="btn btn-primary btn-sm mt-2" href="<?=site_url('dashboard/giftcards')?>">Buy now →</a>
        <?php else: ?>
          <a class="btn btn-primary btn-sm mt-2" href="<?=site_url('login?redirect='.rawurlencode('dashboard/giftcards'))?>">Sign in to buy →</a>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="muted text-sm mt-6">
      Gift cards are purchased and delivered from your <a href="<?=site_url('dashboard/giftcards')?>">dashboard gift card page</a> —
      codes are never shown before payment is confirmed, and every reveal is recorded for your protection.
    </p>
    <?php endif; ?>
  </div>
</section>
