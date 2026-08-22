<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * PULSE — bright marketplace homepage (Session 05).
 * Search-first, bold rose/amber accent, category pills, mobile-first tap targets.
 */
$chips = array('Instagram','TikTok','YouTube','X','Facebook','Telegram');
$categories = array(
  array('Followers','👥','SMM catalogue'),array('Likes','❤️','SMM catalogue'),
  array('Views','👁️','SMM catalogue'),array('Comments','💬','SMM catalogue'),
  array('Shares','🔗','SMM catalogue'),array('Saves','🔖','SMM catalogue'),
  array('Subscribers','📺','SMM catalogue'),array('Live viewers','📡','SMM catalogue'),
);
$trending = array(
  array('Instagram','Followers — HQ',4.9,'1.20','0–5 min','50','100k'),
  array('TikTok','Likes — Instant',4.8,'0.45','Instant','20','50k'),
  array('YouTube','Views — Non-drop',4.7,'2.10','1–2 hrs','100','1M'),
  array('Spotify','Monthly Listeners',4.9,'4.00','0–24 hrs','1000','50k'),
  array('X (Twitter)','Retweets',4.6,'1.80','0–1 hr','100','20k'),
  array('Telegram','Channel Members',4.8,'3.20','0–6 hrs','100','100k'),
  array('Facebook','Page Likes',4.5,'2.60','0–12 hrs','50','25k'),
  array('Twitch','Live Viewers',4.7,'6.00','Instant','50','5k'),
);
$reviews = array(
  array('Search first','Find a service, paste a public link, pay from the wallet. No fake review scores.'),
  array('Refill when marked','Services that support refill expose the action on the order — it is not a slogan.'),
  array('Human support','Tickets and the contact form reach staff. The assistant only explains the site.'),
);
$faqs = array(
  array('How do I place an order?','Search for a service, paste your link, choose a quantity, and pay from your wallet. Most orders start automatically.'),
  array('Can I get a refill?','Services marked “refill” include a refill window from the order detail page.'),
  array('What payment methods are supported?','Add funds through whichever methods the operator has enabled. Manual bank transfer ships on; card gateways stay off until credentials are configured. The default minimum deposit is ₦500.'),
);
?>
<section class="py-10 ws-pulse-hero">
  <div class="container" style="max-width:900px;text-align:center">
    <span class="badge badge-danger">⚡ Fast · Reliable · Refill-ready</span>
    <h1 class="mt-3">Find the right service.<br>Place your order.<br><span class="gradient-text">Track everything.</span></h1>
    <form method="get" action="<?=site_url('services')?>" class="ws-pulse-search" role="search">
      <label class="sr-only" for="ws-q">Search services</label>
      <span class="ws-search-icon" aria-hidden="true">🔍</span>
      <input id="ws-q" name="q" type="search" placeholder="Try “Instagram followers”…"
             class="input" autocomplete="off">
      <button class="btn btn-danger btn-lg" type="submit">Search</button>
    </form>
    <div class="row" style="justify-content:center;margin-top:1rem">
      <?php foreach ($chips as $chip): ?>
        <a class="ws-chip" href="<?=site_url('services?platform='.urlencode($chip))?>"><?=htmlspecialchars($chip)?></a>
      <?php endforeach; ?>
    </div>
    <p class="muted mt-2">Popular: <a href="<?=site_url('services')?>">Instagram Followers</a> · <a href="<?=site_url('services')?>">YouTube Views</a></p>
  </div>
</section>

