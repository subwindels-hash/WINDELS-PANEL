<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<section class="ws-page-hero">
  <div class="container" style="max-width:1080px">
    <p class="ws-kicker">Product UI</p>
    <h1><?=htmlspecialchars(function_exists('windels_site_name') ? windels_site_name() : 'WINDELS PANEL')?> <span class="gradient-text">Design System</span></h1>
    <p class="ws-lede">The tokens and component classes the live site uses. Source of truth: <code>assets/css/design-system.css</code>, mirrored in <code>tailwind.config.js</code>. This page is a reference, not a separate visual language.</p>
  </div>
</section>

<section class="ws-section-sm">
<div class="container stack" style="max-width:1080px">

  <section class="card" id="logo">
    <h2 class="card-title">Logo</h2>
    <p class="muted">The mark is a rounded panel with an A and two rising bars. The public header, footer, auth shell and assistant all load the same assets through <code>partials/brand_logo.php</code>. Do not recolour the gradient, add drop shadows, or place the mark on a busy photograph.</p>
    <div class="grid grid-3 mt-4">
      <div class="card" style="background:#fff">
        <?php $this->load->view('partials/brand_logo', array('variant'=>'horizontal','height'=>48,'class'=>'ws-logo-lg')); ?>
        <p class="hint">Primary / light</p>
      </div>
      <div class="card" style="background:#0b1020">
        <?php $this->load->view('partials/brand_logo', array('variant'=>'dark','height'=>48,'class'=>'ws-logo-lg')); ?>
        <p class="hint" style="color:#94a3b8">Dark background</p>
      </div>
      <div class="card">
        <?php $this->load->view('partials/brand_logo', array('variant'=>'icon','height'=>56,'class'=>'ws-logo-lg')); ?>
        <p class="hint">Icon / favicon</p>
      </div>
    </div>
    <div class="row mt-4">
      <img src="<?=base_url('assets/images/ai/avatar.jpg')?>" alt="Site assistant avatar" class="ws-avatar ws-avatar-lg" width="64" height="64">
      <p class="muted" style="margin:0">Assistant avatar — abstract glass disc, not a photograph of a person.</p>
    </div>
  </section>

  <section class="card" id="imagery">
    <h2 class="card-title">Imagery</h2>
    <p>Photography is indigo-navy with a single fuchsia accent. No fake customers, no stock handshakes, no competitor logos. Hero images load with <code>fetchpriority="high"</code>; supporting images use <code>loading="lazy"</code>.</p>
    <img class="ws-visual mt-4" src="<?=base_url('assets/images/home/hero.jpg')?>" alt="Example hero visual" width="960" height="540" loading="lazy">
  </section>

  <section class="card" id="colors">
    <h2 class="card-title">Brand colours</h2>
    <p class="muted">Indigo brand, fuchsia accent, slate neutrals. Same custom properties the buttons, links and hero gradients read.</p>
    <div class="stack">
      <?php $scales = array(
        'Brand (indigo)' => array('brand', array(50,100,200,300,400,500,600,700,800,900,950)),
        'Accent (fuchsia)'=>array('accent', array(50,100,200,300,400,500,600,700)),
        'Slate'          => array('slate', array(50,100,200,300,400,500,600,700,800,900,950)),
      ); ?>
      <?php foreach ($scales as $label => $pair): list($key,$steps)=$pair; ?>
        <div>
          <h3 class="card-meta"><?=htmlspecialchars($label)?></h3>
          <div class="row" style="gap:.35rem">
          <?php foreach ($steps as $s): ?>
            <div title="<?=$key.'-'.$s?>" style="flex:1;min-width:48px">
              <div style="background:var(--<?=$key.'-'.$s?>);height:54px;border-radius:.5rem;border:1px solid var(--slate-200)"></div>
              <div class="card-meta" style="text-align:center"><?=$s?></div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card" id="semantic">
    <h2 class="card-title">Semantic colours</h2>
    <div class="grid grid-4">
      <div><div style="background:var(--success-500);height:48px;border-radius:.5rem"></div><span class="badge badge-success">Success</span></div>
      <div><div style="background:var(--warning-500);height:48px;border-radius:.5rem"></div><span class="badge badge-warning">Warning</span></div>
      <div><div style="background:var(--danger-500);height:48px;border-radius:.5rem"></div><span class="badge badge-danger">Danger</span></div>
      <div><div style="background:var(--info-500);height:48px;border-radius:.5rem"></div><span class="badge badge-info">Info</span></div>
    </div>
  </section>

  <section class="card" id="type">
    <h2 class="card-title">Typography</h2>
    <div class="stack">
      <h1>Display heading — Fraunces</h1>
      <h2>Section heading — Grow inside one panel</h2>
      <h3>Subsection heading</h3>
      <p>Body copy in Inter. Money renders as <strong><?=function_exists('windels_money')?windels_money(1200):'₦1,200.00'?></strong> and identifiers such as <code>DECIMAL(20,8)</code> stay monospaced.
        <a href="<?=site_url('services')?>">Inline links</a> use the brand colour.</p>
      <p class="muted">Muted secondary text for metadata and helper copy.</p>
      <p class="ws-lede">Lede text for page introductions.</p>
      <pre style="background:var(--slate-900);color:#e2e8f0;padding:1rem;border-radius:.75rem;overflow:auto"><code>// monospaced code
