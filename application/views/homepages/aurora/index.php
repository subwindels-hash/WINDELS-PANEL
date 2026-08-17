<?php defined('BASEPATH') OR exit('No direct script access allowed');
/** AURORA — premium SaaS: soft indigo→fuchsia gradients, glass cards, rounded, serif display. */
?>
<section class="ws-aurora-hero">
  <div class="container" style="max-width:1080px">
    <span class="badge badge-brand">★ 4.9 · Trusted by 50,000+ marketers</span>
    <h1 class="mt-4">Grow your social presence<br><span class="gradient-text">with WINDELS PANEL</span></h1>
    <p class="ws-lead">One platform. 2,000+ services. Automated, reliable fulfillment at reseller speed.</p>
    <div class="row" style="justify-content:center;margin-top:1.5rem">
      <a class="btn btn-primary btn-lg" href="<?=site_url('register')?>">Start ordering →</a>
      <a class="btn btn-secondary btn-lg" href="<?=site_url('services')?>">View services</a>
    </div>
    <div class="ws-aurora-stats">
      <div><strong>2M+</strong><span>Orders delivered</span></div>
      <div><strong>48k</strong><span>Active users</span></div>
      <div><strong>2,000+</strong><span>Services</span></div>
      <div><strong>99.8%</strong><span>Uptime</span></div>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:1080px">
    <h2 class="text-center">Popular services</h2>
    <p class="muted text-center">Real-time start times, refill guarantees, and frozen pricing at checkout.</p>
    <div class="grid grid-3 mt-6">
      <?php foreach (array(
        array('Instagram Followers','$1.20 / 1k','High quality · 0–5 min'),
        array('TikTok Likes','$0.45 / 1k','Instant · refill'),
        array('YouTube Views','$2.10 / 1k','Non-drop · worldwide'),
      ) as $s): ?>
      <div class="card card-hover">
        <h3 class="card-title"><?=htmlspecialchars($s[0])?></h3>
        <p class="muted"><?=htmlspecialchars($s[2])?></p>
        <div class="row justify-between mt-2">
          <strong style="color:var(--brand-700)"><?=htmlspecialchars($s[1])?></strong>
          <a class="btn btn-primary btn-sm" href="<?=site_url('register')?>">Order</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.ws-aurora-hero{position:relative;padding:5rem 0 4rem;text-align:center;overflow:hidden;
  background:radial-gradient(1200px 400px at 50% -10%,var(--brand-100),transparent 60%),
             radial-gradient(800px 300px at 80% 10%,var(--accent-100,var(--brand-100)),transparent 60%);}
.ws-lead{font-size:1.15rem;color:var(--slate-600);max-width:620px;margin:1rem auto 0}
.ws-aurora-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-top:3rem}
.ws-aurora-stats>div{background:rgba(255,255,255,.7);backdrop-filter:blur(6px);border:1px solid var(--slate-200);
  border-radius:var(--radius-lg);padding:1rem}
.ws-aurora-stats strong{display:block;font-family:var(--font-display);font-size:1.6rem;color:var(--brand-700)}
.ws-aurora-stats span{font-size:.8rem;color:var(--slate-500)}
@media(max-width:640px){.ws-aurora-stats{grid-template-columns:repeat(2,1fr)}}
</style>
