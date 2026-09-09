<?php
defined('BASEPATH') OR exit('No direct script access allowed');
if (!class_exists('SiteOperatorKnowledge', false)) {
    require_once APPPATH.'libraries/SiteOperatorKnowledge.php';
}
$plans = SiteOperatorKnowledge::pricing_plans();
?>
<section class="ws-page-hero">
  <div class="container" style="max-width:1100px">
    <div class="ws-hero-split">
      <div>
        <p class="ws-kicker">Pricing</p>
        <h1>Prepaid wallet. Published rates. No fake plans.</h1>
        <p class="ws-lede">MarvySocials does not sell a public monthly subscription. You add funds and pay the rate shown on each service or product. Volume groups exist, but staff assign them — they are not something you check out.</p>
      </div>
      <div class="ws-hero-media">
        <img src="<?=base_url('assets/images/services/marketplace.jpg')?>" alt="Quiet digital storefront of glass product tiles — a visual for prepaid catalogue pricing, not a plan grid." width="800" height="600" fetchpriority="high">
      </div>
    </div>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container">
    <div class="grid grid-3">
      <?php foreach ($plans as $plan): ?>
      <article class="card ws-plan">
        <img class="ws-plan-icon" src="<?=base_url('assets/brand/logo-icon.svg')?>" alt="" width="48" height="48">
        <span class="badge <?=$plan['status']==='available'?'badge-success':'badge-brand'?>">
          <?=$plan['status']==='available'?'Available':'Contact sales'?>
        </span>
        <h2 class="card-title mt-2"><?=htmlspecialchars($plan['name'])?></h2>
        <p class="muted"><?=htmlspecialchars($plan['audience'])?></p>
        <p style="font-size:1.5rem;font-family:var(--font-display);margin:.5rem 0 0"><?=htmlspecialchars($plan['price_label'])?></p>
        <p class="hint"><?=htmlspecialchars($plan['model'])?> · <?=htmlspecialchars($plan['period'])?></p>
        <p><?=htmlspecialchars($plan['price_note'])?></p>
        <ul>
          <?php foreach ($plan['features'] as $f): ?><li><?=htmlspecialchars($f)?></li><?php endforeach; ?>
        </ul>
        <p class="hint"><?=htmlspecialchars($plan['limits'])?></p>
        <p class="hint">Upgrade path: <?=htmlspecialchars($plan['upgrade'])?></p>
        <a class="btn <?=$plan['status']==='available'?'btn-primary':'btn-secondary'?> btn-block" href="<?=site_url($plan['cta_href'])?>">
          <?=htmlspecialchars($plan['cta'])?>
        </a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="ws-section-sm" style="background:var(--slate-50)">
  <div class="container">
    <h2>Compare</h2>
    <p class="muted">This table describes how the panel is actually sold. It does not invent per-seat or per-month prices.</p>
    <div class="card ws-compare mt-4">
      <table class="table">
        <thead>
          <tr>
            <th>Topic</th>
            <th>Pay as you go</th>
            <th>Volume groups</th>
            <th>Custom / operator</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>Who it is for</td><td>Anyone with an account</td><td>Assigned by staff</td><td>By agreement</td></tr>
          <tr><td>Platform fee</td><td>None</td><td>None</td><td>By agreement</td></tr>
          <tr><td>SMM rates</td><td>Default group</td><td>Silver / Gold / Reseller</td><td>Negotiated catalogue</td></tr>
          <tr><td>Wallet</td><td>Required</td><td>Required</td><td>Required</td></tr>
          <tr><td>Withdrawals</td><td>Not available</td><td>Not available</td><td>Not available</td></tr>
          <tr><td>Reseller API</td><td>Optional key</td><td>Optional key</td><td>Optional key</td></tr>
          <tr><td>How to start</td><td><a href="<?=site_url('register')?>">Register</a></td><td><a href="<?=site_url('contact')?>">Contact sales</a></td><td><a href="<?=site_url('contact')?>">Contact sales</a></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="ws-section-sm">
  <div class="container" style="max-width:800px">
    <h2>Billing information</h2>
    <div class="stack">
      <div class="card">
        <h3 class="card-title">Deposits</h3>
        <p>Funds credited after a verified payment become wallet balance. The default minimum deposit is ₦500 and the default maximum is ₦5,000,000; both are settings the operator can change. Manual bank transfer ships enabled. Card and regional gateways stay off until credentials are configured.</p>
      </div>
      <div class="card">
        <h3 class="card-title">Charges</h3>
        <p>An order or purchase debits the wallet through the ledger at the frozen checkout amount. If a provider later delivers only part of an SMM order, the undelivered quantity can be credited back. Failed automated purchases in other modules are refunded by those engines when they mark the job failed or abandoned.</p>
      </div>
      <div class="card">
        <h3 class="card-title">Cancellation</h3>
        <p>There is no subscription to cancel. You can stop adding funds at any time. Individual orders cancel only when the service supports it and the order is still in a cancellable state. Wallet leftovers are not paid out as cash — see the <a href="<?=site_url('refund-policy')?>">Refund Policy</a>.</p>
      </div>
    </div>

    <h2 class="mt-6">Pricing questions</h2>
    <div class="stack">
      <details class="accordion-item">
        <summary>Why is there no $9 / $29 / $99 grid?</summary>
        <div class="accordion-body">Because this product does not sell those plans. Publishing invented package prices would be a payment commitment the software does not enforce.</div>
      </details>
      <details class="accordion-item">
        <summary>Where do I see a real number?</summary>
        <div class="accordion-body">On each service card and at checkout. VTU, numbers, identity, gift cards and marketplace items show their own product prices once staff have set them.</div>
      </details>
      <details class="accordion-item">
        <summary>Can I get a cheaper rate?</summary>
        <div class="accordion-body">Ask support to review a volume group. Staff can assign Silver, Gold, Reseller or a per-user override. That is the upgrade path — not a self-serve checkout.</div>
      </details>
    </div>
  </div>
</section>

<section class="ws-section-sm ws-cta">
  <div class="container">
    <div class="card text-center" style="padding:var(--space-6)">
      <h2 class="card-title">Start with the wallet plan</h2>
      <p class="muted">Register for free, then add funds when you are ready to order. No forced monthly plan.</p>
      <div class="row" style="justify-content:center;margin-top:1rem">
        <a class="btn btn-primary btn-lg" href="<?=site_url('register')?>">Create an account</a>
        <a class="btn btn-secondary btn-lg" href="<?=site_url('contact')?>">Contact sales</a>
      </div>
    </div>
  </div>
</section>
