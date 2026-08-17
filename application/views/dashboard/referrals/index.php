<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-6 lg:grid-cols-3 max-w-5xl">
  <div class="lg:col-span-2 card">
    <h2 class="card-title">Refer &amp; earn</h2>
    <p class="muted">Share your link. When someone signs up and places their first order, you earn a commission to your wallet.</p>

    <label class="field mt-4">
      <span class="label">Your referral link</span>
      <div class="row" style="gap:.5rem">
        <input class="input" id="ws-ref" value="<?=htmlspecialchars($link)?>" readonly>
        <button class="btn btn-secondary" type="button" onclick="navigator.clipboard?.writeText(document.getElementById('ws-ref').value)">Copy</button>
      </div>
    </label>

    <div class="grid grid-3 mt-6">
      <div class="card"><div class="muted text-sm">Referred</div><div class="text-2xl font-bold">0</div></div>
      <div class="card"><div class="muted text-sm">Orders</div><div class="text-2xl font-bold">0</div></div>
      <div class="card"><div class="muted text-sm">Earned</div><div class="text-2xl font-bold"><?=windels_money('0')?></div></div>
    </div>

    <p class="hint mt-4">The full referral dashboard — signups, conversion and commission history — arrives in Session 14 (Affiliate).
      Your referral code <span class="mono"><?=htmlspecialchars($code)?></span> is already active.</p>
  </div>

  <aside class="card">
    <h3 class="card-title">How it works</h3>
    <ol class="stack" style="gap:.75rem;padding-left:1.25rem">
      <li>Share your link with friends and clients.</li>
      <li>They register using it.</li>
      <li>You earn a percentage of their first qualifying orders.</li>
    </ol>
  </aside>
</div>
