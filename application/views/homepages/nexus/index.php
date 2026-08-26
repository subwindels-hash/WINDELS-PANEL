<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * NEXUS — dark enterprise homepage (Session 05).
 * Near-black #0B0F1A, neon cyan + violet, mono data labels, grid/glow, API focus.
 */
$providers = array(
  array('Provider Alpha','healthy','2s ago','₦1,204.55'),
  array('Provider Beta','healthy','4s ago','₦840.10'),
  array('Provider Gamma','degraded','1m ago','₦0.00'),
  array('Provider Delta','healthy','6s ago','₦2,910.00'),
  array('Provider Epsilon','healthy','12s ago','₦512.22'),
  array('Provider Zeta','healthy','30s ago','₦175.40'),
);
$explorer = array(
  array('100291','Instagram Followers — HQ','Default','₦1.20 / 1k','100 – 100k','Alpha'),
  array('100312','TikTok Likes — Instant','Instant','₦0.45 / 1k','20 – 50k','Beta'),
  array('100488','YouTube Views — WW','Slow','₦2.10 / 1k','100 – 1M','Delta'),
  array('100512','Spotify Monthly Listeners','Geo','₦4.00 / 1k','1k – 50k','Epsilon'),
);
$automation = array(
  array('Order sync','CLI cron workers pull provider status with Redis distributed locks — no web cron URLs.'),
  array('Wallet ledger','Every movement is double-entry, DECIMAL(20,8), and reconciles to the wallet balance.'),
  array('Webhooks & retries','Signed webhooks with exponential backoff and idempotency keys — retries never double-charge.'),
);
$faqs = array(
  array('Is the API rate limited?','Yes — per-key token-bucket limits (default 60 req/min) with 429 + Retry-After.'),
  array('Which order statuses are supported?','PENDING, IN_PROGRESS, COMPLETED, PARTIAL, CANCELED, FAILED — with a strict state machine and history.'),
  array('How is provider access secured?','Provider keys are AES-256-GCM encrypted at rest; every outbound call enforces full TLS certificate verification, with no exceptions.'),
);
?>
<section class="ws-nexus-hero">
  <div class="container" style="max-width:1180px">
    <div class="ws-nexus-grid">
      <div>
        <p class="ws-nexus-eyebrow">// ENTERPRISE SMM INFRASTRUCTURE</p>
        <h1 class="ws-nexus-title">One platform.<br>Thousands of services.<br>Automated fulfillment.</h1>
        <p class="ws-nexus-sub">Aggregate providers, route orders with failover, and sync status automatically — backed by an idempotent API and an auditable wallet ledger.</p>
        <div class="row" style="margin-top:1.5rem">
          <a class="btn ws-nexus-cta" href="<?=site_url('register')?>">Launch dashboard →</a>
          <a class="btn ws-nexus-ghost" href="<?=site_url('api/docs')?>">View API docs</a>
        </div>
      </div>
      <div class="ws-flow" aria-hidden="true">
        <div class="ws-flow-node ws-flow-customer">Customer</div>
        <div class="ws-flow-arrow">↓</div>
        <div class="ws-flow-node ws-flow-core">
          <span>MarvySocials</span>
          <small>Queue · Ledger · State machine</small>
        </div>
        <div class="ws-flow-arrow">↓</div>
        <div class="ws-flow-providers">
          <?php foreach (array('P1','P2','P3','P4') as $i): ?><span class="ws-dot"><?=$i?></span><?php endforeach; ?>
        </div>
        <div class="ws-flow-arrow">↓</div>
        <div class="ws-flow-node ws-flow-done">✓ Fulfillment</div>
      </div>
    </div>

    <div class="ws-nexus-stats">
      <div><span class="ws-kpi">CLI</span><span class="ws-kpi-label">Cron workers</span></div>
      <div><span class="ws-kpi">AES</span><span class="ws-kpi-label">Keys at rest</span></div>
      <div><span class="ws-kpi">TLS</span><span class="ws-kpi-label">Provider egress</span></div>
      <div><span class="ws-kpi">RBAC</span><span class="ws-kpi-label">Staff roles</span></div>
    </div>
  </div>
</section>