<section class="py-8">
  <div class="container" style="max-width:1080px">
    <div class="ws-cat-scroll" aria-label="Categories">
      <?php foreach ($categories as $c): ?>
      <a class="ws-cat-pill" href="<?=site_url('services')?>">
        <span class="ws-cat-emoji"><?=$c[1]?></span>
        <span class="ws-cat-name"><?=htmlspecialchars($c[0])?></span>
        <span class="ws-cat-count"><?=htmlspecialchars($c[2])?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-8">
  <div class="container" style="max-width:1080px">
    <div class="row justify-between" style="align-items:end">
      <h2 class="mb-0">🔥 Trending now</h2>
      <a class="btn btn-ghost btn-sm" href="<?=site_url('services')?>">See all →</a>
    </div>
    <div class="grid grid-4 mt-4">
      <?php foreach ($trending as $s): ?>
      <article class="card card-hover">
        <div class="row justify-between">
          <span class="badge badge-default"><?=htmlspecialchars($s[0])?></span>
          <span class="muted" aria-label="Example catalogue card">Example</span>
        </div>
        <h3 class="card-title mt-2"><?=htmlspecialchars($s[1])?></h3>
        <p class="muted" style="font-size:.85rem">⏱ <?=htmlspecialchars($s[4])?></p>
        <div class="row justify-between mt-2">
          <strong style="color:var(--danger-600);font-size:1.15rem">$<?=htmlspecialchars($s[3])?> <span class="muted" style="font-weight:400;font-size:.8rem">/ 1k</span></strong>
          <a class="btn btn-danger btn-sm" href="<?=site_url('register')?>" rel="nofollow">Order</a>
        </div>
        <p class="hint"><?=number_format((int)$s[5])?> – <?=number_format((int)$s[6])?> units</p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-10" style="background:linear-gradient(180deg,var(--danger-50),transparent)">
  <div class="container" style="max-width:900px">
    <div class="ws-fastorder card">
      <div class="text-center">
        <h2>Quick order</h2>
        <p class="muted">Pick a category and service, paste your link, and see the price live.</p>
      </div>
      <form class="grid ws-fast-grid" onsubmit="return false">
        <label class="field"><span class="label">Category</span>
          <select class="select"><option>Followers</option><option>Likes</option><option>Views</option></select>
        </label>
        <label class="field"><span class="label">Service</span>
          <select class="select"><option>Instagram Followers — HQ (₦1.20/1k)</option></select>
        </label>
        <label class="field"><span class="label">Link</span>
          <input class="input" placeholder="https://instagram.com/yourhandle">
        </label>
        <label class="field"><span class="label">Quantity</span>
          <input id="ws-qty" class="input" type="number" min="50" value="1000" inputmode="numeric">
        </label>
      </form>
      <div class="row justify-between ws-fast-total">
        <div><span class="muted">Total</span> <strong id="ws-total">₦1.20</strong></div>
        <a class="btn btn-danger btn-lg" href="<?=site_url('register')?>">Place order →</a>
      </div>
      <p class="hint text-center">Live price is an estimate; the exact total is frozen at checkout (no provider calls on this page).</p>
    </div>
  </div>
</section>

<section class="py-10">
  <div class="container" style="max-width:1080px">
    <div class="text-center">
      <h2 class="mb-0">Built for placing orders, not collecting stars</h2>
      <p class="muted">Capabilities of the panel — not fabricated ratings.</p>
    </div>
    <div class="grid grid-3 mt-6">
      <?php foreach ($reviews as $r): ?>
      <figure class="card">
        <figcaption class="muted"><?=htmlspecialchars($r[0])?></figcaption>
        <blockquote><?=htmlspecialchars($r[1])?></blockquote>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-10" style="background:var(--slate-50)">
  <div class="container" style="max-width:820px">
    <h2 class="text-center">Questions?</h2>
    <div class="mt-6 stack">
      <?php foreach ($faqs as $f): ?>
      <details class="ws-faq-light">
        <summary><?=htmlspecialchars($f[0])?></summary>
        <p><?=htmlspecialchars($f[1])?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:900px">
    <div class="ws-cta-band">
      <h2>Start ordering in minutes</h2>
      <p>No subscription. Add funds, order, track — all in one place.</p>
      <a class="btn btn-lg" style="background:#fff;color:var(--danger-700)" href="<?=site_url('register')?>">Create free account</a>
    </div>
  </div>
</section>

<style>
.ws-pulse-hero{background:
  radial-gradient(700px 260px at 50% 0,rgba(244,63,94,.10),transparent 60%),
  radial-gradient(500px 200px at 90% 10%,rgba(245,158,11,.10),transparent 60%)}
