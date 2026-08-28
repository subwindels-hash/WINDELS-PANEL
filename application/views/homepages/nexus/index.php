<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * NEXUS homepage — the dark, infrastructure-first variant.
 *
 * Same live data as AURORA (catalogue, categories, FAQs and the homepage CMS
 * copy all come from the database); a different argument. AURORA sells the
 * dashboard, NEXUS sells the pipe: provider network, service explorer and the
 * reseller API. Nothing here is invented — every number rendered is either a
 * live count or omitted.
 */
$showcase       = isset($data['showcase']) && is_array($data['showcase']) ? $data['showcase'] : array();
$categories     = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : array();
$catalogue_size = isset($data['catalogue_size']) ? (int)$data['catalogue_size'] : 0;
$faqs           = isset($data['faqs']) && is_array($data['faqs']) ? $data['faqs'] : array();
$stats          = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : array();
$cu             = $current_user ?? null;

$hero_kicker = $data['hero_kicker'] ?: 'ENTERPRISE SMM INFRASTRUCTURE';
$hero_title  = $data['hero_title'] ?: 'One API. Every growth service you resell.';
$hero_lede   = $data['hero_lede'] ?: 'Orders, wallet, providers and reconciliation behind a single prepaid account — with an audited ledger and a reseller API your own panel can call.';
$cta_primary = $data['cta_primary'] ?? 'Get started';
$cta_secondary = $data['cta_secondary'] ?? 'View services';

