<?php defined('BASEPATH') OR exit('No direct script access allowed');
$showcase   = isset($data['showcase']) && is_array($data['showcase']) ? $data['showcase'] : array();
$categories = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : array();
$catalogue_size = isset($data['catalogue_size']) ? (int)$data['catalogue_size'] : 0;
$stats = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : array();
$faqs = isset($data['faqs']) && is_array($data['faqs']) ? $data['faqs'] : array();
$cu = $current_user ?? null;

$hero_kicker = $data['hero_kicker'] ?: 'Power your social growth';
$hero_title  = $data['hero_title'] ?: 'Grow your social presence with one powerful platform';
$hero_lede   = $data['hero_lede'] ?: 'Access fast, reliable social media services, VTU, numbers, identity checks and gift cards from a prepaid dashboard you can actually audit.';
$cta_primary = $data['cta_primary'] ?? 'Get started';
$cta_secondary = $data['cta_secondary'] ?? 'View services';
$services_title = $data['services_title'] ?: 'Everything you need to grow online';
$services_lede = $data['services_lede'] ?: 'Social media services, VTU, numbers, identity checks and gift cards — published rates, prepaid wallet.';
$cta_band_title = $data['cta_band_title'] ?: 'Ready to get started?';
$cta_band_body = $data['cta_band_body'] ?: 'Join MarvySocials and run social media and digital services from one prepaid platform.';

$steps = array(
  array('01', 'Create an account', 'Register in a minute and open your personal dashboard.'),
  array('02', 'Add funds', 'Top up the prepaid wallet with a payment method the operator has enabled.'),
  array('03', 'Choose a service', 'Pick a catalogue item, set the quantity and confirm the exact charge.'),
  array('04', 'Track your order', 'Watch status, start count and remaining quantity from one screen.'),
);

$features = array(
  array('zap', 'Fast processing', 'Orders go to the provider as soon as the wallet can cover them.'),
  array('shield', 'Secure payments', 'Prepaid wallet, hashed passwords, optional PIN and two-factor authentication.'),
  array('dashboard', 'Easy dashboard', 'Balance, orders, tickets and API keys live in the same layout.'),
  array('repeat', 'Real-time tracking', 'Status updates wherever the provider reports them — no guessing.'),
  array('key', 'API access', 'Reseller keys with scopes. Same order engine over HTTP.'),
  array('message-square', 'Professional support', 'Tickets stay attached to your account so the thread is never lost.'),
);

if (empty($faqs)) {
  $faqs = array(
    (object)array('question' => 'How do I create an account?', 'answer' => 'Open Get started, choose a username and email, then confirm. You land on the customer dashboard immediately unless email verification is required.'),
    (object)array('question' => 'How do I place an order?', 'answer' => 'Add funds, open New order, pick a service, paste the target, set quantity and confirm. The charge is shown before anything leaves the wallet.'),
    (object)array('question' => 'Which payment methods do you accept?', 'answer' => 'Whatever the operator has enabled — typically cards, local transfers and crypto. Each method is listed on Add funds.'),
    (object)array('question' => 'Can I track my orders?', 'answer' => 'Yes. My orders and Order history show status, charge and remaining quantity. Notifications fire when something changes.'),
    (object)array('question' => 'Do you provide API access?', 'answer' => 'Yes. Create a key under Account → API and call /api/v1. Documentation is at /api/docs.'),
  );
}

?>