.ws-pulse-search{display:flex;align-items:center;max-width:640px;margin:1.5rem auto 0;
  border:2px solid var(--slate-200);border-radius:9999px;box-shadow:var(--shadow-card);background:#fff;overflow:hidden;transition:border-color .15s,box-shadow .15s}
.ws-pulse-search:focus-within{border-color:var(--danger-500);box-shadow:0 0 0 4px rgba(244,63,94,.15)}
.ws-pulse-search .input{border:0;box-shadow:none;border-radius:0;padding:.95rem 1rem .95rem 2.6rem}
.ws-pulse-search .input:focus{box-shadow:none}
.ws-search-icon{position:absolute;margin-left:1rem;font-size:1.1rem;pointer-events:none}
.ws-chip{padding:.45rem .9rem;border-radius:9999px;background:#fff;border:1px solid var(--slate-200);
  color:var(--slate-700);font-weight:500;font-size:.85rem;transition:.15s}
.ws-chip:hover{border-color:var(--danger-400);color:var(--danger-700);text-decoration:none;transform:translateY(-1px)}
.ws-cat-scroll{display:flex;gap:.75rem;overflow-x:auto;padding-bottom:.5rem;scroll-snap-type:x mandatory}
.ws-cat-pill{flex:0 0 auto;scroll-snap-align:start;display:flex;align-items:center;gap:.6rem;
  padding:.75rem 1rem;background:#fff;border:1px solid var(--slate-200);border-radius:1rem;min-width:180px;transition:.15s}
.ws-cat-pill:hover{border-color:var(--danger-400);box-shadow:var(--shadow-card);text-decoration:none}
.ws-cat-emoji{font-size:1.4rem}.ws-cat-name{font-weight:600;color:var(--slate-900)}
.ws-cat-count{display:block;font-size:.75rem;color:var(--slate-500)}
.ws-stars{color:#f59e0b;font-size:.85rem;font-weight:600}
.ws-fastorder{border:2px solid var(--danger-100);box-shadow:0 20px 50px -25px rgba(244,63,94,.5)}
.ws-fast-grid{grid-template-columns:repeat(2,1fr);gap:1rem;margin-top:1.5rem}
.ws-fast-total{margin-top:1.25rem;padding-top:1rem;border-top:1px dashed var(--slate-200)}
#ws-total{font-size:1.5rem;color:var(--danger-700);font-family:var(--font-display)}
.ws-faq-light{background:#fff;border:1px solid var(--slate-200);border-radius:var(--radius);padding:1rem 1.25rem}
.ws-faq-light summary{cursor:pointer;font-weight:600;list-style:none}
.ws-faq-light summary::-webkit-details-marker{display:none}
.ws-faq-light summary::after{content:'+';float:right;color:var(--danger-600);font-weight:700}
.ws-faq-light[open] summary::after{content:'−'}
.ws-faq-light p{margin:.75rem 0 0;color:var(--slate-600)}
.ws-cta-band{text-align:center;background:linear-gradient(135deg,var(--danger-600),#f97316);color:#fff;border-radius:var(--radius-xl);padding:3rem 2rem}
.ws-cta-band h2{color:#fff}.ws-cta-band p{color:rgba(255,255,255,.9);margin-bottom:1.25rem}
@media(max-width:560px){
  .ws-pulse-search{flex-direction:column;border-radius:var(--radius-lg)}
  .ws-pulse-search .input{border-radius:0;width:100%;padding-left:2.6rem}
  .ws-pulse-search .btn{border-radius:0;width:100%}
  .ws-fast-grid{grid-template-columns:1fr}
}
</style>
<script <?=csp_nonce_attr()?>>
// Live quick-order estimate (no network call — pricing is illustrative).
(function(){
  var qty=document.getElementById('ws-qty'), total=document.getElementById('ws-total');
  if(!qty||!total) return;
  // Currency symbol from the server so live totals match server-rendered prices.
  var sym=<?=json_encode(trim(str_replace(array('0','.',','), '', windels_money(0))))?>;
  function fmt(v){return sym+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function recalc(){
    var q=Math.max(50,parseInt(qty.value||'0',10)||0);
    total.textContent=fmt(q*1.20/1000);
  }
  qty.addEventListener('input',recalc); recalc();
})();
</script>