<section class="py-12" style="background:#0b0f1a;color:#cbd5e1">
  <div class="container" style="max-width:1180px">
    <h2 class="ws-section-title">Provider network</h2>
    <p class="muted" style="color:#94a3b8">Illustrative routing — real provider names, health and balances appear in the staff console after adapters are connected. These cards are not live figures.</p>
    <div class="grid grid-3 mt-6">
      <?php foreach ($providers as $p): $ok = $p[1]==='healthy'; ?>
      <div class="ws-nexus-card">
        <div class="row justify-between">
          <strong style="color:#f1f5f9"><?=htmlspecialchars($p[0])?></strong>
          <span class="badge <?=$ok?'badge-success':'badge-warning'?> badge-dot"><?=$p[1]?></span>
        </div>
        <dl class="ws-kv">
          <div><dt>Last sync</dt><dd class="mono"><?=htmlspecialchars($p[2])?></dd></div>
          <div><dt>Balance</dt><dd class="mono"><?=htmlspecialchars($p[3])?></dd></div>
        </dl>
        <div class="ws-spark"><?=str_repeat('▁▂▃▅▇', 4)?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="py-12" style="background:#0d1322;color:#cbd5e1">
  <div class="container" style="max-width:1180px">
    <h2 class="ws-section-title">Service explorer</h2>
    <div class="ws-explorer" role="region" tabindex="0" aria-label="Service explorer">
      <table class="table ws-table">
        <thead><tr><th>ID</th><th>Service</th><th>Type</th><th>Rate</th><th>Min / Max</th><th>Provider</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($explorer as $r): ?>
          <tr>
            <td class="mono"><?=htmlspecialchars($r[0])?></td>
            <td style="color:#e2e8f0"><?=htmlspecialchars($r[1])?></td>
            <td><span class="badge badge-info"><?=htmlspecialchars($r[2])?></span></td>
            <td class="mono" style="color:#22d3ee"><?=htmlspecialchars($r[3])?></td>
            <td class="mono"><?=htmlspecialchars($r[4])?></td>
            <td><?=htmlspecialchars($r[5])?></td>
            <td><a class="btn ws-nexus-cta btn-sm" href="<?=site_url('register')?>">Order</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="py-12" style="background:#0b0f1a;color:#cbd5e1">
  <div class="container" style="max-width:1080px">
    <h2 class="ws-section-title text-center">Built for automation</h2>
    <div class="grid grid-3 mt-6">
      <?php foreach ($automation as $a): ?>
      <div class="ws-nexus-card">
        <h3 class="ws-card-title"><?=htmlspecialchars($a[0])?></h3>
        <p style="color:#94a3b8"><?=htmlspecialchars($a[1])?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="ws-codecard mt-6">
      <div class="ws-codebar"><span class="ws-dot-r"></span><span class="ws-dot-y"></span><span class="ws-dot-g"></span><span class="mono" style="margin-left:.5rem">POST /api/v1/orders</span></div>
<pre class="mono" style="margin:0;padding:1rem 1.25rem;overflow:auto;color:#cbd5e1"><span style="color:#22d3ee">curl</span> -X POST https://panel.example/api/v1/orders \
  -H <span style="color:#a7f3d0">"X-Api-Key: wind_..."</span> \
  -H <span style="color:#a7f3d0">"Content-Type: application/json"</span> \
  -d '{<span style="color:#f0abfc">"service"</span>: 100291,
       <span style="color:#f0abfc">"link"</span>: <span style="color:#a7f3d0">"https://instagram.com/..."</span>,
       <span style="color:#f0abfc">"quantity"</span>: 1000}'</pre>
    </div>
  </div>
</section>

<section class="py-12" style="background:#0d1322;color:#cbd5e1">
  <div class="container" style="max-width:900px">
    <h2 class="ws-section-title text-center">FAQ</h2>
    <div class="mt-6 stack">
      <?php foreach ($faqs as $f): ?>
      <details class="ws-faq-dark">
        <summary><?=htmlspecialchars($f[0])?></summary>
        <p><?=htmlspecialchars($f[1])?></p>
      </details>
      <?php endforeach; ?>
    </div>
    <div class="ws-neon-cta mt-8">
      <h2 style="color:#fff">Ship at scale.</h2>
      <p style="color:#94a3b8">Spin up your dashboard and first API key in minutes.</p>
      <a class="btn ws-nexus-cta btn-lg" href="<?=site_url('register')?>">Launch dashboard →</a>
    </div>
  </div>
</section>

<style>
.ws-nexus-hero{background:#0b0f1a;color:#e2e8f0;padding:4.5rem 0 3rem;
  background-image:
   linear-gradient(rgba(34,211,238,.04) 1px,transparent 1px),
   linear-gradient(90deg,rgba(34,211,238,.04) 1px,transparent 1px),
   radial-gradient(900px 320px at 70% -10%,rgba(124,92,255,.18),transparent 60%),
   radial-gradient(700px 280px at 10% 10%,rgba(34,211,238,.12),transparent 60%);
  background-size:40px 40px,40px 40px,auto,auto}