<style>
/* AURORA identity: soft aurora gradient wash behind a light, editorial hero. */
.ws-aurora .gradient-text{
  background:linear-gradient(100deg,#4f46e5 0%,#7c3aed 45%,#c026d3 100%);
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
.ws-aurora .ms-hero{background:
  radial-gradient(900px 320px at 8% -10%,rgba(99,102,241,.18),transparent 60%),
  radial-gradient(700px 260px at 92% 0,rgba(192,38,211,.14),transparent 55%);}
/* Hero write-up (eyebrow, headline, lede) reads solid black on the light wash.
   Overrides the white-on-gradient hero text from marketing.css and the purple
   gradient-clipped headline above. */
.ws-aurora .ws-landing-hero.ms-hero .ws-kicker,
.ws-aurora .ws-landing-hero.ms-hero h1,
.ws-aurora .ws-landing-hero.ms-hero .lede{color:#000;}
.ws-aurora .ws-landing-hero.ms-hero h1.gradient-text{
  background:none;-webkit-background-clip:border-box;background-clip:border-box;
  color:#000;-webkit-text-fill-color:#000;}
</style>

<div class="ws-aurora">
<section class="ws-landing-hero ms-hero">
  <div class="container ws-landing-hero-inner">
    <div class="ws-landing-hero-copy">
      <span class="ws-kicker"><?=htmlspecialchars($hero_kicker)?></span>
      <h1 class="gradient-text"><?=htmlspecialchars($hero_title)?></h1>
      <p class="lede"><?=htmlspecialchars($hero_lede)?>
        <?php if ($catalogue_size > 0): ?>
          <?=number_format($catalogue_size)?> live service<?=$catalogue_size === 1 ? '' : 's'?> on the catalogue now.
        <?php endif; ?>
      </p>
      <div class="ws-page-actions">
        <a class="btn btn-primary btn-lg" href="<?=site_url($cu ? 'dashboard' : 'register')?>"><?=$cu ? 'Open dashboard' : htmlspecialchars($cta_primary)?></a>
        <a class="btn btn-secondary btn-lg" href="<?=site_url('services')?>"><?=htmlspecialchars($cta_secondary)?></a>
      </div>
    </div>
    <div class="ws-landing-hero-visual">
      <img src="<?=base_url('assets/images/home/dashboard-mockup.png')?>" width="1280" height="800" alt="MarvySocials customer dashboard showing wallet, orders and quick actions." fetchpriority="high">
    </div>
  </div>
</section>

<?php if ($stats): ?>
<section class="ws-landing-stats" aria-label="Platform snapshot">
  <div class="container ws-landing-stats-grid">
    <?php foreach ($stats as $st): ?>
      <div class="ws-landing-stat">
        <strong><?=htmlspecialchars($st['value'])?></strong>
        <span><?=htmlspecialchars($st['label'])?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Trusted-by / platforms marquee (SMMHub-style) -->
<section class="ms-marquee" aria-label="Platforms supported">
  <p class="muted text-sm text-center">Trusted by resellers growing audiences on the world&rsquo;s biggest social platforms</p>
  <div class="ms-marquee-track" aria-hidden="true">
    <span>Instagram</span><span>TikTok</span><span>YouTube</span><span>Telegram</span>
    <span>Facebook</span><span>X / Twitter</span><span>Spotify</span><span>LinkedIn</span>
    <span>Instagram</span><span>TikTok</span><span>YouTube</span><span>Telegram</span>
    <span>Facebook</span><span>X / Twitter</span><span>Spotify</span><span>LinkedIn</span>
  </div>
</section>

<section class="ws-section" id="services">
  <div class="container">
    <div class="text-center" style="max-width:40rem;margin:0 auto 2rem">
      <span class="ms-eyebrow">Services on the panel</span>
      <h2 class="ws-section-title"><?=htmlspecialchars($services_title)?></h2>
      <p class="ws-section-lead"><?=htmlspecialchars($services_lede)?></p>
    </div>
    <?php if ($categories): ?>
    <h3 class="ws-section-subtitle text-center">Browse by category</h3>
    <div class="ws-landing-cards">
      <?php foreach (array_slice($categories, 0, 8) as $c): ?>
      <a class="card card-hover ws-landing-service" href="<?=site_url('services?category='.rawurlencode($c->slug))?>">
        <?php if (!empty($c->icon)): ?>
          <div class="ws-landing-icon" aria-hidden="true"><?php $this->load->view('partials/icon', array('name'=>$c->icon,'class'=>'w-6 h-6')); ?></div>
        <?php endif; ?>
        <h3 class="card-title"><?=htmlspecialchars($c->name)?></h3>
        <p class="muted mb-0">
          <?=number_format((int)$c->service_count)?> service<?=(int)$c->service_count===1?'':'s'?>
          <?php if ($c->from_rate !== null): ?> · from <?=marvy_money($c->from_rate)?>/1k<?php endif; ?>
        </p>
        <span class="ws-landing-link">Explore services</span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php elseif ($showcase): ?>
    <div class="ws-landing-cards">
      <?php foreach ($showcase as $s): ?>
      <article class="card">
        <?php if (!empty($s->category_name)): ?><span class="badge badge-default"><?=htmlspecialchars($s->category_name)?></span><?php endif; ?>
        <h3 class="card-title mt-2"><?=htmlspecialchars($s->name)?></h3>
        <p class="ws-landing-rate"><?=marvy_money($s->rate)?> <span class="muted">/ 1,000</span></p>
        <a class="btn btn-primary btn-sm" href="<?=site_url('services/'.$s->slug)?>">View service</a>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state card">
      <h3>The catalogue is being prepared</h3>
      <p>No services are published yet. Create an account and the live list will be waiting.</p>
      <a class="btn btn-primary btn-sm" href="<?=site_url('register')?>">Create your account</a>
    </div>
    <?php endif; ?>
    <div class="text-center mt-6"><a class="btn btn-secondary" href="<?=site_url('services')?>">View all services</a></div>
  </div>
</section>

<section class="ws-section ws-landing-muted" id="how-it-works">
  <div class="container">
    <div class="text-center" style="max-width:40rem;margin:0 auto 2rem">
      <span class="ms-eyebrow">How it works</span>
      <h2 class="ws-section-title">Four steps from signup to a tracked order</h2>
      <p class="ws-section-lead">No monthly plan — you spend a prepaid wallet.</p>
    </div>
    <ol class="ws-landing-steps">
      <?php foreach ($steps as $st): ?>
      <li class="card">
        <span class="ws-landing-step-num"><?=htmlspecialchars($st[0])?></span>
        <h3 class="card-title"><?=htmlspecialchars($st[1])?></h3>
        <p class="muted mb-0"><?=htmlspecialchars($st[2])?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="ws-section" id="features">
  <div class="container">
    <div class="text-center" style="max-width:40rem;margin:0 auto 2rem">
      <span class="ms-eyebrow">Why choose MarvySocials</span>
      <h2 class="ws-section-title">Built for growth, not guesswork</h2>
      <p class="ws-section-lead">What the platform actually does — not invented testimonials.</p>
    </div>
    <div class="ws-landing-cards">
      <?php foreach ($features as $f): ?>
      <div class="card">
        <div class="ws-landing-icon"><?php $this->load->view('partials/icon', array('name'=>$f[0],'class'=>'w-6 h-6')); ?></div>
        <h3 class="card-title"><?=htmlspecialchars($f[1])?></h3>
        <p class="muted mb-0"><?=htmlspecialchars($f[2])?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="ws-section ws-landing-muted" id="platform">
  <div class="container ws-landing-showcase">
    <div>
      <span class="ms-eyebrow">Product</span>
      <h2 class="ws-section-title">One dashboard. Everything under control.</h2>
      <p class="lede">Wallet, quick actions, recent orders and service modules use the same layout you already saw after login. The homepage mockup is that dashboard, not a different product.</p>
      <a class="btn btn-primary" href="<?=site_url($cu ? 'dashboard' : 'register')?>"><?=$cu ? 'Go to dashboard' : 'Create free account'?></a>
    </div>
    <img src="<?=base_url('assets/images/home/dashboard-mockup.png')?>" width="1280" height="800" alt="Dashboard overview with statistics and recent orders.">
  </div>
</section>

<section class="ws-section" id="api">
  <div class="container ws-landing-api">
    <div>
      <span class="ms-eyebrow">Developer</span>
      <h2 class="ws-section-title">Build with MarvySocials</h2>
      <p class="lede">Integrate the same catalogue into your own app with reseller API keys, scopes and documented endpoints.</p>
      <div class="ws-page-actions">
        <a class="btn btn-primary" href="<?=site_url('api/docs')?>">View API documentation</a>
        <a class="btn btn-secondary" href="<?=site_url($cu ? 'dashboard/api' : 'register')?>">Get API access</a>
      </div>
    </div>
    <pre class="ws-landing-code" aria-hidden="true"><code>POST /api/v1/orders
X-Api-Key: wind_…
{
  "service": 1042,
  "link": "https://…",
  "quantity": 1000
}</code></pre>
  </div>
</section>

<section class="ws-section ms-cta">
  <div class="container">
    <div class="ws-landing-cta">
      <h2><?=htmlspecialchars($cta_band_title)?></h2>
      <p><?=htmlspecialchars($cta_band_body)?></p>
      <a class="btn btn-lg" href="<?=site_url('register')?>">Create free account</a>
    </div>
  </div>
</section>

<section class="ws-section" id="faq">
  <div class="container" style="max-width:46rem">
    <h2 class="ws-section-title text-center">Frequently asked questions</h2>
    <div class="stack mt-6">
      <?php foreach (array_slice($faqs, 0, 8) as $f): ?>
      <details class="accordion-item">
        <summary><?=htmlspecialchars($f->question)?></summary>
        <div class="accordion-body"><?=nl2br(htmlspecialchars($f->answer))?></div>
      </details>
      <?php endforeach; ?>
    </div>
    <p class="text-center muted mt-4">More answers on the <a href="<?=site_url('faq')?>">FAQ page</a>.</p>
  </div>
</section>
</div>
