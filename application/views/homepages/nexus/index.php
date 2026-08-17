<?php defined('BASEPATH') OR exit('No direct script access allowed');
/** NEXUS — dark enterprise: near-black background, cyan accents, mono eyebrow, data/infra tone. */
?>
<section class="ws-nexus-hero">
  <div class="container" style="max-width:1080px;text-align:center">
    <p class="ws-nexus-eyebrow">// ENTERPRISE SMM INFRASTRUCTURE</p>
    <h1 class="ws-nexus-title">One platform.<br>Thousands of services.<br>Automated fulfillment.</h1>
    <p class="ws-nexus-sub">Reseller-grade API, double-entry ledger, idempotent webhooks and provider failover — built for volume.</p>
    <div class="row" style="justify-content:center;margin-top:1.5rem">
      <a class="btn ws-nexus-cta" href="<?=site_url('register')?>">Launch dashboard →</a>
      <a class="btn ws-nexus-ghost" href="<?=site_url('services')?>">Read the API docs</a>
    </div>
    <div class="ws-nexus-flow">
      <code>Customer</code> <span>→</span> <code>WINDELS PANEL</code> <span>→</span> <code>Provider network</code> <span>→</span> <code>Fulfillment</code>
    </div>
  </div>
</section>

<section class="py-12" style="background:#0b0f1a;color:#cbd5e1">
  <div class="container" style="max-width:1080px">
    <div class="grid grid-3">
      <?php foreach (array(
        array('Idempotent by design','Every order, webhook and ledger entry carries a unique key — retries never double-charge.'),
        array('Provider failover','Health checks and automatic routing keep orders moving when a provider degrades.'),
        array('Audited & reconciled','Append-only audit log and a wallet balance that always equals its transactions.'),
      ) as $f): ?>
      <div class="ws-nexus-card">
        <h3 class="ws-nexus-card-title"><?=htmlspecialchars($f[0])?></h3>
        <p class="muted" style="color:#94a3b8"><?=htmlspecialchars($f[1])?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.ws-nexus-hero{background:#0b0f1a;color:#e2e8f0;padding:5rem 0 4rem;
  background-image:radial-gradient(900px 300px at 50% 0,rgba(34,211,238,.12),transparent 60%)}
.ws-nexus-title{color:#f8fafc}
.ws-nexus-eyebrow{font-family:var(--font-mono);color:#22d3ee;font-size:.8rem;letter-spacing:.15em}
.ws-nexus-sub{color:#94a3b8;max-width:620px;margin:1rem auto 0}
.ws-nexus-cta{background:#22d3ee;color:#041418;font-weight:700}
.ws-nexus-cta:hover{background:#06b6d4;color:#041418}
.ws-nexus-ghost{background:transparent;color:#e2e8f0;border:1px solid #1e293b}
.ws-nexus-ghost:hover{background:#111827}
.ws-nexus-flow{margin-top:3rem;font-family:var(--font-mono);font-size:.85rem;color:#64748b}
.ws-nexus-flow code{color:#22d3ee;background:#0f172a;padding:.25rem .5rem;border-radius:.4rem}
.ws-nexus-flow span{margin:0 .5rem}
.ws-nexus-card{background:#0f172a;border:1px solid #1e293b;border-radius:var(--radius-lg);padding:1.5rem}
.ws-nexus-card-title{color:#f1f5f9}
</style>