$windels = ['panel' =&gt; 'ready'];</code></pre>
    </div>
  </section>

  <section class="card" id="spacing">
    <h2 class="card-title">Spacing and breakpoints</h2>
    <p>Page sections use <code>.ws-section</code> (4.5rem) and <code>.ws-section-sm</code> (3rem). The content container is 1200px with 1rem gutters. Cards pad 1.5rem. Grids collapse with <code>minmax(260px, 1fr)</code>.</p>
    <ul>
      <li>Mobile: default, stacked nav under 860px, assistant full-width under 560px</li>
      <li>Tablet: two-column stats and footer</li>
      <li>Laptop / desktop: full nav, three- and four-column cards</li>
    </ul>
  </section>

  <section class="card" id="buttons">
    <h2 class="card-title">Buttons</h2>
    <div class="row">
      <button class="btn btn-primary" type="button">Primary</button>
      <button class="btn btn-secondary" type="button">Secondary</button>
      <button class="btn btn-ghost" type="button">Ghost</button>
      <button class="btn btn-success" type="button">Success</button>
      <button class="btn btn-danger" type="button">Danger</button>
      <button class="btn btn-primary" type="button" disabled>Disabled</button>
    </div>
    <div class="row mt-4">
      <button class="btn btn-primary btn-lg" type="button">Large</button>
      <button class="btn btn-primary" type="button">Default</button>
      <button class="btn btn-primary btn-sm" type="button">Small</button>
    </div>
    <p class="hint mt-2">Focus is a 2px brand outline. Hover darkens the fill. Disabled uses opacity and <code>pointer-events: none</code>.</p>
  </section>

  <section class="card" id="forms">
    <h2 class="card-title">Form fields</h2>
    <form class="grid" style="grid-template-columns:1fr 1fr;gap:1rem" onsubmit="return false">
      <div class="field">
        <label class="label" for="sg-text">Text input</label>
        <input id="sg-text" class="input" placeholder="you@example.com">
        <div class="hint">We never share your email.</div>
      </div>
      <div class="field">
        <label class="label" for="sg-select">Select</label>
        <select id="sg-select" class="select">
          <option>Instagram Followers</option><option>TikTok Likes</option>
        </select>
      </div>
      <div class="field" style="grid-column:1/-1">
        <label class="label" for="sg-area">Textarea</label>
        <textarea id="sg-area" class="textarea" placeholder="Order notes…"></textarea>
      </div>
      <label class="checkbox"><input type="checkbox" checked> Checkbox</label>
      <label class="radio"><input type="radio" name="sg-r" checked> Radio A</label>
      <label class="radio"><input type="radio" name="sg-r"> Radio B</label>
      <label class="switch"><input type="checkbox" checked><span class="switch-ui"></span> Switch</label>
      <div class="field ws-password" style="grid-column:1/-1">
        <label class="label" for="sg-pass">Password with visibility control</label>
        <input id="sg-pass" class="input" type="password" value="example-only" autocomplete="off">
        <button type="button" class="ws-password-toggle" data-password-toggle="sg-pass" aria-pressed="false">Show</button>
      </div>
    </form>
  </section>

  <section class="grid" style="grid-template-columns:1fr 1fr;gap:1rem" id="feedback">
    <div class="card">
      <h2 class="card-title">Badges</h2>
      <div class="row">
        <span class="badge badge-default">Default</span>
        <span class="badge badge-brand">Brand</span>
        <span class="badge badge-success badge-dot">Active</span>
        <span class="badge badge-warning badge-dot">Pending</span>
        <span class="badge badge-danger badge-dot">Failed</span>
        <span class="badge badge-info">API</span>
      </div>
    </div>
    <div class="card">
      <h2 class="card-title">Alerts</h2>
      <div class="alert alert-success">Payment credited to your wallet.</div>
      <div class="alert alert-warning">Email verification pending.</div>
      <div class="alert alert-danger">Insufficient balance for this order.</div>
      <div class="alert alert-info">Order submitted to provider.</div>
    </div>
  </section>

  <section class="card" id="nav-tabs">
    <h2 class="card-title">Tabs and accordion</h2>
    <div class="tabs" role="tablist">
      <button class="tab" type="button" aria-selected="true">Orders</button>
      <button class="tab" type="button" aria-selected="false">Wallet</button>
    </div>
    <div class="tab-panel mt-4">Tab panels hide with the native <code>hidden</code> attribute.</div>
    <details class="accordion-item mt-4" open>
      <summary>How are refunds paid?</summary>
      <div class="accordion-body">Back to the prepaid wallet, never as a silent cash withdrawal.</div>
    </details>
  </section>

  <section>
    <h2 class="mb-0 mt-2">Cards</h2>
    <div class="grid grid-3 mt-4">
      <div class="card card-hover">
        <h3 class="card-title">Social media services</h3>
        <p class="muted">Catalogue rates frozen at checkout.</p>
        <a class="btn btn-primary btn-sm" href="<?=site_url('services')?>">Browse</a>
      </div>
      <div class="card card-hover">
        <h3 class="card-title">VTU and bills</h3>
        <p class="muted">Airtime, data, cable, electricity, exam pins.</p>
        <a class="btn btn-primary btn-sm" href="<?=site_url('dashboard/vtu')?>">Open VTU</a>
      </div>
      <div class="card card-hover">
        <h3 class="card-title">Reseller API</h3>
        <p class="muted">Keys, scopes, IP allowlists.</p>
        <a class="btn btn-primary btn-sm" href="<?=site_url('api/docs')?>">Docs</a>
      </div>
    </div>
  </section>

  <section class="card" id="table">
    <h2 class="card-title">Table</h2>
    <div style="overflow-x:auto">
      <table class="table">
        <thead><tr><th>Order</th><th>Service</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
          <tr><td>#10293</td><td>Example row</td><td><?=function_exists('windels_money')?windels_money(12):'₦12.00'?></td><td><span class="badge badge-success badge-dot">Completed</span></td></tr>
          <tr><td>#10294</td><td>Example row</td><td><?=function_exists('windels_money')?windels_money(3.4):'₦3.40'?></td><td><span class="badge badge-warning badge-dot">In progress</span></td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="grid" style="grid-template-columns:1fr 1fr;gap:1rem" id="states">
    <div class="card">
      <h2 class="card-title">Loading</h2>
      <div class="row"><span class="spinner" aria-hidden="true"></span><span>Looking that up…</span></div>
      <div class="skeleton mt-4" style="height:2.5rem"></div>
    </div>
    <div class="card">
      <h2 class="card-title">Empty</h2>
      <div class="empty-state">
        <h3>No services match</h3>
        <p>Clear filters to see the catalogue again.</p>
        <a class="btn btn-secondary btn-sm" href="<?=site_url('services')?>">Clear</a>
      </div>
    </div>
  </section>

  <section class="card" id="modal">
    <h2 class="card-title">Modal (structure)</h2>
    <p class="muted">Modals use <code>.modal-backdrop</code> + <code>.modal</code>. They are assembled when a view needs one; this page does not trap focus with a dummy overlay.</p>
    <div class="modal" style="position:relative;box-shadow:var(--shadow-card)">
      <h3 class="card-title">Confirm refund</h3>
      <p>This example is static documentation, not a live dialog.</p>
      <div class="row"><button class="btn btn-secondary" type="button">Cancel</button><button class="btn btn-danger" type="button">Refund wallet</button></div>
    </div>
  </section>

  <section class="card" id="icons">
    <h2 class="card-title">Icons</h2>
    <p>Dashboard and admin navigation use the inline SVG partial <code>partials/icon.php</code> (Lucide-style paths). Public marketing pages prefer text, badges and the brand mark rather than a second icon font.</p>
  </section>

  <section class="card" id="assistant">
    <h2 class="card-title">Site assistant</h2>
    <p>The embedded assistant renders from <code>partials/site_operator.php</code> (wrapped by <code>partials/chatbot.php</code>). Use the documented classes — do not invent new ones:</p>
    <div class="row" style="gap:.5rem;flex-wrap:wrap">
      <button type="button" class="ws-assistant-launch" aria-expanded="false" aria-controls="ws-assistant">Assistant</button>
    </div>
    <section class="ws-assistant" id="ws-assistant" aria-label="Assistant example">
      <header class="ws-assistant-head"><h3 class="card-title">Assistant</h3></header>
      <div class="ws-assistant-log"><p class="muted">This is a static documentation example, not the live widget.</p></div>
      <div class="ws-assistant-links"><a class="btn btn-secondary btn-sm" href="<?=site_url('faq')?>">Open the FAQ</a></div>
    </section>
    <p class="muted mt-3">The launch button is fixed to the corner (<code>.ws-assistant-launch</code>), the panel is fixed above it (<code>.ws-assistant</code>) and hides with <code>[hidden]</code> until opened. It is a local rule-based engine — it is not a cloud generative AI and cannot act on your account.</p>
  </section>

  <p class="text-center muted">The compiled utility stylesheet <code>assets/css/tailwind.css</code> ships with the app. Rebuild it with <code>npm run build:css</code> after changing utility classes.</p>
</div>
</section>
