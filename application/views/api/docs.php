<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reseller API — MarvySocials</title>
<link rel="stylesheet" href="<?=base_url('assets/css/design-system.css')?>">
<style>
  body{background:var(--slate-50);padding:2rem 1rem}
  .ws-api{max-width:920px;margin:0 auto}
  .ws-method{font-family:var(--font-mono);font-size:.75rem;font-weight:700;padding:.15rem .45rem;border-radius:.35rem}
  .ws-get{background:#dbeafe;color:#1d4ed8}
  .ws-post{background:#dcfce7;color:#166534}
  pre{background:var(--slate-900);color:#e2e8f0;padding:1rem;border-radius:.75rem;overflow:auto}
  code{font-family:var(--font-mono)}
  table td{vertical-align:top}
</style>
</head>
<body>
<div class="ws-api stack">
  <div>
    <h1>Reseller API <span class="badge badge-brand">v1</span></h1>
    <p class="muted">Base URL <code><?=site_url('api/v1')?></code>. Authenticate with <code>X-Api-Key: wind_…</code>.
      Create a key in <a href="<?=site_url('dashboard/api')?>">your dashboard</a>. Machine-readable docs at
      <a href="<?=site_url('api/docs/json')?>">/api/docs/json</a>.</p>
  </div>

  <div class="card">
    <h2 class="card-title">Authentication &amp; envelope</h2>
    <p>Every response is JSON: <code>{success, data|error, meta?, requestId}</code>.
      Mutating POSTs accept an <code>Idempotency-Key</code> header. Rate limits are returned via
      <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>, and <code>Retry-After</code>.</p>
<pre><code>curl <?=site_url('api/v1/balance')?> \
  -H "X-Api-Key: wind_yourkey"</code></pre>
  </div>

  <div class="card">
    <h2 class="card-title">Key scopes</h2>
    <p>A key may have full access or an explicit allow-list. A request outside that allow-list returns
      <code>403 SCOPE_FORBIDDEN</code>. Available scopes are:</p>
    <table class="table"><tbody>
      <tr><th><code>services.read</code></th><td>Read services and resolved prices.</td></tr>
      <tr><th><code>orders.read</code></th><td>Read orders and refill statuses.</td></tr>
      <tr><th><code>orders.write</code></th><td>Place, refill, and cancel orders.</td></tr>
      <tr><th><code>account.read</code></th><td>Read wallet balance.</td></tr>
      <tr><th><code>referrals.read</code></th><td>Read referral and commission totals.</td></tr>
    </tbody></table>
  </div>

  <div class="card">
    <h2 class="card-title">Endpoints</h2>
    <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th></th><th>Path</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><span class="ws-method ws-get">GET</span></td><td><code>/services</code></td><td>List active services with your price. Filters: <code>category</code>, <code>q</code>, <code>page</code>, <code>limit</code>.</td></tr>
        <tr><td><span class="ws-method ws-get">GET</span></td><td><code>/services/:public_id</code></td><td>Single service.</td></tr>
        <tr><td><span class="ws-method ws-get">GET</span></td><td><code>/balance</code></td><td>Wallet balance and currency.</td></tr>
        <tr><td><span class="ws-method ws-post">POST</span></td><td><code>/orders</code></td><td>Place an order. Body: <code>{service, link, quantity, fields?, note?}</code>.</td></tr>
        <tr><td><span class="ws-method ws-post">POST</span></td><td><code>/orders/mass</code></td><td>Place up to 100 instructions. Body: <code>{orders:[{service, link, quantity}]}</code>. Returns separate successful and failed rows.</td></tr>
        <tr><td><span class="ws-method ws-get">GET</span></td><td><code>/orders</code></td><td>List your orders (<code>status</code>, <code>page</code>, <code>limit</code>).</td></tr>
        <tr><td><span class="ws-method ws-get">GET</span></td><td><code>/orders/:public_id</code></td><td>Order status, charge and status history.</td></tr>
        <tr><td><span class="ws-method ws-post">POST</span></td><td><code>/orders/status</code></td><td>Bulk lookup: <code>{orderIds:[…]}</code> (max 100).</td></tr>
        <tr><td><span class="ws-method ws-post">POST</span></td><td><code>/refills</code></td><td>Request a refill: <code>{orderId}</code>.</td></tr>
        <tr><td><span class="ws-method ws-get">GET</span></td><td><code>/refills/:public_id</code></td><td>Refill status.</td></tr>
        <tr><td><span class="ws-method ws-post">POST</span></td><td><code>/cancellations</code></td><td>Cancel an order: <code>{orderId}</code>.</td></tr>
      </tbody>
    </table>
    </div>
  </div>

  <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem">
    <div class="card">
      <h3 class="card-title">Place an order</h3>
<pre><code>curl -X POST <?=site_url('api/v1/orders')?> \
  -H "X-Api-Key: wind_..." \
  -H "Content-Type: application/json" \
  -d '{"service":"01SVC...","link":
   "https://instagram.com/u","quantity":1000}'</code></pre>
    </div>
    <div class="card">
      <h3 class="card-title">Order response</h3>
<pre><code>{
  "success": true,
  "data": {
    "order": "01ORDER...",
    "status": "PROCESSING",
    "quantity": 1000,
    "charge": "1.20000000",
    "currency": "NGN"
  },
  "requestId": "..."
}</code></pre>
    </div>
  </div>

  <p class="muted text-center">All monetary amounts are strings in <code>DECIMAL(20,8)</code> to avoid floating-point errors.</p>
</div>
</body>
</html>