.ws-nexus-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:2.5rem;align-items:center}
.ws-nexus-title{color:#f8fafc}
.ws-nexus-eyebrow{font-family:var(--font-mono);color:#22d3ee;font-size:.8rem;letter-spacing:.18em}
.ws-nexus-sub{color:#94a3b8;max-width:540px}
.ws-nexus-cta{background:#22d3ee;color:#041418;font-weight:700;border-color:transparent}
.ws-nexus-cta:hover{background:#06b6d4;color:#041418}
.ws-nexus-ghost{background:transparent;color:#e2e8f0;border:1px solid #1e293b}
.ws-nexus-ghost:hover{background:#111827}
.ws-flow{display:flex;flex-direction:column;align-items:center;gap:.4rem}
.ws-flow-node{padding:.75rem 1.25rem;border-radius:.75rem;border:1px solid #1e293b;background:#0f172a;text-align:center;min-width:240px}
.ws-flow-node small{display:block;color:#64748b;font-family:var(--font-mono);font-size:.72rem;margin-top:.25rem}
.ws-flow-core{border-color:rgba(34,211,238,.4);box-shadow:0 0 30px -8px rgba(34,211,238,.5)}
.ws-flow-done{border-color:rgba(16,185,129,.4);color:#6ee7b7}
.ws-flow-arrow{color:#475569}
.ws-flow-providers{display:flex;gap:.5rem}
.ws-dot{width:38px;height:38px;display:grid;place-items:center;border-radius:50%;background:#0f172a;border:1px solid #1e293b;color:#22d3ee;font-family:var(--font-mono);font-size:.75rem;position:relative}
.ws-dot::after{content:"";position:absolute;inset:-3px;border-radius:50%;border:2px solid rgba(34,211,238,.25);animation:ws-pulse 2.4s ease-in-out infinite}
@keyframes ws-pulse{0%,100%{opacity:.4}50%{opacity:1}}
.ws-nexus-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-top:3rem}
.ws-nexus-stats>div{background:#0f172a;border:1px solid #1e293b;border-radius:var(--radius-lg);padding:1rem}
.ws-kpi{display:block;font-family:var(--font-mono);font-size:1.6rem;color:#22d3ee}
.ws-kpi-label{color:#64748b;font-size:.8rem}
.ws-section-title{color:#f1f5f9}
.mono{font-family:var(--font-mono)}
.ws-nexus-card{background:#0f172a;border:1px solid #1e293b;border-radius:var(--radius-lg);padding:1.25rem}
.ws-card-title{color:#f1f5f9;font-size:1.05rem;margin:0 0 .5rem}
.ws-kv{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin:.75rem 0 0}
.ws-kv dt{color:#64748b;font-size:.75rem}
.ws-kv dd{margin:0;text-align:right}
.ws-spark{margin-top:.75rem;letter-spacing:1px;color:#22d3ee;font-size:1.1rem;opacity:.7}
.ws-explorer{overflow-x:auto;border:1px solid #1e293b;border-radius:var(--radius-lg)}
.ws-table{margin:0}
.ws-table th{background:#0b0f1a;color:#94a3b8;border-bottom:1px solid #1e293b}
.ws-table td{border-bottom:1px solid #111827}
.ws-table tbody tr:hover{background:#0d1322}
.ws-codecard{background:#0b0f1a;border:1px solid #1e293b;border-radius:var(--radius-lg);overflow:hidden}
.ws-codebar{display:flex;align-items:center;padding:.6rem 1rem;background:#0f172a;border-bottom:1px solid #1e293b}
.ws-dot-r,.ws-dot-y,.ws-dot-g{width:10px;height:10px;border-radius:50%;margin-right:.4rem}
.ws-dot-r{background:#ef4444}.ws-dot-y{background:#f59e0b}.ws-dot-g{background:#10b981}
.ws-faq-dark{background:#0f172a;border:1px solid #1e293b;border-radius:var(--radius);padding:1rem 1.25rem}
.ws-faq-dark summary{cursor:pointer;font-weight:600;color:#e2e8f0;list-style:none}
.ws-faq-dark summary::-webkit-details-marker{display:none}
.ws-faq-dark summary::after{content:'+';float:right;color:#22d3ee;font-weight:700}
.ws-faq-dark[open] summary::after{content:'−'}
.ws-faq-dark p{margin:.75rem 0 0;color:#94a3b8}
.ws-neon-cta{text-align:center;border:1px solid rgba(34,211,238,.4);border-radius:var(--radius-xl);padding:2.5rem;background:radial-gradient(600px 200px at 50% 0,rgba(34,211,238,.08),transparent)}
@media(max-width:880px){.ws-nexus-grid{grid-template-columns:1fr}.ws-nexus-stats{grid-template-columns:repeat(2,1fr)}}
@media(prefers-reduced-motion:reduce){.ws-dot::after{animation:none}}
</style>
