<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * AURORA — the default MarvySocials homepage.
 *
 * Cinematic gradients, glass cards, generous space. The catalogue sections are
 * driven by live database rows passed in by Home::index(); the narrative copy
 * (how it works, FAQ) is static because it describes how the panel behaves,
 * not what is in stock.
 */
// Live catalogue, supplied by Home::index(). Every price, service name and
// category on this page is a real row an operator published — there are no
// invented services, and an empty catalogue renders an honest empty state
// rather than placeholder cards that promise things we cannot deliver.
$showcase   = isset($data['showcase']) && is_array($data['showcase']) ? $data['showcase'] : array();
$categories = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : array();
$catalogue_size = isset($data['catalogue_size']) ? (int)$data['catalogue_size'] : 0;

$steps = array(
  array('01','Create an account and add funds','Sign up in a minute, then top up your wallet with any payment method the operator has enabled.'),
  array('02','Pick a service and place the order','Choose a category and service, paste your link or target, set the quantity. The price is calculated before you confirm.'),
  array('03','Track it from your dashboard','Watch the status change as the provider works through it, with the start count and remaining quantity where the provider reports them.'),
);

$faqs = array(
  array('How do I add funds?', 'Open Add funds in your dashboard, choose one of the payment methods the operator has enabled, and follow the instructions shown. Your balance updates once the payment is confirmed — bank transfers are credited after review, and Bitcoin after the configured number of network confirmations.'),
  array('How is the price calculated?', 'Every service has a published rate per 1,000 units. The dashboard shows the exact charge for your quantity before you confirm, and that amount is what leaves your wallet.'),
  array('Why is my order still pending?', 'Pending means the order is queued and has not started at the provider yet. It moves to processing once the provider accepts it. Start times vary by service and are shown on the service where the provider reports them.'),
  array('Can I cancel or get a refill?', 'Only where the service itself supports it — each service says so on its page, and the button appears on the order when it applies. If a provider delivers only part of an order, the undelivered portion is returned to your wallet.'),
  array('Is there an API?', 'Yes. Create a key under Account → API in your dashboard and call /api/v1. The endpoints are documented at /api/docs.'),
);
?>
<section class="ws-aurora-hero">
  <div class="container" style="max-width:1180px">
    <div class="ws-hero-split">
      <div>
        <span class="badge badge-brand">Prepaid social media services</span>
        <h1 class="mt-4">Grow and manage your social presence<br><span class="gradient-text">with MarvySocials</span></h1>
        <p class="ws-lead" style="margin-left:0">
          Browse the service catalogue, add funds to your wallet, place an order and track it —
          all from one dashboard.
          <?php if ($catalogue_size > 0): ?>
            <?=number_format($catalogue_size)?> service<?=$catalogue_size === 1 ? '' : 's'?> are live right now.
          <?php endif; ?>
        </p>
        <div class="row" style="margin-top:1.5rem">
          <a class="btn btn-primary btn-lg" href="<?=site_url('register')?>">Get started</a>
          <a class="btn btn-secondary btn-lg" href="<?=site_url('services')?>">View services</a>
        </div>
      </div>
      <div class="ws-hero-media">
        <img src="<?=base_url('assets/images/home/hero.jpg')?>" alt="Abstract view of a commerce operations hub: glass panels, order flows and connected services." width="960" height="720" fetchpriority="high">
      </div>
    </div>
    <div class="ws-aurora-stats" aria-label="What the panel includes">
      <div><strong>Wallet</strong><span>Prepaid spend only</span></div>
      <div><strong>Ledger</strong><span>Double-entry credits</span></div>
      <div><strong>API</strong><span>Reseller keys &amp; scopes</span></div>
      <div><strong>Staff</strong><span>RBAC back office</span></div>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:1080px">
    <div class="text-center mb-4">
      <h2>Services on the panel</h2>
      <p class="muted">Live rates from the catalogue. The exact charge is shown before you confirm an order.</p>
    </div>

    <?php if ($showcase): ?>
    <div class="grid grid-3 mt-6">
      <?php foreach ($showcase as $s): ?>
      <article class="card card-hover">
        <?php if (!empty($s->category_name)): ?>
          <span class="badge badge-default"><?=htmlspecialchars($s->category_name)?></span>
        <?php endif; ?>
        <h3 class="card-title mt-2"><?=htmlspecialchars($s->name)?></h3>
        <p class="ws-rate"><?=marvy_money($s->rate)?> <span class="muted">/ 1,000</span></p>
        <p class="muted text-sm">
          Min <?=number_format((int)$s->min_quantity)?> · Max <?=number_format((int)$s->max_quantity)?>
          <?php if (!empty($s->average_time)): ?>
            <br>Average start: <?=htmlspecialchars($s->average_time)?>
          <?php endif; ?>
        </p>
        <p class="ws-flags">
          <?php if ((int)$s->refill_supported === 1): ?><span class="badge badge-success">Refill</span><?php endif; ?>
          <?php if ((int)$s->cancel_supported === 1): ?><span class="badge badge-default">Cancellable</span><?php endif; ?>
          <?php if ((int)$s->dripfeed_supported === 1): ?><span class="badge badge-default">Drip-feed</span><?php endif; ?>
        </p>
        <a class="btn btn-primary btn-sm mt-2" href="<?=site_url('services/'.$s->slug)?>">View service</a>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-6"><a class="btn btn-secondary" href="<?=site_url('services')?>">Browse all services →</a></div>

    <?php else: ?>
    <div class="card text-center" style="max-width:640px;margin:2rem auto">
      <h3 class="card-title">The catalogue is being prepared</h3>
      <p class="muted">
        No services have been published yet. Rather than show you prices we cannot honour, this section
        stays empty until the operator publishes the catalogue. Create an account now and it will be
        waiting for you.
      </p>
      <a class="btn btn-primary btn-sm mt-2" href="<?=site_url('register')?>">Create your account</a>
    </div>
    <?php endif; ?>
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