// Capability rows, not vendor names: the panel does not publish which upstream
// supplier fills an order, and inventing six brand logos would be a lie.
$providers = array(
  array('Provider routing',    'Each service is bound to an upstream adapter; staff can repoint it without touching an order.'),
  array('Provider health',     'A cron probe records balance and reachability, so a failing supplier is visible before customers find it.'),
  array('Provider failover',   'An order that a supplier refuses stays unfilled and refunded in full rather than silently stuck.'),
  array('Provider sync',       'Catalogue, rates and limits are pulled from the upstream and re-priced by your own margin rules.'),
  array('Provider ledger',     'Every provider call is recorded against the order it belongs to for later dispute work.'),
  array('Provider sandboxing', 'Mock adapters exist for development and are refused outright in production.'),
);
?>
<style>
/* NEXUS identity: near-black canvas, cyan signal, monospace accents. */
.ws-nexus{background:#0b0f1a;color:#e2e8f0}
.ws-nexus a{color:inherit}
.ws-nexus .ws-section-title,.ws-nexus h1,.ws-nexus h2,.ws-nexus h3{color:#f8fafc}
.ws-nexus .ms-eyebrow{color:#22d3ee;letter-spacing:.18em;text-transform:uppercase;font-size:.75rem;font-weight:700}
.ws-nexus .nexus-hero{padding:5rem 0 4rem;background:
  radial-gradient(800px 300px at 15% -10%,rgba(34,211,238,.16),transparent 60%),
  radial-gradient(700px 260px at 85% 0,rgba(99,102,241,.16),transparent 55%)}
.ws-nexus .nexus-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))}
.ws-nexus .nexus-card{border:1px solid rgba(148,163,184,.22);border-radius:.9rem;padding:1.1rem;background:rgba(15,23,42,.6)}
.ws-nexus .nexus-dot{display:inline-block;width:.5rem;height:.5rem;border-radius:50%;background:#22d3ee;margin-right:.5rem;animation:nexus-pulse 2.4s ease-in-out infinite}
.ws-nexus .nexus-code{background:#020617;border:1px solid rgba(34,211,238,.35);border-radius:.9rem;padding:1rem;overflow:auto;color:#a5f3fc}
.ws-nexus .nexus-table{width:100%;border-collapse:collapse}
.ws-nexus .nexus-table th,.ws-nexus .nexus-table td{text-align:left;padding:.6rem .5rem;border-bottom:1px solid rgba(148,163,184,.18)}
@keyframes nexus-pulse{0%,100%{opacity:1}50%{opacity:.35}}
@media (prefers-reduced-motion: reduce){.ws-nexus .nexus-dot{animation:none}}
</style>

<div class="ws-nexus">

<section class="nexus-hero" aria-label="Introduction">
  <div class="container">
    <span class="ms-eyebrow"><?=htmlspecialchars($hero_kicker)?></span>
    <h1 style="max-width:44rem;margin:.75rem 0"><?=htmlspecialchars($hero_title)?></h1>
    <p class="lede" style="max-width:40rem;color:#cbd5e1"><?=htmlspecialchars($hero_lede)?>
      <?php if ($catalogue_size > 0): ?>
        <?=number_format($catalogue_size)?> live service<?=$catalogue_size === 1 ? '' : 's'?> right now.
      <?php endif; ?>
    </p>
    <div class="ws-page-actions" style="margin-top:1.5rem">
      <a class="btn btn-primary btn-lg" href="<?=site_url($cu ? 'dashboard' : 'register')?>"><?=$cu ? 'Open dashboard' : htmlspecialchars($cta_primary)?></a>
      <a class="btn btn-secondary btn-lg" href="<?=site_url('services')?>"><?=htmlspecialchars($cta_secondary)?></a>
    </div>
    <img src="<?=base_url('assets/images/home/hero-nexus.jpg')?>" width="1280" height="720" fetchpriority="high"
         alt="Order pipeline dashboard: provider routing, queue depth and reconciliation at a glance."
         style="width:100%;max-width:60rem;margin-top:2.5rem;border-radius:1rem;border:1px solid rgba(148,163,184,.25)">
  </div>
</section>

<?php if ($stats): ?>
<section class="ws-section" aria-label="Platform snapshot">
  <div class="container nexus-grid">
    <?php foreach ($stats as $st): ?>
      <div class="nexus-card">
        <strong style="display:block;font-size:1.5rem"><?=htmlspecialchars($st['value'])?></strong>
        <span style="color:#94a3b8"><?=htmlspecialchars($st['label'])?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="ws-section" id="providers">
  <div class="container">
    <span class="ms-eyebrow">Provider network</span>
    <h2 class="ws-section-title">Supply you can inspect</h2>
    <p class="ws-section-lead" style="color:#94a3b8">How orders actually reach an upstream supplier, and what happens when one misbehaves.</p>
    <div class="nexus-grid" style="margin-top:1.5rem">
      <?php foreach ($providers as $p): ?>
        <div class="nexus-card">
          <h3 style="font-size:1rem;margin:0 0 .35rem"><span class="nexus-dot" aria-hidden="true"></span><?=htmlspecialchars($p[0])?></h3>
          <p style="margin:0;color:#94a3b8"><?=htmlspecialchars($p[1])?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="ws-section" id="explorer">
  <div class="container">
    <span class="ms-eyebrow">Service explorer</span>
    <h2 class="ws-section-title">The live catalogue, not a brochure</h2>
    <?php if ($showcase): ?>
      <div style="overflow-x:auto;margin-top:1.25rem">
        <table class="nexus-table">
          <caption class="sr-only">Sample of the live service catalogue</caption>
          <thead><tr><th scope="col">Service</th><th scope="col">Category</th><th scope="col">Rate / 1,000</th><th scope="col"></th></tr></thead>
          <tbody>
            <?php foreach (array_slice($showcase, 0, 6) as $s): ?>
              <tr>
                <td><?=htmlspecialchars($s->name)?></td>
                <td style="color:#94a3b8"><?=htmlspecialchars($s->category_name ?? '—')?></td>
                <td class="mono"><?=marvy_money($s->rate)?></td>
                <td><a class="btn btn-secondary btn-sm" href="<?=site_url('services/'.$s->slug)?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($categories): ?>
      <div class="nexus-grid" style="margin-top:1.25rem">
        <?php foreach (array_slice($categories, 0, 8) as $c): ?>
          <a class="nexus-card" href="<?=site_url('services?category='.rawurlencode($c->slug))?>">
            <h3 style="font-size:1rem;margin:0 0 .25rem"><?=htmlspecialchars($c->name)?></h3>
            <p style="margin:0;color:#94a3b8"><?=number_format((int)$c->service_count)?> service<?=(int)$c->service_count === 1 ? '' : 's'?></p>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="nexus-card" style="margin-top:1.25rem">
        <h3 style="margin-top:0">The catalogue is being prepared</h3>
        <p style="color:#94a3b8">No services are published yet. Create an account and the live list will be waiting.</p>
        <a class="btn btn-primary btn-sm" href="<?=site_url('register')?>">Create your account</a>
      </div>
    <?php endif; ?>
    <div style="margin-top:1.25rem"><a class="btn btn-secondary" href="<?=site_url('services')?>">Browse every service</a></div>
  </div>
</section>

<section class="ws-section" id="automation">
  <div class="container nexus-grid" style="align-items:center">
    <div>
      <span class="ms-eyebrow">Built for automation</span>
      <h2 class="ws-section-title">Your panel can call ours</h2>
      <p style="color:#94a3b8">Scoped reseller keys, idempotent order submission and the same state machine the dashboard uses. No hidden endpoint, no second pricing table.</p>
      <div class="ws-page-actions">
        <a class="btn btn-primary" href="<?=site_url('api/docs')?>">Read the API docs</a>
        <a class="btn btn-secondary" href="<?=site_url($cu ? 'dashboard/api' : 'register')?>">Get a key</a>
      </div>
    </div>
    <pre class="nexus-code" aria-label="Example API request"><code>POST /api/v1/orders
X-Api-Key: wind_…
Idempotency-Key: 6f2b…

{
  "service": 1042,
  "link": "https://…",
  "quantity": 1000
}</code></pre>
  </div>
</section>

<section class="ws-section" id="faq">
  <div class="container" style="max-width:46rem">
    <span class="ms-eyebrow">FAQ</span>
    <h2 class="ws-section-title">Questions engineers ask first</h2>
    <div class="stack ws-faq" style="margin-top:1.25rem">
      <?php foreach (array_slice($faqs, 0, 8) as $f): ?>
        <details class="nexus-card">
          <summary style="cursor:pointer;font-weight:600"><?=htmlspecialchars($f->question)?></summary>
          <div style="color:#94a3b8;margin-top:.5rem"><?=nl2br(htmlspecialchars($f->answer))?></div>
        </details>
      <?php endforeach; ?>
    </div>
    <p style="color:#94a3b8;margin-top:1rem">More answers on the <a href="<?=site_url('faq')?>" style="color:#22d3ee">FAQ page</a>.</p>
  </div>
</section>

<section class="ws-section" aria-label="Get started">
  <div class="container nexus-card" style="text-align:center">
    <h2 class="ws-section-title">Ship at scale</h2>
    <p style="color:#94a3b8">Create an account, fund the wallet and route your first order through the API today.</p>
    <a class="btn btn-primary btn-lg" href="<?=site_url('register')?>">Create free account</a>
  </div>
</section>

</div>
