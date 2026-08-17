<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * AURORA — premium SaaS homepage (Session 05).
 * Cinematic indigo→fuchsia gradients, glass cards, serif display, generous space.
 * All data here is static marketing content; live services arrive in Session 07.
 */
$popular = array(
  array('Instagram','Followers','High quality · 0–5 min','1.20','50','100000','refill'),
  array('TikTok','Likes','Instant · refill guaranteed','0.45','20','50000','instant'),
  array('YouTube','Views','Non-drop · worldwide','2.10','100','1000000',''),
  array('Instagram','Story Views','Starts in 1 min','0.30','100','25000','instant'),
  array('X (Twitter)','Followers','Real-looking profiles','3.40','100','20000','refill'),
  array('Spotify','Monthly Listeners','Geo-targeted','4.00','1000','50000',''),
);
$platforms = array('Instagram','TikTok','YouTube','X','Facebook','Telegram','Spotify','Twitch','LinkedIn','Reddit','Pinterest','Discord');
$steps = array(
  array('01','Choose a service','Browse 2,000+ services across every major platform, with transparent pricing and start times.'),
  array('02','Place your order','Paste your link, pick a quantity, and pay from your wallet. Pricing is frozen at checkout.'),
  array('03','Track & grow','Watch progress in real time. Refills and partial-delivery refunds are handled automatically.'),
);
$categories = array(
  array('Followers','👥'),array('Likes','❤️'),array('Views','👁️'),array('Comments','💬'),
  array('Shares','🔗'),array('Saves','🔖'),array('Subscribers','📺'),array('Live viewers','📡'),
);
$faqs = array(
  array('How fast do orders start?','Most services begin within minutes. Each card shows its average start time and whether refill is included.'),
  array('Is my account safe?','We never ask for your password. Orders follow each platform\'s natural velocity limits to protect your account.'),
  array('What if an order is incomplete?','If a provider delivers only part of your order, the undelivered amount is refunded to your wallet automatically.'),
  array('Do you offer an API?','Yes — create an API key in your dashboard and integrate /api/v1 in minutes. Full docs at /api/docs.'),
);
$testimonials = array(
  array('Mira K.','Agency owner','“We process thousands of orders a month. The wallet ledger and refill handling just work.”'),
  array('Diego R.','Reseller','“The API is fast and the pricing tiers let us run real margins.”'),
  array('Aisha B.','Creator','“Customer support actually replies and my orders complete every time.”'),
);
?>
<section class="ws-aurora-hero">
  <div class="container" style="max-width:1080px">
    <span class="badge badge-brand">★ 4.9 · Trusted by 50,000+ marketers</span>
    <h1 class="mt-4">Grow your social presence<br><span class="gradient-text">with WINDELS PANEL</span></h1>
    <p class="ws-lead">One platform. 2,000+ services. Automated, reliable fulfillment at reseller speed — with a wallet ledger you can trust.</p>
    <div class="row" style="justify-content:center;margin-top:1.5rem">
      <a class="btn btn-primary btn-lg" href="<?=site_url('register')?>">Start ordering →</a>
      <a class="btn btn-secondary btn-lg" href="<?=site_url('services')?>">View services</a>
    </div>
    <div class="ws-aurora-stats" aria-label="Platform statistics">
      <div><strong>2M+</strong><span>Orders delivered</span></div>
      <div><strong>48k</strong><span>Active users</span></div>
      <div><strong>2,000+</strong><span>Services</span></div>
      <div><strong>99.8%</strong><span>Uptime</span></div>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:1080px">
    <div class="text-center mb-4">
      <h2>Popular services</h2>
      <p class="muted">Frozen pricing at checkout · refill where shown · start times in minutes.</p>
    </div>
    <div class="grid grid-3 mt-6">
      <?php foreach ($popular as $s): ?>
      <article class="card card-hover">
        <div class="row justify-between">
          <span class="badge badge-default"><?=htmlspecialchars($s[0])?></span>
          <?php if ($s[6]): ?><span class="badge badge-success badge-dot"><?=htmlspecialchars($s[6])?></span><?php endif; ?>
        </div>
        <h3 class="card-title mt-2"><?=htmlspecialchars($s[1])?></h3>
        <p class="muted"><?=htmlspecialchars($s[2])?></p>
        <div class="row justify-between mt-2">
          <strong style="color:var(--brand-700)">$<?=htmlspecialchars($s[3])?> / 1k</strong>
          <a class="btn btn-primary btn-sm" href="<?=site_url('register')?>" rel="nofollow">Order</a>
        </div>
        <p class="hint">Min <?=number_format((int)$s[4])?> · Max <?=number_format((int)$s[5])?></p>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-6"><a class="btn btn-secondary" href="<?=site_url('services')?>">Browse all services →</a></div>
  </div>
</section>

