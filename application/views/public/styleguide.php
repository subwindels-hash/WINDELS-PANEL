<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<section class="py-12">
<div class="container stack" style="max-width:1080px">

  <header class="text-center mb-2">
    <span class="badge badge-brand">Session 04</span>
    <h1 class="mt-3">WINDELS <span class="gradient-text">Design System</span></h1>
    <p class="muted">Brand tokens, typography, and reusable components. The canonical source is
      <code>assets/css/design-system.css</code>; the Tailwind build mirrors the same tokens from
      <code>tailwind.config.js</code>.</p>
  </header>

  <!-- Brand palette -->
  <section class="card">
    <h2 class="card-title">Brand palette</h2>
    <div class="grid grid-4">
      <?php $scales = array(
        'Brand (indigo)' => array(50,100,200,300,400,500,600,700,800,900,950),
        'Accent (fuchsia)'=>array(100,200,300,400,500,600,700),
        'Slate'          => array(50,100,200,300,400,500,600,700,800,900,950),
      );
      ?>
      <?php foreach ($scales as $label => $steps): ?>
        <div style="grid-column:1/-1">
          <h3 class="card-meta" style="margin:.5rem 0"><?=htmlspecialchars($label)?></h3>
          <div class="row" style="gap:.35rem">
          <?php foreach ($steps as $s): ?>
            <?php $key = ($label === 'Accent (fuchsia)') ? 'accent' : ($label === 'Slate' ? 'slate' : 'brand'); ?>
            <div title="<?=$key.'-'.$s?>" style="flex:1;min-width:54px">
              <div style="background:var(--<?=$key.'-'.$s?>);height:54px;border-radius:.5rem;border:1px solid var(--slate-200)"></div>
              <div class="card-meta" style="text-align:center"><?=$s?></div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Semantic colors -->
  <section class="card">
    <h2 class="card-title">Semantic colors</h2>
    <div class="grid grid-4">
      <div><div style="background:var(--success-500);height:48px;border-radius:.5rem"></div><span class="badge badge-success">Success</span></div>
      <div><div style="background:var(--warning-500);height:48px;border-radius:.5rem"></div><span class="badge badge-warning">Warning</span></div>
      <div><div style="background:var(--danger-500);height:48px;border-radius:.5rem"></div><span class="badge badge-danger">Danger</span></div>
      <div><div style="background:var(--info-500);height:48px;border-radius:.5rem"></div><span class="badge badge-info">Info</span></div>
    </div>
  </section>

  <!-- Typography -->
  <section class="card">
    <h2 class="card-title">Typography</h2>
    <div class="stack">
      <h1>Display heading — Fraunces</h1>
      <h2>Section heading — Grow your social presence</h2>
      <h3>Subsection heading</h3>
      <p>Body copy in Inter. The quick brown fox jumps over the lazy dog. Numbers like
        <strong>2,000+ services</strong> and <code>DECIMAL(20,8)</code> render cleanly.
        <a href="#">Inline links</a> use the brand color with an underline on hover.</p>
      <p class="muted">Muted secondary text for metadata and helper copy.</p>
      <pre style="background:var(--slate-900);color:#e2e8f0;padding:1rem;border-radius:.75rem;overflow:auto"><code>// monospaced code
$windels = ['panel' =&gt; 'ready'];</code></pre>
    </div>
  </section>

  <!-- Buttons -->
  <section class="card">
    <h2 class="card-title">Buttons</h2>
    <div class="row">
      <button class="btn btn-primary">Primary</button>
      <button class="btn btn-secondary">Secondary</button>
      <button class="btn btn-ghost">Ghost</button>
      <button class="btn btn-success">Success</button>
      <button class="btn btn-danger">Danger</button>
      <button class="btn btn-primary" disabled>Disabled</button>
    </div>
    <div class="row mt-4">
      <button class="btn btn-primary btn-lg">Large</button>
      <button class="btn btn-primary">Default</button>
      <button class="btn btn-primary btn-sm">Small</button>
      <a class="btn btn-primary btn-block" href="#" style="max-width:240px">Block link button</a>
    </div>
  </section>

  <!-- Forms -->
  <section class="card">
    <h2 class="card-title">Form controls</h2>
    <form class="grid" style="grid-template-columns:1fr 1fr;gap:1rem" onsubmit="return false">
      <div class="field">
        <label class="label" for="sg-text">Text input</label>
        <input id="sg-text" class="input" placeholder="you@example.com">
        <div class="hint">We never share your email.</div>
      </div>
      <div class="field">
        <label class="label" for="sg-select">Select</label>
        <select id="sg-select" class="select">
          <option>Instagram Followers</option><option>TikTok Likes</option><option>YouTube Views</option>
        </select>
      </div>
      <div class="field" style="grid-column:1/-1">
        <label class="label" for="sg-area">Textarea</label>
        <textarea id="sg-area" class="textarea" placeholder="Order notes…"></textarea>
      </div>
      <label class="checkbox"><input type="checkbox" checked> Subscribe to order updates</label>
    </form>
  </section>

  <!-- Badges & alerts -->
  <section class="grid" style="grid-template-columns:1fr 1fr;gap:1rem">
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

  <!-- Cards -->
  <section>
    <h2 class="mb-0 mt-2">Cards</h2>
    <div class="grid grid-3">
      <div class="card card-hover">
        <h3 class="card-title">Instagram Followers</h3>
        <p class="muted">High quality · starts in 0–5 min</p>
        <div class="row justify-between mt-2">
          <strong>$1.20 / 1k</strong>
          <a class="btn btn-primary btn-sm" href="#">Order</a>
        </div>
      </div>
      <div class="card card-hover">
        <h3 class="card-title">TikTok Likes</h3>
        <p class="muted">Instant · refill guaranteed</p>
        <div class="row justify-between mt-2">
          <strong>$0.45 / 1k</strong>
          <a class="btn btn-primary btn-sm" href="#">Order</a>
        </div>
      </div>
      <div class="card card-hover">
        <h3 class="card-title">YouTube Views</h3>
        <p class="muted">Non-drop · worldwide</p>
        <div class="row justify-between mt-2">
          <strong>$2.10 / 1k</strong>
          <a class="btn btn-primary btn-sm" href="#">Order</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Table -->
  <section class="card">
    <h2 class="card-title">Table</h2>
    <div style="overflow-x:auto">
      <table class="table">
        <thead><tr><th>Order</th><th>Service</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
          <tr><td>#10293</td><td>Instagram Followers</td><td>$12.00</td><td><span class="badge badge-success badge-dot">Completed</span></td></tr>
          <tr><td>#10294</td><td>TikTok Likes</td><td>$3.40</td><td><span class="badge badge-warning badge-dot">In progress</span></td></tr>
          <tr><td>#10295</td><td>YouTube Views</td><td>$21.00</td><td><span class="badge badge-danger badge-dot">Failed</span></td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <p class="text-center muted">Components render without a Tailwind build; in production the utility classes
    used across views are compiled by <code>npm run build:css</code>.</p>
</div>
</section>