<?php if ($categories): ?>
<section class="py-12" style="background:var(--slate-50)">
  <div class="container" style="max-width:1080px">
    <h2 class="text-center">Browse by category</h2>
    <p class="muted text-center mt-2">
      Only categories that currently have services you can order are listed.
    </p>
    <div class="ws-cats">
      <?php foreach ($categories as $c): ?>
      <a class="card card-hover text-center" href="<?=site_url('services?category='.rawurlencode($c->slug))?>">
        <?php if (!empty($c->icon)): ?>
          <?php // icon is a glyph key, not a label — render the SVG, never the raw name.
                // The partial emits nothing for a key it does not know, which is the
                // right fallback: a missing glyph should not print "send" on the card. ?>
          <div class="ws-cat-icon" aria-hidden="true">
            <?php $this->load->view('partials/icon', array('name' => $c->icon, 'class' => 'w-6 h-6')); ?>
          </div>
        <?php endif; ?>
        <strong><?=htmlspecialchars($c->name)?></strong>
        <span class="muted text-xs" style="display:block">
          <?=number_format((int)$c->service_count)?> service<?=(int)$c->service_count === 1 ? '' : 's'?>
          <?php if ($c->from_rate !== null): ?><br>from <?=marvy_money($c->from_rate)?>/1k<?php endif; ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

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

<?php
// Deliberately capabilities, not testimonials. Quoting customers we do not
// have would be the easiest section on this page to fake, and every claim
// below is something the panel actually does.
$reasons = array(
  array('One dashboard', 'Order, track, top up, raise a ticket and manage your account from a single place.'),
  array('Prices you see before you pay', 'The exact charge for your quantity is calculated and shown before you confirm. The wallet balance is what it says it is.'),
  array('An auditable wallet', 'Every credit and charge is a double-entry ledger record. Balances are never adjusted by hand.'),
  array('Order tracking', 'Status, start count and remaining quantity are shown wherever the provider reports them — no guessing.'),
  array('Refills and partial refunds', 'Where a service supports a refill, the option is on the order. If a provider under-delivers, the undelivered part returns to your wallet.'),
  array('An API for resellers', 'Create a key in your dashboard and drive the same order engine over HTTP. Docs at /api/docs.'),
  array('Account security', 'Passwords are hashed, two-factor authentication is available, and an optional transaction PIN guards sensitive actions.'),
  array('Support that keeps a record', 'Open a ticket from your dashboard and the whole thread stays attached to your account.'),
);
?>
<section class="py-12" style="background:var(--slate-50)">
  <div class="container" style="max-width:1080px">
    <h2 class="text-center">Why choose MarvySocials</h2>
    <p class="muted text-center mt-2">What the platform actually does — no invented numbers, no borrowed reviews.</p>
    <div class="grid grid-3 mt-6">
      <?php foreach ($reasons as $r): ?>
      <div class="card">
        <h3 class="card-title"><?=htmlspecialchars($r[0])?></h3>
        <p class="muted"><?=htmlspecialchars($r[1])?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12">
  <div class="container" style="max-width:900px">
    <div class="ws-cta">
      <h2>Ready to get started?</h2>
      <p>Create your account, add funds, and place your first order in minutes.</p>
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
.ws-rate{font-family:var(--font-display);font-size:1.35rem;color:var(--brand-700);margin:.35rem 0 .25rem}
.ws-flags{display:flex;flex-wrap:wrap;gap:.35rem;margin:.5rem 0 0}
.ws-flags:empty{display:none}
.ws-cats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-top:1.5rem}
.ws-cat-icon{display:flex;justify-content:center;margin-bottom:.4rem;color:var(--brand-600)}
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