<section class="py-12" style="background:var(--slate-50)">
  <div class="container" style="max-width:1080px">
    <h2 class="text-center">How it works</h2>
    <div class="grid grid-3 mt-6 ws-steps">
      <?php foreach ($steps as $i => $st): ?>
      <div class="card text-center" style="position:relative">
        <div class="ws-step-num"><?=htmlspecialchars($st[0])?></div>
        <h3 class="card-title"><?=htmlspecialchars($st[1])?></h3>
        <p class="muted"><?=htmlspecialchars($st[2])?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:1080px">
    <h2 class="text-center">Every major platform</h2>
    <div class="ws-platforms">
      <?php foreach ($platforms as $p): ?>
        <span class="ws-platform"><?=htmlspecialchars($p)?></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12" style="background:var(--slate-50)">
  <div class="container" style="max-width:1080px">
    <h2 class="text-center">Browse by category</h2>
    <div class="ws-cats">
      <?php foreach ($categories as $c): ?>
      <a class="card card-hover text-center" href="<?=site_url('services')?>">
        <div class="ws-cat-emoji"><?=$c[1]?></div>
        <strong><?=htmlspecialchars($c[0])?></strong>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:900px">
    <h2 class="text-center">Frequently asked questions</h2>
    <div class="mt-6 stack">
      <?php foreach ($faqs as $i => $f): ?>
      <details class="ws-faq">
        <summary><?=htmlspecialchars($f[0])?></summary>
        <p><?=htmlspecialchars($f[1])?></p>
      </details>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-4 muted">More answers on the <a href="<?=site_url('faq')?>">FAQ page</a>.</div>
  </div>
</section>

<section class="py-12" style="background:var(--slate-50)">
  <div class="container" style="max-width:1080px">
    <h2 class="text-center">Loved by resellers and creators</h2>
    <div class="grid grid-3 mt-6">
      <?php foreach ($testimonials as $t): ?>
      <figure class="card">
        <div aria-label="5 out of 5 stars">★★★★★</div>
        <blockquote><?=htmlspecialchars($t[2])?></blockquote>
        <figcaption><strong><?=htmlspecialchars($t[0])?></strong> <span class="muted">· <?=htmlspecialchars($t[1])?></span></figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:900px">
    <div class="ws-cta">
      <h2>Ready to scale?</h2>
      <p>Create your free account and place your first order in minutes.</p>
      <div class="row" style="justify-content:center">
        <a class="btn btn-primary btn-lg" href="<?=site_url('register')?>">Create your account</a>
        <a class="btn btn-secondary btn-lg" href="<?=site_url('pricing')?>">See pricing</a>
      </div>
    </div>
  </div>
</section>

<style>
.ws-aurora-hero{position:relative;padding:5rem 0 4rem;text-align:center;overflow:hidden;
  background:radial-gradient(1200px 400px at 50% -10%,var(--brand-100),transparent 60%),
             radial-gradient(800px 300px at 85% 5%,var(--accent-100,var(--brand-100)),transparent 60%);}
.ws-lead{font-size:1.15rem;color:var(--slate-600);max-width:620px;margin:1rem auto 0}
.ws-aurora-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-top:3rem}
.ws-aurora-stats>div{background:rgba(255,255,255,.72);backdrop-filter:blur(6px);border:1px solid var(--slate-200);border-radius:var(--radius-lg);padding:1rem}
.ws-aurora-stats strong{display:block;font-family:var(--font-display);font-size:1.6rem;color:var(--brand-700)}
.ws-aurora-stats span{font-size:.8rem;color:var(--slate-500)}
.ws-step-num{width:44px;height:44px;margin:0 auto .75rem;border-radius:9999px;display:grid;place-items:center;
  background:var(--brand-50);color:var(--brand-700);font-family:var(--font-display);font-weight:700}
.ws-platforms{display:flex;flex-wrap:wrap;gap:.6rem;justify-content:center;margin-top:1.5rem}
.ws-platform{padding:.5rem 1rem;border:1px solid var(--slate-200);border-radius:9999px;color:var(--slate-500);background:#fff;transition:.2s}
.ws-platform:hover{color:var(--brand-700);border-color:var(--brand-300);transform:translateY(-1px)}
.ws-cats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-top:1.5rem}
.ws-cat-emoji{font-size:1.6rem;margin-bottom:.25rem}
.ws-faq{background:#fff;border:1px solid var(--slate-200);border-radius:var(--radius);padding:1rem 1.25rem}
.ws-faq summary{cursor:pointer;font-weight:600;list-style:none}
.ws-faq summary::-webkit-details-marker{display:none}
.ws-faq summary::after{content:'+';float:right;color:var(--brand-600);font-weight:700}
.ws-faq[open] summary::after{content:'−'}
.ws-faq p{margin:.75rem 0 0;color:var(--slate-600)}
.ws-cta{text-align:center;background:linear-gradient(135deg,var(--brand-600),var(--accent-600));color:#fff;border-radius:var(--radius-xl);padding:3rem 2rem}
.ws-cta h2{color:#fff}
.ws-cta p{color:rgba(255,255,255,.85);margin-bottom:1.25rem}
.ws-cta .btn-secondary{background:#fff;color:var(--brand-700);border-color:transparent}
@media(max-width:768px){.ws-aurora-stats{grid-template-columns:repeat(2,1fr)}.ws-cats{grid-template-columns:repeat(2,1fr)}}
</style>
